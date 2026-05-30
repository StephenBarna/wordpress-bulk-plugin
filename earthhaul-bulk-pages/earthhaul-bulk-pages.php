<?php
/**
 * Plugin Name:       EarthHaul Bulk Pages
 * Plugin URI:        https://github.com/StephenBarna/wordpress-bulk-plugin
 * Description:       Bulk-clones a Beaver Builder template into many city + service-location pages, with AI-generated content, image rewrites, and SEO meta.
 * Version:           0.1.18
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Sky Compass Media
 * License:           GPL-2.0-or-later
 * Text Domain:       earthhaul-bulk-pages
 *
 * @package EarthHaul\BulkPages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EHBP_VERSION', '0.1.18' );
define( 'EHBP_PLUGIN_FILE', __FILE__ );
define( 'EHBP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EHBP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EHBP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once EHBP_PLUGIN_DIR . 'includes/class-plugin.php';
require_once EHBP_PLUGIN_DIR . 'includes/services/class-openai-client.php';
require_once EHBP_PLUGIN_DIR . 'includes/services/class-csv-importer.php';
require_once EHBP_PLUGIN_DIR . 'includes/services/class-page-cloner.php';
require_once EHBP_PLUGIN_DIR . 'includes/services/class-layout-walker.php';
require_once EHBP_PLUGIN_DIR . 'includes/services/class-layout-mutator.php';
require_once EHBP_PLUGIN_DIR . 'includes/services/class-prompt-builder.php';
require_once EHBP_PLUGIN_DIR . 'includes/services/class-rewrite-engine.php';
require_once EHBP_PLUGIN_DIR . 'includes/services/class-image-pipeline.php';
require_once EHBP_PLUGIN_DIR . 'includes/services/class-yoast-meta-engine.php';
require_once EHBP_PLUGIN_DIR . 'includes/services/class-neighborhoods-csv-importer.php';
require_once EHBP_PLUGIN_DIR . 'includes/services/class-neighborhoods-applier.php';
require_once EHBP_PLUGIN_DIR . 'includes/services/class-neighborhoods-inline-substituter.php';
require_once EHBP_PLUGIN_DIR . 'includes/services/class-job-storage.php';
require_once EHBP_PLUGIN_DIR . 'includes/services/class-token-resolver.php';
require_once EHBP_PLUGIN_DIR . 'includes/services/class-bulk-job-runner.php';
require_once EHBP_PLUGIN_DIR . 'includes/services/class-patch-engine.php';
require_once EHBP_PLUGIN_DIR . 'includes/services/class-patch-image-cloner.php';
require_once EHBP_PLUGIN_DIR . 'includes/admin/class-admin-menu.php';
require_once EHBP_PLUGIN_DIR . 'includes/admin/class-settings-page.php';
require_once EHBP_PLUGIN_DIR . 'includes/admin/class-new-job-screen.php';
require_once EHBP_PLUGIN_DIR . 'includes/admin/class-inspect-screen.php';
require_once EHBP_PLUGIN_DIR . 'includes/admin/class-patch-screen.php';
require_once EHBP_PLUGIN_DIR . 'includes/admin/class-leak-scanner-screen.php';
require_once EHBP_PLUGIN_DIR . 'includes/admin/class-coverage-screen.php';

add_action( 'plugins_loaded', array( '\EarthHaul\BulkPages\Plugin', 'instance' ) );
