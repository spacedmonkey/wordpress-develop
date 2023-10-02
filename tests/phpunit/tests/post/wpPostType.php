<?php

/**
 * @group post
 */
class Tests_Post_WP_Post_Type extends WP_UnitTestCase {
	public function test_instances() {
		global $wp_post_types;

		foreach ( $wp_post_types as $post_type ) {
			$this->assertInstanceOf( 'WP_Post_Type', $post_type );
		}
	}

	public function test_add_supports_defaults() {
		$post_type        = 'cpt';
		$post_type_object = new WP_Post_Type( $post_type );

		$post_type_object->add_supports();
		$post_type_supports = get_all_post_type_supports( $post_type );

		$post_type_object->remove_supports();
		$post_type_supports_after = get_all_post_type_supports( $post_type );

		$this->assertSameSets(
			array(
				'title'  => true,
				'editor' => true,
			),
			$post_type_supports
		);
		$this->assertSameSets( array(), $post_type_supports_after );
	}

	public function test_add_supports_custom() {
		$post_type        = 'cpt';
		$post_type_object = new WP_Post_Type(
			$post_type,
			array(
				'supports' => array(
					'editor',
					'comments',
					'revisions',
				),
			)
		);

		$post_type_object->add_supports();
		$post_type_supports = get_all_post_type_supports( $post_type );

		$post_type_object->remove_supports();
		$post_type_supports_after = get_all_post_type_supports( $post_type );

		$this->assertSameSets(
			array(
				'editor'    => true,
				'comments'  => true,
				'revisions' => true,
			),
			$post_type_supports
		);
		$this->assertSameSets( array(), $post_type_supports_after );
	}

	/**
	 * Test that supports can optionally receive nested args.
	 *
	 * @ticket 40413
	 */
	public function test_add_supports_custom_with_args() {
		$post_type        = 'cpt';
		$post_type_object = new WP_Post_Type(
			$post_type,
			array(
				'supports' => array(
					'support_with_args' => array(
						'arg1',
						'arg2',
					),
					'support_without_args',
				),
			)
		);

		$post_type_object->add_supports();
		$post_type_supports = get_all_post_type_supports( $post_type );

		$post_type_object->remove_supports();
		$post_type_supports_after = get_all_post_type_supports( $post_type );

		$this->assertSameSets(
			array(
				'support_with_args'    => array(
					array(
						'arg1',
						'arg2',
					),
				),
				'support_without_args' => true,
			),
			$post_type_supports
		);
		$this->assertSameSets( array(), $post_type_supports_after );
	}

	public function test_does_not_add_query_var_if_not_public() {
		$this->set_permalink_structure( '/%postname%' );

		/* @var WP $wp */
		global $wp;

		$post_type        = 'cpt';
		$post_type_object = new WP_Post_Type(
			$post_type,
			array(
				'rewrite'   => false,
				'query_var' => 'foobar',
			)
		);
		$post_type_object->add_rewrite_rules();

		$this->assertNotContains( 'foobar', $wp->public_query_vars );
	}

	public function test_adds_query_var_if_public() {
		$this->set_permalink_structure( '/%postname%' );

		/* @var WP $wp */
		global $wp;

		$post_type        = 'cpt';
		$post_type_object = new WP_Post_Type(
			$post_type,
			array(
				'public'    => true,
				'rewrite'   => false,
				'query_var' => 'foobar',
			)
		);

		$post_type_object->add_rewrite_rules();
		$in_array = in_array( 'foobar', $wp->public_query_vars, true );

		$post_type_object->remove_rewrite_rules();
		$in_array_after = in_array( 'foobar', $wp->public_query_vars, true );

		$this->assertTrue( $in_array );
		$this->assertFalse( $in_array_after );
	}

	public function test_adds_rewrite_rules() {
		$this->set_permalink_structure( '/%postname%' );

		/* @var WP_Rewrite $wp_rewrite */
		global $wp_rewrite;

		$post_type        = 'cpt';
		$post_type_object = new WP_Post_Type(
			$post_type,
			array(
				'public'  => true,
				'rewrite' => true,
			)
		);

		$post_type_object->add_rewrite_rules();
		$rewrite_tags = $wp_rewrite->rewritecode;

		$post_type_object->remove_rewrite_rules();
		$rewrite_tags_after = $wp_rewrite->rewritecode;

		$this->assertNotFalse( array_search( "%$post_type%", $rewrite_tags, true ) );
		$this->assertFalse( array_search( "%$post_type%", $rewrite_tags_after, true ) );
	}

