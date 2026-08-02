<?php

declare(strict_types=1);

use Jot\Application;
use Jot\Article\ArticleExtractor;
use Jot\Article\LengthClassifier;
use Jot\Fetch\CurlHttpClient;
use Jot\Fetch\NativeDnsResolver;
use Jot\Id\IdGenerator;
use Jot\Random\SecureRandomSource;
use Jot\Storage\ArticleRepository;
use Jot\Url\UrlValidator;
use Jot\Web\Copy;
use Jot\Web\PrivacyPolicy;
use Jot\Web\PublicUrl;

require dirname(__DIR__) . '/src/Autoload.php';

try {
    $databasePath = getenv('JOT_DB_PATH') ?: dirname(__DIR__) . '/var/jot.sqlite';
    $publicUrl = PublicUrl::fromString(getenv('JOT_BASE_URL') ?: 'https://jot.cat');
    $validator = new UrlValidator();
    $application = new Application(
        ArticleRepository::connect($databasePath),
        $validator,
        new CurlHttpClient($validator, new NativeDnsResolver()),
        new ArticleExtractor(),
        new LengthClassifier(),
        new IdGenerator(new SecureRandomSource()),
        $publicUrl,
        PrivacyPolicy::fromEnvironment(),
    );
    $response = $application->handle(
        (string) ($_SERVER['REQUEST_URI'] ?? '/'),
        (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    );
} catch (Throwable $error) {
    error_log('[Jot] Bootstrap failure: ' . $error->getMessage());
    $response = new Jot\Web\Response(
        500,
        Jot\Web\Html::page('Error', '<p class="eyebrow">Error 500</p><h1>' . Copy::UNEXPECTED . '</h1>'),
    );
}

http_response_code($response->status);
foreach ($response->headers as $name => $value) {
    header($name . ': ' . $value);
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
echo $response->body;
