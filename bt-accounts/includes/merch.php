<?php
/**
 * BT Accounts — merch-store accounts.
 *
 * A merch account (Leonid & Friends first) is a band or brand whose shirts the
 * shop prints for its online store. Unlike a contract account it does not send
 * blanks: it picks from its own product list, each product already tied to the
 * artwork it carries, at prices the shop sets per product.
 *
 *   Bulk order       stock they order themselves, e.g. merch for a run of shows.
 *   On-demand order  one web-store customer's order, shipped to that customer.
 *
 * Orders land in the same orders table as everything else, so the queue, the
 * print sheet, emails and Printavo all read them unchanged. The art a line uses
 * is copied from the library into the order's own art rows for that reason.
 */
if (!defined('ABSPATH')) exit;

function bta_is_merch($account) {
    return $account && isset($account->kind) && $account->kind === 'merch';
}

function bta_account_kinds() {
    return array('contract' => 'Contract (they send blanks)', 'merch' => 'Merch store (bulk + on-demand)');
}

function bta_order_types() {
    return array('bulk' => 'Bulk', 'ondemand' => 'On demand');
}

function bta_order_type_label($type) {
    $t = bta_order_types();
    return isset($t[$type]) ? $t[$type] : '';
}

function bta_money($n) {
    $n = (float) $n;
    return ($n < 0 ? '-$' : '$') . number_format(abs($n), 2);
}

/** Read a money field: "$12.50" → 12.5; blank → null. */
function bta_parse_money($v) {
    $v = trim(str_replace(array('$', ','), '', (string) $v));
    if ($v === '' || !is_numeric($v)) return null;
    return round((float) $v, 2);
}

/* ── Products ────────────────────────────────────────────────────────────── */

function bta_get_products($account_id, $active_only = false) {
    global $wpdb;
    $sql = "SELECT * FROM " . bta_table('products') . " WHERE account_id = %d";
    if ($active_only) $sql .= " AND status = 'active'";
    return $wpdb->get_results($wpdb->prepare($sql . " ORDER BY sort_order ASC, name ASC, id ASC", (int) $account_id));
}

function bta_get_product($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . bta_table('products') . " WHERE id = %d", (int) $id));
}

/** Sizes a product comes in. Blank means the standard adult run. */
function bta_product_sizes($p) {
    $s = array_values(array_filter(array_map('trim', explode(',', (string) $p->sizes)), 'strlen'));
    return $s ? $s : array('S', 'M', 'L', 'XL', '2XL', '3XL');
}

function bta_product_colors($p) {
    return array_values(array_filter(array_map('trim', explode(',', (string) $p->colors)), 'strlen'));
}

function bta_product_channels() {
    return array('both' => 'Bulk + on demand', 'bulk' => 'Bulk only', 'ondemand' => 'On demand only');
}

/** Whether a product can go on a bulk or an on-demand order. */
function bta_product_in($p, $type) {
    return $p->channels === 'both' || $p->channels === $type;
}

/**
 * BT Catalog's photo of one colourway of a style, keyed by colour name
 * (lowercased). Its colors JSON holds [name, hex, img, swatch, …] per colour.
 * A style can sit under more than one supplier, so every row is read and the
 * first photo found for a colour wins.
 */
function bta_catalog_color_images($style_no) {
    static $cache = array();
    $style_no = trim((string) $style_no);
    if ($style_no === '' || !function_exists('bt_cat_table')) return array();
    if (isset($cache[$style_no])) return $cache[$style_no];

    global $wpdb;
    $t = bt_cat_table();
    $out = array();
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t)) === $t) {
        $rows = $wpdb->get_col($wpdb->prepare("SELECT colors FROM $t WHERE style_no = %s ORDER BY active DESC, id ASC", $style_no));
        foreach ((array) $rows as $json) {
            $cols = json_decode((string) $json, true);
            if (!is_array($cols)) continue;
            foreach ($cols as $k => $c) {
                if (!is_array($c) || empty($c['img'])) continue;
                $name = strtolower(trim(isset($c['name']) ? (string) $c['name'] : (string) $k));
                if ($name !== '' && !isset($out[$name])) $out[$name] = (string) $c['img'];
            }
        }
    }
    return $cache[$style_no] = $out;
}

/** Catalogue photo for one colour of a product, or ''. */
function bta_product_color_image($p, $color) {
    $imgs = bta_catalog_color_images($p->style_no);
    $c = strtolower(trim((string) $color));
    if ($c === '' || !$imgs) return '';
    if (isset($imgs[$c])) return $imgs[$c];
    foreach ($imgs as $name => $url) if (strpos($name, $c) === 0) return $url;   // "black" → "black heather" last resort
    return '';
}

/**
 * The product's picture: its own image URL, else the catalogue photo of the
 * black colourway, else of its first colour that has one.
 */
