<?php
/**
 * BT Accounts — wp-admin for merch-store accounts and payments.
 * Products and the art library live on the account's own page; payment
 * settings on the main BT Accounts page; charges and payments on each order.
 */
if (!defined('ABSPATH')) exit;

function bta_merch_handle_admin_post($action) {
    $acct = isset($_POST['account_id']) ? (int) $_POST['account_id'] : 0;
    $post = function ($k) { return isset($_POST[$k]) ? wp_unslash($_POST[$k]) : ''; };

    if ($action === 'save_product') {
        $r = bta_save_product($acct, array(
            'name' => $post('name'), 'brand' => $post('brand'), 'style_no' => $post('style_no'),
            'colors' => $post('colors'), 'sizes' => $post('sizes'), 'channels' => sanitize_key($post('channels')),
            'image_url' => $post('image_url'), 'decoration' => $post('decoration'), 'placement' => $post('placement'),
            'art_ids' => isset($_POST['art_ids']) ? array_map('intval', (array) $_POST['art_ids']) : array(),
            'bulk_price' => $post('bulk_price'), 'ondemand_price' => $post('ondemand_price'), 'upcharge' => $post('upcharge'),
            'store_ref' => $post('store_ref'), 'notes' => $post('notes'), 'sort_order' => $post('sort_order'),
            'status' => sanitize_key($post('status')),
        ), isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0);
        if (is_wp_error($r)) bta_admin_notice($r->get_error_message(), 'error');
        else bta_admin_notice('Product saved.');
    }

    if ($action === 'delete_product') {
        $p = bta_get_product((int) $post('product_id'));
        if ($p && (int) $p->account_id === $acct) { bta_delete_product($p->id); bta_admin_notice('Product deleted.'); }
    }

    if ($action === 'import_products') {
        $n = bta_import_products($acct, $post('rows'));
        bta_admin_notice($n . ' product' . ($n === 1 ? '' : 's') . ' added.');
    }

    if ($action === 'save_art') {
        $r = bta_save_library_art($acct, array(
            'name' => $post('name'), 'file_url' => $post('file_url'), 'preview_url' => $post('preview_url'),
            'placement' => $post('placement'), 'colors' => $post('colors'), 'notes' => $post('notes'),
            'status' => sanitize_key($post('status')),
        ), isset($_POST['art_id']) ? (int) $_POST['art_id'] : 0);
        if (is_wp_error($r)) bta_admin_notice($r->get_error_message(), 'error');
        else bta_admin_notice('Artwork saved.');
    }

    if ($action === 'delete_art') {
        $a = bta_get_library_art((int) $post('art_id'));
        if ($a && (int) $a->account_id === $acct) { bta_delete_library_art($a->id); bta_admin_notice('Artwork deleted.'); }
    }

    if ($action === 'save_payments') {
        $key = trim((string) $post('stripe_secret'));
        if ($key !== '') update_option('bta_stripe_secret', sanitize_text_field($key), false);
        if (!empty($_POST['stripe_clear'])) delete_option('bta_stripe_secret');
        update_option('bta_pay_instructions', sanitize_textarea_field($post('pay_instructions')));
        bta_admin_notice('Payment settings saved.');
    }
}

/* ── Account page: products + art ────────────────────────────────────────── */

