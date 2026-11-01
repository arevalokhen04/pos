<?php

require __DIR__ . '/db.php';

$user   = require_login();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

function my_audiences(array $user): array
{
    return ['user:' . $user['id'], 'role:' . $user['role']];
}

if ($method === 'GET') {
    switch ($action) {

        case 'unread_count':
            $st = db()->prepare(
                'SELECT COUNT(*) c FROM notifications
                  WHERE is_read = 0 AND audience IN (?, ?)'
            );
            $st->execute(my_audiences($user));
            json_out(['success' => true, 'count' => (int)$st->fetch()['c']]);

        case 'list':
            $st = db()->prepare(
                'SELECT * FROM notifications
                  WHERE audience IN (?, ?)
                  ORDER BY id DESC LIMIT 50'
            );
            $st->execute(my_audiences($user));
            json_out(['success' => true, 'notifications' => $st->fetchAll()]);

        default:
            json_out(['success' => false, 'message' => 'Unknown action'], 400);
    }
}

$input = json_input();

switch ($action) {
    case 'mark_read':
        $id = (int)($input['id'] ?? 0);
        $st = db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND audience IN (?, ?)');
        $st->execute([$id, ...my_audiences($user)]);
        json_out(['success' => true]);

    case 'mark_all_read':
        $st = db()->prepare('UPDATE notifications SET is_read = 1 WHERE audience IN (?, ?)');
        $st->execute(my_audiences($user));
        json_out(['success' => true, 'message' => 'All notifications marked as read.']);

    case 'clear':
        $st = db()->prepare('DELETE FROM notifications WHERE is_read = 1 AND audience IN (?, ?)');
        $st->execute(my_audiences($user));
        json_out(['success' => true, 'message' => 'Read notifications cleared.']);

    default:
        json_out(['success' => false, 'message' => 'Unknown action'], 400);
}
