<?php

use JotLibrary\Code;
use JotLibrary\Csv_Transfer;
use JotLibrary\Entry_Service;
use JotLibrary\Glyph;
use JotLibrary\Metadata_Fetcher;
use JotLibrary\Metadata_Enricher;
use JotLibrary\Post_Type;
use JotLibrary\Topic_Suggester;

if (! defined('ABSPATH')) {
	fwrite(STDERR, "Run this file through WP-CLI: wp eval-file tests/integration.php\n");
	exit(2);
}

final class Jot_Integration_Failure extends RuntimeException {}

function jot_check(bool $condition, string $message): void {
	if (! $condition) {
		throw new Jot_Integration_Failure($message);
	}
}

function jot_same(mixed $expected, mixed $actual, string $message): void {
	jot_check($expected === $actual, $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

$existing = get_posts(array('post_type' => Post_Type::TYPE, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids'));
foreach ($existing as $post_id) {
	wp_delete_post((int) $post_id, true);
}

$tests = array();
$service = new Entry_Service();
$published_id = 0;
$draft_id = 0;
$code = '';

$tests['plugin registers the post type, taxonomy, blocks, REST route, and rewrite'] = static function (): void {
	global $wp_rewrite;
	jot_check(post_type_exists(Post_Type::TYPE), 'post type registered');
	jot_check(taxonomy_exists(Post_Type::TAXONOMY), 'taxonomy registered');
	jot_check(WP_Block_Type_Registry::get_instance()->is_registered('jot/library'), 'library block registered');
	jot_check(WP_Block_Type_Registry::get_instance()->is_registered('jot/saved-links'), 'saved-links block registered');
	jot_check(isset(rest_get_server()->get_routes()['/jot-library/v1/entries']), 'Shortcut REST route registered');
	$options = rest_do_request(new WP_REST_Request('OPTIONS', '/jot-library/v1/entries'));
	jot_same(200, $options->get_status(), 'Shortcut REST route exposes discovery schema');
	jot_check(isset($options->get_data()['schema']['properties']['short_url']), 'Shortcut response schema describes short URL');
	$rules = $wp_rewrite->wp_rewrite_rules();
	jot_check(isset($rules['^j/([^/]+)/?$']), 'short-link rewrite registered');
};

$tests['administrator and editor receive entry capabilities'] = static function (): void {
	foreach (array('administrator', 'editor') as $name) {
		$role = get_role($name);
		jot_check(null !== $role && $role->has_cap('edit_jot_entries'), "{$name} can edit entries");
		jot_check(null !== $role && $role->has_cap('publish_jot_entries'), "{$name} can publish entries");
	}
};

$tests['saving is stable, deduplicated, and code-immutable'] = static function () use ($service, &$published_id, &$code): void {
	$first = $service->create_or_find(array('url' => 'https://93.184.216.34/article?utm=kept#part', 'title' => 'A useful article', 'description' => 'A concise description.', 'status' => 'publish'));
	jot_check(! is_wp_error($first), 'first save succeeds');
	jot_check($first['created'], 'first save creates content');
	jot_check(Code::is_new($first['code']), 'new five-character code created');
	$second = $service->create_or_find(array('url' => 'HTTPS://93.184.216.34:443/article?utm=kept#part', 'title' => 'Ignored duplicate', 'status' => 'draft'));
	jot_check(! is_wp_error($second), 'duplicate check succeeds');
	jot_check(! $second['created'], 'normalized duplicate is reused');
	jot_same($first['id'], $second['id'], 'duplicate post ID');
	jot_same($first['code'], $second['code'], 'duplicate code');
	$published_id = $first['id'];
	$code = $first['code'];
	wp_update_post(array('ID' => $published_id, 'post_content' => 'Edited notes'));
	jot_same($code, get_post_meta($published_id, Entry_Service::META_CODE, true), 'code survives edits');
};

$tests['published lookup is case-insensitive and nonpublic entries stay unresolved'] = static function () use ($service, &$draft_id, &$code): void {
	$draft = $service->create_or_find(array('url' => 'https://93.184.216.36/draft', 'title' => 'Draft link', 'status' => 'draft'));
	jot_check(! is_wp_error($draft), 'draft save succeeds');
	$draft_id = $draft['id'];
	jot_check(null !== $service->find_published_by_code(strtolower($code)), 'published lowercase code resolves');
	jot_check(null === $service->find_published_by_code($draft['code']), 'draft code does not resolve');
	jot_check(null === $service->find_published_by_code('XXXXX'), 'unknown code does not resolve');
};

$tests['code collisions retry across publication statuses'] = static function () use (&$code): void {
	$generated = array($code, 'ZZZZZ');
	$collision_service = new Entry_Service(static function () use (&$generated): string {
		return (string) array_shift($generated);
	});
	$result = $collision_service->create_or_find(array('url' => 'https://93.184.216.37/collision', 'title' => 'Collision retry', 'status' => 'private'));
	jot_check(! is_wp_error($result), 'collision retry succeeds');
	jot_same('ZZZZZ', $result['code'], 'second collision-free code used');
	$exhausted = new Entry_Service(static fn (): string => $code);
	$failure = $exhausted->create_or_find(array('url' => 'https://93.184.216.39/exhausted', 'title' => 'Exhausted collision', 'status' => 'draft'));
	jot_check(is_wp_error($failure) && 'jot_code_exhausted' === $failure->get_error_code(), 'collision exhaustion fails without a duplicate');
};

$tests['public REST responses exclude draft content and anonymous writes fail'] = static function () use (&$published_id, &$draft_id): void {
	wp_set_current_user(0);
	$list = rest_do_request(new WP_REST_Request('GET', '/wp/v2/jot_entry'));
	jot_same(200, $list->get_status(), 'public list status');
	$ids = array_map(static fn (array $item): int => (int) $item['id'], (array) $list->get_data());
	jot_check(in_array($published_id, $ids, true), 'published entry listed');
	jot_check(! in_array($draft_id, $ids, true), 'draft entry hidden');
	$private_request = rest_do_request(new WP_REST_Request('GET', '/wp/v2/jot_entry/' . $draft_id));
	jot_check(in_array($private_request->get_status(), array(401, 403, 404), true), 'draft REST request denied');
	$write = new WP_REST_Request('POST', '/wp/v2/jot_entry');
	$write->set_param('title', 'Anonymous entry');
	$write_request = rest_do_request($write);
	jot_check(in_array($write_request->get_status(), array(401, 403), true), 'anonymous write denied');
};

$tests['Shortcut API authenticates, creates drafts, queues enrichment, and reuses duplicates'] = static function (): void {
	delete_option('jot_library_metadata_enrichment_queue');
	wp_clear_scheduled_hook(Metadata_Enricher::HOOK);
	wp_set_current_user(0);
	$anonymous = new WP_REST_Request('POST', '/jot-library/v1/entries');
	$anonymous->set_param('url', 'https://93.184.216.60/shortcut-anonymous');
	$anonymous_response = rest_do_request($anonymous);
	jot_same(401, $anonymous_response->get_status(), 'anonymous Shortcut request denied');

	$administrators = get_users(array('role' => 'administrator', 'number' => 1));
	jot_check(isset($administrators[0]), 'administrator available for authenticated REST request');
	wp_set_current_user((int) $administrators[0]->ID);
	$administrator_role = get_role('administrator');
	jot_check(null !== $administrator_role, 'administrator role available');
	$administrator_role->remove_cap('create_jot_entries');
	try {
		wp_set_current_user(0);
		wp_set_current_user((int) $administrators[0]->ID);
		$forbidden = new WP_REST_Request('POST', '/jot-library/v1/entries');
		$forbidden->set_param('url', 'https://93.184.216.60/shortcut-forbidden');
		$forbidden_response = rest_do_request($forbidden);
		jot_same(403, $forbidden_response->get_status(), 'authenticated user without create capability denied');
	} finally {
		$administrator_role->add_cap('create_jot_entries');
		wp_set_current_user(0);
		wp_set_current_user((int) $administrators[0]->ID);
	}
	$request = new WP_REST_Request('POST', '/jot-library/v1/entries');
	$request->set_param('url', 'https://93.184.216.60/shortcut-entry');
	$request->set_param('notes', '<p>Saved from the Share Sheet.</p><script>ignored()</script>');
	$request->set_param('topics', array('Independent web', 'iOS'));
	$request->set_param('reading_status', 'read');
	$response = rest_do_request($request);
	jot_same(201, $response->get_status(), 'authenticated Shortcut request creates an entry');
	$data = (array) $response->get_data();
	jot_check(true === $data['created'], 'Shortcut response reports creation');
	jot_check(Code::is_new((string) $data['code']), 'Shortcut response includes a new code');
	jot_same('draft', $data['status'], 'Shortcut entries are forced to draft');
	jot_same(home_url('/j/' . rawurlencode((string) $data['code'])), $data['short_url'], 'Shortcut response includes stable short URL');
	jot_check(str_contains((string) $data['edit_url'], 'post.php?post='), 'Shortcut response includes edit URL');
	jot_check(true === $data['enrichment_queued'], 'Shortcut response reports queued enrichment');
	jot_check(false !== wp_next_scheduled(Metadata_Enricher::HOOK), 'Shortcut metadata enrichment scheduled');

	$post = get_post((int) $data['id']);
	jot_check($post instanceof WP_Post, 'Shortcut entry stored');
	jot_same('draft', $post->post_status, 'stored Shortcut entry remains nonpublic');
	jot_same('<p>Saved from the Share Sheet.</p>ignored()', $post->post_content, 'Shortcut notes are sanitized');
	jot_same('read', get_post_meta($post->ID, Entry_Service::META_READING_STATUS, true), 'Shortcut reading status stored');
	$topics = wp_get_object_terms($post->ID, Post_Type::TAXONOMY, array('fields' => 'names'));
	sort($topics);
	jot_same(array('Independent web', 'iOS'), $topics, 'Shortcut topics stored');

	$duplicate = new WP_REST_Request('POST', '/jot-library/v1/entries');
	$duplicate->set_param('url', 'HTTPS://93.184.216.60:443/shortcut-entry');
	$duplicate_response = rest_do_request($duplicate);
	jot_same(200, $duplicate_response->get_status(), 'duplicate Shortcut request succeeds without another entry');
	$duplicate_data = (array) $duplicate_response->get_data();
	jot_check(false === $duplicate_data['created'], 'duplicate Shortcut response reports existing entry');
	jot_same($data['id'], $duplicate_data['id'], 'duplicate Shortcut response reuses post ID');
	jot_same($data['code'], $duplicate_data['code'], 'duplicate Shortcut response reuses code');
	jot_check(true === $duplicate_data['enrichment_queued'], 'duplicate Shortcut request reasserts enrichment idempotently');

	$without_metadata = new WP_REST_Request('POST', '/jot-library/v1/entries');
	$without_metadata->set_param('url', 'https://93.184.216.61/shortcut-no-metadata');
	$without_metadata->set_param('fetch_metadata', false);
	$without_metadata_response = rest_do_request($without_metadata);
	jot_same(201, $without_metadata_response->get_status(), 'Shortcut can skip enrichment');
	jot_check(false === $without_metadata_response->get_data()['enrichment_queued'], 'disabled enrichment remains unqueued');

	$invalid = new WP_REST_Request('POST', '/jot-library/v1/entries');
	$invalid->set_param('url', 'http://127.0.0.1/private');
	$invalid_response = rest_do_request($invalid);
	jot_same(400, $invalid_response->get_status(), 'unsafe Shortcut URL rejected');
	wp_set_current_user(0);
};

$tests['metadata queue preserves overlapping enqueues and retries duplicates'] = static function () use ($service): void {
	delete_option('jot_library_metadata_enrichment_queue');
	delete_option('jot_library_metadata_enrichment_queue_lock');
	wp_clear_scheduled_hook(Metadata_Enricher::HOOK);

	$queued_ids = array();
	for ($index = 0; $index < 7; $index++) {
		$entry = $service->create_or_find(
			array(
				'url' => 'https://93.184.216.' . (70 + $index) . '/queue-' . $index,
				'title' => 'Queue entry ' . $index,
				'status' => 'draft',
			)
		);
		jot_check(! is_wp_error($entry), 'queue fixture created');
		$queued_ids[] = (int) $entry['id'];
		jot_check(Metadata_Enricher::enqueue((int) $entry['id']), 'queue fixture enqueued');
	}

	jot_check(Metadata_Enricher::enqueue($queued_ids[0]), 'duplicate enqueue remains successful');
	jot_same($queued_ids, get_option('jot_library_metadata_enrichment_queue'), 'duplicate enqueue does not duplicate or replace queued IDs');

	$late = $service->create_or_find(
		array(
			'url' => 'https://93.184.216.80/queue-late',
			'title' => 'Late queue entry',
			'status' => 'draft',
		)
	);
	jot_check(! is_wp_error($late), 'late queue fixture created');
	$late_id = (int) $late['id'];
	$late_enqueued = false;
	$mock = static function () use (&$late_enqueued, $late_id): array {
		if (! $late_enqueued) {
			$late_enqueued = Metadata_Enricher::enqueue($late_id);
		}
		return array(
			'headers' => array('content-type' => 'text/html; charset=UTF-8'),
			'body' => '<html><head><title>Queue metadata</title></head></html>',
			'response' => array('code' => 200, 'message' => 'OK'),
			'cookies' => array(),
			'filename' => null,
		);
	};
	add_filter('pre_http_request', $mock);
	Metadata_Enricher::run();
	remove_filter('pre_http_request', $mock);

	jot_check($late_enqueued, 'enqueue during worker processing succeeds');
	jot_same(array($queued_ids[5], $queued_ids[6], $late_id), get_option('jot_library_metadata_enrichment_queue'), 'worker preserves IDs added after its batch removal');

	delete_option('jot_library_metadata_enrichment_queue');
	wp_clear_scheduled_hook(Metadata_Enricher::HOOK);
};

$tests['dynamic block exposes published content with clear destinations'] = static function () use ($service, &$code): void {
	$html = do_blocks('<!-- wp:jot/library {"postsPerPage":12,"showSearch":true} /-->');
	$title_position = strpos($html, 'A useful article');
	$notes_position = strpos($html, 'Edited notes');
	$reference_position = strpos($html, 'jot-library__reference');
	jot_check(false !== $title_position, 'published title rendered');
	jot_check(false !== $notes_position, 'published notes rendered');
	jot_check(false !== $reference_position, 'reference footer rendered');
	jot_check($title_position < $notes_position && $notes_position < $reference_position, 'entry uses title-first reading order');
	jot_check(str_contains($html, 'Short link'), 'short-link purpose labeled');
	jot_check(str_contains($html, 'class="jot-library__code" type="button"'), 'visible code renders as a button');
	jot_check(str_contains($html, 'data-jot-copy-url="' . esc_url(home_url('/j/' . rawurlencode($code))) . '"'), 'copy button carries the complete short URL');
	jot_check(str_contains($html, 'role="status" aria-live="polite"'), 'copy feedback uses a polite live region');
	jot_check(! str_contains($html, 'class="jot-library__code" href='), 'visible code is not a link');
	jot_check(str_contains($html, 'href="https://93.184.216.34/article?utm=kept#part"'), 'title links to the original resource');
	jot_check(! str_contains($html, 'Draft link'), 'draft omitted');

	$second = $service->create_or_find(array('url' => 'https://93.184.216.38/second', 'title' => 'Second published entry', 'status' => 'publish'));
	jot_check(! is_wp_error($second), 'second published entry created');
	$paged_html = do_blocks('<!-- wp:jot/library {"postsPerPage":1,"showSearch":true} /-->');
	$pagination_match = array();
	jot_check(1 === preg_match('/href="([^"]*jot_page=2[^"]*)"/', $paged_html, $pagination_match), 'pagination includes a second library page');
	jot_check(! str_contains(html_entity_decode($pagination_match[1]), '93.184.216.'), 'pagination does not inherit an entry destination');
};

$tests['saved-links block renders an inclusive published date range newest first'] = static function () use ($service): void {
	$older = $service->create_or_find(array(
		'url' => 'https://93.184.216.50/date-range-older',
		'title' => 'First boundary link',
		'description' => 'Older description.',
		'notes' => 'Older notes.',
		'status' => 'publish',
		'saved_at' => '2025-02-01 00:00:00',
	));
	$newer = $service->create_or_find(array(
		'url' => 'https://93.184.216.51/date-range-newer',
		'title' => 'Last boundary link',
		'description' => 'Newer description.',
		'status' => 'publish',
		'saved_at' => '2025-03-31 23:59:59',
	));
	$outside = $service->create_or_find(array(
		'url' => 'https://93.184.216.52/date-range-outside',
		'title' => 'Outside range link',
		'status' => 'publish',
		'saved_at' => '2025-04-01 00:00:00',
	));
	$private = $service->create_or_find(array(
		'url' => 'https://93.184.216.53/date-range-private',
		'title' => 'Private range link',
		'status' => 'draft',
		'saved_at' => '2025-03-01 12:00:00',
	));
	foreach (array($older, $newer, $outside, $private) as $entry) {
		jot_check(! is_wp_error($entry), 'date-range fixture created');
	}

	$html = do_blocks('<!-- wp:jot/saved-links {"fromDate":"2025-02-01","toDate":"2025-03-31"} /-->');
	$older_position = strpos($html, 'First boundary link');
	$newer_position = strpos($html, 'Last boundary link');
	jot_check(false !== $older_position && false !== $newer_position, 'both inclusive boundary entries rendered');
	jot_check($newer_position < $older_position, 'entries render newest first');
	jot_check(! str_contains($html, 'Outside range link'), 'entry after the range omitted');
	jot_check(! str_contains($html, 'Private range link'), 'draft in the range omitted');
	jot_check(str_contains($html, 'href="https://93.184.216.51/date-range-newer"'), 'title keeps the original destination');
	jot_check(str_contains($html, '<time datetime="2025-03-31">'), 'saved date uses semantic time markup');
	jot_check(str_contains($html, 'Older notes.'), 'notes render when present');
	jot_check(str_contains($html, 'Newer description.'), 'description is the fallback when notes are absent');

	$missing = do_blocks('<!-- wp:jot/saved-links /-->');
	jot_check(str_contains($missing, 'Choose a From and To date'), 'unset range gives editing guidance');
	$reversed = do_blocks('<!-- wp:jot/saved-links {"fromDate":"2025-04-01","toDate":"2025-03-01"} /-->');
	jot_check(str_contains($reversed, 'must be earlier than or equal'), 'reversed range gives clear guidance');
};

$tests['topic suggestions combine page metadata with the existing library vocabulary'] = static function (): void {
	wp_insert_term('Typography', Post_Type::TAXONOMY);
	wp_insert_term('Independent web', Post_Type::TAXONOMY);
	$suggestions = (new Topic_Suggester())->suggest(
		'https://example.com/typography-notes',
		'Typography for the independent web',
		'A practical design reference.',
		'',
		array('Design systems', 'News', 'Design systems')
	);
	jot_same(array('Design systems', 'Independent web', 'Typography'), $suggestions, 'suggestions are limited, deduplicated, and useful');
};

$tests['metadata fetching extracts page-provided topic hints without rendering remote HTML'] = static function (): void {
	$mock = static function (): array {
		return array(
			'headers' => array('content-type' => 'text/html; charset=UTF-8'),
			'body' => '<html><head><title>Metadata title</title><meta name="description" content="Metadata description"><meta name="keywords" content="Typography, Design"><meta property="article:tag" content="Independent web"></head><body><script>ignored()</script></body></html>',
			'response' => array('code' => 200, 'message' => 'OK'),
			'cookies' => array(),
			'filename' => null,
		);
	};
	add_filter('pre_http_request', $mock);
	$metadata = (new Metadata_Fetcher())->fetch('https://93.184.216.42/metadata');
	remove_filter('pre_http_request', $mock);
	jot_same('Metadata title', $metadata['title'], 'metadata title extracted');
	jot_same('Metadata description', $metadata['description'], 'metadata description extracted');
	jot_same(array('Typography', 'Design', 'Independent web'), $metadata['topics'], 'metadata topic hints extracted');
};

$tests['CSV transfer checks, imports, repeats safely, and exports complete rows'] = static function (): void {
	$path = tempnam(sys_get_temp_dir(), 'jot-library-csv-');
	jot_check(is_string($path) && '' !== $path, 'temporary CSV path created');
	$stream = fopen($path, 'wb');
	jot_check(false !== $stream, 'temporary CSV opened');
	fputcsv($stream, array('code', 'url', 'title', 'description', 'notes', 'topics', 'status', 'reading_status', 'saved_at'));
	fputcsv($stream, array('CSV01', 'https://93.184.216.40/csv-entry', '=2+2', 'Imported description', 'Imported notes', 'Research|CSV', 'publish', 'read', '2025-01-02 03:04:05'));
	fputcsv($stream, array('', 'https://93.184.216.41/generated-code', 'Generated code entry', '', '', 'Import', 'draft', 'unread', ''));
	fclose($stream);

	$transfer = new Csv_Transfer();
	$dry = $transfer->import_file($path, true, 'draft');
	jot_same(2, $dry['created'], 'CSV dry run reports both ready entries');
	jot_check(0 === count(get_posts(array('post_type' => Post_Type::TYPE, 'post_status' => 'any', 'meta_key' => Entry_Service::META_CODE, 'meta_value' => 'CSV01'))), 'CSV dry run writes nothing');
	$applied = $transfer->import_file($path, false, 'draft');
	jot_same(2, $applied['created'], 'CSV import creates both entries');
	$posts = get_posts(array('post_type' => Post_Type::TYPE, 'post_status' => 'any', 'meta_key' => Entry_Service::META_CODE, 'meta_value' => 'CSV01'));
	jot_same(1, count($posts), 'CSV entry exists once');
	jot_same('draft', $posts[0]->post_status, 'selected import visibility overrides CSV publication');
	jot_same('=2+2', $posts[0]->post_title, 'CSV title preserved during import');
	jot_same('Imported notes', $posts[0]->post_content, 'CSV notes preserved');
	jot_same('read', get_post_meta($posts[0]->ID, Entry_Service::META_READING_STATUS, true), 'CSV reading status preserved');
	$topics = wp_get_object_terms($posts[0]->ID, Post_Type::TAXONOMY, array('fields' => 'names'));
	sort($topics);
	jot_same(array('CSV', 'Research'), $topics, 'CSV topics preserved');
	$generated = get_posts(array('post_type' => Post_Type::TYPE, 'post_status' => 'any', 'meta_key' => Entry_Service::META_URL, 'meta_value' => 'https://93.184.216.41/generated-code'));
	jot_same(1, count($generated), 'URL-only CSV row creates an entry');
	jot_check(Code::is_new((string) get_post_meta($generated[0]->ID, Entry_Service::META_CODE, true)), 'URL-only CSV row receives a new code');
	$again = $transfer->import_file($path, false, 'draft');
	jot_same(2, $again['skipped'], 'repeat CSV import skips both existing entries');

	$export = fopen('php://temp', 'w+b');
	jot_check(false !== $export, 'CSV export stream opened');
	$count = $transfer->export_to_stream($export);
	rewind($export);
	$csv = stream_get_contents($export);
	fclose($export);
	unlink($path);
	jot_check($count >= 1, 'CSV export includes entries');
	jot_check(str_contains((string) $csv, 'code,url,title,description,notes,topics,status,reading_status,saved_at'), 'CSV export includes the documented header');
	jot_check(str_contains((string) $csv, 'https://93.184.216.40/csv-entry'), 'CSV export includes the imported URL');
	jot_check(str_contains((string) $csv, "'=2+2"), 'CSV export neutralizes spreadsheet formulas');
};

$tests['CSV imports enrich missing metadata and topics without overwriting curated fields'] = static function (): void {
	delete_option('jot_library_metadata_enrichment_queue');
	$path = tempnam(sys_get_temp_dir(), 'jot-library-enrichment-');
	jot_check(is_string($path) && '' !== $path, 'temporary enrichment CSV path created');
	$stream = fopen($path, 'wb');
	jot_check(false !== $stream, 'temporary enrichment CSV opened');
	fputcsv($stream, array('url', 'title', 'description', 'topics', 'status'));
	fputcsv($stream, array('https://93.184.216.54/enrich-empty', '', '', '', 'draft'));
	fputcsv($stream, array('https://93.184.216.55/enrich-curated', 'Curated title', 'Curated description', 'Explicit', 'draft'));
	fclose($stream);

	$report = (new Csv_Transfer())->import_file($path, false, 'draft');
	unlink($path);
	jot_same(2, $report['created'], 'both enrichment fixtures imported');
	jot_check(false !== wp_next_scheduled(Metadata_Enricher::HOOK), 'background enrichment scheduled');

	$mock = static function ($preempt, array $args, string $url): array {
		$title = str_contains($url, 'enrich-empty') ? 'Fetched page title' : 'Remote replacement title';
		return array(
			'headers' => array('content-type' => 'text/html; charset=UTF-8'),
			'body' => '<html><head><title>Fallback title</title><meta property="og:title" content="' . esc_attr($title) . '"><meta property="og:description" content="Fetched description"><meta name="keywords" content="Design, WordPress"></head></html>',
			'response' => array('code' => 200, 'message' => 'OK'),
			'cookies' => array(),
			'filename' => null,
		);
	};
	add_filter('pre_http_request', $mock, 10, 3);
	Metadata_Enricher::run();
	remove_filter('pre_http_request', $mock, 10);

	$empty = get_posts(array('post_type' => Post_Type::TYPE, 'post_status' => 'any', 'meta_key' => Entry_Service::META_URL, 'meta_value' => 'https://93.184.216.54/enrich-empty'));
	jot_same('Fetched page title', $empty[0]->post_title, 'missing title filled from Open Graph metadata');
	jot_same('Fetched description', $empty[0]->post_excerpt, 'missing description filled from Open Graph metadata');
	$empty_topics = wp_get_object_terms($empty[0]->ID, Post_Type::TAXONOMY, array('fields' => 'names'));
	sort($empty_topics);
	jot_same(array('Design', 'WordPress'), $empty_topics, 'metadata keywords become topics');

	$curated = get_posts(array('post_type' => Post_Type::TYPE, 'post_status' => 'any', 'meta_key' => Entry_Service::META_URL, 'meta_value' => 'https://93.184.216.55/enrich-curated'));
	jot_same('Curated title', $curated[0]->post_title, 'curated title preserved');
	jot_same('Curated description', $curated[0]->post_excerpt, 'curated description preserved');
	$curated_topics = wp_get_object_terms($curated[0]->ID, Post_Type::TAXONOMY, array('fields' => 'names'));
	sort($curated_topics);
	jot_same(array('Design', 'Explicit', 'WordPress'), $curated_topics, 'curated and suggested topics combined');
};

$tests['glyph output is deterministic and decorative'] = static function () use (&$code): void {
	jot_same(Glyph::svg($code), Glyph::svg(strtolower($code)), 'stable glyph');
	jot_check(str_contains(Glyph::svg($code), 'aria-hidden="true"'), 'decorative glyph');
};

$tests['deactivation preserves entries and reactivation succeeds'] = static function (): void {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$probe = wp_insert_post(array('post_type' => Post_Type::TYPE, 'post_status' => 'draft', 'post_title' => 'Persistence probe'), true);
	jot_check(! is_wp_error($probe), 'probe created');
	$plugin = plugin_basename(JOT_LIBRARY_FILE);
	deactivate_plugins($plugin);
	jot_check(null !== get_post((int) $probe), 'entry remains after deactivation');
	$activated = activate_plugin($plugin);
	jot_check(! is_wp_error($activated), 'plugin reactivates');
};

$tests['uninstall retains data by default and honors explicit deletion'] = static function (): void {
	$probe = wp_insert_post(array('post_type' => Post_Type::TYPE, 'post_status' => 'draft', 'post_title' => 'Uninstall probe'), true);
	jot_check(! is_wp_error($probe), 'uninstall probe created');
	update_option('jot_library_settings', array('delete_on_uninstall' => false));
	if (! defined('WP_UNINSTALL_PLUGIN')) {
		define('WP_UNINSTALL_PLUGIN', 'jot-library/jot-library.php');
	}
	require dirname(__DIR__) . '/uninstall.php';
	jot_check(null !== get_post((int) $probe), 'default uninstall keeps entries');

	update_option('jot_library_settings', array('delete_on_uninstall' => true));
	update_option('jot_library_metadata_enrichment_queue', array((int) $probe), false);
	update_option('jot_library_metadata_enrichment_queue_lock', 'expired-lock|1', false);
	require dirname(__DIR__) . '/uninstall.php';
	jot_check(null === get_post((int) $probe), 'opt-in uninstall deletes entries');
	jot_check(false === get_option('jot_library_settings'), 'opt-in uninstall deletes settings');
	jot_check(false === get_option('jot_library_metadata_enrichment_queue'), 'opt-in uninstall deletes enrichment queue');
	jot_check(false === get_option('jot_library_metadata_enrichment_queue_lock'), 'opt-in uninstall deletes enrichment queue lock');
	JotLibrary\Plugin::activate();
};

$failures = 0;
foreach ($tests as $name => $test) {
	try {
		$test();
		fwrite(STDOUT, "✓ {$name}\n");
	} catch (Throwable $error) {
		++$failures;
		fwrite(STDERR, "✗ {$name}: {$error->getMessage()}\n");
	}
}

foreach (get_posts(array('post_type' => Post_Type::TYPE, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids')) as $post_id) {
	wp_delete_post((int) $post_id, true);
}

fwrite(STDOUT, sprintf("\n%d integration tests, %d failures\n", count($tests), $failures));
if ($failures > 0) {
	exit(1);
}
