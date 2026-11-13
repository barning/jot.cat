<?php

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

wp_clear_scheduled_hook('jot_library_enrich_imported_entries');

$settings = (array) get_option('jot_library_settings', array());
if (empty($settings['delete_on_uninstall'])) {
	return;
}

$posts = get_posts(
	array(
		'post_type' => 'jot_entry',
		'post_status' => array('publish', 'draft', 'private', 'pending', 'future', 'trash'),
		'posts_per_page' => -1,
		'fields' => 'ids',
		'no_found_rows' => true,
	)
);
foreach ($posts as $post_id) {
	wp_delete_post((int) $post_id, true);
}

if (! taxonomy_exists('jot_topic')) {
	register_taxonomy('jot_topic', 'jot_entry');
}
$terms = get_terms(array('taxonomy' => 'jot_topic', 'hide_empty' => false, 'fields' => 'ids'));
if (is_array($terms)) {
	foreach ($terms as $term_id) {
		wp_delete_term((int) $term_id, 'jot_topic');
	}
}

delete_option('jot_library_settings');
delete_option('jot_library_entry_creation_lock');
delete_option('jot_library_metadata_enrichment_queue');
delete_option('jot_library_metadata_enrichment_queue_lock');

foreach (array('administrator', 'editor') as $role_name) {
	$role = get_role($role_name);
	if (null === $role) {
		continue;
	}
	foreach (array(
		'edit_jot_entry', 'read_jot_entry', 'delete_jot_entry', 'edit_jot_entries',
		'edit_others_jot_entries', 'publish_jot_entries', 'read_private_jot_entries',
		'delete_jot_entries', 'delete_private_jot_entries', 'delete_published_jot_entries',
		'delete_others_jot_entries', 'edit_private_jot_entries', 'edit_published_jot_entries',
		'create_jot_entries',
	) as $capability) {
		$role->remove_cap($capability);
	}
}
