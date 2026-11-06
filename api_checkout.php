<?php

require __DIR__ . '/db.php';

$user = require_role('cashier', 'super_admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Method not allowed'], 405);
}

/* Discount rules (r4): cashiers may apply ONLY 0% or 5% (Senior Citizen) —
   whitelisted server-side, no supervisor password needed. Anything else is
   rejected, so the old free-form peso discount (and its >10% password gate)
   is gone. */
$input    = json_input();
$items    = $input['items'] ?? [];
$paid     = (float)($input['amount_paid'] ?? 0);
/* cash-only business rule (user decision): every sale is recorded as cash —
   any posted payment_method is ignored */
$method   = 'cash';
$discPct  = (float)($input['discount_pct'] ?? 0);
if (!in_array($discPct, [0.0, 5.0], true)) {
    json_out(['success' => false, 'message' => 'Invalid discount — only 0% or 5% (Senior Citizen) is allowed.'], 400);
}
/* idempotency key (r4: duplicate submission prevention) — one key per checkout attempt */
$idemKey  = trim((string)($input['idempotency_key'] ?? ''));
if ($idemKey !== '') {
    $idemKey = mb_substr($idemKey, 0, 64);
}

if (!$items || !is_array($items)) {
    json_out(['success' => false, 'message' => 'Cart is empty.']);
}

$pdo = db();

/* ── build the standard success payload from a stored sale row ── */
function sale_payload(array $sale): array
{
    $st = db()->prepare(
        'SELECT si.product_name AS name, si.qty, si.unit_price AS price, si.subtotal, p.unit
           FROM sale_items si JOIN products p ON p.id = si.product_id
          WHERE si.sale_id = ? ORDER BY si.id'
    );
    $st->execute([(int)$sale['id']]);
    $items = array_map(static fn($r) => [
        'name'     => $r['name'],
        'qty'      => (int)$r['qty'],
        'price'    => (float)$r['price'],
        'subtotal' => (float)$r['subtotal'],
        'unit'     => $r['unit'],
    ], $st->fetchAll());

    return [
        'id'         => (int)$sale['id'],
        'receipt_no' => $sale['receipt_no'],
        'total'      => (float)$sale['total_amount'],
        'discount'   => (float)$sale['discount'],
        'paid'       => (float)$sale['amount_paid'],
        'change'     => (float)$sale['change_amount'],
        'method'     => $sale['payment_method'],
        'items'      => $items,
        'print_url'  => 'api_print.php?sale_id=' . (int)$sale['id'],
        'created_at' => date('M j, Y g:i A', strtotime($sale['created_at'])),
    ];
}

function find_sale_by_idem(string $key): ?array
{
    $st = db()->prepare('SELECT * FROM sales WHERE idempotency_key = ? LIMIT 1');
    $st->execute([$key]);
    $row = $st->fetch();
    return $row ?: null;
}

/* duplicate submission: if this attempt was already committed, return the
   original sale instead of creating a second one */
if ($idemKey !== '') {
    $existing = find_sale_by_idem($idemKey);
    if ($existing) {
        json_out([
            'success'   => true,
            'message'   => 'Sale already completed — returning the original receipt.',
            'duplicate' => true,
            'sale'      => sale_payload($existing),
        ]);
    }
}

$sessionId = null;
$st = $pdo->prepare('SELECT id FROM cashier_sessions WHERE user_id = ? AND status = "open" ORDER BY id DESC LIMIT 1');
$st->execute([$user['id']]);
if ($sess = $st->fetch()) {
    $sessionId = (int)$sess['id'];
} elseif ($user['role'] === 'cashier') {
        json_out(['success' => false, 'message' => 'Please clock in first before making a sale.', 'clock_in' => true]);
}

$ids = array_map(static fn($i) => (int)($i['product_id'] ?? 0), $items);
$ph  = implode(',', array_fill(0, count($ids), '?'));

