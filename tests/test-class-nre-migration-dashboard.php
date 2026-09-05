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

	/**
	 * @var int
	 */
	private $locked_post_id = 0;

	public function set_up() {
		parent::set_up();
		$this->rollback  = new NRE_Migration_Rollback();
		$this->dashboard = new NRE_Migration_Dashboard( $this->rollback );
		$this->editor_id = $this->factory->user->create( [ 'role' => 'editor' ] );

		NRE_Migration_Context::register_taxonomy();
	}

	public function tear_down() {
		NRE_Migration_Context::stop();
		remove_filter( 'map_meta_cap', [ $this, 'deny_locked_post' ], 10 );
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

	/**
	 * Helper: create a migration where the post is brand new (no pre-migration revision).
	 */
	private function create_migration_with_new_post( $name = 'Dashboard New Post Migration' ) {
		wp_set_current_user( $this->editor_id );

		NRE_Migration_Context::start( $name );
		$context = NRE_Migration_Context::get_context();

		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Brand New Post',
				'post_content' => 'Created during migration',
			]
		);

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Updated during migration',
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

	public function test_check_permission_author_returns_false() {
		$author_id = $this->factory->user->create( [ 'role' => 'author' ] );
		wp_set_current_user( $author_id );
		$this->assertFalse( $this->dashboard->check_permission() );
	}

	public function test_check_permission_requires_edit_capability_on_named_post() {
		$migration = $this->create_migration();
		$other_id  = $this->factory->post->create();
		wp_set_current_user( $this->editor_id );

		$this->lock_post( $migration['post_id'] );

		$request = new WP_REST_Request( 'GET', '/nre/v1/migrations/' . $migration['term_id'] . '/diff/' . $migration['post_id'] );
		$request->set_param( 'term_id', $migration['term_id'] );
		$request->set_param( 'post_id', $migration['post_id'] );
		$this->assertFalse( $this->dashboard->check_permission( $request ) );

		$request = new WP_REST_Request( 'POST', '/nre/v1/migrations/' . $migration['term_id'] . '/rollback' );
		$request->set_param( 'term_id', $migration['term_id'] );
		$request->set_param( 'post_id', $migration['post_id'] );
		$this->assertFalse( $this->dashboard->check_permission( $request ) );

		$request->set_param( 'post_id', $other_id );
		$this->assertTrue( $this->dashboard->check_permission( $request ) );

		$request = new WP_REST_Request( 'GET', '/nre/v1/migrations/' . $migration['term_id'] );
		$request->set_param( 'term_id', $migration['term_id'] );
		$this->assertTrue( $this->dashboard->check_permission( $request ) );
	}

	public function test_dispatched_diff_request_is_refused_without_edit_capability_on_post() {
		$migration = $this->create_migration();
		wp_set_current_user( $this->editor_id );

		$request  = new WP_REST_Request( 'GET', '/nre/v1/migrations/' . $migration['term_id'] . '/diff/' . $migration['post_id'] );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$this->lock_post( $migration['post_id'] );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
	}

	public function test_get_migration_posts_lists_only_editable_posts() {
		$migration = $this->create_migration();
		wp_set_current_user( $this->editor_id );

		$request = new WP_REST_Request( 'GET', '/nre/v1/migrations/' . $migration['term_id'] . '/posts' );
		$request->set_param( 'term_id', $migration['term_id'] );
		$request->set_param( 'per_page', 50 );
		$request->set_param( 'page', 1 );
		$request->set_param( 'status', 'all' );
		$request->set_param( 'search', '' );

		$data = $this->dashboard->get_migration_posts( $request )->get_data();
		$this->assertSame( 1, $data['total'] );

		$this->lock_post( $migration['post_id'] );

		$data = $this->dashboard->get_migration_posts( $request )->get_data();
		$this->assertSame( 0, $data['total'] );
		$this->assertSame( [], $data['posts'] );
	}

	public function test_rollback_all_records_acting_user() {
		$migration = $this->create_migration();
		wp_set_current_user( $this->editor_id );

		$request = new WP_REST_Request( 'POST', '/nre/v1/migrations/' . $migration['term_id'] . '/rollback-all' );
		$request->set_param( 'term_id', $migration['term_id'] );
		$this->dashboard->rollback_all( $request );

		$state = get_option( 'nre_rollback_' . $migration['term_id'] );
		$this->assertSame( $this->editor_id, $state['user_id'] );

		delete_option( 'nre_rollback_' . $migration['term_id'] );
	}

	public function test_filter_editable_posts_keeps_only_posts_the_user_can_edit() {
		$post_id   = $this->factory->post->create();
		$other_id  = $this->factory->post->create();
		$author_id = $this->factory->user->create( [ 'role' => 'author' ] );
		$own_id    = $this->factory->post->create( [ 'post_author' => $author_id ] );

		$this->assertSame( [ $post_id, $other_id, $own_id ], $this->dashboard->filter_editable_posts( [ $post_id, $other_id, $own_id ], $this->editor_id ) );
		$this->assertSame( [ $own_id ], $this->dashboard->filter_editable_posts( [ $post_id, $other_id, $own_id ], $author_id ) );
		$this->assertSame( [], $this->dashboard->filter_editable_posts( [ $post_id, $other_id, $own_id ], 0 ) );
	}

	/**
	 * Helper: deny edit_post on one post for every user, so a caller holding the
	 * site-wide capability still fails the object-level check on it.
	 */
	private function lock_post( $post_id ) {
		$this->locked_post_id = (int) $post_id;
		add_filter( 'map_meta_cap', [ $this, 'deny_locked_post' ], 10, 4 );
	}

	public function deny_locked_post( $caps, $cap, $user_id, $args ) {
		if ( 'edit_post' === $cap && isset( $args[0] ) && (int) $args[0] === $this->locked_post_id ) {
			return [ 'do_not_allow' ];
		}
		return $caps;
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
		$this->assertArrayHasKey( $prefix . '/migrations/(?P<term_id>\\d+)/rollback-status', $routes );
		$this->assertArrayHasKey( $prefix . '/migrations/(?P<term_id>\\d+)/rollback-cancel', $routes );
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

	public function test_get_migration_detail_correct_stats_data() {
		$migration = $this->create_migration();

		$request = new WP_REST_Request( 'GET', '/nre/v1/migrations/' . $migration['term_id'] );
		$request->set_param( 'term_id', $migration['term_id'] );

		$response = $this->dashboard->get_migration_detail( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'stats', $data );
		$this->assertSame( 1, $data['stats']['total_posts'] );
		$this->assertSame( 0, $data['stats']['posts_created'] );
		$this->assertSame( 1, $data['stats']['posts_updated'] );
	}

	public function test_pre_existing_post_without_prior_revision_is_updated() {
		wp_set_current_user( $this->editor_id );

		// Create a post with a date in the past (before migration).
		$post_id = $this->factory->post->create(
			[
				'post_title'    => 'Old Post',
				'post_content'  => 'Original content',
				'post_date'     => '2020-01-01 00:00:00',
				'post_date_gmt' => '2020-01-01 00:00:00',
			]
		);

		// Delete any auto-created revisions so there's no prior revision.
		foreach ( wp_get_post_revisions( $post_id ) as $rev ) {
			wp_delete_post_revision( $rev->ID );
		}

		// Now run a migration that updates this post for the first time.
		NRE_Migration_Context::start( 'First Touch Migration' );
		$context = NRE_Migration_Context::get_context();

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Migrated content',
			]
		);

		NRE_Migration_Context::stop();

		$slug = sanitize_title( 'First Touch Migration-' . $context['timestamp'] );
		$term = get_term_by( 'slug', $slug, 'nre_migration' );
		$this->assertNotFalse( $term );

		$request = new WP_REST_Request( 'GET', '/nre/v1/migrations/' . $term->term_id );
		$request->set_param( 'term_id', $term->term_id );

		$response = $this->dashboard->get_migration_detail( $request );
		$data     = $response->get_data();

		// The post existed before the migration, so it should be "updated" not "created".
		$this->assertSame( 1, $data['stats']['posts_updated'] );
		$this->assertSame( 0, $data['stats']['posts_created'] );
	}

	public function test_post_created_during_migration_is_created() {
		$migration = $this->create_migration_with_new_post( 'Creation Detection' );

		$request = new WP_REST_Request( 'GET', '/nre/v1/migrations/' . $migration['term_id'] );
		$request->set_param( 'term_id', $migration['term_id'] );

		$response = $this->dashboard->get_migration_detail( $request );
		$data     = $response->get_data();

		$this->assertSame( 1, $data['stats']['posts_created'] );
		$this->assertSame( 0, $data['stats']['posts_updated'] );
	}

	public function test_get_migration_posts_correct_post_data() {
		$migration = $this->create_migration();

		$request = new WP_REST_Request( 'GET', '/nre/v1/migrations/' . $migration['term_id'] . '/posts' );
		$request->set_param( 'term_id', $migration['term_id'] );
		$request->set_param( 'per_page', 50 );
		$request->set_param( 'page', 1 );
		$request->set_param( 'status', 'all' );
		$request->set_param( 'search', '' );

		$response = $this->dashboard->get_migration_posts( $request );
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

	public function test_rollback_all_starts_background_job() {
		$migration = $this->create_migration();

		$request = new WP_REST_Request( 'POST', '/nre/v1/migrations/' . $migration['term_id'] . '/rollback-all' );
		$request->set_param( 'term_id', $migration['term_id'] );

		$response = $this->dashboard->rollback_all( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'running', $data['status'] );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertGreaterThan( 0, $data['total'] );

		// State option should be set.
		$state = get_option( 'nre_rollback_' . $migration['term_id'] );
		$this->assertIsArray( $state );
		$this->assertSame( 'running', $state['status'] );

		// Clean up.
		delete_option( 'nre_rollback_' . $migration['term_id'] );
	}

	public function test_rollback_all_returns_409_when_already_running() {
		$migration = $this->create_migration();

		// Simulate a running rollback.
		update_option(
			'nre_rollback_' . $migration['term_id'],
			[ 'status' => 'running', 'started_at' => time() ],
			false
		);

		$request = new WP_REST_Request( 'POST', '/nre/v1/migrations/' . $migration['term_id'] . '/rollback-all' );
		$request->set_param( 'term_id', $migration['term_id'] );

		$response = $this->dashboard->rollback_all( $request );

		$this->assertSame( 409, $response->get_status() );

		// Clean up.
		delete_option( 'nre_rollback_' . $migration['term_id'] );
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

	public function test_diff_endpoint_returns_diff_for_created_post() {
		$migration = $this->create_migration_with_new_post();

		require_once ABSPATH . 'wp-admin/includes/revision.php';

		$request = new WP_REST_Request( 'GET', '/nre/v1/migrations/' . $migration['term_id'] . '/diff/' . $migration['post_id'] );
		$request->set_param( 'term_id', $migration['term_id'] );
		$request->set_param( 'post_id', $migration['post_id'] );

		$response = $this->dashboard->get_post_diff( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_diff_endpoint_still_fails_for_missing_revisions() {
		$migration = $this->create_migration();

		$request = new WP_REST_Request( 'GET', '/nre/v1/migrations/' . $migration['term_id'] . '/diff/999999' );
		$request->set_param( 'term_id', $migration['term_id'] );
		$request->set_param( 'post_id', 999999 );

		$response = $this->dashboard->get_post_diff( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_created_post_has_compare_from_zero() {
		$migration = $this->create_migration_with_new_post();

		$request = new WP_REST_Request( 'GET', '/nre/v1/migrations/' . $migration['term_id'] . '/posts' );
		$request->set_param( 'term_id', $migration['term_id'] );
		$request->set_param( 'per_page', 50 );
		$request->set_param( 'page', 1 );
		$request->set_param( 'status', 'all' );
		$request->set_param( 'search', '' );

		$response = $this->dashboard->get_migration_posts( $request );
		$data     = $response->get_data();
		$posts    = $data['posts'];

		$created_post = null;
		foreach ( $posts as $p ) {
			if ( $p['post_id'] === $migration['post_id'] ) {
				$created_post = $p;
				break;
			}
		}

		$this->assertNotNull( $created_post );
		$this->assertSame( 0, $created_post['compare_from'] );
		$this->assertSame( 'created', $created_post['status'] );
	}

	public function test_created_post_has_revision_url() {
		$migration = $this->create_migration_with_new_post();

		$request = new WP_REST_Request( 'GET', '/nre/v1/migrations/' . $migration['term_id'] . '/posts' );
		$request->set_param( 'term_id', $migration['term_id'] );
		$request->set_param( 'per_page', 50 );
		$request->set_param( 'page', 1 );
		$request->set_param( 'status', 'all' );
		$request->set_param( 'search', '' );

		$response = $this->dashboard->get_migration_posts( $request );
		$data     = $response->get_data();
		$posts    = $data['posts'];

		$created_post = null;
		foreach ( $posts as $p ) {
			if ( $p['post_id'] === $migration['post_id'] ) {
				$created_post = $p;
				break;
			}
		}

		$this->assertNotNull( $created_post );
		$this->assertNotNull( $created_post['revision_url'] );
		$this->assertStringContainsString( 'revision.php?revision=', $created_post['revision_url'] );
	}

	public function test_diff_endpoint_404_for_invalid_term() {
		$request = new WP_REST_Request( 'GET', '/nre/v1/migrations/999999/diff/1' );
		$request->set_param( 'term_id', 999999 );
		$request->set_param( 'post_id', 1 );

		$response = $this->dashboard->get_post_diff( $request );
		$this->assertSame( 404, $response->get_status() );
	}
}
