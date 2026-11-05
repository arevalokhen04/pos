<?php

require __DIR__ . '/db.php';

$user = require_login();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$input = $method === 'POST' ? json_input() : [];

switch ($action) {

    case 'status':
        $st = db()->prepare(
            'SELECT id, time_in, starting_cash FROM cashier_sessions
              WHERE user_id = ? AND status = "open" ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$user['id']]);
        $open = $st->fetch();
        json_out(['success' => true, 'open_session' => $open ?: null]);

    case 'clock_in':
        if ($user['role'] !== 'cashier') {
            json_out(['success' => false, 'message' => 'Only cashiers can clock in.'], 403);
        }
        $st = db()->prepare('SELECT id FROM cashier_sessions WHERE user_id = ? AND status = "open"');
        $st->execute([$user['id']]);
        if ($st->fetch()) {
            json_out(['success' => false, 'message' => 'You are already clocked in.']);
        }
        $startingCash = max(0.0, (float)($input['starting_cash'] ?? 0));

        $st = db()->prepare(
            'INSERT INTO cashier_sessions (user_id, time_in, starting_cash) VALUES (?, NOW(), ?)'
        );
        $st->execute([$user['id'], $startingCash]);
        audit_log($user, 'clock_in', 'session', (int)db()->lastInsertId(), 'Clock in with starting cash ' . peso($startingCash));
        json_out(['success' => true, 'message' => 'Clocked in at ' . date('g:i A')]);

    case 'clock_out':
        $st = db()->prepare('SELECT id, starting_cash FROM cashier_sessions WHERE user_id = ? AND status = "open" ORDER BY id DESC LIMIT 1');
        $st->execute([$user['id']]);
        $sess = $st->fetch();
        if (!$sess) {
            json_out(['success' => false, 'message' => 'No open session to clock out.']);
        }
        
        /* ending cash counts completed sales only — voided sales are reversals */
        $ss = db()->prepare('SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE session_id = ? AND status = "completed"');
        $ss->execute([(int)$sess['id']]);
        $endingCash = round((float)$sess['starting_cash'] + (float)$ss->fetchColumn(), 2);
        $st = db()->prepare(
            'UPDATE cashier_sessions SET time_out = NOW(), ending_cash = ?, status = "closed" WHERE id = ?'
        );
        $st->execute([$endingCash, $sess['id']]);
        audit_log($user, 'clock_out', 'session', (int)$sess['id'], 'Clock out with ending cash ' . peso($endingCash));
        json_out(['success' => true, 'message' => 'Clocked out at ' . date('g:i A')]);

    case 'void_log':
        /* audit trail for successful voids and cart clears (r10: audit events for voids) */
        $kind  = ($input['kind'] ?? '') === 'clear' ? 'cart_clear' : 'item_void';
        $items = trim((string)($input['items'] ?? ''));
        if ($items !== '') {
            audit_log($user, $kind, 'sale', null, mb_substr($items, 0, 255));
        }
        json_out(['success' => true]);

    case 'void_auth':
        
        mh_session_start();
        if (!empty($_SESSION['void_lock']) && time() < $_SESSION['void_lock']) {
            json_out(['success' => false, 'message' => 'Too many attempts. Try again in ' . ceil($_SESSION['void_lock'] - time()) . ' seconds.']);
        }
        if (($input['password'] ?? '') !== VOID_PASSWORD) {
            $_SESSION['void_fails'] = ($_SESSION['void_fails'] ?? 0) + 1;
            if ($_SESSION['void_fails'] >= 5) {
                $_SESSION['void_lock'] = time() + 60;
                $_SESSION['void_fails'] = 0;
            }
            audit_log($user, 'void_denied', 'auth', null, 'Wrong void password attempt');
            json_out(['success' => false, 'message' => 'Wrong password. Void not authorized.']);
        }
        unset($_SESSION['void_fails'], $_SESSION['void_lock']);
        json_out(['success' => true]);

    default:
        json_out(['success' => false, 'message' => 'Unknown action'], 400);
}
