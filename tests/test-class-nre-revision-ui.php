<?php
/**
 * Tests for NRE_Revision_UI.
 *
 * @package Newspack_Revisions_Enhanced
 */

class Test_NRE_Revision_UI extends WP_UnitTestCase {

	/**
	 * @var NRE_Revision_UI
	 */
	private $revision_ui;

	/**
	 * @var NRE_Meta_Revisions
	 */
	private $meta_revisions;

	/**
	 * @var NRE_Taxonomy_Revisions
	 */
	private $taxonomy_revisions;

	/**
	 * @var NRE_Post_Type_Revisions
	 */
	private $post_type_revisions;

	public function set_up() {
		parent::set_up();
		$this->meta_revisions      = new NRE_Meta_Revisions();
		$this->taxonomy_revisions  = new NRE_Taxonomy_Revisions();
		$this->post_type_revisions = new NRE_Post_Type_Revisions();
		$this->revision_ui         = new NRE_Revision_UI(
			$this->meta_revisions,
			$this->taxonomy_revisions,
			$this->post_type_revisions
		);
	}

	/**
	 * Helper: create a post with two revisions and return them.
	 */
	private function create_post_with_revisions() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create( [ 'post_content' => 'Original content' ] );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'First update',
			]
		);

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Second update',
			]
		);

		$revisions = wp_get_post_revisions( $post_id, [ 'order' => 'ASC' ] );
		$revisions = array_values( $revisions );

		return [
			'post_id'   => $post_id,
			'revisions' => $revisions,
		];
	}

	// --- Featured Image Diff ---

	public function test_featured_image_diff_added_when_changed() {
		$data     = $this->create_post_with_revisions();
		$rev_from = $data['revisions'][0];
		$rev_to   = $data['revisions'][1];

		// Set different thumbnail IDs on the revisions.
		update_metadata( 'post', $rev_from->ID, '_thumbnail_id', 10 );
		update_metadata( 'post', $rev_to->ID, '_thumbnail_id', 20 );

		$result = $this->revision_ui->add_featured_image_diff_row( [], $rev_from, $rev_to );
		$this->assertCount( 1, $result );
		$this->assertSame( 'featured-image', $result[0]['id'] );
	}

	public function test_featured_image_diff_skipped_when_same() {
		$data     = $this->create_post_with_revisions();
		$rev_from = $data['revisions'][0];
		$rev_to   = $data['revisions'][1];

		update_metadata( 'post', $rev_from->ID, '_thumbnail_id', 10 );
		update_metadata( 'post', $rev_to->ID, '_thumbnail_id', 10 );

		$result = $this->revision_ui->add_featured_image_diff_row( [], $rev_from, $rev_to );
		$this->assertEmpty( $result );
	}

	public function test_featured_image_diff_first_revision() {
		$data   = $this->create_post_with_revisions();
		$rev_to = $data['revisions'][0];

		update_metadata( 'post', $rev_to->ID, '_thumbnail_id', 10 );

		// compare_from = false simulates the first revision.
		$result = $this->revision_ui->add_featured_image_diff_row( [], false, $rev_to );
		$this->assertCount( 1, $result );
		$this->assertSame( 'featured-image', $result[0]['id'] );
	}

	// --- Post Type Diff ---

	public function test_post_type_diff_added_when_changed() {
		$data     = $this->create_post_with_revisions();
		$rev_from = $data['revisions'][0];
		$rev_to   = $data['revisions'][1];

		update_metadata( 'post', $rev_from->ID, NRE_Post_Type_Revisions::META_KEY, 'post' );
		update_metadata( 'post', $rev_to->ID, NRE_Post_Type_Revisions::META_KEY, 'page' );

		$result = $this->revision_ui->add_post_type_diff_row( [], $rev_from, $rev_to );

		$found = false;
		foreach ( $result as $row ) {
			if ( 'nre-post-type' === $row['id'] ) {
				$found = true;
			}
		}
		$this->assertTrue( $found );
	}

	public function test_post_type_diff_skipped_when_same() {
		$data     = $this->create_post_with_revisions();
		$rev_from = $data['revisions'][0];
		$rev_to   = $data['revisions'][1];

		update_metadata( 'post', $rev_from->ID, NRE_Post_Type_Revisions::META_KEY, 'post' );
		update_metadata( 'post', $rev_to->ID, NRE_Post_Type_Revisions::META_KEY, 'post' );

		$result = $this->revision_ui->add_post_type_diff_row( [], $rev_from, $rev_to );
		$this->assertEmpty( $result );
	}

	public function test_post_type_diff_skipped_when_no_snapshots() {
		$data     = $this->create_post_with_revisions();
		$rev_from = $data['revisions'][0];
		$rev_to   = $data['revisions'][1];

		// No snapshot meta stored at all.
		$result = $this->revision_ui->add_post_type_diff_row( [], $rev_from, $rev_to );
		$this->assertEmpty( $result );
	}

	// --- Meta Diff ---

	public function test_meta_diff_detects_changes() {
		$data     = $this->create_post_with_revisions();
		$rev_from = $data['revisions'][0];
		$rev_to   = $data['revisions'][1];

		register_post_meta(
			'post',
			'test_meta_key',
			[
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
			]
		);

		// Remove all existing wp_post_revision_meta_keys filters so only our instance runs.
		remove_all_filters( 'wp_post_revision_meta_keys' );
		$this->meta_revisions->register_hooks();

		update_metadata( 'post', $rev_from->ID, 'test_meta_key', 'old value' );
		update_metadata( 'post', $rev_to->ID, 'test_meta_key', 'new value' );

		$result = $this->revision_ui->add_meta_diff_rows( [], $rev_from, $rev_to );

		$found = false;
		foreach ( $result as $row ) {
			if ( 'nre-meta-test_meta_key' === $row['id'] ) {
				$found = true;
			}
		}
		$this->assertTrue( $found );

		// Clean up.
		unregister_meta_key( 'post', 'test_meta_key' );
		remove_filter( 'wp_post_revision_meta_keys', [ $this->meta_revisions, 'filter_revision_meta_keys' ], 10 );
	}

	public function test_meta_diff_skips_thumbnail_id() {
		$data     = $this->create_post_with_revisions();
		$rev_from = $data['revisions'][0];
		$rev_to   = $data['revisions'][1];

		$this->meta_revisions->register_hooks();

		update_metadata( 'post', $rev_from->ID, '_thumbnail_id', '10' );
		update_metadata( 'post', $rev_to->ID, '_thumbnail_id', '20' );

		$result = $this->revision_ui->add_meta_diff_rows( [], $rev_from, $rev_to );

		// _thumbnail_id should not appear in meta diff rows (handled by featured image).
		$found = false;
		foreach ( $result as $row ) {
			if ( 'nre-meta-_thumbnail_id' === $row['id'] ) {
				$found = true;
			}
		}
		$this->assertFalse( $found );

		remove_filter( 'wp_post_revision_meta_keys', [ $this->meta_revisions, 'filter_revision_meta_keys' ], 10 );
	}

	public function test_meta_diff_skips_identical() {
		$data     = $this->create_post_with_revisions();
		$rev_from = $data['revisions'][0];
		$rev_to   = $data['revisions'][1];

		register_post_meta(
			'post',
			'test_identical_meta',
			[
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
			]
		);

		$this->meta_revisions->register_hooks();

		update_metadata( 'post', $rev_from->ID, 'test_identical_meta', 'same value' );
		update_metadata( 'post', $rev_to->ID, 'test_identical_meta', 'same value' );

		$result = $this->revision_ui->add_meta_diff_rows( [], $rev_from, $rev_to );

		$found = false;
		foreach ( $result as $row ) {
			if ( 'nre-meta-test_identical_meta' === $row['id'] ) {
				$found = true;
			}
		}
		$this->assertFalse( $found );

		unregister_meta_key( 'post', 'test_identical_meta' );
		remove_filter( 'wp_post_revision_meta_keys', [ $this->meta_revisions, 'filter_revision_meta_keys' ], 10 );
	}

	// --- Taxonomy Diff ---

	public function test_taxonomy_diff_detects_changes() {
		$data     = $this->create_post_with_revisions();
		$rev_from = $data['revisions'][0];
		$rev_to   = $data['revisions'][1];

		$term1 = $this->factory->term->create( [ 'taxonomy' => 'category' ] );
		$term2 = $this->factory->term->create( [ 'taxonomy' => 'category' ] );

		update_metadata( 'post', $rev_from->ID, NRE_TAX_META_PREFIX . 'category', [ $term1 ] );
		update_metadata( 'post', $rev_to->ID, NRE_TAX_META_PREFIX . 'category', [ $term1, $term2 ] );

		$result = $this->revision_ui->add_taxonomy_diff_rows( [], $rev_from, $rev_to );

		$found = false;
		foreach ( $result as $row ) {
			if ( 'nre-tax-category' === $row['id'] ) {
				$found = true;
			}
		}
		$this->assertTrue( $found );
	}

	public function test_taxonomy_diff_skips_identical() {
		$data     = $this->create_post_with_revisions();
		$rev_from = $data['revisions'][0];
		$rev_to   = $data['revisions'][1];

		$term = $this->factory->term->create( [ 'taxonomy' => 'category' ] );

		update_metadata( 'post', $rev_from->ID, NRE_TAX_META_PREFIX . 'category', [ $term ] );
		update_metadata( 'post', $rev_to->ID, NRE_TAX_META_PREFIX . 'category', [ $term ] );

		$result = $this->revision_ui->add_taxonomy_diff_rows( [], $rev_from, $rev_to );

		$found = false;
		foreach ( $result as $row ) {
			if ( 'nre-tax-category' === $row['id'] ) {
				$found = true;
			}
		}
		$this->assertFalse( $found );
	}

	// --- get_meta_display_value ---

	public function test_get_meta_display_value_single() {
		$post_id = $this->factory->post->create();
		update_post_meta( $post_id, 'test_key', 'hello world' );

		$value = $this->revision_ui->get_meta_display_value( $post_id, 'test_key' );
		$this->assertSame( 'hello world', $value );
	}

	public function test_get_meta_display_value_multi() {
		$post_id = $this->factory->post->create();
		add_post_meta( $post_id, 'test_multi', 'value1' );
		add_post_meta( $post_id, 'test_multi', 'value2' );

		$value = $this->revision_ui->get_meta_display_value( $post_id, 'test_multi' );
		$this->assertSame( "value1\nvalue2", $value );
	}

	public function test_get_meta_display_value_array() {
		$post_id = $this->factory->post->create();
		update_post_meta( $post_id, 'test_array', [ 'a' => 1, 'b' => 2 ] );

		$value = $this->revision_ui->get_meta_display_value( $post_id, 'test_array' );
		$decoded = json_decode( $value, true );
		$this->assertSame( [ 'a' => 1, 'b' => 2 ], $decoded );
	}

	public function test_get_meta_display_value_empty() {
		$post_id = $this->factory->post->create();
		$value   = $this->revision_ui->get_meta_display_value( $post_id, 'nonexistent_key' );
		$this->assertSame( '', $value );
	}

	public function test_meta_display_value_filter() {
		$post_id = $this->factory->post->create();
		update_post_meta( $post_id, 'test_filter_key', 'original' );

		$callback = function ( $value, $post_id, $meta_key ) {
			if ( 'test_filter_key' === $meta_key ) {
				return 'filtered';
			}
			return $value;
		};

		add_filter( 'nre_meta_display_value', $callback, 10, 3 );
		$value = $this->revision_ui->get_meta_display_value( $post_id, 'test_filter_key' );
		remove_filter( 'nre_meta_display_value', $callback, 10 );

		$this->assertSame( 'filtered', $value );
	}

	// --- resolve_term_names ---

	public function test_resolve_term_names_valid_terms() {
		$term = $this->factory->term->create( [ 'taxonomy' => 'category', 'name' => 'Test Category' ] );
		$names = $this->revision_ui->resolve_term_names( [ $term ], 'category' );
		$this->assertContains( 'Test Category', $names );
	}

	public function test_resolve_term_names_deleted_terms() {
		$names = $this->revision_ui->resolve_term_names( [ 999999 ], 'category' );
		$this->assertSame( [ '[Deleted term #999999]' ], $names );
	}

	public function test_resolve_term_names_empty_input() {
		$names = $this->revision_ui->resolve_term_names( [], 'category' );
		$this->assertSame( [], $names );
	}

	// --- get_taxonomy_term_ids ---

	public function test_get_taxonomy_term_ids_from_revision_meta() {
		$data     = $this->create_post_with_revisions();
		$revision = $data['revisions'][0];
		$term     = $this->factory->term->create( [ 'taxonomy' => 'category' ] );

		update_metadata( 'post', $revision->ID, NRE_TAX_META_PREFIX . 'category', [ $term ] );

		$ids = $this->revision_ui->get_taxonomy_term_ids( $revision, 'category' );
		$this->assertSame( [ $term ], $ids );
	}

	public function test_get_taxonomy_term_ids_from_live_data() {
		$post_id = $this->factory->post->create();
		$term    = $this->factory->term->create( [ 'taxonomy' => 'category' ] );
		wp_set_object_terms( $post_id, [ $term ], 'category' );

		$post = get_post( $post_id );
		$ids  = $this->revision_ui->get_taxonomy_term_ids( $post, 'category' );
		$this->assertContains( $term, $ids );
	}
}
