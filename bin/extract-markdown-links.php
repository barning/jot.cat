<?php

declare(strict_types=1);

/**
 * Build a Jot Library CSV from outbound links in a directory of Markdown files.
 *
 * Usage:
 * php bin/extract-markdown-links.php --source=/path/to/markdown --output=/path/to/import.csv
 */

$options = getopt('', array('source:', 'output:'));
$source = isset($options['source']) ? rtrim((string) $options['source'], DIRECTORY_SEPARATOR) : '';
$output = isset($options['output']) ? (string) $options['output'] : '';
if ('' === $source || '' === $output || ! is_dir($source)) {
	fwrite(STDERR, "Usage: php bin/extract-markdown-links.php --source=/path/to/markdown --output=/path/to/import.csv\n");
	exit(2);
}

$files = array();
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
	if ($file->isFile() && 'md' === strtolower($file->getExtension())) {
		$files[] = $file->getPathname();
	}
}
sort($files, SORT_NATURAL | SORT_FLAG_CASE);

$occurrences = array();
$stats = array('candidates' => 0, 'internal' => 0, 'media' => 0, 'invalid' => 0);
foreach ($files as $path) {
	$content = file_get_contents($path);
	if (false === $content) {
		continue;
	}
	$front_matter = array();
	$body = $content;
	if (preg_match('/\A---\R(.*?)\R---\R?/s', $content, $match)) {
		$body = substr($content, strlen($match[0]));
		foreach (preg_split('/\R/', $match[1]) ?: array() as $line) {
			if (preg_match('/^([a-z_]+):\s*(.*)$/i', $line, $field)) {
				$value = trim($field[2]);
				if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
					$value = substr($value, 1, -1);
				}
				$front_matter[strtolower($field[1])] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			}
		}
	}

	$published = published_at($front_matter, $path);
	$episode = trim((string) ($front_matter['title'] ?? ''));
	list($markdown_links, $remaining_body) = markdown_links($body);
	$links = $markdown_links;
	if (preg_match_all('~https?://[^\s<>"\x27]+~iu', $remaining_body, $raw_matches)) {
		foreach ($raw_matches[0] as $url) {
			$links[] = array('url' => rtrim($url, ".,;:!?)]}"), 'label' => '');
		}
	}

	foreach ($links as $link) {
		++$stats['candidates'];
		$prepared = prepare_url($link['url']);
		if (null === $prepared) {
			++$stats['invalid'];
			continue;
		}
		list($url, $key, $reason) = $prepared;
		if ('' !== $reason) {
			++$stats[$reason];
			continue;
		}
		$title = clean_label($link['label']);
		$occurrences[] = array(
			'key' => $key,
			'url' => $url,
			'title' => $title,
			'episode' => $episode,
			'saved_at' => $published,
		);
	}
}

usort($occurrences, static fn (array $a, array $b): int => strcmp($a['saved_at'], $b['saved_at']));
$rows = array();
foreach ($occurrences as $item) {
	$key = $item['key'];
	if (! isset($rows[$key])) {
		$rows[$key] = $item;
		continue;
	}
	if (mb_strlen($item['title']) > mb_strlen($rows[$key]['title'])) {
		$rows[$key]['title'] = $item['title'];
	}
}
uasort($rows, static fn (array $a, array $b): int => strcmp($a['saved_at'], $b['saved_at']) ?: strcasecmp($a['url'], $b['url']));

$directory = dirname($output);
if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
	fwrite(STDERR, "Could not create output directory: {$directory}\n");
	exit(1);
}
$stream = fopen($output, 'wb');
if (false === $stream) {
	fwrite(STDERR, "Could not open output file: {$output}\n");
	exit(1);
}
fputcsv($stream, array('code', 'url', 'title', 'description', 'notes', 'topics', 'status', 'reading_status', 'saved_at'), ',', '"', '\\');
foreach ($rows as $row) {
	fputcsv(
		$stream,
		array('', $row['url'], safe_cell($row['title']), '', '', '', 'draft', 'unread', $row['saved_at']),
		',',
		'"',
		'\\'
	);
}
fclose($stream);

$duplicates = count($occurrences) - count($rows);
printf(
	"Scanned %d Markdown files; wrote %d unique links to %s (%d duplicate occurrences, %d internal links, %d media links, %d invalid links skipped).\n",
	count($files),
	count($rows),
	$output,
	$duplicates,
	$stats['internal'],
	$stats['media'],
	$stats['invalid']
);

/** @param array<string,string> $front_matter */
function published_at(array $front_matter, string $path): string {
	$value = (string) ($front_matter['published'] ?? '');
	try {
		$date = '' !== $value ? new DateTimeImmutable($value) : null;
	} catch (Throwable) {
		$date = null;
	}
	if (! $date instanceof DateTimeImmutable && preg_match('/-(\d{4})-(\d{2})-(\d{2})-/', basename($path), $match)) {
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $match[1] . '-' . $match[2] . '-' . $match[3], new DateTimeZone('UTC')) ?: null;
	}
	return $date instanceof DateTimeImmutable ? $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') : '';
}

