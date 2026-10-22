<?php

declare(strict_types=1);

namespace JotLibrary;

use Throwable;

final class Import_Command {
	/**
	 * Import a legacy Jot JSON export.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the JSON export.
	 *
	 * [--dry-run]
	 * : Validate and report without writing.
	 *
	 * [--status=<status>]
	 * : Imported visibility. Accepts draft or private. Default: draft.
	 *
	 * ## EXAMPLES
	 *
	 *     wp jot-library import legacy.json --dry-run
	 *     wp jot-library import legacy.json --status=private
	 *
	 * @when after_wp_load
	 * @param list<string> $args
	 * @param array<string,mixed> $assoc_args
	 */
	public function __invoke(array $args, array $assoc_args): void {
		$file = (string) ($args[0] ?? '');
		if ('' === $file) {
			\WP_CLI::error(__('Provide a path to a Jot JSON export.', 'jot-library'));
		}
		$status = sanitize_key((string) ($assoc_args['status'] ?? 'draft'));
		if (! in_array($status, array('draft', 'private'), true)) {
			\WP_CLI::error(__('The import status must be draft or private.', 'jot-library'));
		}
		$dry_run = isset($assoc_args['dry-run']);

		try {
			$result = (new Importer())->import_file($file, $dry_run, $status);
		} catch (Throwable $error) {
			\WP_CLI::error($error->getMessage());
			return;
		}

		foreach ($result['messages'] as $message) {
			\WP_CLI::log($message);
		}
		\WP_CLI::log(sprintf('Created: %d; skipped: %d; conflicts: %d; invalid: %d', $result['created'], $result['skipped'], $result['conflicts'], $result['invalid']));
		if ($result['conflicts'] > 0 || $result['invalid'] > 0) {
			\WP_CLI::warning(__('Import completed with rows that need review. No existing entry was overwritten.', 'jot-library'));
		}
		\WP_CLI::success($dry_run ? __('Dry run complete; no content was changed.', 'jot-library') : __('Import complete.', 'jot-library'));
	}
}
