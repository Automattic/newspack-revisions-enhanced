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

	public function tear_down() {
		// Clean up any registered meta.
		unregister_meta_key( 'post', 'test_rest_meta' );
		unregister_meta_key( 'post', 'test_core_revisions_meta' );
		unregister_meta_key( 'post', 'test_no_rest_meta' );
		unregister_meta_key( 'post', 'test_global_meta' );
		parent::tear_down();
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

	public function test_auto_detects_show_in_rest_meta() {
		register_post_meta(
			'post',
			'test_rest_meta',
			[
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
			]
		);

		$keys = $this->meta_revisions->filter_revision_meta_keys( [], 'post' );
		$this->assertContains( 'test_rest_meta', $keys );
	}

	public function test_skips_keys_with_revisions_enabled() {
		register_post_meta(
			'post',
			'test_core_revisions_meta',
			[
				'show_in_rest'     => true,
				'single'           => true,
				'type'             => 'string',
				'revisions_enabled' => true,
			]
		);

		$keys = $this->meta_revisions->filter_revision_meta_keys( [], 'post' );
		$this->assertNotContains( 'test_core_revisions_meta', $keys );
	}

	public function test_skips_meta_without_show_in_rest() {
		register_post_meta(
			'post',
			'test_no_rest_meta',
			[
				'single' => true,
				'type'   => 'string',
			]
		);

		$keys = $this->meta_revisions->filter_revision_meta_keys( [], 'post' );
		$this->assertNotContains( 'test_no_rest_meta', $keys );
	}

	public function test_auto_detect_filter_disables_detection() {
		register_post_meta(
			'post',
			'test_rest_meta',
			[
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
			]
		);

		add_filter( 'nre_auto_detect_rest_meta', '__return_false' );
		$keys = $this->meta_revisions->filter_revision_meta_keys( [], 'post' );
		remove_filter( 'nre_auto_detect_rest_meta', '__return_false' );

		$this->assertNotContains( 'test_rest_meta', $keys );
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

	public function test_get_meta_label_internal_label() {
		$label = $this->meta_revisions->get_meta_label( '_thumbnail_id', 'post' );
		$this->assertSame( 'Featured Image', $label );
	}

	public function test_get_meta_label_registered_label() {
		register_post_meta(
			'post',
			'test_rest_meta',
			[
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
				'label'        => 'My Custom Label',
			]
		);

		$label = $this->meta_revisions->get_meta_label( 'test_rest_meta', 'post' );
		$this->assertSame( 'My Custom Label', $label );
	}

	public function test_get_meta_label_raw_key_fallback() {
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

	public function test_get_tracked_meta_keys_returns_plugin_added_keys() {
		register_post_meta(
			'post',
			'test_rest_meta',
			[
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
			]
		);

		// Remove all existing filters to isolate our instance.
		remove_all_filters( 'wp_post_revision_meta_keys' );
		$this->meta_revisions->register_hooks();

		$keys = $this->meta_revisions->get_tracked_meta_keys( 'post' );
		$this->assertContains( 'test_rest_meta', $keys );

		// Clean up.
		remove_filter( 'wp_post_revision_meta_keys', [ $this->meta_revisions, 'filter_revision_meta_keys' ], 10 );
	}

	public function test_global_registered_meta_detected() {
		register_meta(
			'post',
			'test_global_meta',
			[
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
			]
		);

		$keys = $this->meta_revisions->filter_revision_meta_keys( [], 'post' );
		$this->assertContains( 'test_global_meta', $keys );
	}
}
