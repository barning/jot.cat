<?php

declare(strict_types=1);

namespace Jot\Web;

use InvalidArgumentException;

final class PublicUrl
{
    private function __construct(
        private string $origin,
        private string $displayHost,
    ) {
    }

    public static function fromString(string $value): self
    {
        $parts = parse_url($value);

        if (!is_array($parts)) {
            throw new InvalidArgumentException('JOT_BASE_URL must be an absolute HTTP or HTTPS URL.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new InvalidArgumentException('JOT_BASE_URL must be an absolute HTTP or HTTPS URL.');
        }

        foreach (['user', 'pass', 'query', 'fragment'] as $forbidden) {
            if (array_key_exists($forbidden, $parts)) {
                throw new InvalidArgumentException('JOT_BASE_URL cannot contain credentials, a query, or a fragment.');
            }
        }

        if ($path !== '' && $path !== '/') {
            throw new InvalidArgumentException('JOT_BASE_URL must use the origin root, without a path.');
        }

        $hostForUrl = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $origin = $scheme . '://' . $hostForUrl . $port;

        if (filter_var($origin, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('JOT_BASE_URL contains an invalid hostname.');
        }

        return new self($origin, $hostForUrl . $port);
    }

    public function origin(): string
    {
        return $this->origin;
    }

    public function displayHost(): string
    {
        return $this->displayHost;
    }

    public function absolute(string $path = '/'): string
    {
        if ($path === '' || $path === '/') {
            return $this->origin . '/';
        }

        return $this->origin . '/' . ltrim($path, '/');
    }

    public function bookmarklet(): string
    {
        $target = json_encode(
            $this->absolute('/?url='),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return 'javascript:location.href=' . $target . '+encodeURIComponent(location.href)';
    }
}
