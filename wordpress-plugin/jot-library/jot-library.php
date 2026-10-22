<?php
/**
 * Plugin Name: Jot Library
 * Plugin URI:  https://github.com/barning/jot.cat
 * Description: A small, code-first library for curated links and handwritten notebook references.
 * Version:     0.1.0
 * Requires at least: 7.0
 * Requires PHP: 8.0
 * Author:      Niklas Barning
 * License:     GPL-2.0-or-later
 * Text Domain: jot-library
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

define('JOT_LIBRARY_VERSION', '0.1.0');
define('JOT_LIBRARY_FILE', __FILE__);
define('JOT_LIBRARY_DIR', plugin_dir_path(__FILE__));
define('JOT_LIBRARY_URL', plugin_dir_url(__FILE__));

require_once JOT_LIBRARY_DIR . 'includes/class-code.php';
require_once JOT_LIBRARY_DIR . 'includes/class-url.php';
require_once JOT_LIBRARY_DIR . 'includes/class-glyph.php';
require_once JOT_LIBRARY_DIR . 'includes/class-post-type.php';
require_once JOT_LIBRARY_DIR . 'includes/class-entry-service.php';
require_once JOT_LIBRARY_DIR . 'includes/class-metadata-fetcher.php';
require_once JOT_LIBRARY_DIR . 'includes/class-redirects.php';
require_once JOT_LIBRARY_DIR . 'includes/class-library-block.php';
require_once JOT_LIBRARY_DIR . 'includes/class-admin.php';
require_once JOT_LIBRARY_DIR . 'includes/class-settings.php';
require_once JOT_LIBRARY_DIR . 'includes/class-importer.php';
require_once JOT_LIBRARY_DIR . 'includes/class-import-command.php';
require_once JOT_LIBRARY_DIR . 'includes/class-plugin.php';

register_activation_hook(__FILE__, array('JotLibrary\\Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('JotLibrary\\Plugin', 'deactivate'));

JotLibrary\Plugin::boot();
