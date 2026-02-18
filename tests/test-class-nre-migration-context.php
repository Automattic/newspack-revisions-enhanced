<?php
/**
 * Tests for NRE_Migration_Context.
 *
 * @package Newspack_Revisions_Enhanced
 */

class Test_NRE_Migration_Context extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		NRE_Migration_Context::register_taxonomy();
	}

	public function tear_down() {
		NRE_Migration_Context::stop();
		parent::tear_down();
	}

	public function test_taxonomy_registered_correctly() {
		$tax = get_taxonomy( 'nre_migration' );
		$this->assertNotFalse( $tax );
		$this->assertFalse( $tax->public );
		$this->assertTrue( $tax->show_in_rest );
	}

	public function test_start_sets_context() {
		NRE_Migration_Context::start( 'Test migration' );
		$context = NRE_Migration_Context::get_context();

		$this->assertNotNull( $context );
		$this->assertSame( 'Test migration', $context['name'] );
		$this->assertIsInt( $context['timestamp'] );
	}

	public function test_stop_clears_context() {
		NRE_Migration_Context::start( 'Test migration' );
		NRE_Migration_Context::stop();

		$this->assertNull( NRE_Migration_Context::get_context() );
	}

	public function test_get_context_null_when_inactive() {
		$this->assertNull( NRE_Migration_Context::get_context() );
	}

	public function test_save_migration_meta_stores_name_and_ts_on_revision() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create();

		NRE_Migration_Context::start( 'Import batch' );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Migrated content',
			]
		);

		$revisions = wp_get_post_revisions( $post_id );
		$revision  = reset( $revisions );

		$name = get_metadata( 'post', $revision->ID, '_nre_migration_name', true );
		$ts   = get_metadata( 'post', $revision->ID, '_nre_migration_ts', true );

		$this->assertSame( 'Import batch', $name );
		$this->assertNotEmpty( $ts );
	}

	public function test_save_migration_meta_assigns_term_to_parent_post() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create();

		NRE_Migration_Context::start( 'Import batch' );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Migrated content',
			]
		);

		$terms = wp_get_object_terms( $post_id, 'nre_migration', [ 'fields' => 'names' ] );
		$this->assertContains( 'Import batch', $terms );
	}

	public function test_term_created_with_timestamp_slug_and_meta() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create();

		NRE_Migration_Context::start( 'Slug Test' );
		$context = NRE_Migration_Context::get_context();

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Content for slug test',
			]
		);

		$expected_slug = sanitize_title( 'Slug Test-' . $context['timestamp'] );
		$term = get_term_by( 'slug', $expected_slug, 'nre_migration' );

		$this->assertNotFalse( $term );

		$term_ts = get_term_meta( $term->term_id, '_nre_migration_ts', true );
		$this->assertEquals( $context['timestamp'], $term_ts );
	}

	public function test_multiple_posts_share_same_term() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post1 = $this->factory->post->create();
		$post2 = $this->factory->post->create();

		NRE_Migration_Context::start( 'Shared migration' );

		wp_update_post(
			[
				'ID'           => $post1,
				'post_content' => 'Post 1 migrated',
			]
		);
		wp_update_post(
			[
				'ID'           => $post2,
				'post_content' => 'Post 2 migrated',
			]
		);

		$terms1 = wp_get_object_terms( $post1, 'nre_migration', [ 'fields' => 'ids' ] );
		$terms2 = wp_get_object_terms( $post2, 'nre_migration', [ 'fields' => 'ids' ] );

		$this->assertNotEmpty( $terms1 );
		$this->assertSame( $terms1, $terms2 );
	}

	public function test_no_op_when_context_inactive() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create();

		// No start() call — context is inactive.
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Normal update',
			]
		);

		$revisions = wp_get_post_revisions( $post_id );
		$revision  = reset( $revisions );

		if ( $revision ) {
			$name = get_metadata( 'post', $revision->ID, '_nre_migration_name', true );
			$this->assertEmpty( $name );
		}

		$terms = wp_get_object_terms( $post_id, 'nre_migration', [ 'fields' => 'ids' ] );
		$this->assertEmpty( $terms );
	}

	public function test_on_revision_delete_removes_term_when_last_revision() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create();

		NRE_Migration_Context::start( 'Delete test' );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Migration content',
			]
		);

		NRE_Migration_Context::stop();

		$terms_before = wp_get_object_terms( $post_id, 'nre_migration', [ 'fields' => 'ids' ] );
		$this->assertNotEmpty( $terms_before );

		// Get the migration revision.
		$revisions = wp_get_post_revisions( $post_id );
		foreach ( $revisions as $rev ) {
			$name = get_metadata( 'post', $rev->ID, '_nre_migration_name', true );
			if ( $name ) {
				wp_delete_post_revision( $rev->ID );
			}
		}

		$terms_after = wp_get_object_terms( $post_id, 'nre_migration', [ 'fields' => 'ids' ] );
		$this->assertEmpty( $terms_after );
	}

	public function test_on_revision_delete_keeps_term_when_siblings_exist() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create();

		NRE_Migration_Context::start( 'Multi-rev migration' );

		// Create two migration revisions.
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'First migration update',
			]
		);
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Second migration update',
			]
		);

		NRE_Migration_Context::stop();

		// Find and delete only the first migration revision.
		$revisions = wp_get_post_revisions( $post_id, [ 'order' => 'ASC' ] );
		$deleted   = false;
		foreach ( $revisions as $rev ) {
			$name = get_metadata( 'post', $rev->ID, '_nre_migration_name', true );
			if ( $name && ! $deleted ) {
				wp_delete_post_revision( $rev->ID );
				$deleted = true;
				break;
			}
		}

		// Term should still be assigned since a sibling revision exists.
		$terms = wp_get_object_terms( $post_id, 'nre_migration', [ 'fields' => 'ids' ] );
		$this->assertNotEmpty( $terms );
	}

	public function test_on_revision_delete_deletes_empty_term() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create();

		NRE_Migration_Context::start( 'Term cleanup test' );
		$context = NRE_Migration_Context::get_context();

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Migration content for cleanup',
			]
		);

		NRE_Migration_Context::stop();

		$slug = sanitize_title( 'Term cleanup test-' . $context['timestamp'] );
		$term = get_term_by( 'slug', $slug, 'nre_migration' );
		$this->assertNotFalse( $term );
		$term_id = $term->term_id;

		// Delete all migration revisions.
		$revisions = wp_get_post_revisions( $post_id );
		foreach ( $revisions as $rev ) {
			$name = get_metadata( 'post', $rev->ID, '_nre_migration_name', true );
			if ( $name ) {
				wp_delete_post_revision( $rev->ID );
			}
		}

		// Term should be deleted.
		$term_after = get_term( $term_id, 'nre_migration' );
		$this->assertTrue( is_wp_error( $term_after ) || null === $term_after );
	}

	public function test_on_revision_delete_ignores_non_revisions() {
		$post_id = $this->factory->post->create();

		// Should not throw or error.
		NRE_Migration_Context::on_revision_delete( $post_id );

		// If we get here without error, the test passes.
		$this->assertTrue( true );
	}

	public function test_on_revision_delete_ignores_non_migration_revisions() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create();

		// Create a normal revision (no migration context).
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Normal revision',
			]
		);

		$revisions = wp_get_post_revisions( $post_id );
		$revision  = reset( $revisions );

		// Should not throw or error.
		NRE_Migration_Context::on_revision_delete( $revision->ID );
		$this->assertTrue( true );
	}

	public function test_start_stop_lifecycle() {
		$this->assertNull( NRE_Migration_Context::get_context() );

		NRE_Migration_Context::start( 'Lifecycle test' );
		$context = NRE_Migration_Context::get_context();
		$this->assertSame( 'Lifecycle test', $context['name'] );
		$this->assertIsInt( $context['timestamp'] );

		NRE_Migration_Context::stop();
		$this->assertNull( NRE_Migration_Context::get_context() );
	}
}
