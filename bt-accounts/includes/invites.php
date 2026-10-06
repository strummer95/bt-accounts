<?php
/**
 * BT Accounts — invites, password resets, password changes.
 *
 * People sign in with their email. The shop invites them by name and email;
 * the invite email carries a one-time link where they choose their own
 * password, and the shop never handles it. The same link mechanism serves
 * "Forgot your password?".
 *
 * Links are 32 random bytes; only a sha256 of the token is stored, on the
 * login row, so a database read cannot be turned into a working link. One
 * link per login at a time: sending a new one kills the old. Invites last
 * 7 days, resets 1 hour.
 */
if (!defined('ABSPATH')) exit;

define('BTA_INVITE_DAYS', 7);
define('BTA_RESET_MINS', 60);
define('BTA_MIN_PASSWORD', 8);

/* ── Lookups ─────────────────────────────────────────────────────────────── */

function bta_get_user_by_email($email) {
    global $wpdb;
    $email = strtolower(trim((string) $email));
    if ($email === '') return null;
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . bta_table('users') . " WHERE LOWER(email) = %s ORDER BY id ASC LIMIT 1", $email
    ));
}

/**
 * The login someone means when they type into the sign-in box: their email,
 * or, for logins made by hand before invites, their username.
 */
function bta_get_user_for_signin($typed) {
    $u = bta_get_user_by_username($typed);
    if ($u) return $u;
    return strpos((string) $typed, '@') !== false ? bta_get_user_by_email($typed) : null;
}

/* ── Links ───────────────────────────────────────────────────────────────── */

/** Make a fresh one-time link for a login. Returns the URL. */
function bta_issue_link($user, $kind) {
    global $wpdb;
    $token = bin2hex(random_bytes(32));
    $secs  = $kind === 'invite' ? BTA_INVITE_DAYS * DAY_IN_SECONDS : BTA_RESET_MINS * MINUTE_IN_SECONDS;
    $wpdb->update(bta_table('users'), array(
        'token_hash'    => hash('sha256', $token),
        'token_kind'    => $kind,
        'token_expires' => date('Y-m-d H:i:s', current_time('timestamp') + $secs),
    ), array('id' => (int) $user->id));
    return add_query_arg(array('u' => (int) $user->id, 't' => $token), bta_portal_url('set-password'));
}

/** The login a link belongs to, or WP_Error saying why it will not do. */
function bta_check_link($user_id, $token) {
    $user = bta_get_user((int) $user_id);
    $token = (string) $token;
    if (!$user || $token === '' || $user->token_hash === '' || !hash_equals($user->token_hash, hash('sha256', $token))) {
        return new WP_Error('bta_link_bad', 'That link has already been used or has been replaced by a newer one.');
    }
    if (!$user->token_expires || strtotime($user->token_expires) < current_time('timestamp')) {
        return new WP_Error('bta_link_old', $user->token_kind === 'invite'
            ? 'That invite link has expired.'
            : 'That link has expired. Reset links only last an hour.');
    }
    if ($user->status === 'disabled') {
        return new WP_Error('bta_link_off', 'This login has been turned off. Email orders@boomerts.com and we will switch it back on.');
    }
    return $user;
}

function bta_clear_link($user_id) {
    global $wpdb;
    $wpdb->update(bta_table('users'), array('token_hash' => '', 'token_kind' => '', 'token_expires' => null), array('id' => (int) $user_id));
}

/* ── Invites (shop side) ─────────────────────────────────────────────────── */

/**
 * Invite someone to an account. Creates their login with no password yet
 * (status 'invited') and emails them the link. Returns the user id or WP_Error.
 */
function bta_invite_user($account_id, $name, $email, $is_admin) {
    global $wpdb;
    $account = bta_get_account((int) $account_id);
    if (!$account) return new WP_Error('bta_no_account', 'Pick an account first.');

    $email = strtolower(sanitize_email((string) $email));
    if (!is_email($email)) return new WP_Error('bta_bad_email', 'That is not a valid email address.');
    $name = trim(wp_strip_all_tags((string) $name));

    $existing = bta_get_user_by_email($email);
    if (!$existing) $existing = bta_get_user_by_username($email);
    if ($existing) {
        if ((int) $existing->account_id !== (int) $account->id) {
            return new WP_Error('bta_email_taken', $email . ' already has a login on another account.');
        }
        if ($existing->status !== 'invited') {
            return new WP_Error('bta_email_taken', $email . ' already has a login here. Use "Send reset link" on it instead.');
        }
        return bta_send_invite($existing) ? (int) $existing->id : new WP_Error('bta_mail', 'The invite could not be emailed. Check the site\'s email settings.');
    }

    $ok = $wpdb->insert(bta_table('users'), array(
        'account_id'       => (int) $account->id,
        'username'         => bta_sanitize_username($email),
        'pass_hash'        => '',
        'display_name'     => $name !== '' ? $name : $email,
        'email'            => $email,
        'is_account_admin' => $is_admin ? 1 : 0,
        'status'           => 'invited',
        'created_at'       => current_time('mysql'),
    ));
    if (!$ok) return new WP_Error('bta_insert_failed', 'Could not create the login.');
    $user = bta_get_user((int) $wpdb->insert_id);

    if (!bta_send_invite($user)) {
        return new WP_Error('bta_mail', 'The login was made but the invite could not be emailed. Check the site\'s email settings, then press Resend invite.');
    }
    return (int) $user->id;
}

