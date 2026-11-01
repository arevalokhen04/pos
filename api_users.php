<?php

require __DIR__ . '/db.php';

$user = require_role('super_admin');
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$input = $method === 'POST' ? json_input() : [];

switch ($action) {

    case 'list':
        $st = db()->query(
            'SELECT u.id, u.username, u.full_name, u.role, u.status, u.phone, u.last_login, u.created_at,
                    (SELECT COUNT(*) FROM sales s WHERE s.cashier_id = u.id) AS total_sales
               FROM users u ORDER BY u.role, u.full_name'
        );
        json_out(['success' => true, 'users' => $st->fetchAll()]);

    case 'create':
        $username = trim($input['username'] ?? '');
        $fullName = trim($input['full_name'] ?? '');
        $password = $input['password'] ?? '';
        $role     = in_array($input['role'] ?? '', ['super_admin', 'admin', 'cashier'], true) ? $input['role'] : 'cashier';
        $phone    = trim($input['phone'] ?? '');

        if ($username === '' || $fullName === '' || strlen($password) < 6) {
            json_out(['success' => false, 'message' => 'Fill up all fields. Password must be at least 6 characters.']);
        }
        $st = db()->prepare('SELECT id FROM users WHERE username = ?');
        $st->execute([$username]);
        if ($st->fetch()) {
            json_out(['success' => false, 'message' => 'Username already exists.']);
        }
        $st = db()->prepare(
            'INSERT INTO users (username, password_hash, full_name, role, phone) VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([$username, password_hash($password, PASSWORD_BCRYPT), $fullName, $role, $phone ?: null]);
        $newId = (int)db()->lastInsertId();

        audit_log($user, 'user_create', 'user', $newId, 'Created user ' . $username . ' (' . $role . ')');
        notify('user:' . $newId, 'info', 'Account created', 'Your ' . $role . ' account was created by ' . $user['full_name'] . '.');
        json_out(['success' => true, 'message' => 'User created successfully.']);

    case 'update':
        $id = (int)($input['id'] ?? 0);
        $fullName = trim($input['full_name'] ?? '');
        $role     = in_array($input['role'] ?? '', ['super_admin', 'admin', 'cashier'], true) ? $input['role'] : 'cashier';
        $phone    = trim($input['phone'] ?? '');
        if (!$id || $fullName === '') {
            json_out(['success' => false, 'message' => 'Missing fields.']);
        }
        if ($id === (int)$user['id'] && $role !== 'super_admin') {
            json_out(['success' => false, 'message' => 'You cannot demote your own super admin account.']);
        }
        $st = db()->prepare('UPDATE users SET full_name = ?, role = ?, phone = ? WHERE id = ?');
        $st->execute([$fullName, $role, $phone ?: null, $id]);
        audit_log($user, 'user_update', 'user', $id, 'Updated user #' . $id . ' (' . $fullName . ')');
        json_out(['success' => true, 'message' => 'User updated.']);

    case 'set_status':
        $id = (int)($input['id'] ?? 0);
        $status = ($input['status'] ?? '') === 'active' ? 'active' : 'inactive';
        if (!$id) {
            json_out(['success' => false, 'message' => 'Missing user id.']);
        }
        if ($id === (int)$user['id']) {
            json_out(['success' => false, 'message' => 'You cannot deactivate your own account.']);
        }
        $st = db()->prepare('UPDATE users SET status = ? WHERE id = ?');
        $st->execute([$status, $id]);
        audit_log($user, 'user_status', 'user', $id, 'Set user #' . $id . ' to ' . $status);

        if ($status === 'active') {
            notify('user:' . $id, 'info', 'Account activated', 'Your account has been activated. You can now log in.', 'Login.php');
        }
        json_out(['success' => true, 'message' => 'User set to ' . $status . '.']);

    case 'reset_password':
        $id = (int)($input['id'] ?? 0);
        $password = $input['password'] ?? '';
        if (!$id || strlen($password) < 6) {
            json_out(['success' => false, 'message' => 'Password must be at least 6 characters.']);
        }
        $st = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $st->execute([password_hash($password, PASSWORD_BCRYPT), $id]);
        audit_log($user, 'user_reset_password', 'user', $id, 'Reset password of user #' . $id);
        json_out(['success' => true, 'message' => 'Password reset.']);

    default:
        json_out(['success' => false, 'message' => 'Unknown action'], 400);
}
