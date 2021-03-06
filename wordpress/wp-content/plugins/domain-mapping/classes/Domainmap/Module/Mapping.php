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



/**
 * The module responsible for mapping domains.
 *
 * @category Domainmap
 * @package Module
 *
 * @since 4.0.3
 */
class Domainmap_Module_Mapping extends Domainmap_Module {

	const NAME = __CLASS__;

	/**
	 * @const key for url get param
	 *
	 * @since 4.3.0
	 *
	 */
	const BYPASS = "bypass";

	/**
	 * @const
	 *
	 * since 4.4.0.4
	 */
	const TRIED_SYNC = 'domainmap_tried_sync_auth';

	/**
	 * The array of mapped domains.
	 *
	 * @since 4.1.0
	 *
	 * @access private
	 * @var array
	 */
	private static $_mapped_domains = array();

	/**
	 * Determines whether we need to suppress swapping or not.
	 *
	 * @since 4.1.0
	 *
	 * @access private
	 * @var boolean
	 */
	private $_suppress_swapping = false;


	/**
	 * Determines whether we need to force ssl frontend of original domain
	 *
	 * @since 4.3.1
	 *
	 * @var bool|mixed
	 */
	private static $_force_front_ssl = false;

	/**
	 * Determines whether we need to force ssl admin of original domain
	 *
	 * @since 4.3.1
	 *
	 * @var bool|mixed
	 */
	private static $_force_admin_ssl = false;

	/**
	 * Has domain already been determined.
	 *
	 * @var bool
	 */
	private $_determined_domain = false;

	/**
	 * Constructor.
	 *
	 * @since 4.0.3
	 *
	 * @access public
	 * @param Domainmap_Plugin $plugin The current plugin.
	 */
	public function __construct( Domainmap_Plugin $plugin ) {

		parent::__construct( $plugin );

		self::$_force_front_ssl = $this->_plugin->get_option("map_force_frontend_ssl");
		self::$_force_admin_ssl = $this->_plugin->get_option("map_force_admin_ssl");

		/*
		 * Actions.
		 */
		// Admin routing.
		$this->_add_action('admin_init', 'route_domain');
		// Login routing.
		$this->_add_action('login_init', 'route_domain');
		// Get rid of cookie warning.
		$this->_add_action('login_init', 'allow_crosslogin');
		// Frontend routing.
		$this->_add_action('template_redirect', 'route_domain', 10);

		$this->_add_action( 'customize_controls_init', 'set_customizer_flag' );

		$this->_add_action( 'login_redirect', 'set_proper_login_redirect', 10, 2 );
		$this->_add_action( 'site_url', 'set_login_form_action', 20, 4);

		$this->_add_action("dm_toggle_mapping", "toggle_mapping", 10, 3);
		$this->_add_action("delete_blog", "on_delete_blog", 10, 2);

		/*
		 * Filters.
		 */
		$this->_add_filter("page_link",   'exclude_page_links', 10, 3);
		$this->_add_filter("page_link",   'ssl_force_page_links', 11, 3);
		// URLs swapping
		$this->_add_filter( 'unswap_url', 'unswap_mapped_url' );
		$this->_add_filter( 'home_url',   'home_url_scheme', 99, 4 );
		$this->_add_filter( 'site_url',   'home_url_scheme', 99, 4 );
		$this->_add_filter( 'admin_url', 'admin_url', 99, 3 );
		$this->_add_filter( 'rest_url', 'rest_url_scheme', 99, 4 );
		if ( defined( 'DOMAIN_MAPPING' ) && filter_var( DOMAIN_MAPPING, FILTER_VALIDATE_BOOLEAN ) ) {
			$this->_add_filter( 'login_url', 'set_proper_login_redirect_login', 3, 100 );
			$this->_add_filter( 'logout_url', 'set_proper_login_redirect', 2, 100 );
			$this->_add_filter( 'admin_url', 'set_proper_login_redirect', 2, 100 );


			$this->_add_filter( 'pre_option_siteurl', 'swap_root_url' );
			$this->_add_filter( 'pre_option_home',    'swap_root_url' );
			$this->_add_filter( 'home_url',           'swap_mapped_url', 10, 4 );
			$this->_add_filter( 'site_url',           'swap_mapped_url', 10, 4 );
			$this->_add_filter( 'includes_url',       'swap_mapped_url', 10, 2 );
			$this->_add_filter( 'content_url',        'swap_mapped_url', 10, 2 );
			$this->_add_filter( 'plugins_url',        'swap_mapped_url', 10, 3 );
		} elseif ( is_admin() ) {
			$this->_add_filter( 'home_url',           'swap_mapped_url', 10, 4 );
			$this->_add_filter( 'pre_option_home',    'swap_root_url' );
		}

		$this->_add_filter("preview_post_link", "post_preview_link_from_original_domain_to_mapped_domain", 10, 2);
		$this->_add_filter( 'customize_allowed_urls', "customizer_allowed_urls" );
		$this->_add_filter( 'logout_url', "filter_logout_url", 10, 2 );

	}

