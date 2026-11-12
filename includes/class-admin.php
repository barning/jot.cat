<?php

declare(strict_types=1);

namespace JotLibrary;

use Throwable;

final class Admin {
	private static bool $saving_meta = false;

	public static function menus(): void {
		$parent = 'edit.php?post_type=' . Post_Type::TYPE;
		remove_submenu_page($parent, 'post-new.php?post_type=' . Post_Type::TYPE);
		add_submenu_page(
			$parent,
			__('Add library entry', 'jot-library'),
			__('Add library entry', 'jot-library'),
			'edit_jot_entries',
			'jot-library-save',
			array(self::class, 'save_page')
		);
		add_submenu_page(
			$parent,
			__('Import and export Jot Library entries', 'jot-library'),
			__('Import / Export', 'jot-library'),
			'manage_options',
			'jot-library-transfer',
			array(self::class, 'transfer_page')
		);
		add_submenu_page(
			'edit.php?post_type=' . Post_Type::TYPE,
			__('Jot Library settings', 'jot-library'),
			__('Settings', 'jot-library'),
			'manage_options',
			'jot-library-settings',
			array(self::class, 'settings_page')
		);
	}

	public static function enqueue_assets(string $hook): void {
		$screen = get_current_screen();
		if (null === $screen || (Post_Type::TYPE !== $screen->post_type && ! str_contains($hook, 'jot-library'))) {
			return;
		}
		wp_enqueue_style('jot-library-admin', JOT_LIBRARY_URL . 'assets/admin.css', array(), JOT_LIBRARY_VERSION);
	}

	public static function redirect_new_entry(): void {
		$post_type = isset($_GET['post_type']) ? sanitize_key(wp_unslash((string) $_GET['post_type'])) : 'post';
		if (Post_Type::TYPE !== $post_type || ! current_user_can('edit_jot_entries')) {
			return;
		}
		wp_safe_redirect(admin_url('edit.php?post_type=' . Post_Type::TYPE . '&page=jot-library-save'));
		exit;
	}

