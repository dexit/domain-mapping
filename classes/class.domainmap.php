<?php

// +----------------------------------------------------------------------+
// | Copyright Incsub (http://incsub.com/)                                |
// | Based on an original by Donncha (http://ocaoimh.ie/)                 |
// +----------------------------------------------------------------------+
// | This program is free software; you can redistribute it and/or modify |
// | it under the terms of the GNU General Public License, version 2, as  |
// | published by the Free Software Foundation.                           |
// |                                                                      |
// | This program is distributed in the hope that it will be useful,      |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of       |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the        |
// | GNU General Public License for more details.                         |
// |                                                                      |
// | You should have received a copy of the GNU General Public License    |
// | along with this program; if not, write to the Free Software          |
// | Foundation, Inc., 51 Franklin St, Fifth Floor, Boston,               |
// | MA 02110-1301 USA                                                    |
// +----------------------------------------------------------------------+

class domain_map {

	/**
	 * @var wpdb
	 */
	var $db;

	// The tables we need to map - empty for now as we will move to this later
	var $tables = array();

	// The main domain mapping tables
	var $dmtable;

	// The domain mapping options
	var $options;

	// For caching swapped urls later on
	var $swapped_url = array();

    /**
     * Text domain name used for translating strings
     *
     * @since 4.2.0
     * @param string Text_Domain
     */
    const Text_Domain = "domainmap";

	/**
	 * Options key set when rewrite rules are flushed
	 *
	 * @since 4.3.1
	 * @param string FLUSHED_REWRITE_RULES
	 */
	const FLUSHED_REWRITE_RULES = 'domainmap-flushed-rules-';

	function __construct() {
		global $wpdb, $dm_cookie_style_printed, $dm_logout, $dm_authenticated;

		$dm_cookie_style_printed = false;
		$dm_logout = false;
		$dm_authenticated = false;

		$this->db = $wpdb;
		$this->dmtable = DOMAINMAP_TABLE_MAP;

		// Set up the plugin
		add_action( 'init', array( $this, 'setup_plugin' ) );
		// Add in the cross domain logins
		add_action( 'init', array( &$this, 'build_stylesheet_for_cookie' ) );

		add_filter( 'allowed_redirect_hosts', array( $this, 'allowed_redirect_hosts' ), 10 );

		add_action( 'login_head', array( $this, 'build_logout_cookie' ) );

		// Add in the filters for domain mapping early on to get any information covered before the init action is hit
		$this->add_domain_mapping_filters();
		add_action('wp_ajax_update_excluded_pages_list', array($this, 'ajax_update_excluded_pages_list'));

		add_action("domainmap_plugin_activated", array($this, "flush_rewrite_rules"));
		add_action("domainmap_plugin_deactivated", array($this, "remove_rewrite_rule_flush_trace"));
	}



	function shibboleth_session_initiator_url($initiator_url) {
		return $initiator_url;
	}


	function domain_mapping_login_url( $login_url, $redirect = '' ) {

		switch ( $this->options['map_logindomain'] ) {
			case 'user':
				break;
			case 'mapped':
				break;
			case 'original':
				// Get the mapped url using our filter
				$mapped_url = site_url( '/' );
				// remove the http and https parts of the url
				$mapped_url = str_replace( array( 'https://', 'http://' ), '', $mapped_url );
				// get the original url now with our filter removed
				$url = trailingslashit( apply_filters( 'unswap_url', get_option( 'siteurl' ) ) );
				// again remove the http and https parts of the url
				$url = str_replace( array( 'https://', 'http://' ), '', $url );

				// replace the mapped url with the original one
				$login_url = str_replace( $mapped_url, $url, $login_url );

				/*
				  if( !isset($_POST['postpass']) ) {

				  } else {
				  // keep the mapped url as we need to just process and return
				  $login_url = str_replace($url, $mapped_url, $login_url);
				  }
				 */

				break;
		}

        if( $this->is_original_domain( $login_url ) ){
            return $this->options['map_force_admin_ssl'] ? set_url_scheme($login_url, "https") : $login_url;
        }else{
            $mapped_domain_scheme = self::get_mapped_domain_scheme();
            return $mapped_domain_scheme ? set_url_scheme($login_url, $mapped_domain_scheme)  : $login_url;
        }
	}

