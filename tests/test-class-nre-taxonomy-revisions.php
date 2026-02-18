<?php
/**
 * Tests for NRE_Taxonomy_Revisions.
 *
 * @package Newspack_Revisions_Enhanced
 */

class Test_NRE_Taxonomy_Revisions extends WP_UnitTestCase {

	/**
	 * @var NRE_Taxonomy_Revisions
	 */
	private $taxonomy_revisions;

	public function set_up() {
		parent::set_up();
		$this->taxonomy_revisions = new NRE_Taxonomy_Revisions();
	}

	public function test_tracked_taxonomies_include_category_and_post_tag() {
		$taxonomies = $this->taxonomy_revisions->get_tracked_taxonomies( 'post' );
		$this->assertContains( 'category', $taxonomies );
		$this->assertContains( 'post_tag', $taxonomies );
	}

	public function test_tracked_taxonomies_exclude_nre_migration() {
		// Ensure the taxonomy is registered.
		NRE_Migration_Context::register_taxonomy();

		$taxonomies = $this->taxonomy_revisions->get_tracked_taxonomies( 'post' );
		$this->assertNotContains( 'nre_migration', $taxonomies );
	}

	public function test_tracked_taxonomies_filter_works() {
		$callback = function ( $taxonomies ) {
			return [ 'category' ];
		};

		add_filter( 'nre_tracked_taxonomies', $callback );
		$taxonomies = $this->taxonomy_revisions->get_tracked_taxonomies( 'post' );
		remove_filter( 'nre_tracked_taxonomies', $callback );

		$this->assertSame( [ 'category' ], $taxonomies );
	}

	public function test_save_taxonomy_snapshot_stores_sorted_term_ids() {
		$post_id = $this->factory->post->create();
		$term1   = $this->factory->term->create( [ 'taxonomy' => 'category' ] );
		$term2   = $this->factory->term->create( [ 'taxonomy' => 'category' ] );
		wp_set_object_terms( $post_id, [ $term2, $term1 ], 'category' );

		// Create a revision.
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Updated content for revision',
			]
		);

		$revisions = wp_get_post_revisions( $post_id );
		$revision  = reset( $revisions );

		$this->taxonomy_revisions->save_taxonomy_snapshot( $revision->ID, $post_id );

		$stored = get_post_meta( $revision->ID, NRE_TAX_META_PREFIX . 'category', true );
		$this->assertIsArray( $stored );

		$expected = [ $term1, $term2 ];
		sort( $expected, SORT_NUMERIC );
		$this->assertSame( $expected, $stored );
	}

	public function test_save_taxonomy_snapshot_fallback_when_post_id_zero() {
		$post_id = $this->factory->post->create();
		$term    = $this->factory->term->create( [ 'taxonomy' => 'category' ] );
		wp_set_object_terms( $post_id, [ $term ], 'category' );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Content for fallback test',
			]
		);

		$revisions = wp_get_post_revisions( $post_id );
		$revision  = reset( $revisions );

		// Call with post_id = 0 to test the fallback.
		$this->taxonomy_revisions->save_taxonomy_snapshot( $revision->ID, 0 );

		$stored = get_post_meta( $revision->ID, NRE_TAX_META_PREFIX . 'category', true );
		$this->assertIsArray( $stored );
		$this->assertContains( $term, $stored );
	}

	public function test_check_taxonomy_has_changed_no_change_returns_false() {
		$post_id = $this->factory->post->create();
		$term    = $this->factory->term->create( [ 'taxonomy' => 'category' ] );
		wp_set_object_terms( $post_id, [ $term ], 'category' );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'First revision',
			]
		);

		$revisions     = wp_get_post_revisions( $post_id );
		$last_revision = reset( $revisions );

		// Save snapshot so comparison works.
		$this->taxonomy_revisions->save_taxonomy_snapshot( $last_revision->ID, $post_id );

		$post = get_post( $post_id );
		$result = $this->taxonomy_revisions->check_taxonomy_has_changed( false, $last_revision, $post );
		$this->assertFalse( $result );
	}

	public function test_check_taxonomy_has_changed_terms_added_returns_true() {
		$post_id = $this->factory->post->create();
		$term1   = $this->factory->term->create( [ 'taxonomy' => 'category' ] );
		wp_set_object_terms( $post_id, [ $term1 ], 'category' );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'First revision',
			]
		);

		$revisions     = wp_get_post_revisions( $post_id );
		$last_revision = reset( $revisions );

		$this->taxonomy_revisions->save_taxonomy_snapshot( $last_revision->ID, $post_id );

		// Add another term.
		$term2 = $this->factory->term->create( [ 'taxonomy' => 'category' ] );
		wp_set_object_terms( $post_id, [ $term1, $term2 ], 'category' );

		$post = get_post( $post_id );
		$result = $this->taxonomy_revisions->check_taxonomy_has_changed( false, $last_revision, $post );
		$this->assertTrue( $result );
	}

	public function test_check_taxonomy_has_changed_terms_removed_returns_true() {
		$post_id = $this->factory->post->create();
		$term1   = $this->factory->term->create( [ 'taxonomy' => 'category' ] );
		$term2   = $this->factory->term->create( [ 'taxonomy' => 'category' ] );
		wp_set_object_terms( $post_id, [ $term1, $term2 ], 'category' );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'First revision',
			]
		);

		$revisions     = wp_get_post_revisions( $post_id );
		$last_revision = reset( $revisions );

		$this->taxonomy_revisions->save_taxonomy_snapshot( $last_revision->ID, $post_id );

		// Remove one term.
		wp_set_object_terms( $post_id, [ $term1 ], 'category' );

		$post = get_post( $post_id );
		$result = $this->taxonomy_revisions->check_taxonomy_has_changed( false, $last_revision, $post );
		$this->assertTrue( $result );
	}

	public function test_check_taxonomy_has_changed_passthrough_when_already_true() {
		$post_id = $this->factory->post->create();

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Revision content',
			]
		);

		$revisions     = wp_get_post_revisions( $post_id );
		$last_revision = reset( $revisions );
		$post          = get_post( $post_id );

		$result = $this->taxonomy_revisions->check_taxonomy_has_changed( true, $last_revision, $post );
		$this->assertTrue( $result );
	}

	public function test_get_live_term_ids_returns_sorted_ints() {
		$post_id = $this->factory->post->create();
		$term1   = $this->factory->term->create( [ 'taxonomy' => 'category' ] );
		$term2   = $this->factory->term->create( [ 'taxonomy' => 'category' ] );
		wp_set_object_terms( $post_id, [ $term2, $term1 ], 'category' );

		$term_ids = $this->taxonomy_revisions->get_live_term_ids( $post_id, 'category' );
		$this->assertSame( $term_ids, array_map( 'intval', $term_ids ) );

		// Verify sorted.
		$sorted = $term_ids;
		sort( $sorted, SORT_NUMERIC );
		$this->assertSame( $sorted, $term_ids );
	}

	public function test_get_live_term_ids_empty_for_invalid_taxonomy() {
		$post_id  = $this->factory->post->create();
		$term_ids = $this->taxonomy_revisions->get_live_term_ids( $post_id, 'nonexistent_taxonomy' );
		$this->assertSame( [], $term_ids );
	}
}
