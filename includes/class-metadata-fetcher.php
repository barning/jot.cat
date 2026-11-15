<?php

declare(strict_types=1);

namespace JotLibrary;

use DOMDocument;
use DOMXPath;

final class Metadata_Fetcher {
	/** @return array{title:string,description:string,topics:list<string>} */
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
			return array('title' => '', 'description' => '', 'topics' => array());
		}

		$content_type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
		if (! str_starts_with($content_type, 'text/html') && ! str_starts_with($content_type, 'application/xhtml+xml')) {
			return array('title' => '', 'description' => '', 'topics' => array());
		}

		$body = wp_remote_retrieve_body($response);
		if ('' === $body || ! class_exists(DOMDocument::class)) {
			return array('title' => '', 'description' => '', 'topics' => array());
		}

		$previous = libxml_use_internal_errors(true);
		$document = new DOMDocument();
		$loaded = $document->loadHTML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if (! $loaded) {
			return array('title' => '', 'description' => '', 'topics' => array());
		}

		$xpath = new DOMXPath($document);
		$title = $this->first_meta($xpath, array('property' => 'og:title', 'name' => 'twitter:title'));
		if ('' === $title) {
			$title = trim((string) ($xpath->query('//title')->item(0)?->textContent ?? ''));
		}
		$description = $this->first_meta($xpath, array('property' => 'og:description', 'name' => 'description', 'name_fallback' => 'twitter:description'));
		$topics = array();
		$keywords = $xpath->query('//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="keywords"]/@content')->item(0);
		if (null !== $keywords) {
			$topics = array_merge($topics, array_map('trim', explode(',', (string) $keywords->nodeValue)));
		}
		foreach ($xpath->query('//meta[translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="article:tag"]/@content') as $tag) {
			$topics[] = trim((string) $tag->nodeValue);
		}
		$section = $xpath->query('//meta[translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="article:section"]/@content')->item(0);
		if (null !== $section) {
			$topics[] = trim((string) $section->nodeValue);
		}
		$topics = array_slice(array_values(array_filter(array_map('sanitize_text_field', $topics))), 0, 10);

		return array(
			'title' => mb_substr(sanitize_text_field($title), 0, 200),
			'description' => mb_substr(sanitize_textarea_field($description), 0, 500),
			'topics' => $topics,
		);
	}

	/** @param array<string,string> $candidates */
	private function first_meta(DOMXPath $xpath, array $candidates): string {
		foreach ($candidates as $attribute => $value) {
			if ('name_fallback' === $attribute) {
				$attribute = 'name';
			}
			$query = sprintf('//meta[translate(@%1$s,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="%2$s"]/@content', $attribute, strtolower($value));
			$meta = $xpath->query($query)->item(0);
			if (null !== $meta && '' !== trim((string) $meta->nodeValue)) {
				return trim((string) $meta->nodeValue);
			}
		}
		return '';
	}
}
