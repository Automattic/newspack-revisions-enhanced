<?php
/**
 * NRE_Migration_Dashboard — Admin page and REST API for the Migration Dashboard.
 *
 * @package Newspack_Revisions_Enhanced
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page and REST API for the Migration Dashboard.
 */
class NRE_Migration_Dashboard {

	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'nre/v1';

	/**
	 * Rollback handler.
	 *
	 * @var NRE_Migration_Rollback
	 */
	private $rollback;

	/**
	 * Constructor.
	 *
	 * @param NRE_Migration_Rollback $rollback Rollback handler instance.
	 */
	public function __construct( NRE_Migration_Rollback $rollback ) {
		$this->rollback = $rollback;
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_action( 'admin_menu', [ $this, 'add_admin_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'admin_post_nre_export_migration', [ $this, 'handle_export' ] );
		add_filter( 'admin_body_class', [ $this, 'add_body_class' ] );
	}

	/**
	 * Add a body class on the migrations page for scoped styling.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function add_body_class( $classes ) {
		$screen = get_current_screen();
		if ( $screen && 'tools_page_nre-migrations' === $screen->id ) {
			$classes .= ' nre-dashboard-page';
		}
		return $classes;
	}

	/**
	 * Add the admin page under Tools.
	 */
	public function add_admin_page() {
		add_management_page(
			__( 'Migrations', 'newspack-revisions-enhanced' ),
			__( 'Migrations', 'newspack-revisions-enhanced' ),
			'edit_posts',
			'nre-migrations',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Render the admin page mount point.
	 */
	public function render_page() {
		echo '<div id="nre-migration-dashboard" class="nre-dashboard-wrap"></div>';
	}

	/**
	 * Enqueue dashboard assets on the migrations page only.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'tools_page_nre-migrations' !== $hook_suffix ) {
			return;
		}

		$asset_file = NRE_PLUGIN_DIR . 'build/dashboard/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'nre-migration-dashboard',
			plugins_url( 'build/dashboard/index.js', __DIR__ ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'nre-migration-dashboard',
			plugins_url( 'build/dashboard/style-index.css', __DIR__ ),
			[ 'wp-components' ],
			$asset['version']
		);

		wp_localize_script(
			'nre-migration-dashboard',
			'nreDashboard',
			[
				'restUrl'     => rest_url( self::REST_NAMESPACE ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'exportUrl'   => admin_url( 'admin-post.php' ),
				'exportNonce' => wp_create_nonce( 'nre_export_migration' ),
			]
		);
	}

	/**
	 * Register REST API routes.
	 */
	public function register_rest_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/migrations',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_migrations' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/migrations/(?P<term_id>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_migration_detail' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'term_id' => [
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					],
				],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/migrations/(?P<term_id>\d+)/rollback',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rollback_post' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'term_id' => [
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					],
					'post_id' => [
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					],
				],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/migrations/(?P<term_id>\d+)/diff/(?P<post_id>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_post_diff' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'term_id' => [
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					],
					'post_id' => [
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					],
				],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/migrations/(?P<term_id>\d+)/rollback-all',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rollback_all' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'term_id' => [
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					],
				],
			]
		);
	}

	/**
	 * Permission check for REST routes.
	 *
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * GET /migrations — List all migration terms.
	 *
	 * @return WP_REST_Response
	 */
	public function get_migrations() {
		$terms = get_terms(
			[
				'taxonomy'   => NRE_Migration_Context::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'term_id',
				'order'      => 'DESC',
			]
		);

		if ( is_wp_error( $terms ) ) {
			return new WP_REST_Response( [], 200 );
		}

		$migrations = [];
		foreach ( $terms as $term ) {
			$timestamp = (int) get_term_meta( $term->term_id, '_nre_migration_ts', true );

			$migrations[] = [
				'term_id'    => $term->term_id,
				'name'       => $term->name,
				'slug'       => $term->slug,
				'timestamp'  => $timestamp,
				'date'       => $timestamp ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) : '',
				'post_count' => (int) $term->count,
			];
		}

		return new WP_REST_Response( $migrations, 200 );
	}

	/**
	 * GET /migrations/<term_id> — Migration detail with posts and stats.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public function get_migration_detail( $request ) {
		$term_id = (int) $request['term_id'];
		$term    = get_term( $term_id, NRE_Migration_Context::TAXONOMY );

		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_REST_Response( [ 'message' => 'Migration not found.' ], 404 );
		}

		$timestamp      = (int) get_term_meta( $term_id, '_nre_migration_ts', true );
		$migration_name = $term->name;

		// Get all posts assigned to this migration term.
		$post_ids = get_posts(
			[
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => [
					[
						'taxonomy' => NRE_Migration_Context::TAXONOMY,
						'terms'    => $term_id,
					],
				],
			]
		);

		$posts           = [];
		$posts_created   = 0;
		$posts_updated   = 0;
		$total_revisions = 0;

		foreach ( $post_ids as $post_id ) {
			$post_data = $this->get_post_migration_data( $post_id, $migration_name, $timestamp );

			$posts[] = $post_data;

			if ( 'created' === $post_data['status'] ) {
				++$posts_created;
			} else {
				++$posts_updated;
			}

			$total_revisions += $post_data['revision_count'];
		}

		return new WP_REST_Response(
			[
				'term_id'   => $term_id,
				'name'      => $migration_name,
				'slug'      => $term->slug,
				'timestamp' => $timestamp,
				'date'      => $timestamp ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) : '',
				'stats'     => [
					'total_posts'     => count( $post_ids ),
					'posts_created'   => $posts_created,
					'posts_updated'   => $posts_updated,
					'total_revisions' => $total_revisions,
				],
				'posts'     => $posts,
			],
			200
		);
	}

	/**
	 * Build migration data for a single post.
	 *
	 * @param int    $post_id         The post ID.
	 * @param string $migration_name  The migration name to match.
	 * @param int    $migration_ts    The migration timestamp to match.
	 * @return array Post data with rollback info.
	 */
	private function get_post_migration_data( $post_id, $migration_name, $migration_ts ) {
		$post = get_post( $post_id );

		// Get all revisions for this post ordered by date ASC, ID ASC.
		$revisions = wp_get_post_revisions(
			$post_id,
			[
				'order'   => 'ASC',
				'orderby' => 'date ID',
			]
		);

		$migration_revisions  = [];
		$pre_migration_rev_id = null;
		$prev_rev_id          = null;

		foreach ( $revisions as $rev ) {
			$rev_name = get_post_meta( $rev->ID, '_nre_migration_name', true );
			$rev_ts   = (int) get_post_meta( $rev->ID, '_nre_migration_ts', true );

			if ( $rev_name === $migration_name && $rev_ts === $migration_ts ) {
				$migration_revisions[] = $rev->ID;

				// The first matching revision's predecessor is the pre-migration state.
				if ( null === $pre_migration_rev_id && null !== $prev_rev_id ) {
					$pre_migration_rev_id = $prev_rev_id;
				}
			}

			$prev_rev_id = $rev->ID;
		}

		$status       = ( null === $pre_migration_rev_id ) ? 'created' : 'updated';
		$can_rollback = ( 'updated' === $status );

		$compare_from = $pre_migration_rev_id;
		$compare_to   = $migration_revisions[0] ?? null;

		return [
			'post_id'        => $post_id,
			'title'          => $post->post_title ? $post->post_title : __( '(no title)', 'newspack-revisions-enhanced' ),
			'post_type'      => $post->post_type,
			'post_status'    => $post->post_status,
			'edit_url'       => get_edit_post_link( $post_id, 'raw' ),
			'status'         => $status,
			'can_rollback'   => $can_rollback,
			'revision_count' => count( $migration_revisions ),
			'compare_from'   => $compare_from,
			'compare_to'     => $compare_to,
			'revision_url'   => ( $compare_from && $compare_to )
				? admin_url( "revision.php?from={$compare_from}&to={$compare_to}" )
				: null,
		];
	}

	/**
	 * POST /migrations/<term_id>/rollback — Roll back a single post.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public function rollback_post( $request ) {
		$term_id = (int) $request['term_id'];
		$post_id = (int) $request->get_param( 'post_id' );

		$term = get_term( $term_id, NRE_Migration_Context::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_REST_Response( [ 'message' => 'Migration not found.' ], 404 );
		}

		$timestamp = (int) get_term_meta( $term_id, '_nre_migration_ts', true );

		$result = $this->rollback->rollback_post( $post_id, $term->name, $timestamp );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [ 'message' => $result->get_error_message() ], 400 );
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => sprintf(
					/* translators: %s: Post title. */
					__( 'Post "%s" has been rolled back.', 'newspack-revisions-enhanced' ),
					get_the_title( $post_id )
				),
			],
			200
		);
	}

	/**
	 * POST /migrations/<term_id>/rollback-all — Roll back all rollbackable posts.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public function rollback_all( $request ) {
		$term_id = (int) $request['term_id'];

		$term = get_term( $term_id, NRE_Migration_Context::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_REST_Response( [ 'message' => 'Migration not found.' ], 404 );
		}

		$timestamp = (int) get_term_meta( $term_id, '_nre_migration_ts', true );

		$result = $this->rollback->rollback_migration( $term->name, $timestamp, $term_id );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Handle the admin-post export action. Outputs a self-contained HTML report.
	 */
	public function handle_export() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to export migrations.', 'newspack-revisions-enhanced' ), 403 );
		}

		$term_id = isset( $_GET['term_id'] ) ? (int) $_GET['term_id'] : 0;

		check_admin_referer( 'nre_export_migration' );

		$term = get_term( $term_id, NRE_Migration_Context::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			wp_die( esc_html__( 'Migration not found.', 'newspack-revisions-enhanced' ), 404 );
		}

		$timestamp      = (int) get_term_meta( $term_id, '_nre_migration_ts', true );
		$migration_name = $term->name;
		$date_display   = $timestamp ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) : '';

		// Get all posts assigned to this migration term.
		$post_ids = get_posts(
			[
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => [
					[
						'taxonomy' => NRE_Migration_Context::TAXONOMY,
						'terms'    => $term_id,
					],
				],
			]
		);

		$posts           = [];
		$posts_created   = 0;
		$posts_updated   = 0;
		$total_revisions = 0;

		foreach ( $post_ids as $post_id ) {
			$post_data = $this->get_post_migration_data( $post_id, $migration_name, $timestamp );

			$posts[]          = $post_data;
			$posts_created   += ( 'created' === $post_data['status'] ) ? 1 : 0;
			$posts_updated   += ( 'updated' === $post_data['status'] ) ? 1 : 0;
			$total_revisions += $post_data['revision_count'];
		}

		// Build diffs for updated posts.
		require_once ABSPATH . 'wp-admin/includes/revision.php';

		$diffs_by_post = [];
		foreach ( $posts as $post_data ) {
			if ( ! $post_data['compare_from'] || ! $post_data['compare_to'] ) {
				continue;
			}

			$diff = wp_get_revision_ui_diff(
				get_post( $post_data['post_id'] ),
				$post_data['compare_from'],
				$post_data['compare_to']
			);

			if ( $diff ) {
				$diffs_by_post[ $post_data['post_id'] ] = $diff;
			}
		}

		$html = $this->build_export_html(
			$migration_name,
			$date_display,
			$posts_created,
			$posts_updated,
			$total_revisions,
			$posts,
			$diffs_by_post
		);

		$filename = sanitize_file_name( 'migration-' . $term->slug . '-report.html' );

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $html ) );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Self-contained HTML report built with escaped values.
		exit;
	}

	/**
	 * Build the self-contained HTML report.
	 *
	 * @param string $name            Migration name.
	 * @param string $date            Migration date display string.
	 * @param int    $created         Number of created posts.
	 * @param int    $updated         Number of updated posts.
	 * @param int    $total_revisions Total revision count.
	 * @param array  $posts           Post data array.
	 * @param array  $diffs_by_post   Diff arrays keyed by post ID.
	 * @return string Complete HTML document.
	 */
	private function build_export_html( $name, $date, $created, $updated, $total_revisions, $posts, $diffs_by_post ) {
		$total  = count( $posts );
		$gen_ts = wp_date( 'Y-m-d H:i:s T' );

		ob_start();
		?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Migration Report: <?php echo esc_html( $name ); ?></title>
<style>
*,*::before,*::after{box-sizing:border-box}
body{margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen,Ubuntu,sans-serif;font-size:14px;line-height:1.6;color:#1e1e1e;background:#fff}
a{color:#003da5;text-decoration:none}
a:hover{text-decoration:underline}
.header{background:#003da5;color:#fff;padding:1.5rem 2rem;display:flex;align-items:center;gap:1rem}
.header svg{flex-shrink:0}
.header h1{margin:0;font-size:1.25rem;font-weight:600;color:#fff}
.header .subtitle{font-size:.85rem;opacity:.75;margin-top:2px}
.pdf-btn{margin-left:auto;padding:.5rem 1rem;background:#fff;color:#003da5;border:none;border-radius:3px;font-size:.825rem;font-weight:600;cursor:pointer;transition:background 125ms}
.pdf-btn:hover{background:#e8f0fe}
.content{padding:2rem;max-width:1200px;margin:0 auto}
.meta{color:#6c6c6c;font-size:.85rem;margin-bottom:1.5rem}
.stats{display:flex;gap:1.5rem;margin-bottom:2rem;flex-wrap:wrap}
.stat{background:#f7f7f7;border:1px solid #ddd;border-radius:6px;padding:.75rem 1.25rem;min-width:120px}
.stat strong{display:block;font-size:1.4rem;color:#003da5}
.stat span{font-size:.8rem;color:#6c6c6c;text-transform:uppercase;letter-spacing:.03em}
.tabs{display:flex;gap:0;border-bottom:2px solid #ddd;margin-bottom:0}
.tab{padding:.6rem 1.25rem;font-size:.875rem;font-weight:600;color:#6c6c6c;cursor:pointer;border:none;background:none;border-bottom:2px solid transparent;margin-bottom:-2px;transition:color 125ms,border-color 125ms}
.tab:hover{color:#1e1e1e}
.tab.active{color:#003da5;border-bottom-color:#003da5}
.tab .count{font-weight:400;color:#949494;margin-left:4px}
table{width:100%;border-collapse:collapse;font-size:.875rem}
th,td{text-align:left;padding:.5rem .75rem;border-bottom:1px solid #ddd}
th{background:#f7f7f7;font-weight:600;color:#1e1e1e;border-bottom:2px solid #ddd}
.badge{display:inline-block;padding:2px 8px;border-radius:2px;font-size:.75rem;font-weight:600;text-transform:uppercase}
.badge-created{background:#e6f4ea;color:#1a7431}
.badge-updated{background:#e8f0fe;color:#003da5}
tr.row-toggle{cursor:pointer;transition:background 125ms ease-in-out}
tr.row-toggle:hover td{background:#f7f7f7}
tr.row-toggle td:first-child::before{content:"\25B6";display:inline-block;margin-right:.5rem;font-size:.65rem;transition:transform 125ms ease-in-out;color:#6c6c6c}
tr.row-toggle.open td:first-child::before{transform:rotate(90deg)}
tr.row-diff{display:none}
tr.row-diff.open{display:table-row}
tbody.filter-created tr[data-status="updated"],tbody.filter-updated tr[data-status="created"]{display:none!important}
tr.row-diff>td{padding:0;border-bottom:2px solid #ddd;background:#fff}
.diff-wrap{padding:.75rem 1rem}
.diff-field{padding:.5rem 0;border-bottom:1px solid #f0f0f0}
.diff-field:last-child{border-bottom:none}
.diff-field-name{font-weight:600;margin-bottom:.4rem;color:#6c6c6c;font-size:.8rem;text-transform:uppercase}
.diff-field table{margin:0;font-size:.8rem;width:100%;table-layout:fixed}
.diff-field table td{width:50%}
.diff-field td{border:none;padding:.2rem .5rem;vertical-align:top;word-break:break-word}
.diff-field .diff-deletedline{background-color:#fce4e4}
.diff-field .diff-addedline{background-color:#e6f4ea}
.diff-field del{background-color:#f8b4b4;text-decoration:none}
.diff-field ins{background-color:#a7e3bb;text-decoration:none}
.diff-field .diff-context{color:#6c6c6c}
footer{margin-top:3rem;padding-top:1rem;border-top:1px solid #ddd;font-size:.8rem;color:#949494;text-align:center}
@media print{.header{background:#003da5;-webkit-print-color-adjust:exact;print-color-adjust:exact}.pdf-btn{display:none}body{padding:0}.content{padding:.5rem}.stats{gap:.75rem}.stat{padding:.4rem .75rem}.tabs{display:none}tbody.filter-created tr[data-status],tbody.filter-updated tr[data-status]{display:table-row!important}tr.row-diff{display:table-row!important}}
</style>
</head>
<body>
<div class="header">
	<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="#fff"><path fill-rule="evenodd" clip-rule="evenodd" d="M24 12C24 18.6271 18.6271 24 12 24C5.37213 24 0 18.6271 0 12C0 5.3729 5.3729 0 12 0C18.6271 0 24 5.3729 24 12ZM17.4545 17.4546L6.54545 6.54545V17.4545H8.72727V11.8182L14.3636 17.4546H17.4545ZM11.2727 8.18182H17.4545V6.54545H9.63636L11.2727 8.18182ZM17.4545 11.2727H14.3636L12.7273 9.63636H17.4545V11.2727ZM17.4545 12.7273V14.3636L15.8182 12.7273H17.4545Z"/></svg>
	<div>
		<h1>Migration Report</h1>
		<div class="subtitle"><?php echo esc_html( $name ); ?></div>
	</div>
	<button class="pdf-btn" onclick="window.print()">Save as PDF</button>
</div>

<div class="content">
<div class="meta">
		<?php if ( $date ) : ?>
		Migration date: <?php echo esc_html( $date ); ?> &middot;
	<?php endif; ?>
	Generated: <?php echo esc_html( $gen_ts ); ?>
</div>

<div class="stats">
	<div class="stat"><strong><?php echo esc_html( $total ); ?></strong><span>Total Posts</span></div>
	<div class="stat"><strong><?php echo esc_html( $created ); ?></strong><span>Created</span></div>
	<div class="stat"><strong><?php echo esc_html( $updated ); ?></strong><span>Updated</span></div>
	<div class="stat"><strong><?php echo esc_html( $total_revisions ); ?></strong><span>Revisions</span></div>
</div>

<div class="tabs">
	<button class="tab active" data-filter="all">All <span class="count"><?php echo esc_html( $total ); ?></span></button>
	<button class="tab" data-filter="created">Created <span class="count"><?php echo esc_html( $created ); ?></span></button>
	<button class="tab" data-filter="updated">Updated <span class="count"><?php echo esc_html( $updated ); ?></span></button>
</div>
<table>
<thead><tr><th>ID</th><th>Title</th><th>Status</th><th>Type</th><th>Revisions</th></tr></thead>
<tbody>
		<?php
		foreach ( $posts as $p ) :
			$has_diff = isset( $diffs_by_post[ $p['post_id'] ] );
			$view_url = get_permalink( $p['post_id'] );
			?>
<tr class="<?php echo $has_diff ? 'row-toggle' : ''; ?>" data-status="<?php echo esc_html( $p['status'] ); ?>" <?php echo $has_diff ? 'data-diff="diff-' . esc_html( $p['post_id'] ) . '"' : ''; ?>>
	<td><?php echo esc_html( $p['post_id'] ); ?></td>
	<td>
			<?php
			if ( $view_url ) :
				?>
		<a href="<?php echo esc_url( $view_url ); ?>" target="_blank"><?php echo esc_html( $p['title'] ); ?></a>
				<?php
else :
	?>
		<?php echo esc_html( $p['title'] ); ?><?php endif; ?></td>
	<td><span class="badge badge-<?php echo esc_html( $p['status'] ); ?>"><?php echo esc_html( $p['status'] ); ?></span></td>
	<td><?php echo esc_html( $p['post_type'] ); ?></td>
	<td><?php echo esc_html( $p['revision_count'] ); ?></td>
</tr>
			<?php if ( $has_diff ) : ?>
<tr class="row-diff" data-status="<?php echo esc_html( $p['status'] ); ?>" id="diff-<?php echo esc_html( $p['post_id'] ); ?>">
	<td colspan="5">
		<div class="diff-wrap">
				<?php foreach ( $diffs_by_post[ $p['post_id'] ] as $field ) : ?>
			<div class="diff-field">
				<div class="diff-field-name"><?php echo esc_html( $field['name'] ); ?></div>
				<div><?php echo $field['diff']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML from wp_get_revision_ui_diff(). ?></div>
			</div>
		<?php endforeach; ?>
		</div>
	</td>
</tr>
<?php endif; ?>
<?php endforeach; ?>
</tbody>
</table>
</div>

<footer>Generated by Newspack Revisions Enhanced</footer>
<script>
(function(){
	// Accordion toggles.
	document.querySelectorAll('.row-toggle').forEach(function(row){
		row.addEventListener('click',function(){
			var id=this.getAttribute('data-diff');
			var diff=document.getElementById(id);
			if(diff){this.classList.toggle('open');diff.classList.toggle('open')}
		});
	});
	// Tab filtering — toggle a class on tbody so CSS handles visibility.
	var tbody=document.querySelector('tbody');
	var tabs=document.querySelectorAll('.tab');
	tabs.forEach(function(tab){
		tab.addEventListener('click',function(){
			tabs.forEach(function(t){t.classList.remove('active')});
			this.classList.add('active');
			var filter=this.getAttribute('data-filter');
			tbody.className=filter==='all'?'':'filter-'+filter;
		});
	});
})();
</script>
</body>
</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * GET /migrations/<term_id>/diff/<post_id> — Revision diff for a post.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public function get_post_diff( $request ) {
		$term_id = (int) $request['term_id'];
		$post_id = (int) $request['post_id'];

		$term = get_term( $term_id, NRE_Migration_Context::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_REST_Response( [ 'message' => 'Migration not found.' ], 404 );
		}

		$timestamp      = (int) get_term_meta( $term_id, '_nre_migration_ts', true );
		$migration_name = $term->name;

		// Find compare_from (pre-migration revision).
		$compare_from = $this->rollback->find_pre_migration_revision( $post_id, $migration_name, $timestamp );
		if ( is_wp_error( $compare_from ) ) {
			return new WP_REST_Response( [ 'message' => $compare_from->get_error_message() ], 400 );
		}

		// Find compare_to (first migration revision).
		$revisions  = wp_get_post_revisions(
			$post_id,
			[
				'order'   => 'ASC',
				'orderby' => 'date ID',
			]
		);
		$compare_to = null;
		foreach ( $revisions as $rev ) {
			$rev_name = get_post_meta( $rev->ID, '_nre_migration_name', true );
			$rev_ts   = (int) get_post_meta( $rev->ID, '_nre_migration_ts', true );
			if ( $rev_name === $migration_name && $rev_ts === $timestamp ) {
				$compare_to = $rev->ID;
				break;
			}
		}

		if ( ! $compare_to ) {
			return new WP_REST_Response( [ 'message' => 'Migration revision not found.' ], 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/revision.php';

		$diff = wp_get_revision_ui_diff( get_post( $post_id ), $compare_from, $compare_to );

		if ( ! $diff ) {
			return new WP_REST_Response( [ 'message' => 'No differences found.' ], 200 );
		}

		return new WP_REST_Response( $diff, 200 );
	}
}
