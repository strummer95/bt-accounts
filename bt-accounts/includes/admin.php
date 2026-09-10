<?php
/**
 * BT Accounts — wp-admin screens.
 * Accounts list, account editor (branding + settings + logins), pricing stub.
 */
if (!defined('ABSPATH')) exit;

add_action('admin_menu', 'bta_admin_menu');
function bta_admin_menu() {
    // 58.6 seats this next to BT Quote (58) even if BT Quote's grouper is absent
    // or out of date. When the grouper runs, it re-seats the whole cluster anyway.
    add_menu_page(
        'BT Accounts', 'BT Accounts', 'manage_options',
        'bt-accounts', 'bta_admin_page', 'dashicons-groups', 58.6
    );
}

/**
 * Join the grouped BT block in the sidebar. BT Quote owns the grouping and
 * already lists 'bt accounts' by default; registering here as well means the
 * grouping still holds if that default list is ever edited. The filter
 * de-duplicates, so saying it twice is harmless, and this is a no-op when BT
 * Quote is inactive.
 */
add_filter('bt_menu_group_order', function ($want) {
    if (!in_array('bt accounts', (array) $want, true)) $want[] = 'bt accounts';
    return $want;
});

function bta_admin_notice($msg, $type = 'success') {
    echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($msg) . '</p></div>';
}

function bta_admin_page() {
    if (!current_user_can('manage_options')) wp_die('Nope.');
    bta_handle_admin_post();

    $edit = isset($_GET['account']) ? (int) $_GET['account'] : 0;
    echo '<div class="wrap">';
    if ($edit && bta_get_account($edit)) {
        bta_admin_account_editor(bta_get_account($edit));
    } else {
        bta_admin_accounts_list();
    }
    echo '</div>';
}

/* ── POST handling ───────────────────────────────────────────────────────── */

function bta_handle_admin_post() {
    if (empty($_POST['bta_action'])) return;
    if (!current_user_can('manage_options')) wp_die('Nope.');
    check_admin_referer('bta_admin');

    $action = sanitize_key($_POST['bta_action']);

    if ($action === 'save_settings') {
        update_option('bta_shop_logo', esc_url_raw(wp_unslash(isset($_POST['shop_logo']) ? $_POST['shop_logo'] : '')));
        bta_admin_notice('Branding saved.');
    }

    if ($action === 'save_staff') {
        $want = isset($_POST['staff']) ? array_map('intval', (array) $_POST['staff']) : array();
        foreach (bta_staff_candidates() as $id => $u) bta_staff_set_access($id, in_array((int) $id, $want, true));
        bta_admin_notice('Shop staff saved.');
    }

    if ($action === 'save_notify') {
        update_option('bta_notify_email',    sanitize_text_field(wp_unslash(isset($_POST['notify_email']) ? $_POST['notify_email'] : '')));
        update_option('bta_notify_from',     sanitize_email(wp_unslash(isset($_POST['notify_from']) ? $_POST['notify_from'] : '')));
        update_option('bta_notify_customer', !empty($_POST['notify_customer']) ? 1 : 0);
        update_option('bta_notify_status',   !empty($_POST['notify_status']) ? 1 : 0);
        update_option('bta_specific_errors', !empty($_POST['specific_errors']) ? 1 : 0);
        bta_admin_notice('Notifications saved.');
    }

    if ($action === 'test_notify') {
        $to = bta_notify_recipients();
        if (!$to) {
            bta_admin_notice('No valid notification address to send to.', 'error');
        } else {
            $body = bta_mail_wrap(
                'Test notification',
                null,
                '#27267e',
                '<p style="' . bta_mail_p() . '">If you are reading this, order notifications from the account portal will reach you at '
                    . esc_html(implode(', ', $to)) . '.</p>'
            );
            $sent = wp_mail($to, 'BT Accounts — test notification', $body, bta_mail_headers());
            if ($sent) bta_admin_notice('Test sent to ' . implode(', ', $to) . '.');
            else bta_admin_notice('WordPress could not send the test. Check the site\'s SMTP settings.', 'error');
        }
    }

    if ($action === 'clear_lockout') {
        $username = isset($_POST['username']) ? wp_unslash($_POST['username']) : '';
        $n = bta_clear_lockout($username);
        bta_admin_notice(sprintf('Lockout cleared for %s (and %d IP%s that tried it). They can sign in again immediately.',
            $username, $n, $n === 1 ? '' : 's'));
    }

    if ($action === 'create_account') {
        $r = bta_create_account(array(
            'name'        => isset($_POST['name']) ? wp_unslash($_POST['name']) : '',
            'brand_color' => isset($_POST['brand_color']) ? wp_unslash($_POST['brand_color']) : '',
        ));
        if (is_wp_error($r)) bta_admin_notice($r->get_error_message(), 'error');
        else bta_admin_notice('Account created.');
    }

    if ($action === 'save_account') {
        $id = (int) $_POST['account_id'];
        $r = bta_update_account($id, array(
            'name'             => isset($_POST['name']) ? wp_unslash($_POST['name']) : '',
            'logo_url'         => isset($_POST['logo_url']) ? wp_unslash($_POST['logo_url']) : '',
            'brand_color'      => isset($_POST['brand_color']) ? wp_unslash($_POST['brand_color']) : '',
            'can_buy_garments' => !empty($_POST['can_buy_garments']),
            'requires_po'      => !empty($_POST['requires_po']),
            'status'           => isset($_POST['status']) ? sanitize_key($_POST['status']) : 'active',
        ));
        if (is_wp_error($r)) bta_admin_notice($r->get_error_message(), 'error');
        else bta_admin_notice('Saved.');
    }

    if ($action === 'create_user') {
        $r = bta_create_user(array(
            'account_id'       => (int) $_POST['account_id'],
            'username'         => isset($_POST['username']) ? wp_unslash($_POST['username']) : '',
            'password'         => isset($_POST['password']) ? wp_unslash($_POST['password']) : '',
            'display_name'     => isset($_POST['display_name']) ? wp_unslash($_POST['display_name']) : '',
            'email'            => isset($_POST['email']) ? wp_unslash($_POST['email']) : '',
            'is_account_admin' => !empty($_POST['is_account_admin']),
        ));
        if (is_wp_error($r)) bta_admin_notice($r->get_error_message(), 'error');
        else bta_admin_notice('Login created.');
    }

    if ($action === 'save_user') {
        $r = bta_update_user((int) $_POST['user_id'], array(
            'display_name'     => isset($_POST['display_name']) ? wp_unslash($_POST['display_name']) : '',
            'email'            => isset($_POST['email']) ? wp_unslash($_POST['email']) : '',
            'password'         => isset($_POST['password']) ? wp_unslash($_POST['password']) : '',
            'is_account_admin' => !empty($_POST['is_account_admin']),
            'status'           => isset($_POST['status']) ? sanitize_key($_POST['status']) : 'active',
        ));
        if (is_wp_error($r)) bta_admin_notice($r->get_error_message(), 'error');
        else bta_admin_notice('Login updated. Any active session for that person was signed out.');
    }

    if ($action === 'delete_user') {
        bta_delete_user((int) $_POST['user_id']);
        bta_admin_notice('Login deleted.');
    }
}

