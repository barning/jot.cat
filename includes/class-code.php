<?php

declare(strict_types=1);

namespace JotLibrary;

final class Code {
	public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
	public const MAX_ATTEMPTS = 32;

	public static function normalize(string $code): string {
		return strtoupper(trim($code));
	}

	public static function is_new(string $code): bool {
		return 1 === preg_match('/^[' . self::ALPHABET . ']{5}$/', self::normalize($code));
	}

	public static function is_valid(string $code): bool {
		return self::is_new($code);
	}

	/**
	 * @param null|callable(int, int): int $random_int
	 */
	public static function generate(?callable $random_int = null): string {
		$random_int ??= static fn (int $min, int $max): int => random_int($min, $max);
		$code = '';
		$last = strlen(self::ALPHABET) - 1;

		for ($index = 0; $index < 5; $index++) {
			$code .= self::ALPHABET[$random_int(0, $last)];
		}

		return $code;
	}
}
