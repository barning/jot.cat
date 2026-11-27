<?php

declare(strict_types=1);

function __(string $message, string $domain = 'default'): string {
	return $message;
}

require dirname(__DIR__) . '/includes/class-code.php';
require dirname(__DIR__) . '/includes/class-url.php';
require dirname(__DIR__) . '/includes/class-glyph.php';
if (! defined('MB_IN_BYTES')) {
	define('MB_IN_BYTES', 1024 * 1024);
}
require dirname(__DIR__) . '/includes/class-preview-image.php';

use JotLibrary\Code;
use JotLibrary\Glyph;
use JotLibrary\Preview_Image;
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
$tests['codes are five characters and case-insensitive'] = static function (): void {
	check(Code::is_valid('7k3m9'), 'code parses case-insensitively');
	check(! Code::is_valid('M7KD'), 'four-character code is rejected');
	check(! Code::is_valid('M7KDXZ'), 'six-character code is rejected');
	check(! Code::is_valid('../M7KD'), 'paths are not codes');
};
$tests['glyphs are deterministic, local, and decorative'] = static function (): void {
	$svg = Glyph::svg('7K3M9');
	same($svg, Glyph::svg('7k3m9'), 'glyph is case-insensitive');
	check(str_contains($svg, 'aria-hidden="true"'), 'glyph is hidden from assistive technology');
	check(str_contains($svg, 'currentColor'), 'glyph uses controlled local presentation');
	check(! str_contains($svg, 'http'), 'glyph has no remote dependency');
	check($svg !== Glyph::svg('012YZ'), 'complete code changes the glyph');
};
$tests['URL normalization is conservative'] = static function (): void {
	same('https://example.com/path?q=One#Part', URL::normalize('HTTPS://EXAMPLE.COM:443/path?q=One#Part'), 'scheme, host, and default port normalize');
	same('http://example.com/', URL::normalize('http://EXAMPLE.com'), 'empty path normalizes to slash');
	check(URL::normalize('https://example.com/?a=1') !== URL::normalize('https://example.com/?a=2'), 'query parameters are retained');
	check(URL::normalize('https://example.com/#one') !== URL::normalize('https://example.com/#two'), 'fragments are retained');
};
$tests['og:image references resolve against the page URL'] = static function (): void {
	$page = 'https://example.com/blog/post.html';
	same('https://cdn.example.com/a.jpg', Preview_Image::resolve('https://cdn.example.com/a.jpg', $page), 'absolute URL kept');
	same('https://cdn.example.com/a.jpg', Preview_Image::resolve('//cdn.example.com/a.jpg', $page), 'protocol-relative URL uses page scheme');
	same('https://example.com/img/a.jpg', Preview_Image::resolve('/img/a.jpg', $page), 'root-relative URL uses page origin');
	same('https://example.com/blog/a.jpg', Preview_Image::resolve('a.jpg', $page), 'relative URL uses page directory');
	same('https://example.com/a.jpg?w=1&h=2', Preview_Image::resolve('/a.jpg?w=1&amp;h=2', $page), 'entities are decoded');
	same('', Preview_Image::resolve('data:image/png;base64,AAAA', $page), 'data URLs are ignored');
	same('', Preview_Image::resolve('  ', $page), 'empty value is ignored');
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
