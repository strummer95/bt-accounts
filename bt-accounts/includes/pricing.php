<?php
/**
 * BT Accounts — per-account pricing.
 *
 * BT Quote's engine already ends with apply_filters('btq_pricing_tables', $t),
 * so an account's rates are applied by filtering that table rather than by
 * forking btq_price(). The engine, its formulas and its parity tests stay
 * untouched; an account profile is only a set of value overrides in the same
 * shape as BT Quote's own btq_pricing_overrides option.
 *
 * An empty profile means "same numbers the shop quotes publicly", which is what
 * every new account starts on.
 */
if (!defined('ABSPATH')) exit;

/** Decode an account's saved profile. */
function bta_account_profile($account) {
    if (!$account || empty($account->pricing_profile)) return array();
    $p = json_decode($account->pricing_profile, true);
    return is_array($p) ? $p : array();
}

/**
 * Price something for a specific account.
 * Same arguments as btq_price(); garment defaults to 'supplied' because these
 * accounts drop-ship their own blanks and are charged for decoration only.
 */
function bta_price_for_account($account_id, $args) {
    if (!function_exists('btq_price')) {
        return new WP_Error('bta_no_engine', 'BT Quote is not active, so pricing is unavailable.');
    }
    if (!isset($args['garment']) || $args['garment'] === '') {
        $args['garment'] = 'supplied';
    }

    $account = bta_get_account($account_id);
    if (!$account) return new WP_Error('bta_no_account', 'Unknown account.');

    $GLOBALS['bta_pricing_account'] = $account;
    $result = btq_price($args);
    unset($GLOBALS['bta_pricing_account']);

    return $result;
}

/**
 * Swap in the account's overrides while a bta_price_for_account() call is in
 * flight. Outside that window this is a no-op, so the public Quick Quote and
 * BT Catalog keep quoting standard rates exactly as before.
 */
add_filter('btq_pricing_tables', 'bta_apply_account_pricing', 20);
function bta_apply_account_pricing($t) {
    if (empty($GLOBALS['bta_pricing_account'])) return $t;

    $ov = bta_account_profile($GLOBALS['bta_pricing_account']);
    if (!$ov) return $t;

    // Tier tables: override the price at each fixed qty anchor, by index.
    foreach (array('PRINT_TIERS', 'LOC2_TIERS', 'LOC3_TIERS', 'EMB_TEXT_TIERS') as $sec) {
        if (!empty($ov[$sec]) && is_array($ov[$sec])) {
            foreach ($ov[$sec] as $i => $price) {
                if (isset($t[$sec][$i])) $t[$sec][$i][1] = (float) $price;
            }
        }
    }

    if (!empty($ov['GMT_DISC']) && is_array($ov['GMT_DISC'])) {
        foreach ($ov['GMT_DISC'] as $i => $mult) {
            if (isset($t['GMT_DISC'][$i])) $t['GMT_DISC'][$i]['mult'] = (float) $mult;
        }
    }

    // 'supplied' stays $0 and 'custom' stays caller-supplied, by design.
    if (!empty($ov['GARMENTS']) && is_array($ov['GARMENTS'])) {
        foreach ($ov['GARMENTS'] as $k => $price) {
            if (isset($t['GARMENTS'][$k]) && $k !== 'supplied' && $k !== 'custom') {
                $t['GARMENTS'][$k] = (float) $price;
            }
        }
    }

    foreach (array('EMB_LOGO_MULT', 'EMB_HARD_ADDER', 'HANDLING_UNDER', 'HANDLING_OVER') as $k) {
        if (isset($ov[$k])) $t[$k] = (float) $ov[$k];
    }
    if (isset($ov['EMB_QUOTE_MIN'])) $t['EMB_QUOTE_MIN'] = max(1, (int) $ov['EMB_QUOTE_MIN']);

    // A flat contract discount off everything, applied last.
    if (!empty($ov['FLAT_DISCOUNT_PCT'])) {
        $m = 1 - (min(90, max(0, (float) $ov['FLAT_DISCOUNT_PCT'])) / 100);
        foreach (array('PRINT_TIERS', 'LOC2_TIERS', 'LOC3_TIERS', 'EMB_TEXT_TIERS') as $sec) {
            foreach ($t[$sec] as $i => $row) $t[$sec][$i][1] = $row[1] * $m;
        }
    }

    return $t;
}
