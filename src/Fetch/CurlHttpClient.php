<?php

declare(strict_types=1);

namespace Jot\Fetch;

use Jot\Exception\HttpException;
use Jot\Url\UrlValidator;
use Jot\Url\ValidatedUrl;
use Jot\Web\Copy;

final class CurlHttpClient implements HttpClient
{
    private const MAX_REDIRECTS = 5;
    private const MAX_BYTES = 2_097_152;

    public function __construct(
        private UrlValidator $validator,
        private DnsResolver $dns,
    ) {
    }

    public function get(string $url): FetchResult
    {
        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            $validated = $this->validator->validate($url, 502);
            $addresses = $this->safeAddresses($validated->host);
            [$status, $contentType, $body, $location] = $this->request($validated, $addresses[0], $addresses);

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                if ($location === null || $location === '') {
                    throw new HttpException(502, 'The article could not be downloaded.', 'Redirect response had no Location header.');
                }

                if ($redirects === self::MAX_REDIRECTS) {
                    throw new HttpException(502, 'The article could not be downloaded.', 'Too many redirects.');
                }

                $url = $this->resolveRedirect($url, $location);
                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw new HttpException(502, 'The article could not be downloaded.', "Upstream returned HTTP {$status}.");
            }

            $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));

            if (!in_array($mediaType, ['text/html', 'application/xhtml+xml'], true)) {
                throw new HttpException(422, 'The URL does not point to a readable HTML article.', "Unsupported Content-Type: {$contentType}");
            }

            return new FetchResult($url, $body, $contentType);
        }

        throw new HttpException(502, 'The article could not be downloaded.');
    }

    /** @return list<string> */
    private function safeAddresses(string $host): array
    {
        $addresses = $this->dns->resolve($host);

        if ($addresses === []) {
            throw new HttpException(502, 'The article host could not be resolved.', "DNS lookup failed for {$host}.");
        }

        foreach ($addresses as $address) {
            if (!$this->isPublicIp($address)) {
                throw new HttpException(502, 'The article URL points to a disallowed network.', "Unsafe address {$address} for {$host}.");
            }
        }

        return $addresses;
    }

    private function isPublicIp(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /** @param list<string> $resolvedAddresses
     *  @return array{int, string, string, ?string}
     */
    private function request(ValidatedUrl $url, string $address, array $resolvedAddresses): array
    {
        $handle = curl_init($url->value);

        if ($handle === false) {
            throw new HttpException(502, 'The article could not be downloaded.', 'Unable to initialize cURL.');
        }

        $body = '';
        $location = null;
        $tooLarge = false;
        $options = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'Jot/1.0 (+https://jot.cat/)',
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                    $tooLarge = true;
                    return 0;
                }

                $body .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$location): int {
                if (str_starts_with(strtolower($header), 'location:')) {
                    $location = trim(substr($header, 9));
                }

                return strlen($header);
            },
        ];

        if (filter_var($url->host, FILTER_VALIDATE_IP) === false) {
            $resolveAddress = str_contains($address, ':') ? "[{$address}]" : $address;
            $options[CURLOPT_RESOLVE] = ["{$url->host}:{$url->port}:{$resolveAddress}"];
        }

        curl_setopt_array($handle, $options);

        $ok = curl_exec($handle);
        $errorNumber = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
        $primaryIp = (string) curl_getinfo($handle, CURLINFO_PRIMARY_IP);
        if ($tooLarge) {
            throw new HttpException(422, 'The article is too large to process.', 'Response exceeded the 2 MiB limit.');
        }

        if ($ok === false) {
            if ($errorNumber === CURLE_OPERATION_TIMEDOUT) {
                throw new HttpException(504, Copy::TIMEOUT, "cURL timeout: {$error}");
            }
            throw new HttpException(502, 'The article could not be downloaded.', "cURL error: {$error}");
        }

        if (!$this->isPublicIp($primaryIp) || !$this->addressInList($primaryIp, $resolvedAddresses)) {
            throw new HttpException(502, 'The article URL points to a disallowed network.', "Connected peer {$primaryIp} did not match validated DNS.");
        }

        return [$status, $contentType, $body, $location];
    }

    /** @param list<string> $addresses */
    private function addressInList(string $needle, array $addresses): bool
    {
        $packedNeedle = @inet_pton($needle);

        foreach ($addresses as $address) {
            if ($packedNeedle !== false && $packedNeedle === @inet_pton($address)) {
                return true;
            }
        }

        return false;
    }

    private function resolveRedirect(string $baseUrl, string $location): string
    {
        $location = trim($location);

        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $base = parse_url($baseUrl);

        if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
            throw new HttpException(502, 'The article could not be downloaded.', 'Could not resolve redirect URL.');
        }

        if (str_starts_with($location, '//')) {
            return $base['scheme'] . ':' . $location;
        }

        $authority = $base['host'];
        if (str_contains($authority, ':') && !str_starts_with($authority, '[')) {
            $authority = '[' . $authority . ']';
        }
        if (isset($base['port'])) {
            $authority .= ':' . $base['port'];
        }

        $fragmentless = explode('#', $location, 2)[0];
        if (str_starts_with($fragmentless, '?')) {
            $path = (string) ($base['path'] ?? '/');
            return "{$base['scheme']}://{$authority}{$path}{$fragmentless}";
        }

        $parts = explode('?', $fragmentless, 2);
        $relativePath = $parts[0];
        $query = isset($parts[1]) ? '?' . $parts[1] : '';
        $path = str_starts_with($relativePath, '/')
            ? $relativePath
            : preg_replace('#/[^/]*$#', '/', (string) ($base['path'] ?? '/')) . $relativePath;

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return "{$base['scheme']}://{$authority}/" . implode('/', $segments) . $query;
    }
}
