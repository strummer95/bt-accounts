<?php
/**
 * BT Accounts — sign-in diagnostics.
 *
 * "It will not let me log in" is four different faults wearing the same coat:
 * a wrong password, a lockout, a request that never reached the server at all,
 * and a login that succeeds but whose session does not survive the redirect.
 * They are indistinguishable from the outside and identical in the customer's
 * description, so this reads the two tables that actually tell them apart —
 * login_attempts and sessions — and says which one it is.
 */
if (!defined('ABSPATH')) exit;

/** Failed attempts inside the lockout window, newest first. */
function bta_recent_attempts($limit = 25) {
    global $wpdb;
    $since = gmdate('Y-m-d H:i:s', strtotime(current_time('mysql')) - DAY_IN_SECONDS);
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM " . bta_table('login_attempts') . " WHERE attempted_at > %s ORDER BY attempted_at DESC LIMIT %d",
        $since, (int) $limit
    ));
}

/** Live (unexpired) sessions for one portal user. */
function bta_user_sessions($user_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM " . bta_table('sessions') . " WHERE user_id = %d AND expires_at > %s ORDER BY created_at DESC",
        (int) $user_id, current_time('mysql')
    ));
}

/** Failed attempts against one username inside the lockout window. */
function bta_user_attempt_count($username) {
    global $wpdb;
    $since = gmdate('Y-m-d H:i:s', strtotime(current_time('mysql')) - (BTA_LOCKOUT_MINS * 60));
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM " . bta_table('login_attempts') . " WHERE username = %s AND attempted_at > %s",
        bta_sanitize_username($username), $since
    ));
}

/** Clear the throttle for one username and every IP that tried it. */
function bta_clear_lockout($username) {
    global $wpdb;
    $t = bta_table('login_attempts');
    $u = bta_sanitize_username($username);
    $ips = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT ip FROM $t WHERE username = %s", $u));
    $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE username = %s", $u));
    foreach ($ips as $ip) {
        $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE ip = %s", $ip));
    }
    return count($ips);
}

/**
 * Read the state for one login and say, in words, what is actually wrong.
 * Returns array(verdict, tone, detail).
 */
function bta_diagnose_user($user) {
    $tries    = bta_user_attempt_count($user->username);
    $sessions = bta_user_sessions($user->id);
    $last     = $user->last_login_at ? strtotime($user->last_login_at) : 0;
    $recent   = $last && (time() - $last) < DAY_IN_SECONDS;

    if ($user->status !== 'active') {
        return array('This login is disabled.', 'bad',
            'Set Status to Active on the row above. Nothing else will work until it is.');
    }

    if ($tries >= BTA_MAX_ATTEMPTS) {
        return array('Locked out right now.', 'bad',
            sprintf('%d failed attempts in the last %d minutes. They are being turned away before the password is even checked. Clear the lockout below, then give them a fresh password.',
                $tries, BTA_LOCKOUT_MINS));
    }

    if ($tries > 0) {
        return array('Wrong username or password.', 'warn',
            sprintf('%d failed attempt%s in the last %d minutes, and %d more before the lockout kicks in. The requests are arriving fine — the credentials are the problem. Set a new password and send it across.',
                $tries, $tries === 1 ? '' : 's', BTA_LOCKOUT_MINS, BTA_MAX_ATTEMPTS - $tries));
    }

    if ($recent && !$sessions) {
        return array('Signing in works, but the session is not surviving.', 'bad',
            'They authenticated successfully within the last day, yet no live session exists. That is the cookie being dropped — a page cache serving the portal, or a proxy stripping cookies. Check that a caching layer is not holding /' . esc_html(bta_portal_slug()) . '/.');
    }

    if ($recent && $sessions) {
        return array('Signed in successfully.', 'good',
            sprintf('%d live session%s. Their last sign-in was %s. If they still say they cannot get in, they are most likely on the wrong URL.',
                count($sessions), count($sessions) === 1 ? '' : 's', human_time_diff($last) . ' ago'));
    }

    if (!$last && !$tries) {
        return array('Nothing has ever reached the server.', 'bad',
            'No successful sign-in and no failed attempt has ever been recorded for this login. A wrong password would still leave a failed attempt here, so the form is not being submitted at all — a mistyped or rewritten URL, or their network blocking the site. Send them the exact link below and ask what they see.');
    }

    return array('No recent activity.', 'warn',
        'No failed attempts and no sign-in in the last day. Ask them to try now, then reload this page — whatever appears here will name the fault.');
}

