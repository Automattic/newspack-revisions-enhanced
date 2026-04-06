<?php
/**
 * NRE_Migration_Context — Static API for tagging revisions during data migrations.
 *
 * Usage:
 *   NRE_Migration_Context::start( 'Batch import 2024-Q3 articles' );
 *   // ... wp_update_post() or NRE_Migration_Context::before_update()/after_update() around raw $wpdb ...
 *   NRE_Migration_Context::stop();
 *
 * @package Newspack_Revisions_Enhanced
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static API for tagging revisions during data migrations.
 */
class NRE_Migration_Context {

	/**
	 * Taxonomy name for migration tracking.
	 */
	const TAXONOMY = 'nre_migration';

	/**
	 * Current migration name, or null if no migration is active.
	 *
	 * @var string|null
	 */
	private static $name = null;

	/**
	 * Unix timestamp when the migration was started, or null.
	 *
	 * @var int|null
	 */
	private static $timestamp = null;

	/**
	 * Cached term ID for the current migration, or null.
	 *
	 * @var int|null
	 */
	private static $term_id = null;

	/**
	 * Whether to use raw/fast $wpdb->insert() for revision creation
	 * instead of wp_save_post_revision() → wp_insert_post().
	 *
	 * @var bool
	 */
	private static $raw_revisions = false;

	/**
	 * Set of parent post IDs that have already been assigned the migration
	 * term during this migration context. Used to skip redundant
	 * wp_set_object_terms() calls in save_migration_meta().
	 *
	 * @var array<int, true>
	 */
	private static $assigned_posts = [];

	/**
	 * Register hooks for cleanup when revisions are deleted.
	 */
	public static function register_hooks() {
		add_action( 'before_delete_post', [ __CLASS__, 'on_revision_delete' ] );
	}

	/**
	 * Register the nre_migration taxonomy.
	 *
	 * Called on `init` so the taxonomy is available everywhere (admin, CLI, REST).
	 */
	public static function register_taxonomy() {
		$post_types = get_post_types( [ 'public' => true ] );

		register_taxonomy(
			self::TAXONOMY,
			$post_types,
			[
				'labels'            => [
					'name'                       => _x( 'Migrations', 'taxonomy general name', 'newspack-revisions-enhanced' ),
					'singular_name'              => _x( 'Migration', 'taxonomy singular name', 'newspack-revisions-enhanced' ),
					'search_items'               => __( 'Search Migrations', 'newspack-revisions-enhanced' ),
					'all_items'                  => __( 'All Migrations', 'newspack-revisions-enhanced' ),
					'edit_item'                  => __( 'Edit Migration', 'newspack-revisions-enhanced' ),
					'view_item'                  => __( 'View Migration', 'newspack-revisions-enhanced' ),
					'update_item'                => __( 'Update Migration', 'newspack-revisions-enhanced' ),
					'add_new_item'               => __( 'Add New Migration', 'newspack-revisions-enhanced' ),
					'new_item_name'              => __( 'New Migration Name', 'newspack-revisions-enhanced' ),
					'separate_items_with_commas' => __( 'Separate migrations with commas', 'newspack-revisions-enhanced' ),
					'add_or_remove_items'        => __( 'Add or remove migrations', 'newspack-revisions-enhanced' ),
					'choose_from_most_used'      => __( 'Choose from the most used migrations', 'newspack-revisions-enhanced' ),
					'not_found'                  => __( 'No migrations found.', 'newspack-revisions-enhanced' ),
					'no_terms'                   => __( 'No migrations', 'newspack-revisions-enhanced' ),
					'menu_name'                  => __( 'Migrations', 'newspack-revisions-enhanced' ),
				],
				'hierarchical'      => false,
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'show_tagcloud'     => false,
				'rewrite'           => false,
			]
		);
	}

