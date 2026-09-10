<?php
/**
 * BT Accounts — shop staff order handling, from the employee portal.
 *
 * The wp-admin Orders screen needs manage_options, which means a full
 * WordPress administrator. Shop staff work out of BT Portal, so the same order
 * queue is rendered here as [bta_staff_orders], and BT Portal shows it under
 * Other > Accounts to anyone holding bta_handle_orders.
 *
 * Who holds it:
 *   - WordPress administrators, always. Granted by filter, never stored.
 *   - Anyone ticked under BT Accounts > Shop staff. That writes the cap onto
 *     the person's own user record, not onto a role, so giving it to one
 *     employee never gives it to every Portal User.
 *
 * Staff are WordPress users (BT Portal logins). They have nothing to do with
 * the account portal's own logins in wp_bta_users, and nothing here touches
 * those.
 */
if (!defined('ABSPATH')) exit;

define('BTA_STAFF_CAP', 'bta_handle_orders');

add_filter('user_has_cap', 'bta_staff_admin_cap', 10, 1);
function bta_staff_admin_cap($allcaps) {
    if (!empty($allcaps['manage_options'])) $allcaps[BTA_STAFF_CAP] = true;
    return $allcaps;
}

function bta_staff_can() {
    return is_user_logged_in() && current_user_can(BTA_STAFF_CAP);
}

/** The name stamped on order history. Same name the board uses for them. */
function bta_staff_actor() {
    if (function_exists('btp_actor_name')) {
        $n = (string) btp_actor_name();
        if ($n !== '') return $n;
    }
    $u = wp_get_current_user();
    return ($u && $u->display_name) ? $u->display_name : 'Shop';
}

/* ── Who can be given access ─────────────────────────────────────────────── */

/**
 * Everyone with a BT Portal login, plus anyone already holding the cap.
 * Administrators are left off: they always have it, so there is nothing to tick.
 */
function bta_staff_candidates() {
    $by_id = array();

    $roles = array_values(array_filter(array('bt_portal_user', 'bt_portal_admin'), 'get_role'));
    if ($roles) {
        foreach (get_users(array('role__in' => $roles)) as $u) $by_id[(int) $u->ID] = $u;
    }
    foreach (get_users(array('capability' => BTA_STAFF_CAP)) as $u) $by_id[(int) $u->ID] = $u;

    $by_id = array_filter($by_id, function ($u) { return !user_can($u, 'manage_options'); });
    uasort($by_id, function ($a, $b) { return strcasecmp($a->display_name, $b->display_name); });
    return $by_id;
}

/** True only for access given on the person's own record, not via the admin filter. */
function bta_staff_has_own_access($u) {
    return !empty($u->caps[BTA_STAFF_CAP]);
}

function bta_staff_set_access($user_id, $on) {
    $u = get_userdata((int) $user_id);
    if (!$u) return;
    if ($on && !bta_staff_has_own_access($u)) $u->add_cap(BTA_STAFF_CAP);
    if (!$on && isset($u->caps[BTA_STAFF_CAP])) $u->remove_cap(BTA_STAFF_CAP);
}

/** The Shop staff section on the BT Accounts screen. Admin-only; the caller checks. */
function bta_admin_staff_section() {
    $staff = bta_staff_candidates();

    echo '<h2 style="margin-top:32px">Shop staff</h2>';
    echo '<p class="description" style="max-width:680px">Who can work account orders from the employee portal, under <strong>Other &rarr; Accounts</strong>: see what came in, open the art, print the work order, link the job card and move the status. WordPress administrators always can. Ticking someone gives it to that person only, not to everyone with their portal role.</p>';

    if (!$staff) {
        echo '<p>No portal logins found. People are added under <strong>BT Portal &rarr; Portal Users</strong>.</p>';
        return;
    }

    echo '<form method="post" style="max-width:680px">';
    wp_nonce_field('bta_admin');
    echo '<input type="hidden" name="bta_action" value="save_staff">';
    echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Username</th><th>Portal role</th><th style="width:150px">Handles orders</th></tr></thead><tbody>';
    foreach ($staff as $id => $u) {
        $roles = (array) $u->roles;
        $role  = in_array('bt_portal_admin', $roles, true) ? 'Portal admin'
               : (in_array('bt_portal_user', $roles, true) ? 'Portal user' : ($roles ? implode(', ', $roles) : '&mdash;'));
        echo '<tr>';
        echo '<td><strong>' . esc_html($u->display_name) . '</strong></td>';
        echo '<td><code>' . esc_html($u->user_login) . '</code></td>';
        echo '<td>' . esc_html(wp_strip_all_tags($role)) . '</td>';
        echo '<td><label><input type="checkbox" name="staff[]" value="' . (int) $id . '"' . checked(bta_staff_has_own_access($u), true, false) . '> Yes</label></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<p><button class="button button-primary">Save shop staff</button></p></form>';
}

/* ── Data shapes ─────────────────────────────────────────────────────────── */

function bta_staff_date($value, $format) {
    if (!$value || strpos((string) $value, '0000-00-00') === 0) return '';
    $t = strtotime($value);
    return $t ? date_i18n($format, $t) : '';
}

function bta_staff_order_row($o, $account_names) {
    return array(
        'id'           => (int) $o->id,
        'number'       => (string) $o->order_number,
        'account'      => isset($account_names[(int) $o->account_id]) ? $account_names[(int) $o->account_id] : '',
        'end_customer' => (string) $o->end_customer,
        'po'           => (string) $o->account_po,
        'qty'          => bta_order_qty($o->id),
        'submitted'    => bta_staff_date($o->submitted_at, 'M j, Y'),
        'in_hands'     => bta_staff_date($o->in_hands_date, 'M j'),
        'job_id'       => (int) $o->job_id,
        'status'       => (string) $o->status,
        'status_label' => bta_status_label($o->status),
        'open'         => bta_status_is_open($o->status),
    );
}

function bta_staff_statuses() {
    $out = array();
    foreach (bta_statuses() as $key => $label) $out[] = array('key' => $key, 'label' => $label);
    return $out;
}

/** The BT Portal job table, or '' when BT Portal has never been installed. */
function bta_staff_jobs_table() {
    global $wpdb;
    $t = $wpdb->prefix . 'bt_jobs';
    return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t)) === $t ? $t : '';
}