function bta_product_image($p) {
    if (!empty($p->image_url)) return (string) $p->image_url;
    $try = array_merge(array('Black'), bta_product_colors($p));
    foreach ($try as $c) {
        $u = bta_product_color_image($p, $c);
        if ($u !== '') return $u;
    }
    $imgs = bta_catalog_color_images($p->style_no);
    return $imgs ? (string) reset($imgs) : '';
}

/* ── Print locations + mockups ───────────────────────────────────────────
 *
 * Borrowed from PresStora (includes/zones.php): a print location is a box on
 * the product photo, x / y / w / h as percentages of the image, and the art is
 * contain-fitted and centred inside it. Same location keys as PresStora.
 * Every product starts from the defaults below; the shop drags the boxes to
 * fit each photo in wp-admin, which stores that product's own set.
 */

/** Location key => label. */
function bta_location_catalog() {
    return array(
        'full_front'  => 'Full Front',
        'left_chest'  => 'Left Chest',
        'right_chest' => 'Right Chest',
        'hat'         => 'Hat Front',
        'full_back'   => 'Full Back',
        'upper_back'  => 'Upper Back / Yoke',
        'left_sleeve' => 'Left Sleeve',
        'right_sleeve'=> 'Right Sleeve',
    );
}

/** "Left Chest", "left-chest", "Hat Front" → PresStora location key, or ''. */
function bta_location_key($label) {
    $k = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $label)), '_');
    $alias = array('hat_front' => 'hat', 'cap' => 'hat', 'cap_front' => 'hat', 'front' => 'full_front',
                   'center_chest' => 'full_front', 'back' => 'full_back', 'upper_back_yoke' => 'upper_back', 'yoke' => 'upper_back');
    if (isset($alias[$k])) $k = $alias[$k];
    return array_key_exists($k, bta_location_catalog()) ? $k : '';
}

/**
 * Starting boxes, as a share of a front flat-lay photo. Full front is
 * PresStora's own default (25 / 20 / 50 / 55). Left chest is the wearer's
 * left, so it sits on the right of the photo. Back and sleeve locations have
 * no box because only the front photo is shown.
 */
function bta_default_zones($p) {
    if (bta_product_is_hat($p)) {
        return array('hat' => array('x' => 33, 'y' => 26, 'w' => 34, 'h' => 26));
    }
    return array(
        'full_front'  => array('x' => 25, 'y' => 20, 'w' => 50, 'h' => 55),
        'left_chest'  => array('x' => 55, 'y' => 23, 'w' => 15, 'h' => 15),
        'right_chest' => array('x' => 30, 'y' => 23, 'w' => 15, 'h' => 15),
    );
}

/**
 * A product's boxes. The editor saves the whole set, so a saved set replaces
 * the defaults (a box the shop deleted stays deleted); none saved = defaults.
 */
function bta_product_zones($p) {
    $ov = isset($p->zones) ? json_decode((string) $p->zones, true) : null;
    if (!is_array($ov) || !$ov) return bta_default_zones($p);
    $z = array();
    foreach ($ov as $k => $b) {
        if (is_array($b) && array_key_exists($k, bta_location_catalog())) $z[$k] = bta_clean_zone($b);
    }
    return $z;
}

function bta_clean_zone($b) {
    $out = array();
    foreach (array('x', 'y', 'w', 'h') as $k) {
        $out[$k] = round(min(100, max(0, isset($b[$k]) ? (float) $b[$k] : 0)), 2);
    }
    if ($out['w'] < 1) $out['w'] = 1;
    if ($out['h'] < 1) $out['h'] = 1;
    return $out;
}

/** Art ids a product is printed with. Empty means any art in the library. */
function bta_product_art_ids($p) {
    return array_values(array_filter(array_map('intval', explode(',', (string) $p->art_ids))));
}

/** 2XL and up carry the product's upcharge. */
function bta_is_upcharge_size($size) {
    $size = strtoupper(trim((string) $size));
    if (preg_match('/^(\d)XL$/', $size, $m)) return (int) $m[1] >= 2;
    return (bool) preg_match('/^XX+L$/', $size);
}

/** Price of one piece of a product in one size, or null if the shop has not priced it. */
function bta_product_price($p, $type, $size) {
    $base = $type === 'ondemand' ? $p->ondemand_price : $p->bulk_price;
    if ($base === null || $base === '') return null;
    $price = (float) $base;
    if (bta_is_upcharge_size($size)) $price += (float) $p->upcharge;
    return round($price, 2);
}