$pdo->beginTransaction();
try {
    $st = $pdo->prepare("SELECT id, name, display_qty, selling_price, cost_price FROM products WHERE id IN ($ph) AND status = 'active' FOR UPDATE");
    $st->execute($ids);
    $products = [];
    foreach ($st->fetchAll() as $p) {
        $products[(int)$p['id']] = $p;
    }

    $total     = 0.0;
    $totalCost = 0.0;
    $lines     = [];
    foreach ($items as $i) {
        $pid = (int)($i['product_id'] ?? 0);
        $qty = (int)($i['qty'] ?? 0);
        if (!isset($products[$pid])) {
            throw new RuntimeException('A product in your cart is no longer available.');
        }
        $p = $products[$pid];
        if ($qty <= 0) {
            continue;
        }
        if ($qty > (int)$p['display_qty']) {
            throw new RuntimeException('Not enough display stock for ' . $p['name'] . ' (only ' . $p['display_qty'] . ' left on the shelves).');
        }
        $subtotal   = (float)$p['selling_price'] * $qty;
        $total     += $subtotal;
        $totalCost += (float)$p['cost_price'] * $qty;
        $lines[]    = ['product' => $p, 'qty' => $qty, 'subtotal' => $subtotal];
    }
    if (!$lines) {
        throw new RuntimeException('Cart is empty.');
    }

    /* discount: whitelisted 0% or 5% Senior Citizen — computed server-side
       from the true gross; the cashier applies it directly (no password) */
    $discount = round($total * $discPct / 100, 2);
    $total = round($total - $discount, 2);

    /* cash must cover the total */
    if ($paid < $total) {
        throw new RuntimeException('Amount paid is less than the total.');
    }

    $change    = round($paid - $total, 2);
    $receiptNo = 'MH-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

    $st = $pdo->prepare(
        'INSERT INTO sales (receipt_no, cashier_id, session_id, total_amount, discount, amount_paid, change_amount, payment_method, total_cost, idempotency_key)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    try {
        $st->execute([$receiptNo, $user['id'], $sessionId, $total, $discount, $paid, $change, $method, $totalCost, $idemKey ?: null]);
    } catch (PDOException $dup) {
        /* lost race on the unique idempotency key — the first request won; return its sale */
        if ($idemKey !== '' && $dup->getCode() === '23000' && ($existing = find_sale_by_idem($idemKey))) {
            $pdo->rollBack();
            json_out([
                'success'   => true,
                'message'   => 'Sale already completed — returning the original receipt.',
                'duplicate' => true,
                'sale'      => sale_payload($existing),
            ]);
        }
        throw $dup;
    }
    $saleId = (int)$pdo->lastInsertId();

    $si = $pdo->prepare(
        'INSERT INTO sale_items (sale_id, product_id, product_name, qty, unit_price, unit_cost, subtotal)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $up = $pdo->prepare('UPDATE products SET display_qty = display_qty - ? WHERE id = ?');
    $mv = $pdo->prepare(
        'INSERT INTO inventory_movements (product_id, user_id, type, qty, notes) VALUES (?, ?, "sale", ?, ?)'
    );

    foreach ($lines as $l) {
        $p = $l['product'];
        $si->execute([$saleId, $p['id'], $p['name'], $l['qty'], $p['selling_price'], $p['cost_price'], $l['subtotal']]);
        $up->execute([$l['qty'], $p['id']]);
        $mv->execute([$p['id'], $user['id'], $l['qty'], 'Sale ' . $receiptNo]);
    }
    $pdo->commit();
} catch (RuntimeException $e) {
    $pdo->rollBack();
    json_out(['success' => false, 'message' => $e->getMessage()], 400);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_out(['success' => false, 'message' => 'Checkout failed: ' . $e->getMessage()], 500);
}

foreach ($lines as $l) {
    check_low_stock((int)$l['product']['id']);
}

audit_log($user, 'checkout', 'sale', $saleId, 'Sale ' . $receiptNo . ' total ' . peso($total) . ' (' . count($lines) . ' items, ' . $method . ($discount > 0 ? ', senior citizen 5% discount ' . peso($discount) : '') . ')');

/* automatic receipt printing (r4) — fire-and-forget, never blocks the sale response */
require_once __DIR__ . '/mh_print_raw.php';
mh_print_sale_raw_async($saleId);

$saleRow = find_sale_by_idem($idemKey) ?: null;
json_out([
    'success' => true,
    'message' => 'Sale completed.',
    'sale'    => sale_payload($saleRow ?: [
        'id'             => $saleId,
        'receipt_no'     => $receiptNo,
        'total_amount'   => $total,
        'discount'       => $discount,
        'amount_paid'    => $paid,
        'change_amount'  => $change,
        'payment_method' => $method,
        'created_at'     => date('Y-m-d H:i:s'),
    ]),
]);
