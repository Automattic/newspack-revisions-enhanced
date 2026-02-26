<?php
/**
 * Tests for NRE_Meta_Revisions.
 *
 * @package Newspack_Revisions_Enhanced
 */

class Test_NRE_Meta_Revisions extends WP_UnitTestCase {

	/**
	 * @var NRE_Meta_Revisions
	 */
	private $meta_revisions;

	public function set_up() {
		parent::set_up();
		$this->meta_revisions = new NRE_Meta_Revisions();
	}

	public function test_thumbnail_id_always_included() {
		$keys = $this->meta_revisions->filter_revision_meta_keys( [], 'post' );
		$this->assertContains( '_thumbnail_id', $keys );
	}

	public function test_thumbnail_id_not_duplicated() {
		$keys = $this->meta_revisions->filter_revision_meta_keys( [ '_thumbnail_id' ], 'post' );
		$count = array_count_values( $keys );
		$this->assertSame( 1, $count['_thumbnail_id'] );
	}

	public function test_discovers_meta_keys_from_database() {
		// Create a post with a custom meta key.
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'my_custom_field', 'hello' );

		$keys = $this->meta_revisions->filter_revision_meta_keys( [], 'post' );
		$this->assertContains( 'my_custom_field', $keys );
	}

	public function test_excludes_edit_lock_key() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_edit_lock', '1234567890:1' );

		$keys = $this->meta_revisions->filter_revision_meta_keys( [], 'post' );
		$this->assertNotContains( '_edit_lock', $keys );
	}

	public function test_excludes_edit_last_key() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_edit_last', '1' );

		$keys = $this->meta_revisions->filter_revision_meta_keys( [], 'post' );
		$this->assertNotContains( '_edit_last', $keys );
	}

	public function test_excludes_oembed_prefix() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_oembed_abc123', '<iframe></iframe>' );

		$keys = $this->meta_revisions->filter_revision_meta_keys( [], 'post' );
		$this->assertNotContains( '_oembed_abc123', $keys );
	}

	public function test_excludes_encloseme() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_encloseme', '1' );

		$keys = $this->meta_revisions->filter_revision_meta_keys( [], 'post' );
		$this->assertNotContains( '_encloseme', $keys );
	}

	public function test_excludes_pingme() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_pingme', '1' );

		$keys = $this->meta_revisions->filter_revision_meta_keys( [], 'post' );
		$this->assertNotContains( '_pingme', $keys );
	}

	public function test_excludes_wp_trash_prefix() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_wp_trash_meta_status', 'publish' );

		$keys = $this->meta_revisions->filter_revision_meta_keys( [], 'post' );
		$this->assertNotContains( '_wp_trash_meta_status', $keys );
	}

	public function test_auto_detect_filter_disables_discovery() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'my_custom_field', 'hello' );

		add_filter( 'nre_auto_detect_rest_meta', '__return_false' );
		$keys = $this->meta_revisions->filter_revision_meta_keys( [], 'post' );
		remove_filter( 'nre_auto_detect_rest_meta', '__return_false' );

		$this->assertNotContains( 'my_custom_field', $keys );
	}

	public function test_revision_meta_keys_filter_adds_custom_keys() {
		$callback = function ( $keys ) {
			$keys[] = 'my_custom_key';
			return $keys;
		};

		add_filter( 'nre_revision_meta_keys', $callback );
		$keys = $this->meta_revisions->filter_revision_meta_keys( [], 'post' );
		remove_filter( 'nre_revision_meta_keys', $callback );

		$this->assertContains( 'my_custom_key', $keys );
	}

	public function test_get_meta_label_returns_raw_key() {
		$label = $this->meta_revisions->get_meta_label( '_thumbnail_id', 'post' );
		$this->assertSame( '_thumbnail_id', $label );
	}

	public function test_get_meta_label_returns_raw_key_for_unknown() {
		$label = $this->meta_revisions->get_meta_label( 'some_unknown_key', 'post' );
		$this->assertSame( 'some_unknown_key', $label );
	}

	public function test_meta_label_filter_overrides() {
		$callback = function () {
			return 'Overridden Label';
		};

		add_filter( 'nre_meta_label', $callback );
		$label = $this->meta_revisions->get_meta_label( '_thumbnail_id', 'post' );
		remove_filter( 'nre_meta_label', $callback );

		$this->assertSame( 'Overridden Label', $label );
	}

	public function test_get_tracked_meta_keys_returns_discovered_keys() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'tracked_field', 'value' );

		// Remove all existing filters to isolate our instance.
		remove_all_filters( 'wp_post_revision_meta_keys' );
		$this->meta_revisions->register_hooks();

		$keys = $this->meta_revisions->get_tracked_meta_keys( 'post' );
		$this->assertContains( 'tracked_field', $keys );

		// Clean up.
		remove_filter( 'wp_post_revision_meta_keys', [ $this->meta_revisions, 'filter_revision_meta_keys' ], 10 );
	}

	public function test_does_not_discover_from_trashed_posts() {
		$post_id = self::factory()->post->create( [ 'post_status' => 'trash' ] );
		update_post_meta( $post_id, 'trashed_meta', 'value' );

		$keys = $this->meta_revisions->filter_revision_meta_keys( [], 'post' );
		$this->assertNotContains( 'trashed_meta', $keys );
	}

	public function test_does_not_discover_from_auto_drafts() {
		$post_id = self::factory()->post->create( [ 'post_status' => 'auto-draft' ] );
		update_post_meta( $post_id, 'draft_meta', 'value' );

		$keys = $this->meta_revisions->filter_revision_meta_keys( [], 'post' );
		$this->assertNotContains( 'draft_meta', $keys );
	}
}