/* ── Panel ───────────────────────────────────────────────────────────────── */

function bta_render_signin_diagnostics($account, $users) {
    $colors = array('good' => '#1a7f37', 'warn' => '#b26d00', 'bad' => '#b32d2e');

    echo '<h2 style="margin-top:32px">Sign-in diagnostics</h2>';
    echo '<p class="description" style="max-width:900px">What the server actually recorded, per login. A failed password always leaves a trace here &mdash; so if a login shows nothing at all, the request never arrived.</p>';

    echo '<table class="widefat striped" style="max-width:900px;margin-top:10px"><thead><tr>';
    echo '<th style="width:150px">Login</th><th>What is happening</th><th style="width:120px">Live sessions</th>';
    echo '</tr></thead><tbody>';

    if (!$users) echo '<tr><td colspan="3">No logins on this account.</td></tr>';

    foreach ($users as $u) {
        list($verdict, $tone, $detail) = bta_diagnose_user($u);
        $sessions = bta_user_sessions($u->id);

        echo '<tr>';
        echo '<td><code>' . esc_html($u->username) . '</code><br><span style="color:#666">' . esc_html($u->display_name) . '</span></td>';
        echo '<td><strong style="color:' . esc_attr($colors[$tone]) . '">' . esc_html($verdict) . '</strong>';
        echo '<div style="color:#50575e;margin-top:3px">' . esc_html($detail) . '</div>';

        if (bta_user_attempt_count($u->username) > 0) {
            echo '<form method="post" style="margin-top:8px">';
            wp_nonce_field('bta_admin');
            echo '<input type="hidden" name="bta_action" value="clear_lockout">';
            echo '<input type="hidden" name="username" value="' . esc_attr($u->username) . '">';
            echo '<input type="hidden" name="account_id" value="' . (int) $account->id . '">';
            echo '<button class="button button-small">Clear the lockout for this login</button>';
            echo '</form>';
        }
        echo '</td>';

        echo '<td>' . count($sessions);
        if ($sessions) {
            $s = $sessions[0];
            echo '<div style="color:#666;font-size:12px;margin-top:3px">from ' . esc_html($s->ip) . '<br>expires ' . esc_html(date_i18n('M j, g:ia', strtotime($s->expires_at))) . '</div>';
        }
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    /* Raw attempt log — the ground truth behind the verdicts. */
    $attempts = bta_recent_attempts(25);
    echo '<h3 style="margin-top:20px">Failed attempts, last 24 hours</h3>';
    if (!$attempts) {
        echo '<p class="description">Nothing recorded across the whole site. If someone is telling you they cannot sign in, their browser is not reaching the login form.</p>';
    } else {
        echo '<table class="widefat striped" style="max-width:640px"><thead><tr><th>When</th><th>Username tried</th><th>From IP</th></tr></thead><tbody>';
        foreach ($attempts as $at) {
            echo '<tr>';
            echo '<td>' . esc_html(date_i18n('M j, g:ia', strtotime($at->attempted_at))) . '</td>';
            echo '<td><code>' . esc_html($at->username !== '' ? $at->username : '(blank)') . '</code></td>';
            echo '<td>' . esc_html($at->ip) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p class="description">A username here that is close but not exact &mdash; extra spaces, a different spelling &mdash; is the whole fault. Usernames are lowercased and stripped to letters, numbers and <code>. _ @ -</code> before comparison.</p>';
    }

    echo '<h3 style="margin-top:20px">The exact link to send them</h3>';
    echo '<p><code style="font-size:14px;padding:6px 10px;display:inline-block;background:#fff;border:1px solid #ccd0d4">'
       . esc_html(home_url('/' . bta_portal_slug() . '/')) . '</code></p>';
    echo '<p class="description" style="max-width:900px">Trailing slash included. A corporate mail filter will often rewrite a link into its own scanning domain &mdash; ask them to type this in directly rather than clicking through an email, and to try a private window in case an old cookie is in the way.</p>';
}
