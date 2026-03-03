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
	 * Batch size for background rollback.
	 */
	const ROLLBACK_BATCH_SIZE = 200;

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_action( 'admin_menu', [ $this, 'add_admin_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'admin_post_nre_export_migration', [ $this, 'handle_export' ] );
		add_action( 'admin_post_nopriv_nre_rollback_batch', [ $this, 'handle_rollback_batch' ] );
		add_action( 'wp', [ $this, 'setup_revision_preview' ] );
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
			NRE_PLUGIN_URL . 'build/dashboard/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'nre-migration-dashboard',
			NRE_PLUGIN_URL . 'build/dashboard/style-index.css',
			[ 'wp-components' ],
			$asset['version']
		);

		wp_localize_script(
			'nre-migration-dashboard',
			'nreDashboard',
			[
				'restUrl'      => rest_url( self::REST_NAMESPACE ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'exportUrl'    => admin_url( 'admin-post.php' ),
				'exportNonce'  => wp_create_nonce( 'nre_export_migration' ),
				'previewNonce' => wp_create_nonce( 'nre_revision_preview' ),
			]
		);

		$this->cleanup_stale_rollback_options();
	}

	/**
	 * Delete rollback state options older than 1 hour that are not running.
	 */
	private function cleanup_stale_rollback_options() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$option_names = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'nre\_rollback\_%'"
		);

		$cutoff = time() - HOUR_IN_SECONDS;

		foreach ( $option_names as $option_name ) {
			$state = get_option( $option_name );
			if ( ! is_array( $state ) || empty( $state['started_at'] ) ) {
				continue;
			}
			if ( 'running' === $state['status'] ) {
				continue;
			}
			if ( $state['started_at'] < $cutoff ) {
				delete_option( $option_name );
			}
		}
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
			'/migrations/(?P<term_id>\d+)/posts',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_migration_posts' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'term_id'  => [
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					],
					'per_page' => [
						'default'           => 50,
						'sanitize_callback' => function ( $param ) {
							return min( max( (int) $param, 1 ), 100 );
						},
					],
					'page'     => [
						'default'           => 1,
						'sanitize_callback' => function ( $param ) {
							return max( (int) $param, 1 );
						},
					],
					'status'   => [
						'default'           => 'all',
						'validate_callback' => function ( $param ) {
							return in_array( $param, [ 'all', 'created', 'updated' ], true );
						},
					],
					'search'   => [
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
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

		register_rest_route(
			self::REST_NAMESPACE,
			'/migrations/(?P<term_id>\d+)/rollback-status',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_rollback_status' ],
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
			'/migrations/(?P<term_id>\d+)/rollback-cancel',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'cancel_rollback' ],
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
	 * GET /migrations/<term_id> — Migration detail with stats (no posts).
	 *
	 * Posts are served by the separate paginated endpoint.
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

		$statuses        = $this->get_post_statuses( $term_id, $migration_name, $timestamp );
		$posts_created   = 0;
		$posts_updated   = 0;
		$total_revisions = 0;

		foreach ( $statuses as $info ) {
			if ( 'created' === $info['status'] ) {
				++$posts_created;
			} else {
				++$posts_updated;
			}
			$total_revisions += $info['revision_count'];
		}

		return new WP_REST_Response(
			[
				'term_id'   => $term_id,
				'name'      => $migration_name,
				'slug'      => $term->slug,
				'timestamp' => $timestamp,
				'date'      => $timestamp ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) : '',
				'stats'     => [
					'total_posts'     => count( $statuses ),
					'posts_created'   => $posts_created,
					'posts_updated'   => $posts_updated,
					'total_revisions' => $total_revisions,
				],
			],
			200
		);
	}

	/**
	 * GET /migrations/<term_id>/posts — Paginated posts for a migration.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public function get_migration_posts( $request ) {
		$term_id  = (int) $request['term_id'];
		$per_page = (int) $request->get_param( 'per_page' );
		$page     = (int) $request->get_param( 'page' );
		$status   = $request->get_param( 'status' );
		$search   = $request->get_param( 'search' );

		$term = get_term( $term_id, NRE_Migration_Context::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_REST_Response( [ 'message' => 'Migration not found.' ], 404 );
		}

		$timestamp      = (int) get_term_meta( $term_id, '_nre_migration_ts', true );
		$migration_name = $term->name;

		$statuses = $this->get_post_statuses( $term_id, $migration_name, $timestamp );
		$post_ids = array_keys( $statuses );

		// Filter by status.
		if ( 'all' !== $status ) {
			$post_ids = array_filter(
				$post_ids,
				function ( $pid ) use ( $statuses, $status ) {
					return $statuses[ $pid ]['status'] === $status;
				}
			);
		}

		// Filter by search (title LIKE or exact ID match).
		if ( ! empty( $search ) ) {
			$search   = trim( $search );
			$post_ids = $this->filter_posts_by_search( $post_ids, $search );
		}

		$post_ids = array_values( $post_ids );
		$total    = count( $post_ids );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$page     = min( $page, $pages );
		$offset   = ( $page - 1 ) * $per_page;
		$page_ids = array_slice( $post_ids, $offset, $per_page );

		// Build full data only for the current page.
		$posts = [];
		foreach ( $page_ids as $post_id ) {
			$posts[] = $this->get_post_migration_data( $post_id, $migration_name, $timestamp );
		}

		return new WP_REST_Response(
			[
				'posts'       => $posts,
				'total'       => $total,
				'total_pages' => $pages,
				'page'        => $page,
				'per_page'    => $per_page,
			],
			200
		);
	}

	/**
	 * Compute and cache post status classifications for a migration.
	 *
	 * @param int    $term_id        The migration term ID.
	 * @param string $migration_name The migration name.
	 * @param int    $migration_ts   The migration timestamp.
	 * @return array Associative array of post_id => [ 'status' => string, 'revision_count' => int ].
	 */
	private function get_post_statuses( $term_id, $migration_name, $migration_ts ) {
		$cache_key = 'nre_migration_statuses_' . $term_id;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$taxonomy = NRE_Migration_Context::TAXONOMY;

		// One query: scan all revisions for posts in this migration term.
		// LEFT JOIN on migration meta lets us count migration revisions (both meta match).
		// JOIN the parent post to compare its post_date_gmt against the migration timestamp:
		// if the post existed before the migration started, it was "updated"; otherwise "created".
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.post_parent AS post_id,
						p.post_date_gmt AS post_created_gmt,
						SUM( CASE WHEN mn.meta_id IS NOT NULL AND mt.meta_id IS NOT NULL THEN 1 ELSE 0 END ) AS revision_count
				 FROM {$wpdb->posts} r
				 INNER JOIN {$wpdb->term_relationships} tr ON r.post_parent = tr.object_id
				 INNER JOIN {$wpdb->term_taxonomy} tt
					ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.term_id = %d AND tt.taxonomy = %s
				 INNER JOIN {$wpdb->posts} p ON r.post_parent = p.ID
				 LEFT JOIN {$wpdb->postmeta} mn
					ON r.ID = mn.post_id AND mn.meta_key = '_nre_migration_name' AND mn.meta_value = %s
				 LEFT JOIN {$wpdb->postmeta} mt
					ON r.ID = mt.post_id AND mt.meta_key = '_nre_migration_ts' AND mt.meta_value = %s
				 WHERE r.post_type = 'revision'
				 GROUP BY r.post_parent
				 HAVING revision_count > 0",
				$term_id,
				$taxonomy,
				$migration_name,
				(string) $migration_ts
			)
		);

		$migration_date = gmdate( 'Y-m-d H:i:s', $migration_ts );
		$statuses       = [];

		foreach ( $rows as $row ) {
			$post_id = (int) $row->post_id;
			$status  = ( $row->post_created_gmt < $migration_date ) ? 'updated' : 'created';

			$statuses[ $post_id ] = [
				'status'         => $status,
				'revision_count' => (int) $row->revision_count,
			];
		}

		set_transient( $cache_key, $statuses );

		return $statuses;
	}

	/**
	 * Invalidate the cached post statuses for a migration.
	 *
	 * @param int $term_id The migration term ID.
	 */
	private function invalidate_status_cache( $term_id ) {
		delete_transient( 'nre_migration_statuses_' . $term_id );
	}

	/**
	 * Filter post IDs by search term (title LIKE or exact ID match).
	 *
	 * @param array  $post_ids Array of post IDs to filter.
	 * @param string $search   The search string.
	 * @return array Filtered post IDs.
	 */
	private function filter_posts_by_search( $post_ids, $search ) {
		if ( empty( $post_ids ) ) {
			return [];
		}

		global $wpdb;

		// Exact ID match.
		if ( is_numeric( $search ) ) {
			$search_id = (int) $search;
			if ( in_array( $search_id, $post_ids, true ) ) {
				return [ $search_id ];
			}
		}

		// Title LIKE search.
		$id_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$matching_ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder string is safe.
				"SELECT ID FROM {$wpdb->posts} WHERE ID IN ($id_placeholders) AND post_title LIKE %s",
				...array_merge( $post_ids, [ '%' . $wpdb->esc_like( $search ) . '%' ] )
			)
		);

		return array_map( 'intval', $matching_ids );
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

		// A post is "updated" if it existed before the migration, "created" otherwise.
		// We can't rely solely on pre_migration_rev_id — posts updated for the first time
		// by the migration won't have a prior revision.
		$post_created_ts = strtotime( $post->post_date_gmt );
		$status          = ( $post_created_ts < $migration_ts ) ? 'updated' : 'created';
		$can_rollback    = ( 'updated' === $status );

		$compare_from = $pre_migration_rev_id ?? 0;
		$compare_to   = ! empty( $migration_revisions ) ? end( $migration_revisions ) : null;

		return [
			'post_id'        => $post_id,
			'title'          => $post->post_title ? $post->post_title : __( '(no title)', 'newspack-revisions-enhanced' ),
			'post_type'      => $post->post_type,
			'post_status'    => $post->post_status,
			'edit_url'       => get_edit_post_link( $post_id, 'raw' ),
			'view_url'       => get_permalink( $post_id ),
			'status'         => $status,
			'can_rollback'   => $can_rollback,
			'revision_count' => count( $migration_revisions ),
			'compare_from'   => $compare_from,
			'compare_to'     => $compare_to,
			'revision_url'   => $compare_to
				? ( $compare_from
					? admin_url( "revision.php?from={$compare_from}&to={$compare_to}" )
					: admin_url( "revision.php?revision={$compare_to}" ) )
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

		$timestamp      = (int) get_term_meta( $term_id, '_nre_migration_ts', true );
		$migration_name = $term->name;

		NRE_Migration_Context::start( 'Rollback: ' . $migration_name );

		$result = $this->rollback->rollback_post( $post_id, $migration_name, $timestamp );

		NRE_Migration_Context::stop();

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [ 'message' => $result->get_error_message() ], 400 );
		}

		$this->invalidate_status_cache( $term_id );

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
	 * POST /migrations/<term_id>/rollback-all — Start background bulk rollback.
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

		$option_key = 'nre_rollback_' . $term_id;
		$existing   = get_option( $option_key );

		if ( is_array( $existing ) && 'running' === $existing['status'] ) {
			return new WP_REST_Response( [ 'message' => 'A rollback is already running for this migration.' ], 409 );
		}

		$timestamp      = (int) get_term_meta( $term_id, '_nre_migration_ts', true );
		$migration_name = $term->name;

		$post_ids = get_posts(
			[
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					[
						'taxonomy' => NRE_Migration_Context::TAXONOMY,
						'terms'    => $term_id,
					],
				],
			]
		);

		if ( empty( $post_ids ) ) {
			return new WP_REST_Response(
				[
					'status'      => 'complete',
					'total'       => 0,
					'rolled_back' => 0,
					'skipped'     => 0,
					'errors'      => [],
				],
				200
			);
		}

		NRE_Migration_Context::start( 'Rollback: ' . $migration_name );

		$secret = wp_generate_password( 32, false );

		$state = [
			'status'         => 'running',
			'total'          => count( $post_ids ),
			'processed'      => 0,
			'rolled_back'    => 0,
			'skipped'        => 0,
			'errors'         => [],
			'started_at'     => time(),
			'offset'         => 0,
			'post_ids'       => $post_ids,
			'migration_name' => $migration_name,
			'migration_ts'   => $timestamp,
			'secret'         => $secret,
		];

		update_option( $option_key, $state, false );

		$this->fire_rollback_loopback( $term_id, $secret );

		return new WP_REST_Response(
			[
				'status' => 'running',
				'total'  => count( $post_ids ),
			],
			200
		);
	}

	/**
	 * GET /migrations/<term_id>/rollback-status — Current rollback state.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public function get_rollback_status( $request ) {
		$term_id    = (int) $request['term_id'];
		$option_key = 'nre_rollback_' . $term_id;
		$state      = get_option( $option_key );

		if ( ! is_array( $state ) ) {
			return new WP_REST_Response( [ 'status' => 'idle' ], 200 );
		}

		return new WP_REST_Response(
			[
				'status'      => $state['status'],
				'total'       => $state['total'],
				'processed'   => $state['processed'],
				'rolled_back' => $state['rolled_back'],
				'skipped'     => $state['skipped'],
				'errors'      => $state['errors'],
				'started_at'  => $state['started_at'],
			],
			200
		);
	}

	/**
	 * POST /migrations/<term_id>/rollback-cancel — Cancel a running rollback.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public function cancel_rollback( $request ) {
		$term_id    = (int) $request['term_id'];
		$option_key = 'nre_rollback_' . $term_id;
		$state      = get_option( $option_key );

		if ( ! is_array( $state ) || 'running' !== $state['status'] ) {
			return new WP_REST_Response( [ 'message' => 'No running rollback to cancel.' ], 400 );
		}

		$state['status'] = 'cancelled';
		unset( $state['secret'] );
		update_option( $option_key, $state, false );

		NRE_Migration_Context::stop();
		$this->invalidate_status_cache( $term_id );

		return new WP_REST_Response( [ 'status' => 'cancelled' ], 200 );
	}

	/**
	 * Loopback batch handler for background rollback.
	 *
	 * Uses admin_post_nopriv because the loopback won't carry user cookies.
	 * Auth is via the secret token stored in the rollback option.
	 */
	public function handle_rollback_batch() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Auth via secret token, not nonce.
		$term_id = isset( $_POST['term_id'] ) ? (int) $_POST['term_id'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$secret = isset( $_POST['secret'] ) ? sanitize_text_field( wp_unslash( $_POST['secret'] ) ) : '';

		if ( ! $term_id || ! $secret ) {
			wp_die( 'Invalid request.', 400 );
		}

		$option_key = 'nre_rollback_' . $term_id;
		$state      = get_option( $option_key );

		if ( ! is_array( $state ) || ! hash_equals( $state['secret'], $secret ) ) {
			wp_die( 'Unauthorized.', 403 );
		}

		if ( 'running' !== $state['status'] ) {
			exit;
		}

		// Restore migration context for this batch.
		NRE_Migration_Context::start( 'Rollback: ' . $state['migration_name'] );

		$offset    = $state['offset'];
		$batch_ids = array_slice( $state['post_ids'], $offset, self::ROLLBACK_BATCH_SIZE );

		if ( empty( $batch_ids ) ) {
			$this->finish_rollback( $option_key, $state, $term_id );
			exit;
		}

		$result = $this->rollback->rollback_batch( $batch_ids, $state['migration_name'], $state['migration_ts'] );

		$state['offset']      += count( $batch_ids );
		$state['processed']   += count( $batch_ids );
		$state['rolled_back'] += $result['rolled_back'];
		$state['skipped']     += $result['skipped'];

		// Cap errors at last 50.
		$state['errors'] = array_merge( $state['errors'], $result['errors'] );
		if ( count( $state['errors'] ) > 50 ) {
			$state['errors'] = array_slice( $state['errors'], -50 );
		}

		// Check if done.
		if ( $state['offset'] >= count( $state['post_ids'] ) ) {
			$this->finish_rollback( $option_key, $state, $term_id );
			exit;
		}

		update_option( $option_key, $state, false );

		NRE_Migration_Context::stop();

		wp_cache_flush();

		$this->fire_rollback_loopback( $term_id, $secret );

		exit;
	}

	/**
	 * Mark rollback as complete and clean up.
	 *
	 * @param string $option_key The option key.
	 * @param array  $state      The current state array.
	 * @param int    $term_id    The migration term ID.
	 */
	private function finish_rollback( $option_key, $state, $term_id ) {
		$state['status'] = 'complete';
		unset( $state['secret'], $state['post_ids'] );
		update_option( $option_key, $state, false );

		NRE_Migration_Context::stop();
		$this->invalidate_status_cache( $term_id );
	}

	/**
	 * Fire a non-blocking loopback request for the next rollback batch.
	 *
	 * @param int    $term_id The migration term ID.
	 * @param string $secret  The auth secret.
	 */
	private function fire_rollback_loopback( $term_id, $secret ) {
		wp_remote_post(
			admin_url( 'admin-post.php' ),
			[
				'blocking'  => false,
				'timeout'   => 0.01,
				'sslverify' => false,
				'body'      => [
					'action'  => 'nre_rollback_batch',
					'term_id' => $term_id,
					'secret'  => $secret,
				],
			]
		);
	}

	/**
	 * Set up revision preview on the frontend.
	 *
	 * When a post URL includes `?nre_preview_revision=<id>`, the post's
	 * content/title/excerpt are swapped with the revision's data before
	 * the theme template renders. This gives a true frontend preview with
	 * the full theme (header, nav, sidebar, footer).
	 */
	public function setup_revision_preview() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below via check_admin_referer.
		if ( ! isset( $_GET['nre_preview_revision'] ) ) {
			return;
		}

		check_admin_referer( 'nre_revision_preview' );

		$revision_id = (int) $_GET['nre_preview_revision'];
		$parent_id   = get_queried_object_id();

		if ( ! current_user_can( 'edit_post', $parent_id ) ) {
			wp_die( esc_html__( 'You do not have permission to preview revisions.', 'newspack-revisions-enhanced' ), 403 );
		}

		// Prevent caching, search-engine indexing, and admin bar.
		nocache_headers();
		show_admin_bar( false );
		add_action(
			'wp_head',
			function () {
				echo '<meta name="robots" content="noindex, nofollow">' . "\n";
			}
		);

		if ( 0 === $revision_id ) {
			// No previous version — show an empty-state message in the theme.
			$empty_content = '<p style="color:#757575;text-align:center;padding:3rem 1rem;">'
				. esc_html__( 'This post did not exist before the migration.', 'newspack-revisions-enhanced' )
				. '</p>';
			add_action(
				'the_post',
				function ( $post, $query ) use ( $empty_content ) {
					if ( $query->is_main_query() ) {
						$post->post_title   = __( '(No previous version)', 'newspack-revisions-enhanced' );
						$post->post_content = $empty_content;
						// Update $pages so get_the_content() reads our swapped content
						// (setup_postdata copies post_content into $pages before the_post fires).
						// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
						$GLOBALS['pages'] = [ $empty_content ];
						// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
						$GLOBALS['numpages'] = 1;
					}
				},
				10,
				2
			);
			return;
		}

		$revision = get_post( $revision_id );
		if ( ! $revision || 'revision' !== $revision->post_type ) {
			wp_die( esc_html__( 'Revision not found.', 'newspack-revisions-enhanced' ), 404 );
		}

		// Verify the revision belongs to the queried post.
		if ( (int) $revision->post_parent !== $parent_id ) {
			wp_die( esc_html__( 'Revision does not belong to this post.', 'newspack-revisions-enhanced' ), 403 );
		}

		// Swap the main query post's fields with the revision's data.
		// We also update $GLOBALS['pages'] because setup_postdata() has already
		// copied the original post_content into $pages before the_post fires,
		// and get_the_content() reads from $pages, not $post->post_content.
		add_action(
			'the_post',
			function ( $post, $query ) use ( $revision ) {
				if ( $query->is_main_query() ) {
					$post->post_title   = $revision->post_title;
					$post->post_content = $revision->post_content;
					$post->post_excerpt = $revision->post_excerpt;
					// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
					$GLOBALS['pages'] = [ $revision->post_content ];
					// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
					$GLOBALS['numpages'] = 1;
				}
			},
			10,
			2
		);

		// Swap featured image if the revision has one tracked.
		$rev_thumbnail = get_post_meta( $revision_id, '_thumbnail_id', true );
		if ( $rev_thumbnail ) {
			$queried_id = get_queried_object_id();
			add_filter(
				'get_post_metadata',
				function ( $value, $object_id, $meta_key ) use ( $queried_id, $rev_thumbnail ) {
					if ( $object_id === $queried_id && '_thumbnail_id' === $meta_key ) {
						return [ $rev_thumbnail ];
					}
					return $value;
				},
				10,
				3
			);
		}
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

		// CSV export.
		$format = isset( $_GET['format'] ) ? sanitize_text_field( wp_unslash( $_GET['format'] ) ) : 'html';

		if ( 'csv' === $format ) {
			$this->export_csv( $term, $migration_name, $timestamp );
			return;
		}

		$date_display = $timestamp ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) : '';

		// Use cached statuses for stats (fast, avoids N+1 revision walking).
		$statuses        = $this->get_post_statuses( $term_id, $migration_name, $timestamp );
		$posts_created   = 0;
		$posts_updated   = 0;
		$total_revisions = 0;

		foreach ( $statuses as $info ) {
			if ( 'created' === $info['status'] ) {
				++$posts_created;
			} else {
				++$posts_updated;
			}
			$total_revisions += $info['revision_count'];
		}

		$total    = count( $statuses );
		$post_ids = array_keys( $statuses );

		// Build lightweight post data via bulk query (title + type only).
		$posts_data = $this->get_bulk_post_data( $post_ids, $statuses );

		$report_data = [
			'name'      => $migration_name,
			'date'      => $date_display,
			'generated' => wp_date( 'Y-m-d H:i:s T' ),
			'stats'     => [
				'total'     => $total,
				'created'   => $posts_created,
				'updated'   => $posts_updated,
				'revisions' => $total_revisions,
			],
			'posts'     => $posts_data,
			'term_id'   => $term_id,
			'rest_url'  => rest_url( 'nre/v1' ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
		];

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		$this->render_report_html( $report_data );
		exit;
	}

	/**
	 * Export migration posts as CSV.
	 *
	 * Processes posts in batches and flushes output to avoid memory buildup.
	 *
	 * @param WP_Term $term            The migration term.
	 * @param string  $migration_name  The migration name.
	 * @param int     $timestamp       The migration timestamp.
	 */
	private function export_csv( $term, $migration_name, $timestamp ) {
		$statuses = $this->get_post_statuses( $term->term_id, $migration_name, $timestamp );
		$post_ids = array_keys( $statuses );

		$filename = sanitize_file_name( 'migration-' . $term->slug . '-posts.csv' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, [ 'Post ID', 'Title', 'Migration Status', 'Post Type', 'Post Status', 'Created', 'Revisions', 'Revision URL' ] );

		// Use bulk queries in chunks to avoid per-post lookups.
		global $wpdb;
		$batch_size = 500;
		$chunks     = array_chunk( $post_ids, $batch_size );

		foreach ( $chunks as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title, post_type, post_status, post_date FROM {$wpdb->posts} WHERE ID IN ($placeholders)",
					$chunk
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

			$post_map = [];
			foreach ( $rows as $row ) {
				$post_map[ (int) $row->ID ] = $row;
			}

			foreach ( $chunk as $pid ) {
				$post  = $post_map[ $pid ] ?? null;
				$title = $post && $post->post_title ? $post->post_title : __( '(no title)', 'newspack-revisions-enhanced' );

				fputcsv(
					$output,
					[
						$pid,
						$title,
						$statuses[ $pid ]['status'],
						$post ? $post->post_type : 'post',
						$post ? $post->post_status : '',
						$post ? $post->post_date : '',
						$statuses[ $pid ]['revision_count'],
						admin_url( "revision.php?post={$pid}" ),
					]
				);
			}

			if ( ob_get_level() ) {
				ob_flush();
			}
			flush();
		}

		fclose( $output );
		exit;
	}

	/**
	 * Build lightweight post data via bulk SQL query.
	 *
	 * Returns compact arrays [ id, title, status, type, revision_count ] for all
	 * post IDs, avoiding per-post get_post() calls.
	 *
	 * @param int[] $post_ids All post IDs.
	 * @param array $statuses Cached statuses keyed by post ID.
	 * @return array[] Array of [ id, title, status, type, revision_count ].
	 */
	private function get_bulk_post_data( $post_ids, $statuses ) {
		if ( empty( $post_ids ) ) {
			return [];
		}

		global $wpdb;

		$posts_data = [];
		$chunks     = array_chunk( $post_ids, 500 );

		foreach ( $chunks as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title, post_type FROM {$wpdb->posts} WHERE ID IN ($placeholders)",
					$chunk
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

			$title_map = [];
			$type_map  = [];
			foreach ( $rows as $row ) {
				$title_map[ (int) $row->ID ] = $row->post_title ? $row->post_title : __( '(no title)', 'newspack-revisions-enhanced' );
				$type_map[ (int) $row->ID ]  = $row->post_type;
			}

			foreach ( $chunk as $pid ) {
				$posts_data[] = [
					$pid,
					$title_map[ $pid ] ?? __( '(no title)', 'newspack-revisions-enhanced' ),
					$statuses[ $pid ]['status'],
					$type_map[ $pid ] ?? 'post',
					$statuses[ $pid ]['revision_count'],
				];
			}
		}

		return $posts_data;
	}

	/**
	 * Render self-contained HTML report with embedded JSON data.
	 *
	 * The report embeds all post data as a JSON blob and uses client-side JS
	 * for pagination, search, and tab filtering. This avoids server-side
	 * streaming of 100k+ table rows and produces a fast, interactive report.
	 *
	 * Print/PDF mode renders all rows into a hidden table on beforeprint.
	 *
	 * @param array $report_data Report data with keys: name, date, generated, stats, posts.
	 */
	private function render_report_html( $report_data ) {
		$name     = $report_data['name'];
		$date     = $report_data['date'];
		$gen      = $report_data['generated'];
		$stats    = $report_data['stats'];
		$json     = wp_json_encode( $report_data['posts'] );
		$term_id  = (int) $report_data['term_id'];
		$rest_url = $report_data['rest_url'];
		$nonce    = $report_data['nonce'];

		$fmt_total  = number_format_i18n( $stats['total'] );
		$fmt_create = number_format_i18n( $stats['created'] );
		$fmt_update = number_format_i18n( $stats['updated'] );
		$fmt_revs   = number_format_i18n( $stats['revisions'] );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Self-contained HTML report built with escaped values.
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
.toolbar{display:flex;align-items:center;gap:1rem;margin-bottom:1rem;flex-wrap:wrap}
.tabs{display:flex;gap:0;border-bottom:2px solid #ddd}
.tab{padding:.6rem 1.25rem;font-size:.875rem;font-weight:600;color:#6c6c6c;cursor:pointer;border:none;background:none;border-bottom:2px solid transparent;margin-bottom:-2px;transition:color 125ms,border-color 125ms}
.tab:hover{color:#1e1e1e}
.tab.active{color:#003da5;border-bottom-color:#003da5}
.tab .count{font-weight:400;color:#949494;margin-left:4px}
.search{margin-left:auto;padding:.4rem .75rem;border:1px solid #ddd;border-radius:3px;font-size:.875rem;width:220px}
.search:focus{outline:none;border-color:#003da5;box-shadow:0 0 0 1px #003da5}
table{width:100%;border-collapse:collapse;font-size:.875rem}
th,td{text-align:left;padding:.5rem .75rem;border-bottom:1px solid #ddd}
th{background:#f7f7f7;font-weight:600;color:#1e1e1e;border-bottom:2px solid #ddd}
.badge{display:inline-block;padding:2px 8px;border-radius:2px;font-size:.75rem;font-weight:600;text-transform:uppercase}
.badge-created{background:#e6f4ea;color:#1a7431}
.badge-updated{background:#e8f0fe;color:#003da5}
.pagination{display:flex;align-items:center;justify-content:center;gap:.75rem;margin-top:1rem;font-size:.875rem}
.pagination button{padding:.35rem .75rem;border:1px solid #ddd;background:#fff;border-radius:3px;cursor:pointer;font-size:.8rem;color:#1e1e1e;transition:border-color 125ms}
.pagination button:hover:not(:disabled){border-color:#003da5;color:#003da5}
.pagination button:disabled{opacity:.4;cursor:default}
.pagination .info{color:#6c6c6c}
.btn-diff{padding:2px 10px;border:1px solid #ddd;background:#fff;border-radius:3px;font-size:.75rem;font-weight:600;color:#003da5;cursor:pointer;transition:border-color 125ms,background 125ms}
.btn-diff:hover{border-color:#003da5;background:#f7f7f7}
.btn-diff:disabled{opacity:.5;cursor:default}
.btn-diff.is-loading{color:#949494}
tr.diff-row{display:none}
tr.diff-row.open{display:table-row}
tr.diff-row>td{padding:0;border-bottom:2px solid #ddd;background:#fff}
.diff-wrap{padding:.75rem 1rem}
.diff-field{padding:.5rem 0;border-bottom:1px solid #f0f0f0}
.diff-field:last-child{border-bottom:none}
.diff-field-name{font-weight:600;margin-bottom:.4rem;color:#6c6c6c;font-size:.8rem;text-transform:uppercase}
.diff-field table{margin:0;font-size:.8rem;width:100%;table-layout:fixed}
.diff-field table col.left,.diff-field table col.right{width:50%!important}
.diff-field table col.middle{display:none}
.diff-field table td{width:50%}
.diff-field table td.diff-indicator{display:none}
.diff-field td{border:none;padding:.2rem .5rem;vertical-align:top;word-break:break-word}
.diff-field .diff-deletedline{background-color:#fce4e4}
.diff-field .diff-addedline{background-color:#e6f4ea}
.diff-field del{background-color:#f8b4b4;text-decoration:none}
.diff-field ins{background-color:#a7e3bb;text-decoration:none}
.diff-field .diff-context{color:#6c6c6c}
.diff-field .dashicons,.diff-field .screen-reader-text{display:none}
.diff-field td.diff-addedline::before{content:"+";font-weight:700;color:#1a7431;margin-right:.4rem}
.diff-field td.diff-deletedline::before{content:"\2212";font-weight:700;color:#a50000;margin-right:.4rem}
.diff-error{padding:.75rem 1rem;color:#a50000;font-size:.85rem}
.empty{text-align:center;padding:2rem;color:#6c6c6c}
footer{margin-top:3rem;padding-top:1rem;border-top:1px solid #ddd;font-size:.8rem;color:#949494;text-align:center}
#print-table{display:none}
@media print{
	.header{background:#003da5;-webkit-print-color-adjust:exact;print-color-adjust:exact}
	.pdf-btn,.search,.pagination,.tabs{display:none!important}
	body{padding:0}.content{padding:.5rem}
	.stats{gap:.75rem}.stat{padding:.4rem .75rem}
	#main-table{display:none}
	#print-table{display:table}
}
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
	Generated: <?php echo esc_html( $gen ); ?>
</div>

<div class="stats">
	<div class="stat"><strong><?php echo esc_html( $fmt_total ); ?></strong><span>Total Posts</span></div>
	<div class="stat"><strong><?php echo esc_html( $fmt_create ); ?></strong><span>Created</span></div>
	<div class="stat"><strong><?php echo esc_html( $fmt_update ); ?></strong><span>Updated</span></div>
	<div class="stat"><strong><?php echo esc_html( $fmt_revs ); ?></strong><span>Revisions</span></div>
</div>

<div class="toolbar">
	<div class="tabs">
		<button class="tab active" data-filter="all">All <span class="count"><?php echo esc_html( $fmt_total ); ?></span></button>
		<button class="tab" data-filter="created">Created <span class="count"><?php echo esc_html( $fmt_create ); ?></span></button>
		<button class="tab" data-filter="updated">Updated <span class="count"><?php echo esc_html( $fmt_update ); ?></span></button>
	</div>
	<input type="text" class="search" id="search" placeholder="Search posts..." />
</div>

<table id="main-table">
<thead><tr><th>ID</th><th>Title</th><th>Status</th><th>Type</th><th>Revisions</th><th>Actions</th></tr></thead>
<tbody id="tbody"></tbody>
</table>
<div class="pagination" id="pagination"></div>

<table id="print-table">
<thead><tr><th>ID</th><th>Title</th><th>Status</th><th>Type</th><th>Revisions</th></tr></thead>
<tbody id="print-tbody"></tbody>
</table>
</div>

<footer>Generated by Newspack Revisions Enhanced</footer>
<script>
(function(){
	var PER_PAGE=100;
	var posts=<?php echo $json; ?>;
	var termId=<?php echo (int) $term_id; ?>;
	var restUrl=<?php echo wp_json_encode( $rest_url ); ?>;
	var nonce=<?php echo wp_json_encode( $nonce ); ?>;
	var filtered=posts;
	var page=1;
	var tab='all';
	var query='';
	var debounceTimer;
	var diffCache={};

	function filterPosts(){
		filtered=posts.filter(function(p){
			if(tab!=='all'&&p[2]!==tab)return false;
			if(query){
				var q=query.toLowerCase();
				return String(p[0]).indexOf(q)!==-1||p[1].toLowerCase().indexOf(q)!==-1;
			}
			return true;
		});
	}

	function renderRow(p){
		return '<tr data-post-id="'+esc(p[0])+'"><td>'+esc(p[0])+'</td><td>'+esc(p[1])+'</td><td><span class="badge badge-'+esc(p[2])+'">'+esc(p[2])+'</span></td><td>'+esc(p[3])+'</td><td>'+esc(p[4])+'</td><td><button class="btn-diff" data-post-id="'+esc(p[0])+'">View Changes</button></td></tr>'
			+'<tr class="diff-row" id="diff-'+esc(p[0])+'"><td colspan="6"></td></tr>';
	}

	function renderPrintRow(p){
		return '<tr><td>'+esc(p[0])+'</td><td>'+esc(p[1])+'</td><td><span class="badge badge-'+esc(p[2])+'">'+esc(p[2])+'</span></td><td>'+esc(p[3])+'</td><td>'+esc(p[4])+'</td></tr>';
	}

	function render(){
		filterPosts();
		var totalPages=Math.max(1,Math.ceil(filtered.length/PER_PAGE));
		if(page>totalPages)page=totalPages;
		var start=(page-1)*PER_PAGE;
		var slice=filtered.slice(start,start+PER_PAGE);

		var html='';
		for(var i=0;i<slice.length;i++){html+=renderRow(slice[i])}
		if(!slice.length)html='<tr><td colspan="6" class="empty">No posts match your search.</td></tr>';

		document.getElementById('tbody').innerHTML=html;

		var pag=document.getElementById('pagination');
		if(totalPages>1){
			var fmt=filtered.length.toLocaleString();
			pag.innerHTML='<button id="prev">&lsaquo; Previous</button><span class="info">Page '+page+' of '+totalPages+' ('+fmt+' posts)</span><button id="next">Next &rsaquo;</button>';
			var prev=document.getElementById('prev');
			var next=document.getElementById('next');
			if(page<=1)prev.disabled=true;
			if(page>=totalPages)next.disabled=true;
			prev.onclick=function(){if(page>1){page--;render()}};
			next.onclick=function(){if(page<totalPages){page++;render()}};
		}else{
			pag.innerHTML='';
		}
	}

	function esc(v){
		var d=document.createElement('div');
		d.appendChild(document.createTextNode(String(v)));
		return d.innerHTML;
	}

	function renderDiffHtml(fields){
		var html='<div class="diff-wrap">';
		for(var i=0;i<fields.length;i++){
			html+='<div class="diff-field"><div class="diff-field-name">'+esc(fields[i].name)+'</div><div>'+fields[i].diff+'</div></div>';
		}
		html+='</div>';
		return html;
	}

	function fetchDiff(postId,btn){
		// Toggle off if already open.
		var diffRow=document.getElementById('diff-'+postId);
		if(diffRow&&diffRow.classList.contains('open')){
			diffRow.classList.remove('open');
			btn.textContent='View Changes';
			return;
		}

		// Use cache if available.
		if(diffCache[postId]){
			showDiff(postId,diffCache[postId],btn);
			return;
		}

		btn.disabled=true;
		btn.classList.add('is-loading');
		btn.textContent='Loading...';

		var url=restUrl+'/migrations/'+termId+'/diff/'+postId;
		fetch(url,{credentials:'same-origin',headers:{'X-WP-Nonce':nonce}})
			.then(function(r){
				if(!r.ok)throw new Error(r.status);
				return r.json();
			})
			.then(function(data){
				diffCache[postId]=data;
				showDiff(postId,data,btn);
			})
			.catch(function(){
				var diffRow=document.getElementById('diff-'+postId);
				if(diffRow){
					diffRow.querySelector('td').innerHTML='<div class="diff-error">Could not load diff. Make sure you are logged in.</div>';
					diffRow.classList.add('open');
				}
				btn.disabled=false;
				btn.classList.remove('is-loading');
				btn.textContent='View Changes';
			});
	}

	function showDiff(postId,data,btn){
		var diffRow=document.getElementById('diff-'+postId);
		if(!diffRow)return;
		// The REST endpoint returns an array of {id,name,diff} directly, or {message:...} on error.
		var fields=Array.isArray(data)?data:(data.fields||[]);
		if(fields.length){
			diffRow.querySelector('td').innerHTML=renderDiffHtml(fields);
		}else{
			diffRow.querySelector('td').innerHTML='<div class="diff-wrap" style="color:#6c6c6c">'+(data.message||'No changes detected.')+'</div>';
		}
		diffRow.classList.add('open');
		btn.disabled=false;
		btn.classList.remove('is-loading');
		btn.textContent='Hide Changes';
	}

	// Delegate click on diff buttons.
	document.getElementById('tbody').addEventListener('click',function(e){
		var btn=e.target.closest('.btn-diff');
		if(!btn)return;
		var postId=btn.getAttribute('data-post-id');
		fetchDiff(postId,btn);
	});

	// Tab clicks.
	document.querySelectorAll('.tab').forEach(function(t){
		t.addEventListener('click',function(){
			document.querySelectorAll('.tab').forEach(function(el){el.classList.remove('active')});
			this.classList.add('active');
			tab=this.getAttribute('data-filter');
			page=1;
			render();
		});
	});

	// Search.
	document.getElementById('search').addEventListener('input',function(){
		var val=this.value;
		clearTimeout(debounceTimer);
		debounceTimer=setTimeout(function(){
			query=val;
			page=1;
			render();
		},200);
	});

	// Print: build full table on beforeprint.
	var printBuilt=false;
	window.addEventListener('beforeprint',function(){
		if(printBuilt)return;
		printBuilt=true;
		var html='';
		for(var i=0;i<posts.length;i++){html+=renderPrintRow(posts[i])}
		document.getElementById('print-tbody').innerHTML=html;
	});

	render();
})();
</script>
</body>
</html>
		<?php
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
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
			if ( 'no_pre_migration_revision' === $compare_from->get_error_code() ) {
				$compare_from = 0;
			} else {
				return new WP_REST_Response( [ 'message' => $compare_from->get_error_message() ], 400 );
			}
		}

		// Find compare_to (last migration revision — captures all changes in this migration).
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
