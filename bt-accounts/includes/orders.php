<?php
/**
 * BT Accounts — orders.
 *
 * An account order is a request, not a priced job. The customer supplies the
 * blanks and the art, so nothing here is costed: the shop reviews the art,
 * prices it, and raises the job card. Order status then mirrors that card.
 */
if (!defined('ABSPATH')) exit;

/**
 * Status vocabulary. These are BT Portal's job-card statuses verbatim, plus
 * 'Submitted' for the window before a card exists. Display labels are separate
 * so an account can be shown friendlier wording without changing the board.
 */
function bta_statuses() {
    return apply_filters('bta_statuses', array(
        'Submitted'                => 'Submitted',
        'Pending Approval'         => 'Pending Approval',
        'Approved/Items Ordered'   => 'Approved',
        'Ready for Production'     => 'In Production',
        'Complete/Notify Customer' => 'Complete',
        'On Hold'                  => 'On Hold',
    ));
}

/** What the account sees. Falls back to the raw status if unmapped. */
function bta_status_label($status) {
    $map = bta_statuses();
    return isset($map[$status]) ? $map[$status] : $status;
}

function bta_status_is_open($status) {
    return $status !== 'Complete/Notify Customer';
}

/* ── Order numbers ───────────────────────────────────────────────────────── */

function bta_account_prefix($account) {
    if (!empty($account->order_prefix)) return $account->order_prefix;
    $p = strtoupper(preg_replace('/[^a-z0-9]/i', '', $account->slug));
    return $p !== '' ? substr($p, 0, 3) : 'ACC';
}

/**
 * Next order number for an account, e.g. CIN-1004.
 *
 * Takes the higher of a stored high-water mark and the highest number actually
 * on file. The high-water mark is what stops a deleted order's number from
 * being handed out again — a customer may already be quoting CIN-1002 at their
 * end, so that number must never come back on a different job.
 */
function bta_next_order_number($account) {
    global $wpdb;
    $prefix = bta_account_prefix($account);
    $like   = $wpdb->esc_like($prefix . '-') . '%';

    $max = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT MAX(CAST(SUBSTRING(order_number, %d) AS UNSIGNED))
         FROM " . bta_table('orders') . " WHERE order_number LIKE %s",
        strlen($prefix) + 2, $like
    ));

    $opt  = 'bta_seq_' . strtolower($prefix);
    $mark = (int) get_option($opt, 0);
    $n    = max(1000, $max, $mark) + 1;

    // Belt and braces against a concurrent insert taking the same number.
    while ($wpdb->get_var($wpdb->prepare(
        "SELECT id FROM " . bta_table('orders') . " WHERE order_number = %s", $prefix . '-' . $n
    ))) { $n++; }

    return $prefix . '-' . $n;
}

/**
 * Record that a number has been used. Called only after a successful insert,
 * so merely asking for the next number never consumes one.
 */
function bta_mark_order_number_used($account, $number) {
    $prefix = bta_account_prefix($account);
    $n = (int) substr($number, strlen($prefix) + 1);
    $opt = 'bta_seq_' . strtolower($prefix);
    if ($n > (int) get_option($opt, 0)) update_option($opt, $n);
}

/* ── Reads ───────────────────────────────────────────────────────────────── */

function bta_get_order($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . bta_table('orders') . " WHERE id = %d", (int) $id));
}

/**
 * Orders for an account. $user_id restricts to one person's own orders — used
 * for portal users who are not account admins.
 */
function bta_get_orders($account_id, $user_id = 0, $limit = 200) {
    global $wpdb;
    $sql = "SELECT * FROM " . bta_table('orders') . " WHERE account_id = %d";
    $args = array((int) $account_id);
    if ($user_id) { $sql .= " AND user_id = %d"; $args[] = (int) $user_id; }
    $sql .= " ORDER BY created_at DESC, id DESC LIMIT %d";
    $args[] = (int) $limit;
    return $wpdb->get_results($wpdb->prepare($sql, $args));
}

