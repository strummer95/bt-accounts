<?php
/**
 * BT Accounts — Printavo.
 *
 * Every submitted account order becomes a quote in Printavo on the account's
 * Printavo contact (Cintas is Sasha Velez), priced on the account's rates.
 * The shop reviews it there and sends it for approval by hand. Nothing here
 * approves, invoices, or emails anyone from Printavo.
 *
 * Printavo's API is GraphQL, with an account email and API token as headers.
 * Its docs could not be read while this was written, so the plugin asks
 * Printavo for its own field list (GraphQL introspection) and only sends
 * fields Printavo says exist. Anything left out is written to the order's
 * Printavo log, and everything that matters also goes into the production
 * note as plain text, so a field-name mismatch costs tidiness, not facts.
 *
 * A failed send never touches the order: it is saved first, and a quote that
 * did not make it is flagged on the staff screen with a button to try again.
 */
if (!defined('ABSPATH')) exit;

/* ── Settings ────────────────────────────────────────────────────────────── */

function bta_pv_endpoint() {
    return apply_filters('bta_printavo_endpoint', 'https://www.printavo.com/api/v2');
}

function bta_pv_email()      { return trim((string) get_option('bta_pv_email', '')); }
function bta_pv_token()      { return trim((string) get_option('bta_pv_token', '')); }
function bta_pv_status_id()  { return (string) get_option('bta_pv_status_id', ''); }
function bta_pv_configured() { return bta_pv_email() !== '' && bta_pv_token() !== ''; }

/**
 * Who an account's quotes go to in Printavo: the contact's name as typed, and
 * the ids found for it. Cintas defaults to Sasha Velez.
 */
function bta_pv_account_map($account) {
    $m = get_option('bta_pv_acct_' . (int) $account->id, array());
    if (!is_array($m)) $m = array();
    $m = array_merge(array('name' => '', 'contact_id' => '', 'customer_id' => '', 'label' => ''), $m);
    if ($m['name'] === '' && stripos((string) $account->name, 'cintas') !== false) $m['name'] = 'Sasha Velez';
    return $m;
}

/**
 * Printavo is per account: only an account with a Printavo contact sends.
 * One without stays out of Printavo entirely, with no failure emails.
 */
function bta_pv_account_on($account) {
    if (!$account) return false;
    $m = bta_pv_account_map($account);
    return $m['name'] !== '';
}

function bta_pv_save_account_map($account_id, $m) {
    update_option('bta_pv_acct_' . (int) $account_id, $m, false);
}

/* ── Transport ───────────────────────────────────────────────────────────── */

/**
 * One GraphQL request. Returns the data array or WP_Error with a sentence a
 * person can act on. Printavo allows 10 requests per 5 seconds, so calls are
 * spaced, and one rate-limit response is waited out and retried.
 */
function bta_pv_gql($query, $vars = array()) {
    if (!bta_pv_configured()) {
        return new WP_Error('bta_pv_off', 'Printavo is not connected. Add the Printavo email and API token under BT Accounts in wp-admin.');
    }

    static $last = 0.0;
    $gap = microtime(true) - $last;
    if ($gap < 0.6) usleep((int) ((0.6 - $gap) * 1000000));

    $body = array('query' => $query);
    if ($vars) $body['variables'] = $vars;

    $res  = null;
    $code = 0;
    for ($try = 0; $try < 2; $try++) {
        $last = microtime(true);
        $res = wp_remote_post(bta_pv_endpoint(), array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'email'        => bta_pv_email(),
                'token'        => bta_pv_token(),
            ),
            'body' => wp_json_encode($body),
        ));
        if (is_wp_error($res)) {
            return new WP_Error('bta_pv_http', 'Could not reach Printavo: ' . $res->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code !== 429 || $try === 1) break;
        sleep(6);
    }

    $raw  = (string) wp_remote_retrieve_body($res);
    $json = json_decode($raw, true);

    if ($code === 401 || $code === 403) {
        return new WP_Error('bta_pv_auth', 'Printavo turned down the email and API token (HTTP ' . $code . '). Check both under BT Accounts → Printavo. API access needs Printavo\'s Premium plan.');
    }
    if ($code === 429) {
        return new WP_Error('bta_pv_busy', 'Printavo is limiting how fast it takes requests. Try again in a minute.');
    }
    if (!is_array($json)) {
        return new WP_Error('bta_pv_bad', 'Printavo sent back something unexpected (HTTP ' . $code . '): ' . substr(trim(wp_strip_all_tags($raw)), 0, 200));
    }
    if (!empty($json['errors'])) {
        $msgs = array();
        foreach ((array) $json['errors'] as $e) {
            $msgs[] = is_array($e) && isset($e['message']) ? (string) $e['message'] : (is_string($e) ? $e : wp_json_encode($e));
        }
        return new WP_Error('bta_pv_gql', 'Printavo said: ' . implode(' | ', array_slice($msgs, 0, 5)));
    }
    if ($code >= 400) {
        return new WP_Error('bta_pv_http', 'Printavo returned HTTP ' . $code . '.');
    }
    return isset($json['data']) && is_array($json['data']) ? $json['data'] : array();
}

/* ── Schema (introspection) ──────────────────────────────────────────────── */

function bta_pv_type_ref_fields() {
    return 'kind name ofType { kind name ofType { kind name ofType { kind name ofType { kind name } } } }';
}

/** GraphQL type as written in a variable declaration, e.g. [LineItemCreateInput!]! */
function bta_pv_sig($r) {
    if (!is_array($r)) return '';
    if ($r['kind'] === 'NON_NULL') return bta_pv_sig(isset($r['ofType']) ? $r['ofType'] : null) . '!';
    if ($r['kind'] === 'LIST')     return '[' . bta_pv_sig(isset($r['ofType']) ? $r['ofType'] : null) . ']';
    return (string) $r['name'];
}

/** Flatten a type reference: base name and kind, list or not, required or not. */
function bta_pv_ref($r) {
    $sig  = bta_pv_sig($r);
    $req  = is_array($r) && $r['kind'] === 'NON_NULL';
    $list = false;
    while (is_array($r) && empty($r['name'])) {
        if ($r['kind'] === 'LIST') $list = true;
        $r = isset($r['ofType']) ? $r['ofType'] : null;
    }
    return array(
        'name'     => is_array($r) ? (string) $r['name'] : '',
        'kind'     => is_array($r) ? (string) $r['kind'] : '',
        'list'     => $list,
        'required' => $req,
        'sig'      => $sig,
    );
}

/**
 * What Printavo says about one type, cached for a week (Test connection
 * clears it). False means Printavo would not say (introspection off or no
 * such type); null means Printavo could not be reached.
 */
function bta_pv_type($name) {
    static $mem = array();
    if (array_key_exists($name, $mem)) return $mem[$name];

    $cache = get_option('bta_pv_schema', array());
    if (!is_array($cache) || empty($cache['at']) || (int) $cache['at'] < time() - WEEK_IN_SECONDS) {
        $cache = array('at' => time(), 'types' => array());
    }
    if (!empty($cache['types'][$name])) return $mem[$name] = $cache['types'][$name];

    $ref = bta_pv_type_ref_fields();
    $q = 'query($n: String!) { __type(name: $n) { name kind'
       . ' inputFields { name type { ' . $ref . ' } }'
       . ' fields { name args { name type { ' . $ref . ' } } type { ' . $ref . ' } }'
       . ' enumValues { name } } }';
    $d = bta_pv_gql($q, array('n' => $name));

    if (is_wp_error($d)) {
        if ($d->get_error_code() !== 'bta_pv_gql') return null;
        $info = false;
    } elseif (empty($d['__type'])) {
        $info = false;
    } else {
        $t = $d['__type'];
        $info = array('kind' => (string) $t['kind'], 'fields' => array(), 'args' => array(), 'enum' => array());
        foreach ((array) (isset($t['inputFields']) ? $t['inputFields'] : array()) as $f) {
            $info['fields'][$f['name']] = bta_pv_ref($f['type']);
        }
        foreach ((array) (isset($t['fields']) ? $t['fields'] : array()) as $f) {
            $info['fields'][$f['name']] = bta_pv_ref($f['type']);
            $info['args'][$f['name']] = array();
            foreach ((array) $f['args'] as $a) $info['args'][$f['name']][$a['name']] = bta_pv_ref($a['type']);
        }
        foreach ((array) (isset($t['enumValues']) ? $t['enumValues'] : array()) as $e) $info['enum'][] = (string) $e['name'];
    }

    // Only real answers are kept; a refusal is asked again next time.
    if ($info) {
        $cache['types'][$name] = $info;
        update_option('bta_pv_schema', $cache, false);
    }
    return $mem[$name] = $info;
}

