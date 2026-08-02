<?php

declare(strict_types=1);

namespace Jot;

use Jot\Article\ArticleExtractor;
use Jot\Article\LengthClassifier;
use Jot\Exception\HttpException;
use Jot\Fetch\HttpClient;
use Jot\Id\IdGenerator;
use Jot\Storage\ArticleRepository;
use Jot\Url\UrlValidator;
use Jot\Web\Html;
use Jot\Web\Response;
use Throwable;

final class Application
{
    private const ID_PATTERN = '/^[SML][0123456789ABCDEFGHJKMNPQRSTVWXYZ]{3}$/';
    private const MAX_ID_ATTEMPTS = 32;

    public function __construct(
        private ArticleRepository $articles,
        private UrlValidator $urls,
        private HttpClient $http,
        private ArticleExtractor $extractor,
        private LengthClassifier $classifier,
        private IdGenerator $ids,
        private string $baseUrl,
    ) {
    }

    public function handle(string $requestUri, string $method = 'GET'): Response
    {
        try {
            if ($method !== 'GET') {
                return new Response(
                    405,
                    Html::page('Method not allowed', '<p class="eyebrow">Error 405</p><h1>Only GET requests are supported.</h1>'),
                    ['Content-Type' => 'text/html; charset=utf-8', 'Allow' => 'GET'],
                );
            }

            [$path, $query] = $this->splitRequestUri($requestUri);

            if ($path === '/') {
                return $this->home();
            }

            $candidate = substr($path, 1);

            if (stripos($candidate, 'http://') === 0 || stripos($candidate, 'https://') === 0) {
                return $this->save($candidate . ($query !== '' ? '?' . $query : ''));
            }

            return $this->open(rawurldecode($candidate));
        } catch (HttpException $error) {
            if ($error->getMessage() !== $error->publicMessage) {
                error_log('[Jot] ' . $error->getMessage());
            }

            return $this->error($error->status, $error->publicMessage);
        } catch (Throwable $error) {
            error_log('[Jot] ' . $error::class . ': ' . $error->getMessage());
            return $this->error(500, 'Jot encountered an unexpected error.');
        }
    }

    private function home(): Response
    {
        $content = '<h1>Short IDs for long articles.</h1>'
            . '<p>Put an article URL directly after <code>https://jot.cat/</code>.</p>'
            . '<p class="example"><code>https://jot.cat/https://example.com/article</code></p>';

        return new Response(200, Html::page('Short IDs for long articles', $content));
    }

    private function save(string $url): Response
    {
        $this->urls->validate($url);
        $existingId = $this->articles->findIdByUrl($url);

        if ($existingId !== null) {
            return $this->savedResponse($existingId, $url);
        }

        $result = $this->http->get($url);
        $wordCount = $this->extractor->wordCount($result->body);
        $prefix = $this->classifier->prefix($wordCount);

        for ($attempt = 0; $attempt < self::MAX_ID_ATTEMPTS; $attempt++) {
            $id = $this->ids->generate($prefix);
            $insert = $this->articles->insert($id, $url);

            if ($insert->inserted) {
                return $this->savedResponse($id, $url);
            }

            if ($insert->existingId !== null) {
                return $this->savedResponse($insert->existingId, $url);
            }
        }

        throw new HttpException(500, 'Jot could not allocate an ID. Please try again later.', 'ID collision retry limit exhausted.');
    }

    private function open(string $candidate): Response
    {
        $id = strtoupper($candidate);

        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new HttpException(404, 'That Jot ID does not exist.');
        }

        $url = $this->articles->findUrlById($id);

        if ($url === null) {
            throw new HttpException(404, 'That Jot ID does not exist.');
        }

        return new Response(302, '', ['Location' => $url, 'Cache-Control' => 'no-store']);
    }

    private function savedResponse(string $id, string $url): Response
    {
        $safeId = Html::escape($id);
        $safeUrl = Html::escape($url);
        $shortUrl = rtrim($this->baseUrl, '/') . '/' . $id;
        $safeShortUrl = Html::escape($shortUrl);
        $content = <<<HTML
<p class="eyebrow">Saved</p>
<h1 class="id">{$safeId}</h1>
<p><a class="short-url" href="{$safeShortUrl}">{$safeShortUrl}</a></p>
<p class="source">{$safeUrl}</p>
HTML;

        return new Response(200, Html::page("Saved as {$id}", $content));
    }

    private function error(int $status, string $message): Response
    {
        $safeMessage = Html::escape($message);
        $content = "<p class=\"eyebrow\">Error {$status}</p><h1>{$safeMessage}</h1><p><a href=\"/\">Back to Jot</a></p>";
        return new Response($status, Html::page('Error', $content));
    }

    /** @return array{string, string} */
    private function splitRequestUri(string $requestUri): array
    {
        $position = strpos($requestUri, '?');

        if ($position === false) {
            return [$requestUri, ''];
        }

        return [substr($requestUri, 0, $position), substr($requestUri, $position + 1)];
    }
}
