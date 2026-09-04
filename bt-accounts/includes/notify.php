<?php
/**
 * BT Accounts — notifications.
 *
 * An order submitted through the portal used to land silently in the database:
 * the only way to learn about it was to open the admin queue. This hangs email
 * off the hooks that were already being fired, so a submission reaches the shop
 * the moment it happens and the account gets a receipt with a copy of what they
 * actually sent.
 *
 * Everything goes through wp_mail(), so whatever SMTP the site is configured
 * with carries it. Nothing here calls mail() directly.
 */
if (!defined('ABSPATH')) exit;

/* ── Settings ────────────────────────────────────────────────────────────── */

/**
 * The address portal orders go to when nothing has been configured.
 *
 * This used to fall through to the WordPress admin address, which is a
 * mailbox nobody watches — so a submitted order could sit in the queue
 * unseen. The shop default is a real person.
 */
function bta_notify_default_recipient() {
    return apply_filters('bta_notify_default_recipient', 'dillon@boomerts.com');
}

/**
 * Write the default into the setting once, so it is visible and editable on
 * the admin screen rather than being invisible behaviour. Runs a single time;
 * if the field is later cleared on purpose it stays cleared.
 */
add_action('plugins_loaded', 'bta_seed_notify_recipient', 20);
function bta_seed_notify_recipient() {
    if (get_option('bta_notify_seeded')) return;
    if (trim((string) get_option('bta_notify_email', '')) === '') {
        update_option('bta_notify_email', bta_notify_default_recipient());
    }
    update_option('bta_notify_seeded', 1);
}

/** Where shop-side notifications go. Falls back to the shop default above. */
function bta_notify_recipients() {
    $raw = trim((string) get_option('bta_notify_email', ''));
    if ($raw === '') $raw = bta_notify_default_recipient();

    $out = array();
    foreach (preg_split('/[,;\s]+/', $raw) as $e) {
        $e = sanitize_email(trim($e));
        if ($e && is_email($e)) $out[] = $e;
    }
    return apply_filters('bta_notify_recipients', array_values(array_unique($out)));
}

function bta_notify_customer_enabled() {
    return (bool) get_option('bta_notify_customer', 1);
}

function bta_notify_status_enabled() {
    return (bool) get_option('bta_notify_status', 0);
}

/** From: header. Uses the site name and a real address on this domain. */
function bta_mail_from() {
    $from = trim((string) get_option('bta_notify_from', ''));
    if ($from === '' || !is_email($from)) {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        $host = preg_replace('/^www\./i', '', (string) $host);
        $from = 'orders@' . $host;
    }
    return $from;
}

function bta_mail_headers($reply_to = '') {
    $h = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: Boomer T\'s <' . bta_mail_from() . '>',
    );
    if ($reply_to && is_email($reply_to)) $h[] = 'Reply-To: ' . $reply_to;
    return $h;
}

/* ── Hooks ───────────────────────────────────────────────────────────────── */

add_action('bta_order_submitted', 'bta_notify_order_submitted', 10, 1);
function bta_notify_order_submitted($order_id) {
    $order = bta_get_order($order_id);
    if (!$order) return;

    $account = bta_get_account($order->account_id);
    $user    = bta_get_user($order->user_id);
    $items   = bta_get_order_items($order->id);
    $art     = bta_get_order_art($order->id);

    $who      = $user ? ($user->display_name ? $user->display_name : $user->username) : 'Someone';
    $acct     = $account ? $account->name : 'Unknown account';
    $qty      = bta_order_qty($order->id);
    $reply_to = ($user && $user->email) ? $user->email : '';

    /* ── Shop copy ── */
    $to = bta_notify_recipients();
    if ($to) {
        $subject = sprintf(
            '[%s] New order %s — %d pcs%s',
            $acct,
            $order->order_number,
            $qty,
            $order->in_hands_date ? ' — in hands ' . date_i18n('M j', strtotime($order->in_hands_date)) : ''
        );
        $body = bta_mail_order_html($order, $account, $user, $items, $art, 'shop');
        wp_mail($to, $subject, $body, bta_mail_headers($reply_to));
    }

    /* ── Customer receipt ── */
    if (bta_notify_customer_enabled() && $user && $user->email && is_email($user->email)) {
        $subject = sprintf('Boomer T\'s — we have your order %s', $order->order_number);
        $body    = bta_mail_order_html($order, $account, $user, $items, $art, 'customer');
        wp_mail($user->email, $subject, $body, bta_mail_headers(bta_mail_from()));
    }
}