function bta_pv_forget_schema() {
    delete_option('bta_pv_schema');
}

/** Field reference on a type, or null if the type or field is unknown. */
function bta_pv_field($type_name, $field) {
    $t = bta_pv_type($type_name);
    return ($t && isset($t['fields'][$field])) ? $t['fields'][$field] : null;
}

/**
 * The wanted fields that exist on an output type. When Printavo will not
 * describe the type, $blind (the fields its docs name) or else all of them.
 */
function bta_pv_select($type_name, $wanted, $blind = null) {
    $t = bta_pv_type($type_name);
    if (!$t) return $blind !== null ? $blind : $wanted;
    return array_values(array_filter($wanted, function ($f) use ($t) { return isset($t['fields'][$f]); }));
}

/* ── Fitting a payload to the schema ─────────────────────────────────────── */

/**
 * Keep only what an input type accepts. Unknown keys are dropped and named in
 * $dropped; enum values match case-insensitively; an {id: x} where Printavo
 * wants a bare ID collapses to x. With no schema the value passes untouched.
 */
function bta_pv_fit($value, $type_name, &$dropped, $path = '') {
    $t = bta_pv_type($type_name);
    if (!$t) return $value;
    if ($t['kind'] === 'ENUM') return bta_pv_enum($value, $t['enum']);
    if ($t['kind'] !== 'INPUT_OBJECT' || !is_array($value)) return $value;

    $out = array();
    foreach ($value as $k => $v) {
        if ($v === null || $v === '' || $v === array()) continue;
        $p = ltrim($path . '.' . $k, '.');
        if (!isset($t['fields'][$k])) { $dropped[] = $p; continue; }
        $fv = bta_pv_fit_field($v, $t['fields'][$k], $dropped, $p);
        if ($fv === null) { $dropped[] = $p; continue; }
        $out[$k] = $fv;
    }
    return $out;
}

function bta_pv_fit_field($v, $ref, &$dropped, $path) {
    if (!$ref['list']) return bta_pv_fit_one($v, $ref, $dropped, $path);
    if (!is_array($v) || !wp_is_numeric_array($v)) $v = array($v);
    $out = array();
    foreach ($v as $i => $one) {
        $x = bta_pv_fit_one($one, $ref, $dropped, $path . '[' . $i . ']');
        if ($x !== null) $out[] = $x;
    }
    return $out ? $out : null;
}

function bta_pv_fit_one($v, $ref, &$dropped, $path) {
    if ($ref['kind'] === 'INPUT_OBJECT') {
        if (!is_array($v)) $v = array('id' => $v);
        $x = bta_pv_fit($v, $ref['name'], $dropped, $path);
        return $x ? $x : null;
    }
    if ($ref['kind'] === 'ENUM') {
        $t = bta_pv_type($ref['name']);
        return $t ? bta_pv_enum($v, $t['enum']) : $v;
    }
    if (is_array($v)) return isset($v['id']) ? (string) $v['id'] : null;
    switch ($ref['name']) {
        case 'Int':     return (int) $v;
        case 'Float':   return (float) $v;
        case 'Boolean': return (bool) $v;
        case 'String':
        case 'ID':      return (string) $v;
    }
    return $v;
}

function bta_pv_enum($v, $values) {
    if (!is_scalar($v)) return null;
    foreach ($values as $e) if (strcasecmp($e, (string) $v) === 0) return $e;
    return null;
}

/**
 * Argument types assumed when Printavo will not describe itself. These follow
 * Printavo's published operation names; with introspection on they are never
 * used.
 */
function bta_pv_blind_sig($mutation, $arg) {
    $map = array(
        'quoteCreate'          => array('input' => 'QuoteCreateInput!'),
        'lineItemGroupCreate'  => array('parentId' => 'ID!', 'input' => 'LineItemGroupCreateInput!'),
        'lineItemCreate'       => array('lineItemGroupId' => 'ID!', 'input' => 'LineItemCreateInput!'),
        'imprintCreate'        => array('lineItemGroupId' => 'ID!', 'input' => 'ImprintInput!'),
        'productionFileCreate' => array('parentId' => 'ID!', 'publicFileUrl' => 'String!'),
        'statusUpdate'         => array('parentId' => 'ID!', 'statusId' => 'ID!'),
        'quoteUpdate'          => array('id' => 'ID!', 'input' => 'QuoteUpdateInput!'),
    );
    return isset($map[$mutation][$arg]) ? $map[$mutation][$arg] : '';
}

/**
 * Field names offered as a second guess beside the documented one (contactId
 * beside contact, zip beside zipCode...). With the schema, whichever Printavo
 * lacks is dropped as expected; without it, these are stripped so only the
 * documented names are sent.
 */
function bta_pv_alt_keys() {
    return array('contactId', 'customerId', 'categoryId', 'poNumber', 'state', 'zip', 'country', 'quoteId', 'orderId',
                 'fileUrl', 'url', 'name', 'parentId', 'lineItemGroupId');
}

function bta_pv_strip_alts($v, $in_imprint = false) {
    if (!is_array($v)) return $v;
    $out = array();
    foreach ($v as $k => $x) {
        if (is_string($k) && in_array($k, bta_pv_alt_keys(), true)) continue;
        if ($in_imprint && ($k === 'description' || $k === 'position')) continue;
        $out[$k] = bta_pv_strip_alts($x, $in_imprint || $k === 'imprints');
    }
    return $out;
}

/** Dropped fields worth telling staff about: not the expected second guesses. */
function bta_pv_real_drops($dropped) {
    $alts = bta_pv_alt_keys();
    $out = array();
    foreach (array_unique($dropped) as $p) {
        $leaf = preg_replace('/\[\d+\]$/', '', substr($p, strrpos('.' . $p, '.')));
        if (in_array($leaf, $alts, true)) continue;
        if (preg_match('/(imprints\[\d+\]|imprintCreate\.input)\.(description|position)$/', $p)) continue;
        if ($p === 'quoteCreate.input.statusId') continue;   // set by statusUpdate instead
        if (preg_match('/^(statusUpdate|productionFileCreate|quoteUpdate)\.(id|input|url|fileUrl|poNumber)/', $p)) continue;
        $out[] = $p;
    }
    return array_values($out);
}

/**
 * Call a mutation using whatever argument names Printavo declares for it.
 * $cand maps candidate argument names to values; each is fitted to its
 * declared type. Returns the mutation's result object or WP_Error.
 */
function bta_pv_mutate($name, $cand, $select, &$dropped) {
    $m    = bta_pv_type('Mutation');
    $decl = array();
    $use  = array();
    $vars = array();

    if ($m) {
        if (!isset($m['args'][$name])) return new WP_Error('bta_pv_nomut', 'Printavo has no ' . $name . ' operation.');
        foreach ($m['args'][$name] as $arg => $ref) {
            $val = array_key_exists($arg, $cand) ? bta_pv_fit_field($cand[$arg], $ref, $dropped, $name . '.' . $arg) : null;
            if ($val === null || $val === array()) {
                if ($ref['required']) {
                    return new WP_Error('bta_pv_arg', 'Printavo\'s ' . $name . ' needs "' . $arg . '", which BT Accounts does not fill in yet.');
                }
                continue;
            }
            $decl[] = '$' . $arg . ': ' . $ref['sig'];
            $use[]  = $arg . ': $' . $arg;
            $vars[$arg] = $val;
        }
    } else {
        foreach ($cand as $arg => $val) {
            $sig = bta_pv_blind_sig($name, $arg);
            if ($sig === '' || $val === null || $val === '') continue;
            $val = bta_pv_strip_alts($val, $name === 'imprintCreate');
            $decl[] = '$' . $arg . ': ' . $sig;
            $use[]  = $arg . ': $' . $arg;
            $vars[$arg] = $val;
        }
    }

    $q = 'mutation' . ($decl ? '(' . implode(', ', $decl) . ')' : '')
       . ' { r: ' . $name . ($use ? '(' . implode(', ', $use) . ')' : '') . ' { ' . $select . ' } }';
    $d = bta_pv_gql($q, $vars);
    if (is_wp_error($d)) return $d;
    if (empty($d['r'])) return new WP_Error('bta_pv_empty', 'Printavo did not hand back the new record from ' . $name . '.');
    return $d['r'];
}

