<?php

declare(strict_types=1);

namespace Jot\Url;

use Jot\Exception\HttpException;

final class UrlValidator
{
    public const MAX_LENGTH = 4096;

    public function validate(string $url, int $failureStatus = 400): ValidatedUrl
    {
        if ($url === '' || strlen($url) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new HttpException($failureStatus, 'Enter a valid HTTP or HTTPS URL.');
        }

        $parts = parse_url($url);

        if (!is_array($parts) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new HttpException($failureStatus, 'Enter a valid HTTP or HTTPS URL.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new HttpException($failureStatus, 'Enter a valid HTTP or HTTPS URL.');
        }

        $host = trim($host, '[]');

        if ($host === '' || (!filter_var($host, FILTER_VALIDATE_IP) && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $host) !== 1)) {
            throw new HttpException($failureStatus, 'Enter a valid HTTP or HTTPS URL.');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        if ($port < 1 || $port > 65535) {
            throw new HttpException($failureStatus, 'Enter a valid HTTP or HTTPS URL.');
        }

        return new ValidatedUrl($url, $scheme, strtolower($host), $port);
    }
}
