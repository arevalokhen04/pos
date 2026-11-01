<?php

require __DIR__ . '/db.php';


$user = require_role('cashier', 'super_admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Method not allowed'], 405);
}

$input   = json_input();
$items   = $input['items'] ?? [];
$paid    = (float)($input['amount_paid'] ?? 0);
$discount= max(0.0, (float)($input['discount'] ?? 0));
$method  = 'cash';   

if (!$items || !is_array($items)) {
    json_out(['success' => false, 'message' => 'Cart is empty.']);
}

$pdo = db();


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

    $total    = 0.0;
    $totalCost = 0.0;
    $lines    = [];
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
        $subtotal  = (float)$p['selling_price'] * $qty;
        $total    += $subtotal;
        $totalCost += (float)$p['cost_price'] * $qty;
        $lines[]   = ['product' => $p, 'qty' => $qty, 'subtotal' => $subtotal];
    }
    if (!$lines) {
        throw new RuntimeException('Cart is empty.');
    }

    
    $discount = min($discount, $total);
    $total = round($total - $discount, 2);
    if ($paid < $total) {
        throw new RuntimeException('Amount paid is less than the total.');
    }

    $change = round($paid - $total, 2);
    $receiptNo = 'MH-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

    $st = $pdo->prepare(
        'INSERT INTO sales (receipt_no, cashier_id, session_id, total_amount, discount, amount_paid, change_amount, payment_method, total_cost)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([$receiptNo, $user['id'], $sessionId, $total, $discount, $paid, $change, $method, $totalCost]);
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

audit_log($user, 'checkout', 'sale', $saleId, 'Sale ' . $receiptNo . ' total ' . peso($total) . ' (' . count($lines) . ' items)');

json_out([
    'success' => true,
    'message' => 'Sale completed.',
    'sale' => [
        'id'          => $saleId,
        'receipt_no'  => $receiptNo,
        'total'       => $total,
        'discount'    => $discount,
        'paid'        => $paid,
        'change'      => $change,
        'method'      => $method,
        'items'       => array_map(static fn($l) => [
            'name' => $l['product']['name'], 'qty' => $l['qty'],
            'price' => (float)$l['product']['selling_price'], 'subtotal' => $l['subtotal'],
        ], $lines),
        'print_url'   => 'api_print.php?sale_id=' . $saleId,
        'created_at'  => date('M j, Y g:i A'),
    ],
]);