/** Declared input type of one mutation argument, e.g. quoteCreate.input → QuoteCreateInput. */
function bta_pv_arg_type($mutation, $arg, $fallback) {
    $m = bta_pv_type('Mutation');
    return ($m && isset($m['args'][$mutation][$arg])) ? $m['args'][$mutation][$arg]['name'] : $fallback;
}

/* ── Lookups ─────────────────────────────────────────────────────────────── */

/** Fold a name for comparison: case, spacing and punctuation ignored. */
function bta_pv_fold($s) {
    return strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $s));
}

/**
 * Find a Printavo contact by name (or email). Uses Printavo's search when the
 * contacts query takes one, otherwise reads the contact list page by page.
 * Returns array(contact_id, customer_id, label) or WP_Error.
 */
function bta_pv_find_contact($name) {
    $want = bta_pv_fold($name);
    if ($want === '') return new WP_Error('bta_pv_noname', 'Type the name of the account\'s contact in Printavo first.');

    $q = bta_pv_type('Query');
    if ($q === null) return new WP_Error('bta_pv_http', 'Could not reach Printavo to look the contact up.');

    $sel = bta_pv_select('Contact', array('id', 'fullName', 'firstName', 'lastName', 'email'), array('id', 'fullName', 'email'));
    $sub = '';
    if (bta_pv_field('Contact', 'customer')) {
        $sub = ' customer { ' . implode(' ', bta_pv_select('Customer', array('id', 'companyName'))) . ' }';
    }
    $node = implode(' ', $sel) . $sub;

    $search = ($q && isset($q['args']['contacts']['query'])) ? 'query' : '';
    $after  = null;
    $seen   = 0;
    $loose  = null;

    for ($page = 0; $page < 40; $page++) {
        $args = array('first' => 25);
        $decl = array('$first: Int');
        if ($search) { $args['q'] = $name; $decl[] = '$q: String'; }
        if ($after)  { $args['after'] = $after; $decl[] = '$after: String'; }
        $call = 'first: $first' . ($search ? ', query: $q' : '') . ($after ? ', after: $after' : '');

        $d = bta_pv_gql('query(' . implode(', ', $decl) . ') { contacts(' . $call . ') { edges { node { ' . $node . ' } } pageInfo { hasNextPage endCursor } } }', $args);
        if (is_wp_error($d)) return $d;
        $conn = isset($d['contacts']) ? $d['contacts'] : array();

        foreach ((array) (isset($conn['edges']) ? $conn['edges'] : array()) as $e) {
            $c = isset($e['node']) ? $e['node'] : array();
            $seen++;
            $full = isset($c['fullName']) ? $c['fullName'] : trim((isset($c['firstName']) ? $c['firstName'] : '') . ' ' . (isset($c['lastName']) ? $c['lastName'] : ''));
            $hit  = bta_pv_fold($full) === $want || (isset($c['email']) && bta_pv_fold($c['email']) === $want);
            if (!$hit && !$loose && $want !== '' && strpos(bta_pv_fold($full), $want) !== false) $loose = $c;
            if ($hit) return bta_pv_contact_result($c, $full);
        }

        $info = isset($conn['pageInfo']) ? $conn['pageInfo'] : array();
        if (empty($info['hasNextPage']) || empty($info['endCursor'])) break;
        $after = $info['endCursor'];
    }

    if ($loose) {
        $full = isset($loose['fullName']) ? $loose['fullName'] : $name;
        return bta_pv_contact_result($loose, $full);
    }
    return new WP_Error('bta_pv_nocontact', 'No contact called "' . $name . '" in Printavo (looked through ' . $seen . '). Check the spelling against Printavo\'s Customers page.');
}

function bta_pv_contact_result($c, $full) {
    $cust = isset($c['customer']) && is_array($c['customer']) ? $c['customer'] : array();
    $label = trim($full . (!empty($cust['companyName']) ? ' — ' . $cust['companyName'] : '') . (!empty($c['email']) ? ' <' . $c['email'] . '>' : ''));
    return array(
        'contact_id'  => isset($c['id']) ? (string) $c['id'] : '',
        'customer_id' => isset($cust['id']) ? (string) $cust['id'] : '',
        'label'       => $label,
    );
}

/** The account's Printavo contact, looked up and remembered the first time. */
function bta_pv_account_contact($account) {
    $m = bta_pv_account_map($account);
    if ($m['contact_id'] !== '') return $m;
    if ($m['name'] === '') {
        return new WP_Error('bta_pv_nomap', 'No Printavo contact is set for ' . $account->name . '. Add one on the account in BT Accounts.');
    }
    $r = bta_pv_find_contact($m['name']);
    if (is_wp_error($r)) return $r;
    $m = array_merge($m, $r);
    bta_pv_save_account_map($account->id, $m);
    return $m;
}

/**
 * Line item categories in Printavo (Digi Print, Embroidery...). The query's
 * name is read from the schema rather than assumed.
 */
function bta_pv_fetch_categories() {
    $path = bta_pv_find_category_path();
    if (is_wp_error($path)) return $path;

    // Build the nested query along the path, e.g. { account { categories(first: 100) { ... } } }.
    $leaf  = end($path);
    $inner = $leaf['connection']
        ? '{ edges { node { id name } } }'
        : '{ id name }';
    $q = $leaf['name'] . ($leaf['first'] ? '(first: 100)' : '') . ' ' . $inner;
    for ($i = count($path) - 2; $i >= 0; $i--) $q = $path[$i]['name'] . ' { ' . $q . ' }';
    $d = bta_pv_gql('{ ' . $q . ' }');
    if (is_wp_error($d)) return $d;

    $node = $d;
    foreach ($path as $step) $node = isset($node[$step['name']]) ? $node[$step['name']] : array();
    $rows = array();
    if ($leaf['connection']) {
        foreach ((array) (isset($node['edges']) ? $node['edges'] : array()) as $e) $rows[] = isset($e['node']) ? $e['node'] : array();
    } else {
        $rows = (array) $node;
    }
    $out = array();
    foreach ($rows as $n) {
        if (!empty($n['id'])) $out[] = array('id' => (string) $n['id'], 'name' => (string) (isset($n['name']) ? $n['name'] : $n['id']));
    }
    update_option('bta_pv_categories', $out, false);
    return $out;
}

/**
 * Where Printavo keeps its category list. Not on the top-level query, so
 * walk down from it (account, catalog settings...) up to three levels,
 * following only fields that need no arguments, until a field returns
 * Category items. The path found is remembered.
 */
function bta_pv_find_category_path() {
    $saved = get_option('bta_pv_cat_path', null);
    if (is_array($saved) && $saved) return $saved;

    if (!bta_pv_type('Query')) {
        return array(array('name' => 'categories', 'connection' => true, 'first' => true));
    }

    $budget = 30;   // type lookups; each is one request
    $queue  = array(array('type' => 'Query', 'path' => array()));
    $seen   = array('Query' => true);
    while ($queue && $budget > 0) {
        $cur = array_shift($queue);
        $t = bta_pv_type($cur['type']);
        $budget--;
        if (!$t || $t['kind'] !== 'OBJECT') continue;

        foreach ($t['fields'] as $fname => $ref) {
            $args = isset($t['args'][$fname]) ? $t['args'][$fname] : array();
            $needs = false;
            foreach ($args as $a) if ($a['required']) $needs = true;
            if ($needs) continue;

            $base = $ref['name'];
            $is_conn = preg_match('/^(\w*Categor\w*)Connection$/', $base);
            $is_list = $ref['list'] && preg_match('/categor/i', $base);
            if ($is_conn || $is_list) {
                $path = array_merge($cur['path'], array(array('name' => $fname, 'connection' => (bool) $is_conn, 'first' => isset($args['first']))));
                update_option('bta_pv_cat_path', $path, false);
                return $path;
            }
            if ($ref['kind'] === 'OBJECT' && !$ref['list'] && count($cur['path']) < 2
                && !preg_match('/(Connection|Edge|PageInfo)$/', $base) && empty($seen[$base])) {
                $seen[$base] = true;
                $queue[] = array('type' => $base, 'path' => array_merge($cur['path'], array(array('name' => $fname, 'connection' => false, 'first' => false))));
            }
        }
    }
    return new WP_Error('bta_pv_nocat', 'Printavo does not list line item categories through its API (looked under: ' . implode(', ', array_keys($seen)) . ').');
}

