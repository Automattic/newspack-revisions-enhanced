<?php
/**
 * Tests for NRE_Migration_UI.
 *
 * @package Newspack_Revisions_Enhanced
 */

class Test_NRE_Migration_UI extends WP_UnitTestCase {

	/**
	 * @var NRE_Migration_UI
	 */
	private $migration_ui;

	public function set_up() {
		parent::set_up();
		$this->migration_ui = new NRE_Migration_UI();
		NRE_Migration_Context::register_taxonomy();

		// Reset static $migrations via reflection.
		$reflection = new ReflectionClass( 'NRE_Migration_UI' );
		$property   = $reflection->getProperty( 'migrations' );
		$property->setAccessible( true );
		$property->setValue( null, [] );
	}

	public function tear_down() {
		NRE_Migration_Context::stop();

		// Reset static $migrations.
		$reflection = new ReflectionClass( 'NRE_Migration_UI' );
		$property   = $reflection->getProperty( 'migrations' );
		$property->setAccessible( true );
		$property->setValue( null, [] );

		parent::tear_down();
	}

	public function test_add_migration_data_with_migration_meta() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create();

		NRE_Migration_Context::start( 'UI Test Migration' );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Migrated',
			]
		);

		NRE_Migration_Context::stop();

		$revisions = wp_get_post_revisions( $post_id );
		$revision  = reset( $revisions );
		$post      = get_post( $post_id );

		$data = [
			'id'    => $revision->ID,
			'title' => $revision->post_title,
		];

		$result = $this->migration_ui->add_migration_data_to_revision( $data, $revision, $post );

		$this->assertArrayHasKey( 'nreMigration', $result );
		$this->assertIsArray( $result['nreMigration'] );
		$this->assertSame( 'UI Test Migration', $result['nreMigration']['name'] );
		$this->assertArrayHasKey( 'timestamp', $result['nreMigration'] );
		$this->assertArrayHasKey( 'date', $result['nreMigration'] );
	}

	public function test_add_migration_data_without_migration_meta() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create();

		// Normal update — no migration context.
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Normal update',
			]
		);

		$revisions = wp_get_post_revisions( $post_id );
		$revision  = reset( $revisions );
		$post      = get_post( $post_id );

		$data = [
			'id'    => $revision->ID,
			'title' => $revision->post_title,
		];

		$result = $this->migration_ui->add_migration_data_to_revision( $data, $revision, $post );

		$this->assertArrayHasKey( 'nreMigration', $result );
		$this->assertFalse( $result['nreMigration'] );
	}

	public function test_add_migration_data_aggregates_unique_migrations() {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create();

		NRE_Migration_Context::start( 'Aggregation Test' );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'First migration rev',
			]
		);
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Second migration rev',
			]
		);

		NRE_Migration_Context::stop();

		$revisions = wp_get_post_revisions( $post_id, [ 'order' => 'ASC' ] );
		$post      = get_post( $post_id );

		foreach ( $revisions as $rev ) {
			$data = [ 'id' => $rev->ID, 'title' => $rev->post_title ];
			$this->migration_ui->add_migration_data_to_revision( $data, $rev, $post );
		}

		// Access static $migrations via reflection.
		$reflection = new ReflectionClass( 'NRE_Migration_UI' );
		$property   = $reflection->getProperty( 'migrations' );
		$property->setAccessible( true );
		$migrations = $property->getValue();

		// Should have only one unique migration entry.
		$this->assertCount( 1, $migrations );

		// But it should have multiple revision IDs.
		$migration = reset( $migrations );
		$this->assertGreaterThanOrEqual( 2, count( $migration['revisionIds'] ) );
	}

	public function test_add_body_class_on_revision_screen() {
		// Simulate revision screen.
		set_current_screen( 'revision' );

		$classes = $this->migration_ui->add_body_class( '' );
		$this->assertStringContainsString( 'nre-newspack-theme', $classes );

		set_current_screen( 'front' );
	}

	public function test_add_body_class_disabled_by_filter() {
		set_current_screen( 'revision' );

		add_filter( 'nre_newspack_revisions_theme', '__return_false' );
		$classes = $this->migration_ui->add_body_class( '' );
		remove_filter( 'nre_newspack_revisions_theme', '__return_false' );

		$this->assertStringNotContainsString( 'nre-newspack-theme', $classes );

		set_current_screen( 'front' );
	}

	public function test_add_body_class_no_op_on_other_screens() {
		set_current_screen( 'edit-post' );

		$classes = $this->migration_ui->add_body_class( '' );
		$this->assertStringNotContainsString( 'nre-newspack-theme', $classes );

		set_current_screen( 'front' );
	}
}