	function domain_mapping_admin_url( $admin_url, $path = '/', $_blog_id = false ) {
		global $blog_id;

		if ( !$_blog_id ) {
			$_blog_id = $blog_id;
		}
		switch ( $this->options['map_admindomain'] ) {
			case 'user':
				break;
			case 'mapped':
				break;
			case 'original':
				// get the mapped url using our filter
				$mapped_url = site_url( '/' );
				// remove the http and https parts of the url
				$mapped_url = str_replace( array( 'https://', 'http://' ), '', $mapped_url );
				// get the original url now with our filter removed
				$orig_url = trailingslashit( apply_filters( 'unswap_url', get_option( 'siteurl' ) ) );
				// remove the http and https parts of the original url
				$orig_url = str_replace( array( 'https://', 'http://' ), '', $orig_url );

				// Check if we are looking at the admin-ajax.php and if so, we want to leave the domain as mapped
				if ( $path != 'admin-ajax.php' && strpos($admin_url, "admin-ajax.php") === false ) {
					// swap the mapped url with the original one
					$admin_url = str_replace( $mapped_url, $orig_url, $admin_url );
				} else {
					if ( !is_admin() ) {
						// swap the original url with the mapped one
						$admin_url = str_replace( $orig_url, $mapped_url, $admin_url );
					}
				}

				break;
		}

		return $this->options['map_force_admin_ssl'] ? set_url_scheme($admin_url, "https") : $admin_url;
	}

	function add_domain_mapping_filters() {

		if ( defined( 'DOMAIN_MAPPING' ) ) {
			// filter the content with any original urls and change them to the mapped urls
			add_filter( 'the_content', array(&$this, 'domain_mapping_post_content') );
			// Jump in just before header output to change base_url - until a neater method can be found
			add_filter( 'print_head_scripts', array(&$this, 'reset_script_url'), 1, 1);

			add_filter( 'wp_redirect', array(&$this, 'wp_redirect'), 999, 2 );

			add_filter('authenticate', array(&$this, 'authenticate'), 999, 3);

			add_filter( 'login_url', array(&$this, 'domain_mapping_login_url'), 2, 100 );
			add_filter( 'logout_url', array(&$this, 'domain_mapping_login_url'), 2, 100 );
			add_filter( 'admin_url', array(&$this, 'domain_mapping_admin_url'), 3, 100 );

			add_filter( 'theme_root_uri', array(&$this, 'domain_mapping_post_content'), 1 );
			add_filter( 'stylesheet_uri', array(&$this, 'domain_mapping_post_content'), 1 );
			add_filter( 'stylesheet_directory', array(&$this, 'domain_mapping_post_content'), 1 );
			add_filter( 'stylesheet_directory_uri', array(&$this, 'domain_mapping_post_content'), 1 );
			add_filter( 'template_directory', array(&$this, 'domain_mapping_post_content'), 1 );
			add_filter( 'template_directory_uri', array(&$this, 'domain_mapping_post_content'), 1 );
		} else {
			// We are assuming that we are on the original domain - so if we check if we are in the admin area, we need to only map those links that
			// point to the front end of the site
			if(is_admin()) {
				// filter the content with any original urls and change them to the mapped urls
				add_filter( 'the_content', array(&$this, 'domain_mapping_post_content') );
				add_filter( 'wp_redirect', array(&$this, 'wp_redirect'), 999, 2 );
				add_filter( 'authenticate', array(&$this, 'authenticate'), 999, 3);
			}
		}


	}

