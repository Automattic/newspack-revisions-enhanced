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

	public function test_before_update_creates_baseline_revision() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create( [ 'post_content' => 'Original' ] );

		// Delete any auto-created revisions so the post has none.
		$revisions = wp_get_post_revisions( $post_id );
		foreach ( $revisions as $rev ) {
			wp_delete_post_revision( $rev->ID );
		}
		$this->assertEmpty( wp_get_post_revisions( $post_id ) );

		NRE_Migration_Context::start( 'Before Update Test' );
		NRE_Migration_Context::before_update( $post_id );

		$revisions = wp_get_post_revisions( $post_id );
		$this->assertCount( 1, $revisions );

		// The baseline revision should NOT be tagged with migration meta.
		$rev  = reset( $revisions );
		$name = get_metadata( 'post', $rev->ID, '_nre_migration_name', true );
		$this->assertEmpty( $name );

		NRE_Migration_Context::stop();
	}

	public function test_before_update_skips_when_revisions_exist() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create( [ 'post_content' => 'Original' ] );

		// Force a revision to exist.
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Revision 1',
			]
		);

		$count_before = count( wp_get_post_revisions( $post_id ) );

		NRE_Migration_Context::start( 'Before Update Skip Test' );
		NRE_Migration_Context::before_update( $post_id );

		$count_after = count( wp_get_post_revisions( $post_id ) );
		$this->assertSame( $count_before, $count_after );

		NRE_Migration_Context::stop();
	}

	public function test_before_update_creates_baseline_when_latest_revision_stale() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create( [ 'post_content' => 'Version 1' ] );

		// Create a revision via normal update.
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Version 2',
			]
		);

		// Modify the post directly without creating a revision (simulates
		// REST API, WP-CLI, or direct DB update that skips revision creation).
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[ 'post_content' => 'Version 3' ],
			[ 'ID' => $post_id ]
		);
		clean_post_cache( $post_id );

		$count_before = count( wp_get_post_revisions( $post_id ) );

		NRE_Migration_Context::start( 'Stale Revision Test' );
		NRE_Migration_Context::before_update( $post_id );

		$count_after = count( wp_get_post_revisions( $post_id ) );

		// A baseline should have been created because the latest revision
		// ("Version 2") doesn't match the current post ("Version 3").
		$this->assertSame( $count_before + 1, $count_after );

		// The new baseline should capture the current post state.
		$revisions = wp_get_post_revisions( $post_id, [ 'order' => 'DESC' ] );
		$baseline  = reset( $revisions );
		$this->assertSame( 'Version 3', $baseline->post_content );

		// The baseline should NOT be tagged as a migration revision.
		$name = get_metadata( 'post', $baseline->ID, '_nre_migration_name', true );
		$this->assertEmpty( $name );

		NRE_Migration_Context::stop();
	}

	public function test_before_update_creates_baseline_when_title_stale() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create(
			[
				'post_content' => 'Same content',
				'post_title'   => 'Original Title',
			]
		);

		// Create a revision.
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Same content updated',
				'post_title'   => 'Title V2',
			]
		);

		// Change only the title directly (no revision created).
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[ 'post_title' => 'Title V3' ],
			[ 'ID' => $post_id ]
		);
		clean_post_cache( $post_id );

		$count_before = count( wp_get_post_revisions( $post_id ) );

		NRE_Migration_Context::start( 'Stale Title Test' );
		NRE_Migration_Context::before_update( $post_id );

		$count_after = count( wp_get_post_revisions( $post_id ) );
		$this->assertSame( $count_before + 1, $count_after );

		NRE_Migration_Context::stop();
	}

	public function test_after_update_creates_tagged_revision() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create( [ 'post_content' => 'Original' ] );

		NRE_Migration_Context::start( 'After Update Test' );

		// Simulate a raw SQL update by directly modifying the post.
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[ 'post_content' => 'Updated via raw SQL' ],
			[ 'ID' => $post_id ]
		);

		NRE_Migration_Context::after_update( $post_id );
		NRE_Migration_Context::stop();

		// The newest revision should be tagged.
		$revisions = wp_get_post_revisions( $post_id, [ 'order' => 'DESC' ] );
		$latest    = reset( $revisions );

		$name = get_metadata( 'post', $latest->ID, '_nre_migration_name', true );
		$this->assertSame( 'After Update Test', $name );

		// The revision should capture the updated content.
		$this->assertSame( 'Updated via raw SQL', $latest->post_content );
	}

	public function test_before_after_update_full_workflow() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create( [ 'post_content' => 'Original content' ] );

		// Delete auto-revisions to start clean.
		foreach ( wp_get_post_revisions( $post_id ) as $rev ) {
			wp_delete_post_revision( $rev->ID );
		}

		NRE_Migration_Context::start( 'Full Workflow' );

		NRE_Migration_Context::before_update( $post_id );

		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[ 'post_content' => 'Migrated content' ],
			[ 'ID' => $post_id ]
		);

		NRE_Migration_Context::after_update( $post_id );
		NRE_Migration_Context::stop();

		$revisions = wp_get_post_revisions( $post_id, [ 'order' => 'ASC' ] );
		$this->assertGreaterThanOrEqual( 2, count( $revisions ) );

		// First revision: untagged baseline.
		$baseline      = reset( $revisions );
		$baseline_name = get_metadata( 'post', $baseline->ID, '_nre_migration_name', true );
		$this->assertEmpty( $baseline_name );
		$this->assertSame( 'Original content', $baseline->post_content );

		// Last revision: tagged migration.
		$migration      = end( $revisions );
		$migration_name = get_metadata( 'post', $migration->ID, '_nre_migration_name', true );
		$this->assertSame( 'Full Workflow', $migration_name );
		$this->assertSame( 'Migrated content', $migration->post_content );
	}

	public function test_before_after_update_noop_without_context() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create( [ 'post_content' => 'Original' ] );

		// Delete auto-revisions.
		foreach ( wp_get_post_revisions( $post_id ) as $rev ) {
			wp_delete_post_revision( $rev->ID );
		}

		// No start() call — context is inactive.
		NRE_Migration_Context::before_update( $post_id );
		NRE_Migration_Context::after_update( $post_id );

		// No revisions should have been created.
		$this->assertEmpty( wp_get_post_revisions( $post_id ) );
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

	// --- Raw/fast revision tests ---

	public function test_raw_before_update_creates_baseline_revision() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create( [ 'post_content' => 'Original raw' ] );

		// Delete any auto-created revisions so the post has none.
		$revisions = wp_get_post_revisions( $post_id );
		foreach ( $revisions as $rev ) {
			wp_delete_post_revision( $rev->ID );
		}
		$this->assertEmpty( wp_get_post_revisions( $post_id ) );

		NRE_Migration_Context::start( 'Raw Before Update Test', [ 'raw_revisions' => true ] );
		NRE_Migration_Context::before_update( $post_id );

		// Must clean cache to see raw-inserted revisions via WP API.
		clean_post_cache( $post_id );

		$revisions = wp_get_post_revisions( $post_id );
		$this->assertCount( 1, $revisions );

		// The baseline revision should NOT be tagged with migration meta.
		$rev  = reset( $revisions );
		$name = get_metadata( 'post', $rev->ID, '_nre_migration_name', true );
		$this->assertEmpty( $name );

		// The baseline should capture the original content.
		$this->assertSame( 'Original raw', $rev->post_content );

		NRE_Migration_Context::stop();
	}

	public function test_raw_before_update_skips_when_revisions_exist() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create( [ 'post_content' => 'Original' ] );

		// Force a revision to exist.
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Revision 1',
			]
		);

		$count_before = count( wp_get_post_revisions( $post_id ) );

		NRE_Migration_Context::start( 'Raw Skip Test', [ 'raw_revisions' => true ] );
		NRE_Migration_Context::before_update( $post_id );

		clean_post_cache( $post_id );

		$count_after = count( wp_get_post_revisions( $post_id ) );
		$this->assertSame( $count_before, $count_after );

		NRE_Migration_Context::stop();
	}

	public function test_raw_before_update_creates_baseline_when_latest_revision_stale() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create( [ 'post_content' => 'Version 1' ] );

		// Create a revision via normal update.
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Version 2',
			]
		);

		// Modify the post directly without creating a revision.
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[ 'post_content' => 'Version 3' ],
			[ 'ID' => $post_id ]
		);
		clean_post_cache( $post_id );

		$count_before = count( wp_get_post_revisions( $post_id ) );

		NRE_Migration_Context::start( 'Raw Stale Test', [ 'raw_revisions' => true ] );
		NRE_Migration_Context::before_update( $post_id );

		clean_post_cache( $post_id );

		$count_after = count( wp_get_post_revisions( $post_id ) );

		// A baseline should have been created because the latest revision
		// ("Version 2") doesn't match the current post ("Version 3").
		$this->assertSame( $count_before + 1, $count_after );

		// The new baseline should capture the current post state.
		$revisions = wp_get_post_revisions( $post_id, [ 'order' => 'DESC' ] );
		$baseline  = reset( $revisions );
		$this->assertSame( 'Version 3', $baseline->post_content );

		// The baseline should NOT be tagged as a migration revision.
		$name = get_metadata( 'post', $baseline->ID, '_nre_migration_name', true );
		$this->assertEmpty( $name );

		NRE_Migration_Context::stop();
	}

	public function test_raw_before_update_creates_baseline_when_excerpt_stale() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create(
			[
				'post_content' => 'Same content',
				'post_excerpt' => 'Original excerpt',
			]
		);

		// Create a revision.
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Same content v2',
				'post_excerpt' => 'Excerpt V2',
			]
		);

		// Change only the excerpt directly (no revision created).
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[ 'post_excerpt' => 'Excerpt V3' ],
			[ 'ID' => $post_id ]
		);
		clean_post_cache( $post_id );

		$count_before = count( wp_get_post_revisions( $post_id ) );

		NRE_Migration_Context::start( 'Raw Excerpt Test', [ 'raw_revisions' => true ] );
		NRE_Migration_Context::before_update( $post_id );

		clean_post_cache( $post_id );

		$count_after = count( wp_get_post_revisions( $post_id ) );
		$this->assertSame( $count_before + 1, $count_after );

		NRE_Migration_Context::stop();
	}

	public function test_raw_after_update_creates_tagged_revision() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create( [ 'post_content' => 'Original' ] );

		NRE_Migration_Context::start( 'Raw After Update Test', [ 'raw_revisions' => true ] );

		// Simulate a raw SQL update.
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[ 'post_content' => 'Updated via raw SQL' ],
			[ 'ID' => $post_id ]
		);

		NRE_Migration_Context::after_update( $post_id );
		NRE_Migration_Context::stop();

		$revisions = wp_get_post_revisions( $post_id, [ 'order' => 'DESC' ] );
		$latest    = reset( $revisions );

		$name = get_metadata( 'post', $latest->ID, '_nre_migration_name', true );
		$this->assertSame( 'Raw After Update Test', $name );

		// The revision should capture the updated content.
		$this->assertSame( 'Updated via raw SQL', $latest->post_content );
	}

	public function test_raw_after_update_assigns_term_to_parent_post() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create();

		NRE_Migration_Context::start( 'Raw Term Test', [ 'raw_revisions' => true ] );

		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[ 'post_content' => 'Migrated raw content' ],
			[ 'ID' => $post_id ]
		);

		NRE_Migration_Context::after_update( $post_id );
		NRE_Migration_Context::stop();

		$terms = wp_get_object_terms( $post_id, 'nre_migration', [ 'fields' => 'names' ] );
		$this->assertContains( 'Raw Term Test', $terms );
	}

	public function test_raw_full_workflow() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create( [ 'post_content' => 'Original content' ] );

		// Delete auto-revisions to start clean.
		foreach ( wp_get_post_revisions( $post_id ) as $rev ) {
			wp_delete_post_revision( $rev->ID );
		}

		NRE_Migration_Context::start( 'Raw Full Workflow', [ 'raw_revisions' => true ] );

		NRE_Migration_Context::before_update( $post_id );

		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[ 'post_content' => 'Migrated content' ],
			[ 'ID' => $post_id ]
		);

		NRE_Migration_Context::after_update( $post_id );
		NRE_Migration_Context::stop();

		// Must clean cache to see raw-inserted revisions via WP API.
		clean_post_cache( $post_id );

		$revisions = wp_get_post_revisions( $post_id, [ 'order' => 'ASC' ] );
		$this->assertGreaterThanOrEqual( 2, count( $revisions ) );

		// First revision: untagged baseline.
		$baseline      = reset( $revisions );
		$baseline_name = get_metadata( 'post', $baseline->ID, '_nre_migration_name', true );
		$this->assertEmpty( $baseline_name );
		$this->assertSame( 'Original content', $baseline->post_content );

		// Last revision: tagged migration.
		$migration      = end( $revisions );
		$migration_name = get_metadata( 'post', $migration->ID, '_nre_migration_name', true );
		$this->assertSame( 'Raw Full Workflow', $migration_name );
		$this->assertSame( 'Migrated content', $migration->post_content );
	}

	public function test_raw_revision_has_correct_post_fields() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create(
			[
				'post_content' => 'Content for field check',
				'post_title'   => 'Field Check Title',
				'post_excerpt' => 'Field Check Excerpt',
			]
		);

		// Delete auto-revisions.
		foreach ( wp_get_post_revisions( $post_id ) as $rev ) {
			wp_delete_post_revision( $rev->ID );
		}

		NRE_Migration_Context::start( 'Raw Field Check', [ 'raw_revisions' => true ] );
		NRE_Migration_Context::before_update( $post_id );
		NRE_Migration_Context::stop();

		clean_post_cache( $post_id );

		$revisions = wp_get_post_revisions( $post_id );
		$this->assertCount( 1, $revisions );

		$rev = reset( $revisions );

		$this->assertSame( 'revision', $rev->post_type );
		$this->assertSame( 'inherit', $rev->post_status );
		$this->assertSame( $post_id, $rev->post_parent );
		$this->assertSame( 'Content for field check', $rev->post_content );
		$this->assertSame( 'Field Check Title', $rev->post_title );
		$this->assertSame( 'Field Check Excerpt', $rev->post_excerpt );
		$this->assertSame( $post_id . '-revision-v1', $rev->post_name );
		$this->assertEquals( $user_id, $rev->post_author );
	}

	public function test_raw_before_update_ignores_revisions_and_autosaves() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create();

		// Create a revision so we have one to reference.
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Updated content',
			]
		);
		$revisions   = wp_get_post_revisions( $post_id );
		$revision_id = key( $revisions );

		NRE_Migration_Context::start( 'Raw Ignore Test', [ 'raw_revisions' => true ] );

		// Passing a revision ID should be a no-op.
		$count_before = count( wp_get_post_revisions( $post_id ) );
		NRE_Migration_Context::before_update( $revision_id );
		clean_post_cache( $post_id );
		$count_after = count( wp_get_post_revisions( $post_id ) );

		$this->assertSame( $count_before, $count_after );

		NRE_Migration_Context::stop();
	}

	public function test_raw_multiple_posts_share_same_term() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post1 = $this->factory->post->create();
		$post2 = $this->factory->post->create();

		NRE_Migration_Context::start( 'Raw Shared Migration', [ 'raw_revisions' => true ] );

		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[ 'post_content' => 'Post 1 migrated raw' ],
			[ 'ID' => $post1 ]
		);
		NRE_Migration_Context::after_update( $post1 );

		$wpdb->update(
			$wpdb->posts,
			[ 'post_content' => 'Post 2 migrated raw' ],
			[ 'ID' => $post2 ]
		);
		NRE_Migration_Context::after_update( $post2 );

		NRE_Migration_Context::stop();

		$terms1 = wp_get_object_terms( $post1, 'nre_migration', [ 'fields' => 'ids' ] );
		$terms2 = wp_get_object_terms( $post2, 'nre_migration', [ 'fields' => 'ids' ] );

		$this->assertNotEmpty( $terms1 );
		$this->assertSame( $terms1, $terms2 );
	}

	public function test_raw_noop_without_context() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create( [ 'post_content' => 'Original' ] );

		// Delete auto-revisions.
		foreach ( wp_get_post_revisions( $post_id ) as $rev ) {
			wp_delete_post_revision( $rev->ID );
		}

		// No start() call — context is inactive. Should be no-op even
		// though we never enabled raw_revisions.
		NRE_Migration_Context::before_update( $post_id );
		NRE_Migration_Context::after_update( $post_id );

		$this->assertEmpty( wp_get_post_revisions( $post_id ) );
	}

	public function test_raw_start_without_option_uses_standard_path() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create( [ 'post_content' => 'Original content' ] );

		// Delete auto-revisions to start clean.
		foreach ( wp_get_post_revisions( $post_id ) as $rev ) {
			wp_delete_post_revision( $rev->ID );
		}

		// Start WITHOUT raw_revisions — standard path should still work.
		NRE_Migration_Context::start( 'Standard Path Test' );

		NRE_Migration_Context::before_update( $post_id );

		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[ 'post_content' => 'Standard migrated' ],
			[ 'ID' => $post_id ]
		);

		NRE_Migration_Context::after_update( $post_id );
		NRE_Migration_Context::stop();

		$revisions = wp_get_post_revisions( $post_id, [ 'order' => 'ASC' ] );
		$this->assertGreaterThanOrEqual( 2, count( $revisions ) );

		$migration      = end( $revisions );
		$migration_name = get_metadata( 'post', $migration->ID, '_nre_migration_name', true );
		$this->assertSame( 'Standard Path Test', $migration_name );
	}

	public function test_raw_stop_clears_raw_mode() {
		NRE_Migration_Context::start( 'Clear Raw Test', [ 'raw_revisions' => true ] );
		$this->assertNotNull( NRE_Migration_Context::get_context() );

		NRE_Migration_Context::stop();
		$this->assertNull( NRE_Migration_Context::get_context() );

		// Starting again without raw_revisions should use the standard path.
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create( [ 'post_content' => 'Original' ] );

		foreach ( wp_get_post_revisions( $post_id ) as $rev ) {
			wp_delete_post_revision( $rev->ID );
		}

		NRE_Migration_Context::start( 'After Clear Test' );
		NRE_Migration_Context::before_update( $post_id );

		$revisions = wp_get_post_revisions( $post_id );
		$this->assertCount( 1, $revisions );

		NRE_Migration_Context::stop();
	}

	public function test_raw_skips_wp_save_revisioned_meta_fields() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create( [ 'post_content' => 'Original' ] );

		// Verify wp_save_revisioned_meta_fields is normally hooked.
		$this->assertNotFalse(
			has_action( '_wp_put_post_revision', 'wp_save_revisioned_meta_fields' )
		);

		// Hook at priority 9 (just before wp_save_revisioned_meta_fields at 10)
		// to detect whether the core handler is present when the action fires.
		$hook_present = null;
		$checker      = function () use ( &$hook_present ) {
			$hook_present = has_action( '_wp_put_post_revision', 'wp_save_revisioned_meta_fields' );
		};
		add_action( '_wp_put_post_revision', $checker, 9 );

		NRE_Migration_Context::start( 'Raw Meta Skip Test', [ 'raw_revisions' => true ] );

		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[ 'post_content' => 'Updated content' ],
			[ 'ID' => $post_id ]
		);

		NRE_Migration_Context::after_update( $post_id );
		NRE_Migration_Context::stop();

		// The hook should have been temporarily removed during the raw do_action.
		$this->assertFalse( $hook_present );

		// And it should be restored after.
		$this->assertNotFalse(
			has_action( '_wp_put_post_revision', 'wp_save_revisioned_meta_fields' )
		);

		remove_action( '_wp_put_post_revision', $checker, 9 );
	}

	public function test_raw_restores_revisioned_meta_hook_after_stop() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create( [ 'post_content' => 'Original' ] );

		NRE_Migration_Context::start( 'Raw Hook Restore Test', [ 'raw_revisions' => true ] );

		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[ 'post_content' => 'Migrated' ],
			[ 'ID' => $post_id ]
		);

		NRE_Migration_Context::after_update( $post_id );
		NRE_Migration_Context::stop();

		// After stop(), the core hook must be back for normal WP operation.
		$this->assertNotFalse(
			has_action( '_wp_put_post_revision', 'wp_save_revisioned_meta_fields' )
		);
	}

	public function test_raw_term_assignment_cached_per_post() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create();

		NRE_Migration_Context::start( 'Raw Cache Test', [ 'raw_revisions' => true ] );

		global $wpdb;

		// First after_update — term gets assigned.
		$wpdb->update(
			$wpdb->posts,
			[ 'post_content' => 'First raw update' ],
			[ 'ID' => $post_id ]
		);
		NRE_Migration_Context::after_update( $post_id );

		$terms_after_first = wp_get_object_terms( $post_id, 'nre_migration', [ 'fields' => 'ids' ] );
		$this->assertNotEmpty( $terms_after_first );

		// Track wp_set_object_terms calls during second update.
		$terms_set_count = 0;
		$counter         = function () use ( &$terms_set_count ) {
			++$terms_set_count;
		};
		add_action( 'set_object_terms', $counter );

		// Second after_update for the same post — should skip wp_set_object_terms.
		$wpdb->update(
			$wpdb->posts,
			[ 'post_content' => 'Second raw update' ],
			[ 'ID' => $post_id ]
		);
		NRE_Migration_Context::after_update( $post_id );

		remove_action( 'set_object_terms', $counter );

		// wp_set_object_terms should not have been called for this post.
		$this->assertSame( 0, $terms_set_count );

		// But the term should still be assigned.
		$terms_after_second = wp_get_object_terms( $post_id, 'nre_migration', [ 'fields' => 'ids' ] );
		$this->assertSame( $terms_after_first, $terms_after_second );

		NRE_Migration_Context::stop();
	}

	public function test_raw_term_cache_cleared_on_stop() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create();

		// First migration — assigns term.
		NRE_Migration_Context::start( 'Cache Clear Test 1', [ 'raw_revisions' => true ] );

		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[ 'post_content' => 'Migration 1' ],
			[ 'ID' => $post_id ]
		);
		NRE_Migration_Context::after_update( $post_id );
		NRE_Migration_Context::stop();

		// Second migration — same post should get term assigned again
		// because stop() cleared the cache.
		$terms_set_count = 0;
		$counter         = function () use ( &$terms_set_count ) {
			++$terms_set_count;
		};
		add_action( 'set_object_terms', $counter );

		NRE_Migration_Context::start( 'Cache Clear Test 2', [ 'raw_revisions' => true ] );

		$wpdb->update(
			$wpdb->posts,
			[ 'post_content' => 'Migration 2' ],
			[ 'ID' => $post_id ]
		);
		NRE_Migration_Context::after_update( $post_id );
		NRE_Migration_Context::stop();

		remove_action( 'set_object_terms', $counter );

		// wp_set_object_terms should have been called for the second migration.
		$this->assertGreaterThan( 0, $terms_set_count );
	}
}
