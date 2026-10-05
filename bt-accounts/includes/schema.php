<?php
/**
 * BT Accounts — schema.
 *
 * v1: accounts, portal users, sessions, login attempts.
 * Order tables land in Phase 4 as v2 so the order shape can be settled first.
 * v3: print/embroidery locations and a price per item line, and the Printavo
 *     link on each order. dbDelta adds the new columns to existing tables.
 * v4: merch-store accounts (Leonid & Friends first): a product list, an art
 *     library, bulk / on-demand orders with prices, and payments.
 * v5: colour versions on library art; Leonid's Bottle Cap design.
 * v6: print-location boxes per product, for mockups.
 * v7: Leonid's Deep in the Heart of Texas and Make Me Smile Tour 2026 designs.
 * v8: garment-colour limits on art, a second print location per line with its
 *     own price per product, and Leonid's Tour 2026 record and Fall 2026 dates.
 */
if (!defined('ABSPATH')) exit;

define('BTA_SCHEMA_VERSION', 8);

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
        kind VARCHAR(20) NOT NULL DEFAULT 'contract',
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
        order_type VARCHAR(20) NOT NULL DEFAULT '',
        external_ref VARCHAR(120) NOT NULL DEFAULT '',
        event_name VARCHAR(190) NOT NULL DEFAULT '',
        ship_email VARCHAR(190) NOT NULL DEFAULT '',
        ship_phone VARCHAR(40) NOT NULL DEFAULT '',
        subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
        shipping DECIMAL(10,2) NOT NULL DEFAULT 0,
        adjustment DECIMAL(10,2) NOT NULL DEFAULT 0,
        amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0,
        status VARCHAR(60) NOT NULL DEFAULT 'Submitted',
        job_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        printavo_state VARCHAR(20) NOT NULL DEFAULT '',
        printavo_id VARCHAR(64) NOT NULL DEFAULT '',
        printavo_number VARCHAR(40) NOT NULL DEFAULT '',
        printavo_url TEXT NULL,
        printavo_error TEXT NULL,
        printavo_log MEDIUMTEXT NULL,
        printavo_at DATETIME NULL,
        submitted_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
        updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
        PRIMARY KEY (id),
        UNIQUE KEY order_number (order_number),
        KEY account_id (account_id),
        KEY user_id (user_id),
        KEY status (status),
        KEY job_id (job_id),
        KEY printavo_state (printavo_state),
        KEY acct_created (account_id, created_at)
    ) $charset;");

    dbDelta("CREATE TABLE $items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        catalog_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        style_no VARCHAR(60) NOT NULL DEFAULT '',
        style_name VARCHAR(255) NOT NULL DEFAULT '',
        brand VARCHAR(120) NOT NULL DEFAULT '',
        color VARCHAR(120) NOT NULL DEFAULT '',
        sizes MEDIUMTEXT NULL,
        qty INT NOT NULL DEFAULT 0,
        decoration VARCHAR(40) NOT NULL DEFAULT '',
        placement VARCHAR(255) NOT NULL DEFAULT '',
        art_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        locations MEDIUMTEXT NULL,
        unit_price DECIMAL(10,2) NULL,
        line_total DECIMAL(10,2) NULL,
        price_note VARCHAR(255) NOT NULL DEFAULT '',
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

    /* ── v4: merch stores ─────────────────────────────────────────────────── */

    $products = bta_table('products');
    $library  = bta_table('art_library');
    $payments = bta_table('payments');

    dbDelta("CREATE TABLE $products (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        name VARCHAR(190) NOT NULL DEFAULT '',
        store_ref VARCHAR(190) NOT NULL DEFAULT '',
        style_no VARCHAR(60) NOT NULL DEFAULT '',
        brand VARCHAR(120) NOT NULL DEFAULT '',
        colors VARCHAR(255) NOT NULL DEFAULT '',
        sizes VARCHAR(255) NOT NULL DEFAULT '',
        channels VARCHAR(20) NOT NULL DEFAULT 'both',
        image_url TEXT NULL,
        decoration VARCHAR(40) NOT NULL DEFAULT 'print',
        placement VARCHAR(120) NOT NULL DEFAULT '',
        art_ids VARCHAR(255) NOT NULL DEFAULT '',
        zones TEXT NULL,
        bulk_price DECIMAL(10,2) NULL,
        ondemand_price DECIMAL(10,2) NULL,
        upcharge DECIMAL(10,2) NOT NULL DEFAULT 0,
        extra_price DECIMAL(10,2) NULL,
        notes TEXT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
        PRIMARY KEY (id),
        KEY account_id (account_id),
        KEY status (status)
    ) $charset;");

    dbDelta("CREATE TABLE $library (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        name VARCHAR(190) NOT NULL DEFAULT '',
        file_url TEXT NULL,
        file_name VARCHAR(255) NOT NULL DEFAULT '',
        preview_url TEXT NULL,
        placement VARCHAR(120) NOT NULL DEFAULT '',
        colors VARCHAR(190) NOT NULL DEFAULT '',
        notes TEXT NULL,
        variants MEDIUMTEXT NULL,
        garment_colors VARCHAR(255) NOT NULL DEFAULT '',
        added_by VARCHAR(20) NOT NULL DEFAULT 'shop',
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
        PRIMARY KEY (id),
        KEY account_id (account_id),
        KEY status (status)
    ) $charset;");

    dbDelta("CREATE TABLE $payments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        method VARCHAR(60) NOT NULL DEFAULT '',
        reference VARCHAR(190) NOT NULL DEFAULT '',
        stripe_session VARCHAR(190) NULL,
        note VARCHAR(255) NOT NULL DEFAULT '',
        recorded_by VARCHAR(190) NOT NULL DEFAULT '',
        paid_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
        PRIMARY KEY (id),
        UNIQUE KEY stripe_session (stripe_session),
        KEY order_id (order_id),
        KEY account_id (account_id)
    ) $charset;");

    // v2 also adds a per-account order-number prefix.
    $acc_cols = $wpdb->get_col("DESC $accounts", 0);
    if (is_array($acc_cols) && !in_array('order_prefix', $acc_cols, true)) {
        $wpdb->query("ALTER TABLE $accounts ADD COLUMN order_prefix VARCHAR(12) NOT NULL DEFAULT '' AFTER slug");
    }

    update_option('bta_schema_version', BTA_SCHEMA_VERSION);

    bta_seed_leonid();
    bta_seed_bottle_cap();
    bta_seed_leonid_designs();
    bta_seed_leonid_tour();
}

