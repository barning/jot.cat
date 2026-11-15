<?php

declare(strict_types=1);

namespace JotLibrary;

use DateTimeImmutable;
use DateTimeZone;
use WP_Query;

final class Saved_Links_Block {
	public static function register(): void {
		register_block_type(JOT_LIBRARY_DIR . 'blocks/saved-links');
	}

	/** @param array<string,mixed> $attributes */
	public static function render(array $attributes): string {
		$from = self::boundary((string) ($attributes['fromDate'] ?? ''), false);
		$to = self::boundary((string) ($attributes['toDate'] ?? ''), true);
		$wrapper = get_block_wrapper_attributes(array('class' => 'jot-saved-links'));
		if (null === $from || null === $to) {
			return '<div ' . $wrapper . '><p class="jot-saved-links__empty">' . esc_html__('Choose a From and To date to display saved links.', 'jot-library') . '</p></div>';
		}
		if ($from > $to) {
			return '<div ' . $wrapper . '><p class="jot-saved-links__empty">' . esc_html__('The From date must be earlier than or equal to the To date.', 'jot-library') . '</p></div>';
		}

		$query = new WP_Query(
			array(
				'post_type' => Post_Type::TYPE,
				'post_status' => 'publish',
				'posts_per_page' => -1,
				'ignore_sticky_posts' => true,
				'no_found_rows' => true,
				'meta_key' => Entry_Service::META_SAVED_AT,
				'orderby' => 'meta_value',
				'order' => 'DESC',
				'meta_query' => array(
					array(
						'key' => Entry_Service::META_SAVED_AT,
						'value' => array($from, $to),
						'compare' => 'BETWEEN',
						'type' => 'DATETIME',
					),
				),
			)
		);

		if (! $query->have_posts()) {
			return '<div ' . $wrapper . '><p class="jot-saved-links__empty">' . esc_html__('No published links were saved in this date range.', 'jot-library') . '</p></div>';
		}

		$html = '<div ' . $wrapper . '><ul class="jot-saved-links__entries">';
		foreach ($query->posts as $post) {
			$post_id = (int) $post->ID;
			$url = (string) get_post_meta($post_id, Entry_Service::META_URL, true);
			$source = (string) get_post_meta($post_id, Entry_Service::META_SOURCE, true);
			$saved_at = (string) get_post_meta($post_id, Entry_Service::META_SAVED_AT, true);
			$timestamp = strtotime($saved_at . ' UTC');
			$date = false !== $timestamp ? wp_date((string) get_option('date_format'), $timestamp, wp_timezone()) : '';
			$notes = wp_strip_all_tags((string) $post->post_content);
			$description = wp_strip_all_tags((string) $post->post_excerpt);
			$text = '' !== $notes ? $notes : $description;

			$html .= '<li class="jot-saved-links__entry"><h3><a href="' . esc_url($url) . '" rel="noopener noreferrer">' . esc_html(get_the_title($post_id)) . '</a></h3>';
			$html .= '<p class="jot-saved-links__meta">';
			if ('' !== $source) {
				$html .= '<span>' . esc_html($source) . '</span>';
			}
			if ('' !== $date) {
				if ('' !== $source) {
					$html .= '<span aria-hidden="true"> · </span>';
				}
				$html .= '<time datetime="' . esc_attr(gmdate('Y-m-d', $timestamp)) . '">' . esc_html(sprintf(__('Saved %s', 'jot-library'), $date)) . '</time>';
			}
			$html .= '</p>';
			if ('' !== $text) {
				$html .= '<p class="jot-saved-links__description">' . esc_html($text) . '</p>';
			}
			$html .= '</li>';
		}

		wp_reset_postdata();
		return $html . '</ul></div>';
	}

	private static function boundary(string $value, bool $end_of_day): ?string {
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, wp_timezone());
		if (false === $date || $date->format('Y-m-d') !== $value) {
			return null;
		}
		if ($end_of_day) {
			$date = $date->modify('+1 day')->modify('-1 second');
		}
		return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
	}
}