	function setup_plugin() {
		$this->options = Domainmap_Plugin::instance()->get_options();

		$permitted = true;
		if ( function_exists( 'is_pro_site' ) && !empty( $this->options['map_supporteronly'] ) ) {
			// We have a pro-site option set and the pro-site plugin exists
			$levels = (array)get_site_option( 'psts_levels' );
			if( !is_array( $this->options['map_supporteronly'] ) && !empty( $levels ) && $this->options['map_supporteronly'] == '1' ) {
				$keys = array_keys( $levels );
				$this->options['map_supporteronly'] = array( $keys[0] );
			}

			$permitted = false;
			foreach ( (array)$this->options['map_supporteronly'] as $level ) {
				if( is_pro_site( false, $level ) ) {
					$permitted = true;
				}
			}
		}

		// Add the network admin settings
		if ( $permitted ) {
			add_action( 'wp_logout', array( $this, 'wp_logout' ), 10 );
			add_action( 'admin_head', array( $this, 'build_cookie' ) );
		}
	}

	function authenticate($user) {
		global $dm_authenticated;

		if (!empty($user)) {
			$dm_authenticated = $user;
		}

		return $user;
	}

	function wp_logout() {
		global $dm_logout;

		$dm_logout = true;
	}

	function wp_redirect($location) {
		global $dm_authenticated, $dm_logout, $dm_csc_building_urls;

		if ( $dm_authenticated ) {
			if ( !defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', 1 ); // don't let wp-super-cache cache this page.
			}

			header("HTTP/1.1 301 Moved Permanently", true, 301);
			header("Location: {$location}", true, 301);

			?><!DOCTYPE html>
			<html xmlns="http://www.w3.org/1999/xhtml" dir="ltr" lang="en-US">
				<head>
					<meta name="robots" content="noindex,nofollow" />
					<title><?php _e('Authenticating...', 'domainmap'); ?></title>
					<?php
					if ( !empty( $dm_authenticated ) && !empty( $dm_authenticated->ID ) ) {
						$this->build_cookie( 'login', $dm_authenticated, $location );
					} else {
						$this->build_cookie( 'logout' );
					}

					if ( count( $dm_csc_building_urls ) > 0 ) {
						$dm_csc_building_urls[] = rawurlencode( $location );

						$location = rawurldecode( array_shift( $dm_csc_building_urls ) );
						$location .= '&follow_through=' . implode( ',', $dm_csc_building_urls );
					}
					?>
					<meta http-equiv="refresh" content="3;url=<?php echo $location; ?>" />
				</head>
				<body>
					<h1><?php _e('Please wait...', 'domainmap'); ?></h1>
					<p><?php echo sprintf(__('If it doesn\'t redirect in 5 seconds please click <a href="%s">here</a>.', 'domainmap'), $location); ?></p>
					<!-- Hej -->
				</body>
			</html>
			<?php
			// @ob_flush();
			exit();
		}

		if ($dm_logout) {
			define( 'DONOTCACHEPAGE', 1 ); // don't let wp-super-cache cache this page.
			header("HTTP/1.1 301 Moved Permanently", true, 301);
			header("Location: {$location}", true, 301);
			?>
			<!DOCTYPE html>
			<html xmlns="http://www.w3.org/1999/xhtml" dir="ltr" lang="en-US">
				<head>
					<meta name="robots" content="noindex,nofollow" />
					<title><?php _e('Authenticating...', 'domainmap'); ?></title>
					<?php
					$this->build_cookie('logout');

					if (count($dm_csc_building_urls) > 0) {
						$dm_csc_building_urls[] = rawurlencode($location);

						$location = rawurldecode(array_shift($dm_csc_building_urls));
						$location .= '&follow_through='.join(',', $dm_csc_building_urls);
					}
					?>
					<meta http-equiv="refresh" content="3;url=<?php echo $location; ?>" />
				</head>
				<body>
					<h1><?php _e('Please wait...', 'domainmap'); ?></h1>
					<p><?php echo sprintf(__('If it doesn\'t redirect in 5 seconds please click <a href="%s">here</a>.', 'domainmap'), $location); ?></p>
					<!-- Hej då -->
				</body>
			</html>
			<?php
			// @ob_flush();
			exit();
		}

		return $location;
	}