/**
 * Printavo category id for a decoration. The setting wins; otherwise the
 * category whose name matches (print → Digi Print, embroidery → Embroidery).
 */
function bta_pv_category_for($decoration) {
    $key = $decoration === 'embroidery' ? 'embroidery' : 'print';
    $set = (string) get_option('bta_pv_cat_' . $key, '');
    if ($set !== '') return $set;

    // Empty or never fetched: ask Printavo, once per request.
    static $tried = false;
    $cats = get_option('bta_pv_categories', array());
    if (!$cats && !$tried && bta_pv_configured()) {
        $tried = true;
        $cats = bta_pv_fetch_categories();
        if (is_wp_error($cats)) {
            update_option('bta_pv_cat_error', $cats->get_error_message(), false);
            $cats = array();
        } else {
            delete_option('bta_pv_cat_error');
        }
    }
    $want = $key === 'embroidery' ? array('embroidery') : array('digiprint', 'digitalprint', 'print');
    foreach ($want as $w) {
        foreach ((array) $cats as $c) {
            if (strpos(bta_pv_fold(preg_replace('/^\s*\d+\.\s*/', '', $c['name'])), $w) !== false) return $c['id'];
        }
    }
    return '';
}

/** Statuses in Printavo, for choosing where new quotes land. */
function bta_pv_fetch_statuses() {
    $sel = bta_pv_select('Status', array('id', 'name', 'type'), array('id', 'name'));
    $d = bta_pv_gql('{ statuses(first: 50) { edges { node { ' . implode(' ', $sel) . ' } } } }');
    if (is_wp_error($d)) return $d;
    $out = array();
    foreach ((array) (isset($d['statuses']['edges']) ? $d['statuses']['edges'] : array()) as $e) {
        $n = isset($e['node']) ? $e['node'] : array();
        if (empty($n['id'])) continue;
        $out[] = array('id' => (string) $n['id'], 'name' => (string) $n['name'], 'type' => isset($n['type']) ? (string) $n['type'] : '');
    }
    update_option('bta_pv_statuses', $out, false);
    return $out;
}

/* ── Building the quote ──────────────────────────────────────────────────── */

/** Normalise a size label so "XXL", "2X" and Printavo's size_2xl all meet. */
function bta_pv_size_key($s) {
    $k = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $s));
    $k = preg_replace('/^size/', '', $k);
    $map = array(
        'xxl' => '2xl', 'xxxl' => '3xl', 'xxxxl' => '4xl', 'xxxxxl' => '5xl', 'xxxxxxl' => '6xl',
        '2x' => '2xl', '3x' => '3xl', '4x' => '4xl', '5x' => '5xl', '6x' => '6xl',
        'small' => 's', 'medium' => 'm', 'large' => 'l', 'xlarge' => 'xl',
        'youthxs' => 'yxs', 'youths' => 'ys', 'youthm' => 'ym', 'youthl' => 'yl', 'youthxl' => 'yxl',
        'os' => 'other', 'osfa' => 'other', 'onesize' => 'other',
    );
    return isset($map[$k]) ? $map[$k] : $k;
}

/**
 * Size breakdown in Printavo's shape: a list of {size enum, count}. Sizes
 * Printavo has no column for go under "other" when it has one, and are named
 * in $extra either way so they reach the line description.
 */
function bta_pv_sizes($sizes, $line_type, &$extra) {
    $extra = array();
    $size_ref = $line_type ? bta_pv_field($line_type, 'sizes') : null;
    $st = ($size_ref && $size_ref['kind'] === 'INPUT_OBJECT') ? bta_pv_type($size_ref['name']) : null;

    // Which field holds the size and which the count, by kind rather than name.
    $enum_field = 'size';
    $count_field = 'count';
    $enum = array();
    if ($st) {
        foreach ($st['fields'] as $fname => $ref) {
            if ($ref['kind'] === 'ENUM') {
                $enum_field = $fname;
                $et = bta_pv_type($ref['name']);
                $enum = $et ? $et['enum'] : array();
            } elseif ($ref['name'] === 'Int') {
                $count_field = $fname;
            }
        }
    }

    $by = array();
    $other = '';
    foreach ($enum as $e) {
        $k = bta_pv_size_key($e);
        $by[$k] = $e;
        if ($k === 'other') $other = $e;
    }

    $out = array();
    foreach ($sizes as $label => $qty) {
        $qty = (int) $qty;
        if ($qty < 1) continue;
        $k = bta_pv_size_key($label);
        if ($enum) {
            if (isset($by[$k]) && $k !== 'other') {
                $out[$by[$k]] = (isset($out[$by[$k]]) ? $out[$by[$k]] : 0) + $qty;
                continue;
            }
            $extra[] = $label . '×' . $qty;
            if ($other !== '') $out[$other] = (isset($out[$other]) ? $out[$other] : 0) + $qty;
        } else {
            $out['size_' . $k] = (isset($out['size_' . $k]) ? $out['size_' . $k] : 0) + $qty;
        }
    }

    $list = array();
    foreach ($out as $e => $q) $list[] = array($enum_field => $e, $count_field => $q);
    return $list;
}

function bta_pv_money($n) {
    return '$' . number_format((float) $n, 2);
}

/** One order line as text, for notes. */
function bta_pv_line_text($it, $artby) {
    $decs  = bta_decorations();
    $emb   = bta_emb_types();
    $sizes = array();
    foreach (bta_item_sizes($it) as $s => $q) if ((int) $q > 0) $sizes[] = $s . '×' . (int) $q;
    $locs = array();
    foreach (bta_item_locations($it) as $l) {
        $a = (int) $l['art_id'];
        $locs[] = $l['placement'] . (!empty($l['emb']) && isset($emb[$l['emb']]) ? ' (' . $emb[$l['emb']] . ')' : '') . ($a && isset($artby[$a]) ? ' — ' . $artby[$a]->label : '');
    }
    return (int) $it->qty . ' × ' . trim($it->style_no . ' ' . $it->brand . ' ' . $it->style_name)
        . ($it->color !== '' ? ', ' . $it->color : '')
        . ($sizes ? ' [' . implode(' ', $sizes) . ']' : '')
        . ' · ' . (isset($decs[$it->decoration]) ? $decs[$it->decoration] : $it->decoration)
        . ($locs ? ': ' . implode('; ', $locs) : '')
        . ($it->unit_price !== null ? ' · ' . bta_pv_money($it->unit_price) . ' ea' : ' · price by shop')
        . ($it->price_note !== '' ? ' (' . $it->price_note . ')' : '')
        . ($it->notes !== '' ? ' · Note: ' . $it->notes : '');
}

function bta_pv_staff_link($o) {
    return home_url('/employees/accounts/' . strtolower($o->order_number));
}

/**
 * The production note: one line, so the quote's own details stay near the
 * top of the page in Printavo. Everything else already shows there: PO,
 * dates, ship-to, items, category, art (production files) and the blanks
 * (item descriptions).
 */
function bta_pv_production_note($o, $account, $items, $art, $artby) {
    return $o->order_number . ' · ' . bta_pv_staff_link($o);
}

