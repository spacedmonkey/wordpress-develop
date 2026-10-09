<?php

/**
 * @group user
 * @covers ::wp_lazyload_user_meta
 */
class Tests_User_Lazy_Load_Meta extends WP_UnitTestCase {

	/**
	 * @ticket 63021
	 * @ticket 58001
	 */
	public function test_lazy_load_meta() {
		$user_ids = self::factory()->user->create_many( 3 );
		// Clear any existing cache.
		wp_cache_delete_multiple( $user_ids, 'user_meta' );
		wp_lazyload_user_meta( $user_ids );
		$filter = new MockAction();
		add_filter( 'update_user_metadata_cache', array( $filter, 'filter' ), 10, 2 );
		get_user_meta( $user_ids[0] );

		$args          = $filter->get_args();
		$first         = reset( $args );
		$load_user_ids = end( $first );
		$this->assertSameSets( $user_ids, $load_user_ids, 'Ensure all user IDs are loaded in a single batch' );
	}

	/**
	 * @ticket 63021
	 * @ticket 58001
	 */
	public function test_lazy_load_meta_sets() {
		$user_ids1 = self::factory()->user->create_many( 3 );
		$user_ids2 = self::factory()->user->create_many( 3 );
		$user_ids  = array_merge( $user_ids1, $user_ids2 );
		// Clear any existing cache.
		wp_cache_delete_multiple( $user_ids, 'user_meta' );
		wp_lazyload_user_meta( $user_ids );
		$filter = new MockAction();
		add_filter( 'update_user_metadata_cache', array( $filter, 'filter' ), 10, 2 );
		get_user_meta( $user_ids[0] );

		$args          = $filter->get_args();
		$first         = reset( $args );
		$load_user_ids = end( $first );
		$this->assertSameSets( $user_ids, $load_user_ids, 'Ensure all user IDs are loaded in a single batch' );
	}

	/**
	 * Reading the meta of a user that is not queued, such as the current user, should not
	 * load the meta of the queued users.
	 *
	 * @ticket 58001
	 */
	public function test_lazy_load_meta_not_in_queue() {
		$user_ids    = self::factory()->user->create_many( 3 );
		$new_user_id = self::factory()->user->create();
		wp_cache_delete_multiple( array_merge( $user_ids, array( $new_user_id ) ), 'user_meta' );
		wp_lazyload_user_meta( $user_ids );

		$filter = new MockAction();
		add_filter( 'update_user_metadata_cache', array( $filter, 'filter' ), 10, 2 );
		get_user_meta( $new_user_id );

		$args          = $filter->get_args();
		$first         = reset( $args );
		$load_user_ids = end( $first );
		$this->assertSame( array( $new_user_id ), $load_user_ids, 'Only the requested user should be loaded.' );

		get_user_meta( $user_ids[0] );

		$args          = $filter->get_args();
		$last          = end( $args );
		$load_user_ids = end( $last );
		$this->assertSameSets( $user_ids, $load_user_ids, 'The queued users should still be loaded in a single batch.' );
	}

	/**
	 * @ticket 58001
	 */
	public function test_lazy_load_meta_not_triggered_by_capabilities() {
		global $wpdb;

		$user_ids = self::factory()->user->create_many( 3 );
		wp_cache_delete_multiple( $user_ids, 'user_meta' );
		wp_lazyload_user_meta( $user_ids );

		get_user_meta( $user_ids[0], $wpdb->get_blog_prefix() . 'capabilities', true );

		$this->assertFalse( wp_cache_get( $user_ids[1], 'user_meta' ), 'Reading capabilities should not load the queued user meta.' );
	}
}
