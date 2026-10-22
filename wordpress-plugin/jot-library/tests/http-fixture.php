<?php

use JotLibrary\Entry_Service;

if (! defined('ABSPATH')) {
	exit(2);
}

$service = new Entry_Service();
$published = $service->create_or_find(
	array(
		'url' => 'https://93.184.216.34/http-target?kept=1#part',
		'title' => 'HTTP routing fixture',
		'description' => 'Visible in the test library.',
		'status' => 'publish',
	)
);
$draft = $service->create_or_find(
	array(
		'url' => 'https://93.184.216.36/http-draft',
		'title' => 'Hidden HTTP fixture',
		'status' => 'draft',
	)
);
if (is_wp_error($published) || is_wp_error($draft)) {
	fwrite(STDERR, "Could not create HTTP fixtures.\n");
	exit(1);
}

$page_id = wp_insert_post(
	array(
		'post_type' => 'page',
		'post_status' => 'publish',
		'post_title' => 'Library',
		'post_name' => 'library',
		'post_content' => '<!-- wp:jot/library {"postsPerPage":12,"showSearch":true} /-->',
	),
	true
);
if (is_wp_error($page_id)) {
	fwrite(STDERR, "Could not create library page.\n");
	exit(1);
}

flush_rewrite_rules();
echo wp_json_encode(
	array(
		'published_code' => $published['code'],
		'draft_code' => $draft['code'],
		'entry_path' => (string) wp_parse_url(get_permalink($published['id']), PHP_URL_PATH),
	)
);
