<?php

declare(strict_types=1);

namespace JotLibrary;

use InvalidArgumentException;

/**
 * Copies a page's Open Graph image into the media library as the entry's featured image.
 *
 * The image is downloaded once on the server, so the public library never hotlinks
 * third-party assets and visitors' browsers make no request to the source site.
 */
final class Preview_Image {
	public const MAX_BYTES = 2 * MB_IN_BYTES;
	private const MIME_TYPES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png' => 'image/png',
		'gif' => 'image/gif',
		'webp' => 'image/webp',
		'avif' => 'image/avif',
	);

	/** Resolve a possibly relative image reference against the page it was found on. */
	public static function resolve(string $image, string $page_url): string {
		$image = trim(html_entity_decode($image, ENT_QUOTES | ENT_HTML5));
		if ('' === $image || str_starts_with($image, 'data:')) {
			return '';
		}
		if (str_starts_with($image, '//')) {
			$scheme = (string) parse_url($page_url, PHP_URL_SCHEME);
			return ('' !== $scheme ? $scheme : 'https') . ':' . $image;
		}
		if (preg_match('#^https?://#i', $image)) {
			return $image;
		}
		$parts = parse_url($page_url);
		if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
			return '';
		}
		$origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
		if (str_starts_with($image, '/')) {
			return $origin . $image;
		}
		$path = (string) ($parts['path'] ?? '/');
		$directory = substr($path, 0, (int) strrpos($path, '/') + 1);
		return $origin . ('' !== $directory ? $directory : '/') . $image;
	}

	/** Attach the image as featured image unless the entry already has one. */
	public static function attach(int $post_id, string $image_url): bool {
		if ('' === $image_url || has_post_thumbnail($post_id)) {
			return false;
		}
		try {
			$image_url = URL::validate_for_storage($image_url);
		} catch (InvalidArgumentException) {
			return false;
		}

		$response = wp_safe_remote_get(
			$image_url,
			array(
				'timeout' => 5,
				'redirection' => 3,
				'limit_response_size' => self::MAX_BYTES + 1,
				'user-agent' => 'Jot Library/' . JOT_LIBRARY_VERSION . '; ' . home_url('/'),
			)
		);
		if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
			return false;
		}
		$body = wp_remote_retrieve_body($response);
		if ('' === $body || strlen($body) > self::MAX_BYTES) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$temporary = wp_tempnam('jot-preview');
		if (! is_string($temporary) || '' === $temporary || false === file_put_contents($temporary, $body)) {
			return false;
		}

		$mime = (string) wp_get_image_mime($temporary);
		$extension = array_search($mime, self::MIME_TYPES, true);
		if (false === $extension) {
			wp_delete_file($temporary);
			return false;
		}
		$extension = explode('|', (string) $extension)[0];
		$code = (string) get_post_meta($post_id, Entry_Service::META_CODE, true);
		$file = array(
			'name' => sanitize_file_name('jot-' . strtolower('' !== $code ? $code : (string) $post_id) . '-preview.' . $extension),
			'tmp_name' => $temporary,
		);

		$attachment_id = media_handle_sideload($file, $post_id, get_the_title($post_id), array('post_excerpt' => ''));
		if (is_wp_error($attachment_id)) {
			wp_delete_file($temporary);
			return false;
		}
		// Decorative next to the linked title, so the alternative text stays empty.
		update_post_meta((int) $attachment_id, '_wp_attachment_image_alt', '');
		return (bool) set_post_thumbnail($post_id, (int) $attachment_id);
	}
}
