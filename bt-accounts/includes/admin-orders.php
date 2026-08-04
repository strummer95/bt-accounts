<?php
/**
 * BT Accounts — admin order queue.
 * Where the shop reviews a submitted order, links it to a job card, and moves
 * its status. The job card stays the source of truth once linked.
 */
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_submenu_page(
        'bt-accounts', 'Orders', 'Orders', 'manage_options',
        'bt-accounts-orders', 'bta_admin_orders_page'
    );
}, 11);

function bta_admin_orders_page() {
    if (!current_user_can('manage_options')) wp_die('Nope.');
    bta_handle_order_admin_post();

    $id = isset($_GET['order']) ? (int) $_GET['order'] : 0;
    echo '<div class="wrap">';
    if ($id && bta_get_order($id)) bta_admin_order_detail(bta_get_order($id));
    else bta_admin_orders_list();
    echo '</div>';
}

function bta_handle_order_admin_post() {
    if (empty($_POST['bta_order_action'])) return;
    if (!current_user_can('manage_options')) wp_die('Nope.');
    check_admin_referer('bta_orders');

    $action   = sanitize_key($_POST['bta_order_action']);
    $order_id = (int) $_POST['order_id'];
    $me       = wp_get_current_user();
    $by       = $me && $me->display_name ? $me->display_name : 'Shop';

    if ($action === 'set_status') {
        $r = bta_set_order_status(
            $order_id,
            isset($_POST['status']) ? wp_unslash($_POST['status']) : '',
            $by,
            isset($_POST['note']) ? sanitize_text_field(wp_unslash($_POST['note'])) : ''
        );
        if (is_wp_error($r)) bta_admin_notice($r->get_error_message(), 'error');
        else bta_admin_notice('Status updated.');
    }

    if ($action === 'link_job') {
        $job = (int) $_POST['job_id'];
        bta_link_job_card($order_id, $job);
        bta_log_status($order_id, bta_get_order($order_id)->status, 'Linked to job card #' . $job, $by);
        bta_admin_notice($job ? 'Linked to job card #' . $job . '. Status will follow that card from now on.' : 'Job card link cleared.');
    }
}

