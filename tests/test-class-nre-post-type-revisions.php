<?php
/**
 * Tests for NRE_Post_Type_Revisions.
 *
 * @package Newspack_Revisions_Enhanced
 */

class Test_NRE_Post_Type_Revisions extends WP_UnitTestCase {

	/**
	 * @var NRE_Post_Type_Revisions
	 */
	private $post_type_revisions;

	public function set_up() {
		parent::set_up();
		$this->post_type_revisions = new NRE_Post_Type_Revisions();
	}

	public function test_save_post_type_snapshot() {
		$post_id = $this->factory->post->create();
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Revision for snapshot test',
			]
		);

		$revisions = wp_get_post_revisions( $post_id );
		$revision  = reset( $revisions );

		$this->post_type_revisions->save_post_type_snapshot( $revision->ID, $post_id );

		$stored = get_post_meta( $revision->ID, NRE_Post_Type_Revisions::META_KEY, true );
		$this->assertSame( 'post', $stored );
	}

	public function test_save_post_type_snapshot_fallback_when_post_id_zero() {
		$post_id = $this->factory->post->create();
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Revision for fallback test',
			]
		);

		$revisions = wp_get_post_revisions( $post_id );
		$revision  = reset( $revisions );

		$this->post_type_revisions->save_post_type_snapshot( $revision->ID, 0 );

		$stored = get_post_meta( $revision->ID, NRE_Post_Type_Revisions::META_KEY, true );
		$this->assertSame( 'post', $stored );
	}

	public function test_save_post_type_snapshot_no_op_when_no_parent() {
		// Use a fake revision ID that has no parent.
		$orphan_id = $this->factory->post->create( [ 'post_type' => 'revision' ] );
		// Override post_parent to 0.
		wp_update_post(
			[
				'ID'          => $orphan_id,
				'post_parent' => 0,
			]
		);

		$this->post_type_revisions->save_post_type_snapshot( $orphan_id, 0 );

		$stored = get_post_meta( $orphan_id, NRE_Post_Type_Revisions::META_KEY, true );
		$this->assertSame( '', $stored );
	}

	public function test_check_post_type_same_returns_false() {
		$post_id = $this->factory->post->create();
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Content for same type test',
			]
		);

		$revisions     = wp_get_post_revisions( $post_id );
		$last_revision = reset( $revisions );

		update_metadata( 'post', $last_revision->ID, NRE_Post_Type_Revisions::META_KEY, 'post' );

		$post   = get_post( $post_id );
		$result = $this->post_type_revisions->check_post_type_has_changed( false, $last_revision, $post );
		$this->assertFalse( $result );
	}

	public function test_check_post_type_different_returns_true() {
		$post_id = $this->factory->post->create();
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Content for different type test',
			]
		);

		$revisions     = wp_get_post_revisions( $post_id );
		$last_revision = reset( $revisions );

		// Snapshot says 'page', but post is actually 'post'.
		update_metadata( 'post', $last_revision->ID, NRE_Post_Type_Revisions::META_KEY, 'page' );

		$post   = get_post( $post_id );
		$result = $this->post_type_revisions->check_post_type_has_changed( false, $last_revision, $post );
		$this->assertTrue( $result );
	}

	public function test_check_post_type_no_snapshot_returns_false() {
		$post_id = $this->factory->post->create();
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Content for no snapshot test',
			]
		);

		$revisions     = wp_get_post_revisions( $post_id );
		$last_revision = reset( $revisions );

		$post   = get_post( $post_id );
		$result = $this->post_type_revisions->check_post_type_has_changed( false, $last_revision, $post );
		$this->assertFalse( $result );
	}

	public function test_check_post_type_passthrough_when_already_true() {
		$post_id = $this->factory->post->create();
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Content for passthrough test',
			]
		);

		$revisions     = wp_get_post_revisions( $post_id );
		$last_revision = reset( $revisions );
		$post          = get_post( $post_id );

		$result = $this->post_type_revisions->check_post_type_has_changed( true, $last_revision, $post );
		$this->assertTrue( $result );
	}

	public function test_get_post_type_label_post() {
		$label = $this->post_type_revisions->get_post_type_label( 'post' );
		$this->assertSame( 'Post', $label );
	}

	public function test_get_post_type_label_page() {
		$label = $this->post_type_revisions->get_post_type_label( 'page' );
		$this->assertSame( 'Page', $label );
	}

	public function test_get_post_type_label_unknown_fallback() {
		$label = $this->post_type_revisions->get_post_type_label( 'nonexistent_type' );
		$this->assertSame( 'nonexistent_type', $label );
	}
}
