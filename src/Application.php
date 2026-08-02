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
use Jot\Web\Copy;
use Jot\Web\PrivacyPolicy;
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
        private PrivacyPolicy $privacy,
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
                parse_str($query, $parameters);
                if (array_key_exists('url', $parameters)) {
                    $url = is_string($parameters['url']) ? $parameters['url'] : '';
                    try {
                        return $this->save($url);
                    } catch (HttpException $error) {
                        if ($error->status === 400) {
                            return $this->home($url, $error->publicMessage);
                        }
                        throw $error;
                    }
                }

                return $this->home();
            }

            if ($path === '/privacy') {
                return $this->privacy();
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
            return $this->error(500, Copy::UNEXPECTED);
        }
    }

    private function home(string $value = '', ?string $error = null): Response
    {
        $safeValue = Html::escape($value);
        $invalid = $error !== null ? ' aria-invalid="true" aria-describedby="url-error"' : '';
        $errorMarkup = $error !== null
            ? '<p class="form-error" id="url-error" role="alert">' . Html::escape($error) . '</p>'
            : '';
        $content = '<section class="intro" aria-labelledby="home-title">'
            . '<p class="eyebrow">' . Copy::HOME_EYEBROW . '</p>'
            . '<h1 id="home-title">' . Copy::HOME_TITLE . '</h1>'
            . '<p class="support">' . Copy::HOME_SUPPORT . '</p></section>'
            . '<form class="save-form" action="/" method="get" data-save-form>'
            . '<label for="url">' . Copy::URL_LABEL . '</label>'
            . '<input id="url" name="url" type="url" value="' . $safeValue . '"'
            . ' placeholder="' . Copy::URL_PLACEHOLDER . '" required maxlength="4096"'
            . ' inputmode="url" enterkeyhint="go" autocomplete="url" autocapitalize="none"'
            . ' autocorrect="off" spellcheck="false"' . $invalid . '>'
            . $errorMarkup
            . '<button type="submit" data-submit-label="' . Copy::SAVING_ACTION . '">' . Copy::SAVE_ACTION . '</button>'
            . '</form>';

        return new Response($error === null ? 200 : 400, Html::page(Copy::HOME_TITLE, $content));
    }

    private function save(string $url): Response
    {
        $this->urls->validate($url);
        $existingId = $this->articles->findIdByUrl($url);

        if ($existingId !== null) {
            return $this->savedResponse($existingId, $url, true);
        }

        $result = $this->http->get($url);
        $wordCount = $this->extractor->wordCount($result->body);
        $prefix = $this->classifier->prefix($wordCount);

        for ($attempt = 0; $attempt < self::MAX_ID_ATTEMPTS; $attempt++) {
            $id = $this->ids->generate($prefix);
            $insert = $this->articles->insert($id, $url);

            if ($insert->inserted) {
                return $this->savedResponse($id, $url, false);
            }

            if ($insert->existingId !== null) {
                return $this->savedResponse($insert->existingId, $url, true);
            }
        }

        throw new HttpException(500, Copy::UNEXPECTED, 'ID collision retry limit exhausted.');
    }

    private function open(string $candidate): Response
    {
        $id = strtoupper($candidate);

        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new HttpException(404, Copy::UNKNOWN_CODE);
        }

        $url = $this->articles->findUrlById($id);

        if ($url === null) {
            throw new HttpException(404, Copy::UNKNOWN_CODE);
        }

        return new Response(302, '', ['Location' => $url, 'Cache-Control' => 'no-store']);
    }

    private function savedResponse(string $id, string $url, bool $duplicate): Response
    {
        $safeId = Html::escape($id);
        $safeUrl = Html::escape($url);
        $shortUrl = rtrim($this->baseUrl, '/') . '/' . $id;
        $safeShortUrl = Html::escape($shortUrl);
        $state = $duplicate ? Copy::ALREADY_SAVED : Copy::SAVED;
        $content = <<<HTML
<section class="result" aria-labelledby="result-code">
<p class="eyebrow">{$state}</p>
<h1 class="id" id="result-code">{$safeId}</h1>
<p class="result-help">Write this code in your notebook.</p>
<div class="result-actions">
  <button type="button" class="secondary-button" hidden data-copy-code="{$safeId}">Copy code</button>
  <a class="button-link" href="{$safeShortUrl}">Open article</a>
</div>
<p class="copy-status" aria-live="polite" data-copy-status></p>
<p class="source"><span>Article</span> {$safeUrl}</p>
</section>
HTML;

        return new Response(200, Html::page("Saved as {$id}", $content));
    }

    private function privacy(): Response
    {
        if (!$this->privacy->isComplete()) {
            error_log('[Jot] Missing privacy configuration: ' . implode(', ', $this->privacy->missing()));
            $content = '<p class="eyebrow">Privacy</p><h1>Privacy information is unavailable.</h1>'
                . '<p class="support">The site owner needs to finish the privacy configuration.</p>';
            return new Response(503, Html::page('Privacy unavailable', $content));
        }

        $value = fn (string $key): string => Html::escape($this->privacy->value($key));
        $address = nl2br($value('JOT_PRIVACY_CONTROLLER_ADDRESS'));
        $email = $value('JOT_PRIVACY_CONTACT_EMAIL');
        $content = <<<HTML
<article class="policy">
  <p class="eyebrow">Privacy</p>
  <h1>Privacy policy</h1>
  <p class="support">Last updated {$value('JOT_PRIVACY_UPDATED_AT')}.</p>
  <h2>Who is responsible</h2>
  <p>{$value('JOT_PRIVACY_CONTROLLER_NAME')}<br>{$address}</p>
  <p>Email: <a href="mailto:{$email}">{$email}</a></p>
  <h2>What Jot processes</h2>
  <p>When you save an article, Jot stores the submitted URL, its generated code, and timestamps. The server may also process technical connection data such as your IP address, browser details, requested path, and request time.</p>
  <h2>Why it is processed</h2>
  <p>Jot processes this data to fetch the article, create and resolve short codes, keep the service secure, and diagnose faults.</p>
  <h2>Legal basis</h2>
  <p>{$value('JOT_PRIVACY_LEGAL_BASIS')}</p>
  <h2>Service providers</h2>
  <p>{$value('JOT_PRIVACY_PROCESSOR')}</p>
  <h2>Retention</h2>
  <p>Submitted URLs and their codes: {$value('JOT_PRIVACY_URL_RETENTION')}</p>
  <p>Technical logs: {$value('JOT_PRIVACY_LOG_RETENTION')}</p>
  <h2>Your rights</h2>
  <p>Depending on the law that applies to you, you may have rights to access, correct, erase, restrict, or object to the processing of your personal data, to data portability, and to complain to a supervisory authority. Contact the address above to exercise these rights.</p>
  <h2>Cookies and tracking</h2>
  <p>Jot does not use non-essential cookies or analytics.</p>
</article>
HTML;

        return new Response(200, Html::page('Privacy policy', $content, 'How jot.cat processes submitted URLs and technical connection data.'));
    }

    private function error(int $status, string $message): Response
    {
        $safeMessage = Html::escape($message);
        $content = "<section class=\"error-page\"><p class=\"eyebrow\">Error {$status}</p><h1>{$safeMessage}</h1><p><a href=\"/\">Back to Jot</a></p></section>";
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