	// Cookie functions
	function build_logout_cookie() {
		if(isset($_GET['loggedout'])) {
			// Log out CSS
			$this->build_cookie('logout');
		}
	}

	function build_cookie( $action = 'login', $user = false, $redirect_to = false ) {
		// this method is disabled!
		return;

		global $dm_cookie_style_printed, $dm_csc_building_urls;

		// don't build cookie for visitors
		if ( !is_user_logged_in() ) {
			return;
		}

		// return if cross domain autologin is disabled
		if ( isset( $this->options['map_crossautologin'] ) && $this->options['map_crossautologin'] == 0 ) {
			return;
		}

		// set user id
		$user_id = $user;
		if ( is_a( $user, 'WP_User' ) ) {
			$user_id = $user->ID;
		} elseif ( !is_int( $user ) ) {
			$user_id = get_current_user_id();
		}

		// build cookies only each five minutes
		if ( $action != 'logout' ) {
			$transient = 'domainmapping-sso-' . $user_id;
			if ( get_site_transient( $transient ) ) {
				return;
			}
			set_site_transient( $transient, 1, 10 * MINUTE_IN_SECONDS );
		}

		/**
		 * Cookie building order:
		 * - Main site url
		 * - Main site admin url
		 * - Unmapped site
		 * - Mapped site
		 * - Redirect to unampped site
		 * - Redirect to mapped site
		 */

		if ( !is_array( $dm_csc_building_urls ) ) {
			$dm_csc_building_urls = array();
		}

		if( $action == '' || $action != 'logout' ) {
			$action = 'login';
		}

		$urls = $blog_ids = array();
		$schema = is_ssl() ? 'https://' : 'http://';

		// Main site url
		$network_url = parse_url( network_site_url() );
		if ( !isset( $urls[$network_url['host']] ) ) {
			$urls[$network_url['host']] = trailingslashit( $schema . $network_url['host'] );
		}

		// Main site admin url
		$network_admin_url = parse_url( network_admin_url() );
		if ( !isset( $urls[$network_admin_url['host']] ) ) {
			$urls[$network_admin_url['host']] = $schema . $network_admin_url['host'] . $network_url['path'];
		}

		// Unmapped site
		$blogs = get_blogs_of_user( $user_id );
		foreach ( $blogs as $id => $blog ) {
			$blog_ids[] = $id;
			if ( !isset( $urls[$blog->domain] ) ) {
				$urls[$blog->domain] = $schema . $blog->domain . $blog->path;
			}
		}

		// do nothing if blog ids are empty
		if ( empty( $blog_ids ) ) {
			return;
		}

		// Mapped site
		$domains = $this->db->get_results( sprintf(
			"SELECT domain FROM %s WHERE blog_id IN (%s) ORDER BY id",
			DOMAINMAP_TABLE_MAP,
			implode( ', ', $blog_ids )
		), ARRAY_A );

		if ( $domains && is_array( $domains ) ) {
			foreach ( $domains as $domain ) {
				if ( !isset( $urls[$domain['domain']] ) ) {
					$urls[$domain['domain']] = trailingslashit( $schema . $domain['domain'] );
				}
			}
		}

		// We are redirecting, lets pack some cookies for the journey. Nom Nom Nom
		if ( $redirect_to ) {
			$redirect_url = parse_url( $redirect_to );

			$domain = $this->db->get_row( "SELECT blog_id, domain FROM {$this->dmtable} WHERE domain = '{$redirect_url['host']}' OR domain LIKE '{$redirect_url['host']}/%' ORDER BY id LIMIT 1", ARRAY_A );
			if ( $domain ) {
				// redirect to unmapped site
				$addom = get_site_option( 'map_admindomain', 'user' );
				if ( !isset( $urls[$domain['domain']] ) ) {
					$urls[$domain['domain']] = trailingslashit( $schema . $domain['domain'] );
				}

				// Other mapped sites
				$results = $this->db->get_col( "SELECT domain FROM {$this->dmtable} WHERE blog_id = '{$domain['blog_id']}' ORDER BY id" );
				if ( $results && is_array( $results ) ) {
					foreach ( $results as $result ) {
						if ( !isset( $urls[$result] ) ) {
							$urls[$result] = trailingslashit( $schema . $result );
						}
					}
				}

				// redirect to mapped site
				$result = $this->db->get_row( "SELECT domain, path FROM {$this->db->blogs} WHERE blog_id = '{$domain['blog_id']}' LIMIT 1", ARRAY_A );
				if ( $result && !isset( $urls[$result['domain']] ) ) {
					$urls[$result['domain']] = $schema . $result['domain'] . $result['path'];
				}
			} else {
				// redirect to unmapped site
				$domain = $this->db->get_row( "SELECT blog_id, domain, path FROM {$this->db->blogs} WHERE domain = '{$redirect_url['host']}' LIMIT 1", ARRAY_A );
				if ( $domains ) {
					if ( !isset( $urls[$domain['domain']] ) ) {
						$urls[$domain['domain']] = $schema . $domain['domain'] . $domain['path'];
					}

					// Other mapped sites
					$domains = $this->db->get_results( "SELECT domain FROM {$this->dmtable} WHERE blog_id = '{$domain['blog_id']}' ORDER BY id", ARRAY_A );
					if( $domains && is_array( $domains ) ) {
						foreach ( $domains as $domain ) {
							if ( !isset( $urls[$domain['domain']] ) ) {
								$urls[$domain['domain']] = trailingslashit( $schema . $domain['domain'] );
							}
						}
					}

					// redirect to mapped site
					$domain = $this->db->get_row( "SELECT blog_id, domain FROM {$this->dmtable} WHERE blog_id = '{$domain['blog_id']}' LIMIT 1", ARRAY_A );
					if ( $domain ) {
						if ( !isset( $urls[$domain['domain']] ) ) {
							$urls[$domain['domain']] = trailingslashit( $schema . $domain['domain'] );
						}
					}
				}
			}
		}

		if ( count( $urls ) > 0 ) {
			$key = array();
			$is_admin = is_admin();
			$minus24_date = date( "Ymd", strtotime( '-24 days' ) );

			$keys = get_user_meta( $user_id, 'cross_domain', true );
			if ( !empty( $keys ) && is_array( $keys ) ) {
				foreach( $keys as $hash => $meta ) {
					if ( isset( $meta['built'] ) && $meta['built'] == $minus24_date ) {
						$key[$hash] = $meta;
					}
				}
			}

			foreach ( $urls as $url ) {
				$parsed_url = parse_url( $url );
				if ( !isset( $parsed_url['host'] ) || empty( $parsed_url['host'] ) ) {
					continue;
				}

				$hash = md5( AUTH_KEY . $minus24_date . 'COOKIEMONSTER' . $url . $action );
				$key[$hash] = array (
					'domain'  => $url,
					'hash'    => $hash,
					'user_id' => $user_id,
					'action'  => $action,
					'built'   => $minus24_date,
				);

				$css_url = $url . $hash . '?action=' .  $action . '&uid=' . $user_id . '&build=' . $minus24_date;
				if ( $is_admin ) {
					$dm_cookie_style_printed = true;
					echo '<link rel="stylesheet" href="', $css_url, '" type="text/css" media="screen">';
				}

				$dm_csc_building_urls[] = rawurlencode( $css_url );
			}

			update_user_meta( $user_id, 'cross_domain', $key );
		}
	}

