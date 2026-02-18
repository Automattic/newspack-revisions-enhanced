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
	 * Add show_in_rest meta keys to revision tracking.
	 *
	 * Keys that already have revisions_enabled => true are handled by core,
	 * so we skip those to avoid duplicates. Internal keys like _thumbnail_id
	 * are always included.
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
		 * Filter whether to auto-detect REST meta keys for revision tracking.
		 *
		 * @param bool   $auto_detect Whether to auto-detect. Default true.
		 * @param string $post_type   The post type.
		 */
		$auto_detect = apply_filters( 'nre_auto_detect_rest_meta', true, $post_type );

		if ( $auto_detect ) {
			$registered = get_registered_meta_keys( 'post', $post_type );

			foreach ( $registered as $meta_key => $args ) {
				// Only add keys that have show_in_rest but don't already have revisions_enabled.
				if ( ! empty( $args['show_in_rest'] ) && empty( $args['revisions_enabled'] ) && ! in_array( $meta_key, $keys, true ) ) {
					$keys[] = $meta_key;
				}
			}

			// Also check meta registered for all object types (empty subtype).
			$global_registered = get_registered_meta_keys( 'post' );
			foreach ( $global_registered as $meta_key => $args ) {
				if ( ! empty( $args['show_in_rest'] ) && empty( $args['revisions_enabled'] ) && ! in_array( $meta_key, $keys, true ) ) {
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
	 * Built-in labels for well-known WordPress meta keys.
	 *
	 * @var array<string, string>
	 */
	const INTERNAL_LABELS = [
		'_thumbnail_id' => 'Featured Image',
	];

	/**
	 * Get label for a meta key in the revision diff UI.
	 *
	 * Built-in WP keys use a human-readable label. Keys registered via
	 * register_meta() with a label use that. Everything else shows the
	 * raw meta key so custom keys are immediately identifiable.
	 *
	 * @param string $meta_key  The meta key.
	 * @param string $post_type The post type.
	 * @return string Label for display.
	 */
	public function get_meta_label( $meta_key, $post_type = 'post' ) {
		// Check built-in labels for well-known WP keys.
		if ( isset( self::INTERNAL_LABELS[ $meta_key ] ) ) {
			return apply_filters( 'nre_meta_label', self::INTERNAL_LABELS[ $meta_key ], $meta_key, $post_type );
		}

		// Try to get the label from registered meta args.
		$registered = get_registered_meta_keys( 'post', $post_type );
		if ( isset( $registered[ $meta_key ]['label'] ) && '' !== $registered[ $meta_key ]['label'] ) {
			return apply_filters( 'nre_meta_label', $registered[ $meta_key ]['label'], $meta_key, $post_type );
		}

		// Fallback: check globally registered meta.
		$global_registered = get_registered_meta_keys( 'post' );
		if ( isset( $global_registered[ $meta_key ]['label'] ) && '' !== $global_registered[ $meta_key ]['label'] ) {
			return apply_filters( 'nre_meta_label', $global_registered[ $meta_key ]['label'], $meta_key, $post_type );
		}

		// Fallback: raw meta key — no prettification.
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