function bta_admin_merch_sections($a) {
    $products = bta_get_products($a->id);
    $library  = bta_library_by_id($a->id);
    $edit_p   = isset($_GET['product']) ? bta_get_product((int) $_GET['product']) : null;
    $edit_a   = isset($_GET['art']) ? bta_get_library_art((int) $_GET['art']) : null;
    if ($edit_p && (int) $edit_p->account_id !== (int) $a->id) $edit_p = null;
    if ($edit_a && (int) $edit_a->account_id !== (int) $a->id) $edit_a = null;
    $base = admin_url('admin.php?page=bt-accounts&account=' . (int) $a->id);
    $ch   = bta_product_channels();

    echo '<h2 style="margin-top:32px" id="bta-products">Products</h2>';
    echo '<p class="description" style="max-width:760px">What they can order. Bulk price is per piece on stock they order; on-demand price is per piece on a single customer&rsquo;s order. Leave a price blank and that item shows &ldquo;Ask&rdquo; and comes through for you to price. 2XL and up adds the upcharge.</p>';
    echo '<table class="widefat striped" style="max-width:1100px;font-size:14px"><thead><tr><th>Product</th><th>Colours</th><th>Sizes</th><th>Orders</th><th>Bulk</th><th>On demand</th><th>2XL+</th><th>Art</th><th></th></tr></thead><tbody>';
    if (!$products) echo '<tr><td colspan="9">No products yet.</td></tr>';
    foreach ($products as $p) {
        $arts = array();
        foreach (bta_product_art_ids($p) as $id) if (isset($library[$id])) $arts[] = $library[$id]->name;
        echo '<tr' . ($p->status !== 'active' ? ' style="opacity:.55"' : '') . '>';
        echo '<td><strong>' . esc_html($p->name) . '</strong><br><span style="color:#666">' . esc_html(trim($p->brand . ' ' . $p->style_no)) . ($p->status !== 'active' ? ' &middot; hidden' : '') . '</span></td>';
        echo '<td>' . esc_html(str_replace(',', ', ', $p->colors)) . '</td>';
        echo '<td>' . esc_html(str_replace(',', ' ', $p->sizes)) . '</td>';
        echo '<td>' . esc_html($ch[$p->channels] ?? $p->channels) . '</td>';
        echo '<td>' . ($p->bulk_price !== null ? esc_html(bta_money($p->bulk_price)) : '<span style="color:#b26d00">not set</span>') . '</td>';
        echo '<td>' . ($p->ondemand_price !== null ? esc_html(bta_money($p->ondemand_price)) : '<span style="color:#b26d00">not set</span>') . '</td>';
        echo '<td>' . ((float) $p->upcharge > 0 ? esc_html(bta_money($p->upcharge)) : '—') . '</td>';
        echo '<td>' . esc_html($arts ? implode(', ', $arts) : 'any') . '</td>';
        echo '<td style="white-space:nowrap"><a class="button button-small" href="' . esc_url(add_query_arg('product', (int) $p->id, $base) . '#bta-product-form') . '">Edit</a> ';
        echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Delete this product?\')">';
        wp_nonce_field('bta_admin');
        echo '<input type="hidden" name="bta_action" value="delete_product"><input type="hidden" name="account_id" value="' . (int) $a->id . '"><input type="hidden" name="product_id" value="' . (int) $p->id . '">';
        echo '<button class="button button-small">Delete</button></form></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    // Add / edit
    $f = function ($k, $d = '') use ($edit_p) { return $edit_p && isset($edit_p->$k) && $edit_p->$k !== null ? (string) $edit_p->$k : $d; };
    echo '<h3 id="bta-product-form" style="margin-top:22px">' . ($edit_p ? 'Edit ' . esc_html($edit_p->name) . ' <a style="font-size:13px;font-weight:400" href="' . esc_url($base . '#bta-products') . '">cancel</a>' : 'Add a product') . '</h3>';
    echo '<form method="post" style="max-width:760px"><table class="form-table">';
    wp_nonce_field('bta_admin');
    echo '<input type="hidden" name="bta_action" value="save_product"><input type="hidden" name="account_id" value="' . (int) $a->id . '">';
    if ($edit_p) echo '<input type="hidden" name="product_id" value="' . (int) $edit_p->id . '">';
    echo '<tr><th>Name</th><td><input name="name" class="regular-text" required value="' . esc_attr($f('name')) . '" placeholder="Heavy Cotton Tee"></td></tr>';
    echo '<tr><th>Brand / style</th><td><input name="brand" style="width:160px" value="' . esc_attr($f('brand')) . '" placeholder="Gildan"> <input name="style_no" style="width:120px" value="' . esc_attr($f('style_no')) . '" placeholder="5000"></td></tr>';
    echo '<tr><th>Colours</th><td><input name="colors" class="large-text" value="' . esc_attr(str_replace(',', ', ', $f('colors'))) . '" placeholder="Black, Sport Grey, White"></td></tr>';
    echo '<tr><th>Sizes</th><td><input name="sizes" class="regular-text" value="' . esc_attr(str_replace(',', ', ', $f('sizes'))) . '" placeholder="S, M, L, XL, 2XL, 3XL"><p class="description">Comma separated. OSFA for caps.</p></td></tr>';
    echo '<tr><th>Offered on</th><td><select name="channels">';
    foreach ($ch as $k => $label) echo '<option value="' . esc_attr($k) . '"' . selected($f('channels', 'both'), $k, false) . '>' . esc_html($label) . '</option>';
    echo '</select></td></tr>';
    echo '<tr><th>Prices</th><td>Bulk $<input name="bulk_price" style="width:80px" value="' . esc_attr($f('bulk_price')) . '"> &nbsp; On demand $<input name="ondemand_price" style="width:80px" value="' . esc_attr($f('ondemand_price')) . '"> &nbsp; 2XL and up add $<input name="upcharge" style="width:70px" value="' . esc_attr((float) $f('upcharge') ? $f('upcharge') : '') . '"></td></tr>';
    echo '<tr><th>Decoration</th><td><select name="decoration">';
    foreach (bta_decorations() as $k => $label) echo '<option value="' . esc_attr($k) . '"' . selected($f('decoration', 'print'), $k, false) . '>' . esc_html($label) . '</option>';
    echo '</select> <input name="placement" style="width:180px" value="' . esc_attr($f('placement')) . '" placeholder="Full Front"></td></tr>';
    echo '<tr><th>Artwork</th><td>';
    if (!$library) echo '<span class="description">No art in the library yet. Add some below; until then orders come through without a design picked.</span>';
    $chosen = $edit_p ? bta_product_art_ids($edit_p) : array();
    foreach ($library as $id => $art) {
        echo '<label style="display:inline-block;margin:0 14px 4px 0"><input type="checkbox" name="art_ids[]" value="' . (int) $id . '"' . checked(in_array((int) $id, $chosen, true), true, false) . '> ' . esc_html($art->name) . '</label>';
    }
    if ($library) echo '<p class="description">Tick the designs this product comes in. None ticked means they can pick any design in the library.</p>';
    echo '</td></tr>';
    echo '<tr><th>Mockup image URL</th><td><input name="image_url" class="large-text" value="' . esc_attr($f('image_url')) . '" placeholder="https://boomerts.com/wp-content/uploads/..."></td></tr>';
    echo '<tr><th>Store item ref</th><td><input name="store_ref" class="regular-text" value="' . esc_attr($f('store_ref')) . '"><p class="description">The item&rsquo;s id or link on their web store, for matching orders up later.</p></td></tr>';
    echo '<tr><th>Order / status</th><td><input name="sort_order" type="number" style="width:70px" value="' . esc_attr($f('sort_order', '0')) . '"> <select name="status"><option value="active">Showing</option><option value="hidden"' . selected($f('status'), 'hidden', false) . '>Hidden</option></select></td></tr>';
    echo '</table><p><button class="button button-primary">' . ($edit_p ? 'Save product' : 'Add product') . '</button></p></form>';

    echo '<details style="max-width:760px;margin-top:8px"><summary style="cursor:pointer;font-weight:600">Paste a list of products</summary>';
    echo '<form method="post"><p class="description">One per line: <code>name, colours, sizes, bulk price, on-demand price, 2XL+ add, image URL</code>. Separate colours with <code>/</code>. Sizes can be a range like <code>S-3XL</code>. Copy straight out of a spreadsheet and tabs work too.</p>';
    wp_nonce_field('bta_admin');
    echo '<input type="hidden" name="bta_action" value="import_products"><input type="hidden" name="account_id" value="' . (int) $a->id . '">';
    echo '<textarea name="rows" rows="6" class="large-text code" placeholder="Tour Tee, Black/White, S-3XL, 9.50, 14, 2"></textarea>';
    echo '<p><button class="button">Add these products</button></p></form></details>';

    // ── Art library ──
    echo '<h2 style="margin-top:32px" id="bta-library">Artwork library</h2>';
    echo '<p class="description" style="max-width:760px">Designs this account&rsquo;s products are printed with. Upload the file to the Media Library and paste its URL. Art the account uploads from the portal shows up here too.</p>';
    echo '<table class="widefat striped" style="max-width:1100px;font-size:14px"><thead><tr><th style="width:70px"></th><th>Design</th><th>Placement / colours</th><th>File</th><th>From</th><th></th></tr></thead><tbody>';
    if (!$library) echo '<tr><td colspan="6">No artwork yet.</td></tr>';
    foreach ($library as $art) {
        $pv = bta_art_preview($art);
        echo '<tr' . ($art->status !== 'active' ? ' style="opacity:.55"' : '') . '>';
        echo '<td>' . ($pv ? '<img src="' . esc_url($pv) . '" alt="" style="max-width:60px;max-height:60px">' : '') . '</td>';
        echo '<td><strong>' . esc_html($art->name) . '</strong>' . ($art->notes !== '' ? '<br><span style="color:#666">' . esc_html($art->notes) . '</span>' : '') . '</td>';
        echo '<td>' . esc_html(implode(' · ', array_filter(array($art->placement, $art->colors)))) . '</td>';
        echo '<td><a href="' . esc_url($art->file_url) . '" target="_blank" rel="noopener">' . esc_html($art->file_name) . '</a></td>';
        echo '<td>' . ($art->added_by === 'account' ? '<strong style="color:#b26d00">Account</strong>' : 'Shop') . ($art->status !== 'active' ? ' &middot; archived' : '') . '</td>';
        echo '<td style="white-space:nowrap"><a class="button button-small" href="' . esc_url(add_query_arg('art', (int) $art->id, $base) . '#bta-art-form') . '">Edit</a> ';
        echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Delete this artwork?\')">';
        wp_nonce_field('bta_admin');
        echo '<input type="hidden" name="bta_action" value="delete_art"><input type="hidden" name="account_id" value="' . (int) $a->id . '"><input type="hidden" name="art_id" value="' . (int) $art->id . '">';
        echo '<button class="button button-small">Delete</button></form></td></tr>';
    }
    echo '</tbody></table>';

    $g = function ($k) use ($edit_a) { return $edit_a ? (string) $edit_a->$k : ''; };
    echo '<h3 id="bta-art-form" style="margin-top:22px">' . ($edit_a ? 'Edit ' . esc_html($edit_a->name) . ' <a style="font-size:13px;font-weight:400" href="' . esc_url($base . '#bta-library') . '">cancel</a>' : 'Add artwork') . '</h3>';
    echo '<form method="post" style="max-width:760px"><table class="form-table">';
    wp_nonce_field('bta_admin');
    echo '<input type="hidden" name="bta_action" value="save_art"><input type="hidden" name="account_id" value="' . (int) $a->id . '">';
    if ($edit_a) echo '<input type="hidden" name="art_id" value="' . (int) $edit_a->id . '">';
    echo '<tr><th>Name</th><td><input name="name" class="regular-text" required value="' . esc_attr($g('name')) . '" placeholder="Tour 2026 logo"></td></tr>';
    echo '<tr><th>File URL</th><td><input name="file_url" class="large-text"' . ($edit_a ? '' : ' required') . ' value="' . esc_attr($g('file_url')) . '"></td></tr>';
    echo '<tr><th>Preview image URL</th><td><input name="preview_url" class="large-text" value="' . esc_attr($g('preview_url')) . '"><p class="description">Only needed when the file itself is not an image (AI, PDF, EPS).</p></td></tr>';
    echo '<tr><th>Placement</th><td><input name="placement" class="regular-text" value="' . esc_attr($g('placement')) . '" placeholder="Full Front"></td></tr>';
    echo '<tr><th>Ink colours</th><td><input name="colors" class="regular-text" value="' . esc_attr($g('colors')) . '"></td></tr>';
    echo '<tr><th>Notes</th><td><textarea name="notes" rows="2" class="large-text">' . esc_textarea($g('notes')) . '</textarea></td></tr>';
    echo '<tr><th>Status</th><td><select name="status"><option value="active">Active</option><option value="archived"' . selected($g('status'), 'archived', false) . '>Archived</option></select></td></tr>';
    echo '</table><p><button class="button button-primary">' . ($edit_a ? 'Save artwork' : 'Add artwork') . '</button></p></form>';
}

/* ── Main page: payment settings ─────────────────────────────────────────── */

function bta_admin_payments_settings() {
    $key = bta_stripe_key();
    echo '<h2 style="margin-top:32px">Payments</h2>';
    echo '<p class="description" style="max-width:640px">Merch-store accounts see what they owe on each order and can pay it from the portal. You record checks, cash and other payments on the order in <em>BT Accounts &rarr; Orders</em>.</p>';
    echo '<form method="post" style="max-width:640px"><table class="form-table">';
    wp_nonce_field('bta_admin');
    echo '<input type="hidden" name="bta_action" value="save_payments">';
    echo '<tr><th><label for="bta-stripe">Card payments (Stripe)</label></th><td>';
    echo '<input id="bta-stripe" name="stripe_secret" class="regular-text" autocomplete="off" value="" placeholder="' . esc_attr($key !== '' ? 'saved: ' . substr($key, 0, 8) . '…' . substr($key, -4) : 'sk_live_…') . '">';
    if ($key !== '') echo '<br><label><input type="checkbox" name="stripe_clear" value="1"> Remove the saved key (turns card payments off)</label>';
    echo '<p class="description">Stripe secret key, from the Stripe dashboard under Developers &rarr; API keys. With a key saved, orders show a <strong>Pay by card</strong> button and payments are marked on the order automatically. Leave blank to keep the saved key. A <code>sk_test_</code> key lets you try it without real charges.</p>';
    echo '</td></tr>';
    echo '<tr><th><label for="bta-payhow">How to pay</label></th><td>';
    echo '<textarea id="bta-payhow" name="pay_instructions" rows="3" class="large-text" placeholder="Checks payable to Boomer T\'s Ink &amp; Thread, or call the shop to pay by card.">' . esc_textarea(bta_pay_instructions()) . '</textarea>';
    echo '<p class="description">Shown beside every balance in the portal.</p></td></tr>';
    echo '</table><p><button class="button button-primary">Save payment settings</button></p></form>';
}

/* ── Order page: charges + payments ──────────────────────────────────────── */

function bta_merch_handle_order_post($action, $order_id, $by) {
    $post = function ($k) { return isset($_POST[$k]) ? wp_unslash($_POST[$k]) : ''; };

    if ($action === 'set_charges') {
        // Line prices first, so the subtotal adds up what the shop typed.
        if (isset($_POST['line']) && is_array($_POST['line'])) {
            global $wpdb;
            foreach (wp_unslash($_POST['line']) as $item_id => $price) {
                $it = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . bta_table('order_items') . " WHERE id = %d AND order_id = %d", (int) $item_id, (int) $order_id));
                if (!$it) continue;
                $each = bta_parse_money($price);
                if ($each === null) {
                    $wpdb->query($wpdb->prepare("UPDATE " . bta_table('order_items') . " SET unit_price = NULL, line_total = NULL WHERE id = %d", (int) $it->id));
                    continue;
                }
                if ($it->unit_price !== null && abs((float) $it->unit_price - $each) < 0.005) continue;
                // A hand-set price applies to every size on the line.
                $wpdb->update(bta_table('order_items'), array(
                    'unit_price' => $each, 'line_total' => round($each * (int) $it->qty, 2), 'price_note' => 'Price set by the shop',
                ), array('id' => (int) $it->id));
            }
            bta_refresh_subtotal($order_id);
        }
        bta_set_order_charges($order_id, (float) bta_parse_money($post('shipping')), (float) bta_parse_money($post('adjustment')));
        bta_admin_notice('Charges saved.');
    }

    if ($action === 'add_payment') {
        $r = bta_record_payment($order_id, (float) bta_parse_money($post('amount')), $post('method'), $post('reference'), $post('note'), $by);
        if (is_wp_error($r)) bta_admin_notice($r->get_error_message(), 'error');
        else bta_admin_notice('Payment recorded.');
    }

    if ($action === 'delete_payment') {
        global $wpdb;
        $pid = (int) $post('payment_id');
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM " . bta_table('payments') . " WHERE id = %d AND order_id = %d", $pid, (int) $order_id))) {
            bta_delete_payment($pid);
            bta_admin_notice('Payment removed.');
        }
    }
}

