<?php

declare(strict_types=1);

namespace Jot\Web;

final class UmamiConfig
{
    private function __construct(
        private ?string $websiteId,
        private bool $missing,
        private ?string $error,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $value = getenv('JOT_UMAMI_WEBSITE_ID');
        return self::fromString(is_string($value) ? $value : '');
    }

    public static function fromString(string $websiteId): self
    {
        $websiteId = strtolower(trim($websiteId));

        if ($websiteId === '') {
            return new self(null, true, null);
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $websiteId) !== 1) {
            return new self(null, false, 'JOT_UMAMI_WEBSITE_ID must be a UUID.');
        }

        return new self($websiteId, false, null);
    }

    public function isEnabled(): bool
    {
        return $this->websiteId !== null;
    }

    public function websiteId(): string
    {
        return $this->websiteId ?? '';
    }

    public function isMissing(): bool
    {
        return $this->missing;
    }

    public function error(): ?string
    {
        return $this->error;
    }
}