function bta_save_product($account_id, $args, $id = 0) {
    global $wpdb;
    $name = trim(wp_strip_all_tags(isset($args['name']) ? $args['name'] : ''));
    if ($name === '') return new WP_Error('bta_no_name', 'Give the product a name.');

    $dec = isset($args['decoration']) ? sanitize_key($args['decoration']) : 'print';
    if (!array_key_exists($dec, bta_decorations())) $dec = 'print';

    $art = array();
    foreach ((array) (isset($args['art_ids']) ? $args['art_ids'] : array()) as $a) if ((int) $a) $art[] = (int) $a;

    $sizes = array_filter(array_map(function ($s) { return strtoupper(sanitize_text_field($s)); },
        explode(',', isset($args['sizes']) ? (string) $args['sizes'] : '')), 'strlen');

    $row = array(
        'account_id'     => (int) $account_id,
        'name'           => $name,
        'store_ref'      => sanitize_text_field(isset($args['store_ref']) ? $args['store_ref'] : ''),
        'style_no'       => sanitize_text_field(isset($args['style_no']) ? $args['style_no'] : ''),
        'brand'          => sanitize_text_field(isset($args['brand']) ? $args['brand'] : ''),
        'colors'         => substr(implode(',', array_filter(array_map('trim', explode(',', sanitize_text_field(isset($args['colors']) ? $args['colors'] : ''))), 'strlen')), 0, 255),
        'sizes'          => substr(implode(',', array_map('trim', $sizes)), 0, 255),
        'channels'       => (isset($args['channels']) && array_key_exists($args['channels'], bta_product_channels())) ? $args['channels'] : 'both',
        'image_url'      => esc_url_raw(isset($args['image_url']) ? $args['image_url'] : ''),
        'decoration'     => $dec,
        'placement'      => sanitize_text_field(isset($args['placement']) ? $args['placement'] : ''),
        'art_ids'        => implode(',', array_unique($art)),
        'bulk_price'     => bta_parse_money(isset($args['bulk_price']) ? $args['bulk_price'] : ''),
        'ondemand_price' => bta_parse_money(isset($args['ondemand_price']) ? $args['ondemand_price'] : ''),
        'upcharge'       => (float) bta_parse_money(isset($args['upcharge']) ? $args['upcharge'] : ''),
        'notes'          => sanitize_textarea_field(isset($args['notes']) ? $args['notes'] : ''),
        'sort_order'     => isset($args['sort_order']) ? (int) $args['sort_order'] : 0,
        'status'         => (isset($args['status']) && $args['status'] === 'hidden') ? 'hidden' : 'active',
    );
    if (isset($args['zones'])) {
        $zin = is_array($args['zones']) ? $args['zones'] : json_decode((string) $args['zones'], true);
        $zs  = array();
        if (is_array($zin)) foreach ($zin as $k => $b) if (is_array($b) && array_key_exists($k, bta_location_catalog())) $zs[$k] = bta_clean_zone($b);
        $row['zones'] = $zs ? wp_json_encode($zs) : '';
    }

    if ($id) {
        $p = bta_get_product($id);
        if (!$p || (int) $p->account_id !== (int) $account_id) return new WP_Error('bta_no_product', 'Product not found.');
        $wpdb->update(bta_table('products'), $row, array('id' => (int) $id));
        return (int) $id;
    }
    $row['created_at'] = current_time('mysql');
    if (!$wpdb->insert(bta_table('products'), $row)) return new WP_Error('bta_insert_failed', 'Could not save the product.');
    return (int) $wpdb->insert_id;
}

function bta_delete_product($id) {
    global $wpdb;
    return (bool) $wpdb->delete(bta_table('products'), array('id' => (int) $id));
}

/**
 * Paste-in product list, one per line, comma or tab separated:
 * name, colours (Black/White), sizes (S-3XL or S/M/L), bulk price, on-demand price, 2XL+ add, image URL.
 * Built for copying a store's item list across quickly. Returns count added.
 */
function bta_import_products($account_id, $text) {
    $n = 0;
    foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
        if (trim($line) === '') continue;
        $c = array_map('trim', strpos($line, "\t") !== false ? explode("\t", $line) : str_getcsv($line));
        if ($c[0] === '' || strcasecmp($c[0], 'name') === 0) continue;
        $sizes = isset($c[2]) ? bta_expand_sizes($c[2]) : '';
        $r = bta_save_product($account_id, array(
            'name'           => $c[0],
            'colors'         => isset($c[1]) ? str_replace(array('/', '|', ';'), ',', $c[1]) : '',
            'sizes'          => $sizes,
            'bulk_price'     => isset($c[3]) ? $c[3] : '',
            'ondemand_price' => isset($c[4]) ? $c[4] : '',
            'upcharge'       => isset($c[5]) ? $c[5] : '',
            'image_url'      => isset($c[6]) ? $c[6] : '',
        ));
        if (!is_wp_error($r)) $n++;
    }
    return $n;
}

