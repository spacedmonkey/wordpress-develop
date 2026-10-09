<?php

/**
 * Tests for the 'user_capabilities' cache group.
 *
 * @group user
 * @group cache
 *
 * @covers ::wp_prime_user_capabilities_cache
 * @covers ::wp_get_user_capabilities_metadata
 * @covers ::wp_update_user_capabilities_cache
 * @covers ::wp_delete_user_capabilities_cache_key
 */
class Tests_User_WpPrimeUserCapabilitiesCache extends WP_UnitTestCase {

	/**
	 * @var int[]
	 */
	protected static $user_ids;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$user_ids = array(
			$factory->user->create( array( 'role' => 'administrator' ) ),
			$factory->user->create( array( 'role' => 'editor' ) ),
		);
	}

	/**
	 * Returns the capabilities meta key for the current site.
	 *
	 * @return string
	 */
	private function cap_key() {
		global $wpdb;

		return $wpdb->get_blog_prefix() . 'capabilities';
	}

	/**
	 * Clears the user meta and user capabilities caches for the given users.
	 *
	 * @param int[] $user_ids User IDs.
	 */
	private function flush_caches( array $user_ids ) {
		wp_cache_delete_multiple( $user_ids, 'user_meta' );
		wp_cache_delete_multiple( $user_ids, 'user_capabilities' );
	}

	/**
	 * @ticket 58001
	 *
	 * @dataProvider data_is_user_capabilities_meta_key
	 *
	 * @covers ::_wp_is_user_capabilities_meta_key
	 *
	 * @param string $suffix   Meta key without the base prefix.
	 * @param bool   $expected Whether the key should match.
	 */
	public function test_is_user_capabilities_meta_key( $suffix, $expected ) {
		global $wpdb;

		$this->assertSame( $expected, _wp_is_user_capabilities_meta_key( $wpdb->base_prefix . $suffix ) );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public function data_is_user_capabilities_meta_key() {
		return array(
			'main site'         => array( 'capabilities', true ),
			'site 2'            => array( '2_capabilities', true ),
			'site 123'          => array( '123_capabilities', true ),
			'user level'        => array( 'user_level', false ),
			'non-numeric site'  => array( 'foo_capabilities', false ),
			'trailing suffix'   => array( 'capabilities_old', false ),
			'missing separator' => array( '2capabilities', false ),
		);
	}

	/**
	 * @ticket 58001
	 *
	 * @covers ::_wp_is_user_capabilities_meta_key
	 */
	public function test_is_user_capabilities_meta_key_requires_base_prefix() {
		$this->assertFalse( _wp_is_user_capabilities_meta_key( 'capabilities' ) );
		$this->assertFalse( _wp_is_user_capabilities_meta_key( 'other_capabilities' ) );
		$this->assertFalse( _wp_is_user_capabilities_meta_key( '' ) );
	}

	/**
	 * @ticket 58001
	 */
	public function test_prime_only_reads_the_cache() {
		$this->flush_caches( self::$user_ids );

		$num_queries = get_num_queries();
		wp_prime_user_capabilities_cache( self::$user_ids );

		$this->assertSame( 0, get_num_queries() - $num_queries, 'Priming should not query the database.' );
		$this->assertFalse( wp_cache_get( self::$user_ids[0], 'user_capabilities' ), 'Priming should not build entries.' );
	}

	/**
	 * @ticket 58001
	 */
	public function test_adding_capabilities_meta_stores_it() {
		$user_id = self::factory()->user->create();
		delete_user_meta( $user_id, $this->cap_key() );
		wp_cache_delete( $user_id, 'user_capabilities' );

		add_user_meta( $user_id, $this->cap_key(), array( 'author' => true ) );

		$this->assertSame( array( $this->cap_key() => array( 'author' => true ) ), wp_cache_get( $user_id, 'user_capabilities' ) );
	}

	/**
	 * @ticket 58001
	 */
	public function test_updating_capabilities_meta_stores_it() {
		$user_id = self::$user_ids[1];
		wp_cache_delete( $user_id, 'user_capabilities' );

		update_user_meta( $user_id, $this->cap_key(), array( 'contributor' => true ) );

		$this->assertSame( array( $this->cap_key() => array( 'contributor' => true ) ), wp_cache_get( $user_id, 'user_capabilities' ) );
	}

	/**
	 * @ticket 58001
	 */
	public function test_updating_capabilities_meta_keeps_other_keys() {
		global $wpdb;

		$user_id   = self::$user_ids[1];
		$other_key = $wpdb->base_prefix . '99_capabilities';
		wp_cache_set( $user_id, array( $other_key => array( 'author' => true ) ), 'user_capabilities' );

		update_user_meta( $user_id, $this->cap_key(), array( 'contributor' => true ) );

		$this->assertSame(
			array(
				$other_key       => array( 'author' => true ),
				$this->cap_key() => array( 'contributor' => true ),
			),
			wp_cache_get( $user_id, 'user_capabilities' )
		);
	}

	/**
	 * @ticket 58001
	 */
	public function test_set_role_stores_capabilities() {
		$user_id = self::$user_ids[1];

		( new WP_User( $user_id ) )->set_role( 'author' );

		$this->assertSame( array( 'author' => true ), wp_cache_get( $user_id, 'user_capabilities' )[ $this->cap_key() ] );
	}

	/**
	 * @ticket 58001
	 */
	public function test_stored_value_matches_database_value() {
		$user_id = self::$user_ids[1];

		update_user_meta( $user_id, $this->cap_key(), 5 );
		$cached = wp_cache_get( $user_id, 'user_capabilities' )[ $this->cap_key() ];

		$this->flush_caches( array( $user_id ) );
		$this->assertSame( get_user_meta( $user_id, $this->cap_key(), true ), $cached );
	}

	/**
	 * @ticket 58001
	 */
	public function test_deleting_capabilities_meta_removes_key() {
		global $wpdb;

		$user_id   = self::$user_ids[1];
		$other_key = $wpdb->base_prefix . '99_capabilities';
		wp_cache_set(
			$user_id,
			array(
				$this->cap_key() => array( 'editor' => true ),
				$other_key       => array( 'author' => true ),
			),
			'user_capabilities'
		);

		delete_user_meta( $user_id, $this->cap_key() );

		$this->assertSame( array( $other_key => array( 'author' => true ) ), wp_cache_get( $user_id, 'user_capabilities' ) );
	}

	/**
	 * @ticket 58001
	 */
	public function test_other_meta_is_not_stored() {
		$user_id = self::$user_ids[0];
		wp_cache_delete( $user_id, 'user_capabilities' );

		update_user_meta( $user_id, 'foo', 'bar' );
		delete_user_meta( $user_id, 'foo' );

		$this->assertFalse( wp_cache_get( $user_id, 'user_capabilities' ) );
	}

	/**
	 * @ticket 58001
	 */
	public function test_get_user_meta_reads_capabilities_without_loading_user_meta() {
		$user_id = self::$user_ids[0];
		wp_cache_delete( $user_id, 'user_meta' );
		wp_cache_set( $user_id, array( $this->cap_key() => array( 'administrator' => true ) ), 'user_capabilities' );

		$num_queries = get_num_queries();
		$caps        = get_user_meta( $user_id, $this->cap_key(), true );

		$this->assertSame( array( 'administrator' => true ), $caps );
		$this->assertSame( 0, get_num_queries() - $num_queries, 'No query should be made.' );
		$this->assertFalse( wp_cache_get( $user_id, 'user_meta' ), 'User meta should not be loaded.' );
	}

	/**
	 * @ticket 58001
	 */
	public function test_get_user_meta_returns_single_and_multiple_values_from_cache() {
		wp_cache_set( self::$user_ids[0], array( $this->cap_key() => array( 'subscriber' => true ) ), 'user_capabilities' );

		$this->assertSame( array( 'subscriber' => true ), get_user_meta( self::$user_ids[0], $this->cap_key(), true ) );
		$this->assertSame( array( array( 'subscriber' => true ) ), get_user_meta( self::$user_ids[0], $this->cap_key() ) );
		$this->assertTrue( metadata_exists( 'user', self::$user_ids[0], $this->cap_key() ) );
	}

	/**
	 * @ticket 58001
	 */
	public function test_get_user_meta_falls_through_when_key_is_not_cached() {
		global $wpdb;

		$user_id = self::$user_ids[0];
		$this->flush_caches( array( $user_id ) );
		wp_cache_set( $user_id, array( $wpdb->base_prefix . '99_capabilities' => array( 'author' => true ) ), 'user_capabilities' );

		$this->assertSame( array( 'administrator' => true ), get_user_meta( $user_id, $this->cap_key(), true ) );
		$this->assertSame( '', get_user_meta( $user_id, $wpdb->base_prefix . '98_capabilities', true ) );
		$this->assertFalse( metadata_exists( 'user', $user_id, $wpdb->base_prefix . '98_capabilities' ) );
	}

	/**
	 * @ticket 58001
	 */
	public function test_get_user_meta_falls_through_when_entry_is_not_cached() {
		$this->flush_caches( self::$user_ids );

		$this->assertSame( array( 'editor' => true ), get_user_meta( self::$user_ids[1], $this->cap_key(), true ) );
	}

	/**
	 * @ticket 58001
	 */
	public function test_get_user_meta_for_other_keys_is_unaffected() {
		update_user_meta( self::$user_ids[0], 'foo', 'bar' );
		wp_cache_set( self::$user_ids[0], array( 'foo' => 'cached' ), 'user_capabilities' );

		$this->assertSame( 'bar', get_user_meta( self::$user_ids[0], 'foo', true ) );
	}

	/**
	 * @ticket 58001
	 *
	 * @covers WP_User::get_caps_data
	 */
	public function test_wp_user_reads_capabilities_from_cache() {
		wp_cache_set( self::$user_ids[1], array( $this->cap_key() => array( 'author' => true ) ), 'user_capabilities' );

		$user = new WP_User( self::$user_ids[1] );

		$this->assertSame( array( 'author' ), $user->roles );
	}

	/**
	 * @ticket 58001
	 *
	 * @covers ::_wp_clean_user_capabilities_cache_before_delete
	 */
	public function test_delete_all_clears_every_affected_user() {
		wp_cache_set( self::$user_ids[0], array( $this->cap_key() => array( 'administrator' => true ) ), 'user_capabilities' );
		wp_cache_set( self::$user_ids[1], array( $this->cap_key() => array( 'editor' => true ) ), 'user_capabilities' );

		delete_metadata( 'user', 0, $this->cap_key(), '', true );

		$this->assertFalse( wp_cache_get( self::$user_ids[0], 'user_capabilities' ) );
		$this->assertFalse( wp_cache_get( self::$user_ids[1], 'user_capabilities' ) );
	}

	/**
	 * @ticket 58001
	 *
	 * @covers ::_wp_clean_user_capabilities_cache_before_delete
	 */
	public function test_delete_all_with_other_object_id_clears_affected_user() {
		$user_id = self::factory()->user->create();
		delete_user_meta( $user_id, $this->cap_key() );
		wp_cache_set( self::$user_ids[1], array( $this->cap_key() => array( 'editor' => true ) ), 'user_capabilities' );

		delete_metadata( 'user', $user_id, $this->cap_key(), array( 'editor' => true ), true );

		$this->assertFalse( wp_cache_get( self::$user_ids[1], 'user_capabilities' ) );
	}

	/**
	 * @ticket 58001
	 *
	 * @covers ::_wp_clean_user_capabilities_cache_before_rename
	 */
	public function test_renaming_capabilities_key_by_mid_clears_cache() {
		global $wpdb;

		$user_id = self::$user_ids[1];
		$meta_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT umeta_id FROM $wpdb->usermeta WHERE user_id = %d AND meta_key = %s", $user_id, $this->cap_key() ) );
		wp_cache_set( $user_id, array( $this->cap_key() => array( 'editor' => true ) ), 'user_capabilities' );

		update_metadata_by_mid( 'user', $meta_id, array( 'editor' => true ), 'renamed_key' );

		$this->assertFalse( wp_cache_get( $user_id, 'user_capabilities' ) );
		$this->assertSame( '', get_user_meta( $user_id, $this->cap_key(), true ) );
	}

	/**
	 * @ticket 58001
	 *
	 * @covers ::clean_user_cache
	 */
	public function test_clean_user_cache_clears_capabilities() {
		wp_cache_set( self::$user_ids[0], array(), 'user_capabilities' );

		clean_user_cache( self::$user_ids[0] );

		$this->assertFalse( wp_cache_get( self::$user_ids[0], 'user_capabilities' ) );
	}

	/**
	 * @ticket 58001
	 *
	 * @covers ::wp_delete_user
	 */
	public function test_delete_user_clears_capabilities() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		if ( is_multisite() ) {
			wpmu_delete_user( $user_id );
		} else {
			wp_delete_user( $user_id );
		}

		$this->assertFalse( wp_cache_get( $user_id, 'user_capabilities' ) );
	}

	/**
	 * @ticket 58001
	 *
	 * @covers ::cache_users
	 */
	public function test_cache_users_allows_reading_capabilities_without_queries() {
		cache_users( self::$user_ids );

		$num_queries = get_num_queries();
		get_user_meta( self::$user_ids[0], $this->cap_key(), true );
		get_user_meta( self::$user_ids[1], $this->cap_key(), true );

		$this->assertSame( 0, get_num_queries() - $num_queries );
	}

	/**
	 * @ticket 58001
	 *
	 * @group ms-required
	 *
	 * @covers ::add_user_to_blog
	 * @covers ::remove_user_from_blog
	 */
	public function test_site_membership_changes_are_reflected() {
		global $wpdb;

		$site_id = self::factory()->blog->create();
		$user_id = self::$user_ids[1];
		$key     = $wpdb->get_blog_prefix( $site_id ) . 'capabilities';
		$this->flush_caches( array( $user_id ) );

		// Seed a partial entry, as the add/update hooks can create.
		wp_cache_set( $user_id, array( $key => array( 'subscriber' => true ) ), 'user_capabilities' );
		$this->assertSame( array( 'editor' => true ), get_user_meta( $user_id, $this->cap_key(), true ), 'Keys missing from a partial entry should be read from the database.' );

		add_user_to_blog( $site_id, $user_id, 'author' );
		$this->assertSame( array( 'author' => true ), get_user_meta( $user_id, $key, true ), 'Adding to a site should be reflected.' );
		$this->assertSame( array( 'editor' => true ), get_user_meta( $user_id, $this->cap_key(), true ), 'Main site capabilities should not be lost.' );

		remove_user_from_blog( $user_id, $site_id );
		$this->assertSame( '', get_user_meta( $user_id, $key, true ), 'Removing from a site should be reflected.' );

		wp_delete_site( $site_id );
	}

	/**
	 * @ticket 58001
	 *
	 * @group ms-required
	 *
	 * @covers ::wp_uninitialize_site
	 */
	public function test_deleting_site_clears_cache_of_members() {
		$site_id = self::factory()->blog->create();
		add_user_to_blog( $site_id, self::$user_ids[0], 'editor' );
		add_user_to_blog( $site_id, self::$user_ids[1], 'author' );

		wp_delete_site( $site_id );

		$this->assertFalse( wp_cache_get( self::$user_ids[0], 'user_capabilities' ) );
		$this->assertFalse( wp_cache_get( self::$user_ids[1], 'user_capabilities' ) );
	}
}
