<?php
/**
 * Plugin Name: Newspack Revisions Enhanced
 * Description: Read-only revision tracking for post meta and taxonomy term assignments.
 * Version:     0.2.0
 * Author:      Newspack
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.4
 * Requires PHP: 7.4
 *
 * @package Newspack_Revisions_Enhanced
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NRE_VERSION', '0.2.0' );
define( 'NRE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NRE_PLUGIN_URL', set_url_scheme( WP_PLUGIN_URL . '/newspack-revisions-enhanced/' ) );
define( 'NRE_TAX_META_PREFIX', '_nre_tax_' );

require_once NRE_PLUGIN_DIR . 'includes/class-nre-meta-revisions.php';
require_once NRE_PLUGIN_DIR . 'includes/class-nre-taxonomy-revisions.php';
require_once NRE_PLUGIN_DIR . 'includes/class-nre-post-type-revisions.php';
require_once NRE_PLUGIN_DIR . 'includes/class-nre-revision-ui.php';
require_once NRE_PLUGIN_DIR . 'includes/class-nre-migration-context.php';
require_once NRE_PLUGIN_DIR . 'includes/class-nre-migration-ui.php';
require_once NRE_PLUGIN_DIR . 'includes/class-nre-migration-rollback.php';
require_once NRE_PLUGIN_DIR . 'includes/class-nre-migration-dashboard.php';

add_action( 'init', [ 'NRE_Migration_Context', 'register_taxonomy' ] );
NRE_Migration_Context::register_hooks();
add_action( 'plugins_loaded', 'nre_init' );

/**
 * Initialize plugin components.
 */
function nre_init() {
	$meta_revisions      = new NRE_Meta_Revisions();
	$taxonomy_revisions  = new NRE_Taxonomy_Revisions();
	$post_type_revisions = new NRE_Post_Type_Revisions();
	$revision_ui         = new NRE_Revision_UI( $meta_revisions, $taxonomy_revisions, $post_type_revisions );
	$migration_ui        = new NRE_Migration_UI();
	$migration_rollback  = new NRE_Migration_Rollback();
	$migration_dashboard = new NRE_Migration_Dashboard( $migration_rollback );

	$meta_revisions->register_hooks();
	$taxonomy_revisions->register_hooks();
	$post_type_revisions->register_hooks();
	$revision_ui->register_hooks();
	$migration_ui->register_hooks();
	$migration_dashboard->register_hooks();
}