/** "S-3XL" → "S,M,L,XL,2XL,3XL"; "S/M/L" → "S,M,L". */
function bta_expand_sizes($s) {
    $s = strtoupper(trim((string) $s));
    $run = array('XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL');
    if (preg_match('/^([0-9]?X?[SML]|[0-9]XL)\s*-\s*([0-9]?X?[SML]|[0-9]XL)$/', $s, $m)) {
        $a = array_search($m[1], $run, true);
        $b = array_search($m[2], $run, true);
        if ($a !== false && $b !== false && $a <= $b) return implode(',', array_slice($run, $a, $b - $a + 1));
    }
    return implode(',', array_filter(array_map('trim', preg_split('/[\/|;, ]+/', $s)), 'strlen'));
}

/* ── Art library ─────────────────────────────────────────────────────────── */

function bta_get_library($account_id, $active_only = false) {
    global $wpdb;
    $sql = "SELECT * FROM " . bta_table('art_library') . " WHERE account_id = %d";
    if ($active_only) $sql .= " AND status = 'active'";
    return $wpdb->get_results($wpdb->prepare($sql . " ORDER BY name ASC, id ASC", (int) $account_id));
}

function bta_get_library_art($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . bta_table('art_library') . " WHERE id = %d", (int) $id));
}

/** Library rows keyed by id. */
function bta_library_by_id($account_id) {
    $out = array();
    foreach (bta_get_library($account_id) as $a) $out[(int) $a->id] = $a;
    return $out;
}

/** An image the browser can show for this art, or '' for files like .ai and .pdf. */
function bta_art_preview($a) {
    if (!empty($a->preview_url)) return (string) $a->preview_url;
    $ext = strtolower(pathinfo(wp_parse_url((string) $a->file_url, PHP_URL_PATH), PATHINFO_EXTENSION));
    return in_array($ext, array('png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'), true) ? (string) $a->file_url : '';
}

function bta_save_library_art($account_id, $args, $id = 0) {
    global $wpdb;
    $name = trim(wp_strip_all_tags(isset($args['name']) ? $args['name'] : ''));
    if ($name === '') return new WP_Error('bta_no_name', 'Give the artwork a name.');

    $row = array(
        'account_id'  => (int) $account_id,
        'name'        => $name,
        'placement'   => sanitize_text_field(isset($args['placement']) ? $args['placement'] : ''),
        'colors'      => sanitize_text_field(isset($args['colors']) ? $args['colors'] : ''),
        'notes'       => sanitize_textarea_field(isset($args['notes']) ? $args['notes'] : ''),
        'preview_url' => esc_url_raw(isset($args['preview_url']) ? $args['preview_url'] : ''),
        'status'      => (isset($args['status']) && $args['status'] === 'archived') ? 'archived' : 'active',
    );
    if (isset($args['variants']) && is_array($args['variants'])) {
        $vs = array();
        foreach ($args['variants'] as $v) {
            if (!is_array($v)) continue;
            $colors = array_values(array_filter(array_map('trim', explode(',', sanitize_text_field(isset($v['colors']) ? $v['colors'] : ''))), 'strlen'));
            if (!$colors) continue;
            $vs[] = array(
                'label'       => sanitize_text_field(isset($v['label']) ? $v['label'] : ''),
                'colors'      => $colors,
                'file_url'    => esc_url_raw(isset($v['file_url']) ? $v['file_url'] : ''),
                'preview_url' => esc_url_raw(isset($v['preview_url']) ? $v['preview_url'] : ''),
                'note'        => sanitize_text_field(isset($v['note']) ? $v['note'] : ''),
            );
        }
        $row['variants'] = $vs ? wp_json_encode($vs) : '';
    }
    if (isset($args['file_url']) && $args['file_url'] !== '') {
        $row['file_url']  = esc_url_raw($args['file_url']);
        $row['file_name'] = sanitize_file_name(isset($args['file_name']) && $args['file_name'] !== ''
            ? $args['file_name'] : basename((string) wp_parse_url($args['file_url'], PHP_URL_PATH)));
    }

    if ($id) {
        $a = bta_get_library_art($id);
        if (!$a || (int) $a->account_id !== (int) $account_id) return new WP_Error('bta_no_art', 'Artwork not found.');
        $wpdb->update(bta_table('art_library'), $row, array('id' => (int) $id));
        return (int) $id;
    }
    if (empty($row['file_url'])) return new WP_Error('bta_no_file', 'Add the art file.');
    $row['added_by']   = isset($args['added_by']) && $args['added_by'] === 'account' ? 'account' : 'shop';
    $row['created_at'] = current_time('mysql');
    if (!$wpdb->insert(bta_table('art_library'), $row)) return new WP_Error('bta_insert_failed', 'Could not save the artwork.');
    return (int) $wpdb->insert_id;
}