function bta_staff_job_shape($j) {
    return array(
        'id'        => (int) $j->id,
        'order_num' => (string) $j->order_num,
        'customer'  => (string) $j->customer,
        'qty'       => (int) $j->qty,
        'dept'      => (string) $j->dept,
        'status'    => (string) $j->status,
        'due'       => bta_staff_date($j->due_date, 'M j'),
    );
}

function bta_staff_job_summary($job_id) {
    global $wpdb;
    $t = bta_staff_jobs_table();
    if (!$t || !$job_id) return null;
    $j = $wpdb->get_row($wpdb->prepare(
        "SELECT id, order_num, customer, qty, dept, status, due_date FROM $t WHERE id = %d", (int) $job_id
    ));
    return $j ? bta_staff_job_shape($j) : null;
}

function bta_staff_order_detail($o) {
    $acct  = bta_get_account($o->account_id);
    $user  = bta_get_user($o->user_id);
    $decs  = bta_decorations();
    $artby = array();
    $art   = array();
    foreach (bta_get_order_art($o->id) as $a) {
        $artby[(int) $a->id] = $a;
        $art[] = array('label' => (string) $a->label, 'file_name' => (string) $a->file_name, 'url' => esc_url_raw($a->file_url));
    }

    $items = array();
    foreach (bta_get_order_items($o->id) as $it) {
        $parts = array();
        foreach (bta_item_sizes($it) as $s => $q) if ((int) $q > 0) $parts[] = $s . '×' . (int) $q;
        $items[] = array(
            'style_no'   => (string) $it->style_no,
            'style_name' => trim($it->brand . ' ' . $it->style_name),
            'color'      => (string) $it->color,
            'sizes'      => implode(', ', $parts),
            'qty'        => (int) $it->qty,
            'decoration' => isset($decs[$it->decoration]) ? $decs[$it->decoration] : (string) $it->decoration,
            'placement'  => (string) $it->placement,
            'art'        => isset($artby[(int) $it->art_id]) ? (string) $artby[(int) $it->art_id]->label : '',
            'notes'      => (string) $it->notes,
        );
    }

    $log = array();
    foreach (array_reverse(bta_get_order_log($o->id)) as $l) {
        $log[] = array(
            'status' => bta_status_label($l->status),
            'when'   => bta_staff_date($l->changed_at, 'M j, Y g:ia'),
            'by'     => (string) $l->changed_by,
            'note'   => (string) $l->note,
        );
    }

    $addr = trim($o->ship_address1 . ($o->ship_address2 !== '' ? ', ' . $o->ship_address2 : '') . ', ' . $o->ship_city . ' ' . $o->ship_state . ' ' . $o->ship_zip, ', ');

    return array(
        'id'           => (int) $o->id,
        'number'       => (string) $o->order_number,
        'account'      => $acct ? (string) $acct->name : '',
        'submitted_by' => $user ? (string) ($user->display_name ? $user->display_name : $user->username) : '',
        'submitted'    => bta_staff_date($o->submitted_at, 'M j, Y g:ia'),
        'end_customer' => (string) $o->end_customer,
        'po'           => (string) $o->account_po,
        'supplier'     => (string) $o->supplier_name,
        'supplier_po'  => (string) $o->supplier_po,
        'arrival'      => bta_staff_date($o->expected_arrival, 'M j, Y'),
        'in_hands'     => bta_staff_date($o->in_hands_date, 'M j, Y'),
        'ship_to'      => trim($o->ship_name . ' ' . $addr),
        'ship_method'  => (string) $o->ship_method,
        'notes'        => (string) $o->notes,
        'qty'          => bta_order_qty($o->id),
        'status'       => (string) $o->status,
        'status_label' => bta_status_label($o->status),
        'job_id'       => (int) $o->job_id,
        'job'          => bta_staff_job_summary($o->job_id),
        'board'        => bta_staff_jobs_table() !== '',
        'items'        => $items,
        'art'          => $art,
        'log'          => $log,
        'print_url'    => bta_order_print_url($o->id),
    );
}