/* ── Status + updates ────────────────────────────────────────────────────── */

/**
 * Installed vs published version, a live self-test, and the manual update
 * check. Same panel shape as BT Quote and BT Catalog.
 */
function bta_admin_status_panel() {
    // Prove the portal route and the pricing bridge at a glance, so a broken
    // deploy shows up here rather than when Sasha tries to sign in.
    $rules      = get_option('rewrite_rules', array());
    $route_ok   = is_array($rules) && (bool) preg_grep('#^\^?' . preg_quote(bta_portal_slug(), '#') . '/?\$?#', array_keys($rules));
    $engine_ok  = function_exists('btq_price');
    $tables_ok  = true;
    global $wpdb;
    foreach (array('accounts', 'users', 'sessions', 'login_attempts') as $t) {
        $name = bta_table($t);
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $name)) !== $name) $tables_ok = false;
    }

    $yes = '<span style="color:#1a7f37;font-weight:700">OK</span>';
    $no  = '<span style="color:#b91c1c;font-weight:700">FAIL</span>';

    echo '<h2>Status</h2><table class="widefat" style="max-width:680px"><tbody>';
    echo '<tr><td>Database tables</td><td>' . ($tables_ok ? $yes : $no . ' &mdash; deactivate and reactivate the plugin to rebuild them') . '</td></tr>';
    echo '<tr><td>Portal URL</td><td>' . ($route_ok ? $yes : $no . ' &mdash; go to <a href="' . esc_url(admin_url('options-permalink.php')) . '">Settings &rarr; Permalinks</a> and press Save to flush the rewrite rules')
        . ' &nbsp;<a href="' . esc_url(home_url('/' . bta_portal_slug() . '/')) . '" target="_blank" rel="noopener">' . esc_html(home_url('/' . bta_portal_slug() . '/')) . '</a></td></tr>';
    echo '<tr><td>Pricing engine (BT Quote)</td><td>' . ($engine_ok ? $yes . ' &nbsp;<span style="color:#666">supplied-item rates available</span>' : $no . ' &mdash; BT Quote is not active, so quoting will not work') . '</td></tr>';
    echo '</tbody></table>';

}