function bta_delete_library_art($id) {
    global $wpdb;
    return (bool) $wpdb->delete(bta_table('art_library'), array('id' => (int) $id));
}

/**
 * Colour versions of a design: a list of label / garment colours / file / preview.
 * A design printed differently on different garment colours (Bottle Cap: full
 * colour on black caps, blue and yellow on red, yellow and red on khaki) keeps
 * each version here; the row's own file is the default for any other colour.
 */
function bta_art_versions($a) {
    $v = isset($a->variants) ? json_decode((string) $a->variants, true) : null;
    if (!is_array($v)) return array();
    $out = array();
    foreach ($v as $x) {
        if (!is_array($x)) continue;
        $out[] = array(
            'label'       => isset($x['label']) ? (string) $x['label'] : '',
            'colors'      => array_values(array_filter(array_map('trim', (array) (isset($x['colors']) ? $x['colors'] : array())), 'strlen')),
            'file_url'    => isset($x['file_url']) ? (string) $x['file_url'] : '',
            'preview_url' => isset($x['preview_url']) ? (string) $x['preview_url'] : '',
            'note'        => isset($x['note']) ? (string) $x['note'] : '',
        );
    }
    return $out;
}

/** The version of a design for one garment colour: label, file_url, file_name, preview. */
function bta_art_version($a, $color) {
    $c = strtolower(trim((string) $color));
    foreach (bta_art_versions($a) as $v) {
        foreach ($v['colors'] as $vc) {
            if (strtolower($vc) === $c) {
                return array(
                    'label'     => $v['label'] !== '' ? $v['label'] : $vc,
                    'file_url'  => $v['file_url'],
                    'file_name' => $v['file_url'] !== '' ? basename((string) wp_parse_url($v['file_url'], PHP_URL_PATH)) : '',
                    'preview'   => $v['preview_url'] !== '' ? $v['preview_url'] : ($v['file_url'] !== '' && preg_match('/\.(png|jpe?g|gif|svg|webp)$/i', $v['file_url']) ? $v['file_url'] : ''),
                    'note'      => $v['note'],
                );
            }
        }
    }
    return array('label' => '', 'file_url' => (string) $a->file_url, 'file_name' => (string) $a->file_name, 'preview' => bta_art_preview($a), 'note' => '');
}

/**
 * Whether a design can go on this item at all, by its placements: a cap needs
 * a hat placement, a shirt needs one that isn't. No placement set = anywhere.
 */
function bta_art_fits($p, $a) {
    $places = array_filter(array_map('trim', explode(',', (string) $a->placement)), 'strlen');
    if (!$places) return true;
    $hat = bta_product_is_hat($p);
    foreach ($places as $pl) if ((stripos($pl, 'hat') !== false) === $hat) return true;
    return false;
}

/** Whether a product goes on the head rather than the body. */
function bta_product_is_hat($p) {
    return (bool) preg_match('/\b(hat|cap|beanie|visor)\b/i', $p->name . ' ' . $p->placement);
}

/**
 * Where the design goes on this item. A design can list several placements
 * ("Hat Front, Left Chest"): a hat takes the hat one, a shirt the first other.
 */
function bta_line_placement($p, $art) {
    $places = $art ? array_values(array_filter(array_map('trim', explode(',', (string) $art->placement)), 'strlen')) : array();
    if ($places) {
        $hat = bta_product_is_hat($p);
        foreach ($places as $pl) if ((stripos($pl, 'hat') !== false) === $hat) return $pl;
        if (!$hat) return $p->placement !== '' ? $p->placement : 'Full Front';
        return $places[0];
    }
    return $p->placement !== '' ? $p->placement : 'Full Front';
}

/** The art choices for one product: its own list, or the whole active library. */
function bta_product_art_choices($p, $library) {
    $ids = bta_product_art_ids($p);
    $out = array();
    foreach ($library as $id => $a) {
        if ($a->status !== 'active') continue;
        if ($ids && !in_array((int) $id, $ids, true)) continue;
        if (!bta_art_fits($p, $a)) continue;
        $out[(int) $id] = $a;
    }
    return $out;
}

/* ── Merch orders ────────────────────────────────────────────────────────── */

/**
 * Create a bulk or on-demand order. $lines is a list of
 * array(product, color, art_id, sizes => array(size => qty)), already checked against
 * the account. Prices come from the product, never from the form.
 */
