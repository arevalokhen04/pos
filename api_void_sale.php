<?php

require __DIR__ . '/db.php';

/* ── Post-sale void (r4) ─────────────────────────────────────────────
   Money-level reversal: admin/super_admin only, supervisor password
   required, reason required, display stock restored in the same
   transaction, movement + audit + notification written. ─────────── */
$user = require_role('admin', 'super_admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Method not allowed'], 405);
}

$input    = json_input();
$saleId   = (int)($input['sale_id'] ?? 0);
$password = (string)($input['password'] ?? '');
$reason   = trim((string)($input['reason'] ?? ''));

if (!$saleId) {
    json_out(['success' => false, 'message' => 'Sale id is required.']);
}
if ($reason === '') {
    json_out(['success' => false, 'message' => 'A reason is required to void a sale.']);
}

/* supervisor password with lockout (same policy as cart void) */
mh_session_start();
if (!empty($_SESSION['void_lock']) && time() < $_SESSION['void_lock']) {
    json_out(['success' => false, 'message' => 'Too many attempts. Try again in ' . ceil($_SESSION['void_lock'] - time()) . ' seconds.']);
}
if ($password !== VOID_PASSWORD) {
    $_SESSION['void_fails'] = ($_SESSION['void_fails'] ?? 0) + 1;
    if ($_SESSION['void_fails'] >= 5) {
        $_SESSION['void_lock'] = time() + 60;
        $_SESSION['void_fails'] = 0;
    }
    audit_log($user, 'void_denied', 'sale', $saleId, 'Wrong void password on sale void attempt');
    json_out(['success' => false, 'message' => 'Wrong password. Sale not voided.']);
}
unset($_SESSION['void_fails'], $_SESSION['void_lock']);

$pdo = db();
$pdo->beginTransaction();
try {
    $st = $pdo->prepare('SELECT * FROM sales WHERE id = ? FOR UPDATE');
    $st->execute([$saleId]);
    $sale = $st->fetch();
    if (!$sale) {
        throw new RuntimeException('Sale not found.');
    }
    if ($sale['status'] === 'voided') {
        throw new RuntimeException('This sale is already voided.');
    }

    /* restore display stock for every item, atomically with the status flip */
    $items  = $pdo->prepare('SELECT product_id, qty FROM sale_items WHERE sale_id = ?');
    $items->execute([$saleId]);
    $lockP  = $pdo->prepare('SELECT id FROM products WHERE id = ? FOR UPDATE');
    $restock = $pdo->prepare('UPDATE products SET display_qty = display_qty + ? WHERE id = ?');
    $mv     = $pdo->prepare(
        'INSERT INTO inventory_movements (product_id, user_id, type, qty, notes) VALUES (?, ?, "void_return", ?, ?)'
    );
    foreach ($items->fetchAll() as $it) {
        $lockP->execute([(int)$it['product_id']]);
        $restock->execute([(int)$it['qty'], (int)$it['product_id']]);
        $mv->execute([(int)$it['product_id'], $user['id'], (int)$it['qty'], 'Void of ' . $sale['receipt_no']]);
    }

    $up = $pdo->prepare(
        'UPDATE sales SET status = "voided", voided_by = ?, voided_at = NOW(), void_reason = ? WHERE id = ?'
    );
    $up->execute([$user['id'], mb_substr($reason, 0, 255), $saleId]);
    $pdo->commit();
} catch (RuntimeException $e) {
    $pdo->rollBack();
    json_out(['success' => false, 'message' => $e->getMessage()], 400);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_out(['success' => false, 'message' => 'Void failed: ' . $e->getMessage()], 500);
}

audit_log($user, 'sale_void', 'sale', $saleId, 'Voided ' . $sale['receipt_no'] . ' (' . peso((float)$sale['total_amount']) . ') — ' . $reason);
notify('role:admin', 'sale_void', 'Sale voided', $user['full_name'] . ' voided ' . $sale['receipt_no'] . ': ' . $reason, 'reports.php');

json_out(['success' => true, 'message' => 'Sale ' . $sale['receipt_no'] . ' voided. Stock restored.']);
