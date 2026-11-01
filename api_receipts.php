<?php

require __DIR__ . '/db.php';

$user = require_login();

if (($_GET['action'] ?? '') !== 'history') {
    json_out(['success' => false, 'message' => 'Unknown action'], 400);
}

$pdo = db();

$st = $pdo->prepare('SELECT id FROM cashier_sessions WHERE user_id = ? AND status = "open" ORDER BY id DESC LIMIT 1');
$st->execute([$user['id']]);
$session = $st->fetch();

$cols = 's.id, s.receipt_no, s.created_at, s.total_amount, s.payment_method, s.discount,
         (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) AS items';

if ($session) {
    $st = $pdo->prepare("SELECT $cols FROM sales s WHERE s.session_id = ? ORDER BY s.id DESC LIMIT 200");
    $st->execute([$session['id']]);
    $scope = 'shift';
} else {
    
    $st = $pdo->prepare("SELECT $cols FROM sales s WHERE s.cashier_id = ? AND s.created_at >= CURDATE() ORDER BY s.id DESC LIMIT 200");
    $st->execute([$user['id']]);
    $scope = 'today';
}

$sales = $st->fetchAll();
$total = 0.0;
foreach ($sales as $s) {
    $total += (float)$s['total_amount'];
    $s['items'] = (int)$s['items'];
}

json_out([
    'success' => true,
    'scope'   => $scope,          
    'count'   => count($sales),
    'total'   => round($total, 2),
    'sales'   => $sales,
]);