/** @return array{0:list<array{url:string,label:string}>,1:string} */
function markdown_links(string $body): array {
	$links = array();
	$masked = $body;
	$length = strlen($body);
	for ($offset = 0; $offset < $length;) {
		$open = strpos($body, '[', $offset);
		if (false === $open) {
			break;
		}
		if ($open > 0 && '\\' === $body[$open - 1]) {
			$offset = $open + 1;
			continue;
		}
		$close = balanced_end($body, $open, '[', ']');
		if (null === $close) {
			break;
		}
		$cursor = $close + 1;
		while ($cursor < $length && ctype_space($body[$cursor])) {
			++$cursor;
		}
		if ($cursor >= $length || '(' !== $body[$cursor]) {
			$offset = $close + 1;
			continue;
		}
		$end = balanced_end($body, $cursor, '(', ')');
		if (null === $end) {
			$offset = $close + 1;
			continue;
		}
		$destination = markdown_destination(substr($body, $cursor + 1, $end - $cursor - 1));
		if ('' !== $destination) {
			$links[] = array('url' => $destination, 'label' => substr($body, $open + 1, $close - $open - 1));
		}
		$masked = substr_replace($masked, str_repeat(' ', $end - $open + 1), $open, $end - $open + 1);
		$offset = $end + 1;
	}
	return array($links, $masked);
}

function balanced_end(string $text, int $start, string $opening, string $closing): ?int {
	$depth = 0;
	$length = strlen($text);
	for ($index = $start; $index < $length; ++$index) {
		if ('\\' === $text[$index]) {
			++$index;
			continue;
		}
		if ($opening === $text[$index]) {
			++$depth;
		} elseif ($closing === $text[$index] && 0 === --$depth) {
			return $index;
		}
	}
	return null;
}

function markdown_destination(string $value): string {
	$value = ltrim($value);
	if (str_starts_with($value, '<')) {
		$end = strpos($value, '>');
		return false === $end ? '' : substr($value, 1, $end - 1);
	}
	$url = '';
	$depth = 0;
	$length = strlen($value);
	for ($index = 0; $index < $length; ++$index) {
		$character = $value[$index];
		if ('\\' === $character && $index + 1 < $length) {
			$url .= $value[++$index];
			continue;
		}
		if (ctype_space($character) && 0 === $depth) {
			break;
		}
		if ('(' === $character) {
			++$depth;
		} elseif (')' === $character && $depth > 0) {
			--$depth;
		}
		$url .= $character;
	}
	return $url;
}

/** @return null|array{0:string,1:string,2:string} */
function prepare_url(string $input): ?array {
	$url = trim(html_entity_decode($input, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	if (! filter_var($url, FILTER_VALIDATE_URL)) {
		return null;
	}
	$parts = parse_url($url);
	if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || ! in_array(strtolower($parts['scheme']), array('http', 'https'), true) || isset($parts['user']) || isset($parts['pass'])) {
		return null;
	}
	$host = strtolower($parts['host']);
	if ('exit.sc' === $host && isset($parts['query'])) {
		parse_str($parts['query'], $query);
		if (isset($query['url']) && is_string($query['url'])) {
			return prepare_url($query['url']);
		}
	}
	if (in_array($host, array('halbwissen.co', 'www.halbwissen.co', 'halbwissen-podcast.de', 'www.halbwissen-podcast.de'), true)) {
		return array($url, $url, 'internal');
	}
	$path = strtolower((string) ($parts['path'] ?? '/'));
	if (str_contains($path, '/wp-content/uploads/') || preg_match('/\.(?:avif|gif|jpe?g|png|svg|webp|mp3|m4a|ogg|wav)(?:$|\?)/i', $path)) {
		return array($url, $url, 'media');
	}
	$scheme = strtolower($parts['scheme']);
	$port = isset($parts['port']) && !(('http' === $scheme && 80 === $parts['port']) || ('https' === $scheme && 443 === $parts['port'])) ? ':' . $parts['port'] : '';
	$normalized_path = '' === ($parts['path'] ?? '') ? '/' : (string) $parts['path'];
	$key = $scheme . '://' . $host . $port . $normalized_path;
	if (isset($parts['query'])) {
		$key .= '?' . $parts['query'];
	}
	if (isset($parts['fragment'])) {
		$key .= '#' . $parts['fragment'];
	}
	return array($url, $key, '');
}

function clean_label(string $label): string {
	$label = preg_replace('/!\[[^]]*\]\([^)]*\)/u', '', $label) ?? $label;
	$label = preg_replace('/\\\\([\\\\`*_\[\]{}()#+.!-])/u', '$1', $label) ?? $label;
	$label = preg_replace('/[`*_~\[\]]+/u', '', $label) ?? $label;
	$label = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '', " \t\n\r\0\x0B\\");
	if (filter_var($label, FILTER_VALIDATE_URL) || in_array(mb_strtolower($label), array('hier', 'klick hier', 'link'), true)) {
		return '';
	}
	return mb_substr($label, 0, 200);
}

function safe_cell(string $value): string {
	return preg_match('/^[=+\-@]/u', $value) ? "'" . $value : $value;
}
