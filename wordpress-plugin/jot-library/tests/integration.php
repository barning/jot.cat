<?php

use JotLibrary\Code;
use JotLibrary\Entry_Service;
use JotLibrary\Glyph;
use JotLibrary\Importer;
use JotLibrary\Post_Type;

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

$tests['plugin registers the post type, taxonomy, dynamic block, and rewrite'] = static function (): void {
	global $wp_rewrite;
	jot_check(post_type_exists(Post_Type::TYPE), 'post type registered');
	jot_check(taxonomy_exists(Post_Type::TAXONOMY), 'taxonomy registered');
	jot_check(WP_Block_Type_Registry::get_instance()->is_registered('jot/library'), 'block registered');
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
	jot_check(str_contains($html, 'class="jot-library__code" href="' . esc_url(home_url('/j/' . rawurlencode($code))) . '"'), 'visible code links through the short URL');
	jot_check(str_contains($html, 'href="https://93.184.216.34/article?utm=kept#part"'), 'title links to the original resource');
	jot_check(! str_contains($html, 'Draft link'), 'draft omitted');

	$second = $service->create_or_find(array('url' => 'https://93.184.216.38/second', 'title' => 'Second published entry', 'status' => 'publish'));
	jot_check(! is_wp_error($second), 'second published entry created');
	$paged_html = do_blocks('<!-- wp:jot/library {"postsPerPage":1,"showSearch":true} /-->');
	$pagination_match = array();
	jot_check(1 === preg_match('/href="([^"]*jot_page=2[^"]*)"/', $paged_html, $pagination_match), 'pagination includes a second library page');
	jot_check(! str_contains(html_entity_decode($pagination_match[1]), '93.184.216.'), 'pagination does not inherit an entry destination');
};

$tests['migration dry-run is read-only and imports are idempotent and nonpublic'] = static function (): void {
	$fixture = dirname(__DIR__) . '/tests/fixtures/legacy-export.json';
	$importer = new Importer();
	$invalid_fixture = '/tmp/jot-library-invalid-timestamp.json';
	file_put_contents($invalid_fixture, wp_json_encode(array('version' => 1, 'entries' => array(array('code' => 'M8KD', 'url' => 'https://93.184.216.40/invalid-time', 'created_at' => 'last Tuesday')))));
	$invalid = $importer->import_file($invalid_fixture, true, 'draft');
	unlink($invalid_fixture);
	jot_same(1, $invalid['invalid'], 'invalid timestamp reported');
	jot_same(0, $invalid['created'], 'invalid timestamp not planned for import');
	$before = wp_count_posts(Post_Type::TYPE)->draft;
	$dry = $importer->import_file($fixture, true, 'draft');
	jot_same(2, $dry['created'], 'dry-run planned rows');
	jot_same($before, wp_count_posts(Post_Type::TYPE)->draft, 'dry-run wrote no posts');
	$applied = $importer->import_file($fixture, false, 'draft');
	jot_same(2, $applied['created'], 'legacy rows imported');
	$again = $importer->import_file($fixture, false, 'draft');
	jot_same(2, $again['skipped'], 'repeat import skipped rows');
	foreach (array('M7KD', 'S4F7') as $legacy) {
		$posts = get_posts(array('post_type' => Post_Type::TYPE, 'post_status' => 'any', 'meta_key' => Entry_Service::META_CODE, 'meta_value' => $legacy));
		jot_same(1, count($posts), "{$legacy} imported once");
		jot_same('draft', $posts[0]->post_status, "{$legacy} remains nonpublic");
		jot_check(null === (new Entry_Service())->find_published_by_code($legacy), "{$legacy} does not redirect before publication");
		if ('M7KD' === $legacy) {
			jot_same('2024-06-01 10:30:00', get_post_meta($posts[0]->ID, Entry_Service::META_SAVED_AT, true), 'saved timestamp preserved');
			wp_update_post(array('ID' => $posts[0]->ID, 'post_status' => 'publish'));
			jot_check(null !== (new Entry_Service())->find_published_by_code('m7kd'), 'published legacy code resolves case-insensitively');
		}
	}
};

$tests['glyph output is deterministic and decorative'] = static function () use (&$code): void {
	jot_same(Glyph::svg($code), Glyph::svg(strtolower($code)), 'stable glyph');
	jot_check(str_contains(Glyph::svg($code), 'aria-hidden="true"'), 'decorative glyph');
};

$tests['deactivation preserves entries and reactivation succeeds'] = static function (): void {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$probe = wp_insert_post(array('post_type' => Post_Type::TYPE, 'post_status' => 'draft', 'post_title' => 'Persistence probe'), true);
	jot_check(! is_wp_error($probe), 'probe created');
	deactivate_plugins('jot-library/jot-library.php');
	jot_check(null !== get_post((int) $probe), 'entry remains after deactivation');
	$activated = activate_plugin('jot-library/jot-library.php');
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
	require dirname(__DIR__) . '/uninstall.php';
	jot_check(null === get_post((int) $probe), 'opt-in uninstall deletes entries');
	jot_check(false === get_option('jot_library_settings'), 'opt-in uninstall deletes settings');
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