	public static function save_page(): void {
		if (! current_user_can('edit_jot_entries')) {
			wp_die(esc_html__('You are not allowed to save library entries.', 'jot-library'));
		}

		$prefill = isset($_GET['url']) ? esc_url_raw(wp_unslash((string) $_GET['url'])) : '';
		$saved_id = isset($_GET['saved']) ? absint($_GET['saved']) : 0;
		$created = isset($_GET['created']) && '1' === $_GET['created'];
		$suggested = isset($_GET['suggested']) ? absint($_GET['suggested']) : 0;
		$error_key = isset($_GET['jot_error']) ? sanitize_key(wp_unslash((string) $_GET['jot_error'])) : '';
		$error = '';
		$attempt = array();
		if ('' !== $error_key) {
			$stored_error = get_transient('jot_library_error_' . get_current_user_id() . '_' . $error_key);
			if (is_array($stored_error)) {
				$error = (string) ($stored_error['message'] ?? '');
				$attempt = (array) ($stored_error['input'] ?? array());
				$prefill = (string) ($attempt['url'] ?? $prefill);
			}
			delete_transient('jot_library_error_' . get_current_user_id() . '_' . $error_key);
		}

		echo '<div class="wrap jot-library-admin">';
		echo '<h1>' . esc_html__('Add library entry', 'jot-library') . '</h1>';
		echo '<p class="jot-library-admin__intro">' . esc_html__('Add a public web address to your private curation queue. Publishing remains a separate choice.', 'jot-library') . '</p>';

		if ($saved_id > 0 && current_user_can('edit_post', $saved_id)) {
			$code = (string) get_post_meta($saved_id, Entry_Service::META_CODE, true);
			$message = $created ? __('Entry saved.', 'jot-library') : __('This URL was already in the library.', 'jot-library');
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . ' ';
			if ($suggested > 0) {
				echo esc_html(sprintf(_n('%d topic was suggested.', '%d topics were suggested.', $suggested, 'jot-library'), $suggested)) . ' ';
			}
			echo '<strong class="jot-library-admin__code">' . esc_html($code) . '</strong> · ';
			echo '<a href="' . esc_url(get_edit_post_link($saved_id)) . '">' . esc_html__('Edit entry', 'jot-library') . '</a></p></div>';
		}
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="jot-library-admin__form">';
		wp_nonce_field('jot_library_save_url', 'jot_library_nonce');
		echo '<input type="hidden" name="action" value="jot_library_save_url">';
		$url_error = '' !== $error ? '<span class="jot-library-admin__error" id="jot-url-error">' . esc_html($error) . '</span>' : '';
		$url_state = '' !== $error ? ' aria-invalid="true" aria-describedby="jot-url-error" autofocus' : '';
		self::field('jot_url', __('URL', 'jot-library'), '<input class="regular-text" id="jot_url" name="jot_url" type="url" required maxlength="4096" autocomplete="url" spellcheck="false" value="' . esc_attr($prefill) . '" placeholder="https://example.com/article"' . $url_state . '>' . $url_error, __('Use a public HTTP or HTTPS address.', 'jot-library'));
		self::field('jot_title', __('Title', 'jot-library'), '<input class="regular-text" id="jot_title" name="jot_title" type="text" maxlength="200" value="' . esc_attr((string) ($attempt['title'] ?? '')) . '">', __('Optional. The source domain is used when this is empty.', 'jot-library'));
		self::field('jot_description', __('Description', 'jot-library'), '<textarea class="large-text" rows="3" id="jot_description" name="jot_description" maxlength="500">' . esc_textarea((string) ($attempt['description'] ?? '')) . '</textarea>', __('A short public summary when the entry is published.', 'jot-library'));
		self::field('jot_tags', __('Topics', 'jot-library'), '<input class="regular-text" id="jot_tags" name="jot_tags" type="text" value="' . esc_attr((string) ($attempt['tags'] ?? '')) . '" placeholder="design, independent web">', __('Separate topics with commas.', 'jot-library'));
		self::field('jot_notes', __('Personal notes', 'jot-library'), '<textarea class="large-text" rows="5" id="jot_notes" name="jot_notes">' . esc_textarea((string) ($attempt['notes'] ?? '')) . '</textarea>', __('Notes remain nonpublic while the entry is a draft or private.', 'jot-library'));

		echo '<fieldset class="jot-library-admin__field"><legend>' . esc_html__('Visibility', 'jot-library') . '</legend>';
		foreach (array('draft' => __('Draft', 'jot-library'), 'private' => __('Private', 'jot-library'), 'publish' => __('Published', 'jot-library')) as $value => $label) {
			if ('publish' === $value && ! current_user_can('publish_jot_entries')) {
				continue;
			}
			echo '<label><input type="radio" name="jot_status" value="' . esc_attr($value) . '" ' . checked((string) ($attempt['status'] ?? 'draft'), $value, false) . '> ' . esc_html($label) . '</label> ';
		}
		echo '<p class="description">' . esc_html__('Only published entries appear in the library, REST responses, search, feeds, and short-link redirects.', 'jot-library') . '</p></fieldset>';
		echo '<p><label><input type="checkbox" name="jot_fetch_metadata" value="1"> ' . esc_html__('Fetch missing page details and suggest topics when Topics is empty', 'jot-library') . '</label></p>';
		echo '<p class="description">' . esc_html__('Topic suggestions come from the page’s keyword metadata and matches with topics already in this library. No AI or external suggestion service is used.', 'jot-library') . '</p>';
		submit_button(__('Add library entry', 'jot-library'));
		echo '</form>';

		$bookmarklet = "javascript:location.href='" . esc_js(admin_url('edit.php?post_type=' . Post_Type::TYPE . '&page=jot-library-save&url=')) . "'+encodeURIComponent(location.href)";
		echo '<hr><h2>' . esc_html__('Save from your browser', 'jot-library') . '</h2>';
		echo '<p>' . esc_html__('Drag this link to your bookmarks bar. It opens this authenticated form with the current page URL filled in; saving still requires the form submission and nonce.', 'jot-library') . '</p>';
		echo '<p><a class="button" href="' . esc_attr($bookmarklet) . '">' . esc_html__('Jot this page', 'jot-library') . '</a></p>';
		echo '<h2>' . esc_html__('Save from iPhone or iPad', 'jot-library') . '</h2>';
		echo '<p>' . esc_html__('An iOS Shortcut can send the shared page to the authenticated Jot Library API. Create a dedicated WordPress Application Password in your profile, then send a JSON POST with a url field to:', 'jot-library') . '</p>';
		echo '<p><code>' . esc_html(rest_url('jot-library/v1/entries')) . '</code></p>';
		echo '<p>' . esc_html__('Shortcut entries are always saved as drafts. Missing page metadata and topic suggestions are added in the background by default.', 'jot-library') . '</p>';
		echo '</div>';
	}

