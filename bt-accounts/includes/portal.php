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
    add_rewrite_rule('^' . bta_portal_slug() . '/order/([0-9]+)/print/?$', 'index.php?bta_portal=1&bta_view=order&bta_id=$matches[1]&bta_print=1', 'top');
    add_rewrite_rule('^' . bta_portal_slug() . '/order/([0-9]+)/?$', 'index.php?bta_portal=1&bta_view=order&bta_id=$matches[1]', 'top');
    add_rewrite_rule('^' . bta_portal_slug() . '/([a-z0-9_-]+)/?$', 'index.php?bta_portal=1&bta_view=$matches[1]', 'top');
}

add_filter('query_vars', function ($vars) {
    $vars[] = 'bta_portal';
    $vars[] = 'bta_view';
    $vars[] = 'bta_id';
    $vars[] = 'bta_print';
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
            // ?signedin=1 is a cookie probe. If the next request comes back
            // without a session, the sign-in worked and the cookie was dropped
            // — otherwise that failure is silent and looks like a bad password.
            wp_safe_redirect(add_query_arg('signedin', '1', home_url('/' . bta_portal_slug() . '/')));
            exit;
        }
    }

    // The printable work order is its own document — no portal chrome, and the
    // shop can open one straight from wp-admin without a portal session.
    if ($view === 'order' && get_query_var('bta_print')) {
        bta_render_order_print((int) get_query_var('bta_id'));
        exit;
    }

    if (!bta_is_logged_in()) {
        if ($notice === '' && !empty($_GET['signedin'])) {
            $notice = 'Your username and password were correct, but your browser did not keep the sign-in. '
                    . 'That is usually cookies being blocked for this site, or a private/locked-down browser window. '
                    . 'Allow cookies for boomerts.com and try again, or email orders@boomerts.com.';
        }
        bta_render_login($notice);
        exit;
    }

    $user    = bta_current_user();
    $account = $user->account;
    $errors  = array();

    // New-order submit runs before any output so a success can redirect.
    if ($view === 'new' && !empty($_POST['bta_submit_order'])) {
        $res = bta_handle_order_submit($user, $account);
        if (is_array($res)) {
            $errors = $res;
        } else {
            wp_safe_redirect(bta_portal_url('order/' . (int) $res) . '?new=1');
            exit;
        }
    }

    bta_render_portal($view, $errors);
    exit;
}

/* ── Shared chrome ───────────────────────────────────────────────────────── */

/**
 * Boomer T's own logo for the portal chrome. Set one in BT Accounts → Settings,
 * or leave it empty for the Oswald wordmark.
 */
function bta_shop_logo_url() {
    return apply_filters('bta_shop_logo_url', (string) get_option('bta_shop_logo', ''));
}

/** The BOOMER T'S wordmark, apostrophe picked out in magenta. */
function bta_wordmark($class) {
    $logo = bta_shop_logo_url();
    if ($logo) {
        echo '<img class="bta-shop-logo" src="' . esc_url($logo) . '" alt="Boomer T\'s">';
        return;
    }
    echo '<div class="' . esc_attr($class) . '">Boomer T<em>&rsquo;</em>s</div>';
}

/** URL for a portal view: bta_portal_url() or bta_portal_url('new'). */
function bta_portal_url($view = '') {
    return home_url('/' . bta_portal_slug() . '/' . ($view !== '' ? trim($view, '/') . '/' : ''));
}

