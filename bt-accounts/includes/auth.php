<?php
/**
 * BT Accounts — authentication.
 *
 * Self-contained: portal logins are not WordPress users, so none of WP's login
 * machinery applies and everything it would have given us is rebuilt here —
 * hashing, sessions, brute-force throttling, CSRF.
 *
 * Passwords go through wp_hash_password()/wp_check_password(), which are plain
 * functions with no user object behind them. Plaintext is never stored.
 */
if (!defined('ABSPATH')) exit;

define('BTA_COOKIE',        'bta_sess');
define('BTA_SESSION_HOURS', 12);
define('BTA_MAX_ATTEMPTS',  5);
define('BTA_LOCKOUT_MINS',  15);

/* ── Request helpers ─────────────────────────────────────────────────────── */

function bta_client_ip() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    // Behind a proxy, REMOTE_ADDR is the proxy. Trust a forwarded header only
    // when the site has explicitly opted in — otherwise it is caller-controlled
    // and would let an attacker dodge the throttle by rotating the header.
    if (apply_filters('bta_trust_forwarded_ip', false) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
    }
    return substr((string) $ip, 0, 45);
}

function bta_user_agent() {
    return substr(isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '', 0, 255);
}

/* ── Throttling ──────────────────────────────────────────────────────────── */

function bta_record_attempt($username) {
    global $wpdb;
    $wpdb->insert(bta_table('login_attempts'), array(
        'ip'           => bta_client_ip(),
        'username'     => bta_sanitize_username($username),
        'attempted_at' => current_time('mysql'),
    ));
}

/** True when this IP or this username has burned through the allowance. */
function bta_is_locked_out($username) {
    global $wpdb;
    $since = gmdate('Y-m-d H:i:s', strtotime(current_time('mysql')) - (BTA_LOCKOUT_MINS * 60));
    $table = bta_table('login_attempts');

    $by_ip = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE ip = %s AND attempted_at > %s", bta_client_ip(), $since
    ));
    if ($by_ip >= BTA_MAX_ATTEMPTS) return true;

    $by_user = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE username = %s AND attempted_at > %s",
        bta_sanitize_username($username), $since
    ));
    return $by_user >= BTA_MAX_ATTEMPTS;
}

function bta_clear_attempts($username) {
    global $wpdb;
    $table = bta_table('login_attempts');
    $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE ip = %s", bta_client_ip()));
    $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE username = %s", bta_sanitize_username($username)));
}

/* ── Login / logout ──────────────────────────────────────────────────────── */

/**
 * Attempt a login. Returns the user row on success, WP_Error otherwise.
 * Failure messages are deliberately identical so the form cannot be used to
 * discover which usernames exist.
 */
function bta_login($username, $password) {
    $generic = new WP_Error('bta_bad_login', 'That username and password did not match.');

    if (bta_is_locked_out($username)) {
        return new WP_Error('bta_locked', 'Too many attempts. Try again in ' . BTA_LOCKOUT_MINS . ' minutes.');
    }

    $user = bta_get_user_by_username($username);
    if (!$user || $user->status !== 'active') {
        bta_record_attempt($username);
        return $generic;
    }

    if (!wp_check_password((string) $password, $user->pass_hash)) {
        bta_record_attempt($username);
        return $generic;
    }

    $account = bta_get_account($user->account_id);
    if (!$account || $account->status !== 'active') {
        bta_record_attempt($username);
        return $generic;
    }

    bta_clear_attempts($username);
    bta_start_session($user);
    return $user;
}

