<?php

/**
 * Tests for the user capabilities cache.
 *
 * @group user
 * @group capabilities
 * @group meta
 *
 * @covers ::update_user_capabilities_cache
 * @covers ::_wp_get_user_capabilities_meta
 */
class Tests_User_CapabilitiesCache extends WP_UnitTestCase {

	/**
	 * User IDs keyed by role.
	 *
	 * @var int[]
	 */
	protected static $users = array();

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		foreach ( array( 'administrator', 'editor', 'author', 'subscriber' ) as $role ) {
			self::$users[ $role ] = $factory->user->create( array( 'role' => $role ) );
		}
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
	 * Clears the user meta and user capabilities caches, keeping the users cache warm.
	 *
	 * @param int[] $user_ids User IDs.
	 */
	private function clear_meta_caches( array $user_ids ) {
		cache_users( $user_ids );
		$this->reset_lazyload_queue();
		wp_cache_delete_multiple( $user_ids, 'user_meta' );
		wp_cache_delete_multiple( $user_ids, 'user_capabilities' );
	}

	/**
	 * @ticket 58001
	 */
	public function test_primes_multiple_users_with_one_query() {
		$user_ids = array_values( self::$users );
		$this->clear_meta_caches( $user_ids );

		$queries = get_num_queries();
		$loaded  = update_user_capabilities_cache( $user_ids );

		$this->assertSame( 1, get_num_queries() - $queries, 'Capabilities for all users should be loaded in a single query.' );
		$this->assertSame( array(), $loaded, 'No user meta should be fully loaded.' );

		foreach ( self::$users as $role => $user_id ) {
			$this->assertSame(
				array( $this->cap_key() => array( $role => true ) ),
				wp_cache_get( $user_id, 'user_capabilities' ),
				"The capabilities of the {$role} should be cached."
			);
			$this->assertFalse( wp_cache_get( $user_id, 'user_meta' ), 'All user meta should not be loaded.' );
		}

		$queries = get_num_queries();
		update_user_capabilities_cache( $user_ids );
		$this->assertSame( 0, get_num_queries() - $queries, 'Cached capabilities should not be queried again.' );
	}

	/**
	 * @ticket 58001
	 */
	public function test_derives_capabilities_from_cached_user_meta() {
		$user_ids = array_values( self::$users );
		$this->clear_meta_caches( $user_ids );
		update_meta_cache( 'user', $user_ids );
		wp_cache_delete_multiple( $user_ids, 'user_capabilities' );

		$queries = get_num_queries();
		$loaded  = update_user_capabilities_cache( $user_ids );

		$this->assertSame( 0, get_num_queries() - $queries, 'Capabilities should be derived from the user meta cache.' );
		$this->assertSameSets( $user_ids, $loaded, 'Users with cached meta should be reported as loaded.' );
		$this->assertSame(
			array( $this->cap_key() => array( 'editor' => true ) ),
			wp_cache_get( self::$users['editor'], 'user_capabilities' )
		);
	}

	/**
	 * @ticket 58001
	 */
	public function test_single_user_loads_all_user_meta() {
		$user_id = self::$users['editor'];
		$this->clear_meta_caches( array( $user_id ) );

		$queries = get_num_queries();
		$loaded  = update_user_capabilities_cache( array( $user_id ) );

		$this->assertSame( 1, get_num_queries() - $queries );
		$this->assertSame( array( $user_id ), $loaded );
		$this->assertIsArray( wp_cache_get( $user_id, 'user_meta' ), 'All user meta should be loaded for a single user.' );
		$this->assertSame(
			array( $this->cap_key() => array( 'editor' => true ) ),
			wp_cache_get( $user_id, 'user_capabilities' )
		);
	}

	/**
	 * @ticket 58001
	 */
	public function test_user_without_capabilities_is_cached_as_empty_array() {
		$user_id = self::factory()->user->create();
		delete_user_meta( $user_id, $this->cap_key() );

		$user_ids = array( $user_id, self::$users['editor'] );
		$this->clear_meta_caches( $user_ids );

		update_user_capabilities_cache( $user_ids );
		$this->assertSame( array(), wp_cache_get( $user_id, 'user_capabilities' ) );

		$queries = get_num_queries();
		update_user_capabilities_cache( $user_ids );
		$this->assertSame( 0, get_num_queries() - $queries, 'An empty capabilities cache entry should not be queried again.' );
	}