function bta_head($title, $accent = '#27267e') {
    ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#27267e">
<title><?php echo esc_html($title); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&display=swap">
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
        <div class="bta-login-top">
          <?php
            $logo = bta_shop_logo_url();
            if ($logo) echo '<img class="bta-login-logo" src="' . esc_url($logo) . '" alt="Boomer T\'s">';
            else echo '<div class="bta-login-wordmark">Boomer T<em>&rsquo;</em>s</div>';
          ?>
          <div class="bta-login-sub">Account Portal</div>
        </div>
        <div class="bta-login-body">
          <?php if ($notice) : ?>
            <div class="bta-alert" role="alert"><?php echo esc_html($notice); ?></div>
          <?php endif; ?>
          <form method="post" autocomplete="off">
            <input type="hidden" name="bta_login" value="1">
            <div class="bta-field">
              <label class="bta-label" for="bta-username">Username</label>
              <input class="bta-input" id="bta-username" name="username" autocapitalize="none" autocorrect="off" spellcheck="false" required autofocus>
            </div>
            <div class="bta-field">
              <label class="bta-label" for="bta-password">Password</label>
              <input class="bta-input" id="bta-password" name="password" type="password" required>
            </div>
            <button class="bta-btn" type="submit">Sign in</button>
          </form>
          <p class="bta-login-help">Trouble signing in? Call the shop or email
            <a href="mailto:orders@boomerts.com">orders@boomerts.com</a>.</p>
        </div>
      </div>
    </div>
    <?php
    bta_foot();
}

/* ── Portal shell ────────────────────────────────────────────────────────── */

function bta_render_portal($view = '', $errors = array()) {
    $user    = bta_current_user();
    $account = $user->account;
    $accent  = $account->brand_color ? $account->brand_color : '#27267e';
    $who     = $user->display_name ? $user->display_name : $user->username;

    $titles = array('new' => 'New order', 'order' => 'Order', 'quote' => 'Quote');
    $title  = isset($titles[$view]) ? $titles[$view] . ' · ' : '';

    bta_head($title . $account->name . ' · Boomer T\'s', $accent);
    ?>
    <header class="bta-header">
      <div class="bta-header-inner">
        <div class="bta-header-brand">
          <?php bta_wordmark('bta-wordmark'); ?>
          <span class="bta-header-sep"></span>
          <div class="bta-acct-chip">
            <?php if ($account->logo_url) : ?>
              <img class="bta-acct-logo" src="<?php echo esc_url($account->logo_url); ?>" alt="<?php echo esc_attr($account->name); ?>">
            <?php else : ?>
              <span class="bta-acct-name"><?php echo esc_html($account->name); ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div class="bta-header-user">
          <span class="bta-header-who"><?php echo esc_html($who); ?></span>
          <a class="bta-header-out" href="<?php echo esc_url(bta_portal_url('logout')); ?>">Sign out</a>
        </div>
      </div>
    </header>

    <nav class="bta-tabs">
      <div class="bta-tabs-inner">
        <a class="bta-tab<?php echo ($view === '' || $view === 'order') ? ' is-active' : ''; ?>" href="<?php echo esc_url(bta_portal_url()); ?>">Orders</a>
        <a class="bta-tab<?php echo ($view === 'new') ? ' is-active' : ''; ?>" href="<?php echo esc_url(bta_portal_url('new')); ?>">New Order</a>
        <a class="bta-tab<?php echo ($view === 'quote') ? ' is-active' : ''; ?>" href="<?php echo esc_url(bta_portal_url('quote')); ?>">Quote</a>
      </div>
    </nav>

    <main class="bta-main">
      <?php
      if ($view === 'new') {
          bta_portal_new_order($user, $account, $errors, wp_unslash($_POST));
      } elseif ($view === 'order') {
          if (!empty($_GET['new'])) {
              echo '<div class="bta-notice">Order submitted. We will review the artwork and be in touch to confirm pricing.</div>';
          }
          bta_portal_order_detail($user, $account, (int) get_query_var('bta_id'));
      } elseif ($view === 'quote') {
          bta_portal_quote($user, $account);
      } else {
          bta_portal_orders($user, $account);
      }
      ?>
    </main>

    <footer class="bta-footer">
      Questions? <a href="mailto:orders@boomerts.com">orders@boomerts.com</a>
    </footer>
    <?php
    if ($view === 'new') {
        echo '<script src="' . esc_url(BTA_URL . 'assets/order-form.js?v=' . BTA_VERSION) . '"></script>';
    } elseif ($view === 'quote') {
        echo '<script src="' . esc_url(BTA_URL . 'assets/quote.js?v=' . BTA_VERSION) . '"></script>';
    }
    bta_foot();
}