add_action('bta_order_status_changed', 'bta_notify_status_changed', 10, 3);
function bta_notify_status_changed($order_id, $status, $old_status) {
    if (!bta_notify_status_enabled()) return;
    if ($status === $old_status) return;

    $order = bta_get_order($order_id);
    if (!$order) return;

    $account = bta_get_account($order->account_id);
    $user    = bta_get_user($order->user_id);
    if (!$user || !$user->email || !is_email($user->email)) return;

    $accent = ($account && $account->brand_color) ? $account->brand_color : '#27267e';
    $url    = home_url('/' . bta_portal_slug() . '/order/' . (int) $order->id . '/');

    $rows  = bta_mail_row('Order', $order->order_number);
    $rows .= bta_mail_row('Status', bta_status_label($status));
    if ($order->account_po !== '') $rows .= bta_mail_row('Your PO', $order->account_po);
    if ($order->end_customer !== '') $rows .= bta_mail_row('End customer', $order->end_customer);

    $inner  = '<p style="' . bta_mail_p() . '">The status of your order has changed to <strong>'
            . esc_html(bta_status_label($status)) . '</strong>.</p>';
    $inner .= '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:0 0 20px">' . $rows . '</table>';
    $inner .= bta_mail_button($url, 'View this order', $accent);

    $body = bta_mail_wrap(
        $order->order_number . ' — ' . bta_status_label($status),
        $account,
        $accent,
        $inner
    );

    wp_mail($user->email, sprintf('Boomer T\'s — %s is now %s', $order->order_number, bta_status_label($status)), $body, bta_mail_headers(bta_mail_from()));
}

/* ── HTML builders ───────────────────────────────────────────────────────── */

function bta_mail_p() {
    return 'margin:0 0 14px;font:15px/1.55 Tahoma,Segoe UI,Arial,sans-serif;color:#22242a';
}

function bta_mail_row($label, $value) {
    if ($value === '' || $value === null) return '';
    return '<tr>'
         . '<td style="padding:7px 14px 7px 0;font:13px/1.4 Tahoma,Arial,sans-serif;color:#6b7280;white-space:nowrap;vertical-align:top;border-bottom:1px solid #eceef2">' . esc_html($label) . '</td>'
         . '<td style="padding:7px 0;font:15px/1.4 Tahoma,Arial,sans-serif;color:#22242a;vertical-align:top;border-bottom:1px solid #eceef2"><strong>' . esc_html($value) . '</strong></td>'
         . '</tr>';
}

function bta_mail_button($url, $label, $accent) {
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:6px 0 4px"><tr>'
         . '<td style="background:' . esc_attr($accent) . ';border-radius:4px">'
         . '<a href="' . esc_url($url) . '" style="display:inline-block;padding:12px 22px;font:600 15px/1 Tahoma,Arial,sans-serif;color:#ffffff;text-decoration:none;letter-spacing:.02em">'
         . esc_html($label) . '</a></td></tr></table>';
}

/** Outer shell: navy bar, optional account logo, content, footer. */
function bta_mail_wrap($heading, $account, $accent, $inner) {
    $logo = bta_shop_logo_url();
    $brand = $logo
        ? '<img src="' . esc_url($logo) . '" alt="Boomer T\'s" style="height:34px;display:block;border:0">'
        : '<span style="font:700 24px/1 Oswald,Arial Narrow,Arial,sans-serif;color:#ffffff;letter-spacing:.04em">BOOMER T&rsquo;S</span>';

    $acctline = $account
        ? '<div style="font:13px/1.4 Tahoma,Arial,sans-serif;color:#ffffff;opacity:.78;margin-top:6px">' . esc_html($account->name) . ' &middot; Account Portal</div>'
        : '';

    return '<!DOCTYPE html><html><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f4f5f7">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;background:#f4f5f7"><tr><td align="center" style="padding:26px 12px">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;max-width:660px;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e3e5ea">'

        . '<tr><td style="background:#27267e;padding:20px 26px;border-bottom:4px solid ' . esc_attr($accent) . '">'
        . $brand . $acctline
        . '</td></tr>'

        . '<tr><td style="padding:26px">'
        . '<h1 style="margin:0 0 18px;font:600 22px/1.25 Oswald,Arial Narrow,Arial,sans-serif;color:#22242a;letter-spacing:.01em">' . esc_html($heading) . '</h1>'
        . $inner
        . '</td></tr>'

        . '<tr><td style="padding:16px 26px;background:#fafbfc;border-top:1px solid #eceef2;font:12px/1.6 Tahoma,Arial,sans-serif;color:#6b7280">'
        . 'Boomer T&rsquo;s Ink &amp; Thread &middot; <a href="mailto:orders@boomerts.com" style="color:#6b7280">orders@boomerts.com</a>'
        . '</td></tr>'

        . '</table></td></tr></table></body></html>';
}