function bta_admin_orders_list() {
    $filter = isset($_GET['status']) ? wp_unslash($_GET['status']) : '';
    $orders = bta_get_all_orders(array_key_exists($filter, bta_statuses()) ? $filter : '');

    echo '<h1>Account Orders</h1>';
    echo '<p class="description">Orders submitted through the account portals. Review the artwork, price the job, raise the job card, then link it here so the customer sees live status.</p>';

    $base = admin_url('admin.php?page=bt-accounts-orders');
    echo '<ul class="subsubsub"><li><a href="' . esc_url($base) . '"' . ($filter === '' ? ' class="current"' : '') . '>All</a> | </li>';
    $n = 0; $total = count(bta_statuses());
    foreach (bta_statuses() as $key => $label) {
        $n++;
        echo '<li><a href="' . esc_url(add_query_arg('status', rawurlencode($key), $base)) . '"' . ($filter === $key ? ' class="current"' : '') . '>' . esc_html($label) . '</a>' . ($n < $total ? ' | ' : '') . '</li>';
    }
    echo '</ul><div style="clear:both"></div>';

    echo '<table class="widefat striped" style="margin-top:12px"><thead><tr>';
    echo '<th>Order</th><th>Account</th><th>End customer</th><th>PO</th><th>Pieces</th><th>Submitted</th><th>Job card</th><th>Status</th>';
    echo '</tr></thead><tbody>';
    if (!$orders) echo '<tr><td colspan="8">No orders.</td></tr>';
    foreach ($orders as $o) {
        $acct = bta_get_account($o->account_id);
        $url  = add_query_arg('order', (int) $o->id, $base);
        echo '<tr>';
        echo '<td><strong><a href="' . esc_url($url) . '">' . esc_html($o->order_number) . '</a></strong></td>';
        echo '<td>' . esc_html($acct ? $acct->name : '—') . '</td>';
        echo '<td>' . esc_html($o->end_customer) . '</td>';
        echo '<td>' . esc_html($o->account_po !== '' ? $o->account_po : '—') . '</td>';
        echo '<td>' . esc_html(bta_order_qty($o->id)) . '</td>';
        echo '<td>' . esc_html($o->submitted_at ? date_i18n('M j, Y', strtotime($o->submitted_at)) : '—') . '</td>';
        echo '<td>' . ($o->job_id ? '#' . (int) $o->job_id : '<span style="color:#b26d00">not raised</span>') . '</td>';
        echo '<td>' . esc_html(bta_status_label($o->status)) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

function bta_admin_order_detail($order) {
    $acct  = bta_get_account($order->account_id);
    $user  = bta_get_user($order->user_id);
    $items = bta_get_order_items($order->id);
    $art   = bta_get_order_art($order->id);
    $log   = bta_get_order_log($order->id);
    $artby = array();
    foreach ($art as $a) $artby[(int) $a->id] = $a;

    echo '<h1>' . esc_html($order->order_number) . ' <span style="font-weight:400;color:#666">&mdash; ' . esc_html($acct ? $acct->name : '') . '</span></h1>';
    echo '<p><a href="' . esc_url(admin_url('admin.php?page=bt-accounts-orders')) . '">&larr; All orders</a></p>';

    echo '<div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start">';

    // Left: the order
    echo '<div style="flex:2 1 560px;min-width:340px">';
    echo '<table class="widefat" style="margin-bottom:16px"><tbody>';
    echo '<tr><td style="width:180px">Submitted by</td><td>' . esc_html($user ? ($user->display_name ? $user->display_name : $user->username) : '—')
       . ($order->submitted_at ? ' on ' . esc_html(date_i18n('M j, Y g:ia', strtotime($order->submitted_at))) : '') . '</td></tr>';
    echo '<tr><td>End customer</td><td><strong>' . esc_html($order->end_customer) . '</strong></td></tr>';
    echo '<tr><td>Their PO</td><td>' . esc_html($order->account_po !== '' ? $order->account_po : '—') . '</td></tr>';
    echo '<tr><td>Blanks supplier</td><td>' . esc_html($order->supplier_name !== '' ? $order->supplier_name : '—')
       . ($order->supplier_po !== '' ? ' &nbsp;<span style="color:#666">PO ' . esc_html($order->supplier_po) . '</span>' : '') . '</td></tr>';
    echo '<tr><td>Expected arrival</td><td>' . esc_html($order->expected_arrival ? date_i18n('M j, Y', strtotime($order->expected_arrival)) : '—') . '</td></tr>';
    echo '<tr><td>In-hands date</td><td>' . esc_html($order->in_hands_date ? date_i18n('M j, Y', strtotime($order->in_hands_date)) : '—') . '</td></tr>';
    $addr = trim($order->ship_address1 . ($order->ship_address2 !== '' ? ', ' . $order->ship_address2 : '') . ', ' . $order->ship_city . ' ' . $order->ship_state . ' ' . $order->ship_zip, ', ');
    echo '<tr><td>Ship to</td><td>' . esc_html(trim($order->ship_name . ' ' . $addr)) . ($order->ship_method !== '' ? ' &nbsp;<span style="color:#666">via ' . esc_html($order->ship_method) . '</span>' : '') . '</td></tr>';
    if ($order->notes !== '') echo '<tr><td>Notes</td><td>' . nl2br(esc_html($order->notes)) . '</td></tr>';
    echo '</tbody></table>';

    echo '<h2>Items</h2><table class="widefat striped"><thead><tr><th>Style</th><th>Colour</th><th>Sizes</th><th>Qty</th><th>Decoration</th><th>Placement</th><th>Logo</th></tr></thead><tbody>';
    $decs = bta_decorations();
    foreach ($items as $it) {
        $parts = array();
        foreach (bta_item_sizes($it) as $s => $q) if ((int) $q > 0) $parts[] = $s . '&times;' . (int) $q;
        echo '<tr>';
        echo '<td><strong>' . esc_html($it->style_no) . '</strong><br><span style="color:#666">' . esc_html(trim($it->brand . ' ' . $it->style_name)) . '</span></td>';
        echo '<td>' . esc_html($it->color !== '' ? $it->color : '—') . '</td>';
        echo '<td>' . ($parts ? implode(', ', $parts) : '—') . '</td>';
        echo '<td><strong>' . (int) $it->qty . '</strong></td>';
        echo '<td>' . esc_html(isset($decs[$it->decoration]) ? $decs[$it->decoration] : $it->decoration) . '</td>';
        echo '<td>' . esc_html($it->placement) . '</td>';
        echo '<td>' . esc_html(isset($artby[(int) $it->art_id]) ? $artby[(int) $it->art_id]->label : '—') . '</td>';
        echo '</tr>';
        if ($it->notes !== '') echo '<tr><td colspan="7" style="color:#666">Note: ' . esc_html($it->notes) . '</td></tr>';
    }
    echo '<tr><td colspan="3" style="text-align:right"><strong>Total pieces</strong></td><td colspan="4"><strong>' . esc_html(bta_order_qty($order->id)) . '</strong></td></tr>';
    echo '</tbody></table>';

    if ($art) {
        echo '<h2>Artwork</h2><ul>';
        foreach ($art as $a) echo '<li><strong>' . esc_html($a->label) . '</strong> &mdash; <a href="' . esc_url($a->file_url) . '" target="_blank" rel="noopener">' . esc_html($a->file_name) . '</a></li>';
        echo '</ul>';
    }
    echo '</div>';

    // Right: shop actions
    echo '<div style="flex:1 1 300px;min-width:280px">';

    echo '<div class="card" style="max-width:none"><h2 style="margin-top:0">Status</h2>';
    echo '<p><strong>' . esc_html(bta_status_label($order->status)) . '</strong>';
    if ($order->job_id) echo '<br><span style="color:#666">Following job card #' . (int) $order->job_id . '</span>';
    echo '</p>';
    echo '<form method="post">';
    wp_nonce_field('bta_orders');
    echo '<input type="hidden" name="bta_order_action" value="set_status"><input type="hidden" name="order_id" value="' . (int) $order->id . '">';
    echo '<select name="status" style="width:100%;margin-bottom:8px">';
    foreach (bta_statuses() as $k => $label) {
        echo '<option value="' . esc_attr($k) . '"' . selected($order->status, $k, false) . '>' . esc_html($k) . '</option>';
    }
    echo '</select>';
    echo '<input name="note" placeholder="Note (optional, shown to them)" style="width:100%;margin-bottom:8px">';
    echo '<button class="button button-primary">Update status</button>';
    echo '</form></div>';

    echo '<div class="card" style="max-width:none"><h2 style="margin-top:0">Job card</h2>';
    echo '<p class="description">Raise the card on the BT Portal board, then put its id here. Status then follows the card automatically.</p>';
    echo '<form method="post">';
    wp_nonce_field('bta_orders');
    echo '<input type="hidden" name="bta_order_action" value="link_job"><input type="hidden" name="order_id" value="' . (int) $order->id . '">';
    echo '<input name="job_id" type="number" min="0" value="' . (int) $order->job_id . '" style="width:100%;margin-bottom:8px" placeholder="Job card id">';
    echo '<button class="button">Save link</button>';
    echo '</form></div>';

    if ($log) {
        echo '<div class="card" style="max-width:none"><h2 style="margin-top:0">History</h2><ul style="margin:0">';
        foreach (array_reverse($log) as $l) {
            echo '<li style="margin-bottom:8px"><strong>' . esc_html(bta_status_label($l->status)) . '</strong><br>';
            echo '<span style="color:#666;font-size:12px">' . esc_html(date_i18n('M j, Y g:ia', strtotime($l->changed_at)));
            if ($l->changed_by !== '') echo ' &middot; ' . esc_html($l->changed_by);
            echo '</span>';
            if ($l->note !== '') echo '<br><span style="color:#666">' . esc_html($l->note) . '</span>';
            echo '</li>';
        }
        echo '</ul></div>';
    }

    echo '</div></div>';
}
