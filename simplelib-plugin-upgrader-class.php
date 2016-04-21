<?php
/**
 * Class SimpleLibPluginUpgrader.
 * Version 1.3
 * Author: minimus
 * Author URI: http://simplelib.com
 */

if ( ! class_exists( 'SimpleLibPluginUpgrader' ) ) {
	class SimpleLibPluginUpgrader {
		private $itemId = null;
		private $personalToken = null;
		private $currentVersion = null;
		private $slug = null;
		private $pluginSlug = null;
		private $name = null;
		private $homepage = '';
		private $defaultSections = array(
			'description',
			'installation',
			'faq',
			'screenshots',
			'changelog',
			'reviews',
			'other_notes'
		);

		public $enabled = false;
		public $callback = null;

		/**
		 * SimpleLibPluginUpgrader constructor.
		 *
		 * @param string $id Envato Item ID
		 * @param array $data Contains the required parameters
		 *                      token — Personal Token of buyer
		 *                      version — plugin current version
		 *                      slug — slug of plugin (i.e.: sam-pro-lite)
		 *                      pluginSlug — full slug of plugin (plugin folder + name of main plugin file,
		 *                        i.e.: sam-pro-lite/sam-pro-lite.php)
		 *                      name — name of plugin
		 *                      homepage – plugin homepage URL, not required
		 * @param null|callable $callback The function provides splitting of the content of the Envato plugin description
		 *                                to the standard sections.
		 */
		public function __construct( $id, $data, $callback = null ) {
			if ( ! empty( $id ) ) {
				$this->itemId         = $id;
				$this->personalToken  = ( isset( $data['token'] ) ) ? $data['token'] : null;
				$this->currentVersion = ( isset( $data['version'] ) ) ? $data['version'] : null;
				$this->slug           = ( isset( $data['slug'] ) ) ? $data['slug'] : null;
				$this->pluginSlug     = ( isset( $data['pluginSlug'] ) ) ? $data['pluginSlug'] : null;
				$this->name           = ( isset( $data['name'] ) ) ? $data['name'] : null;
				$this->homepage       = ( isset( $data['homepage'] ) ) ? $data['homepage'] : '';
				$this->callback       = $callback;
			}
			$this->enabled = self::is_enabled();
			if ( $this->enabled ) {
				add_filter( 'pre_set_site_transient_update_plugins', array( &$this, 'checkUpdate' ) );
				add_filter( 'plugins_api', array( &$this, 'checkInfo' ), 10, 3 );
				add_filter( 'upgrader_package_options', array( &$this, 'setUpdatePackage' ) );
			}
		}

		/**
		 * Checking for all the transmitted data to the class
		 *
		 * @return bool
		 */
		private function is_enabled() {
			return (
				! is_null( $this->itemId ) &&
				! is_null( $this->personalToken ) &&
				! is_null( $this->currentVersion ) &&
				! is_null( $this->slug ) &&
				! is_null( $this->pluginSlug ) &&
				! is_null( $this->name )
			);
		}

		/**
		 * Preparing of the part of received data
		 *
		 * @param array $data  preparing data
		 * @param string $name the name of input data part
		 *
		 * @return array|bool|string
		 */
		private function getAttribute( $data, $name ) {
			$out = '';
			foreach ( $data as $key => $val ) {
				if ( $val['name'] === $name ) {
					switch ( $name ) {
						case 'compatible-software':
							$out = array(
								'required' => str_replace( 'WordPress ', '', $val['value'][ count( $val['value'] ) - 1 ] ),
								'tested'   => str_replace( 'WordPress ', '', $val['value'][0] )
							);
							break;
						default:
							$out = false;
					}
				}
			}

			return $out;
		}

		/**
		 * Default function for splitting content. If user function is not defined, provides splitting of the content
		 * of the Envato plugin description to the standard sections.
		 * Default sections: description, installation, faq,	screenshots, changelog,	reviews, other_notes.
		 *
		 * @param null|array $data content of the Envato plugin description
		 *
		 * @return array
		 */
		private function getSections( $data = null ) {
			if ( is_null( $data ) || empty( $data ) ) {
				return array();
			}

			$out                = array();
			$m                  = preg_match_all( "/<h2(.*?)>(.+?)<\/h2>/", $data, $matches );
			$sections           = preg_split( "/<h2(.*?)>(.+?)<\/h2>/", $data );
			$out['description'] = ( isset( $sections[0] ) ) ? $sections[0] : '';
			foreach ( $matches[2] as $key => $match ) {
				$out[ strtolower( $match ) ] = $sections[ $key + 1 ];
			}

			return $out;
		}

		/**
		 * Request data from Envato API
		 *
		 * @param string $data type of data for request
		 *
		 * @return array|mixed|null|object|WP_Error
		 */
		public function request( $data = 'info' ) {
			$args = array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->personalToken,
				),
				'timeout' => 30,
			);

			switch ( $data ) {
				case 'info':
					$url = 'https://api.envato.com/v2/market/catalog/item?id=' . $this->itemId;
					break;
				case 'link':
					$url = 'https://api.envato.com/v2/market/buyer/download?item_id=' . $this->itemId . '&shorten_url=true';
					break;
				default:
					$url = 'https://api.envato.com/v2/market/catalog/item?id=' . $this->itemId;
			}

			$response = wp_remote_get( esc_url_raw( $url ), $args );

			$response_code    = wp_remote_retrieve_response_code( $response );
			$response_message = wp_remote_retrieve_response_message( $response );

			if ( 200 !== $response_code && ! empty( $response_message ) ) {
				return new WP_Error( $response_code, $response_message );
			} elseif ( 200 !== $response_code ) {
				return new WP_Error( $response_code, __( 'An unknown API error occurred.', SAM_PRO_DOMAIN ) );
			} elseif ( 200 == $response_code ) {
				$out = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( null === $out ) {
					return new WP_Error( 'api_error', __( 'An unknown API error occurred.', SAM_PRO_DOMAIN ) );
				}

				return $out;
			} else {
				return null;
			}
		}

		/**
		 * pre_set_site_transient_update_plugins filter handler. Checking the availability of an update of the plugin
		 * on the CodeCanyon.
		 *
		 * @param object $transient
		 *
		 * @return object
		 */
		public function checkUpdate( $transient ) {
			if ( empty( $transient->checked ) ) {
				return $transient;
			}

			$pluginInfo = self::request();
			if ( is_array( $pluginInfo ) && isset( $pluginInfo['wordpress_plugin_metadata'] ) ) {
				$info = $pluginInfo['wordpress_plugin_metadata'];
				if ( version_compare( $this->currentVersion, $info['version'], '<' ) ) {
					$plugin                                   = new stdClass();
					$plugin->slug                             = $this->slug;
					$plugin->new_version                      = $info['version'];
					$plugin->url                              = '';
					$plugin->package                          = $this->pluginSlug;
					$plugin->name                             = $info['plugin_name'];
					$plugin->plugin                           = $this->pluginSlug;
					$transient->response[ $this->pluginSlug ] = $plugin;
				}
			}

			return $transient;
		}

		/**
		 * plugins_api filter handler. Retrieving plugin information from Envato API.
		 *
		 * @param false|object|array $result The result object or array. Default false.
		 * @param string             $action The type of information being requested from the Plugin Install API.
		 * @param object             $args   Plugin API arguments.
		 *
		 * @return bool|object
		 */
		public function checkInfo( $result, $action, $args ) {
			if ( $args->slug === $this->slug ) {
				$pluginInfo = self::request();
				if ( is_array( $pluginInfo ) && isset( $pluginInfo['wordpress_plugin_metadata'] ) ) {
					$info     = $pluginInfo['wordpress_plugin_metadata'];
					$versions = self::getAttribute( $pluginInfo['attributes'], 'compatible-software' );
					$sections = ( is_null( $this->callback ) ) ?
						self::getSections( $pluginInfo['description'] ) :
						call_user_func( $this->callback, $pluginInfo['description'] );

					$plugin                  = new stdClass();
					$plugin->name            = $info['plugin_name'];
					$plugin->author          = $info['author'];
					$plugin->slug            = $this->slug;
					$plugin->version         = $info['version'];
					$plugin->requires        = $versions['required'];
					$plugin->tested          = $versions['tested'];
					$plugin->rating          = ( (int) $pluginInfo['rating']['count'] < 3 ) ? 100.0 : 20 * (float) $pluginInfo['rating']['rating'];
					$plugin->num_ratings     = (int) $pluginInfo['rating']['count'];
					$plugin->active_installs = (int) $pluginInfo['number_of_sales'];
					$plugin->last_updated    = $pluginInfo['updated_at'];
					$plugin->added           = $pluginInfo['published_at'];
					$plugin->homepage        = $this->homepage;
					$plugin->sections        = $sections;
					$plugin->download_link   = $pluginInfo['url'];
					$plugin->banners         = array(
						'high' => $pluginInfo['previews']['landscape_preview']['landscape_url']
					);

					return $plugin;
				} else {
					return false;
				}
			} else {
				return false;
			}
		}

		/**
		 * upgrader_package_options filter handler. Retrieving plugin package URI from Envato API.
		 *
		 * @param array $options The package options before running an update.
		 *
		 * @return array
		 */
		public function setUpdatePackage( $options ) {
			$package = $options['package'];
			if ( $package === $this->pluginSlug ) {
				$response           = self::request( 'link' );
				$options['package'] =
					( is_wp_error( $response ) || empty( $response ) || ! empty( $response['error'] ) ) ?
						'' :
						$response['wordpress_plugin'];
			}

			return $options;
		}
	}
}