function bta_get_all_orders($status = '', $limit = 300) {
    global $wpdb;
    $sql  = "SELECT * FROM " . bta_table('orders');
    $args = array();
    if ($status !== '') { $sql .= " WHERE status = %s"; $args[] = $status; }
    $sql .= " ORDER BY created_at DESC, id DESC LIMIT %d";
    $args[] = (int) $limit;
    return $wpdb->get_results($wpdb->prepare($sql, $args));
}

function bta_get_order_items($order_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM " . bta_table('order_items') . " WHERE order_id = %d ORDER BY sort_order ASC, id ASC",
        (int) $order_id
    ));
}

function bta_get_order_art($order_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM " . bta_table('order_art') . " WHERE order_id = %d ORDER BY id ASC",
        (int) $order_id
    ));
}

function bta_get_order_log($order_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM " . bta_table('order_log') . " WHERE order_id = %d ORDER BY changed_at ASC, id ASC",
        (int) $order_id
    ));
}

/** Decoded size breakdown: array of size => qty. */
function bta_item_sizes($item) {
    $s = json_decode((string) $item->sizes, true);
    return is_array($s) ? $s : array();
}

function bta_order_qty($order_id) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(qty),0) FROM " . bta_table('order_items') . " WHERE order_id = %d", (int) $order_id
    ));
}

/* ── Writes ──────────────────────────────────────────────────────────────── */

/**
 * Create an order from submitted data. $data is already-sanitised scalars;
 * $items is a list of line arrays; $art is a list of uploaded art records.
 * Returns the order id or WP_Error.
 */
function bta_create_order($account, $user, $data, $items, $art = array()) {
    global $wpdb;

    if (!$account || !$user) return new WP_Error('bta_no_ctx', 'Not signed in.');
    if (!$items) return new WP_Error('bta_no_items', 'Add at least one item to the order.');
    if (!empty($account->requires_po) && trim((string) $data['account_po']) === '') {
        return new WP_Error('bta_no_po', 'A PO number is required on every order.');
    }

    $now    = current_time('mysql');
    $number = bta_next_order_number($account);

    $ok = $wpdb->insert(bta_table('orders'), array(
        'account_id'       => (int) $account->id,
        'user_id'          => (int) $user->id,
        'order_number'     => $number,
        'end_customer'     => $data['end_customer'],
        'account_po'       => $data['account_po'],
        'supplier_name'    => $data['supplier_name'],
        'supplier_po'      => $data['supplier_po'],
        'expected_arrival' => $data['expected_arrival'] !== '' ? $data['expected_arrival'] : null,
        'in_hands_date'    => $data['in_hands_date'] !== '' ? $data['in_hands_date'] : null,
        'ship_name'        => $data['ship_name'],
        'ship_address1'    => $data['ship_address1'],
        'ship_address2'    => $data['ship_address2'],
        'ship_city'        => $data['ship_city'],
        'ship_state'       => $data['ship_state'],
        'ship_zip'         => $data['ship_zip'],
        'ship_method'      => $data['ship_method'],
        'notes'            => $data['notes'],
        'status'           => 'Submitted',
        'submitted_at'     => $now,
        'created_at'       => $now,
        'updated_at'       => $now,
    ));
    if (!$ok) return new WP_Error('bta_insert_failed', 'Could not save the order.');
    $order_id = (int) $wpdb->insert_id;
    bta_mark_order_number_used($account, $number);

    // Art first, so line items can reference it by label.
    $art_ids = array();
    foreach ($art as $a) {
        $wpdb->insert(bta_table('order_art'), array(
            'order_id'    => $order_id,
            'account_id'  => (int) $account->id,
            'label'       => $a['label'],
            'file_url'    => $a['url'],
            'file_name'   => $a['name'],
            'uploaded_at' => $now,
        ));
        $art_ids[$a['label']] = (int) $wpdb->insert_id;
    }

    $i = 0;
    foreach ($items as $it) {
        $wpdb->insert(bta_table('order_items'), array(
            'order_id'   => $order_id,
            'sort_order' => $i++,
            'catalog_id' => (int) $it['catalog_id'],
            'style_no'   => $it['style_no'],
            'style_name' => $it['style_name'],
            'brand'      => $it['brand'],
            'color'      => $it['color'],
            'sizes'      => wp_json_encode($it['sizes']),
            'qty'        => (int) $it['qty'],
            'decoration' => $it['decoration'],
            'placement'  => $it['placement'],
            'art_id'     => isset($art_ids[$it['art_label']]) ? $art_ids[$it['art_label']] : 0,
            'notes'      => $it['notes'],
        ));
    }

    bta_log_status($order_id, 'Submitted', 'Submitted through the portal', $user->display_name ? $user->display_name : $user->username);
    do_action('bta_order_submitted', $order_id);

    return $order_id;
}

