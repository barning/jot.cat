<?php

declare(strict_types=1);

namespace JotLibrary;

use RuntimeException;

final class Importer {
	/**
	 * @return array{created:int,skipped:int,conflicts:int,invalid:int,messages:list<string>}
	 */
	public function import_file(string $path, bool $dry_run = true, string $status = 'draft'): array {
		if (! is_readable($path)) {
			throw new RuntimeException(sprintf(__('Import file is not readable: %s', 'jot-library'), $path));
		}

		$data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		if (! is_array($data) || 1 !== ($data['version'] ?? null) || ! is_array($data['entries'] ?? null)) {
			throw new RuntimeException(__('The import file does not use the Jot export version 1 format.', 'jot-library'));
		}

		$result = array('created' => 0, 'skipped' => 0, 'conflicts' => 0, 'invalid' => 0, 'messages' => array());
		$service = new Entry_Service();
		foreach ($data['entries'] as $index => $entry) {
			$number = $index + 1;
			if (! is_array($entry)) {
				++$result['invalid'];
				$result['messages'][] = sprintf(__('Row %d: expected an object.', 'jot-library'), $number);
				continue;
			}

			$code = (string) ($entry['code'] ?? '');
			$url = (string) ($entry['url'] ?? '');
			$saved_at = (string) ($entry['created_at'] ?? '');
			$date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $saved_at, new \DateTimeZone('UTC'));
			if (false === $date || $date->format('Y-m-d H:i:s') !== $saved_at) {
				++$result['invalid'];
				$result['messages'][] = sprintf(__('Row %1$d (%2$s): the saved timestamp must use YYYY-MM-DD HH:MM:SS in UTC.', 'jot-library'), $number, $code);
				continue;
			}
			$inspection = $service->inspect_import($code, $url);
			if ('invalid' === $inspection['action']) {
				++$result['invalid'];
				$result['messages'][] = sprintf(__('Row %1$d (%2$s): %3$s', 'jot-library'), $number, $code, $inspection['message']);
				continue;
			}
			if ('conflict' === $inspection['action']) {
				++$result['conflicts'];
				$result['messages'][] = sprintf(__('Row %1$d (%2$s): %3$s', 'jot-library'), $number, $code, $inspection['message']);
				continue;
			}
			if ('skip' === $inspection['action']) {
				++$result['skipped'];
				$result['messages'][] = sprintf(__('Row %1$d (%2$s): already imported.', 'jot-library'), $number, $code);
				continue;
			}

			if (! $dry_run) {
				$created = $service->import($code, $url, $saved_at, $status);
				if (is_wp_error($created)) {
					++$result['conflicts'];
					$result['messages'][] = sprintf(__('Row %1$d (%2$s): %3$s', 'jot-library'), $number, $code, $created->get_error_message());
					continue;
				}
			}
			++$result['created'];
			$result['messages'][] = $dry_run
				? sprintf(__('Row %1$d (%2$s): would import.', 'jot-library'), $number, $code)
				: sprintf(__('Row %1$d (%2$s): imported.', 'jot-library'), $number, $code);
		}

		return $result;
	}
}
