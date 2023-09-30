<?php
/**
 * Class WP_Block_Patterns_Processor
 *
 * Process and manage WordPress block patterns.
 *
 * @since 6.4.0
 */
class WP_Block_Patterns_Processor {

	/**
	 * Default headers for block patterns.
	 *
	 * @var array
	 * @since 6.4.0
	 */
	protected $default_headers = array(
		'title'         => 'Title',
		'slug'          => 'Slug',
		'description'   => 'Description',
		'viewportWidth' => 'Viewport Width',
		'inserter'      => 'Inserter',
		'categories'    => 'Categories',
		'keywords'      => 'Keywords',
		'blockTypes'    => 'Block Types',
		'postTypes'     => 'Post Types',
		'templateTypes' => 'Template Types',
	);

	/**
	 * Properties to parse as arrays.
	 *
	 * @var array
	 * @since 6.4.0
	 */
	protected $properties_to_parse = array(
		'categories',
		'keywords',
		'blockTypes',
		'postTypes',
		'templateTypes',
	);

	/**
	 * Name of the option for caching.
	 *
	 * @var string
	 * @since 6.4.0
	 */
	protected $option_name;

	/**
	 * Theme version.
	 *
	 * @var string
	 * @since 6.4.0
	 */
	protected $version;

	/**
	 * Whether cached data can be used.
	 *
	 * @var bool
	 * @since 6.4.0
	 */
	protected $can_use_cached;

	/**
	 * Directory path where block patterns are stored.
	 *
	 * @var string
	 * @since 6.4.0
	 */
	public $dirpath;

	/**
	 * WP_Block_Patterns_Processor constructor.
	 *
	 * @param WP_Theme $theme The WordPress theme.
	 *
	 * @since 6.4.0
	 */
	public function __construct( WP_Theme $theme ) {
		$this->option_name    = 'wp_theme_patterns__' . $theme->get_stylesheet();
		$this->version        = $theme->get( 'Version' );
		$this->can_use_cached = ! wp_is_development_mode( 'theme' );
		$this->dirpath        = $theme->get_stylesheet_directory() . '/patterns/';
	}

	/**
	 * Get the block patterns.
	 *
	 * @return array The block patterns data.
	 *
	 * @since 6.4.0
	 */
	public function get_patterns() {
		if ( $this->can_use_cached ) {
			$pattern_data = get_site_option( $this->option_name );
			if ( is_array( $pattern_data ) && $pattern_data['version'] === $this->version ) {
				return $pattern_data;
			}
		}

		$pattern_data = array(
			'version'  => $this->version,
			'patterns' => array(),
		);

		if ( ! file_exists( $this->dirpath ) ) {
			$this->save_cache( $pattern_data );
			return $pattern_data;
		}

		$files = glob( $this->dirpath . '*.php' );
		if ( ! $files ) {
			$this->save_cache( $pattern_data );
			return $pattern_data;
		}

		foreach ( $files as $file ) {
			$pattern = get_file_data( $file, $this->default_headers );

			if ( empty( $pattern['slug'] ) ) {
				_doing_it_wrong(
					'_register_theme_block_patterns',
					sprintf(
					/* translators: %s: file name. */
						__( 'Could not register file "%s" as a block pattern ("Slug" field missing)' ),
						$file
					),
					'6.0.0'
				);
				continue;
			}

			if ( ! preg_match( '/^[A-z0-9\/_-]+$/', $pattern['slug'] ) ) {
				_doing_it_wrong(
					'_register_theme_block_patterns',
					sprintf(
					/* translators: %1s: file name; %2s: slug value found. */
						__( 'Could not register file "%1$s" as a block pattern (invalid slug "%2$s")' ),
						$file,
						$pattern['slug']
					),
					'6.0.0'
				);
			}

			// Title is a required property.
			if ( empty( $pattern['title'] ) ) {
				_doing_it_wrong(
					'_register_theme_block_patterns',
					sprintf(
					/* translators: %1s: file name; %2s: slug value found. */
						__( 'Could not register file "%1$s" as a block pattern ("Title" field missing)' ),
						$file
					),
					'6.0.0'
				);
				continue;
			}

			// For properties of type array, parse data as comma-separated.
			foreach ( $this->properties_to_parse as $property ) {
				if ( ! empty( $pattern[ $property ] ) ) {
					$pattern[ $property ] = array_filter( wp_parse_list( (string) $pattern[ $property ] ) );
				} else {
					unset( $pattern[ $property ] );
				}
			}

			// Parse properties of type int.
			$property = 'viewportWidth';
			if ( ! empty( $pattern[ $property ] ) ) {
				$pattern[ $property ] = (int) $pattern[ $property ];
			} else {
				unset( $pattern[ $property ] );
			}

			// Parse properties of type bool.
			$property = 'inserter';
			if ( ! empty( $pattern[ $property ] ) ) {
				$pattern[ $property ] = in_array(
					strtolower( $pattern[ $property ] ),
					array( 'yes', 'true' ),
					true
				);
			} else {
				unset( $pattern[ $property ] );
			}

			$key = str_replace( $this->dirpath, '', $file );

			$pattern_data['patterns'][ $key ] = $pattern;
		}

		$this->save_cache( $pattern_data );

		return $pattern_data;
	}

	/**
	 * Save the block patterns data to cache.
	 *
	 * @param array $pattern_data The block patterns data to cache.
	 *
	 * @since 6.4.0
	 */
	protected function save_cache( $pattern_data ) {
		if ( $this->can_use_cached ) {
			set_site_option( $this->option_name, $pattern_data );
			if ( ! is_multisite() ) {
				wp_set_option_autoload( $this->option_name, 'yes' );
			}
		}
	}
}
