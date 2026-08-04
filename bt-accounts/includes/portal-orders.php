<?php
/**
 * BT Accounts — portal order screens.
 */
if (!defined('ABSPATH')) exit;

/** Standard sizes offered when a style is typed free-hand. */
function bta_default_sizes() {
    return apply_filters('bta_default_sizes', array('YS','YM','YL','XS','S','M','L','XL','2XL','3XL','4XL','5XL'));
}

function bta_decorations() {
    // Print first — it is the default selection on the order form.
    return array('print' => 'Print', 'embroidery' => 'Embroidery');
}

function bta_placements() {
    return apply_filters('bta_placements', array(
        'Left Chest', 'Right Chest', 'Full Front', 'Full Back', 'Upper Back / Yoke',
        'Left Sleeve', 'Right Sleeve', 'Hat Front', 'Hat Back', 'Other (see notes)',
    ));
}

/* ── Orders list ─────────────────────────────────────────────────────────── */

function bta_portal_orders($user, $account) {
    // Non-admin portal users see only what they submitted.
    $orders = bta_get_orders($account->id, $user->is_account_admin ? 0 : $user->id);
    foreach ($orders as $o) bta_sync_status_from_job($o);

    echo '<div class="bta-page-head">';
    echo '<h1 class="bta-h1">Orders</h1>';
    echo '<a class="bta-btn-sm" href="' . esc_url(bta_portal_url('new')) . '">New order</a>';
    echo '</div>';

    if (!$orders) {
        echo '<div class="bta-empty"><div class="bta-empty-title">No orders yet</div>'
           . '<p>When you submit an order it will appear here, and the status will update as it moves through the shop.</p>'
           . '<p style="margin-top:16px"><a class="bta-btn-sm" href="' . esc_url(bta_portal_url('new')) . '">Start an order</a></p></div>';
        return;
    }

    echo '<div class="bta-tablewrap"><table class="bta-table">';
    echo '<thead><tr><th>Order</th><th>End customer</th><th>PO</th><th>Pieces</th><th>Submitted</th><th>Status</th></tr></thead><tbody>';
    foreach ($orders as $o) {
        $url = bta_portal_url('order/' . (int) $o->id);
        echo '<tr>';
        echo '<td><a class="bta-link-strong" href="' . esc_url($url) . '">' . esc_html($o->order_number) . '</a></td>';
        echo '<td>' . esc_html($o->end_customer !== '' ? $o->end_customer : '—') . '</td>';
        echo '<td>' . esc_html($o->account_po !== '' ? $o->account_po : '—') . '</td>';
        echo '<td>' . esc_html(bta_order_qty($o->id)) . '</td>';
        echo '<td>' . esc_html($o->submitted_at ? date_i18n('M j, Y', strtotime($o->submitted_at)) : '—') . '</td>';
        echo '<td>' . bta_status_pill($o->status) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function bta_status_pill($status) {
    $slug = sanitize_html_class(strtolower(str_replace(array('/', ' '), '-', $status)));
    return '<span class="bta-pill bta-pill-' . esc_attr($slug) . '">' . esc_html(bta_status_label($status)) . '</span>';
}

/* ── Order detail ────────────────────────────────────────────────────────── */

function bta_portal_order_detail($user, $account, $order_id) {
    $order = bta_get_order($order_id);

    // Ownership is checked against the session's account, never a URL value.
    if (!$order || (int) $order->account_id !== (int) $account->id) {
        echo '<h1 class="bta-h1">Order not found</h1><p class="bta-lede">That order does not exist on this account.</p>';
        echo '<p><a class="bta-btn-sm" href="' . esc_url(bta_portal_url()) . '">Back to orders</a></p>';
        return;
    }
    if (!$user->is_account_admin && (int) $order->user_id !== (int) $user->id) {
        echo '<h1 class="bta-h1">Order not found</h1><p class="bta-lede">That order was submitted by someone else on your account.</p>';
        echo '<p><a class="bta-btn-sm" href="' . esc_url(bta_portal_url()) . '">Back to orders</a></p>';
        return;
    }

    bta_sync_status_from_job($order);
    $items = bta_get_order_items($order->id);
    $art   = bta_get_order_art($order->id);
    $log   = bta_get_order_log($order->id);
    $artby = array();
    foreach ($art as $a) $artby[(int) $a->id] = $a;

    echo '<p class="bta-crumb"><a href="' . esc_url(bta_portal_url()) . '">&larr; Orders</a></p>';
    echo '<div class="bta-page-head"><h1 class="bta-h1">' . esc_html($order->order_number) . '</h1>' . bta_status_pill($order->status) . '</div>';

    echo '<div class="bta-cards">';

    echo '<div class="bta-card"><h2 class="bta-h2">Order</h2><dl class="bta-dl">';
    bta_dl('End customer', $order->end_customer);
    bta_dl('Your PO', $order->account_po);
    bta_dl('Submitted', $order->submitted_at ? date_i18n('M j, Y g:ia', strtotime($order->submitted_at)) : '');
    bta_dl('In-hands date', $order->in_hands_date ? date_i18n('M j, Y', strtotime($order->in_hands_date)) : '');
    echo '</dl></div>';

    echo '<div class="bta-card"><h2 class="bta-h2">Blanks</h2><dl class="bta-dl">';
    bta_dl('Supplier', $order->supplier_name);
    bta_dl('Supplier PO', $order->supplier_po);
    bta_dl('Expected arrival', $order->expected_arrival ? date_i18n('M j, Y', strtotime($order->expected_arrival)) : '');
    echo '</dl></div>';

    echo '<div class="bta-card"><h2 class="bta-h2">Ship to</h2><dl class="bta-dl">';
    $addr = trim($order->ship_address1 . ($order->ship_address2 !== '' ? ', ' . $order->ship_address2 : ''));
    $city = trim($order->ship_city . ' ' . $order->ship_state . ' ' . $order->ship_zip);
    bta_dl('Name', $order->ship_name);
    bta_dl('Address', trim($addr . ($city !== '' ? ', ' . $city : ''), ', '));
    bta_dl('Method', $order->ship_method);
    echo '</dl></div>';

    echo '</div>'; // cards

    echo '<h2 class="bta-h2" style="margin-top:28px">Items</h2>';
    echo '<div class="bta-tablewrap"><table class="bta-table">';
    echo '<thead><tr><th>Style</th><th>Colour</th><th>Sizes</th><th>Qty</th><th>Decoration</th><th>Placement</th><th>Logo</th></tr></thead><tbody>';
    foreach ($items as $it) {
        $sizes = bta_item_sizes($it);
        $parts = array();
        foreach ($sizes as $s => $q) if ((int) $q > 0) $parts[] = $s . '&times;' . (int) $q;
        $dec = bta_decorations();
        echo '<tr>';
        echo '<td><strong>' . esc_html($it->style_no) . '</strong><br><span class="bta-sub">' . esc_html(trim($it->brand . ' ' . $it->style_name)) . '</span></td>';
        echo '<td>' . esc_html($it->color !== '' ? $it->color : '—') . '</td>';
        echo '<td>' . ($parts ? implode(', ', $parts) : '—') . '</td>';
        echo '<td>' . (int) $it->qty . '</td>';
        echo '<td>' . esc_html(isset($dec[$it->decoration]) ? $dec[$it->decoration] : $it->decoration) . '</td>';
        echo '<td>' . esc_html($it->placement !== '' ? $it->placement : '—') . '</td>';
        echo '<td>' . esc_html(isset($artby[(int) $it->art_id]) ? $artby[(int) $it->art_id]->label : '—') . '</td>';
        echo '</tr>';
        if ($it->notes !== '') {
            echo '<tr class="bta-row-note"><td colspan="7"><span class="bta-sub">Note:</span> ' . esc_html($it->notes) . '</td></tr>';
        }
    }
    echo '</tbody></table></div>';

    if ($art) {
        echo '<h2 class="bta-h2" style="margin-top:28px">Artwork</h2><ul class="bta-artlist">';
        foreach ($art as $a) {
            echo '<li><strong>' . esc_html($a->label) . '</strong> &mdash; <a href="' . esc_url($a->file_url) . '" target="_blank" rel="noopener">' . esc_html($a->file_name) . '</a></li>';
        }
        echo '</ul>';
    }

    if ($order->notes !== '') {
        echo '<h2 class="bta-h2" style="margin-top:28px">Notes</h2><div class="bta-card"><p style="margin:0">' . nl2br(esc_html($order->notes)) . '</p></div>';
    }

    if ($log) {
        echo '<h2 class="bta-h2" style="margin-top:28px">History</h2><ul class="bta-log">';
        foreach ($log as $l) {
            echo '<li><span class="bta-log-date">' . esc_html(date_i18n('M j, Y g:ia', strtotime($l->changed_at))) . '</span> '
               . bta_status_pill($l->status);
            if ($l->note !== '') echo ' <span class="bta-sub">' . esc_html($l->note) . '</span>';
            echo '</li>';
        }
        echo '</ul>';
    }
}

function bta_dl($label, $value) {
    echo '<dt>' . esc_html($label) . '</dt><dd>' . esc_html($value !== '' && $value !== null ? $value : '—') . '</dd>';
}

/* ── New order form ──────────────────────────────────────────────────────── */

function bta_portal_new_order($user, $account, $errors = array(), $posted = array()) {
    $v = function ($k, $d = '') use ($posted) {
        return isset($posted[$k]) ? (string) $posted[$k] : $d;
    };

    echo '<p class="bta-crumb"><a href="' . esc_url(bta_portal_url()) . '">&larr; Orders</a></p>';
    echo '<h1 class="bta-h1">New order</h1>';
    echo '<p class="bta-lede">You supply the blanks and the artwork; we decorate. We will review the art and confirm pricing before anything goes into production.</p>';

    if ($errors) {
        echo '<div class="bta-alert"><strong>Please fix the following:</strong><ul style="margin:8px 0 0 18px">';
        foreach ($errors as $e) echo '<li>' . esc_html($e) . '</li>';
        echo '</ul></div>';
    }

    echo '<form method="post" enctype="multipart/form-data" id="btaOrderForm">';
    echo bta_csrf_field();
    echo '<input type="hidden" name="bta_submit_order" value="1">';

    // Order details
    echo '<div class="bta-card"><h2 class="bta-h2">Order details</h2><div class="bta-grid">';
    bta_field('end_customer', 'Business / organisation this order is for', $v('end_customer'), true);
    bta_field('account_po', 'Your PO number', $v('account_po'), !empty($account->requires_po));
    bta_field('in_hands_date', 'In-hands date', $v('in_hands_date'), false, 'date');
    echo '</div></div>';

    // Blanks
    echo '<div class="bta-card"><h2 class="bta-h2">Blanks you are sending us</h2>';
    echo '<p class="bta-hint">Who is drop-shipping the garments to us, and under what PO, so we can match the boxes to this job when they land.</p>';
    echo '<div class="bta-grid">';
    bta_field('supplier_name', 'Supplier name', $v('supplier_name'));
    bta_field('supplier_po', 'Supplier PO number', $v('supplier_po'));
    bta_field('expected_arrival', 'Expected arrival at Boomer T&rsquo;s', $v('expected_arrival'), false, 'date');
    echo '</div></div>';

    // Artwork
    echo '<div class="bta-card"><h2 class="bta-h2">Artwork</h2>';
    echo '<p class="bta-hint">Upload each logo and give it a name, then pick that name on the items below so we know which art goes where. Accepted: '
       . esc_html(implode(', ', array_keys(bta_allowed_art_types()))) . ' &mdash; up to 40&nbsp;MB each.</p>';
    echo '<div id="btaArtRows">';
    bta_art_row(0);
    echo '</div>';
    echo '<button type="button" class="bta-btn-ghost" id="btaAddArt">+ Add another logo</button>';
    echo '</div>';

    // Items
    echo '<div class="bta-card"><h2 class="bta-h2">Items</h2>';
    echo '<p class="bta-hint">Type a style number or name to pull it from our catalogue, or just type it in if it is not there.</p>';
    echo '<div id="btaItemRows"></div>';
    echo '<button type="button" class="bta-btn-ghost" id="btaAddItem">+ Add another item</button>';
    echo '</div>';

    // Shipping
    echo '<div class="bta-card"><h2 class="bta-h2">Ship to</h2><div class="bta-grid">';
    bta_field('ship_name', 'Name / attention', $v('ship_name'));
    bta_field('ship_method', 'Shipping method', $v('ship_method'));
    bta_field('ship_address1', 'Address', $v('ship_address1'));
    bta_field('ship_address2', 'Address line 2', $v('ship_address2'));
    bta_field('ship_city', 'City', $v('ship_city'));
    bta_field('ship_state', 'State', $v('ship_state'));
    bta_field('ship_zip', 'ZIP', $v('ship_zip'));
    echo '</div></div>';

    echo '<div class="bta-card"><h2 class="bta-h2">Anything else</h2>';
    echo '<textarea class="bta-input" name="notes" rows="4" placeholder="Special instructions, deadlines, anything we should know.">' . esc_textarea($v('notes')) . '</textarea>';
    echo '</div>';

    echo '<div class="bta-submitbar">';
    echo '<a class="bta-btn-ghost" href="' . esc_url(bta_portal_url()) . '">Cancel</a>';
    echo '<button class="bta-btn bta-btn-inline" type="submit">Submit order</button>';
    echo '</div>';
    echo '</form>';

    // Data the form JS needs.
    echo '<script id="btaFormData" type="application/json">' . wp_json_encode(array(
        'sizes'      => bta_default_sizes(),
        'placements' => bta_placements(),
        'decorations'=> bta_decorations(),
        'searchUrl'  => rest_url('bt-accounts/v1/styles'),
    )) . '</script>';
}

function bta_field($name, $label, $value = '', $required = false, $type = 'text') {
    echo '<div class="bta-field">';
    echo '<label class="bta-label" for="f-' . esc_attr($name) . '">' . wp_kses($label, array('br'=>array())) . ($required ? ' <span class="bta-req">*</span>' : '') . '</label>';
    echo '<input class="bta-input" id="f-' . esc_attr($name) . '" name="' . esc_attr($name) . '" type="' . esc_attr($type) . '" value="' . esc_attr($value) . '"' . ($required ? ' required' : '') . '>';
    echo '</div>';
}

function bta_art_row($i) {
    echo '<div class="bta-artrow">';
    echo '<div class="bta-field"><label class="bta-label">Logo name</label>';
    echo '<input class="bta-input" name="art_label[]" placeholder="e.g. Acme Left Chest"></div>';
    echo '<div class="bta-field"><label class="bta-label">File</label>';
    echo '<input class="bta-input bta-file" type="file" name="art_file[]" accept="' . esc_attr('.' . implode(',.', array_keys(bta_allowed_art_types()))) . '"></div>';
    echo '<button type="button" class="bta-x" aria-label="Remove">&times;</button>';
    echo '</div>';
}

/* ── Submission ──────────────────────────────────────────────────────────── */

/**
 * Validate and store a submitted order.
 * Returns the new order id, or an array of error strings.
 */
function bta_handle_order_submit($user, $account) {
    if (!bta_verify_csrf()) return array('Your session expired. Please try again.');

    $errors = array();
    $txt = function ($k) { return isset($_POST[$k]) ? sanitize_text_field(wp_unslash($_POST[$k])) : ''; };
    $date = function ($k) {
        $v = isset($_POST[$k]) ? trim((string) wp_unslash($_POST[$k])) : '';
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    };

    $data = array(
        'end_customer'     => $txt('end_customer'),
        'account_po'       => $txt('account_po'),
        'supplier_name'    => $txt('supplier_name'),
        'supplier_po'      => $txt('supplier_po'),
        'expected_arrival' => $date('expected_arrival'),
        'in_hands_date'    => $date('in_hands_date'),
        'ship_name'        => $txt('ship_name'),
        'ship_address1'    => $txt('ship_address1'),
        'ship_address2'    => $txt('ship_address2'),
        'ship_city'        => $txt('ship_city'),
        'ship_state'       => $txt('ship_state'),
        'ship_zip'         => $txt('ship_zip'),
        'ship_method'      => $txt('ship_method'),
        'notes'            => isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '',
    );

    if ($data['end_customer'] === '') $errors[] = 'Tell us which business or organisation the order is for.';
    if (!empty($account->requires_po) && $data['account_po'] === '') $errors[] = 'A PO number is required on every order.';

    // ── Artwork ──
    $art = array();
    $labels = isset($_POST['art_label']) ? (array) wp_unslash($_POST['art_label']) : array();
    $files  = isset($_FILES['art_file']) ? $_FILES['art_file'] : null;
    if ($files && is_array($files['name'])) {
        foreach ($files['name'] as $i => $name) {
            if ($name === '') continue;
            $label = isset($labels[$i]) ? sanitize_text_field($labels[$i]) : '';
            if ($label === '') { $errors[] = 'Give every uploaded logo a name.'; continue; }
            $one = array(
                'name'     => $files['name'][$i],
                'type'     => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error'    => $files['error'][$i],
                'size'     => $files['size'][$i],
            );
            $up = bta_handle_art_upload($one, $account->id);
            if (is_wp_error($up)) { $errors[] = $up->get_error_message(); continue; }
            if ($up) $art[] = array('label' => $label, 'url' => $up['url'], 'name' => $up['name']);
        }
    }
    $art_labels = wp_list_pluck($art, 'label');

    // ── Items ──
    $items = array();
    $styles = isset($_POST['item_style']) ? (array) wp_unslash($_POST['item_style']) : array();
    foreach ($styles as $i => $style) {
        $style = sanitize_text_field($style);
        $name  = isset($_POST['item_name'][$i]) ? sanitize_text_field(wp_unslash($_POST['item_name'][$i])) : '';
        if ($style === '' && $name === '') continue;

        $sizes = array();
        $qty = 0;
        if (isset($_POST['item_sizes'][$i]) && is_array($_POST['item_sizes'][$i])) {
            foreach ((array) wp_unslash($_POST['item_sizes'][$i]) as $sz => $q) {
                $q = (int) $q;
                if ($q > 0) { $sizes[sanitize_text_field($sz)] = $q; $qty += $q; }
            }
        }
        if ($qty < 1) { $errors[] = 'Item ' . ($i + 1) . ' (' . ($style !== '' ? $style : $name) . ') needs a quantity in at least one size.'; }

        $dec = isset($_POST['item_decoration'][$i]) ? sanitize_key(wp_unslash($_POST['item_decoration'][$i])) : '';
        if (!array_key_exists($dec, bta_decorations())) $dec = 'print';

        $art_label = isset($_POST['item_art'][$i]) ? sanitize_text_field(wp_unslash($_POST['item_art'][$i])) : '';
        if ($art_label !== '' && !in_array($art_label, $art_labels, true)) $art_label = '';

        $items[] = array(
            'catalog_id' => isset($_POST['item_catalog_id'][$i]) ? (int) $_POST['item_catalog_id'][$i] : 0,
            'style_no'   => $style,
            'style_name' => $name,
            'brand'      => isset($_POST['item_brand'][$i]) ? sanitize_text_field(wp_unslash($_POST['item_brand'][$i])) : '',
            'color'      => isset($_POST['item_color'][$i]) ? sanitize_text_field(wp_unslash($_POST['item_color'][$i])) : '',
            'sizes'      => $sizes,
            'qty'        => $qty,
            'decoration' => $dec,
            'placement'  => isset($_POST['item_placement'][$i]) ? sanitize_text_field(wp_unslash($_POST['item_placement'][$i])) : '',
            'art_label'  => $art_label,
            'notes'      => isset($_POST['item_notes'][$i]) ? sanitize_textarea_field(wp_unslash($_POST['item_notes'][$i])) : '',
        );
    }
    if (!$items) $errors[] = 'Add at least one item.';

    if ($errors) return $errors;

    $res = bta_create_order($account, $user, $data, $items, $art);
    if (is_wp_error($res)) return array($res->get_error_message());
    return (int) $res;
}

/* ── Style search endpoint ───────────────────────────────────────────────── */

add_action('rest_api_init', function () {
    register_rest_route('bt-accounts/v1', '/styles', array(
        'methods'             => 'GET',
        'callback'            => 'bta_rest_styles',
        // Portal session only — this exposes catalogue data, so it is not public.
        'permission_callback' => 'bta_is_logged_in',
    ));
});

function bta_rest_styles($request) {
    $q = (string) $request->get_param('q');
    return rest_ensure_response(bta_catalog_search($q, 10));
}
