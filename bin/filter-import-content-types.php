<?php

declare(strict_types=1);

/**
 * Keep article pages and standalone websites in a Jot Library import CSV.
 *
 * Platform media, wikis, social/messaging services, redirectors, stores,
 * booking pages, and app marketplaces are excluded by hostname. The filter is
 * deliberately domain-based so article titles and notes are not misclassified.
 *
 * Usage:
 * php bin/filter-import-content-types.php --input=/path/import.csv --output=/path/import.csv
 */

$options = getopt('', array('input:', 'output:'));
$input = isset($options['input']) ? (string) $options['input'] : '';
$output = isset($options['output']) ? (string) $options['output'] : '';
if ('' === $input || '' === $output || ! is_file($input)) {
	fwrite(STDERR, "Usage: php bin/filter-import-content-types.php --input=/path/import.csv --output=/path/import.csv\n");
	exit(2);
}

$excluded_domains = array(
	'redirector' => array(
		'bit.do',
		'google.de',
		'linktr.ee',
	),
	'wiki/reference' => array(
		'wikipedia.org',
		'wikimedia.org',
		'wikiquote.org',
		'wiktionary.org',
		'wikia.com',
		'fandom.com',
		'spielwiki.de',
		'imdb.com',
	),
	'video/audio/media' => array(
		'ffffound.com',
		'youtube.com',
		'youtu.be',
		'vimeo.com',
		'periscope.tv',
		'twitch.tv',
		'soundcloud.com',
		'spotify.com',
		'netflix.com',
		'archive.org',
		'flickr.com',
		'imgur.com',
		'pixabay.com',
		'pexels.com',
		'pxhere.com',
		'podigee.io',
		'vier.fm',
		'insertmoin.de',
		'kabinett.hadar.uberspace.de',
		'buddler.atria.uberspace.de',
		'alltagsfiktion.de',
		'radio-castriert.de',
		'viertausendhertz.de',
		'feminismusundcomputerkram.org',
		'tv.apple.com',
		'funk.net',
		'byspotify.com',
		'site.billieeilish.com',
		'werder-raute.de',
	),
	'social/messaging' => array(
		'facebook.com',
		'instagram.com',
		'twitter.com',
		'x.com',
		'reddit.com',
		'telegram.me',
		't.me',
		'mastodon.social',
		'norden.social',
		'tellonym.me',
		'studivz.net',
		'steamcommunity.com',
		'drive.google.com',
		'workflowy.com',
	),
	'store/booking/catalog' => array(
		'amazon.de',
		'amazon.com',
		'amzn.to',
		'play.google.com',
		'apps.apple.com',
		'itunes.apple.com',
		'airbnb.de',
		'airbnb.com',
		'booking.com',
		'tripadvisor.de',
		'tripadvisor.com',
		'aliexpress.com',
		'massdrop.com',
		'drop.com',
		'rossmann.de',
		'sony.de',
		'tokyotreat.com',
		'dhl.de',
		'shop.spreadshirt.de',
		'getshirts.de',
		'brands4friends.de',
		'thomann.de',
		'pretix.eu',
		'marktguru.de',
		'braumischung.de',
		'craftbeer-shop.com',
		'nike.com',
		'werder.de',
	),
);

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

$kept = array();
$seen_urls = array();
$duplicates = 0;
$excluded_counts = array_fill_keys(array_keys($excluded_domains), 0);
$excluded_hosts = array();
$total = 0;
while (false !== ($row = fgetcsv($stream, 0, ',', '"', '\\'))) {
	++$total;
	$row = array_pad($row, count($header), '');
	$row = array_slice($row, 0, count($header));
	$host = normalize_host((string) parse_url((string) $row[$url_index], PHP_URL_HOST));
	$category = excluded_category($host, $excluded_domains);
	if (null === $category) {
		$url_key = normalized_url_key((string) $row[$url_index]);
		if (isset($seen_urls[$url_key])) {
			++$duplicates;
			continue;
		}
		$seen_urls[$url_key] = true;
		$kept[] = $row;
		continue;
	}
	++$excluded_counts[$category];
	$excluded_hosts[$host] = ($excluded_hosts[$host] ?? 0) + 1;
}
fclose($stream);

$temporary = tempnam(dirname($output), '.jot-content-filter-');
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

arsort($excluded_hosts);
printf(
	"Reviewed %d links; kept %d article/website links, excluded %d platform links, and removed %d duplicates.\nExcluded by category: %s\nMost frequent excluded hosts: %s\n",
	$total,
	count($kept),
	$total - count($kept) - $duplicates,
	$duplicates,
	json_encode($excluded_counts, JSON_UNESCAPED_SLASHES),
	json_encode(array_slice($excluded_hosts, 0, 20, true), JSON_UNESCAPED_SLASHES)
);

function normalize_host(string $host): string {
	$host = strtolower(rtrim($host, '.'));
	return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
}

function normalized_url_key(string $url): string {
	$parts = parse_url($url);
	if (! is_array($parts) || ! isset($parts['host'])) {
		return strtolower($url);
	}
	$host = normalize_host((string) $parts['host']);
	$path = (string) ($parts['path'] ?? '/');
	$path = '/' === $path ? '/' : rtrim($path, '/');
	$query = isset($parts['query']) ? '?' . $parts['query'] : '';
	return $host . $path . $query;
}

/**
 * @param array<string,list<string>> $excluded_domains
 */
function excluded_category(string $host, array $excluded_domains): ?string {
	foreach ($excluded_domains as $category => $domains) {
		foreach ($domains as $domain) {
			if ($host === $domain || str_ends_with($host, '.' . $domain)) {
				return $category;
			}
		}
	}
	return null;
}