/**
 * Leonid's second Tour 2026 front (the record) and the Fall 2026 tour-dates
 * back. The dates are white text, so they go on black shirts only. The first
 * Tour 2026 design becomes "(Van)" so the two can be told apart.
 */
function bta_seed_leonid_tour() {
    global $wpdb;
    if (get_option('bta_seed_leonid_tour_done')) return;
    $acct_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM " . bta_table('accounts') . " WHERE slug = %s", 'leonid-and-friends'));
    if (!$acct_id) return;
    $lib = bta_table('art_library');

    $wpdb->update($lib, array('name' => 'Make Me Smile Tour 2026 (Van)'),
        array('account_id' => $acct_id, 'name' => 'Make Me Smile Tour 2026'));

    $designs = array(
        array('Make Me Smile Tour 2026 (Record)', 'make-me-smile-tour-2026-record.png', 'leonid-make-me-smile-tour-2026-record.png', '', 'Full Front', '', 'Record, Tour 2026 and the band logo.'),
        array('Fall 2026 Tour Dates', 'fall-2026-tour-dates.png', 'leonid-fall-2026-tour-dates.png', 'fall-2026-tour-dates-preview.png', 'Full Back', 'Black', 'Band logo, Make Me Smile Fall 2026 and every date, Sept 13 to Nov 28. White text: black shirts only.'),
    );
    foreach ($designs as $d) {
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $lib WHERE account_id = %d AND name = %s", $acct_id, $d[0]))) continue;
        $url  = bta_seed_art_file($d[1], $d[2]);
        $prev = $d[3] !== '' ? bta_seed_art_file($d[3], 'leonid-' . $d[3]) : $url;
        $wpdb->insert($lib, array(
            'account_id'     => $acct_id,
            'name'           => $d[0],
            'file_url'       => $url,
            'file_name'      => $d[2],
            'preview_url'    => $prev,
            'placement'      => $d[4],
            'garment_colors' => $d[5],
            'colors'         => '',
            'notes'          => $d[6],
            'variants'       => '',
            'added_by'       => 'shop',
            'status'         => 'active',
            'created_at'     => current_time('mysql'),
        ));
    }
    if (function_exists('bta_protect_art_dir')) bta_protect_art_dir();
    update_option('bta_seed_leonid_tour_done', 1);
}

