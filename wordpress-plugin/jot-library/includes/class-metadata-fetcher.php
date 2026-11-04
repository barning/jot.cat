<?php

declare(strict_types=1);

namespace JotLibrary;

use DOMDocument;
use DOMXPath;

final class Metadata_Fetcher {
	/** @return array{title:string,description:string} */
	public function fetch(string $url): array {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout' => 5,
				'redirection' => 3,
				'limit_response_size' => 2 * MB_IN_BYTES,
				'user-agent' => 'Jot Library/' . JOT_LIBRARY_VERSION . '; ' . home_url('/'),
			)
		);
		if (is_wp_error($response) || 200 > wp_remote_retrieve_response_code($response) || 300 <= wp_remote_retrieve_response_code($response)) {
			return array('title' => '', 'description' => '');
		}

		$content_type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
		if (! str_starts_with($content_type, 'text/html') && ! str_starts_with($content_type, 'application/xhtml+xml')) {
			return array('title' => '', 'description' => '');
		}

		$body = wp_remote_retrieve_body($response);
		if ('' === $body || ! class_exists(DOMDocument::class)) {
			return array('title' => '', 'description' => '');
		}

		$previous = libxml_use_internal_errors(true);
		$document = new DOMDocument();
		$loaded = $document->loadHTML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if (! $loaded) {
			return array('title' => '', 'description' => '');
		}

		$xpath = new DOMXPath($document);
		$title = trim((string) ($xpath->query('//title')->item(0)?->textContent ?? ''));
		$description = '';
		$meta = $xpath->query('//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]/@content')->item(0);
		if (null !== $meta) {
			$description = trim((string) $meta->nodeValue);
		}

		return array(
			'title' => mb_substr(sanitize_text_field($title), 0, 200),
			'description' => mb_substr(sanitize_textarea_field($description), 0, 500),
		);
	}
}