/** The blanks line for an order: supplier, their PO, arrival. */
function bta_pv_blanks_text($o) {
    $arr = $o->expected_arrival ? date_i18n('M j, Y', strtotime($o->expected_arrival)) : '';
    return trim($o->supplier_name . ($o->supplier_po !== '' ? ', PO ' . $o->supplier_po : '') . ($arr !== '' ? ', arriving ' . $arr : ''), ', ');
}

function bta_pv_group_payload($it, $artby, $line_type, $pos, $o = null) {
    $extra = array();
    $sizes = bta_pv_sizes(bta_item_sizes($it), $line_type, $extra);

    // Description, laid out the way the shop reads it:
    //   Sport-Tek Hooded Raglan Jacket. JST73
    //
    //   LEFT CHEST:
    //   left chest (Logo embroidery)
    //
    //   Blanks: Sanmar, PO 52446331, arriving Oct 2, 2026
    $emb_names = bta_emb_types();
    $D = array(trim($it->brand . ' ' . $it->style_name));
    if ($extra) $D[] = 'Sizes: ' . implode(', ', $extra);
    foreach (bta_item_locations($it) as $l) {
        $a = (int) $l['art_id'];
        $D[] = strtoupper($l['placement']) . ':' . "\n"
             . ($a && isset($artby[$a]) ? $artby[$a]->label : '(no art picked)')
             . (!empty($l['emb']) && isset($emb_names[$l['emb']]) ? ' (' . $emb_names[$l['emb']] . ' embroidery)' : '');
    }
    if ($o && bta_pv_blanks_text($o) !== '') $D[] = 'Blanks: ' . bta_pv_blanks_text($o);
    if ($it->notes !== '') $D[] = 'Note: ' . $it->notes;
    $desc = implode("\n\n", $D);   // a blank line between each part, as the shop lays it out

    $decs = bta_decorations();
    $deco = isset($decs[$it->decoration]) ? $decs[$it->decoration] : $it->decoration;
    $emb  = bta_emb_types();

    $imprints = array();
    foreach (bta_item_locations($it) as $k => $l) {
        $a = (int) $l['art_id'];
        $text = $deco . ' — ' . $l['placement']
              . (!empty($l['emb']) && isset($emb[$l['emb']]) ? ' (' . $emb[$l['emb']] . ')' : '')
              . ($a && isset($artby[$a]) ? ' — art: ' . $artby[$a]->label . ' ' . $artby[$a]->file_url : '');
        $imprints[] = array('details' => $text, 'description' => $text, 'position' => $k + 1);
    }

    $line = array(
        'itemNumber'  => $it->style_no,
        'description' => $desc,
        'color'       => $it->color,
        'price'       => $it->unit_price !== null ? (float) $it->unit_price : 0.0,
        'sizes'       => $sizes,
        'position'    => 1,
    );
    $cat = bta_pv_category_for($it->decoration);
    if ($cat === '') {
        $GLOBALS['bta_pv_cat_miss'] = true;
    } else {
        $line['category']   = array('id' => $cat);
        $line['categoryId'] = $cat;
        $lt = $line_type ? bta_pv_type($line_type) : null;
        if ($lt) {
            foreach (array_keys($lt['fields']) as $f) {
                if (preg_match('/categor/i', $f)) $line[$f] = array('id' => $cat);
            }
        }
    }

    return array('position' => $pos, 'lineItems' => array($line), 'imprints' => $imprints);
}

/** Where a nested line item sits in the schema: group input type → its line items' input type. */
function bta_pv_nested_types($quote_type) {
    $g = bta_pv_field($quote_type, 'lineItemGroups');
    if (!$g || $g['kind'] !== 'INPUT_OBJECT') return array('', '');
    $l = bta_pv_field($g['name'], 'lineItems');
    return array($g['name'], ($l && $l['kind'] === 'INPUT_OBJECT') ? $l['name'] : '');
}

/**
 * Create the quote. Returns array(id, number, url, warnings[], dropped[]) or
 * WP_Error. Tries the whole order in one call first (quote with its line item
 * groups); if Printavo will not take it that way, makes the bare quote and
 * adds each line separately, so one awkward line cannot sink the rest.
 */
