<?php
/**
 * BT Accounts — Google sign-in.
 *
 * Dillon: "google sign ins on all my projects, remember login, and a way to
 * turn previous accounts into google logins." Portal logins are not WordPress
 * users (see auth.php), so this is independent of wp-admin's own Google story
 * if it ever gets one.
 *
 * The client ID is a public OAuth identifier (shipped to the browser by
 * design), not a secret — it lives in BTA_GOOGLE_CLIENT_ID (wp-config) or the
 * bta_google_client_id option, set on BT Accounts' own settings screen by a
 * WordPress administrator.
 *
 * Verification is a live call to Google's own tokeninfo endpoint (same model
 * as every other project this cycle) rather than local JWKS/signature
 * checking, so there is no new dependency to ship in a WordPress zip.
 */
if (!defined('ABSPATH')) exit;

function bta_google_client_id() {
    if (defined('BTA_GOOGLE_CLIENT_ID') && BTA_GOOGLE_CLIENT_ID) return (string) BTA_GOOGLE_CLIENT_ID;
    return (string) get_option('bta_google_client_id', '');
}

/** Verify a Google ID token against Google's own tokeninfo endpoint. Returns array(sub, email) or WP_Error. */
function bta_verify_google_id_token($credential) {
    $credential = (string) $credential;
    if ($credential === '') return new WP_Error('bta_google_empty', 'No Google credential received.');

    $client_id = bta_google_client_id();
    if ($client_id === '') return new WP_Error('bta_google_unconfigured', 'Google sign-in is not set up.');

    $resp = wp_remote_get(
        'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($credential),
        array('timeout' => 10)
    );
    if (is_wp_error($resp)) return $resp;
    if ((int) wp_remote_retrieve_response_code($resp) !== 200) {
        return new WP_Error('bta_google_bad_token', 'That Google sign-in could not be verified.');
    }

    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($body)) return new WP_Error('bta_google_bad_token', 'That Google sign-in could not be verified.');

    $aud      = (string) (isset($body['aud']) ? $body['aud'] : '');
    $iss      = (string) (isset($body['iss']) ? $body['iss'] : '');
    $sub      = (string) (isset($body['sub']) ? $body['sub'] : '');
    $exp      = (int) (isset($body['exp']) ? $body['exp'] : 0);
    $email    = strtolower(trim((string) (isset($body['email']) ? $body['email'] : '')));
    $verified = in_array(isset($body['email_verified']) ? $body['email_verified'] : '', array('true', true, '1', 1), true);

    if ($client_id === '' || !hash_equals($client_id, $aud)) {
        return new WP_Error('bta_google_aud', 'That sign-in was issued for a different site.');
    }
    if (!in_array($iss, array('accounts.google.com', 'https://accounts.google.com'), true)) {
        return new WP_Error('bta_google_iss', 'That sign-in did not come from Google.');
    }
    if ($exp < time()) return new WP_Error('bta_google_expired', 'That sign-in has expired. Try again.');
    if ($sub === '' || !$verified || $email === '') {
        return new WP_Error('bta_google_unverified', 'That Google account email is not verified.');
    }

    return array('sub' => $sub, 'email' => $email);
}

/** The portal login already linked to this Google subject, if any. */
function bta_user_by_google_sub($sub) {
    global $wpdb;
    $sub = (string) $sub;
    if ($sub === '') return null;
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . bta_table('users') . " WHERE google_sub = %s", $sub
    ));
}

/**
 * Per-IP cooldown for failed Google sign-ins, sharing the same throttle table
 * (and the same 15 minute / 5 attempt shape) that password sign-ins use, but
 * under a username bucket no real login can ever have, so the two never
 * cross — contending for the IP is still enough to pace an attacker down.
 */
define('BTA_GOOGLE_ATTEMPT_BUCKET', '__google__');

function bta_google_record_fail() {
    bta_record_attempt(BTA_GOOGLE_ATTEMPT_BUCKET);
}

function bta_google_locked_out() {
    return bta_is_locked_out(BTA_GOOGLE_ATTEMPT_BUCKET);
}

/**
 * Attempt a Google sign-in from a verified credential. Returns the user row
 * on success, WP_Error otherwise. Mirrors bta_login()'s shape so the portal
 * route can treat both the same way.
 */
function bta_google_login($credential) {
    if (bta_google_locked_out()) {
        return new WP_Error('bta_google_locked', 'Too many attempts. Try again in a few minutes, or sign in with your password.');
    }

    $claim = bta_verify_google_id_token($credential);
    if (is_wp_error($claim)) {
        bta_google_record_fail();
        return new WP_Error('bta_google_bad', 'Could not sign in with that Google account.');
    }

    $user = bta_user_by_google_sub($claim['sub']);

    // Auto-link only on first use, and only onto an existing active portal
    // login whose own email already matches the verified Google email —
    // never onto an arbitrary login by email alone, and never create one.
    if (!$user) {
        $candidate = bta_get_user_by_email($claim['email']);
        if ($candidate && $candidate->status === 'active' && $candidate->google_sub === '') {
            global $wpdb;
            $wpdb->update(bta_table('users'), array(
                'google_sub'   => $claim['sub'],
                'google_email' => $claim['email'],
            ), array('id' => (int) $candidate->id));
            $user = bta_get_user((int) $candidate->id);
        }
    }

    if (!$user || $user->status !== 'active') {
        bta_google_record_fail();
        // Deliberately vague, same reasoning as the password login above.
        return new WP_Error('bta_google_bad', 'Could not sign in with that Google account.');
    }

    $account = bta_get_account($user->account_id);
    if (!$account || $account->status !== 'active') {
        bta_google_record_fail();
        return new WP_Error('bta_account_disabled', 'Your company account is not active at the moment. Email orders@boomerts.com and we will sort it out.');
    }

    bta_start_session($user);
    return $user;
}

/** Link the Google account behind $credential onto an already signed-in $user. Returns true or WP_Error. */
function bta_google_link_current($user, $credential) {
    $claim = bta_verify_google_id_token($credential);
    if (is_wp_error($claim)) return new WP_Error('bta_google_bad', 'Could not verify that Google account.');

    $existing = bta_user_by_google_sub($claim['sub']);
    if ($existing && (int) $existing->id !== (int) $user->id) {
        return new WP_Error('bta_google_taken', 'That Google account is already linked to a different login.');
    }

    global $wpdb;
    $wpdb->update(bta_table('users'), array(
        'google_sub'   => $claim['sub'],
        'google_email' => $claim['email'],
    ), array('id' => (int) $user->id));
    return true;
}

/** Unlink never locks anyone out — the password still works. */
function bta_google_unlink_current($user) {
    global $wpdb;
    $wpdb->update(bta_table('users'), array('google_sub' => '', 'google_email' => ''), array('id' => (int) $user->id));
    return true;
}
