<?php

declare(strict_types=1);

namespace JotLibrary;

final class Settings {
	public const OPTION = 'jot_library_settings';

	public static function register(): void {
		register_setting(
			'jot_library',
			self::OPTION,
			array('type' => 'array', 'sanitize_callback' => array(self::class, 'sanitize'), 'default' => self::defaults())
		);
		add_settings_section('jot_library_general', __('Library settings', 'jot-library'), '__return_false', 'jot-library');
		add_settings_field('entries_per_page', __('Entries per page', 'jot-library'), array(self::class, 'page_size_field'), 'jot-library', 'jot_library_general');
		add_settings_field('delete_on_uninstall', __('Uninstall behavior', 'jot-library'), array(self::class, 'delete_field'), 'jot-library', 'jot_library_general');
	}

	/** @return array{entries_per_page:int,delete_on_uninstall:bool} */
	public static function defaults(): array {
		return array('entries_per_page' => 12, 'delete_on_uninstall' => false);
	}

	/** @return array{entries_per_page:int,delete_on_uninstall:bool} */
	public static function get(): array {
		return wp_parse_args((array) get_option(self::OPTION, array()), self::defaults());
	}

	/** @param mixed $input
	 * @return array{entries_per_page:int,delete_on_uninstall:bool}
	 */
	public static function sanitize(mixed $input): array {
		$input = is_array($input) ? $input : array();
		return array(
			'entries_per_page' => min(48, max(1, absint($input['entries_per_page'] ?? 12))),
			'delete_on_uninstall' => ! empty($input['delete_on_uninstall']),
		);
	}

	public static function page_size_field(): void {
		$value = self::get()['entries_per_page'];
		echo '<input type="number" min="1" max="48" name="' . esc_attr(self::OPTION) . '[entries_per_page]" value="' . esc_attr((string) $value) . '" class="small-text">';
		echo '<p class="description">' . esc_html__('Used when the block does not set a page size.', 'jot-library') . '</p>';
	}

	public static function delete_field(): void {
		$checked = self::get()['delete_on_uninstall'];
		echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION) . '[delete_on_uninstall]" value="1" ' . checked($checked, true, false) . '> ';
		echo esc_html__('Permanently delete all Jot Library entries, topics, and settings when the plugin is uninstalled.', 'jot-library') . '</label>';
		echo '<p class="description">' . esc_html__('Leave this off to keep the library. Deactivation never deletes data.', 'jot-library') . '</p>';
	}
}
