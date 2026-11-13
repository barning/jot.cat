<?php

declare(strict_types=1);

namespace JotLibrary;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class Csv_Transfer {
	private const HEADERS = array('code', 'url', 'title', 'description', 'notes', 'topics', 'status', 'reading_status', 'saved_at');
	private const MAX_ROWS = 5000;

	/** @param resource $stream */
	public function export_to_stream($stream): int {
		fputcsv($stream, self::HEADERS, ',', '"', '\\');
		$posts = get_posts(
			array(
				'post_type' => Post_Type::TYPE,
				'post_status' => array('publish', 'draft', 'private', 'pending', 'future'),
				'posts_per_page' => -1,
				'orderby' => 'ID',
				'order' => 'ASC',
			)
		);

		foreach ($posts as $post) {
			$terms = get_the_terms($post->ID, Post_Type::TAXONOMY);
			$topics = is_array($terms) ? implode('|', array_map(static fn ($term): string => (string) $term->name, $terms)) : '';
			fputcsv(
				$stream,
				array(
					(string) get_post_meta($post->ID, Entry_Service::META_CODE, true),
					(string) get_post_meta($post->ID, Entry_Service::META_URL, true),
					$this->safe_export_cell($post->post_title),
					$this->safe_export_cell($post->post_excerpt),
					$this->safe_export_cell($post->post_content),
					$this->safe_export_cell($topics),
					$post->post_status,
					(string) get_post_meta($post->ID, Entry_Service::META_READING_STATUS, true),
					(string) get_post_meta($post->ID, Entry_Service::META_SAVED_AT, true),
				),
				',',
				'"',
				'\\'
			);
		}

		return count($posts);
	}