	/**
	 * Start a migration context. All revisions created after this call
	 * (until ::stop()) will be tagged with the given name and a timestamp.
	 *
	 * @param string $name    Human-readable migration name.
	 * @param array  $options {
	 *     Optional. Configuration for the migration context.
	 *
	 *     @type bool $raw_revisions When true, before_update() and after_update()
	 *                               use direct $wpdb->insert() instead of
	 *                               wp_save_post_revision() → wp_insert_post().
	 *                               This is significantly faster for bulk operations
	 *                               (~50x) because it bypasses the dozens of hooks
	 *                               fired by wp_insert_post(). Default false.
	 * }
	 */
	public static function start( $name, $options = [] ) {
		self::$name          = $name;
		self::$timestamp     = time();
		self::$term_id       = null;
		self::$raw_revisions = ! empty( $options['raw_revisions'] );

		add_action( '_wp_put_post_revision', [ __CLASS__, 'save_migration_meta' ], 5, 2 );
	}

	/**
	 * Stop the migration context. Clears the name/timestamp and removes the hook.
	 */
	public static function stop() {
		self::$name           = null;
		self::$timestamp      = null;
		self::$term_id        = null;
		self::$raw_revisions  = false;
		self::$assigned_posts = [];

		remove_action( '_wp_put_post_revision', [ __CLASS__, 'save_migration_meta' ], 5 );
	}

	/**
	 * Get the current migration context.
	 *
	 * @return array{name: string, timestamp: int}|null Context array or null if inactive.
	 */
	public static function get_context() {
		if ( null === self::$name ) {
			return null;
		}

		return [
			'name'      => self::$name,
			'timestamp' => self::$timestamp,
		];
	}

	/**
	 * Get or create the taxonomy term for the current migration.
	 *
	 * Uses a slug derived from the name + timestamp so that different runs
	 * of the same migration name produce distinct terms.
	 *
	 * @return int Term ID.
	 */
	private static function get_or_create_term() {
		if ( null !== self::$term_id ) {
			return self::$term_id;
		}

		$slug = sanitize_title( self::$name . '-' . self::$timestamp );

		$term = get_term_by( 'slug', $slug, self::TAXONOMY );

		if ( $term ) {
			self::$term_id = $term->term_id;
			return self::$term_id;
		}

		$result = wp_insert_term(
			self::$name,
			self::TAXONOMY,
			[
				'slug' => $slug,
			]
		);

		if ( is_wp_error( $result ) ) {
			// Term might already exist under a different slug due to sanitization.
			$existing = get_term_by( 'name', self::$name, self::TAXONOMY );
			if ( $existing ) {
				self::$term_id = $existing->term_id;
				return self::$term_id;
			}
			return 0;
		}

		self::$term_id = $result['term_id'];

		// Store the timestamp as term meta for reference.
		update_term_meta( self::$term_id, '_nre_migration_ts', self::$timestamp );

		return self::$term_id;
	}