/* ── REST ────────────────────────────────────────────────────────────────── */

add_action('rest_api_init', 'bta_staff_routes');
function bta_staff_routes() {
    $ns   = 'bt-accounts/v1';
    $perm = 'bta_staff_can';

    register_rest_route($ns, '/staff/orders', array(
        'methods' => 'GET', 'callback' => 'bta_staff_rest_orders', 'permission_callback' => $perm,
    ));
    register_rest_route($ns, '/staff/orders/(?P<id>\d+)', array(
        'methods' => 'GET', 'callback' => 'bta_staff_rest_order', 'permission_callback' => $perm,
    ));
    register_rest_route($ns, '/staff/orders/(?P<id>\d+)/status', array(
        'methods' => 'POST', 'callback' => 'bta_staff_rest_status', 'permission_callback' => $perm,
    ));
    register_rest_route($ns, '/staff/orders/(?P<id>\d+)/job', array(
        'methods' => 'POST', 'callback' => 'bta_staff_rest_job', 'permission_callback' => $perm,
    ));
    register_rest_route($ns, '/staff/jobs', array(
        'methods' => 'GET', 'callback' => 'bta_staff_rest_jobs', 'permission_callback' => $perm,
    ));
}

function bta_staff_rest_orders() {
    $names = array();
    foreach (bta_get_accounts() as $a) $names[(int) $a->id] = $a->name;

    $rows = array();
    foreach (bta_get_all_orders('', 300) as $o) {
        // Same catch-up the account's own order list does, so staff and the
        // customer never see two different statuses for one order.
        if (!empty($o->job_id) && bta_status_is_open($o->status)) bta_sync_status_from_job($o);
        $rows[] = bta_staff_order_row($o, $names);
    }
    return rest_ensure_response(array('orders' => $rows, 'statuses' => bta_staff_statuses()));
}

function bta_staff_load($request) {
    $o = bta_get_order((int) $request['id']);
    return $o ? $o : new WP_Error('bta_no_order', 'That order no longer exists.', array('status' => 404));
}

function bta_staff_rest_order($request) {
    $o = bta_staff_load($request);
    if (is_wp_error($o)) return $o;
    bta_sync_status_from_job($o);
    return rest_ensure_response(array('order' => bta_staff_order_detail($o), 'statuses' => bta_staff_statuses()));
}

function bta_staff_rest_status($request) {
    $o = bta_staff_load($request);
    if (is_wp_error($o)) return $o;

    $r = bta_set_order_status(
        $o->id,
        (string) $request->get_param('status'),
        bta_staff_actor(),
        sanitize_text_field((string) $request->get_param('note'))
    );
    if (is_wp_error($r)) return new WP_Error($r->get_error_code(), $r->get_error_message(), array('status' => 400));

    return rest_ensure_response(array('order' => bta_staff_order_detail(bta_get_order($o->id)), 'statuses' => bta_staff_statuses()));
}

function bta_staff_rest_job($request) {
    $o = bta_staff_load($request);
    if (is_wp_error($o)) return $o;

    $job = max(0, (int) $request->get_param('job_id'));
    if ($job && bta_staff_jobs_table() && !bta_staff_job_summary($job)) {
        return new WP_Error('bta_no_job', 'There is no job card #' . $job . ' on the board.', array('status' => 400));
    }

    bta_link_job_card($o->id, $job);
    bta_log_status($o->id, $o->status, $job ? 'Linked to job card #' . $job : 'Job card link cleared', bta_staff_actor());

    $o = bta_get_order($o->id);
    bta_sync_status_from_job($o);
    return rest_ensure_response(array('order' => bta_staff_order_detail(bta_get_order($o->id)), 'statuses' => bta_staff_statuses()));
}

/** Find a card on the board by order #, customer, or card id. */
function bta_staff_rest_jobs($request) {
    global $wpdb;
    $t = bta_staff_jobs_table();
    if (!$t) return rest_ensure_response(array('jobs' => array(), 'board' => false));

    $q = trim(sanitize_text_field((string) $request->get_param('q')));
    if ($q === '') return rest_ensure_response(array('jobs' => array(), 'board' => true));

    $like = '%' . $wpdb->esc_like($q) . '%';
    $bare = ltrim($q, '#');
    $id   = ctype_digit($bare) ? (int) $bare : 0;

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, order_num, customer, qty, dept, status, due_date FROM $t
         WHERE order_num LIKE %s OR customer LIKE %s OR id = %d
         ORDER BY due_date DESC, id DESC LIMIT 12",
        $like, $like, $id
    ));

    return rest_ensure_response(array('jobs' => array_map('bta_staff_job_shape', (array) $rows), 'board' => true));
}