	public static function transfer_page(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to import or export library entries.', 'jot-library'), '', array('response' => 403));
		}

		echo '<div class="wrap jot-library-admin jot-library-admin--transfer"><h1>' . esc_html__('Import and export', 'jot-library') . '</h1>';
		$result_key = isset($_GET['jot_result']) ? sanitize_key(wp_unslash((string) $_GET['jot_result'])) : '';
		if ('' !== $result_key) {
			$result = get_transient('jot_library_transfer_' . get_current_user_id() . '_' . $result_key);
			delete_transient('jot_library_transfer_' . get_current_user_id() . '_' . $result_key);
			if (is_array($result)) {
				$class = ! empty($result['error']) ? 'notice-error' : 'notice-success';
				echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p><strong>' . esc_html((string) ($result['summary'] ?? '')) . '</strong></p>';
				if (! empty($result['messages']) && is_array($result['messages'])) {
					echo '<ul class="jot-library-admin__messages">';
					foreach ($result['messages'] as $message) {
						echo '<li>' . esc_html((string) $message) . '</li>';
					}
					echo '</ul>';
				}
				echo '</div>';
			}
		}

		echo '<div class="jot-library-admin__transfer-grid"><section><h2>' . esc_html__('Import CSV', 'jot-library') . '</h2>';
		echo '<p>' . esc_html__('Check an exported Jot Library CSV before importing it. Existing entries are never overwritten.', 'jot-library') . '</p>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
		wp_nonce_field('jot_library_import_csv', 'jot_library_nonce');
		echo '<input type="hidden" name="action" value="jot_library_import_csv">';
		echo '<p><label for="jot_csv"><strong>' . esc_html__('CSV file', 'jot-library') . '</strong></label><br><input id="jot_csv" name="jot_csv" type="file" accept=".csv,text/csv" required></p>';
		echo '<p><label for="jot_import_visibility"><strong>' . esc_html__('Imported visibility', 'jot-library') . '</strong></label><br><select id="jot_import_visibility" name="jot_import_visibility"><option value="draft">' . esc_html__('Draft (recommended)', 'jot-library') . '</option><option value="private">' . esc_html__('Private', 'jot-library') . '</option><option value="preserve">' . esc_html__('Preserve CSV visibility', 'jot-library') . '</option></select></p>';
		echo '<p class="description">' . esc_html__('Only url is required. Leave code empty to generate one. Imported links are queued for safe background metadata fetching; explicit CSV values are preserved while missing titles, descriptions, and topic suggestions are added.', 'jot-library') . '</p>';
		echo '<p class="submit"><button class="button" type="submit" name="jot_import_mode" value="check">' . esc_html__('Check CSV', 'jot-library') . '</button> ';
		echo '<button class="button button-primary" type="submit" name="jot_import_mode" value="import">' . esc_html__('Import CSV', 'jot-library') . '</button></p>';
		echo '</form></section>';

