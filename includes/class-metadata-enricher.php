<?php

declare(strict_types=1);

namespace JotLibrary;

use WP_Post;

final class Metadata_Enricher {
	public const HOOK = 'jot_library_enrich_imported_entries';
	private const QUEUE_OPTION = 'jot_library_metadata_enrichment_queue';
	private const LOCK_OPTION = 'jot_library_metadata_enrichment_queue_lock';
	private const BATCH_SIZE = 5;
	private const LOCK_TTL = 5;
	private const LOCK_ATTEMPTS = 120;
	private const LOCK_RETRY_MICROSECONDS = 50000;

	public static function register(): void {
		add_action(self::HOOK, array(self::class, 'run'));
		if (array() !== self::queue()) {
			self::schedule();
		}
	}

	public static function enqueue(int $post_id): bool {
		$post = get_post($post_id);
		if (! $post instanceof WP_Post || Post_Type::TYPE !== $post->post_type) {
			return false;
		}

		$lock = self::acquire_lock();
		if (null === $lock) {
			return false;
		}

		try {
			$queue = self::queue();
			if (! in_array($post_id, $queue, true)) {
				$queue[] = $post_id;
				update_option(self::QUEUE_OPTION, $queue, false);
			}
		} finally {
			self::release_lock($lock);
		}

		self::schedule();
		return true;
	}

	public static function run(): void {
		$lock = self::acquire_lock();
		if (null === $lock) {
			self::schedule(10);
			return;
		}

		try {
			$queue = self::queue();
			$batch = array_splice($queue, 0, self::BATCH_SIZE);
			update_option(self::QUEUE_OPTION, $queue, false);
		} finally {
			self::release_lock($lock);
		}

		foreach ($batch as $post_id) {
			self::enrich((int) $post_id);
		}

		if (self::has_queued_entries()) {
			self::schedule(60);
		}
	}

	public static function clear_schedule(): void {
		wp_clear_scheduled_hook(self::HOOK);
	}

	private static function enrich(int $post_id): void {
		$post = get_post($post_id);
		if (! $post instanceof WP_Post || Post_Type::TYPE !== $post->post_type || 'trash' === $post->post_status) {
			return;
		}

		$url = (string) get_post_meta($post_id, Entry_Service::META_URL, true);
		if ('' === $url) {
			return;
		}

		$metadata = (new Metadata_Fetcher())->fetch($url);
		$source = (string) get_post_meta($post_id, Entry_Service::META_SOURCE, true);
		$title = trim((string) $post->post_title);
		$description = trim((string) $post->post_excerpt);
		$updates = array('ID' => $post_id);

		if ('' !== $metadata['title'] && ('' === $title || 0 === strcasecmp($title, $source))) {
			$title = $metadata['title'];
			$updates['post_title'] = $title;
		}
		if ('' === $description && '' !== $metadata['description']) {
			$description = $metadata['description'];
			$updates['post_excerpt'] = $description;
		}
		if (count($updates) > 1) {
			wp_update_post($updates);
		}

		$existing = wp_get_object_terms($post_id, Post_Type::TAXONOMY, array('fields' => 'names'));
		$existing = is_wp_error($existing) ? array() : array_values(array_map('strval', $existing));
		$suggested = (new Topic_Suggester())->suggest($url, $title, $description, (string) $post->post_content, $metadata['topics']);
		$topics = array_values(array_unique(array_merge($existing, $suggested)));
		if (array() !== $topics) {
			wp_set_object_terms($post_id, $topics, Post_Type::TAXONOMY, false);
		}
	}

	/** @return list<int> */
	private static function queue(): array {
		$value = get_option(self::QUEUE_OPTION, array());
		if (! is_array($value)) {
			return array();
		}
		return array_values(array_unique(array_filter(array_map('absint', $value))));
	}

	private static function has_queued_entries(): bool {
		$lock = self::acquire_lock();
		if (null === $lock) {
			return true;
		}

		try {
			return array() !== self::queue();
		} finally {
			self::release_lock($lock);
		}
	}

	private static function acquire_lock(): ?string {
		for ($attempt = 0; $attempt < self::LOCK_ATTEMPTS; $attempt++) {
			$token = wp_generate_uuid4() . '|' . (time() + self::LOCK_TTL);
			if (add_option(self::LOCK_OPTION, $token, '', false)) {
				return $token;
			}

			$current = (string) get_option(self::LOCK_OPTION, '');
			$separator = strrpos($current, '|');
			$expires = false === $separator ? 0 : (int) substr($current, $separator + 1);
			if ($expires > 0 && $expires < time()) {
				self::delete_lock($current);
				continue;
			}

			usleep(self::LOCK_RETRY_MICROSECONDS);
		}

		return null;
	}

	private static function release_lock(string $token): void {
		self::delete_lock($token);
	}

	private static function delete_lock(string $token): void {
		global $wpdb;

		$deleted = $wpdb->delete(
			$wpdb->options,
			array(
				'option_name' => self::LOCK_OPTION,
				'option_value' => $token,
			),
			array('%s', '%s')
		);
		if (false !== $deleted && $deleted > 0) {
			wp_cache_delete(self::LOCK_OPTION, 'options');
		}
	}

	private static function schedule(int $delay = 10): void {
		if (false === wp_next_scheduled(self::HOOK)) {
			wp_schedule_single_event(time() + $delay, self::HOOK);
		}
	}
}