function bta_start_session($user) {
    global $wpdb;

    // Fresh token on every login — an old cookie value is never reusable.
    $token = bin2hex(random_bytes(32));
    $csrf  = bin2hex(random_bytes(32));
    $expires = gmdate('Y-m-d H:i:s', strtotime(current_time('mysql')) + (BTA_SESSION_HOURS * 3600));

    $wpdb->insert(bta_table('sessions'), array(
        'user_id'    => (int) $user->id,
        'token_hash' => hash('sha256', $token),  // only the hash is stored
        'csrf_token' => $csrf,
        'expires_at' => $expires,
        'ip'         => bta_client_ip(),
        'ua'         => bta_user_agent(),
        'created_at' => current_time('mysql'),
    ));

    $wpdb->update(bta_table('users'), array('last_login_at' => current_time('mysql')), array('id' => (int) $user->id));

    bta_set_cookie($token);
    return $token;
}

function bta_set_cookie($token) {
    $args = array(
        'expires'  => time() + (BTA_SESSION_HOURS * 3600),
        'path'     => '/',
        'secure'   => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
    );
    if (PHP_VERSION_ID >= 70300) {
        setcookie(BTA_COOKIE, $token, $args);
    } else {
        setcookie(BTA_COOKIE, $token, $args['expires'], $args['path'] . '; samesite=Lax', '', $args['secure'], true);
    }
    $_COOKIE[BTA_COOKIE] = $token;
}

function bta_clear_cookie() {
    if (PHP_VERSION_ID >= 70300) {
        setcookie(BTA_COOKIE, '', array('expires' => time() - 3600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax'));
    } else {
        setcookie(BTA_COOKIE, '', time() - 3600, '/', '', is_ssl(), true);
    }
    unset($_COOKIE[BTA_COOKIE]);
}

function bta_logout() {
    global $wpdb;
    if (!empty($_COOKIE[BTA_COOKIE])) {
        $wpdb->delete(bta_table('sessions'), array('token_hash' => hash('sha256', (string) $_COOKIE[BTA_COOKIE])));
    }
    bta_clear_cookie();
}

function bta_kill_user_sessions($user_id) {
    global $wpdb;
    $wpdb->delete(bta_table('sessions'), array('user_id' => (int) $user_id));
}

/* ── Current session ─────────────────────────────────────────────────────── */

/**
 * The logged-in portal user for this request, or null.
 * Resolved from the session cookie only — never from a submitted parameter,
 * so one account can't be addressed by a login belonging to another.
 */
function bta_current_user() {
    static $resolved = false, $cache = null;
    if ($resolved) return $cache;
    $resolved = true;

    if (empty($_COOKIE[BTA_COOKIE])) return $cache = null;

    global $wpdb;
    $session = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . bta_table('sessions') . " WHERE token_hash = %s",
        hash('sha256', (string) $_COOKIE[BTA_COOKIE])
    ));
    if (!$session) return $cache = null;

    if (strtotime($session->expires_at) < strtotime(current_time('mysql'))) {
        $wpdb->delete(bta_table('sessions'), array('id' => (int) $session->id));
        bta_clear_cookie();
        return $cache = null;
    }

    $user = bta_get_user((int) $session->user_id);
    if (!$user || $user->status !== 'active') return $cache = null;

    $account = bta_get_account((int) $user->account_id);
    if (!$account || $account->status !== 'active') return $cache = null;

    $user->session = $session;
    $user->account = $account;
    return $cache = $user;
}

function bta_current_account() {
    $u = bta_current_user();
    return $u ? $u->account : null;
}

function bta_is_logged_in() {
    return (bool) bta_current_user();
}

/* ── CSRF ────────────────────────────────────────────────────────────────── */

/**
 * WP nonces are tied to a WP user, so portal forms carry a per-session token
 * of our own instead.
 */
function bta_csrf_token() {
    $u = bta_current_user();
    return $u ? $u->session->csrf_token : '';
}

function bta_csrf_field() {
    return '<input type="hidden" name="bta_csrf" value="' . esc_attr(bta_csrf_token()) . '">';
}

function bta_verify_csrf() {
    $sent = isset($_POST['bta_csrf']) ? (string) $_POST['bta_csrf'] : '';
    $real = bta_csrf_token();
    return $real !== '' && hash_equals($real, $sent);
}