	/**
	 * @ticket 58001
	 */
	public function test_invalid_cached_value_is_replaced() {
		$user_ids = array( self::$users['editor'], self::$users['author'] );
		$this->clear_meta_caches( $user_ids );
		wp_cache_set( self::$users['editor'], 'invalid', 'user_capabilities' );

		update_user_capabilities_cache( $user_ids );

		$this->assertSame(
			array( $this->cap_key() => array( 'editor' => true ) ),
			wp_cache_get( self::$users['editor'], 'user_capabilities' )
		);
	}

	/**
	 * @ticket 58001
	 */
	public function test_respects_cache_addition_suspension() {
		$user_ids = array( self::$users['editor'], self::$users['author'] );
		$this->clear_meta_caches( $user_ids );

		wp_suspend_cache_addition( true );
		update_user_capabilities_cache( $user_ids );
		wp_suspend_cache_addition( false );

		$this->assertFalse( wp_cache_get( self::$users['editor'], 'user_capabilities' ) );
	}

	/**
	 * @ticket 58001
	 */
	public function test_respects_update_user_metadata_cache_short_circuit() {
		$user_ids = array( self::$users['editor'], self::$users['author'] );
		$this->clear_meta_caches( $user_ids );
		add_filter( 'update_user_metadata_cache', '__return_true' );

		$queries = get_num_queries();
		update_user_capabilities_cache( $user_ids );

		$this->assertSame( 0, get_num_queries() - $queries );
		$this->assertFalse( wp_cache_get( self::$users['editor'], 'user_capabilities' ) );
	}

	/**
	 * @ticket 58001
	 */
	public function test_null_returning_update_user_metadata_cache_filter_does_not_disable_priming() {
		$user_ids = array( self::$users['editor'], self::$users['author'] );
		$this->clear_meta_caches( $user_ids );

		$filter = new MockAction();
		add_filter( 'update_user_metadata_cache', array( $filter, 'filter' ), 10, 2 );

		update_user_capabilities_cache( $user_ids );

		$this->assertSame( 1, $filter->get_call_count() );
		$this->assertIsArray( wp_cache_get( self::$users['editor'], 'user_capabilities' ) );
	}

	/**
	 * @ticket 58001
	 */
	public function test_capabilities_meta_key_is_matched_case_sensitively() {
		global $wpdb;

		$user_ids = array( self::$users['editor'], self::$users['author'] );
		add_user_meta( self::$users['editor'], $wpdb->get_blog_prefix() . 'Capabilities', array( 'administrator' => true ) );
		$this->clear_meta_caches( $user_ids );

		update_user_capabilities_cache( $user_ids );

		$this->assertSame(
			array( $this->cap_key() => array( 'editor' => true ) ),
			wp_cache_get( self::$users['editor'], 'user_capabilities' )
		);
	}

	/**
	 * @ticket 58001
	 */
	public function test_full_user_meta_load_primes_capabilities_cache() {
		$user_ids = array_values( self::$users );
		$this->clear_meta_caches( $user_ids );

		update_meta_cache( 'user', $user_ids );

		foreach ( self::$users as $role => $user_id ) {
			$this->assertSame(
				array( $this->cap_key() => array( $role => true ) ),
				wp_cache_get( $user_id, 'user_capabilities' )
			);
		}

		$queries = get_num_queries();
		foreach ( $user_ids as $user_id ) {
			new WP_User( $user_id );
		}
		$this->assertSame( 0, get_num_queries() - $queries, 'Users should be set up without further queries.' );
	}

	/**
	 * @ticket 58001
	 */
	public function test_full_user_meta_load_replaces_stale_capabilities() {
		$user_id = self::$users['editor'];
		$this->clear_meta_caches( array( $user_id ) );
		wp_cache_set( $user_id, array( $this->cap_key() => array( 'administrator' => true ) ), 'user_capabilities' );

		update_meta_cache( 'user', array( $user_id ) );

		$this->assertSame(
			array( $this->cap_key() => array( 'editor' => true ) ),
			wp_cache_get( $user_id, 'user_capabilities' )
		);
	}