	/*
	 * Master router.
	 *
	 * Every page goes through this router.
	 */
	public function route_domain() {
		global $current_blog, $current_site;

		// Safety check to make sure this only runs once.
		if ($this->_determined_domain || $this->bypass_mapping()) return;

		// Make sure only runs once.
		$this->_determined_domain = true;

		$current_scheme =  $this->_http->getIsSecureConnection() ? "https://" : 'http://';
		$current_url = untrailingslashit(  $current_scheme . $current_blog->domain . $current_site->path );
		// Is front end.
		$is_front = !self::utils()->is_login() && !is_admin();

		// Should SSL be used according to settings.
		$use_ssl = $this->use_ssl();
		// Should the mapped domain be used according to settings.
		$use_mapped_domain = $this->use_mapped_domain();

		// redirect if ssl or mapping not correct.
		if ((is_ssl() !== $use_ssl) || ($use_mapped_domain !== domain_map::utils()->is_mapped_domain())) {
			$redirect_to = $use_mapped_domain ? 'mapped' : 'original';
			return $this->_redirect_to_area($redirect_to, $use_ssl, $is_front);
		// If user choice on front end, no need to redirect.
		} elseif (
			$is_front
			&& domain_map::utils()->get_frontend_redirect_type() === 'user'
		) {
			return;
		// If mapped and on frontend, make sure mapped domain is primary one.
		} elseif (
			$is_front
			&& $use_mapped_domain
			// Is the mapped domain primary?
			&& $current_blog->domain
			!== domain_map::utils()->get_mapped_domain()
		) {
			$redirect_to = $use_mapped_domain ? 'mapped' : 'original';
			return $this->_redirect_to_area($redirect_to, $use_ssl, $is_front);
		}
	}

	public function bypass_mapping() {
		if (  filter_input( INPUT_GET, 'dm' ) ===  self::BYPASS
			|| filter_input( INPUT_GET, 'action' ) === "logout"
			|| ( domain_map::utils()->is_login() &&  isset( $_POST['pwd'] ))
 			|| filter_input( INPUT_GET, 'action' ) === 'postpass'
		) return true;
		return false;
	}

	public function use_mapped_domain() {
		// What to return.
		$use_mapped = false;

		// For single page check.
		global $post;
		$post_id = isset( $post ) ? $post->ID : null;
 		$is_forced_single_page = ($this->is_excluded_by_id( $post_id ) || $this->is_excluded_by_request());

		/*
		 * Customizer
		 */
		if (is_customize_preview()) {
			// Return true or false.
			return $this->use_mapped_for_customizer();
		}

		// If no mapped domain is set to even use.
		if (!self::utils()->get_mapped_domain(false, false)) {
			return false;
		}
		/*
		 * Frontend
		 */
		if (!self::utils()->is_login() && !is_admin()){
			$front_type = self::utils()->get_frontend_redirect_type();
			// If user determines mapping, return that.
			if ($front_type === 'user') {
				$use_mapped = domain_map::utils()->is_mapped_domain();
			// Otherwise return front setting.
			} else {
				$use_mapped = ($front_type === 'original' ? false : true);
			}
			/*
 		 	 * Upfront Editor overrides redirect.
 		 	 */
			if (class_exists("Upfront")) {
				$use_mapped = $this->redirect_upfront_to_mapped_domain($use_mapped);
			}
		} else {
			/*
 			 * Login.
 			 */
			if (self::utils()->is_login()) {
				// If main site, do not use mapped.
				if (is_main_site()) {
					$use_mapped = false;
				} else {
					$use_mapped = ($this->_plugin->get_option( 'map_logindomain' ) === 'original' ? false : true);
				}
			} else {
				/*
				 * Admin
				 */
				$admin_type = $this->_get_current_mapping_type('map_admindomain');
				// If user determines mapping, return that.
				if ($admin_type === 'user') {
					$use_mapped = domain_map::utils()->is_mapped_domain();
				// Otherwise return admin setting.
				} else {
					$use_mapped = ($admin_type === 'original' || is_main_site() ? false : true);
				}
			}
		}

		// if forced page, check that.
		if ($is_forced_single_page) {
			return $is_forced_single_page;
		}

		// Return result.
		return $use_mapped;
	}

	public function use_ssl() {
		// What to return.
		$use_ssl = false;
		$use_mapped = $this->use_mapped_domain();

		// For single page check.
		global $post;
		$post_id = isset( $post ) ? $post->ID : null;
 		$is_forced_single_page = ($this->is_ssl_forced_by_id( $post_id ) || $this->is_ssl_forced_by_request());

		/*
		 * Customizer
		 */
		if (is_customize_preview()) {
			return $this->use_ssl_for_customizer($use_mapped ? 'mapped' : 'original');
		}

		/*
		 * Frontend
		 */
		if(!self::utils()->is_login() && !is_admin()){
			// Mapped Domain.
			if ($use_mapped) {
				// (user => false || true, http => 0, https => 1)
				// This is specific to each mapped domain.
				$mapped_domain = self::utils()->get_mapped_domain(false, true);
				$use_ssl = domain_map::utils()->force_ssl_on_mapped_domain($mapped_domain, true);
			// Original Domain.
			} else {
				// User determines.
				if ($this->_plugin->get_option("map_force_frontend_ssl") === 0) {
					$use_ssl = is_ssl();
				// Force http.
				} elseif ($this->_plugin->get_option("map_force_frontend_ssl") === 1) {
					$use_ssl = false;
				// Force https.
				} elseif ($this->_plugin->get_option("map_force_frontend_ssl") === 2) {
					$use_ssl = true;
				}
			}
		/*
		 * Admin
		 */
		} else {
			// Mapped Admin Domain.
			if ($use_mapped) {
				// (user => false || true, http => 0, https => 1)
				// This is specific to each mapped domain.
				$mapped_domain = self::utils()->get_mapped_domain(false, false);
				$use_ssl = domain_map::utils()->force_ssl_on_mapped_domain($mapped_domain, true);
			} else {
				// Original Admin Domain.
				// If not forced, use user preference.
				$use_ssl = $this->force_admin_ssl() ? true : is_ssl();
			}
		}
		/*
		 * Forced via single page.
		 */
		if ($is_forced_single_page) {
			return $is_forced_single_page;
		}
		return (boolean)$use_ssl;
	}