function bta_send_invite($user) {
    $account = bta_get_account($user->account_id);
    $url = bta_issue_link($user, 'invite');
    $p = bta_mail_p();
    $hi = $user->display_name && $user->display_name !== $user->email ? 'Hi ' . esc_html(strtok($user->display_name, ' ')) . ',' : 'Hi,';
    $inner = '<p style="' . $p . '">' . $hi . '</p>'
        . '<p style="' . $p . '">Boomer T&rsquo;s has set up a login for you on the <strong>' . esc_html($account ? $account->name : '') . '</strong> account portal, where you can place orders, get quotes on your rates and follow each order through the shop.</p>'
        . '<p style="' . $p . '">Press the button to choose your password. You will sign in with this email address, <strong>' . esc_html($user->email) . '</strong>.</p>'
        . bta_mail_button(bta_link_for_mail($url), 'Set up my login', '#e535ab')
        . '<p style="' . $p . ';font-size:13px;color:#6b7280">The link works once and lasts ' . BTA_INVITE_DAYS . ' days. After that, ask us for a new one or use &ldquo;Forgot your password?&rdquo; on the sign-in page.</p>';
    return wp_mail($user->email, 'Your Boomer T\'s account login', bta_mail_wrap('You\'re invited', $account, $account && $account->brand_color ? $account->brand_color : '#27267e', $inner), bta_mail_headers());
}

/** Reset link for a login that already has a password (or a fresh invite if it never set one). */
function bta_send_reset($user) {
    if ($user->status === 'invited') return bta_send_invite($user);
    $account = bta_get_account($user->account_id);
    $url = bta_issue_link($user, 'reset');
    $p = bta_mail_p();
    $inner = '<p style="' . $p . '">Someone asked to reset the password for <strong>' . esc_html($user->email) . '</strong> on the Boomer T&rsquo;s account portal.</p>'
        . bta_mail_button(bta_link_for_mail($url), 'Choose a new password', '#e535ab')
        . '<p style="' . $p . ';font-size:13px;color:#6b7280">The link works once and lasts an hour. If you did not ask for this, ignore this email and your password stays as it is.</p>';
    return wp_mail($user->email, 'Reset your Boomer T\'s portal password', bta_mail_wrap('Reset your password', $account, $account && $account->brand_color ? $account->brand_color : '#27267e', $inner), bta_mail_headers());
}

/** Links go out as-is; kept separate so mail filters that rewrite URLs are easy to reason about. */
function bta_link_for_mail($url) {
    return $url;
}

/* ── Portal pages (signed out) ───────────────────────────────────────────── */

/**
 * /accounts/set-password?u=…&t=… — choose a password from an invite or reset
 * link, then signed straight in.
 */
