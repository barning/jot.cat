<?php

declare(strict_types=1);

/**
 * Resolve links from one short-link hostname in a Jot Library import CSV.
 *
 * Successful resolutions replace the short URL with the final effective URL.
 * Unresolved rows remain unchanged so a later content filter can exclude them.
 * Duplicate final URLs are collapsed, keeping the earliest saved_at row.
 *
 * Usage:
 * php bin/resolve-import-shortlinks.php --input=import.csv --output=resolved.csv --host=bit.do
 */

$options = getopt('', array('input:', 'output:', 'host:', 'concurrency::', 'timeout::'));
$input = isset($options['input']) ? (string) $options['input'] : '';
$output = isset($options['output']) ? (string) $options['output'] : '';
$short_host = strtolower((string) ($options['host'] ?? ''));
$concurrency = min(30, max(1, (int) ($options['concurrency'] ?? 15)));
$timeout = min(60, max(5, (int) ($options['timeout'] ?? 20)));
if ('' === $input || '' === $output || '' === $short_host || ! is_file($input) || ! function_exists('curl_multi_init')) {
	fwrite(STDERR, "Usage: php bin/resolve-import-shortlinks.php --input=import.csv --output=resolved.csv --host=bit.do [--concurrency=15] [--timeout=20]\n");
	exit(2);
}

$stream = fopen($input, 'rb');
if (false === $stream) {
	fwrite(STDERR, "Could not open input file: {$input}\n");
	exit(1);
}
$header = fgetcsv($stream, 0, ',', '"', '\\');
if (! is_array($header)) {
	fclose($stream);
	fwrite(STDERR, "The input CSV is empty.\n");
	exit(1);
}
$url_index = array_search('url', $header, true);
$saved_at_index = array_search('saved_at', $header, true);
if (false === $url_index) {
	fclose($stream);
	fwrite(STDERR, "The input CSV has no url column.\n");
	exit(1);
}

$rows = array();
$target_indexes = array();
$target_urls = array();
while (false !== ($row = fgetcsv($stream, 0, ',', '"', '\\'))) {
	$row = array_pad($row, count($header), '');
	$row = array_slice($row, 0, count($header));
	$rows[] = $row;
	$host = strtolower((string) parse_url((string) $row[$url_index], PHP_URL_HOST));
	$host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
	if ($host === $short_host) {
		$target_indexes[] = count($rows) - 1;
		$target_urls[] = (string) $row[$url_index];
	}
}
fclose($stream);

$results = resolve_urls($target_urls, $concurrency, $timeout);
$resolved = 0;
$unresolved = 0;
$final_statuses = array();
$destinations = array();
foreach ($target_indexes as $result_index => $row_index) {
	$result = $results[$result_index];
	$status_key = 0 === $result['status'] ? 'connection-failure' : (string) $result['status'];
	$final_statuses[$status_key] = ($final_statuses[$status_key] ?? 0) + 1;
	$final_url = $result['url'];
	$final_host = strtolower((string) parse_url($final_url, PHP_URL_HOST));
	$final_host = str_starts_with($final_host, 'www.') ? substr($final_host, 4) : $final_host;
	if ('' === $final_url || $final_host === $short_host || 200 > $result['status'] || 400 <= $result['status']) {
		++$unresolved;
		continue;
	}
	$rows[$row_index][$url_index] = $final_url;
	$destinations[$final_host] = ($destinations[$final_host] ?? 0) + 1;
	++$resolved;
}

if (false !== $saved_at_index) {
	usort(
		$rows,
		static fn (array $left, array $right): int => strcmp((string) $left[$saved_at_index], (string) $right[$saved_at_index])
	);
}
$deduplicated = array();
$seen = array();
foreach ($rows as $row) {
	$key = normalized_url_key((string) $row[$url_index]);
	if (isset($seen[$key])) {
		continue;
	}
	$seen[$key] = true;
	$deduplicated[] = $row;
}

