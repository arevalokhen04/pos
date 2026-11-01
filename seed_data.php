<?php
/**
 * MARINA'S HARDWARE POS - Demo data generator
 * Creates ~2 months of random sales (with realistic hardware-store
 * purchasing patterns) so the Reports page has data to show.
 * Safe to run multiple times: clears old generated sales first.
 *
 * ★ PROTECTED: super admin login + ?confirm=yes required — this page
 *   TRUNCATES all sales when run. Never expose it unprotected online.
 */
require __DIR__ . '/db.php';

$me = current_user();
if (!$me || $me['role'] !== 'super_admin') {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body style="font-family:Segoe UI,Arial,sans-serif;background:#f0fdf4;display:grid;place-items:center;height:100vh;margin:0">
    <div style="text-align:center"><h2 style="color:#dc2626">🔒 Super admin access required</h2>
    <p>This tool wipes and regenerates ALL sales data — it is locked to the super admin.</p>
    <a href="Login.php" style="color:#15803d">Sign in as super admin</a></div></body></html>';
    exit;
}
if (($_GET['confirm'] ?? '') !== 'yes') {
    echo '<!DOCTYPE html><html><body style="font-family:Segoe UI,Arial,sans-serif;background:#f0fdf4;display:grid;place-items:center;height:100vh;margin:0">
    <div style="text-align:center;background:#fff;border:1.5px solid #fecaca;border-radius:16px;padding:34px 44px;max-width:460px">
    <h2 style="color:#dc2626;margin-bottom:10px">⚠️ This will wipe all sales</h2>
    <p style="color:#52636f;line-height:1.6">Demo data generation deletes every existing sale and creates about
    2 months of random sample transactions instead. Only do this on a fresh setup.</p>
    <a href="seed_data.php?confirm=yes" style="display:inline-block;margin-top:14px;background:#dc2626;color:#fff;text-decoration:none;font-weight:700;padding:11px 22px;border-radius:10px">Yes, wipe &amp; generate demo data</a><br><br>
    <a href="admin.php" style="color:#52636f">Cancel, go back</a></div></body></html>';
    exit;
}

$pdo = db();
$days = 60;