function bta_admin_order_money($order) {
    $order    = bta_get_order($order->id);
    $items    = bta_get_order_items($order->id);
    $payments = bta_get_payments($order->id);

    echo '<div class="card" style="max-width:none"><h2 style="margin-top:0">Charges</h2>';
    echo '<form method="post">';
    wp_nonce_field('bta_orders');
    echo '<input type="hidden" name="bta_order_action" value="set_charges"><input type="hidden" name="order_id" value="' . (int) $order->id . '">';
    echo '<table style="width:100%;font-size:14px">';
    foreach ($items as $it) {
        echo '<tr><td>' . esc_html($it->style_name) . ($it->color !== '' ? ' &middot; ' . esc_html($it->color) : '') . ' <span style="color:#666">&times;' . (int) $it->qty . '</span></td>';
        echo '<td style="text-align:right;white-space:nowrap">$<input name="line[' . (int) $it->id . ']" style="width:70px" value="' . esc_attr($it->unit_price !== null ? number_format((float) $it->unit_price, 2, '.', '') : '') . '"> ea</td></tr>';
    }
    echo '<tr><td>Shipping</td><td style="text-align:right">$<input name="shipping" style="width:70px" value="' . esc_attr(number_format((float) $order->shipping, 2, '.', '')) . '"></td></tr>';
    echo '<tr><td>Adjustment <span style="color:#666">(minus for a discount)</span></td><td style="text-align:right">$<input name="adjustment" style="width:70px" value="' . esc_attr(number_format((float) $order->adjustment, 2, '.', '')) . '"></td></tr>';
    echo '</table>';
    echo '<p style="margin:10px 0 0;font-size:14px">Items ' . esc_html(bta_money($order->subtotal)) . ' &middot; <strong>Total ' . esc_html(bta_money(bta_order_total($order))) . '</strong><br>';
    echo 'Paid ' . esc_html(bta_money($order->amount_paid)) . ' &middot; <strong>Balance ' . esc_html(bta_money(bta_order_balance($order))) . '</strong></p>';
    echo '<p class="description">A price typed here covers every size on that line, 2XL and up included.</p>';
    echo '<p><button class="button">Save charges</button></p></form></div>';

    echo '<div class="card" style="max-width:none"><h2 style="margin-top:0">Payments</h2>';
    if ($payments) {
        echo '<ul style="margin:0 0 12px">';
        foreach ($payments as $p) {
            echo '<li style="margin-bottom:8px"><strong>' . esc_html(bta_money($p->amount)) . '</strong> ' . esc_html($p->method)
               . '<br><span style="color:#666;font-size:12px">' . esc_html(date_i18n('M j, Y g:ia', strtotime($p->paid_at)))
               . ($p->reference !== '' ? ' &middot; ' . esc_html($p->reference) : '') . ($p->recorded_by !== '' ? ' &middot; ' . esc_html($p->recorded_by) : '') . '</span>';
            echo ' <form method="post" style="display:inline" onsubmit="return confirm(\'Remove this payment?\')">';
            wp_nonce_field('bta_orders');
            echo '<input type="hidden" name="bta_order_action" value="delete_payment"><input type="hidden" name="order_id" value="' . (int) $order->id . '"><input type="hidden" name="payment_id" value="' . (int) $p->id . '">';
            echo '<button class="button-link" style="color:#b32d2e;font-size:12px">remove</button></form></li>';
        }
        echo '</ul>';
    }
    echo '<form method="post">';
    wp_nonce_field('bta_orders');
    echo '<input type="hidden" name="bta_order_action" value="add_payment"><input type="hidden" name="order_id" value="' . (int) $order->id . '">';
    $due = bta_order_balance($order);
    echo '$<input name="amount" style="width:90px;margin-bottom:8px" value="' . esc_attr($due > 0 ? number_format($due, 2, '.', '') : '') . '" placeholder="0.00"> ';
    echo '<select name="method" style="margin-bottom:8px">';
    foreach (array('Check', 'Cash', 'Card', 'ACH / bank', 'Venmo', 'PayPal', 'Other') as $m) echo '<option>' . esc_html($m) . '</option>';
    echo '</select>';
    echo '<input name="reference" placeholder="Check # / reference" style="width:100%;margin-bottom:8px">';
    echo '<button class="button button-primary">Record payment</button>';
    echo '<p class="description">A minus amount records a refund.</p>';
    echo '</form></div>';
}
