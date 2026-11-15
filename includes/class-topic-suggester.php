<?php

declare(strict_types=1);

namespace JotLibrary;

final class Topic_Suggester {
	private const LIMIT = 5;

	/**
	 * Suggest a small set of reviewable topics without contacting another service.
	 *
	 * @param list<string> $metadata_topics
	 * @return list<string>
	 */
	public function suggest(string $url, string $title, string $description, string $notes, array $metadata_topics = array()): array {
		$suggestions = array();
		foreach ($metadata_topics as $topic) {
			$this->add($suggestions, $topic);
		}

		$haystack = mb_strtolower(wp_strip_all_tags(urldecode($url . ' ' . $title . ' ' . $description . ' ' . $notes)));
		$terms = get_terms(array('taxonomy' => Post_Type::TAXONOMY, 'hide_empty' => false));
		if (! is_wp_error($terms)) {
			foreach ($terms as $term) {
				$name = (string) $term->name;
				$needle = mb_strtolower($name);
				if ('' !== $needle && str_contains($haystack, $needle)) {
					$this->add($suggestions, $name);
				}
			}
		}

		return array_slice($suggestions, 0, self::LIMIT);
	}

	/** @param list<string> $suggestions */
	private function add(array &$suggestions, string $candidate): void {
		$candidate = trim(preg_replace('/\s+/u', ' ', sanitize_text_field(ltrim($candidate, "# \t\n\r\0\x0B"))) ?? '');
		if (mb_strlen($candidate) < 2 || mb_strlen($candidate) > 40) {
			return;
		}
		if (in_array(mb_strtolower($candidate), array('article', 'blog', 'general', 'home', 'news', 'uncategorized', 'website'), true)) {
			return;
		}
		foreach ($suggestions as $existing) {
			if (0 === strcasecmp($existing, $candidate)) {
				return;
			}
		}
		$suggestions[] = $candidate;
	}
}
