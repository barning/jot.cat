<?php

declare(strict_types=1);

namespace Jot\Web;

final class PageViewLogger
{
    public static function log(Response $response, string $method): void
    {
        error_log(self::message($response, $method));
    }

    public static function message(Response $response, string $method): string
    {
        $payload = json_encode([
            'page' => $response->page,
            'status' => $response->status,
            'method' => strtoupper($method),
        ], JSON_UNESCAPED_SLASHES);

        return '[Jot] pageview ' . ($payload === false ? '{}' : $payload);
    }
}