function bta_create_merch_order($account, $user, $type, $data, $lines) {
    global $wpdb;
    if (!$account || !$user) return new WP_Error('bta_no_ctx', 'Not signed in.');
    if (!$lines) return new WP_Error('bta_no_items', 'Add at least one item.');

    $now    = current_time('mysql');
    $number = bta_next_order_number($account);
    $end    = $type === 'ondemand' ? $data['ship_name'] : ($data['event_name'] !== '' ? $data['event_name'] : $account->name);

    $ok = $wpdb->insert(bta_table('orders'), array(
        'account_id'    => (int) $account->id,
        'user_id'       => (int) $user->id,
        'order_number'  => $number,
        'order_type'    => $type,
        'end_customer'  => substr($end, 0, 190),
        'account_po'    => $data['account_po'],
        'external_ref'  => $data['external_ref'],
        'event_name'    => $data['event_name'],
        'in_hands_date' => $data['in_hands_date'] !== '' ? $data['in_hands_date'] : null,
        'ship_name'     => $data['ship_name'],
        'ship_address1' => $data['ship_address1'],
        'ship_address2' => $data['ship_address2'],
        'ship_city'     => $data['ship_city'],
        'ship_state'    => $data['ship_state'],
        'ship_zip'      => $data['ship_zip'],
        'ship_method'   => $data['ship_method'],
        'ship_email'    => $data['ship_email'],
        'ship_phone'    => $data['ship_phone'],
        'notes'         => $data['notes'],
        'status'        => 'Submitted',
        'submitted_at'  => $now,
        'created_at'    => $now,
        'updated_at'    => $now,
    ));
    if (!$ok) return new WP_Error('bta_insert_failed', 'Could not save the order.');
    $order_id = (int) $wpdb->insert_id;
    bta_mark_order_number_used($account, $number);

    // Copy each library file used into the order's own art, once per file.
    $copied = array();
    $sub    = 0.0;
    $i      = 0;
    foreach ($lines as $ln) {
        $p   = $ln['product'];
        $art = $ln['art_id'] ? bta_get_library_art($ln['art_id']) : null;
        $oid = 0;
        if ($art) {
            // The version of the design that goes on this garment colour.
            $ver = bta_art_version($art, $ln['color']);
            $key = (int) $art->id . '|' . $ver['label'];
            if (!isset($copied[$key])) {
                $wpdb->insert(bta_table('order_art'), array(
                    'order_id'    => $order_id,
                    'account_id'  => (int) $account->id,
                    'label'       => $ver['label'] !== '' ? $art->name . ' (version ' . $ver['label'] . ')' : $art->name,
                    'file_url'    => $ver['file_url'],
                    'file_name'   => $ver['file_name'] !== '' ? $ver['file_name'] : 'file to come from the shop',
                    'uploaded_at' => $now,
                ));
                $copied[$key] = (int) $wpdb->insert_id;
            }
            $oid = $copied[$key];
        }

        $qty = 0; $line_total = 0.0; $priced = true;
        foreach ($ln['sizes'] as $sz => $q) {
            $qty += $q;
            $each = bta_product_price($p, $type, $sz);
            if ($each === null) $priced = false; else $line_total += $each * $q;
        }
        $base = $type === 'ondemand' ? $p->ondemand_price : $p->bulk_price;
        $note = !$priced ? 'Priced by the shop.' : ((float) $p->upcharge > 0 ? '2XL and up +' . bta_money($p->upcharge) . ' each' : '');

        $place = bta_line_placement($p, $art);
        $locs  = array(array('placement' => $place, 'art_id' => $oid, 'emb' => $p->decoration === 'embroidery' ? 'logo' : ''));

        $row = array(
            'order_id'   => $order_id,
            'sort_order' => $i++,
            'product_id' => (int) $p->id,
            'style_no'   => $p->style_no !== '' ? $p->style_no : $p->name,
            'style_name' => $p->name,
            'brand'      => $p->brand,
            'color'      => $ln['color'],
            'sizes'      => wp_json_encode($ln['sizes']),
            'qty'        => $qty,
            'decoration' => $p->decoration,
            'placement'  => substr(bta_locations_text($locs, $p->decoration), 0, 255),
            'art_id'     => $oid,
            'locations'  => wp_json_encode($locs),
            'price_note' => $note,
            'notes'      => '',
        );
        if ($priced && $base !== null) {
            $row['unit_price'] = (float) $base;
            $row['line_total'] = round($line_total, 2);
            $sub += $line_total;
        }
        $wpdb->insert(bta_table('order_items'), $row);
    }

    $wpdb->update(bta_table('orders'), array('subtotal' => round($sub, 2)), array('id' => $order_id));

    bta_log_status($order_id, 'Submitted', bta_order_type_label($type) . ' order submitted through the portal',
        $user->display_name ? $user->display_name : $user->username);
    do_action('bta_order_submitted', $order_id);
    return $order_id;
}

/* ── Money on an order ───────────────────────────────────────────────────── */

function bta_order_total($o) {
    return round((float) $o->subtotal + (float) $o->shipping + (float) $o->adjustment, 2);
}

function bta_order_balance($o) {
    return round(bta_order_total($o) - (float) $o->amount_paid, 2);
}

