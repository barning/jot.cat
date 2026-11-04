<?php

declare(strict_types=1);

function __(string $message, string $domain = 'default'): string {
	return $message;
}

require dirname(__DIR__) . '/includes/class-code.php';
require dirname(__DIR__) . '/includes/class-url.php';
require dirname(__DIR__) . '/includes/class-glyph.php';

use JotLibrary\Code;
use JotLibrary\Glyph;
use JotLibrary\URL;

final class Test_Failure extends RuntimeException {}

function check(bool $condition, string $message): void {
	if (! $condition) {
		throw new Test_Failure($message);
	}
}

function same(mixed $expected, mixed $actual, string $message): void {
	check($expected === $actual, $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

$tests = array();
$tests['five-character codes use the unambiguous alphabet'] = static function (): void {
	$values = array(0, 1, 2, 30, 31);
	$code = Code::generate(static function () use (&$values): int {
		return array_shift($values);
	});
	same('012YZ', $code, 'deterministic code');
	check(Code::is_new($code), 'new code parses');
	check(! Code::is_new('O1ILU'), 'ambiguous characters are rejected');
};
$tests['legacy and new code formats remain distinct and case-insensitive'] = static function (): void {
	check(Code::is_legacy('m7kd'), 'legacy code parses case-insensitively');
	check(Code::is_valid('7k3m9'), 'new code parses case-insensitively');
	check(! Code::is_valid('M7KDXZ'), 'legacy prefix does not make a six-character code valid');
	check(! Code::is_valid('../M7KD'), 'paths are not codes');
};
$tests['glyphs are deterministic, local, and decorative'] = static function (): void {
	$svg = Glyph::svg('7K3M9');
	same($svg, Glyph::svg('7k3m9'), 'glyph is case-insensitive');
	check(str_contains($svg, 'aria-hidden="true"'), 'glyph is hidden from assistive technology');
	check(str_contains($svg, 'currentColor'), 'glyph uses controlled local presentation');
	check(! str_contains($svg, 'http'), 'glyph has no remote dependency');
	check($svg !== Glyph::svg('M7KD'), 'complete code changes the glyph');
};
$tests['URL normalization is conservative'] = static function (): void {
	same('https://example.com/path?q=One#Part', URL::normalize('HTTPS://EXAMPLE.COM:443/path?q=One#Part'), 'scheme, host, and default port normalize');
	same('http://example.com/', URL::normalize('http://EXAMPLE.com'), 'empty path normalizes to slash');
	check(URL::normalize('https://example.com/?a=1') !== URL::normalize('https://example.com/?a=2'), 'query parameters are retained');
	check(URL::normalize('https://example.com/#one') !== URL::normalize('https://example.com/#two'), 'fragments are retained');
};
$tests['unsafe URL targets are rejected before storage'] = static function (): void {
	foreach (array('http://127.0.0.1/', 'http://10.0.0.1/', 'http://[::1]/', 'http://localhost/', 'ftp://example.com/') as $url) {
		$thrown = false;
		try {
			URL::validate_for_storage($url);
		} catch (InvalidArgumentException) {
			$thrown = true;
		}
		check($thrown, "unsafe URL rejected: {$url}");
	}
	same('https://93.184.216.34/resource', URL::validate_for_storage('https://93.184.216.34/resource'), 'public IP accepted');
};

$failures = 0;
foreach ($tests as $name => $test) {
	try {
		$test();
		fwrite(STDOUT, "✓ {$name}\n");
	} catch (Throwable $error) {
		++$failures;
		fwrite(STDERR, "✗ {$name}: {$error->getMessage()}\n");
	}
}
fwrite(STDOUT, sprintf("\n%d tests, %d failures\n", count($tests), $failures));
exit($failures > 0 ? 1 : 0);
