<?php

declare(strict_types=1);

namespace Jot\Random;

interface RandomSource
{
    public function integer(int $min, int $max): int;
}