	function allowed_redirect_hosts( $allowed_hosts ) {
		if ( !empty( $_REQUEST['redirect_to'] ) ) {
			$redirect_url = parse_url( $_REQUEST['redirect_to'] );
			if ( isset( $redirect_url['host'] ) ) {
				$network_home_url = parse_url( network_home_url() );
				if ( $redirect_url['host'] != $network_home_url['host'] ) {
					$pos = strpos( $redirect_url['host'], '.' );
					if ( ($pos !== false) && (substr( $redirect_url['host'], $pos + 1 ) === $network_home_url['host']) ) {
						$allowed_hosts[] = $redirect_url['host'];
					}

					$bid = $this->db->get_var( "SELECT blog_id FROM {$this->dmtable} WHERE domain = '{$redirect_url['host']}' ORDER BY id LIMIT 1" );
					if ( $bid ) {
						$allowed_hosts[] = $redirect_url['host'];
					}
				}
			}
		} else {
			$domains = (array)$this->db->get_col( sprintf( "SELECT domain FROM %s WHERE blog_id = %d ORDER BY id ASC", DOMAINMAP_TABLE_MAP, $this->db->blogid ) );
			$original = $this->db->get_var( "SELECT domain FROM {$this->db->blogs} WHERE blog_id = " . intval( $this->db->blogid ) );
			$allowed_hosts = array_unique( array_merge( $allowed_hosts, $domains, array( $original ) ) );
		}

		return $allowed_hosts;
	}

