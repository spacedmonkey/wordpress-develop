<?php

/**
 * Extends the WP_User class to provide lazy loading of capabilities.
 *
 * @since 6.9.0
 *
 * @property string $nickname
 * @property string $description
 * @property string $user_description
 * @property string $first_name
 * @property string $user_firstname
 * @property string $last_name
 * @property string $user_lastname
 * @property string $user_login
 * @property string $user_pass
 * @property string $user_nicename
 * @property string $user_email
 * @property string $user_url
 * @property string $user_registered
 * @property string $user_activation_key
 * @property string $user_status
 * @property int    $user_level
 * @property string $display_name
 * @property string $spam
 * @property string $deleted
 * @property string $locale
 * @property string $rich_editing
 * @property string $syntax_highlighting
 * @property string $use_ssl
 */
#[AllowDynamicProperties]
class WP_Author extends WP_User implements JsonSerializable {

    /**
     * Capabilities that the individual user has been granted outside of those inherited from their role.
     *
     * @since 2.0.0
     * @var array<string, bool>|null Array of key/value pairs where keys represent a capability name
     *                               and boolean values represent whether the user has that capability.
     */
    protected $caps = null;

    /**
     * The roles the user is part of.
     *
     * @since 6.9.0
     * @var string[]
     */
    protected $roles = array();

    /**
     * All capabilities the user has, including individual and role based.
     *
     * @since 6.9.0
     * @var bool[] Array of key/value pairs where keys represent a capability name
     *             and boolean values represent whether the user has that capability.
     */
    protected $allcaps = array();

	/**
	 * Loads capability data if it has not been loaded yet.
	 *
	 * @since 6.9.0
	 */
	private function load_capability_data() {
		if ( isset( $this->caps ) ) {
			return;
		}
		$this->caps = $this->get_caps_data();
		$this->get_role_caps();
	}

	/**
	 * Magic method for setting custom user fields.
	 *
	 * This method ensures that capability-related properties are loaded
	 *
	 * @since 6.9.0
	 *
	 * @param string $key   User meta key.
	 * @param mixed  $value User meta value.
	 */
	public function __set( $key, $value ) {
		// Ensure capability data is loaded before setting related properties.
		if ( in_array( $key, array( 'caps', 'allcaps', 'roles' ), true ) ) {
			$this->load_capability_data();
			$this->$key = $value;
			return;
		}
		parent::__set( $key, $value );
	}

	/**
	 * Magic method for accessing custom fields.
	 *
	 * This method ensures that capability-related properties are loaded
	 *
	 * @since 6.9.0
	 *
	 * @param string $key User meta key to retrieve.
	 * @return mixed Value of the given user meta key (if set). If `$key` is 'id', the user ID.
	 */
	public function __get( $key ) {
		if ( in_array( $key, array( 'caps', 'allcaps', 'roles' ), true ) ) {
			$this->load_capability_data();
			return $this->$key;
		}
		return parent::__get( $key );
	}

	public function __isset( $key ) {
		if ( in_array( $key, array( 'caps', 'allcaps', 'roles' ), true ) ) {
			return true;
		}
		return parent::__isset( $key );
	}

	/**
	 * Magic method for unsetting a certain custom field.
	 *
	 * This method ensures that capability-related properties are loaded
	 *
	 * @param string $key User meta key to unset.
	 */
	public function __unset( $key ) {
		if ( in_array( $key, array( 'caps', 'allcaps', 'roles' ), true ) ) {
			$this->$key = null;
		}
		parent::__unset( $key );
	}

	/**
	 * Prepares the object for serialization.
	 *
	 * Loads capability data if not already loaded, then returns an array
	 *
	 * @since 6.9.0
	 *
	 * @return array The user data array.
	 */
	public function __serialize() {
		$this->load_capability_data();
		return array(
			'data'    => $this->data,
			'ID'      => $this->ID,
			'caps'    => $this->caps,
			'cap_key' => $this->cap_key,
			'roles'   => $this->roles,
			'allcaps' => $this->allcaps,
			'filter'  => $this->filter,
			'site_id' => $this->site_id,
		);
	}

	/**
	 * Specify data which should be serialized to JSON.
	 *
	 * @since 6.9.0
	 *
	 * @return array Data for JSON serialization.
	 */
	public function jsonSerialize() {
		return $this->__serialize();
	}

	/**
	 * Removes all of the capabilities of the user.
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @since 6.9.0
	 */
	public function remove_all_caps() {
		global $wpdb;
		$this->caps = null;
		delete_user_meta( $this->ID, $this->cap_key );
		delete_user_meta( $this->ID, $wpdb->get_blog_prefix() . 'user_level' );
		$this->load_capability_data();
	}

	/**
	 * Sets the site context for the user.
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @since 6.9.0
	 *
	 * @param int $site_id Site ID. Default is 0, which means the current site.
	 */
	public function for_site( $site_id = 0 ) {
		global $wpdb;

		if ( ! empty( $site_id ) ) {
			$this->site_id = absint( $site_id );
		} else {
			$this->site_id = get_current_blog_id();
		}

		$this->cap_key = $wpdb->get_blog_prefix( $this->site_id ) . 'capabilities';
		$this->caps    = null;
	}

	/**
	 * Checks if the user has a specific capability.
	 *
	 * Loads capability data if not already loaded.
	 *
	 * @since 6.9.0
	 *
	 * @param string $cap Capability name.
	 * @param mixed ...$args Optional further parameters, typically starting with an object ID.
	 * @return bool Whether the user has the given capability, or, if an object ID is passed, whether the user has
	 *               the given capability for that object.
	 */
	public function has_cap( $cap, ...$args ) {
		$this->load_capability_data();
		return parent::has_cap( $cap, ...$args );
	}

	/**
	 * Removes capability from user
	 *
	 * Loads capability data if not already loaded.
	 *
	 * @since 6.9.0
	 *
	 * @param string $cap Capability name.
	 */
	public function remove_cap( $cap ) {
		$this->load_capability_data();
		parent::remove_cap( $cap );
	}

	/**
	 * Adds capability and grant or deny access to capability.
	 *
	 * Loads capability data if not already loaded.
	 *
	 * @since 6.9.0
	 *
	 * @param string $cap   Capability name.
	 * @param bool   $grant Whether to grant capability to user.
	 */
	public function add_cap( $cap, $grant = true ) {
		$this->load_capability_data();
		parent::add_cap( $cap, $grant );
	}

	/**
	 * Sets the role of the user.
	 *
	 * Loads capability data if not already loaded.
	 *
	 * @since 2.0.0
	 *
	 * @param string $role Role name.
	 */
	public function set_role( $role ) {
		$this->load_capability_data();
		parent::set_role( $role );
	}

	/**
	 * Adds role to user.
	 *
	 * Loads capability data if not already loaded.
	 *
	 * @since 6.9.0
	 *
	 * @param string $role Role name.
	 */
	public function add_role( $role ) {
		if ( empty( $role ) ) {
			return;
		}
		$this->load_capability_data();
		parent::add_role( $role );
	}

	/**
	 * Removes role from user.
	 *
	 * Loads capability data if not already loaded.
	 *
	 * @since 6.9.0
	 *
	 * @param string $role Role name.
	 */
	public function remove_role( $role ) {
		$this->load_capability_data();
		parent::remove_role( $role );
	}
}