function bta_pv_create_quote($o) {
    $account = bta_get_account($o->account_id);
    if (!$account) return new WP_Error('bta_pv_noacct', 'The order\'s account no longer exists.');

    $who = bta_pv_account_contact($account);
    if (is_wp_error($who)) return $who;

    $items = bta_get_order_items($o->id);
    $art   = bta_get_order_art($o->id);
    $artby = array();
    foreach ($art as $a) $artby[(int) $a->id] = $a;

    $dropped  = array();
    $warnings = array();
    $GLOBALS['bta_pv_cat_miss'] = false;

    // Customer due = in-hands, held to a business day at least a week after
    // submission; production due = the business day before it.
    $tz   = wp_timezone();
    $due  = bta_customer_due($o);
    $prod = bta_production_due($o);
    $due_at = (new DateTime($prod . ' 12:00:00', $tz))->format('c');

    $quote = array(
        'contact'         => array('id' => $who['contact_id']),
        'contactId'       => $who['contact_id'],
        'customerId'      => $who['customer_id'],
        'nickname'        => $o->order_number . ($o->end_customer !== '' ? ' · ' . $o->end_customer : ''),
        'visualPoNumber'  => $o->account_po,
        'poNumber'        => $o->account_po,
        'customerDueAt'   => $due,
        'dueAt'           => $due_at,
        'productionNote'  => bta_pv_production_note($o, $account, $items, $art, $artby),
        'customerNote'    => trim(($o->ship_method !== '' ? 'Ship via: ' . $o->ship_method : '') . "\n" . $o->notes),
        'tags'            => array('#BTAccounts'),   // Printavo: "Tags must start with a #"
        'shippingAddress' => array(
            'customerName' => $o->ship_name,
            'companyName'  => $o->end_customer,
            'address1'     => $o->ship_address1,
            'address2'     => $o->ship_address2,
            'city'         => $o->ship_city,
            'state'        => $o->ship_state,
            'stateIso'     => $o->ship_state,
            'zipCode'      => $o->ship_zip,
            'zip'          => $o->ship_zip,
            'country'      => $o->ship_address1 !== '' ? 'US' : '',
            'countryIso'   => $o->ship_address1 !== '' ? 'US' : '',
        ),
    );
    if (bta_pv_status_id() !== '') $quote['statusId'] = bta_pv_status_id();

    $quote_type = bta_pv_arg_type('quoteCreate', 'input', 'QuoteCreateInput');
    list($group_type, $nested_line) = bta_pv_nested_types($quote_type);
    $q_sel = implode(' ', bta_pv_select('Quote', array('id', 'visualId', 'url'), array('id', 'visualId')));

    // Each way of sending is tried whole first, then without the tag, then
    // without the address, so one field Printavo dislikes costs only itself.
    $ladder = array(
        array(),
        array('tags'),
        array('tags', 'shippingAddress'),
    );
    $left_off = function ($drop) {
        $out = array();
        if (in_array('tags', $drop, true))            $out[] = 'The #BTAccounts tag was left off.';
        if (in_array('shippingAddress', $drop, true)) $out[] = 'The ship-to address was left off the quote; it is in the production note.';
        return $out;
    };

    $q = null;
    $nested = false;
    $first_err = null;
    if ($nested_line !== '' && $items) {
        $groups = array();
        foreach ($items as $i => $it) $groups[] = bta_pv_group_payload($it, $artby, $nested_line, $i + 1, $o);
        foreach ($ladder as $drop) {
            $try = array_diff_key($quote, array_flip($drop));
            $try['lineItemGroups'] = $groups;
            $r = bta_pv_mutate('quoteCreate', array('input' => $try), $q_sel, $dropped);
            if (!is_wp_error($r)) { $q = $r; $nested = true; $warnings = array_merge($warnings, $left_off($drop)); break; }
            if ($first_err === null) $first_err = $r;
        }
        if (!$nested) {
            $warnings[] = 'Sending the items with the quote failed (' . $first_err->get_error_message() . '), so they were added one by one.';
        }
    }

    if (!$nested) {
        foreach ($ladder as $drop) {
            $r = bta_pv_mutate('quoteCreate', array('input' => array_diff_key($quote, array_flip($drop))), $q_sel, $dropped);
            if (!is_wp_error($r)) { $q = $r; $warnings = array_merge($warnings, $left_off($drop)); break; }
            if ($first_err === null) $first_err = $r;
        }
        if ($q === null) return $first_err;
    }

    $qid = isset($q['id']) ? (string) $q['id'] : '';
    if ($qid === '') return new WP_Error('bta_pv_noid', 'Printavo made the quote but did not say its id.');

    // PO and dates: anything quoteCreate would not take is set with
    // quoteUpdate straight after, so the PO and customer due date always land.
    $later = array();
    foreach (array('visualPoNumber' => $o->account_po, 'customerDueAt' => $due, 'dueAt' => $due_at) as $k => $val) {
        if ($val !== '' && in_array('quoteCreate.input.' . $k, $dropped, true)) $later[$k] = $val;
    }
    if ($later) {
        $u = bta_pv_mutate('quoteUpdate', array('id' => $qid, 'input' => $later + array('poNumber' => $o->account_po)), 'id', $dropped);
        if (is_wp_error($u)) {
            $warnings[] = 'The PO and due dates could not be set (' . $u->get_error_message() . '). They are in the production note.';
        } else {
            $dropped = array_values(array_diff($dropped, array_map(function ($k) { return 'quoteCreate.input.' . $k; }, array_keys($later))));
        }
    }

    if (!$nested) {
        $line_type = bta_pv_arg_type('lineItemCreate', 'input', 'LineItemCreateInput');
        foreach ($items as $i => $it) {
            $err = bta_pv_add_group($qid, bta_pv_group_payload($it, $artby, $line_type, $i + 1, $o), $dropped);
            if (is_wp_error($err)) $warnings[] = 'Item ' . ($i + 1) . ' (' . $it->style_no . ') did not go in: ' . $err->get_error_message();
        }
    }

    // The quote's status, when it could not be set in the same call.
    $ignore = array();
    $status_set = in_array('statusId', array_keys(bta_pv_fit(array('statusId' => 'x'), $quote_type, $ignore)), true);
    if (bta_pv_status_id() !== '' && !$status_set) {
        $r = bta_pv_mutate('statusUpdate', array(
            'parentId' => $qid, 'id' => $qid, 'statusId' => bta_pv_status_id(),
            'input'    => array('parentId' => $qid, 'statusId' => bta_pv_status_id()),
        ), 'id', $dropped);
        if (is_wp_error($r)) $warnings[] = 'Could not set the starting status: ' . $r->get_error_message();
    }

    // Art files on the quote, so they show with the order in Printavo.
    foreach ($art as $a) {
        $r = bta_pv_mutate('productionFileCreate', array(
            'parentId' => $qid, 'publicFileUrl' => $a->file_url, 'fileUrl' => $a->file_url, 'url' => $a->file_url,
            'input'    => array('parentId' => $qid, 'publicFileUrl' => $a->file_url, 'fileUrl' => $a->file_url, 'name' => $a->file_name),
        ), 'id', $dropped);
        if (is_wp_error($r)) {
            $warnings[] = 'Art "' . $a->label . '" was not attached (' . $r->get_error_message() . '). Its link is in the production note.';
        }
    }

    foreach ($dropped as $dp) {
        if (preg_match('/lineItems\[\d+\]\.category$|lineItemCreate\.input\.category$/', $dp)) {
            $lt = bta_pv_type($nested ? $nested_line : bta_pv_arg_type('lineItemCreate', 'input', 'LineItemCreateInput'));
            $warnings[] = 'Category left blank: Printavo\'s line items have no category field' . ($lt ? ' (they take: ' . implode(', ', array_keys($lt['fields'])) . ')' : '') . '.';
            break;
        }
    }
    if (!empty($GLOBALS['bta_pv_cat_miss'])) {
        $cats = (array) get_option('bta_pv_categories', array());
        $warnings[] = $cats
            ? 'Category left blank: none of Printavo\'s categories (' . implode(', ', wp_list_pluck($cats, 'name')) . ') matched. Pick them under BT Accounts → Printavo.'
            : 'Category left blank: Printavo did not hand over its category list (' . get_option('bta_pv_cat_error', 'no reason given') . ').';
    }

    $number = isset($q['visualId']) ? (string) $q['visualId'] : '';
    $url    = !empty($q['url']) ? (string) $q['url'] : ($number !== '' ? 'https://www.printavo.com/invoices/' . rawurlencode($number) : '');

    return array(
        'id'       => $qid,
        'number'   => $number,
        'url'      => $url,
        'warnings' => $warnings,
        'dropped'  => bta_pv_real_drops($dropped),
    );
}

/** Add one line to an existing quote: group, then its garment line, then its imprints. */
function bta_pv_add_group($qid, $g, &$dropped) {
    $grp = bta_pv_mutate('lineItemGroupCreate', array(
        'parentId' => $qid, 'quoteId' => $qid, 'orderId' => $qid,
        'input'    => array('position' => $g['position'], 'parentId' => $qid, 'quoteId' => $qid),
    ), 'id', $dropped);
    if (is_wp_error($grp)) return $grp;
    $gid = (string) $grp['id'];

    foreach ($g['lineItems'] as $line) {
        $r = bta_pv_mutate('lineItemCreate', array('lineItemGroupId' => $gid, 'input' => $line + array('lineItemGroupId' => $gid)), 'id', $dropped);
        if (is_wp_error($r)) return $r;
    }
    foreach ($g['imprints'] as $imp) {
        $r = bta_pv_mutate('imprintCreate', array('lineItemGroupId' => $gid, 'input' => $imp + array('lineItemGroupId' => $gid)), 'id', $dropped);
        if (is_wp_error($r)) return $r;
    }
    return true;
}

/* ── Sending an order ────────────────────────────────────────────────────── */

function bta_pv_update_order($order_id, $cols) {
    global $wpdb;
    $wpdb->update(bta_table('orders'), $cols, array('id' => (int) $order_id));
}

/** Append to the order's Printavo log (staff-only; the account never sees it). */
function bta_pv_log($order_id, $text, $by = '') {
    $o = bta_get_order($order_id);
    $log = $o && $o->printavo_log ? json_decode($o->printavo_log, true) : array();
    if (!is_array($log)) $log = array();
    $log[] = array('at' => current_time('mysql'), 'by' => $by, 'text' => $text);
    bta_pv_update_order($order_id, array('printavo_log' => wp_json_encode(array_slice($log, -20))));
}

/**
 * Send one order to Printavo. $again sends even if a quote already exists
 * (it makes a second one). Returns true or WP_Error. The atomic claim stops
 * the background send and a staff click from both creating a quote.
 */
function bta_pv_send($order_id, $again = false, $by = '') {
    global $wpdb;
    $o = bta_get_order($order_id);
    if (!$o) return new WP_Error('bta_no_order', 'That order no longer exists.');
    if ($o->printavo_id !== '' && !$again) return true;
    if (!bta_pv_configured()) return new WP_Error('bta_pv_off', 'Printavo is not connected yet. Add the email and API token under BT Accounts in wp-admin.');

    $now   = current_time('mysql');
    $stale = date('Y-m-d H:i:s', current_time('timestamp') - 5 * MINUTE_IN_SECONDS);
    $claimed = $wpdb->query($wpdb->prepare(
        "UPDATE " . bta_table('orders') . " SET printavo_state = 'sending', printavo_at = %s
         WHERE id = %d AND (printavo_state <> 'sending' OR printavo_at IS NULL OR printavo_at < %s)",
        $now, (int) $o->id, $stale
    ));
    if (!$claimed) return new WP_Error('bta_pv_busy', 'This order is being sent to Printavo right now. Give it a minute.');

    if (function_exists('set_time_limit')) @set_time_limit(120);
    $r = bta_pv_create_quote($o);

    if (is_wp_error($r)) {
        bta_pv_update_order($o->id, array(
            'printavo_state' => 'failed',
            'printavo_error' => $r->get_error_message(),
            'printavo_at'    => current_time('mysql'),
        ));
        bta_pv_log($o->id, 'Not sent: ' . $r->get_error_message(), $by);
        if ($by === '') bta_pv_email_failure($o, $r->get_error_message());
        return $r;
    }

    bta_pv_update_order($o->id, array(
        'printavo_state'  => 'sent',
        'printavo_id'     => $r['id'],
        'printavo_number' => $r['number'],
        'printavo_url'    => $r['url'],
        'printavo_error'  => $r['warnings'] ? implode("\n", $r['warnings']) : '',
        'printavo_at'     => current_time('mysql'),
    ));
    bta_pv_log($o->id, 'Sent as Printavo quote #' . ($r['number'] !== '' ? $r['number'] : $r['id'])
        . ($r['warnings'] ? '. ' . implode(' ', $r['warnings']) : '')
        . ($r['dropped'] ? ' Printavo had no field for: ' . implode(', ', $r['dropped']) . '.' : ''), $by);
    return true;
}