/** 'paid', 'part', 'unpaid' or '' when there is nothing to pay yet. */
function bta_pay_state($o) {
    $total = bta_order_total($o);
    if ($total <= 0) return '';
    if (bta_order_balance($o) <= 0) return 'paid';
    return (float) $o->amount_paid > 0 ? 'part' : 'unpaid';
}

function bta_pay_pill($o) {
    $s = bta_pay_state($o);
    if ($s === '') return '<span class="bta-sub">&mdash;</span>';
    $label = array('paid' => 'Paid', 'part' => 'Part paid', 'unpaid' => 'Unpaid');
    return '<span class="bta-pill bta-pill-pay-' . esc_attr($s) . '">' . esc_html($label[$s]) . '</span>';
}

function bta_get_payments($order_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM " . bta_table('payments') . " WHERE order_id = %d ORDER BY paid_at ASC, id ASC", (int) $order_id
    ));
}

function bta_get_account_payments($account_id, $limit = 200) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM " . bta_table('payments') . " WHERE account_id = %d ORDER BY paid_at DESC, id DESC LIMIT %d",
        (int) $account_id, (int) $limit
    ));
}

/** Recompute amount_paid from the payments on file. */
function bta_refresh_paid($order_id) {
    global $wpdb;
    $sum = (float) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(amount),0) FROM " . bta_table('payments') . " WHERE order_id = %d", (int) $order_id
    ));
    $wpdb->update(bta_table('orders'), array('amount_paid' => round($sum, 2), 'updated_at' => current_time('mysql')), array('id' => (int) $order_id));
}

/**
 * Record a payment. $stripe_session makes a card payment idempotent: the same
 * Checkout session can never be counted twice, however often the return page loads.
 */
function bta_record_payment($order_id, $amount, $method, $reference, $note, $by, $stripe_session = '') {
    global $wpdb;
    $o = bta_get_order($order_id);
    if (!$o) return new WP_Error('bta_no_order', 'Order not found.');
    $amount = round((float) $amount, 2);
    if ($amount == 0) return new WP_Error('bta_bad_amount', 'Enter an amount.');

    $row = array(
        'order_id'    => (int) $o->id,
        'account_id'  => (int) $o->account_id,
        'amount'      => $amount,
        'method'      => substr(sanitize_text_field($method), 0, 60),
        'reference'   => substr(sanitize_text_field($reference), 0, 190),
        'note'        => substr(sanitize_text_field($note), 0, 255),
        'recorded_by' => substr((string) $by, 0, 190),
        'paid_at'     => current_time('mysql'),
    );
    if ($stripe_session !== '') {
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM " . bta_table('payments') . " WHERE stripe_session = %s", $stripe_session))) {
            return 0;
        }
        $row['stripe_session'] = $stripe_session;
    }
    if (!$wpdb->insert(bta_table('payments'), $row)) {
        // The unique key on stripe_session lost a race to a second load: already counted.
        return $stripe_session !== '' ? 0 : new WP_Error('bta_insert_failed', 'Could not record the payment.');
    }
    $pid = (int) $wpdb->insert_id;
    bta_refresh_paid($o->id);

    $what = $amount < 0 ? 'Refund of ' . bta_money(-$amount) : 'Payment of ' . bta_money($amount) . ' received';
    bta_log_status($o->id, $o->status, $what . ($row['method'] !== '' ? ' (' . $row['method'] . ')' : ''), $by);
    do_action('bta_payment_recorded', $o->id, $pid, $amount);
    return $pid;
}

function bta_delete_payment($payment_id) {
    global $wpdb;
    $p = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . bta_table('payments') . " WHERE id = %d", (int) $payment_id));
    if (!$p) return false;
    $wpdb->delete(bta_table('payments'), array('id' => (int) $p->id));
    bta_refresh_paid($p->order_id);
    return true;
}

function bta_set_order_charges($order_id, $shipping, $adjustment) {
    global $wpdb;
    $wpdb->update(bta_table('orders'), array(
        'shipping'   => round((float) $shipping, 2),
        'adjustment' => round((float) $adjustment, 2),
        'updated_at' => current_time('mysql'),
    ), array('id' => (int) $order_id));
}

/** Re-add the line totals, after the shop changes a line price. */
function bta_refresh_subtotal($order_id) {
    global $wpdb;
    $sum = (float) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(line_total),0) FROM " . bta_table('order_items') . " WHERE order_id = %d", (int) $order_id
    ));
    $wpdb->update(bta_table('orders'), array('subtotal' => round($sum, 2)), array('id' => (int) $order_id));
}

/* ── Card payments (Stripe Checkout) ─────────────────────────────────────── */

function bta_stripe_key() {
    return trim((string) get_option('bta_stripe_secret', ''));
}

function bta_stripe_on() {
    return strpos(bta_stripe_key(), 'sk_') === 0 || strpos(bta_stripe_key(), 'rk_') === 0;
}

