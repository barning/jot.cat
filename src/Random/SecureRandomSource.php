<?php

declare(strict_types=1);

namespace Jot\Random;

final class SecureRandomSource implements RandomSource
{
    public function integer(int $min, int $max): int
    {
        return random_int($min, $max);
    }
}
