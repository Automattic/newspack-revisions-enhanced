<?php
/**
 * NRE_Meta_Revisions — Auto-detect and register meta keys for revision tracking.
 *
 * @package Newspack_Revisions_Enhanced
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Auto-detect and register meta keys for revision tracking.
 */
class NRE_Meta_Revisions {

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_filter( 'wp_post_revision_meta_keys', [ $this, 'filter_revision_meta_keys' ], 10, 2 );
	}

	/**
	 * Internal meta keys that should always be tracked.
	 *
	 * @var string[]
	 */
	const INTERNAL_KEYS = [ '_thumbnail_id' ];

	/**
	 * Meta key prefixes to exclude from "track all" mode.
	 *
	 * These are internal/system keys that produce noise in diffs.
	 *
	 * @var string[]
	 */
	const EXCLUDED_PREFIXES = [
		'_edit_',
		'_oembed_',
		'_encloseme',
		'_pingme',
		'_wp_old_',
		'_wp_trash_',
		'_wp_attached_',
		'_wp_attachment_',
		'_wp_page_template',
		'_menu_item_',
	];

	/**
	 * Exact meta keys to exclude from "track all" mode.
	 *
	 * @var string[]
	 */
	const EXCLUDED_KEYS = [
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
	];

	/**
	 * Add meta keys to revision tracking.
	 *
	 * By default, discovers all meta keys in use for the post type and
	 * tracks them, minus known internal/noise keys. This ensures migration
	 * scripts that write to arbitrary meta keys are fully captured.
	 *
	 * @param string[] $keys      Meta keys already registered for revision tracking.
	 * @param string   $post_type Post type being revised.
	 * @return string[] Filtered meta keys.
	 */
	public function filter_revision_meta_keys( $keys, $post_type = 'post' ) {
		// Always track internal keys.
		foreach ( self::INTERNAL_KEYS as $internal_key ) {
			if ( ! in_array( $internal_key, $keys, true ) ) {
				$keys[] = $internal_key;
			}
		}

		/**
		 * Filter whether to auto-detect meta keys for revision tracking.
		 *
		 * When true (default), NRE discovers all meta keys in use for the
		 * post type and tracks them. Set to false to only track keys
		 * explicitly added via the nre_revision_meta_keys filter.
		 *
		 * @param bool   $auto_detect Whether to auto-detect. Default true.
		 * @param string $post_type   The post type.
		 */
		$auto_detect = apply_filters( 'nre_auto_detect_rest_meta', true, $post_type );

		if ( $auto_detect ) {
			$discovered = $this->discover_meta_keys( $post_type );
			foreach ( $discovered as $meta_key ) {
				if ( ! in_array( $meta_key, $keys, true ) ) {
					$keys[] = $meta_key;
				}
			}
		}

		/**
		 * Filter the meta keys tracked in revisions by this plugin.
		 *
		 * @param string[] $keys      Meta keys to track.
		 * @param string   $post_type The post type.
		 */
		return apply_filters( 'nre_revision_meta_keys', $keys, $post_type );
	}

	/**
	 * Discover all meta keys in use for a post type, minus excluded noise.
	 *
	 * Queries distinct meta_key values from postmeta for posts of the
	 * given type, then filters out known internal/system prefixes.
	 *
	 * @param string $post_type The post type.
	 * @return string[] Meta keys to track.
	 */
	private function discover_meta_keys( $post_type ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_key
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type = %s
				AND p.post_status NOT IN ('auto-draft', 'trash')
				LIMIT 200",
				$post_type
			)
		);

		if ( empty( $meta_keys ) ) {
			return [];
		}

		return array_values( array_filter( $meta_keys, [ $this, 'is_trackable_key' ] ) );
	}

	/**
	 * Check whether a meta key should be tracked.
	 *
	 * @param string $meta_key The meta key.
	 * @return bool True if trackable, false if excluded.
	 */
	private function is_trackable_key( $meta_key ) {
		if ( in_array( $meta_key, self::EXCLUDED_KEYS, true ) ) {
			return false;
		}

		foreach ( self::EXCLUDED_PREFIXES as $prefix ) {
			if ( 0 === strpos( $meta_key, $prefix ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get label for a meta key in the revision diff UI.
	 *
	 * Returns the raw meta key. Use the nre_meta_label filter to override.
	 *
	 * @param string $meta_key  The meta key.
	 * @param string $post_type The post type.
	 * @return string Label for display.
	 */
	public function get_meta_label( $meta_key, $post_type = 'post' ) {
		return apply_filters( 'nre_meta_label', $meta_key, $meta_key, $post_type );
	}

	/**
	 * Get the meta keys this plugin is tracking for a given post type.
	 *
	 * This re-runs the filter logic to determine which keys we added
	 * (as opposed to keys core already tracks).
	 *
	 * @param string $post_type The post type.
	 * @return string[] Meta keys tracked by this plugin.
	 */
	public function get_tracked_meta_keys( $post_type = 'post' ) {
		// Get what core provides by default (without our filter).
		$has_filter = has_filter( 'wp_post_revision_meta_keys', [ $this, 'filter_revision_meta_keys' ] );

		if ( false !== $has_filter ) {
			remove_filter( 'wp_post_revision_meta_keys', [ $this, 'filter_revision_meta_keys' ], 10 );
		}

		$core_keys = wp_post_revision_meta_keys( $post_type );

		if ( false !== $has_filter ) {
			add_filter( 'wp_post_revision_meta_keys', [ $this, 'filter_revision_meta_keys' ], 10, 2 );
		}

		// Get full list with our filter.
		$all_keys = wp_post_revision_meta_keys( $post_type );

		// Return only keys we added.
		return array_values( array_diff( $all_keys, $core_keys ) );
	}
}