	/**
	 * @ticket 58001
	 */
	public function test_cache_users_does_not_load_all_user_meta() {
		$user_ids = array_values( self::$users );
		$this->clear_meta_caches( $user_ids );

		cache_users( $user_ids );

		foreach ( self::$users as $role => $user_id ) {
			$user = new WP_User( $user_id );
			$this->assertSame( array( $role ), $user->roles );
			$this->assertTrue( $user->has_cap( $role ) );
			$this->assertFalse( wp_cache_get( $user_id, 'user_meta' ), 'All user meta should not be loaded.' );

			$vars = get_object_vars( $user );
			$this->assertSame( array( $role ), $vars['roles'], 'Roles should be included in the object vars.' );
			$this->assertSame( array( $role => true ), $vars['caps'], 'Capabilities should be included in the object vars.' );
			$this->assertStringContainsString( '"roles":["' . $role . '"]', wp_json_encode( $user ) );
		}
	}

	/**
	 * @ticket 58001
	 */
	public function test_new_user_without_cache_loads_all_user_meta_in_one_query() {
		$user_id = self::$users['author'];
		$this->clear_meta_caches( array( $user_id ) );

		$queries = get_num_queries();
		$user    = new WP_User( $user_id );

		$this->assertSame( 1, get_num_queries() - $queries );
		$this->assertSame( array( 'author' ), $user->roles );
		$this->assertIsArray( wp_cache_get( $user_id, 'user_meta' ) );
		$this->assertIsArray( wp_cache_get( $user_id, 'user_capabilities' ) );
	}

	/**
	 * @ticket 58001
	 *
	 * @dataProvider data_invalidation
	 *
	 * @param callable $callback Callback that modifies the user.
	 */
	public function test_invalidation( $callback ) {
		$user_id  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user_ids = array( $user_id, self::$users['author'] );
		$this->clear_meta_caches( $user_ids );
		update_user_capabilities_cache( $user_ids );
		$this->assertIsArray( wp_cache_get( $user_id, 'user_capabilities' ), 'The capabilities cache should be primed.' );

		// Use a sentinel value which the write must remove.
		$stale = array_merge( wp_cache_get( $user_id, 'user_capabilities' ), array( 'wptests_stale_capabilities' => array( 'administrator' => true ) ) );
		wp_cache_set( $user_id, $stale, 'user_capabilities' );

		$callback( $user_id, $this->cap_key() );

		$cached = wp_cache_get( $user_id, 'user_capabilities' );
		if ( false !== $cached ) {
			// The cache may have been repopulated after the write, it must match the database.
			$this->assertSame( $this->get_capabilities_from_database( $user_id ), $cached, 'The capabilities cache should not be stale.' );
		}

		update_user_capabilities_cache( array( $user_id, self::$users['author'] ) );
		$this->assertSame(
			$this->get_capabilities_from_database( $user_id ),
			wp_cache_get( $user_id, 'user_capabilities' ),
			'The primed capabilities should match the database.'
		);
	}

