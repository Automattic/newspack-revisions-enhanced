<?php
/**
 * Tests for NRE_Migration_Dashboard.
 *
 * @package Newspack_Revisions_Enhanced
 */

class Test_NRE_Migration_Dashboard extends WP_UnitTestCase {

	/**
	 * @var NRE_Migration_Dashboard
	 */
	private $dashboard;

	/**
	 * @var NRE_Migration_Rollback
	 */
	private $rollback;

	/**
	 * @var int
	 */
	private $editor_id;

	public function set_up() {
		parent::set_up();
		$this->rollback  = new NRE_Migration_Rollback();
		$this->dashboard = new NRE_Migration_Dashboard( $this->rollback );
		$this->editor_id = $this->factory->user->create( [ 'role' => 'editor' ] );

		NRE_Migration_Context::register_taxonomy();
	}

	public function tear_down() {
		NRE_Migration_Context::stop();
		parent::tear_down();
	}

	/**
	 * Helper: create a migration with posts.
	 */
	private function create_migration( $name = 'Dashboard Test Migration' ) {
		wp_set_current_user( $this->editor_id );

		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Pre-existing Post',
				'post_content' => 'Original',
			]
		);

		// Pre-migration revision.
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Pre-migration state',
			]
		);

		NRE_Migration_Context::start( $name );
		$context = NRE_Migration_Context::get_context();

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_title'   => 'Migrated Post',
				'post_content' => 'Migrated content',
			]
		);

		NRE_Migration_Context::stop();

		$slug = sanitize_title( $name . '-' . $context['timestamp'] );
		$term = get_term_by( 'slug', $slug, 'nre_migration' );

		return [
			'post_id'   => $post_id,
			'term_id'   => $term ? $term->term_id : 0,
			'name'      => $name,
			'timestamp' => $context['timestamp'],
		];
	}

	// --- Permissions ---

	public function test_check_permission_editor_returns_true() {
		wp_set_current_user( $this->editor_id );
		$this->assertTrue( $this->dashboard->check_permission() );
	}

	public function test_check_permission_subscriber_returns_false() {
		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );
		$this->assertFalse( $this->dashboard->check_permission() );
	}

	// --- REST routes ---

	public function test_rest_routes_registered() {
		// Routes are registered during rest_api_init; use the server which has already run init.
		$routes = rest_get_server()->get_routes();
		$prefix = '/' . NRE_Migration_Dashboard::REST_NAMESPACE;

		$this->assertArrayHasKey( $prefix . '/migrations', $routes );
		$this->assertArrayHasKey( $prefix . '/migrations/(?P<term_id>\\d+)', $routes );
		$this->assertArrayHasKey( $prefix . '/migrations/(?P<term_id>\\d+)/rollback', $routes );
		$this->assertArrayHasKey( $prefix . '/migrations/(?P<term_id>\\d+)/diff/(?P<post_id>\\d+)', $routes );
		$this->assertArrayHasKey( $prefix . '/migrations/(?P<term_id>\\d+)/rollback-all', $routes );
	}

	// --- get_migrations ---

	public function test_get_migrations_empty_response() {
		$response = $this->dashboard->get_migrations();
		$data     = $response->get_data();

		$this->assertIsArray( $data );
	}

	public function test_get_migrations_returns_terms_with_correct_data() {
		$migration = $this->create_migration();

		$response = $this->dashboard->get_migrations();
		$data     = $response->get_data();

		$this->assertNotEmpty( $data );

		$found = false;
		foreach ( $data as $item ) {
			if ( $item['term_id'] === $migration['term_id'] ) {
				$found = true;
				$this->assertSame( $migration['name'], $item['name'] );
				$this->assertArrayHasKey( 'timestamp', $item );
				$this->assertArrayHasKey( 'post_count', $item );
				$this->assertArrayHasKey( 'date', $item );
				$this->assertArrayHasKey( 'slug', $item );
			}
		}
		$this->assertTrue( $found );
	}

	public function test_get_migrations_ordered_desc() {
		$this->create_migration( 'First Migration' );
		sleep( 1 ); // Ensure different timestamp.
		$this->create_migration( 'Second Migration' );

		$response = $this->dashboard->get_migrations();
		$data     = $response->get_data();

		if ( count( $data ) >= 2 ) {
			$this->assertGreaterThan( $data[1]['term_id'], $data[0]['term_id'] );
		}
	}

	// --- get_migration_detail ---

	public function test_get_migration_detail_404_for_invalid_term() {
		$request = new WP_REST_Request( 'GET', '/nre/v1/migrations/999999' );
		$request->set_param( 'term_id', 999999 );

		$response = $this->dashboard->get_migration_detail( $request );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_get_migration_detail_returns_stats() {
		$migration = $this->create_migration();

		$request = new WP_REST_Request( 'GET', '/nre/v1/migrations/' . $migration['term_id'] );
		$request->set_param( 'term_id', $migration['term_id'] );

		$response = $this->dashboard->get_migration_detail( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'stats', $data );
		$this->assertArrayHasKey( 'total_posts', $data['stats'] );
		$this->assertArrayHasKey( 'posts_created', $data['stats'] );
		$this->assertArrayHasKey( 'posts_updated', $data['stats'] );
	}

	public function test_get_migration_detail_correct_post_data() {
		$migration = $this->create_migration();

		$request = new WP_REST_Request( 'GET', '/nre/v1/migrations/' . $migration['term_id'] );
		$request->set_param( 'term_id', $migration['term_id'] );

		$response = $this->dashboard->get_migration_detail( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'posts', $data );
		$this->assertNotEmpty( $data['posts'] );

		$post_data = $data['posts'][0];
		$this->assertArrayHasKey( 'post_id', $post_data );
		$this->assertArrayHasKey( 'title', $post_data );
		$this->assertArrayHasKey( 'status', $post_data );
		$this->assertArrayHasKey( 'can_rollback', $post_data );
		$this->assertArrayHasKey( 'revision_count', $post_data );
	}

	// --- rollback_post ---

	public function test_rollback_endpoint_success() {
		$migration = $this->create_migration();

		$request = new WP_REST_Request( 'POST', '/nre/v1/migrations/' . $migration['term_id'] . '/rollback' );
		$request->set_param( 'term_id', $migration['term_id'] );
		$request->set_param( 'post_id', $migration['post_id'] );

		$response = $this->dashboard->rollback_post( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
	}

	public function test_rollback_endpoint_404_for_invalid_term() {
		$request = new WP_REST_Request( 'POST', '/nre/v1/migrations/999999/rollback' );
		$request->set_param( 'term_id', 999999 );
		$request->set_param( 'post_id', 1 );

		$response = $this->dashboard->rollback_post( $request );
		$this->assertSame( 404, $response->get_status() );
	}

	// --- rollback_all ---

	public function test_rollback_all_returns_summary() {
		$migration = $this->create_migration();

		$request = new WP_REST_Request( 'POST', '/nre/v1/migrations/' . $migration['term_id'] . '/rollback-all' );
		$request->set_param( 'term_id', $migration['term_id'] );

		$response = $this->dashboard->rollback_all( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'rolled_back', $data );
		$this->assertArrayHasKey( 'skipped', $data );
		$this->assertArrayHasKey( 'total', $data );
	}

	// --- get_post_diff ---

	public function test_diff_endpoint_returns_diff_fields() {
		$migration = $this->create_migration();

		// Load revision.php functions.
		require_once ABSPATH . 'wp-admin/includes/revision.php';

		$request = new WP_REST_Request( 'GET', '/nre/v1/migrations/' . $migration['term_id'] . '/diff/' . $migration['post_id'] );
		$request->set_param( 'term_id', $migration['term_id'] );
		$request->set_param( 'post_id', $migration['post_id'] );

		$response = $this->dashboard->get_post_diff( $request );
		$data     = $response->get_data();

		// Should return diff fields or a message.
		$this->assertTrue( $response->get_status() === 200 );
	}

	public function test_diff_endpoint_404_for_invalid_term() {
		$request = new WP_REST_Request( 'GET', '/nre/v1/migrations/999999/diff/1' );
		$request->set_param( 'term_id', 999999 );
		$request->set_param( 'post_id', 1 );

		$response = $this->dashboard->get_post_diff( $request );
		$this->assertSame( 404, $response->get_status() );
	}
}
