<?php

declare(strict_types=1);

namespace JotLibrary;

use InvalidArgumentException;
use WP_Error;
use WP_Post;

final class Entry_Service {
	public const META_URL = '_jot_url';
	public const META_NORMALIZED_URL = '_jot_normalized_url';
	public const META_CODE = '_jot_code';
	public const META_SOURCE = '_jot_source';
	public const META_SAVED_AT = '_jot_saved_at';
	public const META_READING_STATUS = '_jot_reading_status';
	private const LOCK_OPTION = 'jot_library_entry_creation_lock';
	private const LOCK_TTL = 30;
	/** @var null|callable(): string */
	private $code_generator;

	/** @param null|callable(): string $code_generator */
	public function __construct(?callable $code_generator = null) {
		$this->code_generator = $code_generator;
	}

	/**
	 * @param array{url:string,title?:string,description?:string,notes?:string,status?:string,tags?:list<string>,reading_status?:string,saved_at?:string} $input
	 * @return array{id:int,code:string,created:bool}|WP_Error
	 */
	public function create_or_find(array $input): array|WP_Error {
		try {
			$url = URL::validate_for_storage((string) ($input['url'] ?? ''));
			$normalized = URL::normalize($url);
		} catch (InvalidArgumentException $error) {
			return new WP_Error('jot_invalid_url', $error->getMessage());
		}

		if (! $this->acquire_lock()) {
			return new WP_Error('jot_busy', __('Another link is being saved. Wait a moment and try again.', 'jot-library'));
		}

		try {
			$existing = $this->find_by_meta(self::META_NORMALIZED_URL, $normalized);
			if ($existing instanceof WP_Post) {
				return array(
					'id' => $existing->ID,
					'code' => (string) get_post_meta($existing->ID, self::META_CODE, true),
					'created' => false,
				);
			}

			for ($attempt = 0; $attempt < Code::MAX_ATTEMPTS; $attempt++) {
				$code = null !== $this->code_generator ? (string) ($this->code_generator)() : Code::generate();
				$code = Code::normalize($code);
				if (! Code::is_new($code)) {
					return new WP_Error('jot_invalid_generated_code', __('A valid reference could not be generated.', 'jot-library'));
				}
				if (null === $this->find_by_meta(self::META_CODE, $code)) {
					return $this->insert($input, $url, $normalized, $code);
				}
			}

			return new WP_Error('jot_code_exhausted', __('A reference could not be created. Try again.', 'jot-library'));
		} finally {
			delete_option(self::LOCK_OPTION);
		}
	}

	/**
	 * @return array{action:string,message:string,post_id?:int}
	 */
	public function inspect_import(string $code, string $url): array {
		$code = Code::normalize($code);
		if (! Code::is_valid($code)) {
			return array('action' => 'invalid', 'message' => __('The code format is invalid.', 'jot-library'));
		}

		try {
			$url = URL::validate_for_storage($url);
			$normalized = URL::normalize($url);
		} catch (InvalidArgumentException $error) {
			return array('action' => 'invalid', 'message' => $error->getMessage());
		}

		$by_code = $this->find_by_meta(self::META_CODE, $code);
		$by_url = $this->find_by_meta(self::META_NORMALIZED_URL, $normalized);

		if ($by_code instanceof WP_Post && $by_url instanceof WP_Post && $by_code->ID === $by_url->ID) {
			return array('action' => 'skip', 'message' => __('The same code and URL already exist.', 'jot-library'), 'post_id' => $by_code->ID);
		}
		if ($by_code instanceof WP_Post) {
			return array('action' => 'conflict', 'message' => __('The code already belongs to a different URL.', 'jot-library'), 'post_id' => $by_code->ID);
		}
		if ($by_url instanceof WP_Post) {
			return array('action' => 'conflict', 'message' => __('The URL already has a different code.', 'jot-library'), 'post_id' => $by_url->ID);
		}

		return array('action' => 'create', 'message' => __('The entry can be imported.', 'jot-library'));
	}

	/** @return array{action:string,message:string,post_id?:int} */
	public function inspect_new_url(string $url): array {
		try {
			$url = URL::validate_for_storage($url);
			$normalized = URL::normalize($url);
		} catch (InvalidArgumentException $error) {
			return array('action' => 'invalid', 'message' => $error->getMessage());
		}

		$existing = $this->find_by_meta(self::META_NORMALIZED_URL, $normalized);
		if ($existing instanceof WP_Post) {
			return array('action' => 'skip', 'message' => __('The URL already exists.', 'jot-library'), 'post_id' => $existing->ID);
		}

		return array('action' => 'create', 'message' => __('The URL can be imported.', 'jot-library'));
	}

