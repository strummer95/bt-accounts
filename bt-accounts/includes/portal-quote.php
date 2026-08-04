<?php
/**
 * BT Accounts — the account quoter.
 *
 * Same engine as the public Quick Quote, but locked to customer-supplied
 * garments: these accounts drop-ship their own blanks, so a quote is the
 * decoration cost and nothing else. Rates come from the account's pricing
 * profile, which is empty by default and therefore quotes standard supplied
 * item pricing.
 *
 * Prices here are an estimate. Stitch count and ink colours are not known
 * until someone looks at the art, so the shop confirms before production.
 */
if (!defined('ABSPATH')) exit;

function bta_portal_quote($user, $account) {
    if (!function_exists('btq_price')) {
        echo '<h1 class="bta-h1">Quote</h1>';
        echo '<div class="bta-empty"><div class="bta-empty-title">Quoting is unavailable</div>'
           . '<p>The pricing engine is not running. Please call the shop and we will quote it for you.</p></div>';
        return;
    }

    echo '<h1 class="bta-h1">Quote</h1>';
    echo '<p class="bta-lede">Decoration pricing on garments you supply. Artwork can change the number, so treat this as an estimate &mdash; we confirm before anything goes into production.</p>';

    echo '<div class="bta-quote">';

    // ── Controls ──
    echo '<div class="bta-card bta-quote-form">';

    echo '<label class="bta-label">Method</label>';
    echo '<div class="bta-seg" id="btaMethod">';
    echo '<button type="button" class="bta-seg-btn is-on" data-v="print">Print</button>';
    echo '<button type="button" class="bta-seg-btn" data-v="embroidery">Embroidery</button>';
    echo '</div>';

    echo '<div id="btaPrintOpts">';
    echo '<label class="bta-label" style="margin-top:18px">Print locations</label>';
    echo '<div class="bta-seg" id="btaLocations">';
    for ($i = 1; $i <= 3; $i++) {
        echo '<button type="button" class="bta-seg-btn' . ($i === 1 ? ' is-on' : '') . '" data-v="' . $i . '">' . $i . '</button>';
    }
    echo '</div></div>';

    echo '<div id="btaEmbOpts" hidden>';
    echo '<label class="bta-label" style="margin-top:18px">Embroidery type</label>';
    echo '<div class="bta-seg bta-seg-wrap" id="btaEmbType">';
    echo '<button type="button" class="bta-seg-btn is-on" data-v="text">Text</button>';
    echo '<button type="button" class="bta-seg-btn" data-v="logo">Logo</button>';
    echo '<button type="button" class="bta-seg-btn" data-v="hard">Hard to handle</button>';
    echo '</div></div>';

    echo '<label class="bta-label" for="btaQty" style="margin-top:18px">Quantity</label>';
    echo '<input class="bta-input" id="btaQty" type="number" min="1" max="1000" value="48" inputmode="numeric">';
    echo '<div class="bta-qtychips" id="btaQtyChips"></div>';

    echo '</div>';

    // ── Result ──
    echo '<div class="bta-card bta-quote-out" id="btaQuoteOut" aria-live="polite">';
    echo '<div class="bta-quote-loading">Working it out&hellip;</div>';
    echo '</div>';

    echo '</div>'; // .bta-quote

    echo '<div id="btaBreaks"></div>';

    echo '<p class="bta-hint" style="margin-top:22px">Ready to order? <a href="' . esc_url(bta_portal_url('new')) . '">Start an order</a> and we will confirm pricing once we have seen the art.</p>';

    echo '<script id="btaQuoteData" type="application/json">' . wp_json_encode(array(
        'url'   => rest_url('bt-accounts/v1/quote'),
        'chips' => array(12, 24, 48, 72, 144, 288),
    )) . '</script>';
}

/* ── Endpoint ────────────────────────────────────────────────────────────── */

add_action('rest_api_init', function () {
    register_rest_route('bt-accounts/v1', '/quote', array(
        'methods'             => 'GET',
        'callback'            => 'bta_rest_quote',
        // Account rates are not public — portal session required.
        'permission_callback' => 'bta_is_logged_in',
    ));
});

function bta_rest_quote($request) {
    $account = bta_current_account();
    if (!$account) return new WP_Error('bta_no_account', 'Not signed in.', array('status' => 401));

    $res = bta_price_for_account($account->id, array(
        'qty'       => (int) $request->get_param('qty'),
        'garment'   => 'supplied',                       // always: they send the blanks
        'method'    => sanitize_text_field((string) $request->get_param('method')),
        'locations' => (int) $request->get_param('locations'),
        'embType'   => sanitize_text_field((string) $request->get_param('embType')),
    ));
    if (is_wp_error($res)) return $res;
    return rest_ensure_response($res);
}