	/**
	 * Redirects to original domain.
	 *
	 * @since 4.1.0
	 *
	 * @access public
	 * @global object $current_blog Current blog object.
	 * @global object $current_site Current site object.
	 * @param bool $force_ssl
	 */
	public function redirect_to_orig_domain( $force_ssl ) {
		global $current_blog, $current_site;

		// don't redirect AJAX requests
		// also check if customizer should use mapped or not.
		if ( defined( 'DOING_AJAX' )) {
			return;
		}

		// The correct scheme to be used.
		$correct_scheme_raw = ($force_ssl) ? 'https' : 'http';
		// The current scheme being used.
		$current_scheme = (is_ssl()) ? 'https://' : 'http://';

		$swapping = $this->_suppress_swapping;
		$this->_suppress_swapping = true;
		$url = get_option( 'siteurl' );
		$this->_suppress_swapping = $swapping;

		// Use correct protocol.
		$url = set_url_scheme($url, $correct_scheme_raw);

		if ( $url && $url != untrailingslashit( $current_scheme . $current_blog->domain . $current_site->path ) ) {
			// strip out any subdirectory blog names
			$request = str_replace( "/a{$current_blog->path}", "/", "/a{$_SERVER['REQUEST_URI']}" );

			// If stripped out blog string does not equal rest of request.
			if ($request !== $_SERVER['REQUEST_URI']) {
				header( "HTTP/1.1 301 Moved Permanently", true, 301 );
				header( "Location: " . $url . $request, true, 301 );
			} else {
				header( "HTTP/1.1 301 Moved Permanently", true, 301 );
				header( "Location: " . $url . $_SERVER['REQUEST_URI'], true, 301 );
			}
			exit;
		}
	}

	/**
	 * Redirects to mapped domain.
	 *
	 * @since 4.0.3
	 *
	 * @param bool $force_ssl
	 * @param bool $is_front is it related to frontend
	 * @access public
	 *
	 * @return void
	 */
	public function redirect_to_mapped_domain( $force_ssl = false, $is_front = true ) {
		global $current_blog, $current_site;

		/**
		 * do not redirect if headers were sent or site is not permitted to use domain mapping
		 */
		if ( headers_sent() || !$this->_plugin->is_site_permitted()  ) {
			return;
		}

		$mapped_domain = self::utils()->get_mapped_domain(false, $is_front);
		// do not redirect if there is no mapped domain
		if ( !$mapped_domain) {
			return;
		}


		$map_check_health = $this->_plugin->get_option("map_check_domain_health");

		if( $map_check_health ){
			// Don't map if mapped domain is not healthy
			$health =  get_site_transient( "domainmapping-{$mapped_domain}-health" );

			if( $health !== "1"){
				if( !$this->set_valid_transient($mapped_domain)  ) return true;
			}

		}

		$current_scheme =  $this->_http->getIsSecureConnection() ? "https://" : 'http://';
		$current_url = untrailingslashit(  $current_scheme . $current_blog->domain . $current_site->path );
		$mapped_url = untrailingslashit( set_url_scheme( "http://" . $mapped_domain . $current_site->path,  $force_ssl ? 'https' :  'http') );

		if ( strtolower( $mapped_url ) != strtolower( $current_url ) ) {
			// strip out any subdirectory blog names
			$request = str_replace( "/a" . $current_blog->path, "/", "/a" . $_SERVER['REQUEST_URI'] );
			if ( $request != $_SERVER['REQUEST_URI'] ) {
				header( "HTTP/1.1 301 Moved Permanently", true, 301 );
				header( "Location: " . $mapped_url . $request, true, 301 );
			} else {
				header( "HTTP/1.1 301 Moved Permanently", true, 301 );
				header( "Location: " . $mapped_url . $_SERVER['REQUEST_URI'], true, 301 );
			}
			exit;
		}
	}

	/**
	 * Redirects to mapped or original domain.
	 *
	 * @since 4.1.0
	 *
	 * @access private
	 * @param string $redirect_to The direction to redirect to.
	 * @param bool $force_ssl
	 * @param bool $is_front is it related to frontend.
	 */
	private function _redirect_to_area( $redirect_to, $force_ssl = false, $is_front = true ) {

		/**
		 * Don't map if this page is exluded from mapping
		 */
		global $post;

		if( isset( $post ) && $this->is_excluded_by_id( $post->ID ) ) return;

		if( $this->is_excluded_by_request() ) return;

		switch ( $redirect_to ) {
			case 'mapped':
				$this->redirect_to_mapped_domain( $force_ssl, $is_front );
				break;
			case 'original':
				// The below if condition causes unhandled conditions. Commented out.
				//if ( defined( 'DOMAIN_MAPPING' ) ) {
					$this->redirect_to_orig_domain( $force_ssl );
				//}
				break;
		}
	}

	/**
	 * Redirects admin area to mapped or original domain depending on options settings.
	 *
	 * @since 4.1.0
	 * @action admin_init
	 *
	 * @access public
	 */
	public function redirect_admin_area() {
		$force_ssl = $this->_get_current_mapping_type( 'map_admindomain' ) === 'original' ?  $this->_plugin->get_option("map_force_admin_ssl") : false;

		$this->_redirect_to_area( $this->_plugin->get_option( 'map_admindomain' ), $force_ssl, false );
	}

	/**
	 * Redirects login area to mapped or original domain depending on options settings.
	 *
	 * @since 4.1.0
	 * @action login_init
	 *
	 * @access public
	 */
	public function redirect_login_area() {


		if(  filter_input( INPUT_GET, 'dm' ) ===  self::BYPASS
		     || filter_input( INPUT_GET, 'action' ) === "logout"
			|| ( domain_map::utils()->is_login() &&  isset( $_POST['pwd'] ) )
		) return;

		if ( filter_input( INPUT_GET, 'action' ) != 'postpass' ) {

			if( domain_map::utils()->is_original_domain() )
				$force_ssl = $this->_get_current_mapping_type( 'map_admindomain' ) === 'original'  ? $this->_plugin->get_option("map_force_admin_ssl") : false;

			if( domain_map::utils()->is_mapped_domain() )
				$force_ssl = domain_map::utils()->force_ssl_on_mapped_domain() == 2 ? false : domain_map::utils()->force_ssl_on_mapped_domain() ;

			$this->_redirect_to_area( $this->_plugin->get_option( 'map_logindomain' ), $force_ssl, false );
		}
	}