	function build_stylesheet_for_cookie() {
		if( !isset( $_GET['build'] ) || !isset( $_GET['uid'] ) ) {
			return;
		}

		if ( addslashes( $_GET['build'] ) == date( 'Ymd', strtotime( '-24 days' ) ) ) {
			// We have a stylesheet with a build and a matching date - so grab the hash
			$hash = basename( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );
			$key = (array)get_user_meta( $_GET['uid'], 'cross_domain', true );
			if ( isset( $key[$hash]['action'] ) ) {
				// Set the cookies
				if ( !is_user_logged_in() ) {
					if ( $key[$hash]['action'] == 'login' ) {
						wp_set_auth_cookie( $key[$hash]['user_id'] );
					}
				} else {
					if ( $key[$hash]['action'] == 'logout' ) {
						wp_clear_auth_cookie();
					}
				}
			}
		}

		define( 'DONOTCACHEPAGE', 1 ); // don't let wp-super-cache cache this page.
		if ( !isset( $_REQUEST['follow_through'] ) ) {
			header( "Content-type: text/css" );
			echo "/* Sometimes me think what is love, and then me think love is what last cookie is for. Me give up the last cookie for you. */";
			exit;
		}

		$follow_through = explode( ',', $_REQUEST['follow_through'] );
		$location = count( $follow_through ) > 0
			? rawurldecode( array_pop( $follow_through ) )
			: site_url();

		?><!DOCTYPE html>
		<html>
			<head>
				<meta charset="<?php bloginfo( 'charset' ) ?>">
				<title><?php _e( 'Authenticating...', 'domainmap' ); ?></title>
				<meta name="robots" content="noindex,nofollow">
				<?php foreach ( $follow_through as $dm_csc_style_location ) : ?>
				<link rel="stylesheet" href="<?php echo rawurldecode( $dm_csc_style_location ) ?>" type="text/css" media="screen">
				<?php endforeach; ?>
			</head>
			<body>
				<h1><?php _e( 'Please wait...', 'domainmap' ) ?></h1>
				<p><?php echo sprintf( __( 'If it does not redirect in 5 seconds please click <a href="%s">here</a>.', 'domainmap' ), $location ) ?></p>
				<script type="text/javascript">
					window.location = '<?php echo $location ?>';
				</script>
			</body>
		</html><?php

		exit;
	}

