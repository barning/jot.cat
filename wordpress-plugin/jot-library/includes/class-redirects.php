<?php

declare(strict_types=1);

namespace JotLibrary;

use InvalidArgumentException;

final class Redirects {
	public static function register(): void {
		add_rewrite_rule('^j/([^/]+)/?$', 'index.php?jot_code=$matches[1]', 'top');
	}

	/** @param list<string> $variables
	 * @return list<string>
	 */
	public static function query_vars(array $variables): array {
		$variables[] = 'jot_code';
		return $variables;
	}

	public static function maybe_redirect(): void {
		$requested = (string) get_query_var('jot_code');
		if ('' === $requested) {
			return;
		}

		$entry = (new Entry_Service())->find_published_by_code($requested);
		if (null === $entry) {
			self::not_found();
			return;
		}

		try {
			$url = URL::validate_stored((string) get_post_meta($entry->ID, Entry_Service::META_URL, true));
		} catch (InvalidArgumentException) {
			self::not_found();
			return;
		}

		nocache_headers();
		wp_redirect($url, 302, 'Jot Library'); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Validated stored external URL is the intended destination.
		exit;
	}

	private static function not_found(): void {
		global $wp_query;
		$wp_query->set_404();
		status_header(404);
		nocache_headers();
	}
}
