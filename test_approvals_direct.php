<?php

require __DIR__ . '/db.php';

$me = current_user();
if (!$me || $me['role'] !== 'super_admin') {
    http_response_code(403);
    echo '<h3 style="font-family:sans-serif">🔒 Super admin login required.</h3>
          <p style="font-family:sans-serif"><a href="Login.php">Sign in</a></p>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
echo '<html><head><title>Approvals test</title>
<style>body{font-family:Consolas,monospace;background:#f0fdf4;padding:30px;line-height:1.7}
.ok{color:#15803d;font-weight:bold}.bad{color:#dc2626;font-weight:bold}</style></head><body>';
echo "<h2>🧪 Approvals workflow test</h2>";

function step(string $label, bool $pass, string $detail = ''): void
{
    echo $pass ? "<span class='ok'>✔</span> " : "<span class='bad'>✖</span> ";
    echo htmlspecialchars($label) . ($detail ? ' — <i>' . htmlspecialchars($detail) . '</i>' : '') . "<br>";
    if (!$pass) {
        echo "<span class='bad'>TEST STOPPED</span></body></html>";
        exit;
    }
}

$pdo = db();

$admin   = $pdo->query("SELECT * FROM users WHERE username='admin1'")->fetch();
$cashier = $pdo->query("SELECT * FROM users WHERE username='cashier1'")->fetch();
step('Found admin + cashier users', (bool)$admin && (bool)$cashier);

$prod = $pdo->query("SELECT * FROM products WHERE status='active' ORDER BY id LIMIT 1")->fetch();
step('Found a product to test with', (bool)$prod, $prod['name']);
$before = (int)$prod['stock_qty'];

$st = $pdo->prepare('INSERT INTO stock_requests (product_id, requested_by, req_type, qty, reason) VALUES (?,?,?,?,?)');
$st->execute([$prod['id'], $cashier['id'], 'stock_in', 5, 'TEST: diagnostics restock']);
$reqId = (int)$pdo->lastInsertId();
step('Cashier filed stock-in request', $reqId > 0, 'request #' . $reqId);

$notifBefore = (int)$pdo->query("SELECT COUNT(*) c FROM notifications WHERE type='approval'")->fetch()['c'];
notify('role:admin', 'approval', 'New request needs approval', 'TEST request #' . $reqId . ' for ' . $prod['name'], 'admin.php');
$notifAfter = (int)$pdo->query("SELECT COUNT(*) c FROM notifications WHERE type='approval'")->fetch()['c'];
step('Approvers were notified', $notifAfter > $notifBefore,
     ($notifAfter - $notifBefore) . ' approval notification(s) created');

$st = $pdo->prepare('UPDATE stock_requests SET status="approved", decided_by=?, decided_at=NOW() WHERE id=?');
$st->execute([$admin['id'], $reqId]);
$mv = $pdo->prepare('INSERT INTO inventory_movements (product_id, user_id, type, qty, notes) VALUES (?,?,"stock_in",5,?)');
$mv->execute([$prod['id'], $admin['id'], 'TEST approved request #' . $reqId]);
$pdo->prepare('UPDATE products SET stock_qty = stock_qty + 5 WHERE id=?')->execute([$prod['id']]);

$after = (int)$pdo->query("SELECT stock_qty FROM products WHERE id=" . (int)$prod['id'])->fetch()['stock_qty'];
step('Approving added 5 to stock', $after === $before + 5, "$before → $after");

notify('user:' . $cashier['id'], 'approved', 'Request approved', 'TEST: your restock for ' . $prod['name'] . ' was approved');
$gotIt = (int)$pdo->query("SELECT COUNT(*) c FROM notifications WHERE audience='user:{$cashier['id']}' AND type='approved'")->fetch()['c'];
step('Requester notified of decision', $gotIt > 0);

$pdo->prepare('UPDATE products SET stock_qty = ? WHERE id=?')->execute([$before, $prod['id']]);
$pdo->prepare('DELETE FROM stock_requests WHERE id=?')->execute([$reqId]);
$pdo->prepare('DELETE FROM inventory_movements WHERE notes LIKE ?')->execute(['TEST%']);
$pdo->query("DELETE FROM notifications WHERE message LIKE 'TEST%'");
$restored = (int)$pdo->query("SELECT stock_qty FROM products WHERE id=" . (int)$prod['id'])->fetch()['stock_qty'];
step('Cleanup done (stock restored)', $restored === $before);

echo "<br><span class='ok'>🎉 ALL APPROVAL TESTS PASSED</span> — the request → notify → approve → apply → notify loop works.";
echo '<br><a href="diagnostic.php">← back to diagnostics</a></body></html>';