	function reset_script_url($return) {
		global $wp_scripts;

		$wp_scripts->base_url = site_url();

		return $return;
	}

	function domain_mapping_post_content( $post_content ) {
		static $orig_urls = array();
		$blog_id = get_current_blog_id();
		if ( !isset( $orig_urls[$blog_id] ) ) {

            /**
             * Filter the original url
             *
             * @since 1.0.0
             * @param string $orig_url the original url
             */
            $orig_url = apply_filters( 'unswap_url', get_option( 'siteurl' ) );
			// switch the url to use the correct http or https and store the url in the cache
			$orig_urls[$blog_id] = is_ssl()
				? str_replace( "http://", "https://", $orig_url )
				: str_replace( "https://", "http://", $orig_url );
		} else {
			// we have a cached entry so just return that
			$orig_url = $orig_urls[$blog_id];
		}

        /**
         * Filter getting new mapped url
         *
         * @since 1.0.0
         * @param string $url
         */
        $url = apply_filters( 'pre_option_siteurl', 'NA' );
		if ( $url == 'NA' ) {
			// If we don't have a mapped url then just return the content unchanged
			return $post_content;
		}

		// replace all the original urls with the new ones and then return the content
		return str_replace( trailingslashit( $orig_url ), trailingslashit( $url ), $post_content );
	}

    /**
     * Retrieves option from db
     *
     * @since 4.2.0
     * @param $key string option name
     * @param bool $default string default value to return when option is not set or is empty
     * @return bool false if option not set or empty | mixed option value
     */
    protected function get_option( $key, $default = false ){
        return isset( $this->options[$key] ) && !empty( $this->options[$key] ) ? $this->options[$key] : $default;
    }


    /**
     * Return mapping dns config and status
     *
     * @since 4.2.0
     *
     * @param null $mapping
     * @return array
     */
    function get_dns_config($mapping = null) {
        if ($mapping == null) {
            $mapping = (object) array('domain' => 'www.example.com', 'active' => 1);
        }

        $map_ipaddress = $this->get_option("map_ipaddress", __('IP not set by admin yet.', self::Text_Domain) );
        $no_www_domain = preg_replace('/^www\./', '', $mapping->domain);

        $records = array();
        if ( strpos( $map_ipaddress, ',' ) ) {
            // Multiple CNAME not supported, so assume A
            $_records = preg_split(',', $map_ipaddress);
            foreach ($_records as $record) {
                $records[] = array('host' => $mapping->domain, 'type' => 'A', 'target' => $record);
            }
        } else {
            if (ip2long($map_ipaddress) > 0) {
                $rec_type = "A";
            } else {
                $rec_type = "CNAME";
            }
            $records[] = array('host' => $mapping->domain, 'type' => $rec_type, 'target' => $map_ipaddress);
        }
        return $records;
    }

	/**
	 * Updates excluded pages listing table with ajax
	 *
	 * @since 4.3.0
	 */
	function ajax_update_excluded_pages_list() {
		$wp_list_table = new Domainmap_Table_ExcludedPages_Listing();
		$wp_list_table->ajax_response();
	}

	/**
	 * Allow multiple domain mappings
	 * @return bool|mixed
	 */
	public static function allow_multiple(){
		if( defined("DOMAINMAPPING_ALLOWMULTI") ) return (bool) DOMAINMAPPING_ALLOWMULTI;
		return Domainmap_Plugin::instance()->get_option("map_allow_multiple", false);
	}

	/**
	 * Flushes rewrite rules on plugin activation
	 *
	 * @since 4.3.1
	 */
	function flush_rewrite_rules(){
		flush_rewrite_rules(true);
	}