	/**
	 * @return array{id:int,code:string,created:bool}|WP_Error
	 */
	public function import(string $code, string $url, string $saved_at, string $status = 'draft', array $data = array()): array|WP_Error {
		$inspection = $this->inspect_import($code, $url);
		if ('skip' === $inspection['action']) {
			return array('id' => (int) $inspection['post_id'], 'code' => Code::normalize($code), 'created' => false);
		}
		if ('create' !== $inspection['action']) {
			return new WP_Error('jot_import_' . $inspection['action'], $inspection['message']);
		}
		$saved_at = $this->valid_date($saved_at) ?? '';
		if ('' === $saved_at) {
			return new WP_Error('jot_import_timestamp', __('The saved timestamp must use YYYY-MM-DD HH:MM:SS in UTC.', 'jot-library'));
		}

		$url = URL::validate_for_storage($url);
		return $this->insert(
			array_merge(
				$data,
				array(
					'url' => $url,
					'title' => (string) ($data['title'] ?? URL::source($url)),
					'status' => in_array($status, array('publish', 'draft', 'private'), true) ? $status : 'draft',
					'saved_at' => $saved_at,
				)
			),
			$url,
			URL::normalize($url),
			Code::normalize($code)
		);
	}

	public function find_published_by_code(string $code): ?WP_Post {
		$code = Code::normalize($code);
		if (! Code::is_valid($code)) {
			return null;
		}

		return $this->find_by_meta(self::META_CODE, $code, array('publish'));
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{id:int,code:string,created:bool}|WP_Error
	 */
	private function insert(array $input, string $url, string $normalized, string $code): array|WP_Error {
		$status = (string) ($input['status'] ?? 'draft');
		if (! in_array($status, array('publish', 'draft', 'private'), true)) {
			$status = 'draft';
		}

		$saved_at = $this->valid_date((string) ($input['saved_at'] ?? ''));
		$title = sanitize_text_field((string) ($input['title'] ?? ''));
		if ('' === $title) {
			$title = URL::source($url);
		}

		$post = array(
			'post_type' => Post_Type::TYPE,
			'post_status' => $status,
			'post_title' => $title,
			'post_content' => wp_kses_post((string) ($input['notes'] ?? '')),
			'post_excerpt' => sanitize_textarea_field((string) ($input['description'] ?? '')),
		);
		if (null !== $saved_at) {
			$post['post_date_gmt'] = $saved_at;
			$post['post_date'] = get_date_from_gmt($saved_at);
		}

		$post_id = wp_insert_post($post, true);
		if (is_wp_error($post_id)) {
			return $post_id;
		}

		update_post_meta($post_id, self::META_URL, $url);
		update_post_meta($post_id, self::META_NORMALIZED_URL, $normalized);
		update_post_meta($post_id, self::META_CODE, $code);
		update_post_meta($post_id, self::META_SOURCE, URL::source($url));
		update_post_meta($post_id, self::META_SAVED_AT, $saved_at ?? gmdate('Y-m-d H:i:s'));
		$reading_status = 'read' === ($input['reading_status'] ?? '') ? 'read' : 'unread';
		update_post_meta($post_id, self::META_READING_STATUS, $reading_status);

		$tags = array_values(array_filter(array_map('sanitize_text_field', (array) ($input['tags'] ?? array()))));
		if (array() !== $tags) {
			wp_set_object_terms($post_id, $tags, Post_Type::TAXONOMY, false);
		}

		return array('id' => $post_id, 'code' => $code, 'created' => true);
	}

	/** @param list<string>|null $statuses */
	private function find_by_meta(string $key, string $value, ?array $statuses = null): ?WP_Post {
		$posts = get_posts(
			array(
				'post_type' => Post_Type::TYPE,
				'post_status' => $statuses ?? array('publish', 'draft', 'private', 'pending', 'future', 'trash'),
				'posts_per_page' => 1,
				'no_found_rows' => true,
				'suppress_filters' => true,
				'meta_key' => $key,
				'meta_value' => $value,
			)
		);

		return $posts[0] ?? null;
	}

	private function acquire_lock(): bool {
		$now = time();
		if (add_option(self::LOCK_OPTION, $now, '', false)) {
			return true;
		}

		$created = (int) get_option(self::LOCK_OPTION, 0);
		if ($created > 0 && ($now - $created) > self::LOCK_TTL) {
			delete_option(self::LOCK_OPTION);
			return add_option(self::LOCK_OPTION, $now, '', false);
		}

		return false;
	}

	private function valid_date(string $value): ?string {
		if ('' === $value) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
		return false !== $date && $date->format('Y-m-d H:i:s') === $value ? $value : null;
	}
}