/**
 * Copy one design file shipped in assets/art/ into the uploads art folder
 * (once) and return its URL there, or the plugin URL if the copy fails.
 */
function bta_seed_art_file($src_name, $dest_name) {
    $src = BTA_DIR . 'assets/art/' . $src_name;
    $url = BTA_URL . 'assets/art/' . $src_name;
    $up  = wp_upload_dir();
    if (!empty($up['error']) || !file_exists($src)) return $url;
    $dir = $up['basedir'] . '/bt-accounts-art';
    if (!is_dir($dir)) wp_mkdir_p($dir);
    if (file_exists($dir . '/' . $dest_name) || @copy($src, $dir . '/' . $dest_name)) {
        return $up['baseurl'] . '/bt-accounts-art/' . $dest_name;
    }
    return $url;
}

/** Leonid's full-front shirt designs (Oct 5 2026). One run; deleting one later sticks. */
function bta_seed_leonid_designs() {
    global $wpdb;
    if (get_option('bta_seed_leonid_designs_done')) return;
    $acct_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM " . bta_table('accounts') . " WHERE slug = %s", 'leonid-and-friends'));
    if (!$acct_id) return;
    $lib = bta_table('art_library');

    $designs = array(
        array('Deep in the Heart of Texas', 'deep-in-the-heart-of-texas.png', 'leonid-deep-in-the-heart-of-texas.png', 'Texas flag state with the band logo, rider and cactus.'),
        array('Make Me Smile Tour 2026',    'make-me-smile-tour-2026.png',    'leonid-make-me-smile-tour-2026.png',    'Tour van, wave, palm and flowers.'),
    );
    foreach ($designs as $d) {
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $lib WHERE account_id = %d AND name = %s", $acct_id, $d[0]))) continue;
        $url = bta_seed_art_file($d[1], $d[2]);
        $wpdb->insert($lib, array(
            'account_id'  => $acct_id,
            'name'        => $d[0],
            'file_url'    => $url,
            'file_name'   => $d[2],
            'preview_url' => $url,
            'placement'   => 'Full Front',
            'colors'      => '',
            'notes'       => $d[3],
            'variants'    => '',
            'added_by'    => 'shop',
            'status'      => 'active',
            'created_at'  => current_time('mysql'),
        ));
    }
    if (function_exists('bta_protect_art_dir')) bta_protect_art_dir();
    update_option('bta_seed_leonid_designs_done', 1);
}

/**
 * Leonid's Bottle Cap design ("Make Me Smile" roundel), shipped inside the
 * plugin because Dillon works only through the dashboard. Copied once into the
 * uploads art folder so its link outlives plugin updates. Goes on the cap's
 * front or a shirt's left chest; the cap version follows the cap colour.
 * Versions 2 and 3 have no files yet: the shop pastes their links in later.
 */
function bta_seed_bottle_cap() {
    global $wpdb;
    if (get_option('bta_seed_bottlecap_done')) return;
    $acct_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM " . bta_table('accounts') . " WHERE slug = %s", 'leonid-and-friends'));
    if (!$acct_id) return;

    $urls = array();
    $up   = wp_upload_dir();
    foreach (array('pdf', 'png') as $ext) {
        $src  = BTA_DIR . 'assets/art/bottle-cap.' . $ext;
        $urls[$ext] = BTA_URL . 'assets/art/bottle-cap.' . $ext;
        if (empty($up['error']) && file_exists($src)) {
            $dir = $up['basedir'] . '/bt-accounts-art';
            if (!is_dir($dir)) wp_mkdir_p($dir);
            if (file_exists($dir . '/leonid-bottle-cap.' . $ext) || @copy($src, $dir . '/leonid-bottle-cap.' . $ext)) {
                $urls[$ext] = $up['baseurl'] . '/bt-accounts-art/leonid-bottle-cap.' . $ext;
            }
        }
    }
    if (function_exists('bta_protect_art_dir')) bta_protect_art_dir();

    $lib = bta_table('art_library');
    if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM $lib WHERE account_id = %d AND name = %s", $acct_id, 'Bottle Cap'))) {
        $wpdb->insert($lib, array(
            'account_id'  => $acct_id,
            'name'        => 'Bottle Cap',
            'file_url'    => $urls['pdf'],
            'file_name'   => 'leonid-bottle-cap.pdf',
            'preview_url' => $urls['png'],
            'placement'   => 'Hat Front, Left Chest',
            'colors'      => '',
            'notes'       => 'Make Me Smile roundel. Hat front or left chest.',
            'variants'    => wp_json_encode(array(
                array('label' => '1', 'colors' => array('Black'), 'file_url' => $urls['pdf'], 'preview_url' => $urls['png'], 'note' => 'Full colour'),
                array('label' => '2', 'colors' => array('Red'), 'file_url' => '', 'preview_url' => '', 'note' => 'Blue and yellow'),
                array('label' => '3', 'colors' => array('Khaki', 'Brown'), 'file_url' => '', 'preview_url' => '', 'note' => 'Yellow and red'),
            )),
            'added_by'    => 'shop',
            'status'      => 'active',
            'created_at'  => current_time('mysql'),
        ));
    }
    update_option('bta_seed_bottlecap_done', 1);
}