/* ── The screen ──────────────────────────────────────────────────────────── */

add_shortcode('bta_staff_orders', 'bta_staff_orders_shortcode');

function bta_staff_orders_shortcode() {
    if (!bta_staff_can()) {
        return '<div style="padding:40px;text-align:center;color:#9ca3b8;font-size:15px">You don&rsquo;t have access to account orders. An admin can turn it on under BT Accounts &rarr; Shop staff.</div>';
    }

    $cfg = wp_json_encode(array(
        'api'   => rest_url('bt-accounts/v1/staff'),
        'nonce' => wp_create_nonce('wp_rest'),
    ));

    ob_start();
    ?>
<div id="bta-staff">
<style>
/* Every rule is #bta-staff + class (1,1,0), which outranks BT Portal's
   #bt-schedule-app reset (1,0,0) without the reset needing to know about it. */
#bta-staff { font-family:'Barlow',sans-serif; color:#0f1240; padding:22px 26px 40px; }
#bta-staff .bta-s-head { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
#bta-staff .bta-s-h2 { margin:0; font-family:'Oswald',sans-serif; font-size:1.3em; font-weight:600; letter-spacing:.04em; text-transform:uppercase; color:#0f1240; }
#bta-staff .bta-s-h2 span { color:#9ca3b8; font-weight:500; }
#bta-staff .bta-s-h3 { margin:0 0 8px; font-family:'Oswald',sans-serif; font-size:14px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:#0f1240; }
#bta-staff .bta-s-grow { flex:1 1 auto; }
#bta-staff .bta-s-pills { display:flex; gap:6px; flex-wrap:wrap; }
#bta-staff .bta-s-pill { font-family:'Barlow Condensed',sans-serif; font-size:14px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; padding:5px 11px; border-radius:999px; border:1px solid #d6d9e4; background:#fff; color:#5a6380; cursor:pointer; }
#bta-staff .bta-s-pill b { font-weight:700; color:#9ca3b8; margin-left:4px; }
#bta-staff .bta-s-pill.on { background:#0f1240; border-color:#0f1240; color:#fff; }
#bta-staff .bta-s-pill.on b { color:#e91e8c; }
#bta-staff .bta-s-btn { font-family:'Barlow Condensed',sans-serif; font-size:14px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; padding:7px 14px; border-radius:6px; border:1px solid #0f1240; background:#0f1240; color:#fff; cursor:pointer; text-decoration:none; display:inline-block; line-height:1.2; }
#bta-staff .bta-s-btn:disabled { opacity:.5; cursor:default; }
#bta-staff .bta-s-btn.ghost { background:#fff; color:#0f1240; }
#bta-staff .bta-s-btn.pink { background:#e91e8c; border-color:#e91e8c; }
#bta-staff .bta-s-btn.sm { padding:4px 10px; font-size:13px; }
#bta-staff .bta-s-back { font-size:15px; color:#5a6380; cursor:pointer; background:none; border:none; padding:0; font-family:'Barlow',sans-serif; }
#bta-staff .bta-s-back:hover { color:#0f1240; }
#bta-staff .bta-s-msg { display:none; padding:10px 14px; border-radius:6px; margin-bottom:12px; font-size:15px; }
#bta-staff .bta-s-msg.good { display:block; background:#e8f5e9; color:#1b5e20; }
#bta-staff .bta-s-msg.bad  { display:block; background:#ffebee; color:#b71c1c; }
#bta-staff .bta-s-tablewrap { overflow-x:auto; border:1px solid #e8eaf0; border-radius:8px; background:#fff; }
#bta-staff .bta-s-table { width:100%; border-collapse:collapse; font-size:15px; }
#bta-staff .bta-s-table th { text-align:left; padding:9px 12px; background:#0f1240; color:#fff; font-family:'Barlow Condensed',sans-serif; font-size:15px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; white-space:nowrap; }
#bta-staff .bta-s-table td { padding:9px 12px; border-top:1px solid #f0f1f5; vertical-align:top; }
#bta-staff .bta-s-table tr.row { cursor:pointer; }
#bta-staff .bta-s-table tr.row:hover td { background:#f7f8fc; }
#bta-staff .bta-s-num { font-weight:700; white-space:nowrap; }
#bta-staff .bta-s-dim { color:#9ca3b8; }
#bta-staff .bta-s-warn { color:#b26d00; font-weight:600; }
#bta-staff .bta-s-empty { padding:36px 14px; text-align:center; color:#9ca3b8; font-size:15px; }
#bta-staff .bta-s-status { display:inline-block; font-family:'Barlow Condensed',sans-serif; font-size:13px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; padding:3px 9px; border-radius:999px; white-space:nowrap; background:#e8eaf6; color:#1a1f5e; }
#bta-staff .bta-s-status.new  { background:#fce4f1; color:#b0126a; }
#bta-staff .bta-s-status.hold { background:#ffebee; color:#b71c1c; }
#bta-staff .bta-s-status.done { background:#e8f5e9; color:#1b5e20; }
#bta-staff .bta-s-grid { display:flex; gap:18px; flex-wrap:wrap; align-items:flex-start; }
#bta-staff .bta-s-main { flex:2 1 560px; min-width:320px; display:flex; flex-direction:column; gap:16px; }
#bta-staff .bta-s-side { flex:1 1 300px; min-width:280px; display:flex; flex-direction:column; gap:14px; }
#bta-staff .bta-s-card { background:#fff; border:1px solid #e8eaf0; border-radius:8px; padding:14px 16px; }
#bta-staff .bta-s-kv { width:100%; border-collapse:collapse; font-size:15px; }
#bta-staff .bta-s-kv td { padding:6px 0; border-top:1px solid #f0f1f5; vertical-align:top; }
#bta-staff .bta-s-kv tr:first-child td { border-top:none; }
#bta-staff .bta-s-kv td:first-child { width:150px; color:#5a6380; padding-right:12px; }
#bta-staff .bta-s-field { width:100%; font-family:'Barlow',sans-serif; font-size:15px; padding:7px 9px; border:1px solid #d6d9e4; border-radius:6px; background:#fff; color:#0f1240; margin-bottom:8px; }
#bta-staff .bta-s-row { display:flex; gap:8px; }
#bta-staff .bta-s-row .bta-s-field { margin-bottom:0; }
#bta-staff .bta-s-hint { font-size:13.5px; color:#5a6380; line-height:1.5; margin:0 0 10px; }
#bta-staff .bta-s-job { display:flex; gap:10px; align-items:flex-start; justify-content:space-between; padding:8px 0; border-top:1px solid #f0f1f5; font-size:15px; }
#bta-staff .bta-s-job:first-child { border-top:none; }
#bta-staff .bta-s-job small { display:block; font-size:13.5px; color:#5a6380; }
#bta-staff .bta-s-linked { background:#f4f5f9; border-radius:6px; padding:9px 11px; margin-bottom:10px; font-size:15px; }
#bta-staff .bta-s-log { list-style:none; margin:0; padding:0; font-size:15px; }
#bta-staff .bta-s-log li { padding:7px 0; border-top:1px solid #f0f1f5; }
#bta-staff .bta-s-log li:first-child { border-top:none; }
#bta-staff .bta-s-log small { display:block; font-size:13.5px; color:#5a6380; }
#bta-staff .bta-s-art { margin:0; padding:0; list-style:none; font-size:15px; }
#bta-staff .bta-s-art li { padding:5px 0; }
#bta-staff .bta-s-art a, #bta-staff .bta-s-link { color:#1a1f5e; font-weight:600; }
</style>

<div id="bta-s-list">
  <div class="bta-s-head">
    <h2 class="bta-s-h2">Account Orders</h2>
    <div class="bta-s-pills" id="bta-s-pills"></div>
    <div class="bta-s-grow"></div>
    <button type="button" class="bta-s-btn ghost" data-act="refresh">Refresh</button>
  </div>
  <div class="bta-s-msg" id="bta-s-listmsg"></div>
  <div class="bta-s-tablewrap">
    <table class="bta-s-table">
      <thead><tr><th>Order</th><th>Account</th><th>End customer</th><th>PO</th><th>Pieces</th><th>Submitted</th><th>In hands</th><th>Job card</th><th>Status</th></tr></thead>
      <tbody id="bta-s-body"><tr><td colspan="9" class="bta-s-empty">Loading&hellip;</td></tr></tbody>
    </table>
  </div>
</div>

<div id="bta-s-detail" style="display:none"></div>

<script>
(function () {
  'use strict';
  var CFG  = <?php echo $cfg; ?>;
  var root = document.getElementById('bta-staff');
  if (!root) return;

  var S = { orders: [], statuses: [], filter: 'open', current: null, loaded: false };

  function $(id) { return document.getElementById(id); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function url(path, qs) {
    var u = CFG.api + path;
    if (qs) u += (u.indexOf('?') > -1 ? '&' : '?') + qs;
    return u;
  }
  function api(path, method, body, qs) {
    var opts = { method: method || 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': CFG.nonce } };
    if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    return fetch(url(path, qs), opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (!r.ok) throw new Error((d && d.message) ? d.message : 'The server answered ' + r.status + '.');
        return d;
      });
    });
  }
  function msg(id, text, kind) {
    var el = $(id);
    if (!el) return;
    el.className = 'bta-s-msg' + (text ? ' ' + (kind || 'good') : '');
    el.textContent = text || '';
  }
  function pillClass(status) {
    if (status === 'Submitted') return 'new';
    if (status === 'On Hold') return 'hold';
    if (status === 'Complete/Notify Customer') return 'done';
    return '';
  }
  function statusPill(status, label) {
    return '<span class="bta-s-status ' + pillClass(status) + '">' + esc(label || status) + '</span>';
  }

  /* ── List ── */

  function matches(o) {
    if (S.filter === 'all') return true;
    if (S.filter === 'open') return o.open;
    return o.status === S.filter;
  }

  function renderPills() {
    var n = { open: 0, all: S.orders.length };
    S.orders.forEach(function (o) { if (o.open) n.open++; n[o.status] = (n[o.status] || 0) + 1; });
    var defs = [{ key: 'open', label: 'Open' }].concat(S.statuses).concat([{ key: 'all', label: 'All' }]);
    $('bta-s-pills').innerHTML = defs.map(function (d) {
      return '<button type="button" class="bta-s-pill' + (S.filter === d.key ? ' on' : '') + '" data-filter="' + esc(d.key) + '">'
        + esc(d.label) + '<b>' + (n[d.key] || 0) + '</b></button>';
    }).join('');
  }

  function renderList() {
    renderPills();
    var rows = S.orders.filter(matches);
    if (!rows.length) {
      $('bta-s-body').innerHTML = '<tr><td colspan="9" class="bta-s-empty">'
        + (S.orders.length ? 'Nothing in this list.' : 'No account orders yet. When an account submits one it lands here.')
        + '</td></tr>';
      return;
    }
    $('bta-s-body').innerHTML = rows.map(function (o) {
      return '<tr class="row" data-open="' + o.id + '">'
        + '<td class="bta-s-num">' + esc(o.number) + '</td>'
        + '<td>' + esc(o.account) + '</td>'
        + '<td>' + esc(o.end_customer || '—') + '</td>'
        + '<td>' + esc(o.po || '—') + '</td>'
        + '<td>' + esc(o.qty) + '</td>'
        + '<td>' + esc(o.submitted || '—') + '</td>'
        + '<td>' + esc(o.in_hands || '—') + '</td>'
        + '<td>' + (o.job_id ? '#' + o.job_id : '<span class="bta-s-warn">not raised</span>') + '</td>'
        + '<td>' + statusPill(o.status, o.status_label) + '</td>'
        + '</tr>';
    }).join('');
  }

  function loadList() {
    return api('/orders').then(function (d) {
      S.orders = d.orders || []; S.statuses = d.statuses || []; S.loaded = true;
      msg('bta-s-listmsg', '');
      renderList();
    }).catch(function (e) {
      $('bta-s-body').innerHTML = '<tr><td colspan="9" class="bta-s-empty">Could not load orders.</td></tr>';
      msg('bta-s-listmsg', e.message, 'bad');
    });
  }

  function showList() {
    S.current = null;
    $('bta-s-detail').style.display = 'none';
    $('bta-s-list').style.display = '';
    loadList();
  }

  /* ── Detail ── */

  function kv(label, value) {
    return value ? '<tr><td>' + esc(label) + '</td><td>' + value + '</td></tr>' : '';
  }

  function renderDetail(o) {
    S.current = o;
    var info = '<table class="bta-s-kv">'
      + kv('Submitted by', esc(o.submitted_by || '—') + (o.submitted ? ' <span class="bta-s-dim">on ' + esc(o.submitted) + '</span>' : ''))
      + kv('End customer', '<strong>' + esc(o.end_customer || '—') + '</strong>')
      + kv('Their PO', esc(o.po || '—'))
      + kv('Blanks supplier', esc(o.supplier || '—') + (o.supplier_po ? ' <span class="bta-s-dim">PO ' + esc(o.supplier_po) + '</span>' : ''))
      + kv('Expected arrival', esc(o.arrival || '—'))
      + kv('In-hands date', esc(o.in_hands || '—'))
      + kv('Ship to', esc(o.ship_to || '—') + (o.ship_method ? ' <span class="bta-s-dim">via ' + esc(o.ship_method) + '</span>' : ''))
      + (o.notes ? kv('Notes', esc(o.notes).replace(/\n/g, '<br>')) : '')
      + '</table>';

    var items = '<div class="bta-s-tablewrap"><table class="bta-s-table"><thead><tr><th>Style</th><th>Colour</th><th>Sizes</th><th>Qty</th><th>Decoration</th><th>Placement</th><th>Logo</th></tr></thead><tbody>'
      + (o.items.length ? o.items.map(function (it) {
          return '<tr><td><strong>' + esc(it.style_no) + '</strong><br><span class="bta-s-dim">' + esc(it.style_name) + '</span></td>'
            + '<td>' + esc(it.color || '—') + '</td><td>' + esc(it.sizes || '—') + '</td><td><strong>' + esc(it.qty) + '</strong></td>'
            + '<td>' + esc(it.decoration) + '</td><td>' + esc(it.placement) + '</td><td>' + esc(it.art || '—') + '</td></tr>'
            + (it.notes ? '<tr><td colspan="7" class="bta-s-dim">Note: ' + esc(it.notes) + '</td></tr>' : '');
        }).join('') : '<tr><td colspan="7" class="bta-s-empty">No items.</td></tr>')
      + '<tr><td colspan="3" style="text-align:right"><strong>Total pieces</strong></td><td colspan="4"><strong>' + esc(o.qty) + '</strong></td></tr>'
      + '</tbody></table></div>';

    var art = o.art.length
      ? '<div class="bta-s-card"><h3 class="bta-s-h3">Artwork</h3><ul class="bta-s-art">' + o.art.map(function (a) {
          return '<li><strong>' + esc(a.label) + '</strong> &middot; <a href="' + esc(a.url) + '" target="_blank" rel="noopener">' + esc(a.file_name) + '</a></li>';
        }).join('') + '</ul></div>'
      : '';

    var opts = S.statuses.map(function (s) {
      return '<option value="' + esc(s.key) + '"' + (s.key === o.status ? ' selected' : '') + '>' + esc(s.key) + '</option>';
    }).join('');

    var status = '<div class="bta-s-card"><h3 class="bta-s-h3">Status</h3>'
      + '<p style="margin:0 0 10px">' + statusPill(o.status, o.status_label) + '</p>'
      + (o.job_id ? '<p class="bta-s-hint">Following job card #' + o.job_id + '. Move it on the board; a change made here gets overwritten by the card.</p>' : '')
      + '<select class="bta-s-field" id="bta-s-status">' + opts + '</select>'
      + '<input class="bta-s-field" id="bta-s-note" placeholder="Note (optional, the account sees it)">'
      + '<button type="button" class="bta-s-btn" data-act="status">Update status</button></div>';

    var linked = o.job_id
      ? '<div class="bta-s-linked">' + (o.job
          ? '<strong>#' + o.job.id + '</strong> &middot; ' + esc(o.job.order_num || 'no order #') + ' &middot; ' + esc(o.job.customer)
            + '<small class="bta-s-dim" style="display:block">' + esc(o.job.status) + (o.job.due ? ' &middot; due ' + esc(o.job.due) : '') + (o.job.dept ? ' &middot; ' + esc(o.job.dept) : '') + '</small>'
          : '<strong>#' + o.job_id + '</strong> <span class="bta-s-warn">is no longer on the board</span>')
        + '<div style="margin-top:8px"><button type="button" class="bta-s-btn ghost sm" data-act="unlink">Unlink</button></div></div>'
      : '';

    var job = '<div class="bta-s-card"><h3 class="bta-s-h3">Job card</h3>'
      + (o.board
          ? (o.job_id ? '' : '<p class="bta-s-hint">Raise the card on the Schedule board, then find it here and link it. The status follows the card from then on.</p>')
            + linked
            + '<div class="bta-s-row"><input class="bta-s-field" id="bta-s-jobq" placeholder="Order #, customer or card #">'
            + '<button type="button" class="bta-s-btn ghost sm" data-act="jobsearch">Find</button></div>'
            + '<div id="bta-s-jobs" style="margin-top:8px"></div>'
          : '<p class="bta-s-hint">The Schedule board isn&rsquo;t installed, so there are no job cards to link.</p>')
      + '</div>';

    var hist = o.log.length
      ? '<div class="bta-s-card"><h3 class="bta-s-h3">History</h3><ul class="bta-s-log">' + o.log.map(function (l) {
          return '<li><strong>' + esc(l.status) + '</strong><small>' + esc(l.when) + (l.by ? ' &middot; ' + esc(l.by) : '') + '</small>'
            + (l.note ? '<small>' + esc(l.note) + '</small>' : '') + '</li>';
        }).join('') + '</ul></div>'
      : '';

    $('bta-s-detail').innerHTML =
        '<div class="bta-s-head"><button type="button" class="bta-s-back" data-act="back">&larr; All orders</button></div>'
      + '<div class="bta-s-head"><h2 class="bta-s-h2">' + esc(o.number) + ' <span>' + esc(o.account) + '</span></h2><div class="bta-s-grow"></div>'
      + '<a class="bta-s-btn pink" href="' + esc(o.print_url) + '" target="_blank" rel="noopener">Print work order</a></div>'
      + '<div class="bta-s-msg" id="bta-s-detailmsg"></div>'
      + '<div class="bta-s-grid"><div class="bta-s-main"><div class="bta-s-card">' + info + '</div>' + items + art + '</div>'
      + '<div class="bta-s-side">' + status + job + hist + '</div></div>';
  }

  function openOrder(id) {
    $('bta-s-list').style.display = 'none';
    $('bta-s-detail').style.display = '';
    $('bta-s-detail').innerHTML = '<div class="bta-s-empty">Loading&hellip;</div>';
    return api('/orders/' + id).then(function (d) {
      if (d.statuses) S.statuses = d.statuses;
      renderDetail(d.order);
      if (d.order.board && !d.order.job_id) suggestJobs(d.order);
    }).catch(function (e) {
      $('bta-s-detail').innerHTML = '<div class="bta-s-head"><button type="button" class="bta-s-back" data-act="back">&larr; All orders</button></div>'
        + '<div class="bta-s-msg bad">' + esc(e.message) + '</div>';
    });
  }

  /* ── Job cards ── */

  function renderJobs(jobs, searched) {
    var box = $('bta-s-jobs');
    if (!box) return;
    if (!jobs.length) {
      box.innerHTML = '<p class="bta-s-hint" style="margin:0">No card matches &ldquo;' + esc(searched) + '&rdquo; yet.</p>';
      return;
    }
    box.innerHTML = jobs.map(function (j) {
      var mine = S.current && S.current.job_id === j.id;
      return '<div class="bta-s-job"><div><strong>#' + j.id + '</strong> &middot; ' + esc(j.order_num || 'no order #') + ' &middot; ' + esc(j.customer)
        + '<small>' + esc(j.status) + (j.due ? ' &middot; due ' + esc(j.due) : '') + (j.qty ? ' &middot; ' + j.qty + ' pcs' : '') + (j.dept ? ' &middot; ' + esc(j.dept) : '') + '</small></div>'
        + (mine ? '<span class="bta-s-dim" style="font-size:13px">Linked</span>'
                : '<button type="button" class="bta-s-btn sm" data-link="' + j.id + '">Link</button>')
        + '</div>';
    }).join('');
  }

  function searchJobs(q) {
    var box = $('bta-s-jobs');
    if (!q) { if (box) box.innerHTML = ''; return Promise.resolve([]); }
    if (box) box.innerHTML = '<p class="bta-s-hint" style="margin:0">Searching&hellip;</p>';
    return api('/jobs', 'GET', null, 'q=' + encodeURIComponent(q)).then(function (d) {
      renderJobs(d.jobs || [], q);
      return d.jobs || [];
    }).catch(function (e) {
      if (box) box.innerHTML = '<p class="bta-s-hint bta-s-warn" style="margin:0">' + esc(e.message) + '</p>';
      return [];
    });
  }

  /* Most cards carry the order number; failing that, the end customer. */
  function suggestJobs(o) {
    var input = $('bta-s-jobq');
    if (!input) return;
    input.value = o.number;
    searchJobs(o.number).then(function (found) {
      if (!found.length && o.end_customer && S.current && S.current.id === o.id) {
        input.value = o.end_customer;
        searchJobs(o.end_customer);
      }
    });
  }

  function linkJob(jobId) {
    var o = S.current;
    if (!o) return;
    api('/orders/' + o.id + '/job', 'POST', { job_id: jobId }).then(function (d) {
      renderDetail(d.order);
      msg('bta-s-detailmsg', jobId ? 'Linked to job card #' + jobId + '. The status follows that card from now on.' : 'Job card unlinked.');
      if (!jobId && d.order.board) suggestJobs(d.order);
    }).catch(function (e) { msg('bta-s-detailmsg', e.message, 'bad'); });
  }

  function setStatus(btn) {
    var o = S.current;
    if (!o) return;
    var status = $('bta-s-status').value;
    var note = $('bta-s-note').value.trim();
    if (status === o.status && !note) { msg('bta-s-detailmsg', 'Nothing changed.', 'bad'); return; }
    btn.disabled = true;
    api('/orders/' + o.id + '/status', 'POST', { status: status, note: note }).then(function (d) {
      renderDetail(d.order);
      msg('bta-s-detailmsg', 'Status updated.');
    }).catch(function (e) {
      btn.disabled = false;
      msg('bta-s-detailmsg', e.message, 'bad');
    });
  }

  /* ── Events ── */

  root.addEventListener('click', function (e) {
    var t = e.target;
    var f = t.closest('[data-filter]');
    if (f) { S.filter = f.getAttribute('data-filter'); renderList(); return; }
    var row = t.closest('[data-open]');
    if (row) { openOrder(row.getAttribute('data-open')); return; }
    var lk = t.closest('[data-link]');
    if (lk) { lk.disabled = true; linkJob(parseInt(lk.getAttribute('data-link'), 10)); return; }
    var a = t.closest('[data-act]');
    if (!a) return;
    var act = a.getAttribute('data-act');
    if (act === 'refresh') loadList();
    if (act === 'back') showList();
    if (act === 'status') setStatus(a);
    if (act === 'unlink' && confirm('Unlink this job card? The order keeps its current status.')) linkJob(0);
    if (act === 'jobsearch') searchJobs($('bta-s-jobq').value.trim());
  });

  root.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target && e.target.id === 'bta-s-jobq') {
      e.preventDefault();
      searchJobs(e.target.value.trim());
    }
  });

  /* BT Portal calls this each time the tab is opened. Reopening it refreshes
     whatever was on screen rather than dropping back to the list. */
  window.btaStaffLoad = function () {
    if (S.current) return openOrder(S.current.id);
    return loadList();
  };
})();
</script>
</div>
    <?php
    return ob_get_clean();
}
