<?php

require __DIR__ . '/db.php';

/* ── Post-sale void (r4) ─────────────────────────────────────────────
   Money-level reversal, two modes:
   • mode=sale (default): void the WHOLE sale — admin/super_admin may
     void any sale, a cashier their OWN sale only.
   • mode=items: PARTIAL void — tick specific receipt lines; those items
     return to display stock, the receipt total is recalculated and the
     reprint shows only what actually stayed sold. If every line is
     voided the whole sale flips to "voided" automatically.
   Both: supervisor password, required reason, atomic transaction,
   movement + audit + notification written. ───────────────────────── */
$user = require_login();

/* item list for the void-items dialog */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['action'] ?? '') === 'items') {
    $saleId = (int)($_GET['sale_id'] ?? 0);
    $st = db()->prepare('SELECT cashier_id, status, receipt_no FROM sales WHERE id = ?');
    $st->execute([$saleId]);
    $s0 = $st->fetch();
    if (!$s0) {
        json_out(['success' => false, 'message' => 'Receipt not found.']);
    }
    if ($user['role'] === 'cashier' && (int)$s0['cashier_id'] !== (int)$user['id']) {
        json_out(['success' => false, 'message' => 'Cashiers can only void their own sales.'], 403);
    }
    if ($s0['status'] === 'voided') {
        json_out(['success' => false, 'message' => 'This sale is already voided.']);
    }
    $it = db()->prepare(
        'SELECT si.id, si.product_name, si.qty, p.unit, si.unit_price, si.subtotal
           FROM sale_items si JOIN products p ON p.id = si.product_id
          WHERE si.sale_id = ? ORDER BY si.id'
    );
    $it->execute([$saleId]);
    json_out(['success' => true, 'receipt_no' => $s0['receipt_no'], 'items' => $it->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Method not allowed'], 405);
}

$input    = json_input();
$mode     = ($input['mode'] ?? 'sale') === 'items' ? 'items' : 'sale';
$saleId   = (int)($input['sale_id'] ?? 0);
$password = (string)($input['password'] ?? '');
$reason   = trim((string)($input['reason'] ?? ''));

if (!$saleId) {
    json_out(['success' => false, 'message' => 'Sale id is required.']);
}
if ($reason === '') {
    json_out(['success' => false, 'message' => 'A reason is required to void a sale.']);
}

/* ownership rule: cashiers may only void sales they rang up themselves */
$own = db()->prepare('SELECT cashier_id FROM sales WHERE id = ?');
$own->execute([$saleId]);
$s0 = $own->fetch();
if (!$s0) {
    json_out(['success' => false, 'message' => 'Receipt not found.']);
}
if ($user['role'] === 'cashier' && (int)$s0['cashier_id'] !== (int)$user['id']) {
    json_out(['success' => false, 'message' => 'Cashiers can only void their own sales.'], 403);
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

    $lockP   = $pdo->prepare('SELECT id FROM products WHERE id = ? FOR UPDATE');
    $restock = $pdo->prepare('UPDATE products SET display_qty = display_qty + ? WHERE id = ?');
    $mv      = $pdo->prepare(
        'INSERT INTO inventory_movements (product_id, user_id, type, qty, notes) VALUES (?, ?, "void_return", ?, ?)'
    );

    $fullVoid = true;
    if ($mode === 'items') {
        /* partial void: only the ticked receipt lines are reversed */
        $itemIds = array_values(array_unique(array_filter(array_map('intval', (array)($input['items'] ?? [])))));
        if (!$itemIds) {
            throw new RuntimeException('Select at least one item to void.');
        }
        $ph = implode(',', array_fill(0, count($itemIds), '?'));
        $si = $pdo->prepare(
            "SELECT si.id, si.product_id, si.qty, si.subtotal FROM sale_items si
              WHERE si.sale_id = ? AND si.id IN ($ph) FOR UPDATE"
        );
        $si->execute(array_merge([$saleId], $itemIds));
        $sel = $si->fetchAll();
        if (count($sel) !== count($itemIds)) {
            throw new RuntimeException('One of the selected items no longer exists on this receipt.');
        }
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM sale_items WHERE sale_id = ?');
        $cnt->execute([$saleId]);
        $allLines = (int)$cnt->fetchColumn();
        $fullVoid = $allLines <= count($sel);

        $del = $pdo->prepare('DELETE FROM sale_items WHERE id = ?');
        $voidedAmount = 0.0;
        foreach ($sel as $it) {
            $lockP->execute([(int)$it['product_id']]);
            $restock->execute([(int)$it['qty'], (int)$it['product_id']]);
            $mv->execute([(int)$it['product_id'], $user['id'], (int)$it['qty'], 'Partial void of ' . $sale['receipt_no']]);
            $del->execute([(int)$it['id']]);
            $voidedAmount += (float)$it['subtotal'];
        }

        if (!$fullVoid) {
            /* recompute the receipt: remaining items minus the recorded discount */
            $sum = $pdo->prepare('SELECT COALESCE(SUM(subtotal), 0) FROM sale_items WHERE sale_id = ?');
            $sum->execute([$saleId]);
            $newTotal  = max(0, round((float)$sum->fetchColumn() - (float)$sale['discount'], 2));
            $newChange = round((float)$sale['amount_paid'] - $newTotal, 2);
            $upd = $pdo->prepare('UPDATE sales SET total_amount = ?, change_amount = ? WHERE id = ?');
            $upd->execute([$newTotal, $newChange, $saleId]);
        }
    } else {
        /* full void: restore display stock for every item */
        $items = $pdo->prepare('SELECT product_id, qty FROM sale_items WHERE sale_id = ?');
        $items->execute([$saleId]);
        foreach ($items->fetchAll() as $it) {
            $lockP->execute([(int)$it['product_id']]);
            $restock->execute([(int)$it['qty'], (int)$it['product_id']]);
            $mv->execute([(int)$it['product_id'], $user['id'], (int)$it['qty'], 'Void of ' . $sale['receipt_no']]);
        }
    }

    if ($fullVoid) {
        $up = $pdo->prepare(
            'UPDATE sales SET status = "voided", voided_by = ?, voided_at = NOW(), void_reason = ? WHERE id = ?'
        );
        $up->execute([$user['id'], mb_substr($reason, 0, 255), $saleId]);
    }
    $pdo->commit();
} catch (RuntimeException $e) {
    $pdo->rollBack();
    json_out(['success' => false, 'message' => $e->getMessage()], 400);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_out(['success' => false, 'message' => 'Void failed: ' . $e->getMessage()], 500);
}

if ($mode === 'items' && empty($fullVoid)) {
    audit_log($user, 'sale_void_partial', 'sale', $saleId, 'Partial void of ' . $sale['receipt_no'] . ' (' . peso($voidedAmount) . ') — ' . $reason);
    notify('role:admin', 'sale_void', 'Items voided', $user['full_name'] . ' voided items from ' . $sale['receipt_no'] . ': ' . $reason, 'reports.php');
    json_out(['success' => true, 'message' => 'Items voided from ' . $sale['receipt_no'] . ' — stock returned and receipt total updated.']);
}

audit_log($user, 'sale_void', 'sale', $saleId, 'Voided ' . $sale['receipt_no'] . ' (' . peso((float)$sale['total_amount']) . ') — ' . $reason);
notify('role:admin', 'sale_void', 'Sale voided', $user['full_name'] . ' voided ' . $sale['receipt_no'] . ': ' . $reason, 'reports.php');

json_out(['success' => true, 'message' => 'Sale ' . $sale['receipt_no'] . ' voided. Stock restored.']);
