<?php

declare(strict_types=1);

namespace Jot\Storage;

final class InsertResult
{
    private function __construct(
        public bool $inserted,
        public ?string $existingId,
    ) {
    }

    public static function inserted(): self
    {
        return new self(true, null);
    }

    public static function duplicateUrl(string $id): self
    {
        return new self(false, $id);
    }

    public static function idCollision(): self
    {
        return new self(false, null);
    }
}
