<?php

/**
 * Plugin Name: Toolbar for JCH Optimize
 * Description: This plugin adds a toolbar for JCH Optimize Plugin
 * Version: 1.0.2
 * Author: Sabbir Hasan
 * Author URI: https://iamsabbir.dev/
 * License: GNU/GPLv3
 * Text Domain: jch-optimize-toolbar
 * Domain Path: /languages
 *
 */

require __DIR__ . '/vendor/autoload.php';

use JchOptimize\Core\Helper;
use JchOptimize\Platform\Cache;
use JchOptimize\Platform\Plugin;

define( 'JCH_HELPER_VERSION', '1.0.1' );

class JCH_Toolbar {
	/**
	 * @return null
	 */
	function __construct() {
		add_action( 'admin_init', array( $this, 'check_version' ) );

		// Don't run anything else in the plugin, if we're on an incompatible WordPress version
		if ( ! self::compatible_version() ) {
			return;
		}

		$this->appsero_init_tracker_toolbar_jch_optimize();

		// Load admin toolbar feature once WordPress, all plugins, and the theme are fully loaded and instantiated.
		add_action( 'wp_loaded', array( $this, 'load_toolbar' ) );
	}

	// The primary sanity check, automatically disable the plugin on activation if it doesn't
	// meet minimum requirements.
	static function activation_check() {
		if ( ! self::compatible_version() ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
		}
	}

	// The backup sanity check, in case the plugin is activated in a weird way,
	// or the versions change after activation.
	function check_version() {
		if ( ! self::compatible_version() ) {
			if ( is_plugin_active( plugin_basename( __FILE__ ) ) ) {
				deactivate_plugins( plugin_basename( __FILE__ ) );
				add_action( 'admin_notices', array( $this, 'disabled_notice' ) );
				if ( isset( $_GET['activate'] ) ) {
					unset( $_GET['activate'] );
				}
			}
		}
	}

	function disabled_notice() {
		$install_link = admin_url( 'plugin-install.php?tab=plugin-information&plugin=jch-optimize&TB_iframe=true&width=640&height=500' );
		echo '<div class="notice notice-error is-dismissible"><p>Please install and activate <a class="thickbox" href="' . $install_link . '"><strong>JCH Optimize</strong></a> plugin first.</p></div>';
	}

	static function compatible_version() {
		if ( ! defined( 'JCH_VERSION' ) ) {
			return false;
		}
		return true;
	}

	public function load_toolbar() {
		// Check permissions and that toolbar is not hidden via filter.
		if ( current_user_can( 'manage_options' ) ) {

			// Create a handler for the AJAX toolbar requests.
			add_action( 'wp_ajax_jch_helper_delete_cache', array( $this, 'delete_cache' ) );

			// Load custom styles, scripts and menu only when needed.
			if ( is_admin_bar_showing() ) {
				if ( is_admin() ) {
					add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
				} else {
					add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
				}

				// Add the Autoptimize Toolbar to the Admin bar.
				add_action( 'admin_bar_menu', array( $this, 'jch_helper_menu_callback' ), 100 );
			}
		}
	}

	function enqueue_scripts() {
		// JCH Toolbar Styles.
		wp_enqueue_style( 'jch-helper-toolbar', plugins_url( '/statics/toolbar.css', __FILE__ ), array(), JCH_HELPER_VERSION, 'all' );

		// JCH Toolbar Javascript.
		wp_enqueue_script( 'jch-helper-toolbar', plugins_url( '/statics/toolbar.js', __FILE__ ), array( 'jquery' ), JCH_HELPER_VERSION, true );

		// Localizes a registered script with data for a JavaScript variable.
		// Needed for the AJAX to work properly on the frontend.
		wp_localize_script( 'jch-helper-toolbar', 'jch_helper_ajax_object', array(
			'ajaxurl'     => admin_url( 'admin-ajax.php' ),
			// translators: links to the Autoptimize settings page.
			'error_msg'   => sprintf( __( 'Your JCH cache might not have been purged successfully, please check on the <a href=%s>JCH settings page</a>.', 'jch-optimize-toolbar' ), admin_url( 'options-general.php?page=jchoptimize-settings' ) . ' style="white-space:nowrap;"' ),
			'dismiss_msg' => __( 'Dismiss this notice.', 'jch-optimize-toolbar' ),
			'nonce'       => wp_create_nonce( 'jch_helper_delcache_nonce' ),
		) );
	}

