<?php
/**
 * NRE_Taxonomy_Revisions — Snapshot taxonomy term assignments into revision meta.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NRE_Taxonomy_Revisions {

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_action( '_wp_put_post_revision', [ $this, 'save_taxonomy_snapshot' ], 20, 2 );
		add_filter( 'wp_save_post_revision_post_has_changed', [ $this, 'check_taxonomy_has_changed' ], 20, 3 );
	}

	/**
	 * Get taxonomies to track for a post type.
	 *
	 * @param string $post_type The post type.
	 * @return string[] Taxonomy names.
	 */
	public function get_tracked_taxonomies( $post_type ) {
		$taxonomies = get_object_taxonomies( $post_type );

		// Exclude the plugin's own migration taxonomy from revision tracking.
		$taxonomies = array_diff( $taxonomies, [ NRE_Migration_Context::TAXONOMY ] );

		/**
		 * Filter which taxonomies are tracked in revisions.
		 *
		 * @param string[] $taxonomies Taxonomy names.
		 * @param string   $post_type  The post type.
		 */
		return apply_filters( 'nre_tracked_taxonomies', $taxonomies, $post_type );
	}

	/**
	 * Save taxonomy term assignments as revision meta.
	 *
	 * Fires on `_wp_put_post_revision`.
	 *
	 * @param int $revision_id The revision ID.
	 * @param int $post_id     The parent post ID. May be 0 in older WP versions.
	 */
	public function save_taxonomy_snapshot( $revision_id, $post_id = 0 ) {
		// If $post_id wasn't passed, get it from the revision.
		if ( ! $post_id ) {
			$post_id = wp_get_post_parent_id( $revision_id );
		}

		if ( ! $post_id ) {
			return;
		}

		$post_type  = get_post_type( $post_id );
		$taxonomies = $this->get_tracked_taxonomies( $post_type );

		foreach ( $taxonomies as $taxonomy ) {
			$term_ids = $this->get_live_term_ids( $post_id, $taxonomy );
			update_metadata( 'post', $revision_id, NRE_TAX_META_PREFIX . $taxonomy, $term_ids );
		}
	}

	/**
	 * Check if any tracked taxonomy has changed since the last revision.
	 *
	 * Fires on `wp_save_post_revision_post_has_changed`.
	 *
	 * @param bool    $post_has_changed Whether the post has changed.
	 * @param WP_Post $last_revision    The last revision post object.
	 * @param WP_Post $post             The current post object.
	 * @return bool True if changed.
	 */
	public function check_taxonomy_has_changed( $post_has_changed, $last_revision, $post ) {
		// If core already detected a change, no need to check further.
		if ( $post_has_changed ) {
			return true;
		}

		$post_type  = get_post_type( $post );
		$taxonomies = $this->get_tracked_taxonomies( $post_type );

		foreach ( $taxonomies as $taxonomy ) {
			$current_ids = $this->get_live_term_ids( $post->ID, $taxonomy );
			$stored_ids  = get_post_meta( $last_revision->ID, NRE_TAX_META_PREFIX . $taxonomy, true );

			if ( ! is_array( $stored_ids ) ) {
				$stored_ids = [];
			}

			if ( $current_ids !== $stored_ids ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get sorted term IDs for a post and taxonomy from live data.
	 *
	 * @param int    $post_id  The post ID.
	 * @param string $taxonomy The taxonomy name.
	 * @return int[] Sorted term IDs.
	 */
	public function get_live_term_ids( $post_id, $taxonomy ) {
		$terms = wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );

		if ( is_wp_error( $terms ) ) {
			return [];
		}

		$terms = array_map( 'intval', $terms );
		sort( $terms, SORT_NUMERIC );

		return $terms;
	}
}
