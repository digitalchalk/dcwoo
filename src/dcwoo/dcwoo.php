<?php
/*
Plugin Name: DigitalChalk / WooCommerce Integration
Plugin URI: https://github.com/digitalchalk/dcwoo
Description: Custom DigitalChalk plugin that integrates offerings with WooCommerce
Version: 2.0.3
Author: Bob Robinson (brobinson@digitalchalk.com)
Author URI: http://www.digitalchalk.com
License: The MIT License (MIT).  Copyright (c) 2014 DigitalChalk.com
Text Domain: dcwoo

This plugin provides integration between DigitalChalk and the WooCommerce plugin.
*/


// Allow custom functions file
if ( file_exists( WP_PLUGIN_DIR . '/dcwoo-custom.php' ) )
	include_once( WP_PLUGIN_DIR . '/dcwoo-custom.php' );
	
if ( !defined( 'DCWOO_ABSPATH' ) )
	define( 'DCWOO_ABSPATH', dirname( __FILE__ ) );

if (!defined('DCWOO_VERSION_KEY'))
	define('DCWOO_VERSION_KEY', 'dcwoo_version');

if (!defined('DCWOO_VERSION_NUM'))
	define('DCWOO_VERSION_NUM', '2.0.3');

add_option(DCWOO_VERSION_KEY, DCWOO_VERSION_NUM);
add_action('init', 'dcwoo_activate_updater');
	
require_once( DCWOO_ABSPATH . '/includes/class-dcwoo.php' );

function dcwoo_settings_link($links) {
	$settings_link = '<a href="options-general.php?page=dcwoo_options">Settings</a>';
	array_unshift($links, $settings_link);
	return $links;
}

function dcwoo_activate_updater() {
	require_once(DCWOO_ABSPATH .'/includes/class-dcwoo-updater.php');
	new dcwoo_updater(DCWOO_VERSION_NUM, 'https://raw.github.com/digitalchalk/dcwoo/master/update', plugin_basename(__FILE__));
}

$plugin = plugin_basename(__FILE__);
add_filter("plugin_action_links_$plugin", 'dcwoo_settings_link' );

$GLOBALS['DCWOO'] = new DCWOO();
register_activation_hook( __FILE__, array('DCWOO', 'activate') );
register_deactivation_hook( __FILE__, array('DCWOO', 'deactivate') );

?>