	function delete_cache() {
		check_ajax_referer( 'jch_helper_delcache_nonce', 'nonce' );

		$result = false;
		if ( current_user_can( 'manage_options' ) ) {
			// We call the function for cleaning the Autoptimize cache.
			Helper::clearHiddenValues( Plugin::getPluginParams() );
			try {
				$result = Cache::deleteCache();
			} catch ( \JchOptimize\Core\Exception $e ) {}

			wp_send_json( $result );
		}
	}

	function jch_helper_menu_callback() {

		if ( current_user_can( 'manage_options' ) ) {
			global $wp_admin_bar;
			global $wp_filesystem;
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();

			if ( $wp_filesystem !== false && $wp_filesystem->exists( JCH_CACHE_DIR ) ) {
				$size    = 0;
				$dirlist = $wp_filesystem->dirlist( JCH_CACHE_DIR );

				foreach ( $dirlist as $file ) {
					if ( $file['name'] == 'index.html' ) {
						continue;
					}
					$size += $file['size'];
				}

				$decimals = 2;
				$sz       = 'BKMGTP';
				$factor   = (int) floor(  ( strlen( $size ) - 1 ) / 3 );
				$size     = sprintf( "%.{$decimals}f", $size / pow( 1024, $factor ) ) . $sz[$factor];

				$no_files = number_format( count( $dirlist ) - 1 );
			} else {
				$size     = '0';
				$no_files = '0';
			}

			$color = ( 100 == 60 ) ? 'red' : (  ( 60 > 80 ) ? 'orange' : 'green' );

			// "Cache Info" node.
			$wp_admin_bar->add_node( array(
				'id'    => 'jch-helper',
				'title' => '<span class="ab-icon dashicons dashicons-laptop"></span><span class="ab-label">' . __( 'JCH Helper', 'jch-optimize-toolbar' ) . '</span>',
				'href'  => admin_url( 'options-general.php?page=jchoptimize-settings' ),
				'meta'  => array( 'class' => 'bullet-' . $color ),
			) );
			$wp_admin_bar->add_node( array(
				'id'     => 'jch-helper-cache-info',
				'title'  => __( 'Cache Info', 'jch-optimize-toolbar' ) .
				'<table>' .
				'<tr><td>' . __( 'Size', 'jch-optimize-toolbar' ) . ':</td><td class="size ' . $color . '">' . $size . '</td></tr>' .
				'<tr><td>' . __( 'Files', 'jch-optimize-toolbar' ) . ':</td><td class="files white">' . $no_files . '</td></tr>' .
				'</table>',
				'parent' => 'jch-helper',
			) );

			// "Delete Cache" node.
			$wp_admin_bar->add_node( array(
				'id'     => 'jch-helper-delete-cache',
				'title'  => '<span class="dashicons dashicons-trash" style="margin: 2px 0; color: #C0110A"></span>' . __( 'Delete Cache', 'jch-optimize-toolbar' ),
				'parent' => 'jch-helper',
			) );
		}
	}

	private function appsero_init_tracker_toolbar_jch_optimize() {

		if ( ! class_exists( 'Appsero\Client' ) ) {
			require_once __DIR__ . '/appsero/src/Client.php';
		}

		$client = new Appsero\Client( 'faef1d7c-8b32-4457-99e6-5279ca712371', 'Toolbar For JCH Optimize', __FILE__ );

		// Active insights
		$client->insights()->init();

		// Active automatic updater
		$client->updater();
	}
}

global $jchHelper;
$jchHelper = new JCH_Toolbar();

register_activation_hook( __FILE__, array( 'JCH_Toolbar', 'activation_check' ) );
