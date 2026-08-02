<?php

declare(strict_types=1);

namespace Jot\Id;

use InvalidArgumentException;
use Jot\Random\RandomSource;

final class IdGenerator
{
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function __construct(private RandomSource $random)
    {
    }

    public function generate(string $prefix): string
    {
        if (!in_array($prefix, ['S', 'M', 'L'], true)) {
            throw new InvalidArgumentException('ID prefix must be S, M, or L.');
        }

        $id = $prefix;
        $lastIndex = strlen(self::ALPHABET) - 1;

        for ($index = 0; $index < 3; $index++) {
            $id .= self::ALPHABET[$this->random->integer(0, $lastIndex)];
        }

        return $id;
    }
}