$temporary = tempnam(dirname($output), '.jot-shortlink-resolution-');
if (false === $temporary) {
	fwrite(STDERR, "Could not create a temporary output file.\n");
	exit(1);
}
$target = fopen($temporary, 'wb');
if (false === $target) {
	unlink($temporary);
	fwrite(STDERR, "Could not open the temporary output file.\n");
	exit(1);
}
fputcsv($target, $header, ',', '"', '\\');
foreach ($deduplicated as $row) {
	fputcsv($target, $row, ',', '"', '\\');
}
fclose($target);
if (! rename($temporary, $output)) {
	unlink($temporary);
	fwrite(STDERR, "Could not replace the output CSV.\n");
	exit(1);
}

ksort($final_statuses, SORT_NATURAL);
arsort($destinations);
printf(
	"Found %d %s links; resolved %d and left %d unresolved. Removed %d duplicate final URLs.\nFinal status counts: %s\nTop destination hosts: %s\n",
	count($target_urls),
	$short_host,
	$resolved,
	$unresolved,
	count($rows) - count($deduplicated),
	json_encode($final_statuses, JSON_UNESCAPED_SLASHES),
	json_encode(array_slice($destinations, 0, 25, true), JSON_UNESCAPED_SLASHES)
);

/**
 * @param list<string> $urls
 * @return list<array{status:int,url:string,error:string}>
 */
function resolve_urls(array $urls, int $concurrency, int $timeout): array {
	$multi = curl_multi_init();
	$active = array();
	$results = array_fill(0, count($urls), array('status' => 0, 'url' => '', 'error' => 'not checked'));
	$next = 0;
	$completed = 0;

	while ($next < count($urls) || array() !== $active) {
		while ($next < count($urls) && count($active) < $concurrency) {
			$handle = curl_init($urls[$next]);
			curl_setopt_array(
				$handle,
				array(
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_MAXREDIRS => 10,
					CURLOPT_RETURNTRANSFER => false,
					CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
					CURLOPT_TIMEOUT => $timeout,
					CURLOPT_RANGE => '0-0',
					CURLOPT_USERAGENT => 'Jot Library short-link resolver/1.0',
					CURLOPT_HTTPHEADER => array('Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.1'),
					CURLOPT_ENCODING => '',
					CURLOPT_SSL_VERIFYPEER => true,
					CURLOPT_SSL_VERIFYHOST => 2,
					CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
					CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
					CURLOPT_WRITEFUNCTION => static fn ($curl, string $data): int => strlen($data),
				)
			);
			$id = spl_object_id($handle);
			$active[$id] = array('handle' => $handle, 'index' => $next);
			curl_multi_add_handle($multi, $handle);
			++$next;
		}

		do {
			$state = curl_multi_exec($multi, $running);
		} while (CURLM_CALL_MULTI_PERFORM === $state);
		if (CURLM_OK !== $state) {
			break;
		}
		if ($running > 0) {
			curl_multi_select($multi, 1.0);
		}

		while (false !== ($info = curl_multi_info_read($multi))) {
			$handle = $info['handle'];
			$id = spl_object_id($handle);
			$index = $active[$id]['index'];
			$results[$index] = array(
				'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
				'url' => (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL),
				'error' => curl_error($handle),
			);
			curl_multi_remove_handle($multi, $handle);
			unset($active[$id]);
			++$completed;
			if (0 === $completed % 50 || $completed === count($urls)) {
				fwrite(STDERR, sprintf("Resolved %d/%d short links.\n", $completed, count($urls)));
			}
		}
	}

	foreach ($active as $item) {
		curl_multi_remove_handle($multi, $item['handle']);
	}
	curl_multi_close($multi);
	ksort($results);
	return array_values($results);
}

function normalized_url_key(string $url): string {
	$parts = parse_url($url);
	if (! is_array($parts) || ! isset($parts['host'])) {
		return strtolower($url);
	}
	$scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
	$host = strtolower((string) $parts['host']);
	$host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
	$path = (string) ($parts['path'] ?? '/');
	$path = '/' === $path ? '/' : rtrim($path, '/');
	$query = isset($parts['query']) ? '?' . $parts['query'] : '';
	return $scheme . '://' . $host . $path . $query;
}
