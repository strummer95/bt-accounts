<?php
/**
 * BT Accounts — schema.
 *
 * v1: accounts, portal users, sessions, login attempts.
 * Order tables land in Phase 4 as v2 so the order shape can be settled first.
 */
if (!defined('ABSPATH')) exit;

define('BTA_SCHEMA_VERSION', 1);

function bta_table($name) {
    global $wpdb;
    return $wpdb->prefix . 'bta_' . $name;
}

function bta_install_schema() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();

    $accounts = bta_table('accounts');
    $users    = bta_table('users');
    $sessions = bta_table('sessions');
    $attempts = bta_table('login_attempts');

    dbDelta("CREATE TABLE $accounts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(190) NOT NULL DEFAULT '',
        slug VARCHAR(190) NOT NULL DEFAULT '',
        logo_url TEXT NULL,
        brand_color VARCHAR(9) NOT NULL DEFAULT '#0b5d8f',
        pricing_profile LONGTEXT NULL,
        can_buy_garments TINYINT(1) NOT NULL DEFAULT 0,
        requires_po TINYINT(1) NOT NULL DEFAULT 1,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
        PRIMARY KEY (id),
        UNIQUE KEY slug (slug),
        KEY status (status)
    ) $charset;");

    dbDelta("CREATE TABLE $users (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        username VARCHAR(190) NOT NULL DEFAULT '',
        pass_hash VARCHAR(255) NOT NULL DEFAULT '',
        display_name VARCHAR(190) NOT NULL DEFAULT '',
        email VARCHAR(190) NOT NULL DEFAULT '',
        is_account_admin TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        last_login_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
        PRIMARY KEY (id),
        UNIQUE KEY username (username),
        KEY account_id (account_id),
        KEY status (status)
    ) $charset;");

    dbDelta("CREATE TABLE $sessions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        token_hash CHAR(64) NOT NULL DEFAULT '',
        csrf_token CHAR(64) NOT NULL DEFAULT '',
        expires_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
        ip VARCHAR(45) NOT NULL DEFAULT '',
        ua VARCHAR(255) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
        PRIMARY KEY (id),
        UNIQUE KEY token_hash (token_hash),
        KEY user_id (user_id),
        KEY expires_at (expires_at)
    ) $charset;");

    dbDelta("CREATE TABLE $attempts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ip VARCHAR(45) NOT NULL DEFAULT '',
        username VARCHAR(190) NOT NULL DEFAULT '',
        attempted_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
        PRIMARY KEY (id),
        KEY ip_time (ip, attempted_at),
        KEY user_time (username, attempted_at)
    ) $charset;");

    update_option('bta_schema_version', BTA_SCHEMA_VERSION);
}

/** Housekeeping: drop dead sessions and stale attempt rows. */
add_action('bta_cleanup', 'bta_run_cleanup');
function bta_run_cleanup() {
    global $wpdb;
    $now = current_time('mysql');
    $wpdb->query($wpdb->prepare("DELETE FROM " . bta_table('sessions') . " WHERE expires_at < %s", $now));
    $wpdb->query($wpdb->prepare(
        "DELETE FROM " . bta_table('login_attempts') . " WHERE attempted_at < %s",
        gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)
    ));
}

add_action('init', function () {
    if (!wp_next_scheduled('bta_cleanup')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'bta_cleanup');
    }
});
