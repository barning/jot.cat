<?php

declare(strict_types=1);

namespace JotLibrary;

use InvalidArgumentException;

final class URL {
	public const MAX_LENGTH = 4096;

	public static function validate_for_storage(string $url): string {
		$url = trim($url);
		$parts = self::validated_parts($url);
		$host = trim(strtolower((string) $parts['host']), '[]');

		if (self::is_local_hostname($host)) {
			throw new InvalidArgumentException(__('Enter a public HTTP or HTTPS URL.', 'jot-library'));
		}

		$addresses = self::resolve($host);
		if (array() === $addresses) {
			throw new InvalidArgumentException(__('The URL host could not be resolved. Check the address and try again.', 'jot-library'));
		}

		foreach ($addresses as $address) {
			if (! self::is_public_ip($address)) {
				throw new InvalidArgumentException(__('Enter a URL that does not point to a private or reserved network.', 'jot-library'));
			}
		}

		return $url;
	}

	public static function validate_stored(string $url): string {
		$url = trim($url);
		self::validated_parts($url);
		return $url;
	}

	public static function normalize(string $url): string {
		$url = self::validate_stored($url);
		$parts = parse_url($url);
		if (! is_array($parts)) {
			throw new InvalidArgumentException(__('Enter a valid URL.', 'jot-library'));
		}

		$scheme = strtolower((string) $parts['scheme']);
		$host = strtolower((string) $parts['host']);
		if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
			$host = '[' . $host . ']';
		}

		$port = isset($parts['port']) ? (int) $parts['port'] : null;
		$authority = $host;
		if (null !== $port && ! (('http' === $scheme && 80 === $port) || ('https' === $scheme && 443 === $port))) {
			$authority .= ':' . $port;
		}

		$path = (string) ($parts['path'] ?? '/');
		if ('' === $path) {
			$path = '/';
		}

		return $scheme . '://' . $authority . $path
			. (isset($parts['query']) ? '?' . $parts['query'] : '')
			. (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
	}

	public static function source(string $url): string {
		$parts = self::validated_parts($url);
		return strtolower(trim((string) $parts['host'], '[]'));
	}

	/** @return array<string, mixed> */
	private static function validated_parts(string $url): array {
		if ('' === $url || strlen($url) > self::MAX_LENGTH || 1 === preg_match('/[\x00-\x20\x7f]/', $url)) {
			throw new InvalidArgumentException(__('Enter a valid URL without spaces.', 'jot-library'));
		}

		$parts = parse_url($url);
		if (! is_array($parts) || false === filter_var($url, FILTER_VALIDATE_URL)) {
			throw new InvalidArgumentException(__('Enter a valid URL.', 'jot-library'));
		}

		$scheme = strtolower((string) ($parts['scheme'] ?? ''));
		$host = (string) ($parts['host'] ?? '');
		if (! in_array($scheme, array('http', 'https'), true) || '' === $host || isset($parts['user']) || isset($parts['pass'])) {
			throw new InvalidArgumentException(__('Enter a public HTTP or HTTPS URL without credentials.', 'jot-library'));
		}

		return $parts;
	}

	/** @return list<string> */
	private static function resolve(string $host): array {
		if (false !== filter_var($host, FILTER_VALIDATE_IP)) {
			return array($host);
		}

		$records = dns_get_record($host, DNS_A | DNS_AAAA);
		$addresses = array();
		if (is_array($records)) {
			foreach ($records as $record) {
				$address = $record['ip'] ?? $record['ipv6'] ?? null;
				if (is_string($address)) {
					$addresses[] = $address;
				}
			}
		}

		if (function_exists('apply_filters')) {
			/** @var list<string> $addresses */
			$addresses = apply_filters('jot_library_resolved_ips', array_values(array_unique($addresses)), $host);
		}

		return array_values(array_unique(array_filter($addresses, 'is_string')));
	}

	private static function is_local_hostname(string $host): bool {
		return 'localhost' === $host
			|| str_ends_with($host, '.localhost')
			|| str_ends_with($host, '.local')
			|| str_ends_with($host, '.internal');
	}

	private static function is_public_ip(string $address): bool {
		return false !== filter_var(
			$address,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}
}