/** Tell the shop when an order did not make it into Printavo by itself. */
function bta_pv_email_failure($o, $why) {
    if (!function_exists('bta_notify_recipients')) return;
    $to = bta_notify_recipients();
    if (!$to) return;
    $html = '<p style="' . bta_mail_p() . '">Order <strong>' . esc_html($o->order_number) . '</strong> was saved, but it did not go into Printavo.</p>'
          . '<p style="' . bta_mail_p() . '"><strong>Printavo said:</strong> ' . esc_html($why) . '</p>'
          . '<p style="' . bta_mail_p() . '">Open the order in the employee portal and press <strong>Try again</strong> once it is sorted, or enter it in Printavo by hand.</p>'
          . bta_mail_button(bta_pv_staff_link($o), 'Open ' . $o->order_number, '#e535ab');
    wp_mail($to, $o->order_number . ' did not go into Printavo', bta_mail_wrap('Not in Printavo yet', null, '#27267e', $html), bta_mail_headers());
}

/* ── Automatic send on submit ────────────────────────────────────────────── */

/**
 * Queue rather than send inline, so Sasha's Submit is never held up by
 * Printavo. WP-Cron picks it up within seconds; if cron is not running, the
 * next time anyone opens the staff screen the stragglers go.
 */
add_action('bta_order_submitted', 'bta_pv_queue_order', 20, 1);
function bta_pv_queue_order($order_id) {
    if (!bta_pv_configured() || !get_option('bta_pv_auto', 1)) return;
    $o = bta_get_order($order_id);
    if (!$o || !bta_pv_account_on(bta_get_account($o->account_id))) return;
    bta_pv_update_order($order_id, array('printavo_state' => 'queued', 'printavo_at' => current_time('mysql')));
    wp_schedule_single_event(time(), 'bta_pv_send_event', array((int) $order_id));
    if (function_exists('spawn_cron')) spawn_cron();
}

add_action('bta_pv_send_event', 'bta_pv_send_event');
function bta_pv_send_event($order_id) {
    $o = bta_get_order($order_id);
    if ($o && $o->printavo_state === 'queued') bta_pv_send($order_id);
}

/** Send anything still queued after a few minutes. Two at most per call. */
function bta_pv_catch_up() {
    global $wpdb;
    if (!bta_pv_configured()) return;
    $cut = date('Y-m-d H:i:s', current_time('timestamp') - 3 * MINUTE_IN_SECONDS);
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM " . bta_table('orders') . " WHERE printavo_state = 'queued' AND printavo_at < %s ORDER BY id ASC LIMIT 2", $cut
    ));
    foreach ((array) $ids as $id) bta_pv_send((int) $id);
}

/* ── Staff screen ────────────────────────────────────────────────────────── */

/** The Printavo part of an order, for the staff screen. */
function bta_pv_order_shape($o) {
    $log = $o->printavo_log ? json_decode($o->printavo_log, true) : array();
    $out = array();
    foreach (array_reverse(is_array($log) ? $log : array()) as $l) {
        $out[] = array(
            'when' => isset($l['at']) ? date_i18n('M j, g:ia', strtotime($l['at'])) : '',
            'by'   => isset($l['by']) ? (string) $l['by'] : '',
            'text' => isset($l['text']) ? (string) $l['text'] : '',
        );
    }
    return array(
        'connected' => bta_pv_configured(),
        'account_on'=> bta_pv_account_on(bta_get_account($o->account_id)),
        'state'     => (string) $o->printavo_state,
        'number'    => (string) $o->printavo_number,
        'url'       => esc_url_raw((string) $o->printavo_url),
        'error'     => (string) $o->printavo_error,
        'when'      => $o->printavo_at ? date_i18n('M j, Y g:ia', strtotime($o->printavo_at)) : '',
        'log'       => array_slice($out, 0, 6),
    );
}

add_action('rest_api_init', function () {
    register_rest_route('bt-accounts/v1', '/staff/orders/(?P<id>\d+)/printavo', array(
        'methods' => 'POST', 'callback' => 'bta_pv_rest_send', 'permission_callback' => 'bta_staff_can',
    ));
    register_rest_route('bt-accounts/v1', '/staff/orders/(?P<id>\d+)/printavo/clear-log', array(
        'methods' => 'POST', 'callback' => 'bta_pv_rest_clear_log', 'permission_callback' => 'bta_staff_can',
    ));
});

/** Empty an order's Printavo history (test runs). The quote link stays. */
function bta_pv_rest_clear_log($request) {
    $o = bta_staff_load($request);
    if (is_wp_error($o)) return $o;
    bta_pv_update_order($o->id, array('printavo_log' => ''));
    return rest_ensure_response(array('ok' => true, 'order' => bta_staff_order_detail(bta_get_order($o->id)), 'statuses' => bta_staff_statuses()));
}

function bta_pv_rest_send($request) {
    $o = bta_staff_load($request);
    if (is_wp_error($o)) return $o;

    $again = (bool) $request->get_param('again');
    if ($o->printavo_id !== '' && !$again) {
        return new WP_Error('bta_pv_dupe', 'This order is already quote #' . $o->printavo_number . ' in Printavo.', array('status' => 409));
    }

    $r = bta_pv_send($o->id, $again, bta_staff_actor());
    $order = bta_staff_order_detail(bta_get_order($o->id));
    if (is_wp_error($r)) {
        return new WP_REST_Response(array('ok' => false, 'message' => $r->get_error_message(), 'order' => $order, 'statuses' => bta_staff_statuses()), 200);
    }
    return rest_ensure_response(array('ok' => true, 'order' => $order, 'statuses' => bta_staff_statuses()));
}

/* ── wp-admin ────────────────────────────────────────────────────────────── */