	public function test_register_meta_boxes() {
		$post_type        = 'cpt';
		$post_type_object = new WP_Post_Type( $post_type, array( 'register_meta_box_cb' => '__return_false' ) );

		$post_type_object->register_meta_boxes();
		$has_action = has_action( "add_meta_boxes_$post_type", '__return_false' );
		$post_type_object->unregister_meta_boxes();
		$has_action_after = has_action( "add_meta_boxes_$post_type", '__return_false' );

		$this->assertSame( 10, $has_action );
		$this->assertFalse( $has_action_after );
	}

	public function test_adds_future_post_hook() {
		$post_type        = 'cpt';
		$post_type_object = new WP_Post_Type( $post_type );
		$post_type_object->add_hooks();
		$has_action = has_action( "future_$post_type", '_future_post_hook' );
		$post_type_object->remove_hooks();
		$has_action_after = has_action( "future_$post_type", '_future_post_hook' );

		$this->assertSame( 5, $has_action );
		$this->assertFalse( $has_action_after );
	}

	public function test_register_taxonomies() {
		global $wp_post_types;

		$post_type        = 'cpt';
		$post_type_object = new WP_Post_Type( $post_type, array( 'taxonomies' => array( 'post_tag' ) ) );

		$wp_post_types[ $post_type ] = $post_type_object;

		$post_type_object->register_taxonomies();
		$taxonomies = get_object_taxonomies( $post_type );
		$post_type_object->unregister_taxonomies();
		$taxonomies_after = get_object_taxonomies( $post_type );

		unset( $wp_post_types[ $post_type ] );

		$this->assertSameSets( array( 'post_tag' ), $taxonomies );
		$this->assertSameSets( array(), $taxonomies_after );
	}

	public function test_applies_registration_args_filters() {
		$post_type = 'cpt';
		$action    = new MockAction();

		add_filter( 'register_post_type_args', array( $action, 'filter' ) );
		add_filter( "register_{$post_type}_post_type_args", array( $action, 'filter' ) );

		new WP_Post_Type( $post_type );
		new WP_Post_Type( 'random' );

		$this->assertSame( 3, $action->get_call_count() );
	}

	/**
	 * @ticket 56922
	 *
	 * @dataProvider data_should_have_correct_custom_revisions_and_autosaves_controllers_properties
	 *
	 * @covers WP_Post_Type::set_props
	 *
	 * @param string      $property_name           Property name.
	 * @param string      $property_value          Property value.
	 * @param string|bool $expected_property_value Expected property value.
	 */
	public function test_should_have_correct_custom_revisions_and_autosaves_controllers_properties( $property_name, $property_value, $expected_property_value ) {
		$properties = null === $property_value ? array() : array( $property_name => $property_value );

		$post_type = new WP_Post_Type( 'wp_template', $properties );

		$this->assertObjectHasProperty( $property_name, $post_type, "The WP_Post_Type object does not have the expected {$property_name} property." );
		$this->assertSame(
			$expected_property_value,
			$post_type->$property_name,
			sprintf( 'Expected the property "%s" to have the %s value.', $property_name, var_export( $expected_property_value, true ) )
		);
	}

	/**
	 * Data provider for test_should_allow_to_set_custom_revisions_and_autosaves_controllers_properties.
	 *
	 * @return array[] Arguments {
	 *     @type string $property_name           Property name.
	 *     @type string $property_value          Property value.
	 *     @type string $expected_property_value Expected property value.
	 * }
	 */
	public function data_should_have_correct_custom_revisions_and_autosaves_controllers_properties() {
		return array(
			'autosave_rest_controller_class property'  => array(
				'autosave_rest_controller_class',
				'My_Custom_Template_Autosaves_Controller',
				'My_Custom_Template_Autosaves_Controller',
			),
			'autosave_rest_controller_class property (null value)' => array(
				'autosave_rest_controller_class',
				null,
				false,
			),
			'revisions_rest_controller_class property' => array(
				'revisions_rest_controller_class',
				'My_Custom_Template_Revisions_Controller',
				'My_Custom_Template_Revisions_Controller',
			),
			'revisions_rest_controller_class property (null value)' => array(
				'revisions_rest_controller_class',
				null,
				false,
			),
		);
	}
}