	/**
	 * Create an untagged baseline revision before the migration modifies a post.
	 *
	 * Suppresses migration tagging so the baseline isn't marked as a migration
	 * revision. Creates a baseline if the post has no revisions, or if the
	 * latest revision doesn't match the current post state (stale revision).
	 *
	 * When raw_revisions is enabled, uses direct $wpdb queries instead of
	 * wp_save_post_revision() for significantly better bulk performance.
	 *
	 * @param int $post_id The post about to be modified.
	 */
	public static function before_update( $post_id ) {
		if ( null === self::$name ) {
			return;
		}

		if ( self::$raw_revisions ) {
			self::before_update_raw( $post_id );
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		$revisions = wp_get_post_revisions( $post_id, [ 'posts_per_page' => 1 ] );
		if ( ! empty( $revisions ) ) {
			// Check whether the latest revision matches the current post state.
			$latest = reset( $revisions );
			if ( self::revision_matches_post( $latest, $post ) ) {
				return;
			}
			// Latest revision is stale — fall through to create a baseline.
		}

		// Suppress tagging so the baseline revision is clean.
		remove_action( '_wp_put_post_revision', [ __CLASS__, 'save_migration_meta' ], 5 );
		wp_save_post_revision( $post_id );
		add_action( '_wp_put_post_revision', [ __CLASS__, 'save_migration_meta' ], 5, 2 );
	}

	/**
	 * Create a tagged migration revision after a raw SQL update.
	 *
	 * Clears the post cache so the revision captures the updated state.
	 * The revision is automatically tagged by save_migration_meta.
	 *
	 * When raw_revisions is enabled, uses direct $wpdb queries instead of
	 * wp_save_post_revision() for significantly better bulk performance.
	 *
	 * @param int $post_id The post that was just modified.
	 */
	public static function after_update( $post_id ) {
		if ( null === self::$name ) {
			return;
		}

		if ( self::$raw_revisions ) {
			self::after_update_raw( $post_id );
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		clean_post_cache( $post_id );
		$revision_id = wp_save_post_revision( $post_id );

		// Fix revision dates to current time so it sorts as the newest in the UI.
		// wp_save_post_revision() copies the parent's dates, which places the
		// migration revision at the wrong chronological position when the parent
		// was published long ago.
		if ( $revision_id && ! is_wp_error( $revision_id ) ) {
			global $wpdb;
			$now     = current_time( 'mysql' );
			$now_gmt = current_time( 'mysql', true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->posts,
				[
					'post_date'         => $now,
					'post_date_gmt'     => $now_gmt,
					'post_modified'     => $now,
					'post_modified_gmt' => $now_gmt,
				],
				[ 'ID' => $revision_id ]
			);
			clean_post_cache( $revision_id );
		}
	}

	/**
	 * Check whether a revision's tracked fields match the current post state.
	 *
	 * Compares post_content, post_title, and post_excerpt — the core fields
	 * that NRE tracks in revisions.
	 *
	 * @param WP_Post $revision The revision to compare.
	 * @param WP_Post $post     The current post.
	 * @return bool True if all tracked fields match.
	 */
	private static function revision_matches_post( $revision, $post ) {
		return $revision->post_content === $post->post_content
			&& $revision->post_title === $post->post_title
			&& $revision->post_excerpt === $post->post_excerpt;
	}

	/**
	 * Raw/fast implementation of before_update().
	 *
	 * Uses direct $wpdb queries to read the post and check whether the latest
	 * revision matches the current post state. Creates a baseline if no
	 * revisions exist or if the latest revision is stale. Inserts the baseline
	 * with $wpdb->insert() instead of wp_insert_post(). Fires
	 * `_wp_put_post_revision` so that NRE snapshot hooks (meta, taxonomy,
	 * post type) still run.
	 *
	 * @param int $post_id The post about to be modified.
	 */
	private static function before_update_raw( $post_id ) {
		global $wpdb;

		// Read post directly from DB, bypassing object cache and filters.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->posts} WHERE ID = %d",
				$post_id
			)
		);

		if ( ! $post || 'revision' === $post->post_type ) {
			return;
		}

		// Fetch the latest revision to check whether it matches the current post.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$latest_revision = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT post_content, post_title, post_excerpt FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'revision' ORDER BY ID DESC LIMIT 1",
				$post_id
			)
		);

		if ( $latest_revision
			&& $latest_revision->post_content === $post->post_content
			&& $latest_revision->post_title === $post->post_title
			&& $latest_revision->post_excerpt === $post->post_excerpt
		) {
			return;
		}

		// Suppress tagging so the baseline revision is clean.
		remove_action( '_wp_put_post_revision', [ __CLASS__, 'save_migration_meta' ], 5 );

		$revision_id = self::raw_insert_revision( $post );
		if ( $revision_id ) {
			// Fix baseline date to current time so it sorts after existing
			// revisions but before the after_update migration revision.
			$now     = current_time( 'mysql' );
			$now_gmt = current_time( 'mysql', true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->posts,
				[
					'post_date'         => $now,
					'post_date_gmt'     => $now_gmt,
					'post_modified'     => $now,
					'post_modified_gmt' => $now_gmt,
				],
				[ 'ID' => $revision_id ]
			);

			self::suspend_core_meta_hook();

			/**
			 * Fires after a revision is inserted via the raw/fast path.
			 *
			 * NRE hooks (taxonomy snapshot, post type snapshot) listen on
			 * this action.
			 */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentionally firing WP core hook.
			do_action( '_wp_put_post_revision', $revision_id, (int) $post->ID );

			self::restore_core_meta_hook();
		}

		add_action( '_wp_put_post_revision', [ __CLASS__, 'save_migration_meta' ], 5, 2 );
	}

	/**
	 * Raw/fast implementation of after_update().
	 *
	 * Clears the post cache, reads the post directly from the database,
	 * and inserts the revision with $wpdb->insert(). Fires
	 * `_wp_put_post_revision` so that NRE hooks (migration meta, taxonomy
	 * snapshot, post type snapshot) still run on the new revision.
	 *
	 * @param int $post_id The post that was just modified.
	 */
	private static function after_update_raw( $post_id ) {
		global $wpdb;

		// Clear cache so subsequent meta reads (by _wp_put_post_revision
		// handlers) reflect any raw SQL changes the consumer made.
		clean_post_cache( $post_id );

		// Read post directly from DB to get the updated state.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->posts} WHERE ID = %d",
				$post_id
			)
		);

		if ( ! $post || 'revision' === $post->post_type ) {
			return;
		}

		$revision_id = self::raw_insert_revision( $post );
		if ( $revision_id ) {
			// Fix revision dates to current time so it sorts as the newest in the UI.
			$now     = current_time( 'mysql' );
			$now_gmt = current_time( 'mysql', true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->posts,
				[
					'post_date'         => $now,
					'post_date_gmt'     => $now_gmt,
					'post_modified'     => $now,
					'post_modified_gmt' => $now_gmt,
				],
				[ 'ID' => $revision_id ]
			);

			self::suspend_core_meta_hook();

			/**
			 * Fires after a revision is inserted via the raw/fast path.
			 *
			 * NRE hooks (migration meta, taxonomy snapshot, post type snapshot)
			 * listen on this action. save_migration_meta() tags the revision here.
			 */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentionally firing WP core hook.
			do_action( '_wp_put_post_revision', $revision_id, (int) $post->ID );

			self::restore_core_meta_hook();
		}
	}

	/**
	 * Temporarily unhook core's wp_save_revisioned_meta_fields().
	 *
	 * The raw insert already captured all post fields, and the caller is
	 * modifying post_content via raw SQL, not meta. Removing the core
	 * handler avoids per-field update_metadata() calls that dominate the
	 * hook cost in bulk operations.
	 */
	private static function suspend_core_meta_hook() {
		remove_action( '_wp_put_post_revision', 'wp_save_revisioned_meta_fields' );
	}

	/**
	 * Restore core's wp_save_revisioned_meta_fields() after a raw do_action.
	 *
	 * WordPress core registers this at priority 10 with 2 accepted args.
	 */
	private static function restore_core_meta_hook() {
		add_action( '_wp_put_post_revision', 'wp_save_revisioned_meta_fields', 10, 2 );
	}

	/**
	 * Insert a revision row directly into wp_posts via $wpdb->insert().
	 *
	 * Copies the same fields that WordPress core's _wp_post_revision_data()
	 * uses, but skips wp_insert_post() and its ~30 hook invocations.
	 *
	 * @param object $post The parent post row from $wpdb->get_row().
	 * @return int The new revision ID, or 0 on failure.
	 */
	private static function raw_insert_revision( $post ) {
		global $wpdb;

		$current_user_id = get_current_user_id();

		$data = [
			'post_author'           => $current_user_id ? $current_user_id : $post->post_author,
			'post_date'             => $post->post_date,
			'post_date_gmt'         => $post->post_date_gmt,
			'post_content'          => $post->post_content,
			'post_title'            => $post->post_title,
			'post_excerpt'          => $post->post_excerpt,
			'post_status'           => 'inherit',
			'post_name'             => $post->ID . '-revision-v1',
			'post_modified'         => $post->post_modified,
			'post_modified_gmt'     => $post->post_modified_gmt,
			'post_parent'           => $post->ID,
			'post_type'             => 'revision',
			'post_content_filtered' => $post->post_content_filtered,
			'post_mime_type'        => $post->post_mime_type,
			'comment_count'         => 0,
		];

		$formats = [
			'%d', // post_author.
			'%s', // post_date.
			'%s', // post_date_gmt.
			'%s', // post_content.
			'%s', // post_title.
			'%s', // post_excerpt.
			'%s', // post_status.
			'%s', // post_name.
			'%s', // post_modified.
			'%s', // post_modified_gmt.
			'%d', // post_parent.
			'%s', // post_type.
			'%s', // post_content_filtered.
			'%s', // post_mime_type.
			'%d', // comment_count.
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $wpdb->posts, $data, $formats );

		if ( false === $result ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Hook callback: save migration metadata on a newly created revision
	 * and assign the migration term to the parent post.
	 *
	 * Hooked to `_wp_put_post_revision` at priority 5 (before taxonomy snapshot at 20).
	 *
	 * @param int $revision_id The revision post ID.
	 * @param int $post_id     The parent post ID.
	 */
	public static function save_migration_meta( $revision_id, $post_id = 0 ) {
		if ( null === self::$name ) {
			return;
		}

		// Use update_metadata() directly because update_post_meta() redirects
		// revision writes to the parent post (wp-includes/post.php:2740).
		update_metadata( 'post', $revision_id, '_nre_migration_name', self::$name );
		update_metadata( 'post', $revision_id, '_nre_migration_ts', self::$timestamp );

		// Assign the migration term to the parent post.
		if ( ! $post_id ) {
			$post_id = wp_get_post_parent_id( $revision_id );
		}

		if ( $post_id && taxonomy_exists( self::TAXONOMY ) ) {
			// Skip if we already assigned the term to this post during this
			// migration context. wp_set_object_terms() with append=true is
			// idempotent but still does a SELECT + conditional INSERT per call.
			if ( isset( self::$assigned_posts[ $post_id ] ) ) {
				return;
			}

			$term_id = self::get_or_create_term();
			if ( $term_id ) {
				wp_set_object_terms( $post_id, [ $term_id ], self::TAXONOMY, true );
				self::$assigned_posts[ $post_id ] = true;
			}
		}
	}

	/**
	 * Clean up migration data when a revision is deleted.
	 *
	 * If the deleted revision was the last one linking a post to a migration,
	 * the migration term is removed from the parent post. If the migration
	 * term has no more posts, the term itself is deleted.
	 *
	 * @param int $post_id The post (revision) being deleted.
	 */
	public static function on_revision_delete( $post_id ) {
		$post = get_post( $post_id );

		// Only act on revisions.
		if ( ! $post || 'revision' !== $post->post_type ) {
			return;
		}

		$migration_name = get_metadata( 'post', $post_id, '_nre_migration_name', true );
		$migration_ts   = (int) get_metadata( 'post', $post_id, '_nre_migration_ts', true );

		// Not a migration revision — nothing to clean up.
		if ( ! $migration_name || ! $migration_ts ) {
			return;
		}

		$parent_id = $post->post_parent;
		if ( ! $parent_id ) {
			return;
		}

		// Find the migration term by slug.
		$slug = sanitize_title( $migration_name . '-' . $migration_ts );
		$term = get_term_by( 'slug', $slug, self::TAXONOMY );

		if ( ! $term ) {
			return;
		}

		// Check if the parent post has any other revisions for this migration.
		$sibling_revisions = wp_get_post_revisions(
			$parent_id,
			[
				'order' => 'ASC',
			]
		);

		$has_other = false;
		foreach ( $sibling_revisions as $rev ) {
			if ( $rev->ID === $post_id ) {
				continue; // Skip the one being deleted.
			}
			$rev_name = get_metadata( 'post', $rev->ID, '_nre_migration_name', true );
			$rev_ts   = (int) get_metadata( 'post', $rev->ID, '_nre_migration_ts', true );
			if ( $rev_name === $migration_name && $rev_ts === $migration_ts ) {
				$has_other = true;
				break;
			}
		}

		if ( ! $has_other ) {
			// Remove the migration term from this post.
			wp_remove_object_terms( $parent_id, $term->term_id, self::TAXONOMY );

			// If no posts remain in the term, delete it.
			$fresh_term = get_term( $term->term_id, self::TAXONOMY );
			if ( $fresh_term && ! is_wp_error( $fresh_term ) && 0 === (int) $fresh_term->count ) {
				wp_delete_term( $term->term_id, self::TAXONOMY );
			}
		}
	}
}
