<?php
/**
 * BT Accounts — wp-admin screens.
 * Accounts list, account editor (branding + settings + logins), pricing stub.
 */
if (!defined('ABSPATH')) exit;

add_action('admin_menu', 'bta_admin_menu');
function bta_admin_menu() {
    add_menu_page(
        'BT Accounts', 'BT Accounts', 'manage_options',
        'bt-accounts', 'bta_admin_page', 'dashicons-groups', 57
    );
}

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

/* ── Accounts list ───────────────────────────────────────────────────────── */

function bta_admin_accounts_list() {
    $accounts = bta_get_accounts();
    echo '<h1>BT Accounts</h1>';
    echo '<p class="description">Contract accounts sign in at <code>' . esc_html(home_url('/' . bta_portal_slug() . '/')) . '</code>. These logins are portal-only — they are not WordPress users and cannot reach wp-admin.</p>';

    echo '<table class="widefat striped" style="max-width:900px;margin-top:16px">';
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

    echo '<h2 style="margin-top:32px">Portal branding</h2>';
    echo '<form method="post" style="max-width:640px"><table class="form-table">';
    wp_nonce_field('bta_admin');
    echo '<input type="hidden" name="bta_action" value="save_settings">';
    echo '<tr><th><label for="bta-shoplogo">Boomer T&rsquo;s logo</label></th><td>';
    echo '<input id="bta-shoplogo" name="shop_logo" class="large-text" value="' . esc_attr(get_option('bta_shop_logo', '')) . '" placeholder="https://boomerts.com/wp-content/uploads/...">';
    echo '<p class="description">Shown on the sign-in card and in the portal header. Use a version that reads well on navy &mdash; white or knockout works best. Leave blank for the Oswald wordmark.</p>';
    echo '</td></tr></table><p><button class="button">Save branding</button></p></form>';

    echo '<h2 style="margin-top:32px">Add an account</h2>';
    echo '<form method="post" style="max-width:520px"><table class="form-table">';
    wp_nonce_field('bta_admin');
    echo '<input type="hidden" name="bta_action" value="create_account">';
    echo '<tr><th><label for="bta-name">Account name</label></th><td><input id="bta-name" name="name" class="regular-text" required></td></tr>';
    echo '<tr><th><label for="bta-color">Brand colour</label></th><td><input id="bta-color" name="brand_color" type="color" value="#27267e"></td></tr>';
    echo '</table><p><button class="button button-primary">Create account</button></p></form>';
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