function bta_log_status($order_id, $status, $note, $by) {
    global $wpdb;
    $wpdb->insert(bta_table('order_log'), array(
        'order_id'   => (int) $order_id,
        'status'     => $status,
        'note'       => substr((string) $note, 0, 255),
        'changed_by' => substr((string) $by, 0, 190),
        'changed_at' => current_time('mysql'),
    ));
}

function bta_set_order_status($order_id, $status, $by = '', $note = '') {
    global $wpdb;
    if (!array_key_exists($status, bta_statuses())) return new WP_Error('bta_bad_status', 'Unknown status.');
    $order = bta_get_order($order_id);
    if (!$order) return new WP_Error('bta_no_order', 'Order not found.');
    if ($order->status === $status && $note === '') return true;

    $wpdb->update(bta_table('orders'),
        array('status' => $status, 'updated_at' => current_time('mysql')),
        array('id' => (int) $order_id)
    );
    bta_log_status($order_id, $status, $note, $by);
    do_action('bta_order_status_changed', $order_id, $status, $order->status);
    return true;
}

function bta_link_job_card($order_id, $job_id) {
    global $wpdb;
    $wpdb->update(bta_table('orders'),
        array('job_id' => (int) $job_id, 'updated_at' => current_time('mysql')),
        array('id' => (int) $order_id)
    );
}

/**
 * Pull status from the linked BT Portal job card, so the board stays the single
 * source of truth. Safe no-op when BT Portal is inactive or nothing is linked.
 */
function bta_sync_status_from_job($order) {
    global $wpdb;
    if (!$order || empty($order->job_id)) return $order;
    $jobs = $wpdb->prefix . 'bt_jobs';
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $jobs)) !== $jobs) return $order;

    $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM $jobs WHERE id = %d", (int) $order->job_id));
    if ($status && $status !== $order->status && array_key_exists($status, bta_statuses())) {
        bta_set_order_status($order->id, $status, 'Job card', 'Synced from job card #' . (int) $order->job_id);
        $order->status = $status;
    }
    return $order;
}

/* ── Catalog lookup ──────────────────────────────────────────────────────── */

/**
 * Style autocomplete against BT Catalog. Returns [] when BT Catalog is absent,
 * so the order form degrades to free-text rather than breaking.
 */
