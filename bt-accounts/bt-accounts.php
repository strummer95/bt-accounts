<?php
/*
Plugin Name: BT Accounts
Plugin URI: https://boomerts.com
Description: Contract account portal for Boomer T's. Each account gets its own branded login at /accounts, its own pricing profile, an order entry form, and live order status pulled from the shop's job cards.
Version: 0.5.0
Author: Duck and Rabbit Co.
*/

if (!defined('ABSPATH')) exit;

define('BTA_VERSION', '0.5.0');
define('BTA_DIR', plugin_dir_path(__FILE__));
define('BTA_URL', plugin_dir_url(__FILE__));
define('BTA_FILE', __FILE__);

/** Portal path. /accounts by default; filterable if it ever needs to move. */
function bta_portal_slug() {
    return apply_filters('bta_portal_slug', 'accounts');
}

require_once BTA_DIR . 'includes/schema.php';
require_once BTA_DIR . 'includes/accounts.php';
require_once BTA_DIR . 'includes/auth.php';
require_once BTA_DIR . 'includes/pricing.php';
require_once BTA_DIR . 'includes/orders.php';
require_once BTA_DIR . 'includes/portal-orders.php';
require_once BTA_DIR . 'includes/portal-quote.php';
require_once BTA_DIR . 'includes/bt-admin.php';
require_once BTA_DIR . 'includes/admin.php';
require_once BTA_DIR . 'includes/admin-orders.php';
require_once BTA_DIR . 'includes/portal.php';
require_once BTA_DIR . 'includes/updater.php';

register_activation_hook(BTA_FILE, 'bta_activate');
function bta_activate() {
    bta_install_schema();
    bta_register_rewrite();
    flush_rewrite_rules();
}

register_deactivation_hook(BTA_FILE, 'flush_rewrite_rules');

/** Run pending migrations on load when the stored schema version is behind. */
add_action('plugins_loaded', 'bta_maybe_migrate');
function bta_maybe_migrate() {
    if ((int) get_option('bta_schema_version', 0) < BTA_SCHEMA_VERSION) {
        bta_install_schema();
    }
}
