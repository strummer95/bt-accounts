<?php
/**
 * BT Accounts — portal screens for merch-store accounts.
 *
 * Tabs: Orders · Products · Bulk Order · On-Demand Order · Artwork · Payments.
 * Contract accounts never reach any of this; bta_render_portal() picks the set.
 */
if (!defined('ABSPATH')) exit;

function bta_merch_tabs() {
    return array(
        ''         => 'Orders',
        'products' => 'Products',
        'bulk'     => 'Bulk Order',
        'ondemand' => 'On-Demand Order',
        'artwork'  => 'Artwork',
        'payments' => 'Payments',
    );
}

/** Same rule as the order page: account admins see everything, others their own. */
function bta_merch_can_see($user, $account, $order) {
    if (!$order || (int) $order->account_id !== (int) $account->id) return false;
    return $user->is_account_admin || (int) $order->user_id === (int) $user->id;
}

/* ── Router hooks (called from bta_route_portal before any output) ───────── */

/**
 * POSTs for merch views. Returns array($errors, $notice); a success that
 * should leave the page redirects and exits instead.
 */
function bta_merch_handle_post($view, $user, $account) {
    if ($view === 'bulk' || $view === 'ondemand') {
        if (empty($_POST['bta_submit_merch'])) return array(array(), '');
        $res = bta_handle_merch_submit($user, $account, $view);
        if (is_array($res)) return array($res, '');
        wp_safe_redirect(bta_portal_url('order/' . (int) $res) . '?new=1');
        exit;
    }

    if ($view === 'artwork' && !empty($_POST['bta_add_art'])) {
        $r = bta_handle_portal_art($account);
        if (is_array($r)) return array($r, '');
        wp_safe_redirect(bta_portal_url('artwork') . '?added=1');
        exit;
    }

    if ($view === 'pay' && !empty($_POST['order_id'])) {
        $order = bta_get_order((int) $_POST['order_id']);
        $back  = $order ? bta_portal_url('order/' . (int) $order->id) : bta_portal_url();
        if (!bta_verify_csrf() || !bta_merch_can_see($user, $account, $order)) {
            wp_safe_redirect($back);
            exit;
        }
        $url = bta_stripe_checkout($order, $account);
        if (is_wp_error($url)) {
            wp_safe_redirect(add_query_arg('payerr', rawurlencode($url->get_error_message()), $back));
            exit;
        }
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        if (!preg_match('/(^|\.)stripe\.com$/', $host)) { wp_safe_redirect($back); exit; }
        wp_redirect($url);
        exit;
    }
    return array(array(), '');
}

/** Body of the portal for a merch account. */
function bta_merch_render_view($view, $user, $account, $errors) {
    if ($view === 'products')      bta_merch_products($account);
    elseif ($view === 'bulk')      bta_merch_order_form($user, $account, 'bulk', $errors, wp_unslash($_POST));
    elseif ($view === 'ondemand')  bta_merch_order_form($user, $account, 'ondemand', $errors, wp_unslash($_POST));
    elseif ($view === 'artwork')   bta_merch_artwork($account, $errors);
    elseif ($view === 'payments')  bta_merch_payments($user, $account);
    elseif ($view === 'order')     bta_merch_order_detail($user, $account, (int) get_query_var('bta_id'));
    else                           bta_merch_orders($user, $account);
}

/* ── Orders list ─────────────────────────────────────────────────────────── */

