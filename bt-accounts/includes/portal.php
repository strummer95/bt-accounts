<?php
/**
 * BT Accounts — the /accounts portal.
 *
 * Rendered as a standalone document rather than through the active theme. That
 * keeps the portal free of theme markup and, more usefully, free of every other
 * plugin's CSS and DOM ids — BT Quote and BT Portal have already collided once
 * over a shared #btModalOverlay. Nothing here shares a namespace with anything.
 */
if (!defined('ABSPATH')) exit;

add_action('init', 'bta_register_rewrite');
function bta_register_rewrite() {
    add_rewrite_rule('^' . bta_portal_slug() . '/?$', 'index.php?bta_portal=1', 'top');
    add_rewrite_rule('^' . bta_portal_slug() . '/([a-z0-9_-]+)/?$', 'index.php?bta_portal=1&bta_view=$matches[1]', 'top');
}

add_filter('query_vars', function ($vars) {
    $vars[] = 'bta_portal';
    $vars[] = 'bta_view';
    return $vars;
});

/** Self-heal if the rules were never flushed (e.g. plugin folder renamed). */
add_action('wp_loaded', function () {
    if (get_option('bta_rewrites_flushed') !== BTA_VERSION) {
        bta_register_rewrite();
        flush_rewrite_rules();
        update_option('bta_rewrites_flushed', BTA_VERSION);
    }
});

add_action('template_redirect', 'bta_route_portal');
function bta_route_portal() {
    if (!get_query_var('bta_portal')) return;

    // Never let a page cache or CDN hold on to one account's portal.
    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow', true);
    if (function_exists('is_plugin_active') || defined('DONOTCACHEPAGE') === false) {
        define('DONOTCACHEPAGE', true);
    }

    $view   = get_query_var('bta_view');
    $notice = '';

    // ── Logout ──
    if ($view === 'logout') {
        bta_logout();
        wp_safe_redirect(home_url('/' . bta_portal_slug() . '/'));
        exit;
    }

    // ── Login submit ──
    if (!bta_is_logged_in() && !empty($_POST['bta_login'])) {
        $r = bta_login(
            isset($_POST['username']) ? wp_unslash($_POST['username']) : '',
            isset($_POST['password']) ? wp_unslash($_POST['password']) : ''
        );
        if (is_wp_error($r)) {
            $notice = $r->get_error_message();
        } else {
            wp_safe_redirect(home_url('/' . bta_portal_slug() . '/'));
            exit;
        }
    }

    if (bta_is_logged_in()) bta_render_portal();
    else bta_render_login($notice);
    exit;
}

/* ── Shared chrome ───────────────────────────────────────────────────────── */

function bta_head($title, $accent = '#0b5d8f') {
    ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html($title); ?></title>
<link rel="stylesheet" href="<?php echo esc_url(BTA_URL . 'assets/portal.css?v=' . BTA_VERSION); ?>">
<style>:root { --bta-accent: <?php echo esc_html($accent); ?>; }</style>
</head>
<body class="bta-body"><?php
}

function bta_foot() {
    echo '</body></html>';
}

/* ── Login ───────────────────────────────────────────────────────────────── */

function bta_render_login($notice = '') {
    bta_head('Account Sign In · Boomer T\'s');
    ?>
    <div class="bta-login-wrap">
      <div class="bta-login-card">
        <div class="bta-login-brand">Boomer T's</div>
        <h1 class="bta-login-title">Account Sign In</h1>
        <?php if ($notice) : ?>
          <div class="bta-alert" role="alert"><?php echo esc_html($notice); ?></div>
        <?php endif; ?>
        <form method="post" class="bta-form" autocomplete="off">
          <input type="hidden" name="bta_login" value="1">
          <label class="bta-label" for="bta-username">Username</label>
          <input class="bta-input" id="bta-username" name="username" autocapitalize="none" autocorrect="off" required autofocus>
          <label class="bta-label" for="bta-password">Password</label>
          <input class="bta-input" id="bta-password" name="password" type="password" required>
          <button class="bta-btn" type="submit">Sign in</button>
        </form>
        <p class="bta-login-help">Trouble signing in? Call the shop or email
          <a href="mailto:orders@boomerts.com">orders@boomerts.com</a>.</p>
      </div>
    </div>
    <?php
    bta_foot();
}

/* ── Portal shell ────────────────────────────────────────────────────────── */

function bta_render_portal() {
    $user    = bta_current_user();
    $account = $user->account;
    $accent  = $account->brand_color ? $account->brand_color : '#0b5d8f';

    bta_head($account->name . ' · Boomer T\'s', $accent);
    ?>
    <header class="bta-header">
      <div class="bta-header-inner">
        <div class="bta-header-brand">
          <?php if ($account->logo_url) : ?>
            <img class="bta-header-logo" src="<?php echo esc_url($account->logo_url); ?>" alt="<?php echo esc_attr($account->name); ?>">
          <?php else : ?>
            <span class="bta-header-name"><?php echo esc_html($account->name); ?></span>
          <?php endif; ?>
          <span class="bta-header-sep"></span>
          <span class="bta-header-shop">Boomer T's</span>
        </div>
        <div class="bta-header-user">
          <span class="bta-header-who"><?php echo esc_html($user->display_name ? $user->display_name : $user->username); ?></span>
          <a class="bta-header-out" href="<?php echo esc_url(home_url('/' . bta_portal_slug() . '/logout/')); ?>">Sign out</a>
        </div>
      </div>
    </header>

    <nav class="bta-tabs">
      <div class="bta-tabs-inner">
        <span class="bta-tab is-active">Orders</span>
        <span class="bta-tab is-disabled" title="Coming soon">New Order</span>
        <span class="bta-tab is-disabled" title="Coming soon">Quote</span>
      </div>
    </nav>

    <main class="bta-main">
      <h1 class="bta-h1">Welcome, <?php echo esc_html($user->display_name ? $user->display_name : $user->username); ?>.</h1>
      <p class="bta-lede">This is the <?php echo esc_html($account->name); ?> portal at Boomer T's. Your order list will appear here.</p>

      <div class="bta-empty">
        <div class="bta-empty-title">No orders yet</div>
        <p>Order entry and the quoter are being built now. Once they are live you will submit orders here and watch their status update as they move through the shop.</p>
      </div>
    </main>

    <footer class="bta-footer">
      Questions? <a href="mailto:orders@boomerts.com">orders@boomerts.com</a>
    </footer>
    <?php
    bta_foot();
}