/**
 * The order email itself. $audience is 'shop' or 'customer' — same order data
 * either way, different framing and different call to action.
 */
function bta_mail_order_html($order, $account, $user, $items, $art, $audience = 'shop') {
    $accent = ($account && $account->brand_color) ? $account->brand_color : '#27267e';
    $who    = $user ? ($user->display_name ? $user->display_name : $user->username) : '';
    $qty    = bta_order_qty($order->id);
    $dec    = bta_decorations();

    /* Intro */
    if ($audience === 'shop') {
        $intro = '<p style="' . bta_mail_p() . '"><strong>' . esc_html($who) . '</strong> at <strong>'
               . esc_html($account ? $account->name : '') . '</strong> submitted an order through the portal. '
               . 'They are supplying the blanks and the artwork — review the art and price the job, then raise the card.</p>';
    } else {
        $intro = '<p style="' . bta_mail_p() . '">Thanks ' . esc_html($who) . ' — we have your order. '
               . 'Here is a copy of what you sent us. We will review the artwork and confirm pricing before anything goes into production.</p>';
    }

    /* Summary rows */
    $rows  = bta_mail_row('Order number', $order->order_number);
    if ($audience === 'shop') $rows .= bta_mail_row('Account', $account ? $account->name : '');
    $rows .= bta_mail_row('End customer', $order->end_customer);
    $rows .= bta_mail_row('Their PO', $order->account_po);
    $rows .= bta_mail_row('Total pieces', $qty);
    $rows .= bta_mail_row('In-hands date', $order->in_hands_date ? date_i18n('D, M j, Y', strtotime($order->in_hands_date)) : '');
    $rows .= bta_mail_row('Submitted', $order->submitted_at ? date_i18n('M j, Y \a\t g:ia', strtotime($order->submitted_at)) : '');
    if ($audience === 'shop') $rows .= bta_mail_row('Submitted by', $who . ($user && $user->email ? ' (' . $user->email . ')' : ''));

    $summary = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:0 0 24px">' . $rows . '</table>';

    /* Blanks */
    $blanks = bta_mail_row('Supplier', $order->supplier_name)
            . bta_mail_row('Supplier PO', $order->supplier_po)
            . bta_mail_row('Expected arrival', $order->expected_arrival ? date_i18n('M j, Y', strtotime($order->expected_arrival)) : '');
    if ($blanks !== '') {
        $summary .= '<h2 style="margin:0 0 10px;font:600 15px/1.3 Oswald,Arial Narrow,Arial,sans-serif;color:#22242a;text-transform:uppercase;letter-spacing:.06em">Blanks inbound</h2>'
                  . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:0 0 24px">' . $blanks . '</table>';
    }

    /* Ship to */
    $addr = trim($order->ship_address1 . ($order->ship_address2 !== '' ? ', ' . $order->ship_address2 : ''));
    $city = trim($order->ship_city . ' ' . $order->ship_state . ' ' . $order->ship_zip);
    $ship = bta_mail_row('Name', $order->ship_name)
          . bta_mail_row('Address', trim($addr . ($city !== '' ? ', ' . $city : ''), ', '))
          . bta_mail_row('Method', $order->ship_method);
    if ($ship !== '') {
        $summary .= '<h2 style="margin:0 0 10px;font:600 15px/1.3 Oswald,Arial Narrow,Arial,sans-serif;color:#22242a;text-transform:uppercase;letter-spacing:.06em">Ship to</h2>'
                  . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:0 0 24px">' . $ship . '</table>';
    }

    /* Items */
    $artby = array();
    foreach ($art as $a) $artby[(int) $a->id] = $a;

    $th = 'padding:9px 10px;font:600 13px/1.3 Tahoma,Arial,sans-serif;color:#ffffff;background:#27267e;text-align:left';
    $td = 'padding:10px;font:14px/1.45 Tahoma,Arial,sans-serif;color:#22242a;border-bottom:1px solid #eceef2;vertical-align:top';

    $body = '<h2 style="margin:0 0 10px;font:600 15px/1.3 Oswald,Arial Narrow,Arial,sans-serif;color:#22242a;text-transform:uppercase;letter-spacing:.06em">Items</h2>'
          . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:0 0 24px;border:1px solid #e3e5ea">'
          . '<tr><th style="' . $th . '">Style</th><th style="' . $th . '">Colour</th><th style="' . $th . '">Sizes</th>'
          . '<th style="' . $th . ';text-align:right">Qty</th><th style="' . $th . '">Decoration</th><th style="' . $th . '">Logo</th></tr>';

    foreach ($items as $it) {
        $parts = array();
        foreach (bta_item_sizes($it) as $s => $q) if ((int) $q > 0) $parts[] = $s . '&times;' . (int) $q;
        $style = '<strong>' . esc_html($it->style_no) . '</strong>';
        $sub   = trim($it->brand . ' ' . $it->style_name);
        if ($sub !== '') $style .= '<br><span style="font-size:13px;color:#6b7280">' . esc_html($sub) . '</span>';

        $decl = isset($dec[$it->decoration]) ? $dec[$it->decoration] : $it->decoration;
        if ($it->placement !== '') $decl .= '<br><span style="font-size:13px;color:#6b7280">' . esc_html($it->placement) . '</span>';

        $body .= '<tr>'
              . '<td style="' . $td . '">' . $style . '</td>'
              . '<td style="' . $td . '">' . esc_html($it->color !== '' ? $it->color : '—') . '</td>'
              . '<td style="' . $td . '">' . ($parts ? implode(', ', $parts) : '—') . '</td>'
              . '<td style="' . $td . ';text-align:right"><strong>' . (int) $it->qty . '</strong></td>'
              . '<td style="' . $td . '">' . $decl . '</td>'
              . '<td style="' . $td . '">' . esc_html(isset($artby[(int) $it->art_id]) ? $artby[(int) $it->art_id]->label : '—') . '</td>'
              . '</tr>';

        if ($it->notes !== '') {
            $body .= '<tr><td colspan="6" style="padding:8px 10px;font:13px/1.45 Tahoma,Arial,sans-serif;color:#6b7280;background:#fafbfc;border-bottom:1px solid #eceef2">'
                   . '<strong>Note:</strong> ' . esc_html($it->notes) . '</td></tr>';
        }
    }
    $body .= '<tr><td colspan="3" style="' . $td . ';border-bottom:none;text-align:right;font-weight:600">Total</td>'
           . '<td style="' . $td . ';border-bottom:none;text-align:right;font-weight:700">' . (int) $qty . '</td>'
           . '<td colspan="2" style="' . $td . ';border-bottom:none"></td></tr>';
    $body .= '</table>';

    /* Artwork */
    if ($art) {
        $body .= '<h2 style="margin:0 0 10px;font:600 15px/1.3 Oswald,Arial Narrow,Arial,sans-serif;color:#22242a;text-transform:uppercase;letter-spacing:.06em">Artwork</h2><ul style="margin:0 0 24px;padding:0 0 0 18px">';
        foreach ($art as $a) {
            $body .= '<li style="margin:0 0 7px;font:14px/1.45 Tahoma,Arial,sans-serif;color:#22242a">'
                   . '<strong>' . esc_html($a->label) . '</strong> &mdash; '
                   . '<a href="' . esc_url($a->file_url) . '" style="color:#27267e">' . esc_html($a->file_name) . '</a></li>';
        }
        $body .= '</ul>';
    }

    /* Notes */
    if ($order->notes !== '') {
        $body .= '<h2 style="margin:0 0 10px;font:600 15px/1.3 Oswald,Arial Narrow,Arial,sans-serif;color:#22242a;text-transform:uppercase;letter-spacing:.06em">Notes</h2>'
               . '<div style="padding:14px;background:#fafbfc;border:1px solid #eceef2;border-radius:5px;margin:0 0 24px;font:14px/1.6 Tahoma,Arial,sans-serif;color:#22242a">'
               . nl2br(esc_html($order->notes)) . '</div>';
    }

    /* Call to action */
    if ($audience === 'shop') {
        $admin = admin_url('admin.php?page=bt-accounts-orders&id=' . (int) $order->id);
        $print = bta_order_print_url($order->id);
        $cta   = bta_mail_button($admin, 'Open in the shop queue', $accent)
               . '<p style="margin:12px 0 0;font:13px/1.5 Tahoma,Arial,sans-serif;color:#6b7280">'
               . 'Printable work order: <a href="' . esc_url($print) . '" style="color:#27267e">' . esc_html($order->order_number) . '</a></p>';
    } else {
        $cta = bta_mail_button(home_url('/' . bta_portal_slug() . '/order/' . (int) $order->id . '/'), 'View or print this order', $accent);
    }

    return bta_mail_wrap(
        ($audience === 'shop' ? 'New order ' : 'Order ') . $order->order_number,
        $account,
        $accent,
        $intro . $summary . $body . $cta
    );
}
