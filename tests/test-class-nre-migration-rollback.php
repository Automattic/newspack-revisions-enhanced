<?php
/**
 * Tests for NRE_Migration_Rollback.
 *
 * @package Newspack_Revisions_Enhanced
 */

class Test_NRE_Migration_Rollback extends WP_UnitTestCase {

	/**
	 * @var NRE_Migration_Rollback
	 */
	private $rollback;

	/**
	 * @var int
	 */
	private $user_id;

	public function set_up() {
		parent::set_up();
		$this->rollback = new NRE_Migration_Rollback();
		$this->user_id  = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $this->user_id );
		NRE_Migration_Context::register_taxonomy();
	}

	public function tear_down() {
		NRE_Migration_Context::stop();
		parent::tear_down();
	}

	/**
	 * Helper: create a post, make a normal revision, then a migration revision.
	 *
	 * Returns post_id, migration name, and timestamp.
	 */
	private function create_migrated_post() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Original Title',
				'post_content' => 'Original content',
			]
		);

		// Create a pre-migration revision.
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Pre-migration content',
			]
		);

		NRE_Migration_Context::start( 'Test Rollback Migration' );
		$context = NRE_Migration_Context::get_context();

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_title'   => 'Migrated Title',
				'post_content' => 'Migrated content',
			]
		);

		NRE_Migration_Context::stop();

		return [
			'post_id'   => $post_id,
			'name'      => 'Test Rollback Migration',
			'timestamp' => $context['timestamp'],
		];
	}

	public function test_find_pre_migration_revision_success() {
		$data = $this->create_migrated_post();

		$result = $this->rollback->find_pre_migration_revision(
			$data['post_id'],
			$data['name'],
			$data['timestamp']
		);

		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );
	}

	public function test_find_pre_migration_revision_created_during_migration() {
		NRE_Migration_Context::start( 'Create During Migration' );
		$context = NRE_Migration_Context::get_context();

		// Create a post during migration (no pre-existing revisions).
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Created during migration',
				'post_content' => 'Brand new post',
			]
		);

		// Force a revision.
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Updated during migration',
			]
		);

		NRE_Migration_Context::stop();

		$result = $this->rollback->find_pre_migration_revision(
			$post_id,
			'Create During Migration',
			$context['timestamp']
		);

		$this->assertWPError( $result );
		$this->assertSame( 'no_pre_migration_revision', $result->get_error_code() );
	}

	public function test_find_pre_migration_revision_no_revisions() {
		$post_id = $this->factory->post->create();

		// Delete all revisions.
		$revisions = wp_get_post_revisions( $post_id );
		foreach ( $revisions as $rev ) {
			wp_delete_post_revision( $rev->ID );
		}

		$result = $this->rollback->find_pre_migration_revision( $post_id, 'Nonexistent', 1000000 );

		$this->assertWPError( $result );
		$this->assertSame( 'no_revisions', $result->get_error_code() );
	}

	public function test_find_pre_migration_revision_no_matching_migration() {
		$post_id = $this->factory->post->create();
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Some revision',
			]
		);

		$result = $this->rollback->find_pre_migration_revision( $post_id, 'Wrong Name', 999 );

		$this->assertWPError( $result );
		$this->assertSame( 'migration_revisions_not_found', $result->get_error_code() );
	}

	public function test_rollback_post_restores_content() {
		$data = $this->create_migrated_post();

		$result = $this->rollback->rollback_post(
			$data['post_id'],
			$data['name'],
			$data['timestamp']
		);

		$this->assertTrue( $result );

		$post = get_post( $data['post_id'] );
		$this->assertSame( 'Pre-migration content', $post->post_content );
	}

	public function test_rollback_post_restores_title() {
		$data = $this->create_migrated_post();

		$this->rollback->rollback_post(
			$data['post_id'],
			$data['name'],
			$data['timestamp']
		);

		$post = get_post( $data['post_id'] );
		// Title should be from pre-migration revision, which kept the original title.
		$this->assertSame( 'Original Title', $post->post_title );
	}

	public function test_rollback_post_restores_taxonomies() {
		$post_id = $this->factory->post->create( [ 'post_content' => 'Original' ] );
		$term1   = $this->factory->term->create( [ 'taxonomy' => 'category', 'name' => 'Original Cat' ] );
		wp_set_object_terms( $post_id, [ $term1 ], 'category' );

		// Pre-migration revision.
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Pre-migration',
			]
		);

		// Save taxonomy snapshot on the pre-migration revision.
		$tax_revisions = new NRE_Taxonomy_Revisions();
		$revisions     = wp_get_post_revisions( $post_id, [ 'order' => 'DESC' ] );
		$pre_rev       = reset( $revisions );
		$tax_revisions->save_taxonomy_snapshot( $pre_rev->ID, $post_id );

		// Migration: change terms.
		NRE_Migration_Context::start( 'Tax Rollback' );
		$context = NRE_Migration_Context::get_context();

		$term2 = $this->factory->term->create( [ 'taxonomy' => 'category', 'name' => 'Migrated Cat' ] );
		wp_set_object_terms( $post_id, [ $term2 ], 'category' );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Migrated',
			]
		);

		NRE_Migration_Context::stop();

		$this->rollback->rollback_post( $post_id, 'Tax Rollback', $context['timestamp'] );

		$term_ids = wp_get_object_terms( $post_id, 'category', [ 'fields' => 'ids' ] );
		$this->assertContains( $term1, $term_ids );
	}

	public function test_rollback_post_restores_post_type() {
		$post_id = $this->factory->post->create(
			[
				'post_type'    => 'post',
				'post_content' => 'Original',
			]
		);

		// Pre-migration revision with post_type snapshot.
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Pre-migration',
			]
		);

		$revisions = wp_get_post_revisions( $post_id, [ 'order' => 'DESC' ] );
		$pre_rev   = reset( $revisions );
		update_metadata( 'post', $pre_rev->ID, '_nre_post_type', 'post' );

		// Migration: change post type.
		NRE_Migration_Context::start( 'Type Rollback' );
		$context = NRE_Migration_Context::get_context();

		set_post_type( $post_id, 'page' );
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Migrated',
			]
		);

		NRE_Migration_Context::stop();

		$this->rollback->rollback_post( $post_id, 'Type Rollback', $context['timestamp'] );

		$post = get_post( $post_id );
		$this->assertSame( 'post', $post->post_type );
	}

	public function test_rollback_post_returns_error_for_created_posts() {
		NRE_Migration_Context::start( 'Created Post Rollback' );
		$context = NRE_Migration_Context::get_context();

		$post_id = $this->factory->post->create( [ 'post_content' => 'Created during migration' ] );
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Updated during migration',
			]
		);

		NRE_Migration_Context::stop();

		$result = $this->rollback->rollback_post(
			$post_id,
			'Created Post Rollback',
			$context['timestamp']
		);

		$this->assertWPError( $result );
	}

	public function test_rollback_migration_rolls_back_all() {
		$data = $this->create_migrated_post();

		// Get the term.
		$slug = sanitize_title( $data['name'] . '-' . $data['timestamp'] );
		$term = get_term_by( 'slug', $slug, 'nre_migration' );

		$result = $this->rollback->rollback_migration( $data['name'], $data['timestamp'], $term->term_id );

		$this->assertArrayHasKey( 'rolled_back', $result );
		$this->assertArrayHasKey( 'skipped', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertGreaterThanOrEqual( 1, $result['rolled_back'] );
	}

	public function test_rollback_migration_skips_created_posts() {
		NRE_Migration_Context::start( 'Mixed Migration' );
		$context = NRE_Migration_Context::get_context();

		// Create a brand new post during migration.
		$created_post_id = $this->factory->post->create( [ 'post_content' => 'Brand new' ] );
		wp_update_post(
			[
				'ID'           => $created_post_id,
				'post_content' => 'Brand new updated',
			]
		);

		NRE_Migration_Context::stop();

		$slug = sanitize_title( 'Mixed Migration-' . $context['timestamp'] );
		$term = get_term_by( 'slug', $slug, 'nre_migration' );

		if ( ! $term ) {
			$this->markTestSkipped( 'Migration term not created.' );
		}

		$result = $this->rollback->rollback_migration( 'Mixed Migration', $context['timestamp'], $term->term_id );

		$this->assertGreaterThanOrEqual( 1, $result['skipped'] );
	}

	public function test_rollback_creates_new_revision() {
		$data = $this->create_migrated_post();

		$revisions_before = wp_get_post_revisions( $data['post_id'] );
		$count_before     = count( $revisions_before );

		$this->rollback->rollback_post(
			$data['post_id'],
			$data['name'],
			$data['timestamp']
		);

		$revisions_after = wp_get_post_revisions( $data['post_id'] );
		$count_after     = count( $revisions_after );

		$this->assertGreaterThan( $count_before, $count_after );
	}
}
