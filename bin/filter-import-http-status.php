<?php

declare(strict_types=1);

/**
 * Remove redirecting and 404 URLs from a Jot Library import CSV.
 *
 * The initial response is checked with a small GET request and redirects are not followed.
 * Other statuses and connection failures are retained and reported.
 *
 * Usage:
 * php bin/filter-import-http-status.php --input=/path/import.csv --output=/path/import.csv
 */

$options = getopt('', array('input:', 'output:', 'concurrency::', 'timeout::'));
$input = isset($options['input']) ? (string) $options['input'] : '';
$output = isset($options['output']) ? (string) $options['output'] : '';
$concurrency = min(40, max(1, (int) ($options['concurrency'] ?? 20)));
$timeout = min(60, max(3, (int) ($options['timeout'] ?? 15)));
if ('' === $input || '' === $output || ! is_file($input) || ! function_exists('curl_multi_init')) {
	fwrite(STDERR, "Usage: php bin/filter-import-http-status.php --input=/path/import.csv --output=/path/import.csv [--concurrency=20] [--timeout=15]\n");
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
if (false === $url_index) {
	fclose($stream);
	fwrite(STDERR, "The input CSV has no url column.\n");
	exit(1);
}
$rows = array();
while (false !== ($row = fgetcsv($stream, 0, ',', '"', '\\'))) {
	$row = array_pad($row, count($header), '');
	$rows[] = array_slice($row, 0, count($header));
}
fclose($stream);

$results = check_urls(array_map(static fn (array $row): string => (string) $row[$url_index], $rows), $concurrency, $timeout);
$kept = array();
$excluded = array();
$statuses = array();
$failures = 0;
foreach ($rows as $index => $row) {
	$result = $results[$index];
	$status = $result['status'];
	$key = 0 === $status ? 'connection-failure' : (string) $status;
	$statuses[$key] = ($statuses[$key] ?? 0) + 1;
	if ((300 <= $status && 400 > $status) || 404 === $status) {
		$excluded[] = array('url' => $row[$url_index], 'status' => $status);
		continue;
	}
	if (0 === $status) {
		++$failures;
	}
	$kept[] = $row;
}

$temporary = tempnam(dirname($output), '.jot-http-filter-');
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
foreach ($kept as $row) {
	fputcsv($target, $row, ',', '"', '\\');
}
fclose($target);
if (! rename($temporary, $output)) {
	unlink($temporary);
	fwrite(STDERR, "Could not replace the output CSV.\n");
	exit(1);
}

ksort($statuses, SORT_NATURAL);
printf(
	"Checked %d URLs; kept %d and excluded %d redirects/404s. Retained %d connection failures for manual review.\nStatus counts: %s\n",
	count($rows),
	count($kept),
	count($excluded),
	$failures,
	json_encode($statuses, JSON_UNESCAPED_SLASHES)
);

/**
 * @param list<string> $urls
 * @return list<array{status:int,error:string}>
 */
function check_urls(array $urls, int $concurrency, int $timeout): array {
	$multi = curl_multi_init();
	$active = array();
	$results = array_fill(0, count($urls), array('status' => 0, 'error' => 'not checked'));
	$next = 0;
	$completed = 0;

	while ($next < count($urls) || array() !== $active) {
		while ($next < count($urls) && count($active) < $concurrency) {
			$handle = curl_init($urls[$next]);
			curl_setopt_array(
				$handle,
				array(
					CURLOPT_FOLLOWLOCATION => false,
					CURLOPT_RETURNTRANSFER => false,
					CURLOPT_CONNECTTIMEOUT => min(6, $timeout),
					CURLOPT_TIMEOUT => $timeout,
					CURLOPT_RANGE => '0-0',
					CURLOPT_USERAGENT => 'Jot Library import link check/1.0',
					CURLOPT_HTTPHEADER => array('Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.1'),
					CURLOPT_ENCODING => '',
					CURLOPT_SSL_VERIFYPEER => true,
					CURLOPT_SSL_VERIFYHOST => 2,
					CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
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
				'error' => curl_error($handle),
			);
			curl_multi_remove_handle($multi, $handle);
			unset($active[$id]);
			++$completed;
			if (0 === $completed % 100 || $completed === count($urls)) {
				fwrite(STDERR, sprintf("Checked %d/%d URLs.\n", $completed, count($urls)));
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
