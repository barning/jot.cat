<?php

declare(strict_types=1);

function usage(): never {
	fwrite(STDERR, "Usage: php bin/export-legacy-jot.php --database=/path/to/jot.sqlite [--output=/path/to/export.json] [--force]\n");
	exit(2);
}

$options = getopt('', array('database:', 'output:', 'force'));
$database = isset($options['database']) ? (string) $options['database'] : '';
$output = isset($options['output']) ? (string) $options['output'] : '';

if ('' === $database || ! is_readable($database)) {
	usage();
}
if ('' !== $output && file_exists($output) && ! isset($options['force'])) {
	fwrite(STDERR, "Refusing to overwrite {$output}. Use --force after checking the target.\n");
	exit(1);
}

try {
	$pdo = new PDO('sqlite:' . $database, options: array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
	$statement = $pdo->query('SELECT id, url, created_at FROM articles ORDER BY created_at ASC, id ASC');
	$entries = array();
	foreach ($statement as $row) {
		$code = strtoupper((string) $row['id']);
		if (1 !== preg_match('/^[SML][0123456789ABCDEFGHJKMNPQRSTVWXYZ]{3}$/', $code)) {
			throw new RuntimeException("Invalid legacy code in database: {$code}");
		}
		$url = (string) $row['url'];
		$parts = parse_url($url);
		if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), array('http', 'https'), true) || '' === ($parts['host'] ?? '')) {
			throw new RuntimeException("Invalid URL for {$code}");
		}
		$created_at = (string) $row['created_at'];
		$date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $created_at, new DateTimeZone('UTC'));
		if (false === $date || $date->format('Y-m-d H:i:s') !== $created_at) {
			throw new RuntimeException("Invalid created_at timestamp for {$code}");
		}
		$entries[] = array('code' => $code, 'url' => $url, 'created_at' => $created_at);
	}

	$document = array(
		'version' => 1,
		'generated_at' => gmdate('c'),
		'entries' => $entries,
	);
	$json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
	if ('' === $output) {
		fwrite(STDOUT, $json);
	} elseif (false === file_put_contents($output, $json, LOCK_EX)) {
		throw new RuntimeException("Could not write {$output}");
	} else {
		fwrite(STDERR, sprintf("Exported %d entries to %s\n", count($entries), $output));
	}
} catch (Throwable $error) {
	fwrite(STDERR, "Export failed: {$error->getMessage()}\n");
	exit(1);
}