	/**
	 * Removes trace of rewrite rule flush from db so that later on they can be flashed when the plugin gets activated again
	 *
	 * @since 4.3.1
	 */
	function remove_rewrite_rule_flush_trace(){
		global $wpdb;

		/**
		 * @param $wpdb WPDB
		 */
		$prefix = self::FLUSHED_REWRITE_RULES;

		$wpdb->query("DELETE FROM $wpdb->sitemeta WHERE `meta_key` LIKE '$prefix%'");
	}


    protected function get_original_domain( $with_www = false ){
        $home = network_home_url( '/' );
        $original_domain = parse_url( $home, PHP_URL_HOST );
        return $with_www ? "www." . $original_domain : $original_domain ;
    }
    /**
     * Checks if current site resides in original domain
     *
     * @since 4.2.0
     *
     * @param string $domain
     * @return bool true if it's original domain, false if not
     */
    protected function is_original_domain( $domain = null ){
        $domain = parse_url( is_null( $domain ) ? $this->_http->hostinfo : $domain  , PHP_URL_HOST );

        /** MULTI DOMAINS INTEGRATION */
        if( class_exists( 'multi_domain' ) ){
            global $multi_dm;
            if( is_array( $multi_dm->domains ) ){
                foreach( $multi_dm->domains as $key => $domain_item){
                    if( $domain === $domain_item['domain_name'] || strpos($domain, "." . $domain_item['domain_name']) ){
                        return apply_filters("dm_is_original_domain", true, $domain);
                    }
                }
            }
        }

        $is_oroginal_domain = $domain === $this->get_original_domain() || strpos($domain, "." . $this->get_original_domain());
        return apply_filters("dm_is_original_domain", $is_oroginal_domain, $domain);
    }

    /**
     * Checks if current site resides in mapped domain
     *
     * @since 4.2.0
     *
     * @param null $domain
     *
     * @return bool
     */
    protected function is_mapped_domain( $domain = null ){
        return !$this->is_original_domain( $domain );
    }

    /**
     * Checks if current page is login page
     *
     * @since 4.2.0
     *
     * @return bool
     */
    protected function is_login(){
        global $pagenow;
        $needle = isset( $pagenow ) ? $pagenow : str_replace("/", "", $this->_http->getRequestUri() );
        $is_login = in_array( $needle, array( 'wp-login.php', 'wp-register.php' ) );
        return apply_filters("dm_is_login", $is_login, $needle, $pagenow) ;
    }

    /**
     * Checks to see if the passed $url is an admin url
     *
     * @param $url
     *
     * @return bool
     */
    protected function is_admin_url( $url ){
        $parsed = parse_url( urldecode(  $url ) );

        return isset( $parsed['path'] ) ? strpos($parsed['path'], "/wp-admin") !== false : false;
    }

    /**
     * Checks if give domain should be forced to use https
     *
     * @since 4.2.0
     *
     * @param string $domain
     * @return bool
     */
    public static function force_ssl_on_mapped_domain( $domain = "" ){
        global $wpdb;
        $current_domain = isset( $_SERVER['SERVER_NAME'] ) ? $_SERVER['SERVER_NAME'] : $_SERVER['HTTP_HOST'];
        $domain = $domain === "" ? $current_domain  : $domain;
        $force_ssl_on_mapped_domain = (int) $wpdb->get_var( $wpdb->prepare("SELECT `scheme` FROM `" . DOMAINMAP_TABLE_MAP . "` WHERE `domain`=%s", $domain) );
        return apply_filters("dm_force_ssl_on_mapped_domain", $force_ssl_on_mapped_domain) ;
    }

    /**
     * Returns the forced scheme for the mapped domain
     *
     * @param string $domain
     * @return bool|string false when no scheme should be forced and https or http for the scheme
     */
    public static  function get_mapped_domain_scheme( $domain = "" ){

        switch(  self::force_ssl_on_mapped_domain( $domain ) ){
            case 0:
                $scheme = "http";
                break;
            case 1:
                $scheme = "https";
                break;
            default:
                $scheme = false;
                break;
        }

        return $scheme;
    }
}
