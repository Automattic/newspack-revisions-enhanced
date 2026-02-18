<?php
/**
 * NRE_Migration_Context — Static API for tagging revisions during data migrations.
 *
 * Usage:
 *   NRE_Migration_Context::start( 'Batch import 2024-Q3 articles' );
 *   // ... wp_update_post() calls ...
 *   NRE_Migration_Context::stop();
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

		register_taxonomy( self::TAXONOMY, $post_types, [
			'labels'            => [
				'name'                       => _x( 'Migrations', 'taxonomy general name', 'newspack-revisions-enhanced' ),
				'singular_name'              => _x( 'Migration', 'taxonomy singular name', 'newspack-revisions-enhanced' ),
				'search_items'               => __( 'Search Migrations', 'newspack-revisions-enhanced' ),
				'all_items'                   => __( 'All Migrations', 'newspack-revisions-enhanced' ),
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
		] );
	}

	/**
	 * Start a migration context. All revisions created after this call
	 * (until ::stop()) will be tagged with the given name and a timestamp.
	 *
	 * @param string $name Human-readable migration name.
	 */
	public static function start( $name ) {
		self::$name      = $name;
		self::$timestamp = time();
		self::$term_id   = null;

		add_action( '_wp_put_post_revision', [ __CLASS__, 'save_migration_meta' ], 5, 2 );
	}

	/**
	 * Stop the migration context. Clears the name/timestamp and removes the hook.
	 */
	public static function stop() {
		self::$name      = null;
		self::$timestamp = null;
		self::$term_id   = null;

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

		$result = wp_insert_term( self::$name, self::TAXONOMY, [
			'slug' => $slug,
		] );

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
			$term_id = self::get_or_create_term();
			if ( $term_id ) {
				wp_set_object_terms( $post_id, [ $term_id ], self::TAXONOMY, true );
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
		$sibling_revisions = wp_get_post_revisions( $parent_id, [
			'order' => 'ASC',
		] );

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