/**
 * Leonid & Friends: the first merch-store account, with Kim's login.
 * Runs once. If Dillon later deletes or renames either, it does not come back.
 * Only a bcrypt hash of the starting password is kept here, never the password.
 */
function bta_seed_leonid() {
    global $wpdb;
    if (get_option('bta_seed_leonid_done')) return;

    $accounts = bta_table('accounts');
    $acct_id  = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $accounts WHERE slug = %s", 'leonid-and-friends'));
    if (!$acct_id) {
        $wpdb->insert($accounts, array(
            'name'             => 'Leonid & Friends',
            'slug'             => 'leonid-and-friends',
            'order_prefix'     => 'LAF',
            'logo_url'         => '',
            'brand_color'      => '#27267e',
            'pricing_profile'  => wp_json_encode(array()),
            'can_buy_garments' => 1,
            'requires_po'      => 0,
            'kind'             => 'merch',
            'status'           => 'active',
            'created_at'       => current_time('mysql'),
        ));
        $acct_id = (int) $wpdb->insert_id;
    }

    $users = bta_table('users');
    if ($acct_id && !$wpdb->get_var($wpdb->prepare("SELECT id FROM $users WHERE username = %s", 'kim'))) {
        $wpdb->insert($users, array(
            'account_id'       => $acct_id,
            'username'         => 'kim',
            'pass_hash'        => '$2y$10$9S8NBAuBaQ7UEFnlZc4wTeqEgGwqRyMzHFX26oecaJl8.Rc66Ne0a',
            'display_name'     => 'Kim',
            'email'            => '',
            'is_account_admin' => 1,
            'status'           => 'active',
            'created_at'       => current_time('mysql'),
        ));
    }

    // The store's line-up as Dillon gave it. Prices are left for the shop to set.
    $products = bta_table('products');
    if ($acct_id && !$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $products WHERE account_id = %d", $acct_id))) {
        $seed = array(
            array('Heavy Cotton Tee', 'Gildan',         '5000',   'Black,Sport Grey,White',         'S,M,L,XL,2XL,3XL,4XL,5XL', 'both',     'print',      'Full Front'),
            array('Ladies Heavy Cotton V-Neck', 'Gildan',         '5V00L',  'Black,Sport Grey,White',         'S,M,L,XL,2XL,3XL',         'both',     'print',      'Full Front'),
            array('Heavy Cotton Long Sleeve Tee', 'Gildan',         '5400',   'Black,Sport Grey,White',         'S,M,L,XL,2XL,3XL',         'both',     'print',      'Full Front'),
            array('Chino Cap', 'Valucap',        'VC300A', 'Black,White,Khaki,Red',          'OSFA',                     'both',     'embroidery', 'Hat Front'),
            array('Ladies Core Cotton V-Neck', 'Port & Company', 'LPC54V', 'Black,White',            'S,M,L,XL,2XL,3XL,4XL',     'ondemand', 'print',      'Full Front'),
        );
        foreach ($seed as $i => $r) {
            $wpdb->insert($products, array(
                'account_id' => $acct_id, 'name' => $r[0], 'brand' => $r[1], 'style_no' => $r[2],
                'colors' => $r[3], 'sizes' => $r[4], 'channels' => $r[5], 'decoration' => $r[6],
                'placement' => $r[7], 'sort_order' => $i, 'status' => 'active', 'created_at' => current_time('mysql'),
            ));
        }
    }

    if ($acct_id) update_option('bta_seed_leonid_done', 1);
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