	/**
	 * Redirects frontend to mapped or original.
	 *
	 * @since 4.1.0
	 * @action template_redirect
	 *
	 * @access public
	 */
	public function redirect_front_area() {

		if(  filter_input( INPUT_GET, 'dm' ) ===  self::BYPASS ) return;

		/**
		 * Filter if it should proceed with redirecting
		 *
		 * @since 4.1.0
		 * @param bool $is_ssl
		 */
		if( apply_filters( "dm_prevent_redirection_for_ssl", is_ssl() && $redirect_to !== "mapped"  ) ) return;

		if ( $redirect_to != 'user' && $this->redirect_upfront_to_mapped_domain() ) {
			$this->_redirect_to_area( $redirect_to, $force_ssl);
		}
	}

	/**
	 * Sets customizer flag which determines to not map URLs.
	 *
	 * @since 4.1.0
	 * @action customize_controls_init
	 *
	 * @access public
	 */
	public function set_customizer_flag() {
		$this->_suppress_swapping = $this->_get_current_mapping_type( 'map_admindomain' ) == 'original';
	}

	/**
	 * Returns current mapping type.
	 *
	 * @since 4.1.0
	 *
	 * @access private
	 * @param string $option The option name to check.
	 * @return string Mapping type.
	 */
	private function _get_current_mapping_type( $option ) {
		$mapping = $this->_plugin->get_option( $option );
		if ( $mapping != 'original' && $mapping != 'mapped' ) {
			$original = $this->_wpdb->get_var( sprintf(
				"SELECT option_value FROM %s WHERE option_name = 'siteurl'",
				$this->_wpdb->options
			) );

			if ( $original ) {
				$components = self::utils()->parse_mb_url( $original );
				$mapping = isset( $components['host'] ) && $_SERVER['HTTP_HOST'] == $components['host']
					? 'original'
					: 'mapped';
			}
		}

		return apply_filters("dm_current_mapping_type", $mapping, $option);
	}

	/**
	 * Find what settings are used and return correct customizer mapping.
	 *
	 * @since 4.3
	 *
	 * @return boolean
	 */
	public function use_mapped_for_customizer() {
		// If admin is set to user mapping, use that.
 		if ($this->_plugin->get_option('map_admindomain') === 'user' ) {
			return domain_map::utils()->is_mapped_domain();
		}
		// If admin is forced to original domain, disable mapping to prevent non-matching domain errors.
 		if ($this->_plugin->get_option('map_admindomain') === 'original' ) {
			return false;
		}
		// Otherwise use mapped.
		return true;
	}

	/**
 	 * Find what settings are used and choose whether to force SSL on customizer.
 	 *
	 * @param bool $force_ssl Whether to default to forcing ssl or not if not customizer.
	 *
 	 * @since 4.3
 	 *
 	 * @return boolean
 	 */
	public function use_ssl_for_customizer($original_or_mapped) {
		// If original domain.
		if ($original_or_mapped === 'original') {
			// If admin is SSL, return true.
			if (is_ssl() || $this->_plugin->get_option("map_force_admin_ssl")) {
				return true;
			} else {
				return false;
			}
		// If mapped domain.
		} else if ($original_or_mapped === 'mapped') {
			if (
				is_ssl()
				|| $this->is_ssl_forced_by_request()
				// If admin is SSL.
				|| $this->_plugin->get_option("map_force_admin_ssl")
				// If mapped domain is SSL.
				|| domain_map::utils()->force_ssl_on_mapped_domain() === 1
				// If frontend is SSL.
				|| (self::$_force_front_ssl)
			) {
				return true;
			} else {
				return false;
			}
		}
	}






	/**
	 * Swaps URL from original to mapped one.
	 *
	 * @since 4.1.0
	 * @filter home_url 10 4
	 * @filter site_url 10 4
	 * @filter includes_url 10 2
	 * @filter content_url 10 2
	 * @filter plugins_url 10 3
	 *
	 * @param $url
	 * @param bool $path
	 * @param bool $orig_scheme
	 * @param bool $blog_id
	 * @param bool $consider_front_redirect_type
	 *
	 * @return string
	 */
	public function swap_mapped_url( $url, $path = false, $orig_scheme = false, $blog_id = false, $consider_front_redirect_type = true ) {

		// do not swap URL if customizer is running
		if ( $this->_suppress_swapping || self::utils()->is_mapped_domain( $url ) ) {
			return $url;
		}

		if ( $this->is_excluded_by_url( $url ) ) {
			return apply_filters("dm_swap_mapped_url", $url, $path, $orig_scheme, $blog_id);
		}

		return self::utils()->swap_to_mapped_url( $url, $path, $orig_scheme, $blog_id, $consider_front_redirect_type );
	}


	/**
	 * Returns swapped root URL.
	 *
	 * @since 4.1.0
	 * @filter pre_option_home
	 * @filter pre_option_siteurl
	 *
	 * @access public
	 * @global object $current_site The current site object.
	 * @param string $url The current root URL.
	 * @return string Swapped root URL on success, otherwise inital value.
	 */
	public function swap_root_url( $url ) {
		global $current_site, $current_blog;

		// do not swap URL if customizer is running or front end redirection is disabled
		if ( $this->_suppress_swapping ) {
			return apply_filters("dm_swap_root_url", $url);
		}

		$domain = self::utils()->get_mapped_domain(false, !is_admin());
		if ( !$domain ){
			return apply_filters("dm_swap_root_url", $url);
		}

		$protocol = 'http://';

		if ( domain_map::utils()->force_ssl_on_mapped_domain( $domain, true ) && is_ssl() ) {
			$protocol = 'https://';
		}

		$destination = untrailingslashit( $protocol . $domain  . $current_site->path );

		if ( $this->is_excluded_by_url( $url ) ) {
			$_url = $current_site->domain . $current_blog->path .$current_site->path;
			$destination = untrailingslashit( $protocol .  str_replace("//", "/", $_url) );
		}

		return apply_filters("dm_swap_root_url", $destination);
	}