/* cashiers + products */
$cashiers = $pdo->query("SELECT id FROM users WHERE role='cashier' AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
$products = $pdo->query("SELECT id, selling_price, cost_price, name FROM products WHERE status='active' ORDER BY RAND()")->fetchAll();
if (!$cashiers || !$products) {
    die('<h3 style="font-family:sans-serif">Need at least one active cashier and one active product. Import posdatabase.sql first.</h3>');
}

/* wipe previously generated demo sales */
$pdo->query('SET FOREIGN_KEY_CHECKS=0');
$pdo->query('TRUNCATE sale_items');
$pdo->query('TRUNCATE sales');
$pdo->query('DELETE FROM inventory_movements WHERE type="sale"');
$pdo->query('SET FOREIGN_KEY_CHECKS=1');

/* weights: some products sell much more than others */
$weights = [];
foreach ($products as $i => $p) {
    $weights[] = pow(1.18, count($products) - $i); // earlier (random) products weigh more
}
$totalW = array_sum($weights);

function pick_product(array $products, array $weights, float $totalW): array
{
    $r = mt_rand() / mt_getrandmax() * $totalW;
    foreach ($products as $i => $p) {
        $r -= $weights[$i];
        if ($r <= 0) return $p;
    }
    return $products[0];
}

$insSale = $pdo->prepare(
    'INSERT INTO sales (receipt_no, cashier_id, total_amount, discount, amount_paid, change_amount, payment_method, total_cost, created_at)
     VALUES (?,?,?,?,?,?,?,?,?)'
);
$insItem = $pdo->prepare(
    'INSERT INTO sale_items (sale_id, product_id, product_name, qty, unit_price, unit_cost, subtotal)
     VALUES (?,?,?,?,?,?,?)'
);
$insMove = $pdo->prepare(
    'INSERT INTO inventory_movements (product_id, user_id, type, qty, notes, created_at)
     VALUES (?,?,"sale",?,?,?)'
);

$count = 0;
for ($d = $days - 1; $d >= 0; $d--) {
    $date = new DateTime("-$d days");
    $dow  = (int)$date->format('N');          // 1=Mon..7=Sun
    /* weekends + month-ends (13th-month bonus season) are busier */
    $txn  = random_int($dow >= 6 ? 9 : 4, $dow >= 6 ? 18 : 11);
    if ((int)$date->format('d') >= 27 || (int)$date->format('d') <= 3) $txn += random_int(2, 6);
    if (random_int(1, 20) === 1) $txn = random_int(1, 3);   // slow day

    for ($t = 0; $t < $txn; $t++) {
        $cashierId = $cashiers[array_rand($cashiers)];
        $hour = random_int(8, 19);
        $min  = random_int(0, 59);
        $sec  = random_int(0, 59);
        $when = $date->format('Y-m-d') . sprintf(' %02d:%02d:%02d', $hour, $min, $sec);

        $nItems = random_int(1, 4);
        $lines = [];
        $total = 0.0; $cost = 0.0;
        for ($k = 0; $k < $nItems; $k++) {
            $p = pick_product($products, $weights, $totalW);
            if (isset($lines[$p['id']])) continue;
            $qty = random_int(1, 5);
            $sub = (float)$p['selling_price'] * $qty;
            $lines[$p['id']] = ['p' => $p, 'qty' => $qty, 'sub' => $sub];
            $total += $sub;
            $cost  += (float)$p['cost_price'] * $qty;
        }
        if (!$lines) continue;

        $discount = (random_int(1, 12) === 1) ? round($total * 0.05, 2) : 0.0;
        $total = round($total - $discount, 2);
        $method = random_int(1, 6) === 1 ? 'gcash' : 'cash';
        $paid = $method === 'gcash' ? $total : (random_int(0, 1) ? $total : (ceil($total / 100) * 100));
        $paid = round($paid + ($method === 'cash' && $paid == $total && random_int(0, 1) ? random_int(1, 3) * 10 : 0), 2);
        $change = round($paid - $total, 2);

        $receipt = 'MH-' . $date->format('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 5));
        $insSale->execute([$receipt, $cashierId, $total, $discount, $paid, $change, $method, round($cost, 2), $when]);
        $saleId = (int)$pdo->lastInsertId();

        foreach ($lines as $lid => $l) {
            $insItem->execute([$saleId, $lid, $l['p']['name'], $l['qty'], $l['p']['selling_price'], $l['p']['cost_price'], $l['sub']]);
            $insMove->execute([$lid, $cashierId, $l['qty'], 'Sale ' . $receipt, $when]);
        }
        $count++;
    }
}

/* a couple of pending requests + matching notifications so the workflow is visible */
$pdo->query("DELETE FROM stock_requests WHERE reason LIKE 'DEMO%'");
$demoCashier = (int)$cashiers[0];
foreach ($products as $i => $p) {
    if ($i >= 2) break;
    $pdo->prepare('INSERT INTO stock_requests (product_id, requested_by, req_type, qty, reason) VALUES (?,?,?,?,?)')
        ->execute([$p['id'], $demoCashier, 'stock_in', random_int(10, 50), 'DEMO: weekly replenishment']);
}
notify('role:admin', 'approval', 'New request needs approval',
    'Demo cashier filed stock-in requests (demo data). Check the Approvals page.', 'admin.php');

$sum = $pdo->query('SELECT COUNT(*) n, COALESCE(SUM(total_amount),0) t FROM sales')->fetch();

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Seed data</title>
<style>body{font-family:Segoe UI,Arial,sans-serif;background:#f0fdf4;display:grid;place-items:center;height:100vh;margin:0}
.c{background:#fff;border:1px solid #dcfce7;border-radius:18px;padding:38px 46px;text-align:center;box-shadow:0 20px 40px -20px rgba(20,83,45,.25)}
h1{color:#14532d}.n{font-size:34px;font-weight:800;color:#16a34a}
a{display:inline-block;margin-top:14px;background:#16a34a;color:#fff;text-decoration:none;font-weight:700;padding:11px 20px;border-radius:10px}</style>
</head><body><div class="c">
<h1>🎲 Demo data generated</h1>
<div class="n">' . (int)$sum['n'] . '</div> sales over the last ' . $days . ' days<br>
Total revenue: <b>₱' . number_format((float)$sum['t'], 2) . '</b><br><br>
Also created 2 pending stock requests + notifications.<br>
<a href="admin.php">Open Admin dashboard →</a>
<a href="diagnostic.php" style="background:#fff;color:#15803d;border:1.5px solid #bbf7d0">Diagnostics</a>
</div></body></html>';
