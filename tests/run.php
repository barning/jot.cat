<?php

declare(strict_types=1);

use Jot\Application;
use Jot\Article\ArticleExtractor;
use Jot\Article\LengthClassifier;
use Jot\Exception\HttpException;
use Jot\Fetch\CurlHttpClient;
use Jot\Fetch\DnsResolver;
use Jot\Fetch\FetchResult;
use Jot\Fetch\HttpClient;
use Jot\Id\IdGenerator;
use Jot\Random\RandomSource;
use Jot\Storage\ArticleRepository;
use Jot\Url\UrlValidator;
use Jot\Web\PrivacyPolicy;

require dirname(__DIR__) . '/src/Autoload.php';

final class TestFailure extends RuntimeException {}

final class SequenceRandom implements RandomSource
{
    /** @param list<int> $values */
    public function __construct(private array $values = []) {}

    public function integer(int $min, int $max): int
    {
        $value = array_shift($this->values) ?? 0;
        if ($value < $min || $value > $max) {
            throw new RuntimeException("Test random value {$value} is out of range.");
        }
        return $value;
    }
}

final class FakeHttpClient implements HttpClient
{
    public int $calls = 0;

    public function __construct(
        private string $body = '<article>one two three</article>',
        private ?HttpException $error = null,
    ) {}

    public function get(string $url): FetchResult
    {
        $this->calls++;
        if ($this->error !== null) {
            throw $this->error;
        }
        return new FetchResult($url, $this->body, 'text/html');
    }
}

final class FakeDns implements DnsResolver
{
    /** @param list<string> $addresses */
    public function __construct(private array $addresses) {}

    public function resolve(string $hostname): array
    {
        return $this->addresses;
    }
}