/** Settings form actions, called from bta_handle_admin_post() after its nonce check. */
function bta_pv_handle_admin_post($action) {
    if ($action === 'save_printavo') {
        $old_email = bta_pv_email();
        update_option('bta_pv_email', sanitize_email(wp_unslash(isset($_POST['pv_email']) ? $_POST['pv_email'] : '')));
        $token = isset($_POST['pv_token']) ? trim(sanitize_text_field(wp_unslash($_POST['pv_token']))) : '';
        if ($token !== '') update_option('bta_pv_token', $token, false);
        if (!empty($_POST['pv_forget_token'])) delete_option('bta_pv_token');
        update_option('bta_pv_status_id', sanitize_text_field(wp_unslash(isset($_POST['pv_status']) ? $_POST['pv_status'] : '')));
        update_option('bta_pv_auto', !empty($_POST['pv_auto']) ? 1 : 0);
        update_option('bta_pv_cat_print', sanitize_text_field(wp_unslash(isset($_POST['pv_cat_print']) ? $_POST['pv_cat_print'] : '')));
        update_option('bta_pv_cat_embroidery', sanitize_text_field(wp_unslash(isset($_POST['pv_cat_embroidery']) ? $_POST['pv_cat_embroidery'] : '')));
        if ($old_email !== bta_pv_email() || $token !== '') bta_pv_forget_schema();
        bta_admin_notice('Printavo settings saved.' . (bta_pv_configured() ? ' Press Test connection to check them.' : ''));
    }

    if ($action === 'test_printavo') {
        bta_pv_forget_schema();
        delete_option('bta_pv_categories');
        delete_option('bta_pv_cat_path');
        $st = bta_pv_fetch_statuses();
        if (is_wp_error($st)) {
            update_option('bta_pv_last_test', array('ok' => 0, 'at' => current_time('mysql'), 'msg' => $st->get_error_message()), false);
            bta_admin_notice($st->get_error_message(), 'error');
            return;
        }
        $schema = bta_pv_type('Mutation') ? 'Printavo described its fields, so only fields it accepts are sent.' : 'Printavo would not describe its fields, so quotes are sent on the documented field names.';
        $cats = bta_pv_fetch_categories();
        $schema .= is_wp_error($cats) ? ' Categories: ' . $cats->get_error_message() : ' ' . count($cats) . ' line item categories found.';
        $qt = bta_pv_type(bta_pv_arg_type('quoteCreate', 'input', 'QuoteCreateInput'));
        if ($qt) $schema .= ' A new quote takes: ' . implode(', ', array_keys($qt['fields'])) . '.';
        update_option('bta_pv_last_test', array('ok' => 1, 'at' => current_time('mysql'), 'msg' => count($st) . ' statuses found. ' . $schema), false);
        bta_admin_notice('Connected to Printavo. ' . count($st) . ' statuses found. ' . $schema);
    }

    if ($action === 'find_printavo_contact') {
        $id = (int) (isset($_POST['account_id']) ? $_POST['account_id'] : 0);
        $account = bta_get_account($id);
        if (!$account) return;
        $name = sanitize_text_field(wp_unslash(isset($_POST['pv_contact']) ? $_POST['pv_contact'] : ''));
        $m = array('name' => $name, 'contact_id' => '', 'customer_id' => '', 'label' => '');
        if ($name !== '') {
            $r = bta_pv_find_contact($name);
            if (is_wp_error($r)) {
                bta_pv_save_account_map($id, $m);
                bta_admin_notice($r->get_error_message(), 'error');
                return;
            }
            $m = array_merge($m, $r);
        }
        bta_pv_save_account_map($id, $m);
        bta_admin_notice($name === '' ? 'Printavo contact cleared.' : 'Linked to ' . $m['label'] . ' in Printavo.');
    }
}

/** Printavo section on the main BT Accounts page. */
function bta_pv_admin_section() {
    $statuses = get_option('bta_pv_statuses', array());
    $test     = get_option('bta_pv_last_test', array());
    $has_tok  = bta_pv_token() !== '';

    echo '<h2 style="margin-top:32px">Printavo</h2>';
    echo '<p class="description" style="max-width:680px">Every order an account submits goes into Printavo as a <strong>quote</strong> on that account&rsquo;s Printavo contact, with the items, sizes, locations, prices, PO, dates, ship-to and art. You review it in Printavo and send it for approval yourself. Needs Printavo&rsquo;s API, which is on the Premium plan: the token is under <em>My Account</em> in Printavo.</p>';

    if ($test) {
        $ok = !empty($test['ok']);
        echo '<p style="font-size:14px"><strong style="color:' . ($ok ? '#1a7f37' : '#b91c1c') . '">' . ($ok ? 'Connected' : 'Not connected') . '</strong> &mdash; '
           . esc_html($test['msg']) . ' <span style="color:#666">(' . esc_html(date_i18n('M j, g:ia', strtotime($test['at']))) . ')</span></p>';
    }

    echo '<form method="post" style="max-width:680px"><table class="form-table">';
    wp_nonce_field('bta_admin');
    echo '<input type="hidden" name="bta_action" value="save_printavo">';
    echo '<tr><th><label for="bta-pv-email">Printavo login email</label></th><td>';
    echo '<input id="bta-pv-email" name="pv_email" class="regular-text" value="' . esc_attr(bta_pv_email()) . '" autocomplete="off"></td></tr>';
    echo '<tr><th><label for="bta-pv-token">API token</label></th><td>';
    echo '<input id="bta-pv-token" name="pv_token" type="password" class="regular-text" value="" autocomplete="new-password" placeholder="' . ($has_tok ? 'Saved — leave blank to keep it' : 'Paste from Printavo → My Account') . '">';
    if ($has_tok) echo '<br><label><input type="checkbox" name="pv_forget_token" value="1"> Remove the saved token</label>';
    echo '</td></tr>';

    echo '<tr><th><label for="bta-pv-status">New quotes land in</label></th><td><select id="bta-pv-status" name="pv_status">';
    echo '<option value="">Printavo&rsquo;s default status</option>';
    foreach ((array) $statuses as $s) {
        $t = $s['type'] !== '' ? ' (' . strtolower($s['type']) . ')' : '';
        echo '<option value="' . esc_attr($s['id']) . '"' . selected(bta_pv_status_id(), $s['id'], false) . '>' . esc_html($s['name'] . $t) . '</option>';
    }
    echo '</select>';
    echo '<p class="description">' . ($statuses ? 'A status of their own, like &ldquo;Portal Order &ndash; Review&rdquo;, makes them easy to spot.' : 'Save the email and token, then Test connection to load your Printavo statuses here.') . '</p></td></tr>';

    $cats = get_option('bta_pv_categories', array());
    foreach (array('print' => 'Print items go under', 'embroidery' => 'Embroidery items go under') as $k => $lab) {
        $cur = (string) get_option('bta_pv_cat_' . $k, '');
        echo '<tr><th><label for="bta-pv-cat-' . $k . '">' . esc_html($lab) . '</label></th><td><select id="bta-pv-cat-' . $k . '" name="pv_cat_' . $k . '">';
        echo '<option value="">Match by name (' . ($k === 'print' ? 'Digi Print' : 'Embroidery') . ')</option>';
        foreach ((array) $cats as $c) {
            echo '<option value="' . esc_attr($c['id']) . '"' . selected($cur, $c['id'], false) . '>' . esc_html($c['name']) . '</option>';
        }
        echo '</select></td></tr>';
    }

    echo '<tr><th>Sending</th><td><label><input type="checkbox" name="pv_auto" value="1"' . checked(get_option('bta_pv_auto', 1), 1, false) . '> Send each order to Printavo the moment it is submitted</label>';
    echo '<p class="description">Off: nothing goes automatically; staff press <em>Send to Printavo</em> on the order in <em>Other &rarr; Accounts</em>.</p></td></tr>';
    echo '</table><p><button class="button button-primary">Save Printavo settings</button></p></form>';

    echo '<form method="post" style="margin-top:-8px">';
    wp_nonce_field('bta_admin');
    echo '<input type="hidden" name="bta_action" value="test_printavo">';
    echo '<button class="button"' . (bta_pv_configured() ? '' : ' disabled') . '>Test connection</button>';
    echo '<span class="description" style="margin-left:10px">Checks the token, loads your statuses, and reads which fields Printavo accepts.</span>';
    echo '</form>';
}

/** Printavo contact box on an account's page. */
function bta_pv_account_section($a) {
    $m = bta_pv_account_map($a);
    echo '<h2 style="margin-top:32px">Printavo</h2>';
    echo '<form method="post" style="max-width:640px"><table class="form-table">';
    wp_nonce_field('bta_admin');
    echo '<input type="hidden" name="bta_action" value="find_printavo_contact">';
    echo '<input type="hidden" name="account_id" value="' . (int) $a->id . '">';
    echo '<tr><th><label for="bta-pv-contact">Printavo contact</label></th><td>';
    echo '<input id="bta-pv-contact" name="pv_contact" class="regular-text" value="' . esc_attr($m['name']) . '" placeholder="Name as it is in Printavo">';
    echo '<p class="description">';
    if ($m['contact_id'] !== '') {
        echo '<span style="color:#1a7f37;font-weight:700">Linked:</span> ' . esc_html($m['label']) . '. Quotes for this account go to them.';
    } elseif ($m['name'] !== '') {
        echo 'Not looked up yet. Press Find, or it is looked up on the first order.';
    } else {
        echo 'Blank: this account&rsquo;s orders stay out of Printavo. Fill in a name to send them there as quotes for that contact.';
    }
    echo '</p></td></tr></table>';
    echo '<p><button class="button"' . (bta_pv_configured() ? '' : ' disabled title="Connect Printavo on the main BT Accounts page first"') . '>Find in Printavo</button></p></form>';
}
