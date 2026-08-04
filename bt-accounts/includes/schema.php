<?php
/**
 * BT Accounts — schema.
 *
 * v1: accounts, portal users, sessions, login attempts.
 * Order tables land in Phase 4 as v2 so the order shape can be settled first.
 */
if (!defined('ABSPATH')) exit;

define('BTA_SCHEMA_VERSION', 2);

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
        brand_color VARCHAR(9) NOT NULL DEFAULT '#27267e',
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

    /* ── v2: orders ──────────────────────────────────────────────────────── */

    $orders = bta_table('orders');
    $items  = bta_table('order_items');
    $art    = bta_table('order_art');
    $log    = bta_table('order_log');

    dbDelta("CREATE TABLE $orders (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        order_number VARCHAR(40) NOT NULL DEFAULT '',
        end_customer VARCHAR(190) NOT NULL DEFAULT '',
        account_po VARCHAR(120) NOT NULL DEFAULT '',
        supplier_name VARCHAR(190) NOT NULL DEFAULT '',
        supplier_po VARCHAR(120) NOT NULL DEFAULT '',
        expected_arrival DATE NULL,
        in_hands_date DATE NULL,
        ship_name VARCHAR(190) NOT NULL DEFAULT '',
        ship_address1 VARCHAR(190) NOT NULL DEFAULT '',
        ship_address2 VARCHAR(190) NOT NULL DEFAULT '',
        ship_city VARCHAR(120) NOT NULL DEFAULT '',
        ship_state VARCHAR(60) NOT NULL DEFAULT '',
        ship_zip VARCHAR(20) NOT NULL DEFAULT '',
        ship_method VARCHAR(60) NOT NULL DEFAULT '',
        notes MEDIUMTEXT NULL,
        status VARCHAR(60) NOT NULL DEFAULT 'Submitted',
        job_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        submitted_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
        updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
        PRIMARY KEY (id),
        UNIQUE KEY order_number (order_number),
        KEY account_id (account_id),
        KEY user_id (user_id),
        KEY status (status),
        KEY job_id (job_id),
        KEY acct_created (account_id, created_at)
    ) $charset;");

    dbDelta("CREATE TABLE $items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        catalog_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        style_no VARCHAR(60) NOT NULL DEFAULT '',
        style_name VARCHAR(255) NOT NULL DEFAULT '',
        brand VARCHAR(120) NOT NULL DEFAULT '',
        color VARCHAR(120) NOT NULL DEFAULT '',
        sizes MEDIUMTEXT NULL,
        qty INT NOT NULL DEFAULT 0,
        decoration VARCHAR(40) NOT NULL DEFAULT '',
        placement VARCHAR(120) NOT NULL DEFAULT '',
        art_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        notes TEXT NULL,
        PRIMARY KEY (id),
        KEY order_id (order_id)
    ) $charset;");

    dbDelta("CREATE TABLE $art (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        label VARCHAR(190) NOT NULL DEFAULT '',
        file_url TEXT NULL,
        file_name VARCHAR(255) NOT NULL DEFAULT '',
        uploaded_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
        PRIMARY KEY (id),
        KEY order_id (order_id),
        KEY account_id (account_id)
    ) $charset;");

    dbDelta("CREATE TABLE $log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        status VARCHAR(60) NOT NULL DEFAULT '',
        note VARCHAR(255) NOT NULL DEFAULT '',
        changed_by VARCHAR(190) NOT NULL DEFAULT '',
        changed_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
        PRIMARY KEY (id),
        KEY order_id (order_id)
    ) $charset;");

    // v2 also adds a per-account order-number prefix.
    $acc_cols = $wpdb->get_col("DESC $accounts", 0);
    if (is_array($acc_cols) && !in_array('order_prefix', $acc_cols, true)) {
        $wpdb->query("ALTER TABLE $accounts ADD COLUMN order_prefix VARCHAR(12) NOT NULL DEFAULT '' AFTER slug");
    }

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