/** @param mixed $actual @param mixed $expected */
function assertSameValue(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new TestFailure(($message !== '' ? $message . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrue(bool $condition, string $message = 'condition is false'): void
{
    if (!$condition) {
        throw new TestFailure($message);
    }
}

function assertContains(string $needle, string $haystack): void
{
    assertTrue(str_contains($haystack, $needle), "expected output to contain {$needle}");
}

function assertThrows(callable $callback, string $class, ?int $status = null): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        assertTrue($error instanceof $class, 'unexpected exception ' . $error::class);
        if ($status !== null) {
            assertSameValue($status, $error->status);
        }
        return;
    }
    throw new TestFailure("expected {$class} to be thrown");
}

function repository(): ArticleRepository
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec(<<<'SQL'
CREATE TABLE articles (
  id TEXT PRIMARY KEY,
  url TEXT NOT NULL UNIQUE,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);
    return new ArticleRepository($pdo);
}

function application(
    ArticleRepository $repository,
    FakeHttpClient $http,
    ?SequenceRandom $random = null,
    ?PrivacyPolicy $privacy = null,
): Application {
    return new Application(
        $repository,
        new UrlValidator(),
        $http,
        new ArticleExtractor(),
        new LengthClassifier(),
        new IdGenerator($random ?? new SequenceRandom()),
        'https://jot.cat',
        $privacy ?? privacyPolicy(),
    );
}

function privacyPolicy(bool $complete = true): PrivacyPolicy
{
    $values = [
        'JOT_PRIVACY_CONTROLLER_NAME' => 'Example Operator',
        'JOT_PRIVACY_CONTROLLER_ADDRESS' => 'Example Street 1, Berlin',
        'JOT_PRIVACY_CONTACT_EMAIL' => 'privacy@example.com',
        'JOT_PRIVACY_LEGAL_BASIS' => 'Performance of the requested service and legitimate interests.',
        'JOT_PRIVACY_PROCESSOR' => 'Example Hosting GmbH hosts the service in Germany.',
        'JOT_PRIVACY_THIRD_COUNTRY_TRANSFERS' => 'No transfer to a third country takes place.',
        'JOT_PRIVACY_URL_RETENTION' => 'until the code is deleted',
        'JOT_PRIVACY_LOG_RETENTION' => '14 days',
        'JOT_PRIVACY_SUPERVISORY_AUTHORITY' => 'Berlin Commissioner for Data Protection',
        'JOT_PRIVACY_UPDATED_AT' => '2 August 2026',
    ];
    if (!$complete) {
        unset($values['JOT_PRIVACY_CONTROLLER_NAME']);
    }

    $original = [];
    foreach (array_keys($values + ['JOT_PRIVACY_CONTROLLER_NAME' => '']) as $key) {
        $original[$key] = getenv($key);
        if (array_key_exists($key, $values)) {
            putenv($key . '=' . $values[$key]);
        } else {
            putenv($key);
        }
    }

    $policy = PrivacyPolicy::fromEnvironment();
    foreach ($original as $key => $value) {
        putenv($value === false ? $key : $key . '=' . $value);
    }
    return $policy;
}

$tests = [];

$tests['URL validation accepts public-shaped HTTP URLs and rejects malformed input'] = static function (): void {
    $validator = new UrlValidator();
    assertSameValue('example.com', $validator->validate('https://example.com/article?q=1')->host);
    foreach (['', 'ftp://example.com/a', 'https://user:pass@example.com/a', 'https://bad host/a'] as $url) {
        assertThrows(fn () => $validator->validate($url), HttpException::class, 400);
    }
    assertThrows(fn () => $validator->validate('https://example.com/' . str_repeat('a', 4097)), HttpException::class, 400);
};

$tests['SSRF policy rejects private and mixed DNS answers before connecting'] = static function (): void {
    $validator = new UrlValidator();
    foreach ([['127.0.0.1'], ['10.0.0.1'], ['::1'], ['93.184.216.34', '192.168.1.1']] as $addresses) {
        $client = new CurlHttpClient($validator, new FakeDns($addresses));
        assertThrows(fn () => $client->get('https://example.com/article'), HttpException::class, 502);
    }
};

$tests['article extraction ignores page chrome and counts Unicode words'] = static function (): void {
    $extractor = new ArticleExtractor();
    $html = '<html><body><nav>ignored words here</nav><article>Hello world—it\'s déjà vu.</article></body></html>';
    assertSameValue(5, $extractor->wordCount($html));
    assertThrows(fn () => $extractor->wordCount('<html><script>only words</script></html>'), HttpException::class, 422);
};

$tests['length boundaries are exact'] = static function (): void {
    $classifier = new LengthClassifier();
    assertSameValue('S', $classifier->prefix(999));
    assertSameValue('M', $classifier->prefix(1000));
    assertSameValue('M', $classifier->prefix(2499));
    assertSameValue('L', $classifier->prefix(2500));
};

$tests['ID generation uses the Crockford alphabet and four total characters'] = static function (): void {
    $generator = new IdGenerator(new SequenceRandom([0, 31, 10]));
    assertSameValue('M0ZA', $generator->generate('M'));
    assertTrue(preg_match('/^[SML][0123456789ABCDEFGHJKMNPQRSTVWXYZ]{3}$/', $generator->generate('S')) === 1);
};

$tests['repository distinguishes insert, duplicate URL, and ID collision'] = static function (): void {
    $repository = repository();
    assertTrue($repository->insert('S000', 'https://example.com/a')->inserted);
    assertSameValue('S000', $repository->insert('S111', 'https://example.com/a')->existingId);
    $collision = $repository->insert('S000', 'https://example.com/b');
    assertTrue(!$collision->inserted && $collision->existingId === null);
};

$tests['saving returns HTML and preserves the source query string'] = static function (): void {
    $repository = repository();
    $http = new FakeHttpClient();
    $response = application($repository, $http, new SequenceRandom([7, 19, 13]))
        ->handle('/https://example.com/article?one=1&two=2');
    assertSameValue(200, $response->status);
    assertContains('S7K', $response->body);
    assertContains('one=1&amp;two=2', $response->body);
    assertSameValue('https://example.com/article?one=1&two=2', $repository->findUrlById('S7KD'));
};

$tests['saved article IDs reflect extracted medium and long lengths'] = static function (): void {
    foreach ([[1000, 'M000'], [2500, 'L000']] as [$wordCount, $expectedId]) {
        $repository = repository();
        $body = '<article>' . str_repeat('word ', $wordCount) . '</article>';
        $response = application($repository, new FakeHttpClient($body))->handle('/https://example.com/article');
        assertSameValue(200, $response->status);
        assertContains($expectedId, $response->body);
    }
};

$tests['duplicate saves reuse the ID without refetching'] = static function (): void {
    $repository = repository();
    $repository->insert('M7KD', 'https://example.com/article');
    $http = new FakeHttpClient();
    $response = application($repository, $http)->handle('/https://example.com/article');
    assertSameValue(200, $response->status);
    assertContains('M7KD', $response->body);
    assertContains('Already saved', $response->body);
    assertSameValue(0, $http->calls);
};

$tests['ID collisions retry with a new random ID'] = static function (): void {
    $repository = repository();
    $repository->insert('S000', 'https://example.com/existing');
    $random = new SequenceRandom([0, 0, 0, 1, 1, 1]);
    $response = application($repository, new FakeHttpClient(), $random)->handle('/https://example.com/new');
    assertSameValue(200, $response->status);
    assertSameValue('https://example.com/new', $repository->findUrlById('S111'));
};

$tests['collision exhaustion returns a generic 500'] = static function (): void {
    $repository = repository();
    $repository->insert('S000', 'https://example.com/existing');
    $response = application($repository, new FakeHttpClient())->handle('/https://example.com/new');
    assertSameValue(500, $response->status);
    assertContains('Jot could not finish that request. Try again.', $response->body);
};

$tests['ID lookup is case-insensitive and redirects without caching'] = static function (): void {
    $repository = repository();
    $repository->insert('M7KD', 'https://example.com/article');
    $response = application($repository, new FakeHttpClient())->handle('/m7kd');
    assertSameValue(302, $response->status);
    assertSameValue('https://example.com/article', $response->headers['Location']);
    assertSameValue('no-store', $response->headers['Cache-Control']);
};

$tests['invalid and unknown IDs return 404'] = static function (): void {
    $app = application(repository(), new FakeHttpClient());
    assertSameValue(404, $app->handle('/MIOO')->status);
    assertSameValue(404, $app->handle('/M7KD')->status);
};

$tests['fetch and extraction failures do not save an article'] = static function (): void {
    $repository = repository();
    $fetchFailure = new FakeHttpClient(error: new HttpException(502, 'The article could not be downloaded.'));
    assertSameValue(502, application($repository, $fetchFailure)->handle('/https://example.com/a')->status);
    assertSameValue(null, $repository->findIdByUrl('https://example.com/a'));

    $empty = new FakeHttpClient('<html><body><nav>nothing useful</nav></body></html>');
    assertSameValue(422, application($repository, $empty)->handle('/https://example.com/b')->status);
    assertSameValue(null, $repository->findIdByUrl('https://example.com/b'));
};

$tests['root page and method handling are explicit'] = static function (): void {
    $app = application(repository(), new FakeHttpClient());
    assertSameValue(200, $app->handle('/')->status);
    $response = $app->handle('/', 'POST');
    assertSameValue(405, $response->status);
    assertSameValue('GET', $response->headers['Allow']);
};

$tests['homepage form is semantic and saves through its query parameter'] = static function (): void {
    $repository = repository();
    $app = application($repository, new FakeHttpClient(), new SequenceRandom([7, 19, 13]));
    $home = $app->handle('/');
    assertContains('<main id="main">', $home->body);
    assertContains('<label for="url">Article URL</label>', $home->body);
    assertContains('name="url" type="url"', $home->body);
    assertContains('href="#main"', $home->body);

    $saved = $app->handle('/?url=https%3A%2F%2Fexample.com%2Farticle%3Fone%3D1%26two%3D2');
    assertSameValue(200, $saved->status);
    assertContains('Saved', $saved->body);
    assertContains('data-copy-code="S7KD"', $saved->body);
    assertSameValue('https://example.com/article?one=1&two=2', $repository->findUrlById('S7KD'));
};

$tests['invalid form input is retained and associated with its error'] = static function (): void {
    $response = application(repository(), new FakeHttpClient())->handle('/?url=not-a-url');
    assertSameValue(400, $response->status);
    assertContains('value="not-a-url"', $response->body);
    assertContains('aria-invalid="true" aria-describedby="url-error"', $response->body);
    assertContains('id="url-error" role="alert"', $response->body);
    assertContains('This does not look like a valid article URL.', $response->body);
};

$tests['privacy route renders configured details and fails closed when incomplete'] = static function (): void {
    $ready = application(repository(), new FakeHttpClient(), privacy: privacyPolicy());
    $response = $ready->handle('/privacy');
    assertSameValue(200, $response->status);
    assertContains('Example Operator', $response->body);
    assertContains('privacy@example.com', $response->body);
    assertContains('<html lang="de">', $response->body);
    assertContains('Datenschutzerklärung', $response->body);
    assertContains('Art. 77 DSGVO', $response->body);
    assertContains('§ 25 Abs. 2 Nr. 2 TDDDG', $response->body);
    assertContains('Berlin Commissioner for Data Protection', $response->body);

    $incomplete = application(repository(), new FakeHttpClient(), privacy: privacyPolicy(false));
    assertSameValue(503, $incomplete->handle('/privacy')->status);
};

$tests['progressive copy script and security policy support the enhanced form'] = static function (): void {
    $script = file_get_contents(dirname(__DIR__) . '/public/app.js');
    $frontController = file_get_contents(dirname(__DIR__) . '/public/index.php');
    assertTrue(is_string($script));
    assertContains('navigator.clipboard', $script);
    assertContains('button.hidden = false', $script);
    assertContains("script-src 'self'", (string) $frontController);
    assertContains("form-action 'self'", (string) $frontController);
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, "✓ {$name}\n");
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, "✗ {$name}\n  {$error->getMessage()}\n");
    }
}

fwrite(STDOUT, sprintf("\n%d tests, %d failures\n", count($tests), $failures));
exit($failures === 0 ? 0 : 1);