	/**
	 * Reads a user's capabilities from the database.
	 *
	 * @param int $user_id User ID.
	 * @return array Capabilities keyed by meta key.
	 */
	private function get_capabilities_from_database( $user_id ) {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM $wpdb->usermeta WHERE user_id = %d ORDER BY umeta_id ASC", $user_id ) );
		$caps = array();
		foreach ( $rows as $row ) {
			if ( str_ends_with( $row->meta_key, 'capabilities' ) && ! array_key_exists( $row->meta_key, $caps ) ) {
				$caps[ $row->meta_key ] = maybe_unserialize( $row->meta_value );
			}
		}

		return $caps;
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public function data_invalidation() {
		return array(
			'update_user_meta'             => array(
				static function ( $user_id, $cap_key ) {
					update_user_meta( $user_id, $cap_key, array( 'author' => true ) );
				},
			),
			'add_user_meta'                => array(
				static function ( $user_id, $cap_key ) {
					add_user_meta( $user_id, 'wptests_other_capabilities', array( 'author' => true ) );
				},
			),
			'delete_user_meta'             => array(
				static function ( $user_id, $cap_key ) {
					delete_user_meta( $user_id, $cap_key );
				},
			),
			'delete_metadata delete_all'   => array(
				static function ( $user_id, $cap_key ) {
					delete_metadata( 'user', 0, $cap_key, '', true );
				},
			),
			'update_metadata_by_mid'       => array(
				static function ( $user_id, $cap_key ) {
					global $wpdb;
					$wpdb->insert(
						$wpdb->usermeta,
						array(
							'user_id'    => $user_id,
							'meta_key'   => 'wptests_meta',
							'meta_value' => 'value',
						)
					);
					$mid = $wpdb->insert_id;
					update_metadata_by_mid( 'user', $mid, array( 'author' => true ), 'wptests_2_capabilities' );
				},
			),
			'delete_metadata_by_mid'       => array(
				static function ( $user_id, $cap_key ) {
					global $wpdb;
					$mid = $wpdb->get_var( $wpdb->prepare( "SELECT umeta_id FROM $wpdb->usermeta WHERE user_id = %d AND meta_key = %s", $user_id, $cap_key ) );
					delete_metadata_by_mid( 'user', $mid );
				},
			),
			'clean_user_cache'             => array(
				static function ( $user_id ) {
					clean_user_cache( $user_id );
				},
			),
			'WP_User::set_role'            => array(
				static function ( $user_id ) {
					( new WP_User( $user_id ) )->set_role( 'author' );
				},
			),
			'WP_User::add_role'            => array(
				static function ( $user_id ) {
					( new WP_User( $user_id ) )->add_role( 'author' );
				},
			),
			'WP_User::remove_role'         => array(
				static function ( $user_id ) {
					( new WP_User( $user_id ) )->remove_role( 'editor' );
				},
			),
			'WP_User::add_cap'             => array(
				static function ( $user_id ) {
					( new WP_User( $user_id ) )->add_cap( 'wptests_cap' );
				},
			),
			'WP_User::remove_cap'          => array(
				static function ( $user_id ) {
					( new WP_User( $user_id ) )->remove_cap( 'editor' );
				},
			),
			'WP_User::remove_all_caps'     => array(
				static function ( $user_id ) {
					( new WP_User( $user_id ) )->remove_all_caps();
				},
			),
			'wp_update_user with new role' => array(
				static function ( $user_id ) {
					wp_update_user(
						array(
							'ID'   => $user_id,
							'role' => 'author',
						)
					);
				},
			),
		);
	}

	/**
	 * @ticket 58001
	 *
	 * @expectedDeprecated update_usermeta
	 * @expectedDeprecated delete_usermeta
	 */
	public function test_deprecated_usermeta_functions_invalidate() {
		$user_id  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user_ids = array( $user_id, self::$users['author'] );

		$this->clear_meta_caches( $user_ids );
		update_user_capabilities_cache( $user_ids );
		update_usermeta( $user_id, $this->cap_key(), array( 'author' => true ) );
		$this->assertFalse( wp_cache_get( $user_id, 'user_capabilities' ) );

		$this->clear_meta_caches( $user_ids );
		update_user_capabilities_cache( $user_ids );
		delete_usermeta( $user_id, $this->cap_key() );
		$this->assertFalse( wp_cache_get( $user_id, 'user_capabilities' ) );
	}

	/**
	 * @ticket 58001
	 */
	public function test_role_change_is_reflected_after_priming() {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user_ids = array( $user_id, self::$users['author'] );
		$this->clear_meta_caches( $user_ids );
		cache_users( $user_ids );

		( new WP_User( $user_id ) )->set_role( 'subscriber' );

		$user = new WP_User( $user_id );
		$this->assertSame( array( 'subscriber' ), $user->roles );
		$this->assertFalse( $user->has_cap( 'manage_options' ) );
	}

	/**
	 * @ticket 58001
	 */
	public function test_invalid_user_id_returns_false_without_queries() {
		$queries = get_num_queries();

		$this->assertFalse( _wp_get_user_capabilities_meta( 0, $this->cap_key() ) );
		$this->assertFalse( _wp_get_user_capabilities_meta( 'abc', $this->cap_key() ) );
		$this->assertSame( 0, get_num_queries() - $queries );
	}

	/**
	 * @ticket 58001
	 */
	public function test_get_user_metadata_filter_overrides_capabilities() {
		$user_id = self::$users['subscriber'];
		$cap_key = $this->cap_key();
		$filter  = new MockAction();

		add_filter( 'get_user_metadata', array( $filter, 'filter' ), 10, 5 );
		add_filter(
			'get_user_metadata',
			static function ( $value, $object_id, $meta_key ) use ( $user_id, $cap_key ) {
				if ( $user_id === $object_id && $cap_key === $meta_key ) {
					return array( array( 'editor' => true ) );
				}
				return $value;
			},
			10,
			3
		);

		$user = new WP_User( $user_id );

		$this->assertSame( array( 'editor' ), $user->roles );
		$this->assertSame( 1, $filter->get_call_count(), 'The get_user_metadata filter should run once.' );
	}

	/**
	 * @ticket 58001
	 */
	public function test_default_user_metadata_is_applied() {
		$user_id = self::factory()->user->create();
		delete_user_meta( $user_id, $this->cap_key() );

		$cap_key = $this->cap_key();
		add_filter(
			'default_user_metadata',
			static function ( $value, $object_id, $meta_key ) use ( $user_id, $cap_key ) {
				if ( $user_id === $object_id && $cap_key === $meta_key ) {
					return array( 'author' => true );
				}
				return $value;
			},
			10,
			3
		);

		$this->assertSame( array( 'author' => true ), _wp_get_user_capabilities_meta( $user_id, $cap_key ) );
	}

	/**
	 * @ticket 58001
	 */
	public function test_other_meta_keys_fall_through_to_get_user_meta() {
		$user_id = self::$users['editor'];
		update_user_meta( $user_id, 'wptests_meta', 'value' );

		$this->assertSame( 'value', _wp_get_user_capabilities_meta( $user_id, 'wptests_meta' ) );
	}

	/**
	 * @ticket 58001
	 */
	public function test_reading_capabilities_does_not_load_queued_user_meta() {
		$user_ids = array_values( self::$users );
		$this->clear_meta_caches( $user_ids );

		cache_users( $user_ids );
		foreach ( $user_ids as $user_id ) {
			new WP_User( $user_id );
		}

		// Reading capabilities directly loads only that user's meta.
		get_user_meta( $user_ids[0], $this->cap_key(), true );

		foreach ( array_slice( $user_ids, 1 ) as $user_id ) {
			$this->assertFalse( wp_cache_get( $user_id, 'user_meta' ), 'Reading capabilities should not load the queued user meta.' );
		}

		$queries = get_num_queries();
		get_user_meta( $user_ids[1], 'nickname', true );
		$this->assertSame( 1, get_num_queries() - $queries, 'All queued user meta should be loaded in a single query.' );

		foreach ( $user_ids as $user_id ) {
			$this->assertIsArray( wp_cache_get( $user_id, 'user_meta' ) );
		}
	}

	/**
	 * @ticket 58001
	 */
	public function test_wp_user_query_primes_all_user_meta() {
		$user_ids = array_values( self::$users );
		$this->clear_meta_caches( $user_ids );

		new WP_User_Query( array( 'include' => $user_ids ) );

		foreach ( $user_ids as $user_id ) {
			$this->assertIsArray( wp_cache_get( $user_id, 'user_meta' ) );
			$this->assertIsArray( wp_cache_get( $user_id, 'user_capabilities' ) );
		}
	}

	/**
	 * @ticket 58001
	 */
	public function test_user_capabilities_is_a_global_cache_group() {
		global $wp_object_cache;

		if ( ! isset( $wp_object_cache->global_groups ) ) {
			$this->markTestSkipped( 'The object cache does not expose global groups.' );
		}

		$this->assertArrayHasKey( 'user_capabilities', $wp_object_cache->global_groups );
	}

	/**
	 * @ticket 58001
	 * @group ms-required
	 */
	public function test_for_site_reads_other_site_capabilities_from_cache() {
		$site_id = self::factory()->blog->create();
		$user_id = self::$users['subscriber'];
		add_user_to_blog( $site_id, $user_id, 'editor' );

		$user_ids = array( $user_id, self::$users['author'] );
		$this->clear_meta_caches( $user_ids );
		update_meta_cache( 'user', $user_ids );
		$this->reset_lazyload_queue();
		wp_cache_delete_multiple( $user_ids, 'user_meta' );

		$queries = get_num_queries();
		$user    = new WP_User( $user_id, '', $site_id );

		$this->assertSame( 0, get_num_queries() - $queries, 'Capabilities for another site should be read from the cache.' );
		$this->assertSame( array( 'editor' ), $user->roles );

		$user->for_site( get_current_blog_id() );
		$this->assertSame( array( 'subscriber' ), $user->roles );
	}

	/**
	 * @ticket 58001
	 * @group ms-required
	 */
	public function test_set_role_on_other_site_without_switching_invalidates_cache() {
		$site_id = self::factory()->blog->create();
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		add_user_to_blog( $site_id, $user_id, 'administrator' );

		$user_ids = array( $user_id, self::$users['author'] );
		$this->clear_meta_caches( $user_ids );
		cache_users( $user_ids );

		$user = new WP_User( $user_id, '', $site_id );
		$this->assertTrue( $user->has_cap( 'manage_options' ) );

		$user->set_role( 'subscriber' );

		$user = new WP_User( $user_id, '', $site_id );
		$this->assertSame( array( 'subscriber' ), $user->roles );
		$this->assertFalse( $user->has_cap( 'manage_options' ) );
	}

	/**
	 * @ticket 58001
	 * @group ms-required
	 */
	public function test_remove_user_from_blog_invalidates_cache() {
		$site_id = self::factory()->blog->create();
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		add_user_to_blog( $site_id, $user_id, 'editor' );

		$user_ids = array( $user_id, self::$users['author'] );
		$this->clear_meta_caches( $user_ids );
		cache_users( $user_ids );
		$this->assertTrue( is_user_member_of_blog( $user_id, $site_id ) );

		remove_user_from_blog( $user_id, $site_id );

		$this->assertFalse( is_user_member_of_blog( $user_id, $site_id ) );
		$this->assertSame( array(), ( new WP_User( $user_id, '', $site_id ) )->roles );
	}

	/**
	 * @ticket 58001
	 * @group ms-required
	 */
	public function test_wpmu_delete_user_invalidates_cache() {
		$site_id = self::factory()->blog->create();
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		add_user_to_blog( $site_id, $user_id, 'editor' );
		update_user_capabilities_cache( array( $user_id, self::$users['author'] ) );

		require_once ABSPATH . 'wp-admin/includes/ms.php';
		wpmu_delete_user( $user_id );

		$this->assertFalse( wp_cache_get( $user_id, 'user_capabilities' ) );
	}

	/**
	 * @ticket 58001
	 * @group ms-required
	 */
	public function test_switch_to_blog_does_not_query_capabilities() {
		$site_id = self::factory()->blog->create();
		add_user_to_blog( $site_id, self::$users['editor'], 'author' );

		foreach ( array( 0, self::$users['editor'] ) as $current_user_id ) {
			wp_set_current_user( $current_user_id );

			// Warm the per-site caches.
			switch_to_blog( $site_id );
			restore_current_blog();

			$queries = get_num_queries();
			switch_to_blog( $site_id );
			restore_current_blog();
			$this->assertSame( 0, get_num_queries() - $queries, "Switching sites should not query for user {$current_user_id}." );
		}
	}

	/**
	 * @ticket 58001
	 * @group ms-required
	 */
	public function test_get_blogs_of_user_is_unchanged() {
		$site_id = self::factory()->blog->create();
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		add_user_to_blog( $site_id, $user_id, 'editor' );

		$user_ids = array( $user_id, self::$users['author'] );
		$this->clear_meta_caches( $user_ids );
		cache_users( $user_ids );

		$this->assertSameSets( array( get_current_blog_id(), $site_id ), array_keys( get_blogs_of_user( $user_id ) ) );
	}
}