		echo '<section><h2>' . esc_html__('Export CSV', 'jot-library') . '</h2><p>' . esc_html__('Download every published, draft, private, pending, and scheduled entry, including notes and topics.', 'jot-library') . '</p>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('jot_library_export_csv', 'jot_library_nonce');
		echo '<input type="hidden" name="action" value="jot_library_export_csv">';
		submit_button(__('Download CSV', 'jot-library'), 'secondary', 'submit', false);
		echo '</form></section></div></div>';
	}

	public static function handle_save(): void {
		if (! current_user_can('edit_jot_entries')) {
			wp_die(esc_html__('You are not allowed to save library entries.', 'jot-library'), '', array('response' => 403));
		}
		check_admin_referer('jot_library_save_url', 'jot_library_nonce');

		$url = isset($_POST['jot_url']) ? (string) wp_unslash($_POST['jot_url']) : '';
		$title = isset($_POST['jot_title']) ? sanitize_text_field(wp_unslash((string) $_POST['jot_title'])) : '';
		$description = isset($_POST['jot_description']) ? sanitize_textarea_field(wp_unslash((string) $_POST['jot_description'])) : '';
		$notes = isset($_POST['jot_notes']) ? wp_kses_post(wp_unslash((string) $_POST['jot_notes'])) : '';
		$tags = isset($_POST['jot_tags']) ? array_values(array_filter(array_map('trim', explode(',', sanitize_text_field(wp_unslash((string) $_POST['jot_tags'])))))) : array();
		$suggested = 0;
		if (! empty($_POST['jot_fetch_metadata']) && ('' === $title || '' === $description || array() === $tags)) {
			$metadata = (new Metadata_Fetcher())->fetch($url);
			$title = '' !== $title ? $title : $metadata['title'];
			$description = '' !== $description ? $description : $metadata['description'];
			if (array() === $tags) {
				$tags = (new Topic_Suggester())->suggest($url, $title, $description, $notes, $metadata['topics']);
				$suggested = count($tags);
			}
		}

		$status = isset($_POST['jot_status']) ? sanitize_key(wp_unslash((string) $_POST['jot_status'])) : 'draft';
		if ('publish' === $status && ! current_user_can('publish_jot_entries')) {
			$status = 'draft';
		}
		$result = (new Entry_Service())->create_or_find(
			array(
				'url' => $url,
				'title' => $title,
				'description' => $description,
				'notes' => $notes,
				'status' => $status,
				'tags' => $tags,
			)
		);

		$target = admin_url('edit.php?post_type=' . Post_Type::TYPE . '&page=jot-library-save');
		if (is_wp_error($result)) {
			$key = wp_generate_password(12, false, false);
			set_transient(
				'jot_library_error_' . get_current_user_id() . '_' . $key,
				array(
					'message' => $result->get_error_message(),
					'input' => array(
						'url' => $url,
						'title' => $title,
						'description' => $description,
						'tags' => implode(', ', $tags),
						'notes' => $notes,
						'status' => $status,
					),
				),
				60
			);
			wp_safe_redirect(add_query_arg(array('jot_error' => $key), $target));
			exit;
		}

		wp_safe_redirect(add_query_arg(array('saved' => $result['id'], 'created' => $result['created'] ? '1' : '0', 'suggested' => $result['created'] ? $suggested : 0), $target));
		exit;
	}

	public static function handle_import_csv(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to import library entries.', 'jot-library'), '', array('response' => 403));
		}
		check_admin_referer('jot_library_import_csv', 'jot_library_nonce');
		$redirect = admin_url('edit.php?post_type=' . Post_Type::TYPE . '&page=jot-library-transfer');
		$result = array('error' => true, 'summary' => __('The CSV could not be imported.', 'jot-library'), 'messages' => array());

		try {
			$file = $_FILES['jot_csv'] ?? null;
			if (! is_array($file) || UPLOAD_ERR_OK !== (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)) {
				throw new \RuntimeException(__('Choose a CSV file to upload.', 'jot-library'));
			}
			if ((int) ($file['size'] ?? 0) > 5 * MB_IN_BYTES || 'csv' !== strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION))) {
				throw new \RuntimeException(__('Use a .csv file no larger than 5 MiB.', 'jot-library'));
			}
			$mode = isset($_POST['jot_import_mode']) ? sanitize_key(wp_unslash((string) $_POST['jot_import_mode'])) : 'check';
			$dry_run = 'import' !== $mode;
			$visibility = isset($_POST['jot_import_visibility']) ? sanitize_key(wp_unslash((string) $_POST['jot_import_visibility'])) : 'draft';
			$report = (new Csv_Transfer())->import_file((string) $file['tmp_name'], $dry_run, $visibility);
			$result = array(
				'error' => $report['conflicts'] > 0 || $report['invalid'] > 0,
				'summary' => sprintf(
					$dry_run ? __('Check complete — ready: %1$d; existing: %2$d; conflicts: %3$d; invalid: %4$d.', 'jot-library') : __('Import complete — created: %1$d; existing: %2$d; conflicts: %3$d; invalid: %4$d.', 'jot-library'),
					$report['created'],
					$report['skipped'],
					$report['conflicts'],
					$report['invalid']
				),
				'messages' => $report['messages'],
			);
		} catch (Throwable $error) {
			$result['messages'][] = $error->getMessage();
		}

		$key = wp_generate_password(12, false, false);
		set_transient('jot_library_transfer_' . get_current_user_id() . '_' . $key, $result, 5 * MINUTE_IN_SECONDS);
		wp_safe_redirect(add_query_arg('jot_result', $key, $redirect));
		exit;
	}

	public static function handle_export_csv(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to export library entries.', 'jot-library'), '', array('response' => 403));
		}
		check_admin_referer('jot_library_export_csv', 'jot_library_nonce');
		nocache_headers();
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="jot-library-' . gmdate('Y-m-d') . '.csv"');
		$stream = fopen('php://output', 'wb');
		if (false === $stream) {
			wp_die(esc_html__('The CSV export could not be opened.', 'jot-library'));
		}
		fwrite($stream, "\xEF\xBB\xBF");
		(new Csv_Transfer())->export_to_stream($stream);
		fclose($stream);
		exit;
	}

	public static function meta_boxes(): void {
		add_meta_box('jot-library-details', __('Jot details', 'jot-library'), array(self::class, 'details_box'), Post_Type::TYPE, 'normal', 'high');
	}

	public static function details_box(\WP_Post $post): void {
		wp_nonce_field('jot_library_entry_meta', 'jot_library_entry_nonce');
		$url = (string) get_post_meta($post->ID, Entry_Service::META_URL, true);
		$code = (string) get_post_meta($post->ID, Entry_Service::META_CODE, true);
		$status = (string) get_post_meta($post->ID, Entry_Service::META_READING_STATUS, true);
		echo '<div class="jot-library-admin__identity">' . Glyph::svg($code) . '<div><p class="jot-library-admin__code">' . esc_html($code) . '</p>';
		echo '<p><a href="' . esc_url(home_url('/j/' . rawurlencode($code))) . '">' . esc_html(home_url('/j/' . $code)) . '</a></p></div></div>';
		echo '<p><strong>' . esc_html__('Original URL', 'jot-library') . '</strong><br><a href="' . esc_url($url) . '" rel="noopener noreferrer">' . esc_html($url) . '</a></p>';
		echo '<p><label for="jot_description"><strong>' . esc_html__('Description', 'jot-library') . '</strong></label><br>';
		echo '<textarea class="large-text" rows="3" id="jot_description" name="jot_description" maxlength="500">' . esc_textarea($post->post_excerpt) . '</textarea></p>';
		echo '<p><label for="jot_reading_status"><strong>' . esc_html__('Reading status', 'jot-library') . '</strong></label><br>';
		echo '<select id="jot_reading_status" name="jot_reading_status"><option value="unread" ' . selected($status, 'unread', false) . '>' . esc_html__('Unread', 'jot-library') . '</option><option value="read" ' . selected($status, 'read', false) . '>' . esc_html__('Read', 'jot-library') . '</option></select></p>';
		echo '<p class="description">' . esc_html__('The code and original URL stay fixed so written references remain stable.', 'jot-library') . '</p>';
	}

	public static function save_meta(int $post_id): void {
		if (self::$saving_meta || ! isset($_POST['jot_library_entry_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['jot_library_entry_nonce'])), 'jot_library_entry_meta')) {
			return;
		}
		if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || ! current_user_can('edit_post', $post_id)) {
			return;
		}

		$reading = isset($_POST['jot_reading_status']) && 'read' === $_POST['jot_reading_status'] ? 'read' : 'unread';
		update_post_meta($post_id, Entry_Service::META_READING_STATUS, $reading);
		$excerpt = isset($_POST['jot_description']) ? sanitize_textarea_field(wp_unslash((string) $_POST['jot_description'])) : '';
		self::$saving_meta = true;
		wp_update_post(array('ID' => $post_id, 'post_excerpt' => $excerpt));
		self::$saving_meta = false;
	}

	/** @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public static function columns(array $columns): array {
		return array(
			'cb' => $columns['cb'],
			'title' => $columns['title'],
			'jot_code' => __('Code', 'jot-library'),
			'jot_source' => __('Source', 'jot-library'),
			'taxonomy-' . Post_Type::TAXONOMY => __('Topics', 'jot-library'),
			'date' => $columns['date'],
		);
	}

	public static function column(string $column, int $post_id): void {
		if ('jot_code' === $column) {
			echo '<code>' . esc_html((string) get_post_meta($post_id, Entry_Service::META_CODE, true)) . '</code>';
		} elseif ('jot_source' === $column) {
			echo esc_html((string) get_post_meta($post_id, Entry_Service::META_SOURCE, true));
		}
	}

	public static function settings_page(): void {
		if (! current_user_can('manage_options')) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__('Jot Library settings', 'jot-library') . '</h1><form action="options.php" method="post">';
		settings_fields('jot_library');
		do_settings_sections('jot-library');
		submit_button();
		echo '</form></div>';
	}

	private static function field(string $id, string $label, string $control, string $description): void {
		echo '<p class="jot-library-admin__field"><label for="' . esc_attr($id) . '"><strong>' . esc_html($label) . '</strong></label><br>' . $control . '<br><span class="description">' . esc_html($description) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Control markup is assembled from fixed local strings with escaped values.
	}
}
