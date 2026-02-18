<?php
/**
 * NRE_Migration_Rollback — Rollback logic for migration revisions.
 *
 * Finds the pre-migration revision and restores it, including taxonomy
 * and post type state captured by NRE's revision hooks.
 *
 * @package Newspack_Revisions_Enhanced
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rollback logic for migration revisions.
 */
class NRE_Migration_Rollback {

	/**
	 * Roll back a single post to its pre-migration state.
	 *
	 * @param int    $post_id        The post ID to roll back.
	 * @param string $migration_name The migration name to match.
	 * @param int    $migration_ts   The migration timestamp to match.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function rollback_post( $post_id, $migration_name, $migration_ts ) {
		$pre_revision_id = $this->find_pre_migration_revision( $post_id, $migration_name, $migration_ts );

		if ( is_wp_error( $pre_revision_id ) ) {
			return $pre_revision_id;
		}

		return $this->execute_rollback( $post_id, $pre_revision_id );
	}

	/**
	 * Roll back all rollbackable posts for a migration.
	 *
	 * @param string $migration_name The migration name.
	 * @param int    $migration_ts   The migration timestamp.
	 * @param int    $term_id        The migration term ID.
	 * @return array Summary of rollback results.
	 */
	public function rollback_migration( $migration_name, $migration_ts, $term_id ) {
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

		$rolled_back = 0;
		$skipped     = 0;
		$errors      = [];

		foreach ( $post_ids as $post_id ) {
			$pre_revision_id = $this->find_pre_migration_revision( $post_id, $migration_name, $migration_ts );

			if ( is_wp_error( $pre_revision_id ) ) {
				++$skipped;
				continue;
			}

			$result = $this->execute_rollback( $post_id, $pre_revision_id );

			if ( is_wp_error( $result ) ) {
				$errors[] = [
					'post_id' => $post_id,
					'message' => $result->get_error_message(),
				];
			} else {
				++$rolled_back;
			}
		}

		return [
			'rolled_back' => $rolled_back,
			'skipped'     => $skipped,
			'errors'      => $errors,
			'total'       => count( $post_ids ),
		];
	}

	/**
	 * Roll back a batch of post IDs.
	 *
	 * Processes a slice of posts and returns counts. Does not manage
	 * migration context or cache invalidation — the caller is responsible.
	 *
	 * @param int[]  $post_ids        Array of post IDs to process.
	 * @param string $migration_name  The migration name to match.
	 * @param int    $migration_ts    The migration timestamp to match.
	 * @return array { rolled_back: int, skipped: int, errors: array }
	 */
	public function rollback_batch( $post_ids, $migration_name, $migration_ts ) {
		$rolled_back = 0;
		$skipped     = 0;
		$errors      = [];

		foreach ( $post_ids as $post_id ) {
			$pre_revision_id = $this->find_pre_migration_revision( $post_id, $migration_name, $migration_ts );

			if ( is_wp_error( $pre_revision_id ) ) {
				++$skipped;
				continue;
			}

			$result = $this->execute_rollback( $post_id, $pre_revision_id );

			if ( is_wp_error( $result ) ) {
				$errors[] = [
					'post_id' => $post_id,
					'message' => $result->get_error_message(),
				];
			} else {
				++$rolled_back;
			}
		}

		return [
			'rolled_back' => $rolled_back,
			'skipped'     => $skipped,
			'errors'      => $errors,
		];
	}

	/**
	 * Find the revision immediately before the first migration revision.
	 *
	 * @param int    $post_id        The post ID.
	 * @param string $migration_name The migration name to match.
	 * @param int    $migration_ts   The migration timestamp to match.
	 * @return int|WP_Error Pre-migration revision ID or WP_Error.
	 */
	public function find_pre_migration_revision( $post_id, $migration_name, $migration_ts ) {
		$revisions = wp_get_post_revisions(
			$post_id,
			[
				'order'   => 'ASC',
				'orderby' => 'date ID',
			]
		);

		if ( empty( $revisions ) ) {
			return new WP_Error(
				'no_revisions',
				__( 'No revisions found for this post.', 'newspack-revisions-enhanced' )
			);
		}

		$prev_rev_id = null;

		foreach ( $revisions as $rev ) {
			$rev_name = get_post_meta( $rev->ID, '_nre_migration_name', true );
			$rev_ts   = (int) get_post_meta( $rev->ID, '_nre_migration_ts', true );

			if ( $rev_name === $migration_name && $rev_ts === $migration_ts ) {
				if ( null === $prev_rev_id ) {
					return new WP_Error(
						'no_pre_migration_revision',
						__( 'Post was created during this migration and cannot be rolled back.', 'newspack-revisions-enhanced' )
					);
				}

				return $prev_rev_id;
			}

			$prev_rev_id = $rev->ID;
		}

		return new WP_Error(
			'migration_revisions_not_found',
			__( 'No migration revisions found for this post.', 'newspack-revisions-enhanced' )
		);
	}

	/**
	 * Execute the rollback: restore taxonomy, post type, then core fields.
	 *
	 * Order matters: taxonomy and post type are restored first so that
	 * wp_restore_post_revision() creates a new revision that captures
	 * the already-restored state via NRE's _wp_put_post_revision hooks.
	 *
	 * @param int $post_id         The post ID to restore.
	 * @param int $pre_revision_id The revision ID to restore from.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private function execute_rollback( $post_id, $pre_revision_id ) {
		// 1. Restore taxonomies from the pre-migration revision.
		$this->restore_taxonomies( $post_id, $pre_revision_id );

		// 2. Restore post type from the pre-migration revision.
		$this->restore_post_type( $post_id, $pre_revision_id );

		// 3. Restore core fields + registered meta (creates a new revision).
		$result = wp_restore_post_revision( $pre_revision_id );

		if ( ! $result || is_wp_error( $result ) ) {
			return new WP_Error(
				'restore_failed',
				__( 'Failed to restore the post revision.', 'newspack-revisions-enhanced' )
			);
		}

		return true;
	}

	/**
	 * Restore taxonomy term assignments from a revision's snapshot.
	 *
	 * @param int $post_id     The post ID.
	 * @param int $revision_id The revision ID with the taxonomy snapshot.
	 */
	private function restore_taxonomies( $post_id, $revision_id ) {
		$post_type  = get_post_type( $post_id );
		$taxonomies = get_object_taxonomies( $post_type );

		// Exclude the migration taxonomy itself.
		$taxonomies = array_diff( $taxonomies, [ NRE_Migration_Context::TAXONOMY ] );

		foreach ( $taxonomies as $taxonomy ) {
			$meta_key = NRE_TAX_META_PREFIX . $taxonomy;
			$term_ids = get_post_meta( $revision_id, $meta_key, true );

			if ( is_array( $term_ids ) ) {
				$term_ids = array_map( 'intval', $term_ids );
				wp_set_object_terms( $post_id, $term_ids, $taxonomy );
			}
		}
	}

	/**
	 * Restore the post type from a revision's snapshot.
	 *
	 * @param int $post_id     The post ID.
	 * @param int $revision_id The revision ID with the post type snapshot.
	 */
	private function restore_post_type( $post_id, $revision_id ) {
		$stored_type = get_post_meta( $revision_id, '_nre_post_type', true );

		if ( $stored_type && get_post_type( $post_id ) !== $stored_type ) {
			set_post_type( $post_id, $stored_type );
		}
	}
}
