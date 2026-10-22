<?php

declare(strict_types=1);

namespace JotLibrary;

final class Plugin {
	public static function boot(): void {
		add_action('init', array(Post_Type::class, 'register'), 5);
		add_action('init', array(Redirects::class, 'register'), 6);
		add_action('init', array(Library_Block::class, 'register'), 10);
		add_filter('query_vars', array(Redirects::class, 'query_vars'));
		add_action('template_redirect', array(Redirects::class, 'maybe_redirect'));
		add_filter('the_content', array(Library_Block::class, 'append_entry_details'));
		add_action('wp_enqueue_scripts', array(self::class, 'enqueue_entry_style'));

		if (is_admin()) {
			add_action('admin_menu', array(Admin::class, 'menus'));
			add_action('admin_init', array(Settings::class, 'register'));
			add_action('admin_enqueue_scripts', array(Admin::class, 'enqueue_assets'));
			add_action('load-post-new.php', array(Admin::class, 'redirect_new_entry'));
			add_action('admin_post_jot_library_save_url', array(Admin::class, 'handle_save'));
			add_action('add_meta_boxes_' . Post_Type::TYPE, array(Admin::class, 'meta_boxes'));
			add_action('save_post_' . Post_Type::TYPE, array(Admin::class, 'save_meta'));
			add_filter('manage_' . Post_Type::TYPE . '_posts_columns', array(Admin::class, 'columns'));
			add_action('manage_' . Post_Type::TYPE . '_posts_custom_column', array(Admin::class, 'column'), 10, 2);
		}

		if (defined('WP_CLI') && WP_CLI) {
			\WP_CLI::add_command('jot-library import', Import_Command::class);
		}
	}

	public static function activate(): void {
		Post_Type::register();
		Redirects::register();
		self::grant_capabilities();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	public static function enqueue_entry_style(): void {
		if (is_singular(Post_Type::TYPE)) {
			wp_enqueue_style('jot-library-entry', JOT_LIBRARY_URL . 'blocks/library/style.css', array(), JOT_LIBRARY_VERSION);
		}
	}

	private static function grant_capabilities(): void {
		foreach (array('administrator', 'editor') as $role_name) {
			$role = get_role($role_name);
			if (null === $role) {
				continue;
			}
			foreach (Post_Type::capabilities() as $capability) {
				$role->add_cap($capability);
			}
		}
	}
}