	/**
	 * Retrieves original domain from the given mapped_url
	 *
	 * @since 4.1.3
	 * @access public
	 *
	 * @uses self::unswap_url()
	 *
	 * @param $url
	 * @param bool $blog_id
	 * @param bool $include_path
	 * @return string
	 */
	public function unswap_mapped_url( $url, $blog_id = false, $include_path = true ) {
		return self::utils()->unswap_url( $url, $blog_id, $include_path );
	}

	/**
	 * Forces ssl in different areas of the site based on user choice
	 *
	 * @since 4.2
	 *
	 * @uses force_ssl_admin
	 * @uses force_ssl_login
	 * @uses wp_redirect
	 */
	public function force_schema(){
		global $post;

		$post_id = isset( $post ) ? $post->ID : null;

		if( filter_input( INPUT_GET, 'dm' ) ===  self::BYPASS ) return;

		do_action("dm_before_force_schema");

		$current_url = $this->_http->getHostInfo("http") . $this->_http->getUrl();
		$current_url = apply_filters("dm_force_schema_current_url", $current_url);
		$current_url_secure = $this->_http->getHostInfo("https") . $this->_http->getUrl();
		$current_url_secure = apply_filters("dm_force_schema_current_secure_url", $current_url_secure);
		$force_schema = apply_filters("dm_force_schema", true, $current_url, $current_url_secure);

		/**
		 * Filters if schema should be forced
		 *
		 * @since 4.2.0.4
		 *
		 * @param bool $force_schema
		 * @param bool $current_url current page http url
		 * @param bool $current_url_secure current page https url
		 */
		if( !apply_filters("dm_forcing_schema", $force_schema, $current_url, $current_url_secure) ) return;

		/**
		 * Force original domain
		 */
		if(  !self::utils()->is_login() && !is_admin() && self::utils()->is_original_domain()){

			// Force http
			if(  $this->_plugin->get_option("map_force_frontend_ssl") === 1  && is_ssl() ){
				wp_redirect( $current_url );
				exit();
			}

			// Force https
			if(  $this->_plugin->get_option("map_force_frontend_ssl") === 2 &&  self::utils()->is_original_domain() && !is_ssl() ){
				wp_redirect( $current_url_secure  );
				exit();
			}

		}

		/**
		 * Force single page
		 */
		if( !is_admin() && ( $this->is_ssl_forced_by_id( $post_id ) || $this->is_ssl_forced_by_request() ) && !is_ssl() ){
			wp_redirect( $current_url_secure  );
			exit();
		}elseif(  self::utils()->is_mapped_domain() && self::utils()->force_ssl_on_mapped_domain() !== 2 && !( $this->is_ssl_forced_by_id( $post_id ) || $this->is_ssl_forced_by_request() ) ){

			/**
			 * Force mapped domains
			 */
			if ( domain_map::utils()->force_ssl_on_mapped_domain("", true)  ){ // force https
				// If already SSL, prevent infinite redirects.
 				if(is_ssl()) return;

				wp_redirect( $current_url_secure  );
				exit();
			} elseif( domain_map::utils()->force_ssl_on_mapped_domain() === 0 && is_ssl() ){ //force http
				wp_redirect( $current_url);
				exit();
			}
		}
	}

	/**
	 * Forces scheme in admin|login of original domain
	 *
	 * @since 4.2
	 *
	 * @uses force_ssl_admin
	 * @uses force_ssl_login
	 * @uses wp_redirect
	 */
	// Use SSL for admin or login?
	function force_admin_ssl(){
		do_action("dm_before_force_admin_schema");
		$force_admin_schema = apply_filters("dm_force_admin_schema", true,  $this->_http->getUrl());

		if( $force_admin_schema && self::utils()->is_original_domain() && $this->_plugin->get_option("map_force_admin_ssl") && ( is_admin() || self::utils()->is_login() ) ){
			return true;
		}
		return false;
	}


	/**
	 * Forces scheme in admin|login of original domain
	 *
	 * @since 4.2
	 *
	 * @uses force_ssl_admin
	 * @uses force_ssl_login
	 * @uses wp_redirect
	 */
	function force_login_scheme(){
		/**
		 * Takes care of login scheme for original domain
		 */
	   $this->force_admin_ssl();

		/**
		 * Suppress if logging is going to happen
		 */
		if(  isset( $_POST['pwd'] ) )
			return;

		$mapped_domain_scheme = domain_map::utils()->get_mapped_domain_scheme();

		if(  domain_map::utils()->is_mapped_domain() &&  $mapped_domain_scheme && $this->_http->currentScheme() !== $mapped_domain_scheme ){
			$redirect_to = ( domain_map::utils()->force_ssl_on_mapped_domain() == 1 ?  $this->_http->getHostInfo($mapped_domain_scheme) : $this->_http->getHostInfo($mapped_domain_scheme) )  . $this->_http->getUrl();
			wp_redirect( $redirect_to );
		}
	}

	/**
	 * Removes mapping record from db when a site is deleted
	 *
	 * Since 4.2.0
	 * @param $blog_id
	 * @param $drop
	 */
	function on_delete_blog( $blog_id, $drop){
		$this->_wpdb->delete(DOMAINMAP_TABLE_MAP, array( "blog_id" => $blog_id ) , array( "%d" ) );
	}


