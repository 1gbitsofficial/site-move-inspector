<?php
/**
 * Plugin Name:       1Gbits Site Move Inspector
 * Description:       Run a private, read-only preflight before moving a WordPress site to a new hosting environment.
 * Version:           1.0.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            1Gbits
 * Author URI:        https://1gbits.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       1gbits-site-move-inspector
 *
 * @package OneGbits_Site_Move_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OGSMI_VERSION', '1.0.1' );
define( 'OGSMI_PLUGIN_FILE', __FILE__ );
define( 'OGSMI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OGSMI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once OGSMI_PLUGIN_DIR . 'includes/class-ogsmi-utils.php';
require_once OGSMI_PLUGIN_DIR . 'includes/class-ogsmi-locations.php';
require_once OGSMI_PLUGIN_DIR . 'includes/class-ogsmi-job-store.php';
require_once OGSMI_PLUGIN_DIR . 'includes/class-ogsmi-report-builder.php';
require_once OGSMI_PLUGIN_DIR . 'includes/class-ogsmi-file-scanner.php';
require_once OGSMI_PLUGIN_DIR . 'includes/class-ogsmi-inspector.php';
require_once OGSMI_PLUGIN_DIR . 'includes/class-ogsmi-redactor.php';
require_once OGSMI_PLUGIN_DIR . 'includes/class-ogsmi-exporter.php';
require_once OGSMI_PLUGIN_DIR . 'includes/class-ogsmi-rest-controller.php';
require_once OGSMI_PLUGIN_DIR . 'includes/class-ogsmi-admin-page.php';
require_once OGSMI_PLUGIN_DIR . 'includes/class-ogsmi-plugin.php';

OGSMI_Plugin::instance();
