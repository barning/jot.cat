<?php

declare(strict_types=1);

namespace JotLibrary;

final class Post_Type {
	public const TYPE = 'jot_entry';
	public const TAXONOMY = 'jot_topic';

	public static function register(): void {
		register_post_type(
			self::TYPE,
			array(
				'labels' => array(
					'name' => __('Jot Library', 'jot-library'),
					'singular_name' => __('Library entry', 'jot-library'),
					'add_new_item' => __('Add library entry', 'jot-library'),
					'edit_item' => __('Edit library entry', 'jot-library'),
					'new_item' => __('New library entry', 'jot-library'),
					'view_item' => __('View library entry', 'jot-library'),
					'search_items' => __('Search library entries', 'jot-library'),
					'not_found' => __('No library entries found.', 'jot-library'),
					'menu_name' => __('Jot Library', 'jot-library'),
				),
				'public' => true,
				'show_in_rest' => true,
				'has_archive' => false,
				'rewrite' => array('slug' => 'library-entry', 'with_front' => false),
				'menu_icon' => 'dashicons-book-alt',
				'supports' => array('title', 'editor', 'excerpt', 'author'),
				'taxonomies' => array(self::TAXONOMY),
				'capability_type' => array('jot_entry', 'jot_entries'),
				'map_meta_cap' => true,
				'delete_with_user' => false,
				'show_in_nav_menus' => false,
			)
		);

		register_taxonomy(
			self::TAXONOMY,
			self::TYPE,
			array(
				'labels' => array(
					'name' => __('Topics', 'jot-library'),
					'singular_name' => __('Topic', 'jot-library'),
					'search_items' => __('Search topics', 'jot-library'),
					'all_items' => __('All topics', 'jot-library'),
					'edit_item' => __('Edit topic', 'jot-library'),
					'update_item' => __('Update topic', 'jot-library'),
					'add_new_item' => __('Add topic', 'jot-library'),
					'new_item_name' => __('New topic name', 'jot-library'),
				),
				'public' => true,
				'show_in_rest' => true,
				'hierarchical' => false,
				'rewrite' => array('slug' => 'library-topic', 'with_front' => false),
			)
		);

		self::register_meta();
	}

	/** @return list<string> */
	public static function capabilities(): array {
		return array(
			'edit_jot_entry', 'read_jot_entry', 'delete_jot_entry',
			'edit_jot_entries', 'edit_others_jot_entries', 'publish_jot_entries',
			'read_private_jot_entries', 'delete_jot_entries',
			'delete_private_jot_entries', 'delete_published_jot_entries',
			'delete_others_jot_entries', 'edit_private_jot_entries',
			'edit_published_jot_entries', 'create_jot_entries',
		);
	}

	private static function register_meta(): void {
		foreach (array(
			Entry_Service::META_URL => 'string',
			Entry_Service::META_NORMALIZED_URL => 'string',
			Entry_Service::META_CODE => 'string',
			Entry_Service::META_SOURCE => 'string',
			Entry_Service::META_SAVED_AT => 'string',
			Entry_Service::META_READING_STATUS => 'string',
		) as $key => $type) {
			register_post_meta(
				self::TYPE,
				$key,
				array(
					'type' => $type,
					'single' => true,
					'show_in_rest' => false,
					'auth_callback' => static fn (bool $allowed, string $meta_key, int $post_id): bool => current_user_can('edit_post', $post_id),
				)
			);
		}
	}
}
