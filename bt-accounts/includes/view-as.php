<?php
/**
 * BT Accounts — the shop opening an account's portal.
 *
 * Each account gets one hidden "shop" login (status 'shop', username
 * shop-<account id>, no password, no email). The View portal button signs the
 * browser into the portal as that login, so the shop sees exactly what the
 * account sees and can place orders for them; those orders read "Boomer T's
 * (name)" as the submitter, never as Sasha or Kim.
 *
 * A shop session is only honoured while the browser is also signed into
 * WordPress as someone who can handle account orders (bta_staff_can). Sign out
 * of WordPress and the portal session stops working with it. The shop login
 * cannot be signed into with a password, never appears in an account's
 * logins list, and gets no customer emails.
 */
if (!defined('ABSPATH')) exit;

function bta_is_shop_user($user) {
    return $user && isset($user->status) && $user->status === 'shop';
}

/** The account's shop login, made on first use; named for whoever is using it. */
function bta_shop_user($account, $wp_user) {
    global $wpdb;
    $username = 'shop-' . (int) $account->id;
    $name = 'Boomer T\'s' . ($wp_user && $wp_user->display_name ? ' (' . $wp_user->display_name . ')' : '');

    $u = bta_get_user_by_username($username);
    if (!$u) {
        $wpdb->insert(bta_table('users'), array(
            'account_id'       => (int) $account->id,
            'username'         => $username,
            'pass_hash'        => '',
            'display_name'     => $name,
            'email'            => '',
            'is_account_admin' => 1,   // sees every order on the account
            'status'           => 'shop',
            'created_at'       => current_time('mysql'),
        ));
        return bta_get_user((int) $wpdb->insert_id);
    }
    if ($u->display_name !== $name) {
        $wpdb->update(bta_table('users'), array('display_name' => $name), array('id' => (int) $u->id));
        $u->display_name = $name;
    }
    return $u;
}

function bta_view_as_url($account_id) {
    $account_id = (int) $account_id;
    return wp_nonce_url(admin_url('admin-post.php?action=bta_view_as&account=' . $account_id), 'bta_view_as_' . $account_id);
}

add_action('admin_post_bta_view_as', 'bta_view_as');
function bta_view_as() {
    $id = isset($_GET['account']) ? (int) $_GET['account'] : 0;
    if (!bta_staff_can()) wp_die('You need access to account orders to open an account\'s portal.');
    check_admin_referer('bta_view_as_' . $id);

    $account = bta_get_account($id);
    if (!$account) wp_die('That account no longer exists.');
    if ($account->status !== 'active') wp_die($account->name . ' is not active, so its portal is closed. Switch the account back on first.');

    // Whatever portal session this browser had (another account, a test login) ends here.
    bta_logout();
    bta_start_session(bta_shop_user($account, wp_get_current_user()));
    wp_safe_redirect(bta_portal_url());
    exit;
}

/** Where Exit takes the shop: back to the account in wp-admin, or the staff screen. */
function bta_shop_exit_url($user) {
    if (current_user_can('manage_options')) return admin_url('admin.php?page=bt-accounts&account=' . (int) $user->account_id);
    return home_url('/employees/accounts');
}

/** Bar across the top of the portal while the shop is in it. */
function bta_render_shop_bar($user, $account) {
    echo '<div class="bta-shopbar"><div class="bta-shopbar-inner">'
       . '<span><strong>Shop view of ' . esc_html($account->name) . '.</strong> You see what they see. Orders placed here show as placed by ' . esc_html($user->display_name) . '.</span>'
       . '<a href="' . esc_url(bta_portal_url('logout')) . '">Exit</a>'
       . '</div></div>';
}
