<?php
/**
 * BT Accounts — account + portal user data access.
 *
 * Portal users are NOT WordPress users. They exist only inside this plugin and
 * can only ever reach /accounts. Nothing here touches wp_users or grants a
 * capability anywhere in WordPress.
 */
if (!defined('ABSPATH')) exit;

/* ── Accounts ────────────────────────────────────────────────────────────── */

function bta_get_accounts($status = null) {
    global $wpdb;
    $sql = "SELECT * FROM " . bta_table('accounts');
    if ($status) return $wpdb->get_results($wpdb->prepare($sql . " WHERE status = %s ORDER BY name ASC", $status));
    return $wpdb->get_results($sql . " ORDER BY name ASC");
}

function bta_get_account($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . bta_table('accounts') . " WHERE id = %d", (int) $id));
}

function bta_create_account($args) {
    global $wpdb;
    $name = isset($args['name']) ? trim(wp_strip_all_tags($args['name'])) : '';
    if ($name === '') return new WP_Error('bta_no_name', 'Account name is required.');

    $slug = isset($args['slug']) && $args['slug'] !== '' ? sanitize_title($args['slug']) : sanitize_title($name);
    $base = $slug; $n = 2;
    while ($wpdb->get_var($wpdb->prepare("SELECT id FROM " . bta_table('accounts') . " WHERE slug = %s", $slug))) {
        $slug = $base . '-' . $n++;
    }

    $ok = $wpdb->insert(bta_table('accounts'), array(
        'name'             => $name,
        'slug'             => $slug,
        'logo_url'         => isset($args['logo_url']) ? esc_url_raw($args['logo_url']) : '',
        'brand_color'      => bta_sanitize_hex(isset($args['brand_color']) ? $args['brand_color'] : ''),
        'pricing_profile'  => wp_json_encode(array()),
        'can_buy_garments' => !empty($args['can_buy_garments']) ? 1 : 0,
        'requires_po'      => isset($args['requires_po']) ? (!empty($args['requires_po']) ? 1 : 0) : 1,
        'status'           => 'active',
        'created_at'       => current_time('mysql'),
    ));
    if (!$ok) return new WP_Error('bta_insert_failed', 'Could not create the account.');
    return (int) $wpdb->insert_id;
}

function bta_update_account($id, $args) {
    global $wpdb;
    $id = (int) $id;
    if (!bta_get_account($id)) return new WP_Error('bta_no_account', 'Account not found.');

    $data = array();
    if (isset($args['name']))             $data['name']             = trim(wp_strip_all_tags($args['name']));
    if (isset($args['logo_url']))         $data['logo_url']         = esc_url_raw($args['logo_url']);
    if (isset($args['brand_color']))      $data['brand_color']      = bta_sanitize_hex($args['brand_color']);
    if (isset($args['can_buy_garments'])) $data['can_buy_garments'] = !empty($args['can_buy_garments']) ? 1 : 0;
    if (isset($args['requires_po']))      $data['requires_po']      = !empty($args['requires_po']) ? 1 : 0;
    if (isset($args['status']))           $data['status']           = in_array($args['status'], array('active','disabled'), true) ? $args['status'] : 'active';
    if (isset($args['pricing_profile']))  $data['pricing_profile']  = wp_json_encode($args['pricing_profile']);

    if (!$data) return true;
    $wpdb->update(bta_table('accounts'), $data, array('id' => $id));

    // A disabled account's people lose their sessions immediately.
    if (isset($data['status']) && $data['status'] === 'disabled') {
        foreach (bta_get_account_users($id) as $u) bta_kill_user_sessions($u->id);
    }
    return true;
}

function bta_sanitize_hex($hex) {
    $hex = trim((string) $hex);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $hex) ? strtolower($hex) : '#27267e';
}

/* ── Portal users ────────────────────────────────────────────────────────── */

function bta_get_account_users($account_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM " . bta_table('users') . " WHERE account_id = %d ORDER BY display_name ASC, username ASC",
        (int) $account_id
    ));
}

function bta_get_user($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . bta_table('users') . " WHERE id = %d", (int) $id));
}

function bta_get_user_by_username($username) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . bta_table('users') . " WHERE username = %s",
        bta_sanitize_username($username)
    ));
}

/** Lowercase, no spaces — usernames are compared exactly, so normalise on the way in. */
function bta_sanitize_username($u) {
    $u = strtolower(trim((string) $u));
    return preg_replace('/[^a-z0-9._@-]/', '', $u);
}

function bta_create_user($args) {
    global $wpdb;

    $account_id = isset($args['account_id']) ? (int) $args['account_id'] : 0;
    if (!bta_get_account($account_id)) return new WP_Error('bta_no_account', 'Pick an account first.');

    $username = bta_sanitize_username(isset($args['username']) ? $args['username'] : '');
    if (strlen($username) < 3) return new WP_Error('bta_bad_username', 'Username must be at least 3 characters.');
    if (bta_get_user_by_username($username)) return new WP_Error('bta_dupe_username', 'That username is already taken.');

    $password = isset($args['password']) ? (string) $args['password'] : '';
    if (strlen($password) < 6) return new WP_Error('bta_bad_password', 'Password must be at least 6 characters.');

    $ok = $wpdb->insert(bta_table('users'), array(
        'account_id'       => $account_id,
        'username'         => $username,
        'pass_hash'        => wp_hash_password($password),
        'display_name'     => trim(wp_strip_all_tags(isset($args['display_name']) ? $args['display_name'] : $username)),
        'email'            => sanitize_email(isset($args['email']) ? $args['email'] : ''),
        'is_account_admin' => !empty($args['is_account_admin']) ? 1 : 0,
        'status'           => 'active',
        'created_at'       => current_time('mysql'),
    ));
    if (!$ok) return new WP_Error('bta_insert_failed', 'Could not create the login.');
    return (int) $wpdb->insert_id;
}

function bta_update_user($id, $args) {
    global $wpdb;
    $id = (int) $id;
    $user = bta_get_user($id);
    if (!$user) return new WP_Error('bta_no_user', 'Login not found.');

    $data = array();
    if (isset($args['display_name']))     $data['display_name']     = trim(wp_strip_all_tags($args['display_name']));
    if (isset($args['email']))            $data['email']            = sanitize_email($args['email']);
    if (isset($args['is_account_admin'])) $data['is_account_admin'] = !empty($args['is_account_admin']) ? 1 : 0;
    if (isset($args['status']))           $data['status']           = in_array($args['status'], array('active','disabled'), true) ? $args['status'] : 'active';

    if (isset($args['password']) && $args['password'] !== '') {
        if (strlen($args['password']) < 6) return new WP_Error('bta_bad_password', 'Password must be at least 6 characters.');
        $data['pass_hash'] = wp_hash_password((string) $args['password']);
    }

    if (!$data) return true;
    $wpdb->update(bta_table('users'), $data, array('id' => $id));

    // Password change or disable ends every existing session for that person.
    if (isset($data['pass_hash']) || (isset($data['status']) && $data['status'] === 'disabled')) {
        bta_kill_user_sessions($id);
    }
    return true;
}

function bta_delete_user($id) {
    global $wpdb;
    bta_kill_user_sessions((int) $id);
    return (bool) $wpdb->delete(bta_table('users'), array('id' => (int) $id));
}
