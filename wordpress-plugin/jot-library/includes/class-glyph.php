<?php

declare(strict_types=1);

namespace JotLibrary;

final class Glyph {
	public static function svg(string $code): string {
		$hash = hash('sha256', Code::normalize($code), true);
		$points = array();
		for ($index = 0; $index < 6; $index++) {
			$x = 8 + ((ord($hash[$index * 2]) % 5) * 8);
			$y = 8 + ((ord($hash[$index * 2 + 1]) % 5) * 8);
			$points[] = $x . ',' . $y;
		}

		$rotation = (ord($hash[12]) % 4) * 90;
		return '<svg class="jot-library-glyph" viewBox="0 0 48 48" aria-hidden="true" focusable="false">'
			. '<g transform="rotate(' . $rotation . ' 24 24)" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
			. '<path d="M' . implode(' L', $points) . '"/>'
			. '<circle cx="' . (8 + ((ord($hash[13]) % 5) * 8)) . '" cy="' . (8 + ((ord($hash[14]) % 5) * 8)) . '" r="3"/>'
			. '</g></svg>';
	}
}