function bta_pay_instructions() {
    return trim((string) get_option('bta_pay_instructions', ''));
}

function bta_stripe_call($method, $path, $body = array()) {
    $args = array(
        'method'  => $method,
        'timeout' => 25,
        'headers' => array('Authorization' => 'Bearer ' . bta_stripe_key()),
    );
    if ($body) $args['body'] = $body;
    $res = wp_remote_request('https://api.stripe.com/v1/' . ltrim($path, '/'), $args);
    if (is_wp_error($res)) return $res;
    $data = json_decode(wp_remote_retrieve_body($res), true);
    if (!is_array($data)) return new WP_Error('bta_stripe', 'Stripe sent back something unreadable.');
    if (!empty($data['error'])) {
        return new WP_Error('bta_stripe', isset($data['error']['message']) ? $data['error']['message'] : 'Stripe refused the request.');
    }
    return $data;
}

/** Start a Checkout session for an order's balance. Returns the Stripe URL. */
function bta_stripe_checkout($order, $account) {
    $due = bta_order_balance($order);
    if ($due <= 0) return new WP_Error('bta_nothing_due', 'Nothing is owing on this order.');

    $back = bta_portal_url('order/' . (int) $order->id);
    $s = bta_stripe_call('POST', 'checkout/sessions', array(
        'mode'                 => 'payment',
        'client_reference_id'  => (string) $order->id,
        'success_url'          => $back . '?paid={CHECKOUT_SESSION_ID}',
        'cancel_url'           => $back . '?paycancel=1',
        'metadata[bta_order]'  => (string) $order->id,
        'metadata[order_number]' => $order->order_number,
        'payment_intent_data[description]' => $order->order_number . ' · ' . $account->name,
        'line_items[0][quantity]' => 1,
        'line_items[0][price_data][currency]' => 'usd',
        'line_items[0][price_data][unit_amount]' => (int) round($due * 100),
        'line_items[0][price_data][product_data][name]' => 'Boomer T\'s order ' . $order->order_number,
    ));
    if (is_wp_error($s)) return $s;
    return isset($s['url']) ? $s['url'] : new WP_Error('bta_stripe', 'Stripe did not return a payment page.');
}

/**
 * Back from Stripe with ?paid=cs_…: ask Stripe whether it was paid, and record
 * it once. Never trusts the URL alone. Returns a message for the page, or ''.
 */
function bta_stripe_confirm($order, $session_id) {
    if (!bta_stripe_on() || !preg_match('/^cs_[A-Za-z0-9_]+$/', $session_id)) return '';
    $s = bta_stripe_call('GET', 'checkout/sessions/' . rawurlencode($session_id));
    if (is_wp_error($s)) return 'We could not confirm the card payment with Stripe yet (' . $s->get_error_message() . '). If you were charged, the shop will see it and mark it paid.';
    if (!isset($s['metadata']['bta_order']) || (string) $s['metadata']['bta_order'] !== (string) $order->id) return '';
    if (!isset($s['payment_status']) || $s['payment_status'] !== 'paid') return 'The card payment has not gone through yet.';

    $amount = isset($s['amount_total']) ? ((int) $s['amount_total']) / 100 : 0;
    $pi     = isset($s['payment_intent']) && is_string($s['payment_intent']) ? $s['payment_intent'] : '';
    $r = bta_record_payment($order->id, $amount, 'Card (Stripe)', $pi, '', 'Stripe', $session_id);
    if (is_wp_error($r)) return $r->get_error_message();
    return 'Thank you. Your payment of ' . bta_money($amount) . ' went through.';
}

/** Let the shop know when money comes in through the portal. */
add_action('bta_payment_recorded', 'bta_notify_payment', 10, 3);
function bta_notify_payment($order_id, $payment_id, $amount) {
    global $wpdb;
    $p = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . bta_table('payments') . " WHERE id = %d", (int) $payment_id));
    if (!$p || empty($p->stripe_session)) return;   // the shop already knows about payments it entered
    $o = bta_get_order($order_id);
    $to = bta_notify_recipients();
    if (!$o || !$to) return;
    $acct = bta_get_account($o->account_id);
    $rows = bta_mail_row('Order', $o->order_number) . bta_mail_row('Amount', bta_money($amount))
          . bta_mail_row('Balance now', bta_money(bta_order_balance($o))) . bta_mail_row('Stripe', $p->reference);
    $body = bta_mail_wrap('Card payment on ' . $o->order_number, $acct, '#27267e',
        '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:0 0 20px">' . $rows . '</table>');
    wp_mail($to, sprintf('[%s] Paid %s on %s', $acct ? $acct->name : 'Account', bta_money($amount), $o->order_number), $body, bta_mail_headers());
}