/* ── Accounts list ───────────────────────────────────────────────────────── */

function bta_admin_accounts_list() {
    $accounts = bta_get_accounts();
    echo '<h1>BT Accounts</h1>';
    echo '<p class="description">Contract accounts sign in at <code>' . esc_html(home_url('/' . bta_portal_slug() . '/')) . '</code>. These logins are portal-only — they are not WordPress users and cannot reach wp-admin.</p>';

    bta_admin_status_panel();

    echo '<h2 style="margin-top:32px">Accounts</h2>';
    echo '<table class="widefat striped" style="max-width:900px">';
    echo '<thead><tr><th>Account</th><th>Logins</th><th>Status</th><th></th></tr></thead><tbody>';
    if (!$accounts) {
        echo '<tr><td colspan="4">No accounts yet.</td></tr>';
    }
    foreach ($accounts as $a) {
        $n = count(bta_get_account_users($a->id));
        $url = admin_url('admin.php?page=bt-accounts&account=' . (int) $a->id);
        echo '<tr>';
        echo '<td><strong><a href="' . esc_url($url) . '">' . esc_html($a->name) . '</a></strong><br><span style="color:#666">/' . esc_html($a->slug) . '</span></td>';
        echo '<td>' . (int) $n . '</td>';
        echo '<td>' . esc_html($a->status) . '</td>';
        echo '<td><a class="button" href="' . esc_url($url) . '">Manage</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    bta_admin_staff_section();

    echo '<h2 style="margin-top:32px">Portal branding</h2>';
    echo '<form method="post" style="max-width:640px"><table class="form-table">';
    wp_nonce_field('bta_admin');
    echo '<input type="hidden" name="bta_action" value="save_settings">';
    echo '<tr><th><label for="bta-shoplogo">Boomer T&rsquo;s logo</label></th><td>';
    echo '<input id="bta-shoplogo" name="shop_logo" class="large-text" value="' . esc_attr(get_option('bta_shop_logo', '')) . '" placeholder="https://boomerts.com/wp-content/uploads/...">';
    echo '<p class="description">Shown on the sign-in card and in the portal header. Use a version that reads well on navy &mdash; white or knockout works best. Leave blank for the Oswald wordmark.</p>';
    echo '</td></tr></table><p><button class="button">Save branding</button></p></form>';

    echo '<h2 style="margin-top:32px">Order notifications</h2>';
    echo '<form method="post" style="max-width:640px"><table class="form-table">';
    wp_nonce_field('bta_admin');
    echo '<input type="hidden" name="bta_action" value="save_notify">';

    echo '<tr><th><label for="bta-notify-email">Send new orders to</label></th><td>';
    echo '<input id="bta-notify-email" name="notify_email" class="large-text" value="' . esc_attr(get_option('bta_notify_email', '')) . '" placeholder="' . esc_attr(bta_notify_default_recipient()) . '">';
    echo '<p class="description">Who gets an email the moment an account submits an order. Separate several addresses with commas. Blank falls back to <code>' . esc_html(bta_notify_default_recipient()) . '</code>.</p>';
    echo '</td></tr>';

    echo '<tr><th><label for="bta-notify-from">Send from</label></th><td>';
    echo '<input id="bta-notify-from" name="notify_from" class="regular-text" value="' . esc_attr(get_option('bta_notify_from', '')) . '" placeholder="orders@boomerts.com">';
    echo '<p class="description">The From address on portal email. Must be an address on this domain or the mail will be filtered. Blank uses <code>orders@' . esc_html(preg_replace('/^www\./i', '', (string) wp_parse_url(home_url(), PHP_URL_HOST))) . '</code>.</p>';
    echo '</td></tr>';

    echo '<tr><th>Also email the account</th><td>';
    echo '<label><input type="checkbox" name="notify_customer" value="1"' . checked(get_option('bta_notify_customer', 1), 1, false) . '> Send the person who submitted a receipt with a copy of their order</label><br>';
    echo '<label><input type="checkbox" name="notify_status" value="1"' . checked(get_option('bta_notify_status', 0), 1, false) . '> Email them again whenever the order status changes</label>';
    echo '<p class="description">Status email follows the job card, so every move on the board reaches them. Leave off if you would rather tell them yourself.</p>';
    echo '</td></tr>';

    echo '<tr><th>Sign-in messages</th><td>';
    echo '<label><input type="checkbox" name="specific_errors" value="1"' . checked(get_option('bta_specific_errors', 1), 1, false) . '> Tell people exactly why a sign-in failed</label>';
    echo '<p class="description">On: the form says whether the username is unknown, the password is wrong, or the login is locked &mdash; so they can fix it themselves instead of emailing you. Off: one vague message for every failure, which stops an attacker discovering which usernames exist. Worth leaving on while logins are created by hand and there is no public sign-up.</p>';
    echo '</td></tr>';

    echo '</table><p><button class="button button-primary">Save notifications</button></p></form>';

    echo '<form method="post" style="margin-top:-8px">';
    wp_nonce_field('bta_admin');
    echo '<input type="hidden" name="bta_action" value="test_notify">';
    echo '<button class="button">Send a test email</button>';
    echo '<span class="description" style="margin-left:10px">Sends to the address above so you can prove SMTP works before a real order arrives.</span>';
    echo '</form>';

    echo '<h2 style="margin-top:32px">Add an account</h2>';
    echo '<form method="post" style="max-width:520px"><table class="form-table">';
    wp_nonce_field('bta_admin');
    echo '<input type="hidden" name="bta_action" value="create_account">';
    echo '<tr><th><label for="bta-name">Account name</label></th><td><input id="bta-name" name="name" class="regular-text" required></td></tr>';
    echo '<tr><th><label for="bta-color">Brand colour</label></th><td><input id="bta-color" name="brand_color" type="color" value="#27267e"></td></tr>';
    echo '</table><p><button class="button button-primary">Create account</button></p></form>';

    // Shared BT panel — always the last section, same on every BT plugin.
    echo '<hr style="margin:28px 0">';
    bt_admin_updates_panel(array(
        'slug'     => 'bt-accounts',
        'version'  => BTA_VERSION,
        'manifest' => 'bta_update_manifest',
        'flush'    => 'bta_force_update_check',
    ));
}

/* ── Account editor ──────────────────────────────────────────────────────── */

function bta_admin_account_editor($a) {
    $users = bta_get_account_users($a->id);

    echo '<h1>' . esc_html($a->name) . '</h1>';
    echo '<p><a href="' . esc_url(admin_url('admin.php?page=bt-accounts')) . '">&larr; All accounts</a></p>';

    // Settings
    echo '<h2>Settings</h2>';
    echo '<form method="post" style="max-width:640px"><table class="form-table">';
    wp_nonce_field('bta_admin');
    echo '<input type="hidden" name="bta_action" value="save_account">';
    echo '<input type="hidden" name="account_id" value="' . (int) $a->id . '">';
    echo '<tr><th><label for="bta-name">Name</label></th><td><input id="bta-name" name="name" class="regular-text" value="' . esc_attr($a->name) . '"></td></tr>';
    echo '<tr><th><label for="bta-logo">Logo URL</label></th><td><input id="bta-logo" name="logo_url" class="large-text" value="' . esc_attr($a->logo_url) . '" placeholder="https://boomerts.com/wp-content/uploads/...">';
    echo '<p class="description">Upload to the Media Library, then paste the file URL here. Shown at the top of their portal.</p></td></tr>';
    echo '<tr><th><label for="bta-color">Brand colour</label></th><td><input id="bta-color" name="brand_color" type="color" value="' . esc_attr($a->brand_color) . '"></td></tr>';
    echo '<tr><th>Options</th><td>';
    echo '<label><input type="checkbox" name="requires_po" value="1"' . checked($a->requires_po, 1, false) . '> Require a PO number on every order</label><br>';
    echo '<label><input type="checkbox" name="can_buy_garments" value="1"' . checked($a->can_buy_garments, 1, false) . '> Allow buying garments from us (off = they supply their own blanks, decoration-only pricing)</label>';
    echo '</td></tr>';
    echo '<tr><th><label for="bta-status">Status</label></th><td><select id="bta-status" name="status">';
    echo '<option value="active"' . selected($a->status, 'active', false) . '>Active</option>';
    echo '<option value="disabled"' . selected($a->status, 'disabled', false) . '>Disabled</option>';
    echo '</select><p class="description">Disabling signs out everyone on this account immediately.</p></td></tr>';
    echo '</table><p><button class="button button-primary">Save</button></p></form>';

    // Logins
    echo '<h2 style="margin-top:32px">Logins</h2>';
    echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Username</th><th>Name</th><th>Email</th><th>Last sign-in</th><th>Status</th><th></th></tr></thead><tbody>';
    if (!$users) echo '<tr><td colspan="6">No logins yet.</td></tr>';
    foreach ($users as $u) {
        echo '<tr>';
        echo '<td><code>' . esc_html($u->username) . '</code>' . ($u->is_account_admin ? ' <span style="color:#666">(admin)</span>' : '') . '</td>';
        echo '<td>' . esc_html($u->display_name) . '</td>';
        echo '<td>' . esc_html($u->email) . '</td>';
        echo '<td>' . esc_html($u->last_login_at ? $u->last_login_at : 'never') . '</td>';
        echo '<td>' . esc_html($u->status) . '</td>';
        echo '<td>';
        echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Delete this login?\')">';
        wp_nonce_field('bta_admin');
        echo '<input type="hidden" name="bta_action" value="delete_user"><input type="hidden" name="user_id" value="' . (int) $u->id . '">';
        echo '<button class="button button-small">Delete</button></form>';
        echo '</td></tr>';

        // Inline edit row
        echo '<tr><td colspan="6" style="background:#fafafa">';
        echo '<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
        wp_nonce_field('bta_admin');
        echo '<input type="hidden" name="bta_action" value="save_user"><input type="hidden" name="user_id" value="' . (int) $u->id . '">';
        echo '<input name="display_name" value="' . esc_attr($u->display_name) . '" placeholder="Name">';
        echo '<input name="email" value="' . esc_attr($u->email) . '" placeholder="Email">';
        echo '<input name="password" type="text" value="" placeholder="New password (blank = unchanged)" style="width:230px">';
        echo '<label><input type="checkbox" name="is_account_admin" value="1"' . checked($u->is_account_admin, 1, false) . '> Sees all orders on the account</label>';
        echo '<select name="status"><option value="active"' . selected($u->status, 'active', false) . '>Active</option><option value="disabled"' . selected($u->status, 'disabled', false) . '>Disabled</option></select>';
        echo '<button class="button button-small">Update</button>';
        echo '</form></td></tr>';
    }
    echo '</tbody></table>';

    echo '<h3 style="margin-top:24px">Add a login</h3>';
    echo '<form method="post" style="max-width:520px"><table class="form-table">';
    wp_nonce_field('bta_admin');
    echo '<input type="hidden" name="bta_action" value="create_user">';
    echo '<input type="hidden" name="account_id" value="' . (int) $a->id . '">';
    echo '<tr><th><label for="bta-u">Username</label></th><td><input id="bta-u" name="username" class="regular-text" required></td></tr>';
    echo '<tr><th><label for="bta-dn">Name</label></th><td><input id="bta-dn" name="display_name" class="regular-text"></td></tr>';
    echo '<tr><th><label for="bta-em">Email</label></th><td><input id="bta-em" name="email" type="email" class="regular-text"></td></tr>';
    echo '<tr><th><label for="bta-pw">Password</label></th><td><input id="bta-pw" name="password" type="text" class="regular-text" required>';
    echo '<p class="description">You set it and pass it along; there is no self-serve reset yet. One login per person rather than one per company keeps order history attributable and lets you disable someone without disrupting everyone else.</p></td></tr>';
    echo '<tr><th>Permissions</th><td><label><input type="checkbox" name="is_account_admin" value="1"> Sees all orders on the account (otherwise only their own)</label></td></tr>';
    echo '</table><p><button class="button button-primary">Create login</button></p></form>';

    bta_render_signin_diagnostics($a, $users);

    // Pricing
    echo '<h2 style="margin-top:32px">Pricing</h2>';
    $profile = bta_account_profile($a);
    echo '<p class="description" style="max-width:640px">';
    if (!$profile) {
        echo 'Currently on standard rates &mdash; supplied-item pricing straight from BT Quote, decoration only, no garment cost. The per-account rate table editor lands in Phase&nbsp;3; until then this account quotes exactly what the public quoter quotes for customer-supplied garments.';
    } else {
        echo 'This account has ' . count($profile) . ' pricing override(s) saved.';
    }
    echo '</p>';
    if (function_exists('btq_price')) {
        $s = bta_price_for_account($a->id, array('qty' => 48, 'garment' => 'supplied', 'method' => 'embroidery', 'embType' => 'logo'));
        if (!is_wp_error($s) && isset($s['perShirt'])) {
            echo '<p><strong>Sample:</strong> 48 &times; supplied garment, embroidered logo &mdash; $' . esc_html(number_format($s['perShirt'], 2)) . '/ea</p>';
        }
    } else {
        echo '<p style="color:#b32d2e"><strong>BT Quote is not active.</strong> Pricing will not work until it is.</p>';
    }
}