function bta_handle_set_password() {
    $uid   = isset($_REQUEST['u']) ? (int) $_REQUEST['u'] : 0;
    $token = isset($_REQUEST['t']) ? (string) wp_unslash($_REQUEST['t']) : '';
    $user  = bta_check_link($uid, $token);

    $error = '';
    if (!is_wp_error($user) && !empty($_POST['bta_set_password'])) {
        $pw  = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';
        $pw2 = isset($_POST['password2']) ? (string) wp_unslash($_POST['password2']) : '';
        if (strlen($pw) < BTA_MIN_PASSWORD) {
            $error = 'Make the password at least ' . BTA_MIN_PASSWORD . ' characters.';
        } elseif ($pw !== $pw2) {
            $error = 'The two passwords do not match. Type the same one in both boxes.';
        } else {
            global $wpdb;
            $wpdb->update(bta_table('users'), array('pass_hash' => wp_hash_password($pw), 'status' => 'active'), array('id' => (int) $user->id));
            bta_clear_link($user->id);
            bta_kill_user_sessions($user->id);
            bta_clear_attempts($user->username);
            $account = bta_get_account($user->account_id);
            if (!$account || $account->status !== 'active') {
                // Password saved, but the company account is off: the sign-in page will say so.
                wp_safe_redirect(home_url('/' . bta_portal_slug() . '/'));
                exit;
            }
            bta_start_session(bta_get_user($user->id));
            wp_safe_redirect(add_query_arg('signedin', '1', home_url('/' . bta_portal_slug() . '/')));
            exit;
        }
    }

    bta_head('Set your password · Boomer T\'s');
    bta_auth_card_open();
    if (is_wp_error($user)) {
        echo '<div class="bta-alert" role="alert">' . esc_html($user->get_error_message()) . '</div>';
        echo '<p class="bta-login-help" style="margin-top:0">Get a fresh link from <a href="' . esc_url(bta_portal_url('forgot')) . '">Forgot your password?</a>, or email <a href="mailto:orders@boomerts.com">orders@boomerts.com</a>.</p>';
    } else {
        $first = $user->status === 'invited';
        echo '<h1 class="bta-auth-h">' . ($first ? 'Set up your login' : 'Choose a new password') . '</h1>';
        echo '<p class="bta-auth-p">You sign in with <strong>' . esc_html($user->email ? $user->email : $user->username) . '</strong>.</p>';
        if ($error) echo '<div class="bta-alert" role="alert">' . esc_html($error) . '</div>';
        echo '<form method="post" action="' . esc_url(add_query_arg(array('u' => (int) $user->id, 't' => $token), bta_portal_url('set-password'))) . '">';
        echo '<input type="hidden" name="bta_set_password" value="1">';
        echo '<div class="bta-field"><label class="bta-label" for="bta-pw1">Password</label>';
        echo '<input class="bta-input" id="bta-pw1" name="password" type="password" minlength="' . BTA_MIN_PASSWORD . '" autocomplete="new-password" required autofocus>';
        echo '<p class="bta-hint" style="margin:6px 0 0">At least ' . BTA_MIN_PASSWORD . ' characters.</p></div>';
        echo '<div class="bta-field"><label class="bta-label" for="bta-pw2">Type it again</label>';
        echo '<input class="bta-input" id="bta-pw2" name="password2" type="password" minlength="' . BTA_MIN_PASSWORD . '" autocomplete="new-password" required></div>';
        echo '<button class="bta-btn" type="submit">' . ($first ? 'Save and sign in' : 'Save new password') . '</button>';
        echo '</form>';
    }
    bta_auth_card_close();
    bta_foot();
    exit;
}

/** /accounts/forgot — email a reset link. */
function bta_handle_forgot() {
    $msg = '';
    $kind = 'alert';
    $typed = '';
    if (!empty($_POST['bta_forgot'])) {
        $typed = trim((string) wp_unslash(isset($_POST['email']) ? $_POST['email'] : ''));
        $user = $typed !== '' ? bta_get_user_for_signin($typed) : null;

        // At most 3 links per login per hour, so the form cannot be used to flood someone.
        $key = 'bta_forgot_' . md5(strtolower($typed));
        $sent = (int) get_transient($key);

        if ($typed === '') {
            $msg = 'Enter the email address you sign in with.';
        } elseif ($sent >= 3) {
            $msg = 'We have already sent three links in the last hour. Check your inbox and spam folder, or email orders@boomerts.com.';
        } elseif (!$user || $user->status === 'disabled' || $user->email === '') {
            $msg = bta_specific_errors()
                ? (!$user ? 'No login uses ' . $typed . '. Check the spelling, or email orders@boomerts.com.'
                   : ($user->status === 'disabled' ? 'This login has been turned off. Email orders@boomerts.com and we will switch it back on.'
                   : 'That login has no email address on file, so we cannot send a link. Email orders@boomerts.com and we will reset it.'))
                : 'If that email has a login, a link to set a new password is on its way.';
            if (!bta_specific_errors()) $kind = 'notice';
        } else {
            set_transient($key, $sent + 1, HOUR_IN_SECONDS);
            if (bta_send_reset($user)) {
                $kind = 'notice';
                $msg = 'Check your email. A link to choose ' . ($user->status === 'invited' ? 'your password' : 'a new password') . ' is on its way to ' . $user->email . '. It lasts ' . ($user->status === 'invited' ? BTA_INVITE_DAYS . ' days' : 'an hour') . '.';
            } else {
                $msg = 'The email could not be sent from our end. Please email orders@boomerts.com and we will reset it.';
            }
        }
    }

    bta_head('Forgot your password · Boomer T\'s');
    bta_auth_card_open();
    echo '<h1 class="bta-auth-h">Forgot your password?</h1>';
    echo '<p class="bta-auth-p">Enter the email you sign in with and we will send you a link to choose a new one.</p>';
    if ($msg) echo '<div class="bta-' . ($kind === 'notice' ? 'notice' : 'alert') . '" role="alert">' . esc_html($msg) . '</div>';
    echo '<form method="post"><input type="hidden" name="bta_forgot" value="1">';
    echo '<div class="bta-field"><label class="bta-label" for="bta-fe">Email</label>';
    echo '<input class="bta-input" id="bta-fe" name="email" type="email" value="' . esc_attr($typed) . '" autocapitalize="none" spellcheck="false" required autofocus></div>';
    echo '<button class="bta-btn" type="submit">Send me a link</button></form>';
    echo '<p class="bta-login-help"><a href="' . esc_url(home_url('/' . bta_portal_slug() . '/')) . '">&larr; Back to sign in</a></p>';
    bta_auth_card_close();
    bta_foot();
    exit;
}