	/**
	 * @return array{created:int,skipped:int,conflicts:int,invalid:int,messages:list<string>}
	 */
	public function import_file(string $path, bool $dry_run = true, string $visibility = 'draft'): array {
		if (! in_array($visibility, array('draft', 'private', 'preserve'), true)) {
			throw new RuntimeException(__('Choose draft, private, or preserve as the import visibility.', 'jot-library'));
		}
		$handle = fopen($path, 'rb');
		if (false === $handle) {
			throw new RuntimeException(__('The CSV file could not be opened.', 'jot-library'));
		}

		try {
			$header = fgetcsv($handle, 0, ',', '"', '\\');
			if (! is_array($header)) {
				throw new RuntimeException(__('The CSV file is empty.', 'jot-library'));
			}
			$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($header[0] ?? '')) ?? '';
			$header = array_map(static fn ($value): string => sanitize_key(trim((string) $value)), $header);
			if (count($header) !== count(array_unique($header))) {
				throw new RuntimeException(__('The CSV header contains duplicate columns.', 'jot-library'));
			}
			if (! in_array('url', $header, true)) {
				throw new RuntimeException(__('The CSV file is missing the required “url” column.', 'jot-library'));
			}

			$result = array('created' => 0, 'skipped' => 0, 'conflicts' => 0, 'invalid' => 0, 'messages' => array());
			$service = new Entry_Service();
			$row_number = 1;
			while (false !== ($values = fgetcsv($handle, 0, ',', '"', '\\'))) {
				++$row_number;
				if ($row_number > self::MAX_ROWS + 1) {
					throw new RuntimeException(sprintf(__('CSV imports are limited to %d entries at a time.', 'jot-library'), self::MAX_ROWS));
				}
				if (array() === array_filter($values, static fn ($value): bool => '' !== trim((string) $value))) {
					continue;
				}
				$values = array_pad($values, count($header), '');
				$row = array_combine($header, array_slice($values, 0, count($header)));
				if (! is_array($row)) {
					++$result['invalid'];
					$this->message($result['messages'], sprintf(__('Row %d: the columns could not be read.', 'jot-library'), $row_number));
					continue;
				}

				$code = Code::normalize((string) ($row['code'] ?? ''));
				$url = trim((string) ($row['url'] ?? ''));
				$saved_at = trim((string) ($row['saved_at'] ?? ''));
				$date = '' === $saved_at ? null : DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $saved_at, new DateTimeZone('UTC'));
				if (('' !== $code && ! Code::is_valid($code)) || ('' !== $saved_at && (false === $date || $date->format('Y-m-d H:i:s') !== $saved_at))) {
					++$result['invalid'];
					$this->message($result['messages'], sprintf(__('Row %d: use a valid Jot code and a UTC saved_at value formatted as YYYY-MM-DD HH:MM:SS.', 'jot-library'), $row_number));
					continue;
				}

				$inspection = '' === $code ? $service->inspect_new_url($url) : $service->inspect_import($code, $url);
				$reference = '' === $code ? __('new code', 'jot-library') : $code;
				if ('skip' === $inspection['action']) {
					++$result['skipped'];
					$this->message($result['messages'], sprintf(__('Row %d (%s): already exists.', 'jot-library'), $row_number, $reference));
					continue;
				}
				if ('create' !== $inspection['action']) {
					$key = 'invalid' === $inspection['action'] ? 'invalid' : 'conflicts';
					++$result[$key];
					$this->message($result['messages'], sprintf(__('Row %1$d (%2$s): %3$s', 'jot-library'), $row_number, $reference, $inspection['message']));
					continue;
				}

				$status = 'preserve' === $visibility ? sanitize_key((string) ($row['status'] ?? 'draft')) : $visibility;
				if (! in_array($status, array('publish', 'draft', 'private'), true)) {
					$status = 'draft';
				}
				$enrichment_queued = false;
				if (! $dry_run) {
					$data = array(
							'title' => $this->restore_export_cell((string) ($row['title'] ?? '')),
							'description' => $this->restore_export_cell((string) ($row['description'] ?? '')),
							'notes' => $this->restore_export_cell((string) ($row['notes'] ?? '')),
							'tags' => array_values(array_filter(array_map('trim', explode('|', $this->restore_export_cell((string) ($row['topics'] ?? '')))))),
							'reading_status' => (string) ($row['reading_status'] ?? 'unread'),
							'status' => $status,
						);
					if ('' !== $saved_at) {
						$data['saved_at'] = $saved_at;
					}
					$created = '' === $code
						? $service->create_or_find(array_merge($data, array('url' => $url)))
						: $service->import($code, $url, '' !== $saved_at ? $saved_at : gmdate('Y-m-d H:i:s'), $status, $data);
					if (is_wp_error($created)) {
						++$result['conflicts'];
						$this->message($result['messages'], sprintf(__('Row %1$d (%2$s): %3$s', 'jot-library'), $row_number, $reference, $created->get_error_message()));
						continue;
					}
					if (! $created['created']) {
						++$result['skipped'];
						$this->message($result['messages'], sprintf(__('Row %d (%s): already exists.', 'jot-library'), $row_number, (string) $created['code']));
						continue;
					}
					$reference = (string) $created['code'];
					$enrichment_queued = Metadata_Enricher::enqueue((int) $created['id']);
				}
				++$result['created'];
				if ($dry_run) {
					$message = sprintf(__('Row %d (%s): ready to import.', 'jot-library'), $row_number, $reference);
				} elseif ($enrichment_queued) {
					$message = sprintf(__('Row %d (%s): imported and queued for metadata enrichment.', 'jot-library'), $row_number, $reference);
				} else {
					$message = sprintf(__('Row %d (%s): imported, but metadata enrichment could not be queued.', 'jot-library'), $row_number, $reference);
				}
				$this->message($result['messages'], $message);
			}
		} finally {
			fclose($handle);
		}

		return $result;
	}

	/** @param list<string> $messages */
	private function message(array &$messages, string $message): void {
		if (count($messages) < 100) {
			$messages[] = $message;
		}
	}

	private function safe_export_cell(string $value): string {
		return preg_match('/^[=+\-@]/u', $value) ? "'" . $value : $value;
	}

	private function restore_export_cell(string $value): string {
		return preg_match('/^\x27[=+\-@]/u', $value) ? substr($value, 1) : $value;
	}
}
