<?php

require __DIR__ . '/db.php';

$user = require_role('admin', 'super_admin');

if (($_GET['action'] ?? '') !== 'list') {
    json_out(['success' => false, 'message' => 'Unknown action'], 400);
}

$search = trim($_GET['search'] ?? '');
$limit  = min(500, max(20, (int)($_GET['limit'] ?? 200)));

$sql = 'SELECT a.*, u2.role AS user_role
          FROM audit_logs a
          LEFT JOIN users u2 ON u2.id = a.user_id
         WHERE 1';
$params = [];
if ($search !== '') {
    $sql .= ' AND (a.user_name LIKE ? OR a.action LIKE ? OR a.details LIKE ? OR a.entity LIKE ?)';
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
}
$sql .= ' ORDER BY a.id DESC LIMIT ' . $limit;

$st = db()->prepare($sql);
$st->execute($params);
json_out(['success' => true, 'logs' => $st->fetchAll()]);