	/**
	 * Makes sure post preview is shown even if the admin uses original or entered domain and the frontend is supposed
	 * to use mapped domain
	 *
	 * @since 4.3.0
	 *
	 * @param $url
	 * @param $post
	 *
	 * @return string
	 */
	function post_preview_link_from_original_domain_to_mapped_domain($url, $post = null){
		if($url){
			$url_fragments = parse_url( $url );
			$hostinfo = $url_fragments['scheme'] . "://" . $url_fragments['host'];
			if( $hostinfo !== $this->_http->hostInfo ){
				return esc_url_raw( add_query_arg(array("dm" => self::BYPASS ),   set_url_scheme($this->unswap_mapped_url( $url  ), is_ssl() ) ) );
			}
		}

		return $url;
	}

	/**
	 * Adds mapped domain to customizer's allowed urls so
	 * @param $allowed_urls
	 *
	 * @return array
	 */
	function customizer_allowed_urls( $allowed_urls ){

		if( self::$_mapped_domains === array() ) return $allowed_urls;

		$mapped_urls = array();

		foreach( self::$_mapped_domains as $domain ){
			if(  !empty( $domain ) ){
				$mapped_urls[] = "http://" . $domain;
				$mapped_urls[] = "https://" . $domain;
			}
		}

		return array_merge($allowed_urls, $mapped_urls);
	}

	/**
	 * Returns excluded pages
	 *
	 * @since 4.3.0
	 *
	 * @param bool $return_array weather it should return array or or string of comma separated ids
	 *
	 * @return array|mixed|void
	 */
	public static function get_excluded_pages( $return_array = false ){
		$excluded_pages = get_option( "dm_excluded_pages", "");
		if( $return_array ){
			return $excluded_pages === "" ? array() :  array_map("intval", array_map("trim", explode(",", $excluded_pages)) );
		}

		$excluded_pages = $excluded_pages === "" ? false : $excluded_pages;
		return apply_filters("dm_excluded_pages", $excluded_pages);
	}

	/**
	 * Returns excluded page urls
	 *
	 * @since 4.3.0
	 *
	 * @param bool $return_array weather it should return array or or string of comma separated ids
	 *
	 * @return array|mixed|void
	 */
	public static function get_excluded_page_urls( $return_array = false ){
		global $current_blog;
		$excluded_page_urls = trim( get_option( "dm_excluded_page_urls", "") );

		if( empty(  $excluded_page_urls   ) ) return $return_array ? array() : "";

		if( $return_array ){
			if( $excluded_page_urls === "" )
				return array();

			$urls = array_map("trim", explode(",", $excluded_page_urls));

			$parseds = array_map("parse_url", $urls);
			$paths = array();

			foreach( $parseds as $parsed ){
				if( isset( $parsed['path'] ) ){
					$path =  ltrim( untrailingslashit( str_replace("//", "/", $parsed['path']) ), '/\\' );
					$replacee = ltrim( $current_blog->path, '/\\');
					$paths[] = str_replace($replacee, "", $path);
				}

			}
			return $paths;
		}

		return apply_filters("dm_excluded_pages_url", $excluded_page_urls, $return_array);
	}

	/**
	 * Returns excluded page urls
	 *
	 * @since 4.3.0
	 *
	 * @param bool $return_array weather it should return array or or string of comma separated ids
	 *
	 * @return array|mixed|void
	 */
	public static function get_ssl_forced_page_urls( $return_array = false ){
		global $current_blog;
		$ssl_forced_page_urls =  trim( get_option( "dm_ssl_forced_page_urls", "") );

		if( empty(  $ssl_forced_page_urls   ) ) return $return_array ? array() : "";

		if( $return_array ){

			$urls = array_map("trim", explode(",", $ssl_forced_page_urls));
			$parseds = array_map("parse_url", $urls);
			$paths = array();
			foreach( $parseds as $parsed ){
				if( isset( $parsed['path'] ) ){
					$path =  ltrim( untrailingslashit( str_replace("//", "/", $parsed['path']) ), '/\\' );
					$replacee = ltrim( $current_blog->path, '/\\');
					$paths[] = str_replace($replacee, "", $path);
				}

			}
			return $paths;
		}

		return apply_filters("dm_ssl_forced_page_urls", $ssl_forced_page_urls, $return_array);
	}

	/**
	 * Returns ssl forced pages
	 *
	 * @since 4.3.0
	 *
	 * @param bool $return_array weather it should return array or or string of comma separated ids
	 *
	 * @return array|mixed|void
	 */
	public static function get_ssl_forced_pages( $return_array = false ){
		$forced_pages = get_option( "dm_ssl_forced_pages", "");
		if( $return_array ){
			$forced_pages =  $forced_pages == "" ? array() :  array_map("intval", array_map("trim", explode(",", $forced_pages)) );
		}

		return apply_filters("dm_ssl_forced_pages", $forced_pages, $return_array);
	}

	/**
	 * Checks to see if the given page should be excluded from mapping
	 *
	 * @since 4.3.0
	 *
	 * @param $post_id int | null
	 *
	 * @return bool
	 */
	function is_excluded_by_id( $post_id ){
		if( is_null( $post_id ) ) return apply_filters("dm_is_excluded_by_id", false);
		return apply_filters("dm_is_excluded_by_url", in_array( $post_id, self::get_excluded_pages( true )  ), $post_id);
	}

	/**
	 * Checks if the given url is ( should be ) excluded
	 *
	 * @since 4.3.0
	 *
	 * @param $url
	 *
	 * @return bool
	 */
	function is_excluded_by_url( $url ){
		$excluded_ids =  self::get_excluded_pages( true );

		if( empty( $url ) || !$excluded_ids ) return false;

		$permalink_structure = get_option("permalink_structure");
		$comps = parse_url( $url );
		if( empty( $permalink_structure ) )
		{
			if( isset( $comps['query'] ) && $query = $comps['query'] ){
				foreach( $excluded_ids as $excluded_id ){
					if( $query === "page_id=" . $excluded_id ) return apply_filters("dm_is_excluded_by_url", true, $url);
				}
			}

			return apply_filters("dm_is_excluded_by_url", false, $url);
		}



		if( isset( $comps['path'] ) && $path = $comps['path'] )
		{
			foreach( $excluded_ids as $excluded_id ){
				$post = get_post( $excluded_id );

				if( strrpos( $path, $post->post_name ) ) return apply_filters("dm_is_excluded_by_url", true, $url);
			}
		}


		return apply_filters("dm_is_excluded_by_url", false, $url);
	}