/** The branded card the sign-in page uses, for the other signed-out pages. */
function bta_auth_card_open() {
    echo '<div class="bta-login-wrap"><div class="bta-login-card"><div class="bta-login-top">';
    $logo = bta_shop_logo_url();
    if ($logo) echo '<img class="bta-login-logo" src="' . esc_url($logo) . '" alt="Boomer T\'s">';
    else echo '<div class="bta-login-wordmark">Boomer T<em>&rsquo;</em>s</div>';
    echo '<div class="bta-login-sub">Account Portal</div></div><div class="bta-login-body">';
}

function bta_auth_card_close() {
    echo '</div></div></div>';
}

/* ── Portal page (signed in) ─────────────────────────────────────────────── */

/** Handle the change-password form. Returns '' or an error; redirects on success. */
function bta_handle_change_password($user) {
    if (!bta_verify_csrf()) return 'Your session expired. Please try again.';
    $cur = isset($_POST['current']) ? (string) wp_unslash($_POST['current']) : '';
    $pw  = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';
    $pw2 = isset($_POST['password2']) ? (string) wp_unslash($_POST['password2']) : '';
    if (!wp_check_password($cur, $user->pass_hash)) return 'Your current password is not right.';
    if (strlen($pw) < BTA_MIN_PASSWORD) return 'Make the new password at least ' . BTA_MIN_PASSWORD . ' characters.';
    if ($pw !== $pw2) return 'The two new passwords do not match.';

    global $wpdb;
    $wpdb->update(bta_table('users'), array('pass_hash' => wp_hash_password($pw)), array('id' => (int) $user->id));
    bta_clear_link($user->id);
    // Signs out every other browser this login was open in, then this one back in.
    bta_kill_user_sessions($user->id);
    bta_start_session(bta_get_user($user->id));
    wp_safe_redirect(add_query_arg('changed', '1', bta_portal_url('password')));
    exit;
}

function bta_portal_password($user, $error = '') {
    echo '<h1 class="bta-h1">Password</h1>';
    echo '<p class="bta-lede">You sign in with <strong>' . esc_html($user->email ? $user->email : $user->username) . '</strong>. Changing your password signs you out anywhere else you are signed in.</p>';
    if (!empty($_GET['changed'])) echo '<div class="bta-notice">Password changed.</div>';
    if ($error) echo '<div class="bta-alert">' . esc_html($error) . '</div>';
    echo '<div class="bta-card" style="max-width:460px"><form method="post">';
    echo bta_csrf_field();
    echo '<input type="hidden" name="bta_change_password" value="1">';
    echo '<div class="bta-field"><label class="bta-label" for="bta-cur">Current password</label><input class="bta-input" id="bta-cur" name="current" type="password" autocomplete="current-password" required></div>';
    echo '<div class="bta-field"><label class="bta-label" for="bta-np1">New password</label><input class="bta-input" id="bta-np1" name="password" type="password" minlength="' . BTA_MIN_PASSWORD . '" autocomplete="new-password" required>';
    echo '<p class="bta-hint" style="margin:6px 0 0">At least ' . BTA_MIN_PASSWORD . ' characters.</p></div>';
    echo '<div class="bta-field"><label class="bta-label" for="bta-np2">Type it again</label><input class="bta-input" id="bta-np2" name="password2" type="password" minlength="' . BTA_MIN_PASSWORD . '" autocomplete="new-password" required></div>';
    echo '<button class="bta-btn bta-btn-inline" type="submit">Change password</button>';
    echo '</form></div>';
}
