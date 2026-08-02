<?php

declare(strict_types=1);

namespace Jot\Web;

final class Glyph
{
    /** @return array{int, int, int} */
    public static function cells(string $id): array
    {
        $digest = hash('sha256', strtoupper($id), true);

        return [ord($digest[0]) % 6, ord($digest[1]) % 6, ord($digest[2]) % 6];
    }

    public static function svg(string $id): string
    {
        $shapes = '';

        foreach (self::cells($id) as $index => $variant) {
            $x = 1 + ($index * 16);
            $shapes .= self::shape($x, $variant);
            $shapes .= '<rect x="' . $x . '" y="1" width="12" height="12" rx="0.75" fill="none" stroke="currentColor" stroke-width="1.5"/>';
        }

        return '<svg class="glyph" viewBox="0 0 46 14" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">'
            . $shapes . '</svg>';
    }

    private static function shape(int $x, int $variant): string
    {
        return match ($variant) {
            1 => '<rect x="' . ($x + 2.25) . '" y="3.25" width="7.5" height="7.5" fill="currentColor"/>',
            2 => '<path d="M' . ($x + 1.5) . ' 11.5V2.5L' . ($x + 10.5) . ' 11.5Z" fill="currentColor"/>',
            3 => '<path d="M' . ($x + 1.5) . ' 2.5H' . ($x + 6) . 'V11.5H' . ($x + 1.5) . 'Z" fill="currentColor"/>',
            4 => '<path d="M' . ($x + 1.5) . ' 7H' . ($x + 10.5) . 'V11.5H' . ($x + 1.5) . 'Z" fill="currentColor"/>',
            5 => '<path d="M' . ($x + 6) . ' 2.5L' . ($x + 10.5) . ' 11.5H' . ($x + 1.5) . 'Z" fill="currentColor"/>',
            default => '',
        };
    }
}