	function is_excluded_by_request(){
		global $wp;

		if( !isset( $wp ) || !isset( $wp->request ) ) return apply_filters("dm_is_excluded_by_request", false);
		return apply_filters("dm_is_excluded_by_request", in_array( $wp->request, $this->get_excluded_page_urls(true) ));
	}

	function is_ssl_forced_by_request(){
		global $wp;

		if( !isset($wp) || !isset( $wp->request ) ) return apply_filters("dm_is_ssl_forced_by_request", false);
		return apply_filters("dm_is_ssl_forced_by_request", in_array( $wp->request, $this->get_ssl_forced_page_urls(true) ));
	}

	/**
	 * Excludes page permalinks
	 *
	 * @since 4.3.0
	 *
	 * @param $permalink
	 * @param $post_id
	 * @param $leavename
	 *
	 * @return string
	 */
	function exclude_page_links( $permalink, $post_id, $leavename  ){

		$exclude = apply_filters("dm_exclude_page_links", true);

		if(!$exclude || empty($post_id) || self::utils()->is_original_domain( $permalink ) ) return $permalink;


		if( $this->is_excluded_by_id( $post_id) ){
			return self::utils()->unswap_url( $permalink );
		}
		return $permalink;
	}

	/**
	 * Forces excluded pages to land on the main domain
	 *
	 * @since 4.3.0
	 */
	function force_page_exclusion(){
		global $post;
		$post_id = isset( $post ) ? $post->ID : null;

		if( self::utils()->is_mapped_domain()  &&  ( $this->is_excluded_by_id( $post_id ) || $this->is_excluded_by_request() ) ){
			$current_url = is_ssl() ? $this->_http->getHostInfo("https") . $this->_http->getUrl() : $this->_http->getHostInfo("http") . $this->_http->getUrl();
			$current_url = self::utils()->unswap_url( $current_url );
			wp_redirect( $current_url );
			die;
		}
	}

	/**
	 * Checks to see if the given page should be forced to https
	 *
	 * @since 4.3.0
	 *
	 * @param $post_id
	 *
	 * @return bool
	 */
	function is_ssl_forced_by_id( $post_id ){
		if( is_null( $post_id ) ) return apply_filters("dm_is_ssl_forced_by_id", false);
		return apply_filters("dm_is_ssl_forced_by_id",  in_array( $post_id, self::get_ssl_forced_pages( true )  ), $post_id );
	}


	/**
	 * SSL force page permalinks
	 *
	 * @since 4.3.0
	 *
	 * @param $permalink
	 * @param $post_id
	 * @param $leavename
	 *
	 * @return string
	 */
	function ssl_force_page_links( $permalink, $post_id, $leavename  ){

		if( empty( $post_id )) return $permalink;

		if( $this->is_ssl_forced_by_id( $post_id ) ){
			$permalink = set_url_scheme( $permalink, "https" ) ;
		}
		return apply_filters("dm_ssl_force_page_links", $permalink, $post_id, $leavename);
	}



	/**
	 * Force logout url to logout the site on current domain
	 *
	 * @param $logout_url
	 * @param $redirect
	 *
	 * @since 4.3.1
	 * @return string
	 */
	function filter_logout_url( $logout_url, $redirect  ){
		if( self::utils()->is_mapped_domain() ){
			$logout_url = $this->swap_mapped_url( $logout_url, "wp-login.php" );
		}
		return apply_filters("dm_filter_logout_url", $logout_url, $redirect);
	}

	/**
	 * After logout redirects user to mapped or original domain depending on options settings.
	 *
	 * @since 4.3.1
	 * @action wp_logout
	 * @access public
	 */
	public function redirect_logged_out() {
		$force_ssl = $this->_get_current_mapping_type( 'map_admindomain' ) === 'original'  ? $this->_plugin->get_option("map_force_admin_ssl") : false;
		$this->_redirect_to_area( $this->_plugin->get_option( 'map_logindomain' ), $force_ssl, false );
	}

	/**
	 * Sets proper $redirect_to based on admin mapping opted in settings
	 * Note that this overrides the redirect_to query string on the login url.
	 *
	 * @since 4.4.0.4
	 *
	 * @param $redirect_to
	 * @param $requested_redirect_to
	 * @param $user
	 *
	 * @uses login_redirect filter
	 *

	 * @return string
	 */
	function set_proper_login_redirect( $redirect_to, $requested_redirect_to ){
		$admin_mapping = $this->_plugin->get_option( 'map_admindomain' );
		$login_mapping = $this->_plugin->get_option( 'map_logindomain' );

		$scheme = $this->use_ssl() ? 'https' : 'http';

		// If admin is original or admin is user and login was original, keep on original domain to prevent an inability to login.
		if( $admin_mapping === "original" || ($admin_mapping === "user" && $login_mapping === "original") ){
			if (self::utils()->is_mapped_domain( $redirect_to )) {
				return set_url_scheme( $this->unswap_mapped_url( $redirect_to, false, true ), $scheme );
			}
		}

		if( $admin_mapping === "mapped" && self::utils()->is_original_domain( $redirect_to ) ){
			return set_url_scheme( $this->swap_mapped_url( $redirect_to, false, false, false, false ), $scheme );
		}

		return set_url_scheme( $redirect_to, $scheme );
	}

