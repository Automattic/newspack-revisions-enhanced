<?php
/**
 * NRE_Post_Type_Revisions — Track post type changes in revisions.
 *
 * WordPress revisions always have post_type='revision', so the parent's actual
 * post type is lost. This class snapshots the parent's post_type into revision
 * meta so changes can be diffed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NRE_Post_Type_Revisions {

	/**
	 * Meta key used to store the post type snapshot.
	 */
	const META_KEY = '_nre_post_type';

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_action( '_wp_put_post_revision', [ $this, 'save_post_type_snapshot' ], 10, 2 );
		add_filter( 'wp_save_post_revision_post_has_changed', [ $this, 'check_post_type_has_changed' ], 10, 3 );
	}

	/**
	 * Save the parent post's post_type as revision meta.
	 *
	 * @param int $revision_id The revision ID.
	 * @param int $post_id     The parent post ID.
	 */
	public function save_post_type_snapshot( $revision_id, $post_id = 0 ) {
		if ( ! $post_id ) {
			$post_id = wp_get_post_parent_id( $revision_id );
		}

		if ( ! $post_id ) {
			return;
		}

		$post_type = get_post_type( $post_id );
		update_metadata( 'post', $revision_id, self::META_KEY, $post_type );
	}

	/**
	 * Force a revision when the post type has changed since the last revision.
	 *
	 * @param bool    $post_has_changed Whether the post has changed.
	 * @param WP_Post $last_revision    The last revision post object.
	 * @param WP_Post $post             The current post object.
	 * @return bool
	 */
	public function check_post_type_has_changed( $post_has_changed, $last_revision, $post ) {
		if ( $post_has_changed ) {
			return true;
		}

		$stored = get_post_meta( $last_revision->ID, self::META_KEY, true );

		// If no snapshot exists yet (pre-existing revisions), skip.
		if ( '' === $stored ) {
			return false;
		}

		return $stored !== $post->post_type;
	}

	/**
	 * Get the post type label for display in the diff UI.
	 *
	 * @param string $post_type The post type slug.
	 * @return string Human-readable label.
	 */
	public function get_post_type_label( $post_type ) {
		$type_object = get_post_type_object( $post_type );

		if ( $type_object ) {
			return $type_object->labels->singular_name;
		}

		return $post_type;
	}
}