function bta_merch_orders($user, $account) {
    $orders = bta_get_orders($account->id, $user->is_account_admin ? 0 : $user->id);
    foreach ($orders as $o) bta_sync_status_from_job($o);

    echo '<div class="bta-page-head"><h1 class="bta-h1">Orders</h1><div class="bta-head-btns">';
    echo '<a class="bta-btn-sm" href="' . esc_url(bta_portal_url('bulk')) . '">Bulk order</a>';
    echo '<a class="bta-btn-sm" href="' . esc_url(bta_portal_url('ondemand')) . '">On-demand order</a>';
    echo '</div></div>';

    if (!$orders) {
        echo '<div class="bta-empty"><div class="bta-empty-title">No orders yet</div>'
           . '<p><strong>Bulk order</strong> is stock you order yourselves, like merch for a run of shows. '
           . '<strong>On-demand order</strong> is one customer&rsquo;s order from your web store, shipped straight to them.</p></div>';
        return;
    }

    echo '<div class="bta-tablewrap"><table class="bta-table bta-table-lg">';
    echo '<thead><tr><th>Order</th><th>Type</th><th>For</th><th>Pieces</th><th>Total</th><th>Payment</th><th>Submitted</th><th>Status</th></tr></thead><tbody>';
    foreach ($orders as $o) {
        $url = bta_portal_url('order/' . (int) $o->id);
        $for = $o->order_type === 'ondemand' ? trim($o->ship_name . ($o->external_ref !== '' ? ' · #' . $o->external_ref : '')) : $o->end_customer;
        echo '<tr>';
        echo '<td><a class="bta-link-strong" href="' . esc_url($url) . '">' . esc_html($o->order_number) . '</a></td>';
        echo '<td>' . esc_html($o->order_type !== '' ? bta_order_type_label($o->order_type) : 'Order') . '</td>';
        echo '<td>' . esc_html($for !== '' ? $for : '—') . '</td>';
        echo '<td>' . esc_html(bta_order_qty($o->id)) . '</td>';
        echo '<td>' . (bta_order_total($o) > 0 ? esc_html(bta_money(bta_order_total($o))) : '—') . '</td>';
        echo '<td>' . bta_pay_pill($o) . '</td>';
        echo '<td>' . esc_html($o->submitted_at ? date_i18n('M j, Y', strtotime($o->submitted_at)) : '—') . '</td>';
        echo '<td>' . bta_status_pill($o->status) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

/* ── Products ────────────────────────────────────────────────────────────── */

function bta_merch_products($account) {
    $products = bta_get_products($account->id, true);
    $library  = bta_library_by_id($account->id);

    echo '<div class="bta-page-head"><h1 class="bta-h1">Products</h1></div>';
    echo '<p class="bta-lede">The items on your store, with your prices. Bulk is the per-piece price when you order stock; on demand is the price for one customer&rsquo;s order shipped to them. Want something added or changed? Email <a href="mailto:orders@boomerts.com">orders@boomerts.com</a>.</p>';

    if (!$products) {
        echo '<div class="bta-empty"><div class="bta-empty-title">Products coming soon</div><p>The shop is setting up your product list. Once it is here you can order straight from it.</p></div>';
        return;
    }

    echo '<div class="bta-prodgrid">';
    foreach ($products as $p) {
        $arts = bta_product_art_choices($p, $library);
        echo '<div class="bta-prod">';
        echo '<div class="bta-prod-img">' . (bta_product_image($p) ? '<img src="' . esc_url(bta_product_image($p)) . '" alt="" loading="lazy">' : '<span>' . esc_html(trim($p->brand . ' ' . $p->style_no)) . '</span>') . '</div>';
        echo '<div class="bta-prod-body">';
        echo '<div class="bta-prod-name">' . esc_html($p->name) . '</div>';
        $ch  = bta_product_channels();
        $sub = trim($p->brand . ' ' . $p->style_no);
        if ($sub !== '') echo '<div class="bta-sub">' . esc_html($sub) . '</div>';
        echo '<div class="bta-sub">' . esc_html(implode(' ', bta_product_sizes($p))) . ' &middot; ' . esc_html($ch[$p->channels] ?? '') . '</div>';
        if (bta_product_colors($p)) {
            echo '<div class="bta-prod-colors">';
            foreach (bta_product_colors($p) as $c) echo '<span class="bta-colorchip">' . esc_html($c) . '</span>';
            echo '</div>';
        }
        echo '<div class="bta-prod-prices">';
        echo '<div><span>Bulk</span><strong>' . ($p->bulk_price !== null ? esc_html(bta_money($p->bulk_price)) : 'Ask') . '</strong></div>';
        echo '<div><span>On demand</span><strong>' . ($p->ondemand_price !== null ? esc_html(bta_money($p->ondemand_price)) : 'Ask') . '</strong></div>';
        echo '</div>';
        if ((float) $p->upcharge > 0) echo '<div class="bta-sub">2XL and up +' . esc_html(bta_money($p->upcharge)) . ' each</div>';
        if ($arts) {
            echo '<div class="bta-prod-arts">';
            foreach ($arts as $a) {
                $pv = bta_art_preview($a);
                echo '<span class="bta-artchip">' . ($pv ? '<img src="' . esc_url($pv) . '" alt="">' : '') . esc_html($a->name) . '</span>';
            }
            echo '</div>';
        }
        echo '</div></div>';
    }
    echo '</div>';
}

/* ── Artwork ─────────────────────────────────────────────────────────────── */

function bta_merch_artwork($account, $errors) {
    $library = bta_get_library($account->id, true);

    echo '<div class="bta-page-head"><h1 class="bta-h1">Artwork</h1></div>';
    echo '<p class="bta-lede">Your designs on file. Each product is printed with the art shown here. Have a new design? Add it below and let us know which items it goes on.</p>';

    if (!empty($_GET['added'])) echo '<div class="bta-notice">Artwork added. The shop will check it before it goes on anything.</div>';
    bta_merch_errors($errors);

    if (!$library) {
        echo '<div class="bta-empty" style="margin-bottom:22px"><div class="bta-empty-title">No artwork yet</div><p>Upload your designs below, or send them to orders@boomerts.com.</p></div>';
    } else {
        echo '<div class="bta-artgrid">';
        foreach ($library as $a) {
            $pv = bta_art_preview($a);
            echo '<div class="bta-artcard">';
            echo '<div class="bta-artcard-img">' . ($pv ? '<img src="' . esc_url($pv) . '" alt="" loading="lazy">' : '<span>' . esc_html(strtoupper(pathinfo((string) $a->file_name, PATHINFO_EXTENSION))) . '</span>') . '</div>';
            echo '<div class="bta-artcard-body"><div class="bta-prod-name">' . esc_html($a->name) . '</div>';
            $meta = array_filter(array($a->placement, $a->colors));
            if ($meta) echo '<div class="bta-sub">' . esc_html(implode(' · ', $meta)) . '</div>';
            if ($a->notes !== '') echo '<div class="bta-sub">' . esc_html($a->notes) . '</div>';
            echo '<a class="bta-sub" href="' . esc_url($a->file_url) . '" target="_blank" rel="noopener">' . esc_html($a->file_name) . '</a>';
            echo '</div></div>';
        }
        echo '</div>';
    }

    echo '<form method="post" enctype="multipart/form-data" class="bta-card" style="margin-top:22px">';
    echo bta_csrf_field();
    echo '<input type="hidden" name="bta_add_art" value="1">';
    echo '<h2 class="bta-h2">Add artwork</h2><div class="bta-grid">';
    bta_field('art_name', 'Design name', '', true);
    bta_field('art_placement', 'Where it goes (e.g. Full Front)', '');
    bta_field('art_colors', 'Ink colours', '');
    echo '<div class="bta-field"><label class="bta-label" for="f-art_file">File <span class="bta-req">*</span></label>';
    echo '<input class="bta-input bta-file" id="f-art_file" type="file" name="art_file" required accept="' . esc_attr('.' . implode(',.', array_keys(bta_allowed_art_types()))) . '"></div>';
    echo '</div>';
    echo '<div class="bta-field"><label class="bta-label" for="f-art_notes">Notes</label><textarea class="bta-input" id="f-art_notes" name="art_notes" rows="2" placeholder="Which products it is for, sizes, anything we should know."></textarea></div>';
    echo '<button class="bta-btn bta-btn-inline" type="submit">Add artwork</button>';
    echo '</form>';
}

function bta_handle_portal_art($account) {
    if (!bta_verify_csrf()) return array('Your session expired. Please try again.');
    $name = isset($_POST['art_name']) ? sanitize_text_field(wp_unslash($_POST['art_name'])) : '';
    if ($name === '') return array('Give the design a name.');
    if (empty($_FILES['art_file']['name'])) return array('Choose the art file.');

    $up = bta_handle_art_upload($_FILES['art_file'], $account->id);
    if (is_wp_error($up)) return array($up->get_error_message());
    if (!$up) return array('Choose the art file.');

    $r = bta_save_library_art($account->id, array(
        'name'      => $name,
        'placement' => isset($_POST['art_placement']) ? wp_unslash($_POST['art_placement']) : '',
        'colors'    => isset($_POST['art_colors']) ? wp_unslash($_POST['art_colors']) : '',
        'notes'     => isset($_POST['art_notes']) ? wp_unslash($_POST['art_notes']) : '',
        'file_url'  => $up['url'],
        'file_name' => $up['name'],
        'added_by'  => 'account',
    ));
    return is_wp_error($r) ? array($r->get_error_message()) : true;
}

/* ── Bulk / on-demand order form ─────────────────────────────────────────── */

function bta_merch_errors($errors) {
    if (!$errors) return;
    echo '<div class="bta-alert"><strong>Please fix the following:</strong><ul style="margin:8px 0 0 18px">';
    foreach ($errors as $e) echo '<li>' . esc_html($e) . '</li>';
    echo '</ul></div>';
}

/** The product data the order form's script works from. */
function bta_merch_form_products($account, $type) {
    $library = bta_library_by_id($account->id);
    $out = array();
    foreach (bta_get_products($account->id, true) as $p) {
        if (!bta_product_in($p, $type)) continue;
        $prices = array();
        foreach (bta_product_sizes($p) as $s) $prices[$s] = bta_product_price($p, $type, $s);
        $arts = array();
        foreach (bta_product_art_choices($p, $library) as $a) {
            $arts[] = array('id' => (int) $a->id, 'name' => (string) $a->name, 'img' => bta_art_preview($a));
        }
        $out[] = array(
            'id'     => (int) $p->id,
            'name'   => (string) $p->name,
            'brand'  => trim($p->brand . ' ' . $p->style_no),
            'colors' => bta_product_colors($p),
            'img'    => bta_product_image($p),
            'colorImgs' => (object) array_filter(array_combine(bta_product_colors($p) ?: array(), array_map(function ($c) use ($p) { return bta_product_color_image($p, $c); }, bta_product_colors($p)))),
            'sizes'  => bta_product_sizes($p),
            'prices' => $prices,
            'art'    => $arts,
        );
    }
    return $out;
}

function bta_merch_order_form($user, $account, $type, $errors, $posted) {
    $v = function ($k, $d = '') use ($posted) { return isset($posted[$k]) && is_scalar($posted[$k]) ? (string) $posted[$k] : $d; };
    $products = bta_merch_form_products($account, $type);
    $bulk = $type === 'bulk';

    echo '<p class="bta-crumb"><a href="' . esc_url(bta_portal_url()) . '">&larr; Orders</a></p>';
    echo '<h1 class="bta-h1">' . ($bulk ? 'Bulk order' : 'On-demand order') . '</h1>';
    echo '<p class="bta-lede">' . ($bulk
        ? 'Stock for you: merch for shows, a tour, or to keep on hand. Enter how many of each size you want.'
        : 'One customer&rsquo;s order from your web store. We print it and ship it straight to them.') . '</p>';

    bta_merch_errors($errors);

    if (!$products) {
        echo '<div class="bta-empty"><div class="bta-empty-title">No products yet</div><p>The shop is still setting up your product list. Email orders@boomerts.com if you need something now.</p></div>';
        return;
    }

    echo '<form method="post" id="btaMerchForm" data-type="' . esc_attr($type) . '">';
    echo bta_csrf_field();
    echo '<input type="hidden" name="bta_submit_merch" value="1">';

    if ($bulk) {
        echo '<div class="bta-card"><h2 class="bta-h2">Order details</h2><div class="bta-grid">';
        bta_field('event_name', 'Show / tour / what it is for', $v('event_name'));
        bta_field('account_po', 'Your reference (optional)', $v('account_po'));
        bta_field('in_hands_date', 'Need it by', $v('in_hands_date'), false, 'date', bta_min_in_hands(),
            'A weekday at least a week out: ' . bta_ymd_label(bta_min_in_hands()) . ' or later. Sooner? Call the shop.');
        echo '</div></div>';
    } else {
        echo '<div class="bta-card"><h2 class="bta-h2">Store order</h2><div class="bta-grid">';
        bta_field('external_ref', 'Store order number', $v('external_ref'), false, 'text', '', 'The order number from your Chipply store, so we can match it up.');
        echo '</div></div>';
    }

    echo '<div class="bta-card"><h2 class="bta-h2">Items</h2>';
    echo '<p class="bta-hint">' . ($bulk
        ? 'Add each item: pick the garment, then the design, then how many of each size in each colour.'
        : 'Add each item the customer bought: the garment, the design, then the colour and size.') . '</p>';
    echo '<div id="btaMerchLines"></div>';
    echo '<button type="button" class="bta-btn-ghost" id="btaMerchAdd">+ Add item</button>';
    echo '</div>';

    echo '<div class="bta-card"><h2 class="bta-h2">' . ($bulk ? 'Ship to' : 'Ship to the customer') . '</h2><div class="bta-grid">';
    bta_field('ship_name', $bulk ? 'Name / attention' : 'Customer name', $v('ship_name'), !$bulk);
    if (!$bulk) {
        bta_field('ship_email', 'Customer email', $v('ship_email'), false, 'email');
        bta_field('ship_phone', 'Customer phone', $v('ship_phone'), false, 'tel');
    }
    bta_field('ship_address1', 'Address', $v('ship_address1'), !$bulk);
    bta_field('ship_address2', 'Address line 2', $v('ship_address2'));
    bta_field('ship_city', 'City', $v('ship_city'), !$bulk);
    bta_field('ship_state', 'State', $v('ship_state'), !$bulk);
    bta_field('ship_zip', 'ZIP', $v('ship_zip'), !$bulk);
    bta_field('ship_method', 'Shipping method', $v('ship_method', $bulk ? '' : 'USPS Ground Advantage'));
    echo '</div>';
    echo '<p class="bta-hint" style="margin:4px 0 0">Shipping is added to the order once it is packed and weighed.</p>';
    echo '</div>';

    echo '<div class="bta-card"><h2 class="bta-h2">Notes</h2>';
    echo '<textarea class="bta-input" name="notes" rows="3" placeholder="Anything we should know.">' . esc_textarea($v('notes')) . '</textarea>';
    echo '</div>';

    echo '<div class="bta-submitbar">';
    echo '<div class="bta-est-total" id="btaMerchTotal" aria-live="polite"></div>';
    echo '<a class="bta-btn-ghost" href="' . esc_url(bta_portal_url()) . '">Cancel</a>';
    echo '<button class="bta-btn bta-btn-inline" type="submit">Submit order</button>';
    echo '</div>';
    echo '</form>';

    // Lines to put back after a failed submit.
    $lines = array();
    if (isset($posted['line']) && is_array($posted['line'])) {
        foreach ($posted['line'] as $ln) {
            if (!is_array($ln)) continue;
            $qty = array();
            if (isset($ln['qty']) && is_array($ln['qty'])) {
                foreach ($ln['qty'] as $c => $row) {
                    if (!is_array($row)) continue;
                    foreach ($row as $sz => $q) if (is_scalar($q) && (int) $q > 0) $qty[sanitize_text_field($c)][sanitize_text_field($sz)] = (int) $q;
                }
            }
            $lines[] = array(
                'product' => isset($ln['product']) ? (int) $ln['product'] : 0,
                'art'     => isset($ln['art']) && is_scalar($ln['art']) ? (int) $ln['art'] : 0,
                'qty'     => (object) $qty,
            );
        }
    }

    echo '<script id="btaMerchData" type="application/json">' . wp_json_encode(array(
        'type'     => $type,
        'products' => $products,
        'lines'    => $lines,
    )) . '</script>';
}

/**
 * Validate and save a merch order. Returns the order id or a list of errors.
 * Prices are looked up from the product here, never taken from the form.
 */
function bta_handle_merch_submit($user, $account, $type) {
    if (!bta_verify_csrf()) return array('Your session expired. Please try again.');
    $errors = array();
    $txt  = function ($k) { return isset($_POST[$k]) ? sanitize_text_field(wp_unslash($_POST[$k])) : ''; };
    $date = function ($k) {
        $v = isset($_POST[$k]) ? trim((string) wp_unslash($_POST[$k])) : '';
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    };

    $data = array(
        'event_name'    => $type === 'bulk' ? $txt('event_name') : '',
        'account_po'    => $type === 'bulk' ? $txt('account_po') : '',
        'external_ref'  => $type === 'ondemand' ? $txt('external_ref') : '',
        'in_hands_date' => $type === 'bulk' ? $date('in_hands_date') : '',
        'ship_name'     => $txt('ship_name'),
        'ship_email'    => $type === 'ondemand' ? sanitize_email(isset($_POST['ship_email']) ? wp_unslash($_POST['ship_email']) : '') : '',
        'ship_phone'    => $type === 'ondemand' ? $txt('ship_phone') : '',
        'ship_address1' => $txt('ship_address1'),
        'ship_address2' => $txt('ship_address2'),
        'ship_city'     => $txt('ship_city'),
        'ship_state'    => $txt('ship_state'),
        'ship_zip'      => $txt('ship_zip'),
        'ship_method'   => $txt('ship_method'),
        'notes'         => isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '',
    );

    if ($type === 'bulk' && $data['in_hands_date'] !== '') {
        if ($data['in_hands_date'] < bta_min_in_hands()) {
            $errors[] = 'The need-by date has to be at least a week out: ' . bta_ymd_label(bta_min_in_hands(), true) . ' or later. If you need it sooner, call the shop.';
        } elseif (!bta_is_business_day($data['in_hands_date'])) {
            $errors[] = 'The need-by date has to be a weekday. The next one is ' . bta_ymd_label(bta_business_day_on_or_after($data['in_hands_date']), true) . '.';
        }
    }
    if ($type === 'ondemand') {
        foreach (array('ship_name' => 'the customer name', 'ship_address1' => 'the address', 'ship_city' => 'the city', 'ship_state' => 'the state', 'ship_zip' => 'the ZIP') as $k => $label) {
            if ($data[$k] === '') $errors[] = 'Add ' . $label . ' to ship to.';
        }
        if ($data['external_ref'] !== '') {
            global $wpdb;
            $dupe = $wpdb->get_var($wpdb->prepare(
                "SELECT order_number FROM " . bta_table('orders') . " WHERE account_id = %d AND external_ref = %s LIMIT 1",
                (int) $account->id, $data['external_ref']
            ));
            if ($dupe) $errors[] = 'Store order ' . $data['external_ref'] . ' is already in as ' . $dupe . '.';
        }
    }

    $library = bta_library_by_id($account->id);
    $lines   = array();
    $posted  = isset($_POST['line']) && is_array($_POST['line']) ? wp_unslash($_POST['line']) : array();
    // One card on the form is one garment + design, with a row of sizes per
    // colour. Each colour with a quantity becomes its own order line.
    foreach ($posted as $ln) {
        if (!is_array($ln)) continue;
        $p = bta_get_product(isset($ln['product']) ? (int) $ln['product'] : 0);
        if (!$p || (int) $p->account_id !== (int) $account->id || $p->status !== 'active' || !bta_product_in($p, $type)) continue;

        $choices = bta_product_art_choices($p, $library);
        $art = isset($ln['art']) && is_scalar($ln['art']) ? (int) $ln['art'] : 0;
        if (!isset($choices[$art])) $art = count($choices) === 1 ? (int) key($choices) : 0;
        if (!$art && count($choices) > 1) { $errors[] = 'Pick the design for ' . $p->name . '.'; continue; }

        $allowed = bta_product_sizes($p);
        $colors  = bta_product_colors($p);
        $found   = 0;
        $grid    = isset($ln['qty']) && is_array($ln['qty']) ? $ln['qty'] : array();
        foreach ($grid as $color => $row) {
            $color = (string) $color;
            if (!is_array($row) || ($colors ? !in_array($color, $colors, true) : $color !== '')) continue;
            $sizes = array();
            foreach ($row as $s => $q) {
                $q = is_scalar($q) ? (int) $q : 0;
                if ($q > 0 && in_array((string) $s, $allowed, true)) $sizes[(string) $s] = min($q, 100000);
            }
            if (!$sizes) continue;
            $lines[] = array('product' => $p, 'color' => $color, 'art_id' => $art, 'sizes' => $sizes);
            $found++;
        }
        if (!$found) $errors[] = 'Add quantities for ' . $p->name . ', or remove it.';
    }
    if (!$lines) $errors[] = 'Add at least one item with a quantity.';

    if ($errors) return $errors;
    $r = bta_create_merch_order($account, $user, $type, $data, $lines);
    return is_wp_error($r) ? array($r->get_error_message()) : (int) $r;
}

/* ── Order detail ────────────────────────────────────────────────────────── */

function bta_merch_order_detail($user, $account, $order_id) {
    $order = bta_get_order($order_id);
    if (!bta_merch_can_see($user, $account, $order)) {
        echo '<h1 class="bta-h1">Order not found</h1><p class="bta-lede">That order does not exist on this account.</p>';
        echo '<p><a class="bta-btn-sm" href="' . esc_url(bta_portal_url()) . '">Back to orders</a></p>';
        return;
    }

    if (!empty($_GET['paid'])) {
        $m = bta_stripe_confirm($order, sanitize_text_field(wp_unslash($_GET['paid'])));
        if ($m !== '') echo '<div class="bta-notice">' . esc_html($m) . '</div>';
        $order = bta_get_order($order->id);
    }
    if (!empty($_GET['payerr'])) echo '<div class="bta-alert">Card payment could not start: ' . esc_html(wp_unslash($_GET['payerr'])) . '</div>';
    if (!empty($_GET['paycancel'])) echo '<div class="bta-alert">Payment cancelled. Nothing was charged.</div>';
    if (!empty($_GET['new'])) echo '<div class="bta-notice">Order submitted. We will be in touch if anything needs checking.</div>';

    bta_sync_status_from_job($order);
    $items = bta_get_order_items($order->id);
    $art   = bta_get_order_art($order->id);
    $log   = bta_get_order_log($order->id);
    $artby = array();
    foreach ($art as $a) $artby[(int) $a->id] = $a;
    $bulk = $order->order_type !== 'ondemand';

    echo '<p class="bta-crumb"><a href="' . esc_url(bta_portal_url()) . '">&larr; Orders</a></p>';
    echo '<div class="bta-page-head"><h1 class="bta-h1">' . esc_html($order->order_number) . '</h1>';
    echo '<div class="bta-head-btns">' . bta_status_pill($order->status)
       . '<a class="bta-btn-sm" href="' . esc_url(bta_order_print_url($order->id)) . '" target="_blank" rel="noopener">Print order</a></div>';
    echo '</div>';

    echo '<div class="bta-cards">';

    echo '<div class="bta-card"><h2 class="bta-h2">' . esc_html(bta_order_type_label($order->order_type) ?: 'Order') . ' order</h2><dl class="bta-dl">';
    if ($bulk) {
        bta_dl('For', $order->event_name);
        bta_dl('Your reference', $order->account_po);
        bta_dl('Need it by', $order->in_hands_date ? date_i18n('M j, Y', strtotime($order->in_hands_date)) : '');
    } else {
        bta_dl('Store order #', $order->external_ref);
        bta_dl('Customer', $order->ship_name);
    }
    bta_dl('Submitted', $order->submitted_at ? date_i18n('M j, Y g:ia', strtotime($order->submitted_at)) : '');
    echo '</dl></div>';

    echo '<div class="bta-card"><h2 class="bta-h2">Ship to</h2><dl class="bta-dl">';
    $addr = trim($order->ship_address1 . ($order->ship_address2 !== '' ? ', ' . $order->ship_address2 : ''));
    $city = trim($order->ship_city . ' ' . $order->ship_state . ' ' . $order->ship_zip);
    bta_dl('Name', $order->ship_name);
    bta_dl('Address', trim($addr . ($city !== '' ? ', ' . $city : ''), ', '));
    if (!$bulk) { bta_dl('Email', $order->ship_email); bta_dl('Phone', $order->ship_phone); }
    bta_dl('Method', $order->ship_method);
    echo '</dl></div>';

    bta_merch_pay_card($order);

    echo '</div>'; // cards

    echo '<h2 class="bta-h2" style="margin-top:28px">Items</h2>';
    echo '<div class="bta-tablewrap"><table class="bta-table bta-table-lg">';
    echo '<thead><tr><th>Item</th><th>Design</th><th>Sizes</th><th>Qty</th><th>Each</th><th>Total</th></tr></thead><tbody>';
    foreach ($items as $it) {
        $parts = array();
        foreach (bta_item_sizes($it) as $s => $q) if ((int) $q > 0) $parts[] = $s . '&times;' . (int) $q;
        echo '<tr>';
        echo '<td><strong>' . esc_html($it->style_name !== '' ? $it->style_name : $it->style_no) . '</strong>' . ($it->color !== '' ? '<br><span class="bta-sub">' . esc_html($it->color) . '</span>' : '') . '</td>';
        $logos = bta_item_art_text($it, $artby);
        echo '<td>' . esc_html($logos !== '' ? $logos : '—') . '</td>';
        echo '<td>' . ($parts ? implode(', ', $parts) : '—') . '</td>';
        echo '<td>' . (int) $it->qty . '</td>';
        echo '<td>' . ($it->unit_price !== null ? esc_html(bta_money($it->unit_price)) : '—') . ($it->price_note !== '' ? '<br><span class="bta-sub">' . esc_html($it->price_note) . '</span>' : '') . '</td>';
        echo '<td>' . ($it->line_total !== null ? '<strong>' . esc_html(bta_money($it->line_total)) . '</strong>' : '—') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';

    if ($order->notes !== '') {
        echo '<h2 class="bta-h2" style="margin-top:28px">Notes</h2><div class="bta-card"><p style="margin:0">' . nl2br(esc_html($order->notes)) . '</p></div>';
    }

    $payments = bta_get_payments($order->id);
    if ($payments) {
        echo '<h2 class="bta-h2" style="margin-top:28px">Payments</h2><div class="bta-tablewrap"><table class="bta-table bta-table-lg"><thead><tr><th>Date</th><th>Amount</th><th>How</th><th>Reference</th></tr></thead><tbody>';
        foreach ($payments as $p) {
            echo '<tr><td>' . esc_html(date_i18n('M j, Y', strtotime($p->paid_at))) . '</td><td><strong>' . esc_html(bta_money($p->amount)) . '</strong></td><td>' . esc_html($p->method) . '</td><td>' . esc_html($p->reference !== '' ? $p->reference : '—') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    if ($log) {
        echo '<h2 class="bta-h2" style="margin-top:28px">History</h2><ul class="bta-log">';
        foreach ($log as $l) {
            echo '<li><span class="bta-log-date">' . esc_html(date_i18n('M j, Y g:ia', strtotime($l->changed_at))) . '</span> ' . bta_status_pill($l->status);
            if ($l->note !== '') echo ' <span class="bta-sub">' . esc_html($l->note) . '</span>';
            echo '</li>';
        }
        echo '</ul>';
    }
}

/** Totals, balance and the way to pay it. */
function bta_merch_pay_card($order) {
    $total   = bta_order_total($order);
    $balance = bta_order_balance($order);
    echo '<div class="bta-card"><h2 class="bta-h2">Payment</h2><dl class="bta-dl bta-dl-money">';
    echo '<dt>Items</dt><dd>' . esc_html(bta_money($order->subtotal)) . '</dd>';
    echo '<dt>Shipping</dt><dd>' . ((float) $order->shipping > 0 ? esc_html(bta_money($order->shipping)) : '<span class="bta-sub">added when shipped</span>') . '</dd>';
    if ((float) $order->adjustment != 0) echo '<dt>Adjustment</dt><dd>' . esc_html(bta_money($order->adjustment)) . '</dd>';
    echo '<dt>Total</dt><dd><strong>' . esc_html(bta_money($total)) . '</strong></dd>';
    if ((float) $order->amount_paid != 0) echo '<dt>Paid</dt><dd>' . esc_html(bta_money($order->amount_paid)) . '</dd>';
    echo '<dt>Balance</dt><dd><strong class="bta-balance' . ($balance > 0 ? ' is-due' : '') . '">' . esc_html(bta_money(max(0, $balance))) . '</strong></dd>';
    echo '</dl>';

    if ($balance > 0) {
        if (bta_stripe_on()) {
            echo '<form method="post" action="' . esc_url(bta_portal_url('pay')) . '" style="margin-top:14px">' . bta_csrf_field()
               . '<input type="hidden" name="order_id" value="' . (int) $order->id . '">'
               . '<button class="bta-btn" type="submit">Pay ' . esc_html(bta_money($balance)) . ' by card</button></form>';
        }
        if (bta_pay_instructions() !== '') {
            echo '<p class="bta-sub" style="margin:12px 0 0">' . nl2br(esc_html(bta_pay_instructions())) . '</p>';
        } elseif (!bta_stripe_on()) {
            echo '<p class="bta-sub" style="margin:12px 0 0">The shop will be in touch about payment.</p>';
        }
    } elseif ($total > 0) {
        echo '<p class="bta-sub" style="margin:12px 0 0">Paid in full. Thank you.</p>';
    }
    echo '</div>';
}

/* ── Payments ────────────────────────────────────────────────────────────── */

function bta_merch_payments($user, $account) {
    $orders = bta_get_orders($account->id, $user->is_account_admin ? 0 : $user->id, 500);
    $owing = array();
    $due = 0.0;
    foreach ($orders as $o) {
        $b = bta_order_balance($o);
        if ($b > 0) { $owing[] = $o; $due += $b; }
    }

    echo '<div class="bta-page-head"><h1 class="bta-h1">Payments</h1></div>';
    echo '<div class="bta-cards" style="margin-bottom:24px">';
    echo '<div class="bta-card bta-stat"><span>Balance owing</span><strong>' . esc_html(bta_money($due)) . '</strong><em>' . count($owing) . ' order' . (count($owing) === 1 ? '' : 's') . '</em></div>';
    if (bta_pay_instructions() !== '') echo '<div class="bta-card"><h2 class="bta-h2">How to pay</h2><p style="margin:0">' . nl2br(esc_html(bta_pay_instructions())) . '</p></div>';
    echo '</div>';

    if ($owing) {
        echo '<h2 class="bta-h2">Owing</h2><div class="bta-tablewrap" style="margin-bottom:24px"><table class="bta-table bta-table-lg"><thead><tr><th>Order</th><th>Type</th><th>Total</th><th>Paid</th><th>Balance</th><th></th></tr></thead><tbody>';
        foreach ($owing as $o) {
            $url = bta_portal_url('order/' . (int) $o->id);
            echo '<tr><td><a class="bta-link-strong" href="' . esc_url($url) . '">' . esc_html($o->order_number) . '</a></td>';
            echo '<td>' . esc_html(bta_order_type_label($o->order_type)) . '</td>';
            echo '<td>' . esc_html(bta_money(bta_order_total($o))) . '</td><td>' . esc_html(bta_money($o->amount_paid)) . '</td>';
            echo '<td><strong>' . esc_html(bta_money(bta_order_balance($o))) . '</strong></td>';
            echo '<td>' . (bta_stripe_on() ? '<form method="post" action="' . esc_url(bta_portal_url('pay')) . '">' . bta_csrf_field()
                . '<input type="hidden" name="order_id" value="' . (int) $o->id . '"><button class="bta-btn-sm" type="submit">Pay now</button></form>'
                : '<a class="bta-link-strong" href="' . esc_url($url) . '">View</a>') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    $mine = array();
    foreach ($orders as $o) $mine[(int) $o->id] = $o;
    $history = array_filter(bta_get_account_payments($account->id), function ($p) use ($mine) { return isset($mine[(int) $p->order_id]); });
    echo '<h2 class="bta-h2">Payment history</h2>';
    if (!$history) { echo '<div class="bta-card"><p style="margin:0" class="bta-sub">No payments yet.</p></div>'; return; }
    echo '<div class="bta-tablewrap"><table class="bta-table bta-table-lg"><thead><tr><th>Date</th><th>Order</th><th>Amount</th><th>How</th><th>Reference</th></tr></thead><tbody>';
    foreach ($history as $p) {
        $o = $mine[(int) $p->order_id];
        echo '<tr><td>' . esc_html(date_i18n('M j, Y', strtotime($p->paid_at))) . '</td>';
        echo '<td><a class="bta-link-strong" href="' . esc_url(bta_portal_url('order/' . (int) $o->id)) . '">' . esc_html($o->order_number) . '</a></td>';
        echo '<td><strong>' . esc_html(bta_money($p->amount)) . '</strong></td><td>' . esc_html($p->method) . '</td><td>' . esc_html($p->reference !== '' ? $p->reference : '—') . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