	/**
	 * Login redirect filter
	 *
	 * @param $redirect_to
	 * @param $requested_redirect_to
	 * @param $force_reauth
	 *
	 * @see set_proper_login_redirect
	 */
	function set_proper_login_redirect_login( $redirect_to, $requested_redirect_to, $force_reauth ) {
		return $this->set_proper_login_redirect( $redirect_to, $requested_redirect_to );
	}


	/**
	 * Sets proper login form action attribute based on admin mapping opted in settings
	 *
	 * @since 4.4.0.4
	 *
	 * @param $url
	 * @param $path
	 * @param $scheme
	 * @param $blog_id
	 *
	 * @uses site_url filter
	 *
	 * @return string
	 */
	function set_login_form_action($url, $path, $scheme, $blog_id ){

		if( !self::utils()->is_login() || is_main_site() ) return $url;

		$admin_mapping = $this->use_mapped_domain();

		$scheme = $this->use_ssl();

		if( $path === "wp-login.php" ){

			if( $admin_mapping  == "mapped" ){
				$scheme =  self::utils()->get_mapped_domain_scheme( $url );
				return $scheme ?  set_url_scheme( $this->swap_mapped_url($url, $path, $scheme, $blog_id, false), $scheme ) : $this->swap_mapped_url($url, $path, $scheme, $blog_id, false);
			}

			if( $admin_mapping == "original" && self::utils()->is_mapped_domain( $url ) ){
				return set_url_scheme( $this->unswap_mapped_url($url, $blog_id), $scheme );
			}
		}

		$scheme = self::utils()->is_mapped_domain( $url ) ? self::utils()->get_mapped_domain_scheme( $url ) : $scheme;

		return $scheme ?  set_url_scheme( $url, $scheme ) : $url ;
	}

	/**
	 * Allows login from mapped domain to the original domain and vise versa by bypassing testcookie nag
	 *
	 * @since 4.4.0.7
	 *
	 */
	function allow_crosslogin(){
		if( isset( $_POST['testcookie'] ) &&  empty( $_COOKIE[ TEST_COOKIE ] ) )
			unset( $_POST['testcookie'] );
	}


	/**
	 * Toggles mapping to $toggle_value based on the provided $blog_id or $domain
	 *
	 * @uses dm_toggle_mapping
	 *
	 * @param int $blog_id
	 * @param string $domain
	 * @param int $toggle_value 1|0
	 * @return false|int
	 *
	 * @since 4.4.0.8
	 */
	function toggle_mapping($toggle_value = 0, $blog_id = null, $domain = null ){

		if( !empty( $blog_id ) ){
			return $this->_wpdb->update( DOMAINMAP_TABLE_MAP, array( "active" => $toggle_value ), array( "blog_id" => $blog_id ) );
		}

		if( !empty( $blog_id ) ){
			return $this->_wpdb->update( DOMAINMAP_TABLE_MAP, array( "active" => $toggle_value ), array( "domain" => $domain ) );
		}

		return false;
	}


	/**
	 * Do url scheme manipulation when needed
	 * @param $url
	 * @param $path
	 * @param $orig_scheme
	 * @param $blog_id
	 * @return string
	 */
	function home_url_scheme($url, $path, $orig_scheme, $blog_id){
		$path = empty( $path ) ? "/" : $path;

		if( class_exists("Upfront") && false !== strpos(  $path, "editmode=true" )  ){
			return self::utils()->is_mapped_domain( $url ) && "mapped" !== $this->_get_current_mapping_type( 'map_admindomain' ) ?  self::utils()->unswap_url( $url )  : $url;
		}

		return $url;
	}

	// Override admin_url to prevent issues with relative ajaxurls, etc on some subdirectory setups.
	// For example, we were getting the admin url appended to the admin url on mapped domains for some reason when the 'relative' scheme is used in core.
	function admin_url($url, $path, $blog_id = null){
		// Admin URL (Override scheme).
		$url = get_site_url( $blog_id, 'wp-admin/', $this->use_ssl() ? 'https' : 'http' );
		force_ssl_admin( $this->use_ssl() );
		// Append Path.
		if ( $path && is_string( $path ) )
			$url .= ltrim( $path, '/' );

		// Return updated URL for admin_url filter.
		return $url;
	}

	/**
	 * Decide if upfront should be redirected to mapped domain
	 *
	 * @since 4.4.2.0
	 * @param $default bool The default to use if upfront editor is not active.
	 * @return bool
	 */
	function redirect_upfront_to_mapped_domain($default){
		// If Editor.
		if( class_exists("Upfront") && wp_get_theme()->parent() && "upfront" ===  strtolower( wp_get_theme()->parent()->get("Name") ) && isset( $_GET[ "editmode" ] ) ) {
			// Use whatever admin mapping is.
			return "mapped" === $this->_get_current_mapping_type( 'map_admindomain' );
		// If Builder.
		} elseif (class_exists("Upfront") && wp_get_theme()->parent() && "upfront" ===  strtolower( wp_get_theme()->parent()->get("Name")) && strpos($_SERVER['REQUEST_URI'], 'create_new')) {
			return false;
		}
		// If no editor or builder, use default.
		else
			return $default;
	}

	/**
	 * Rest URL Scheme
	 */
	function rest_url_scheme( $url, $path, $blog_id, $orig_scheme ){
		$current_rest_url 	= parse_url( $url );

		//Get the host from each url to allow across the iframe
		$current_page_url 	= $_SERVER['HTTP_HOST'];
		$current_rest_url 	= $current_rest_url['host'];

		if ( $current_page_url !== $current_rest_url ) {
			$url = self::utils()->unswap_url( $url );
		}

		if ( is_ssl() || $this->_plugin->get_option("map_force_admin_ssl")) {
			$url_info = parse_url( $url );
			if( $url_info['scheme'] != 'https' ){
				$url = str_replace( 'http://', 'https://', $url );
			}
		}
		return $url;
	}

}
