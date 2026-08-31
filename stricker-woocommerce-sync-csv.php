<?php
/**
 * Plugin Name: Stricker WooCommerce Catalog Sync CSV
 * Description: Downloads and prepares the Stricker catalog CSV files for WooCommerce synchronisation.
 * Version: 0.1.1
 * Author: Fabio Veneroni
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SWCS_VERSION', '0.1.1' );
define( 'SWCS_FILE', __FILE__ );
define( 'SWCS_DIR', plugin_dir_path( __FILE__ ) );
define( 'SWCS_URL', plugin_dir_url( __FILE__ ) );

require_once SWCS_DIR . 'includes/class-swcs-api.php';
require_once SWCS_DIR . 'includes/class-swcs-csv.php';
require_once SWCS_DIR . 'includes/class-swcs-admin.php';

register_activation_hook( __FILE__, array( 'SWCS_Admin', 'activate' ) );
add_action( 'plugins_loaded', array( 'SWCS_Admin', 'init' ) );