function bta_catalog_search($term, $limit = 10) {
    global $wpdb;
    if (!function_exists('bt_cat_table')) return array();
    $t = bt_cat_table();
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t)) !== $t) return array();

    $term = trim((string) $term);
    if (strlen($term) < 2) return array();
    $like = '%' . $wpdb->esc_like($term) . '%';
    $starts = $wpdb->esc_like($term) . '%';

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, style_no, brand, name, sizes, colors
         FROM $t
         WHERE active = 1 AND (style_no LIKE %s OR name LIKE %s OR brand LIKE %s)
         ORDER BY (style_no LIKE %s) DESC, style_no ASC
         LIMIT %d",
        $like, $like, $like, $starts, (int) $limit
    ));
    if (!$rows) return array();

    $out = array();
    foreach ($rows as $r) {
        $colors = json_decode((string) $r->colors, true);
        $names  = array();
        if (is_array($colors)) {
            foreach ($colors as $k => $c) {
                if (is_array($c) && isset($c['name'])) $names[] = (string) $c['name'];
                elseif (is_string($k)) $names[] = $k;
            }
        }
        $sizes = array_values(array_filter(array_map('trim', explode(',', (string) $r->sizes))));
        $out[] = array(
            'id'     => (int) $r->id,
            'style'  => (string) $r->style_no,
            'brand'  => (string) $r->brand,
            'name'   => (string) $r->name,
            'label'  => trim($r->style_no . ' — ' . $r->brand . ' ' . $r->name),
            'colors' => array_values(array_unique($names)),
            'sizes'  => $sizes,
        );
    }
    return $out;
}

/* ── Art uploads ─────────────────────────────────────────────────────────── */

/** Extensions the shop can actually use. Anything else is rejected outright. */
function bta_allowed_art_types() {
    return array(
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'pdf'  => 'application/pdf',
        'ai'   => 'application/postscript',
        'eps'  => 'application/postscript',
        'psd'  => 'image/vnd.adobe.photoshop',
        'zip'  => 'application/zip',
    );
}

/**
 * Store one uploaded art file.
 *
 * Deliberately strict: the extension is checked against a whitelist before
 * anything touches disk, WordPress re-derives the type from the file itself,
 * and the destination directory carries an .htaccess that refuses to execute
 * whatever lands in it. An upload form that accepts art is the single easiest
 * way to hand someone a shell, so none of this is optional.
 */
function bta_handle_art_upload($file, $account_id) {
    if (empty($file['name']) || !isset($file['tmp_name']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return new WP_Error('bta_upload_error', 'Upload failed for ' . sanitize_file_name($file['name']) . '.');
    }

    $allowed = bta_allowed_art_types();
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) {
        return new WP_Error('bta_bad_type', sanitize_file_name($file['name']) . ' is not an accepted file type. Use ' . implode(', ', array_keys($allowed)) . '.');
    }
    if (!empty($file['size']) && $file['size'] > 40 * 1024 * 1024) {
        return new WP_Error('bta_too_big', sanitize_file_name($file['name']) . ' is larger than 40 MB.');
    }

    if (!function_exists('wp_handle_upload')) require_once ABSPATH . 'wp-admin/includes/file.php';

    $mimes = array();
    foreach ($allowed as $e => $m) $mimes[$e] = $m;

    add_filter('upload_dir', 'bta_art_upload_dir');
    $res = wp_handle_upload($file, array(
        'test_form' => false,
        'mimes'     => $mimes,   // WP re-checks the real type against this
    ));
    remove_filter('upload_dir', 'bta_art_upload_dir');

    if (isset($res['error'])) return new WP_Error('bta_upload_error', $res['error']);

    bta_protect_art_dir();

    return array(
        'url'  => $res['url'],
        'file' => $res['file'],
        'name' => sanitize_file_name($file['name']),
    );
}

function bta_art_upload_dir($dirs) {
    $sub = '/bt-accounts-art';
    $dirs['path']   = $dirs['basedir'] . $sub;
    $dirs['url']    = $dirs['baseurl'] . $sub;
    $dirs['subdir'] = $sub;
    return $dirs;
}

/** Drop an .htaccess in the art directory so nothing there can ever execute. */
function bta_protect_art_dir() {
    $up  = wp_upload_dir();
    $dir = $up['basedir'] . '/bt-accounts-art';
    if (!is_dir($dir)) wp_mkdir_p($dir);

    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        file_put_contents($ht, "php_flag engine off\n<FilesMatch \"\\.(php|php[0-9]|phtml|phar|cgi|pl|py|sh)$\">\n  Require all denied\n</FilesMatch>\n");
    }
    $idx = $dir . '/index.html';
    if (!file_exists($idx)) file_put_contents($idx, '');
}
