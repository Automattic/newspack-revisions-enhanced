<?php
/**
 * NRE_Migration_Dashboard — Admin page and REST API for the Migration Dashboard.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
			plugins_url( 'build/dashboard/index.js', dirname( __FILE__ ) ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'nre-migration-dashboard',
			plugins_url( 'build/dashboard/style-index.css', dirname( __FILE__ ) ),
			[ 'wp-components' ],
			$asset['version']
		);

		wp_localize_script( 'nre-migration-dashboard', 'nreDashboard', [
			'restUrl' => rest_url( self::REST_NAMESPACE ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		] );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_rest_routes() {
		register_rest_route( self::REST_NAMESPACE, '/migrations', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_migrations' ],
			'permission_callback' => [ $this, 'check_permission' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/migrations/(?P<term_id>\d+)', [
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
		] );

		register_rest_route( self::REST_NAMESPACE, '/migrations/(?P<term_id>\d+)/rollback', [
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
		] );

		register_rest_route( self::REST_NAMESPACE, '/migrations/(?P<term_id>\d+)/diff/(?P<post_id>\d+)', [
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
		] );

		register_rest_route( self::REST_NAMESPACE, '/migrations/(?P<term_id>\d+)/rollback-all', [
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
		] );
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
		$terms = get_terms( [
			'taxonomy'   => NRE_Migration_Context::TAXONOMY,
			'hide_empty' => false,
			'orderby'    => 'term_id',
			'order'      => 'DESC',
		] );

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
				'date'       => $timestamp ? wp_date( get_option( 'date_format' ), $timestamp ) : '',
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
		$post_ids = get_posts( [
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
		] );

		$posts           = [];
		$posts_created   = 0;
		$posts_updated   = 0;
		$total_revisions = 0;

		foreach ( $post_ids as $post_id ) {
			$post_data = $this->get_post_migration_data( $post_id, $migration_name, $timestamp );

			$posts[] = $post_data;

			if ( 'created' === $post_data['status'] ) {
				$posts_created++;
			} else {
				$posts_updated++;
			}

			$total_revisions += $post_data['revision_count'];
		}

		return new WP_REST_Response( [
			'term_id'   => $term_id,
			'name'      => $migration_name,
			'slug'      => $term->slug,
			'timestamp' => $timestamp,
			'date'      => $timestamp ? wp_date( get_option( 'date_format' ), $timestamp ) : '',
			'stats'     => [
				'total_posts'      => count( $post_ids ),
				'posts_created'    => $posts_created,
				'posts_updated'    => $posts_updated,
				'total_revisions'  => $total_revisions,
			],
			'posts'     => $posts,
		], 200 );
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
		$revisions = wp_get_post_revisions( $post_id, [
			'order'   => 'ASC',
			'orderby' => 'date ID',
		] );

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
			'title'          => $post->post_title ?: __( '(no title)', 'newspack-revisions-enhanced' ),
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

		return new WP_REST_Response( [
			'success' => true,
			'message' => sprintf(
				__( 'Post "%s" has been rolled back.', 'newspack-revisions-enhanced' ),
				get_the_title( $post_id )
			),
		], 200 );
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
		$revisions  = wp_get_post_revisions( $post_id, [
			'order'   => 'ASC',
			'orderby' => 'date ID',
		] );
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
