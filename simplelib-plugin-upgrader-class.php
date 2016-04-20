<?php
/**
 * Class SimpleLibPluginUpgrader.
 * Author: minimus
 * Version 1.0
 * Date: 21.03.2016
 * Time: 12:21
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

		public $enabled = false;

		public function __construct( $id, $data ) {
			if ( ! empty( $id ) ) {
				$this->itemId         = $id;
				$this->personalToken  = ( isset( $data['token'] ) ) ? $data['token'] : null;
				$this->currentVersion = ( isset( $data['version'] ) ) ? $data['version'] : null;
				$this->slug           = ( isset( $data['slug'] ) ) ? $data['slug'] : null;
				$this->pluginSlug     = ( isset( $data['pluginSlug'] ) ) ? $data['pluginSlug'] : null;
				$this->name           = ( isset( $data['name'] ) ) ? $data['name'] : null;
				$this->homepage       = ( isset( $data['homepage'] ) ) ? $data['homepage'] : '';
			}
			$this->enabled = self::is_enabled();
			if ( $this->enabled ) {
				add_filter( 'pre_set_site_transient_update_plugins', array( &$this, 'checkUpdate' ) );
				add_filter( 'plugins_api', array( &$this, 'checkInfo' ), 10, 3 );
				add_filter( 'upgrader_package_options', array( &$this, 'setUpdatePackage' ) );
			}
		}

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
			} elseif( 200 == $response_code ) {
				$out = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( null === $out ) {
					return new WP_Error( 'api_error', __( 'An unknown API error occurred.', SAM_PRO_DOMAIN ) );
				}

				return $out;
			}
			else return null;
		}

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

		public function checkInfo( $result, $action, $args ) {
			if ( $args->slug === $this->slug ) {
				$pluginInfo = self::request();
				if ( isset( $pluginInfo['wordpress_plugin_metadata'] ) ) {
					$info        = $pluginInfo['wordpress_plugin_metadata'];
					$versions    = self::getAttribute( $pluginInfo['attributes'], 'compatible-software' );
					$sections    = explode( '<h2 id="item-description__changelog">Changelog</h2>', $pluginInfo['description'] );
					$description = ( isset( $sections[0] ) ) ? $sections[0] : '';
					$changelog   = ( isset( $sections[1] ) ) ? $sections[1] : '';

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
					$plugin->sections        = array(
						'description' => $description,
						'changelog'   => $changelog
					);
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