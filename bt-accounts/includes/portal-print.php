<?php
/**
 * BT Accounts — printable work order.
 *
 * A standalone document at /accounts/order/{id}/print. Rendered outside the
 * theme and outside the portal chrome, so it prints as a clean sheet with no
 * navigation, no tabs and nothing from another plugin's stylesheet.
 *
 * Two audiences share it: the account prints their own copy from the portal,
 * and the shop prints the same sheet as the pull ticket from wp-admin. A
 * wp-admin user with manage_options can open any order's sheet without a portal
 * session; a portal user gets exactly the ownership check the order page uses.
 */
if (!defined('ABSPATH')) exit;

function bta_order_print_url($order_id) {
    return home_url('/' . bta_portal_slug() . '/order/' . (int) $order_id . '/print/');
}

/**
 * Decide whether the current requester may see this sheet, then render it.
 * Called from bta_route_portal() before any portal chrome is emitted.
 */
function bta_render_order_print($order_id) {
    $order = bta_get_order($order_id);
    if (!$order) { status_header(404); bta_print_denied(); return; }

    $is_shop = current_user_can('manage_options');

    if (!$is_shop) {
        if (!bta_is_logged_in()) { status_header(403); bta_print_denied(); return; }
        $user    = bta_current_user();
        $account = $user->account;
        if ((int) $order->account_id !== (int) $account->id) { status_header(403); bta_print_denied(); return; }
        if (!$user->is_account_admin && (int) $order->user_id !== (int) $user->id) { status_header(403); bta_print_denied(); return; }
    }

    bta_sync_status_from_job($order);

    $account = bta_get_account($order->account_id);
    $user    = bta_get_user($order->user_id);
    $items   = bta_get_order_items($order->id);
    $art     = bta_get_order_art($order->id);
    $accent  = ($account && $account->brand_color) ? $account->brand_color : '#27267e';
    $qty     = bta_order_qty($order->id);
    $dec     = bta_decorations();
    $auto    = !empty($_GET['auto']);

    $artby = array();
    foreach ($art as $a) $artby[(int) $a->id] = $a;

    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow', true);
    ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html($order->order_number . ' · Work Order · Boomer T\'s'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&display=swap">
<link rel="stylesheet" href="<?php echo esc_url(BTA_URL . 'assets/print.css?v=' . BTA_VERSION); ?>">
<style>:root { --bta-accent: <?php echo esc_html($accent); ?>; }</style>
</head>
<body class="btp-body">

<div class="btp-bar btp-noprint">
  <a class="btp-back" href="<?php echo esc_url($is_shop && !bta_is_logged_in() ? admin_url('admin.php?page=bt-accounts-orders&id=' . (int) $order->id) : bta_portal_url('order/' . (int) $order->id)); ?>">&larr; Back to the order</a>
  <button class="btp-print" type="button" onclick="window.print()">Print / Save as PDF</button>
</div>

<div class="btp-sheet">

  <header class="btp-head">
    <div class="btp-head-left">
      <?php
        $logo = bta_shop_logo_url();
        if ($logo) echo '<img class="btp-shoplogo" src="' . esc_url($logo) . '" alt="Boomer T\'s">';
        else echo '<div class="btp-wordmark">Boomer T<em>&rsquo;</em>s</div>';
      ?>
      <div class="btp-shopmeta">Ink &amp; Thread &middot; orders@boomerts.com</div>
    </div>
    <div class="btp-head-right">
      <div class="btp-doctype">Work Order</div>
      <div class="btp-number"><?php echo esc_html($order->order_number); ?></div>
      <div class="btp-status"><?php echo esc_html(bta_status_label($order->status)); ?></div>
    </div>
  </header>

  <div class="btp-acctstrip">
    <?php if ($account && $account->logo_url) : ?>
      <img class="btp-acctlogo" src="<?php echo esc_url($account->logo_url); ?>" alt="<?php echo esc_attr($account->name); ?>">
    <?php endif; ?>
    <div class="btp-acctname"><?php echo esc_html($account ? $account->name : ''); ?></div>
    <?php if ($order->account_po !== '') : ?>
      <div class="btp-acctpo">PO <strong><?php echo esc_html($order->account_po); ?></strong></div>
    <?php endif; ?>
  </div>

  <section class="btp-cols">
    <div class="btp-col">
      <h2 class="btp-h2">Order</h2>
      <dl class="btp-dl">
        <?php
        btp_dl('End customer', $order->end_customer);
        btp_dl('Submitted', $order->submitted_at ? date_i18n('M j, Y g:ia', strtotime($order->submitted_at)) : '');
        btp_dl('Submitted by', $user ? ($user->display_name ? $user->display_name : $user->username) : '');
        btp_dl('In-hands date', $order->in_hands_date ? date_i18n('D, M j, Y', strtotime($order->in_hands_date)) : '');
        btp_dl('Total pieces', $qty);
        ?>
      </dl>
    </div>
    <div class="btp-col">
      <h2 class="btp-h2">Blanks inbound</h2>
      <dl class="btp-dl">
        <?php
        btp_dl('Supplier', $order->supplier_name);
        btp_dl('Supplier PO', $order->supplier_po);
        btp_dl('Expected arrival', $order->expected_arrival ? date_i18n('M j, Y', strtotime($order->expected_arrival)) : '');
        ?>
      </dl>
    </div>
    <div class="btp-col">
      <h2 class="btp-h2">Ship to</h2>
      <dl class="btp-dl">
        <?php
        $addr = trim($order->ship_address1 . ($order->ship_address2 !== '' ? ', ' . $order->ship_address2 : ''));
        $city = trim($order->ship_city . ' ' . $order->ship_state . ' ' . $order->ship_zip);
        btp_dl('Name', $order->ship_name);
        btp_dl('Address', trim($addr . ($city !== '' ? ', ' . $city : ''), ', '));
        btp_dl('Method', $order->ship_method);
        ?>
      </dl>
    </div>
  </section>

  <h2 class="btp-h2 btp-h2-wide">Items</h2>
  <table class="btp-table">
    <thead>
      <tr>
        <th style="width:26px">#</th>
        <th>Style</th>
        <th>Colour</th>
        <th>Size breakdown</th>
        <th style="text-align:right;width:56px">Qty</th>
        <th style="width:150px">Decoration</th>
        <th style="width:120px">Logo</th>
      </tr>
    </thead>
    <tbody>
      <?php $n = 0; foreach ($items as $it) : $n++;
        $sizes = bta_item_sizes($it);
        $parts = array();
        foreach ($sizes as $s => $q) if ((int) $q > 0) $parts[] = '<span class="btp-size"><b>' . esc_html($s) . '</b>' . (int) $q . '</span>';
      ?>
      <tr>
        <td class="btp-num"><?php echo (int) $n; ?></td>
        <td>
          <strong class="btp-style"><?php echo esc_html($it->style_no); ?></strong>
          <?php $sub = trim($it->brand . ' ' . $it->style_name); if ($sub !== '') : ?>
            <div class="btp-sub"><?php echo esc_html($sub); ?></div>
          <?php endif; ?>
        </td>
        <td><?php echo esc_html($it->color !== '' ? $it->color : '—'); ?></td>
        <td class="btp-sizes"><?php echo $parts ? implode(' ', $parts) : '—'; ?></td>
        <td class="btp-qty"><?php echo (int) $it->qty; ?></td>
        <td>
          <?php echo esc_html(isset($dec[$it->decoration]) ? $dec[$it->decoration] : $it->decoration); ?>
          <?php if ($it->placement !== '') : ?><div class="btp-sub"><?php echo esc_html($it->placement); ?></div><?php endif; ?>
        </td>
        <td><?php echo esc_html(isset($artby[(int) $it->art_id]) ? $artby[(int) $it->art_id]->label : '—'); ?></td>
      </tr>
      <?php if ($it->notes !== '') : ?>
      <tr class="btp-noterow"><td></td><td colspan="6"><span class="btp-notelabel">Note</span> <?php echo esc_html($it->notes); ?></td></tr>
      <?php endif; ?>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="4" class="btp-totlabel">Total pieces</td><td class="btp-qty btp-tot"><?php echo (int) $qty; ?></td><td colspan="2"></td></tr>
    </tfoot>
  </table>

  <?php if ($art) : ?>
  <h2 class="btp-h2 btp-h2-wide">Artwork</h2>
  <div class="btp-art">
    <?php foreach ($art as $a) :
      $ext = strtolower(pathinfo((string) $a->file_name, PATHINFO_EXTENSION));
      $is_img = in_array($ext, array('png', 'jpg', 'jpeg', 'gif'), true);
    ?>
    <div class="btp-artcard">
      <div class="btp-artthumb">
        <?php if ($is_img) : ?>
          <img src="<?php echo esc_url($a->file_url); ?>" alt="<?php echo esc_attr($a->label); ?>">
        <?php else : ?>
          <span class="btp-artext"><?php echo esc_html($ext !== '' ? strtoupper($ext) : 'FILE'); ?></span>
        <?php endif; ?>
      </div>
      <div class="btp-artlabel"><?php echo esc_html($a->label); ?></div>
      <div class="btp-artfile"><?php echo esc_html($a->file_name); ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($order->notes !== '') : ?>
  <h2 class="btp-h2 btp-h2-wide">Notes</h2>
  <div class="btp-notes"><?php echo nl2br(esc_html($order->notes)); ?></div>
  <?php endif; ?>

  <section class="btp-signoff">
    <div class="btp-sigbox"><span class="btp-sigline"></span><span class="btp-siglabel">Art approved by / date</span></div>
    <div class="btp-sigbox"><span class="btp-sigline"></span><span class="btp-siglabel">Production checked by / date</span></div>
    <div class="btp-sigbox"><span class="btp-sigline"></span><span class="btp-siglabel">Packed &amp; shipped by / date</span></div>
  </section>

  <footer class="btp-foot">
    <span><?php echo esc_html($order->order_number); ?> &middot; <?php echo esc_html($account ? $account->name : ''); ?></span>
    <span>Printed <?php echo esc_html(date_i18n('M j, Y g:ia')); ?></span>
  </footer>

</div>

<?php if ($auto) : ?>
<script>window.addEventListener('load', function () { window.print(); });</script>
<?php endif; ?>
</body>
</html>
    <?php
}

function btp_dl($label, $value) {
    echo '<dt>' . esc_html($label) . '</dt><dd>' . esc_html($value !== '' && $value !== null ? $value : '—') . '</dd>';
}

function bta_print_denied() {
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Not available</title>'
       . '<style>body{font:16px/1.6 Tahoma,Arial,sans-serif;color:#22242a;margin:60px auto;max-width:520px;padding:0 20px}'
       . 'a{color:#27267e}</style></head><body>'
       . '<h1 style="font-size:22px;margin:0 0 10px">Order not available</h1>'
       . '<p>That order does not exist, or it is not on your account.</p>'
       . '<p><a href="' . esc_url(home_url('/' . bta_portal_slug() . '/')) . '">Back to the portal</a></p>'
       . '</body></html>';
}
