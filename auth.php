<?php

require __DIR__ . '/db.php';

mh_session_start();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && $action === 'me') {
    $user = current_user();
    json_out(['success' => true, 'user' => $user]);
}

if ($method !== 'POST') {
    json_out(['success' => false, 'message' => 'Method not allowed'], 405);
}

$input = json_input();
if (empty($_POST['action'])) {
    $_POST = $input;
}
$action = $action ?: ($_POST['action'] ?? '');

switch ($action) {

    
    case 'login':
        
        if (!empty($_SESSION['login_lock']) && time() < $_SESSION['login_lock']) {
            json_out(['success' => false, 'message' => 'Too many failed attempts. Try again in ' . ceil($_SESSION['login_lock'] - time()) . ' seconds.']);
        }

        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';

        if ($username === '' || $password === '') {
            json_out(['success' => false, 'message' => 'Please enter username and password.']);
        }

        $st = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $st->execute([$username]);
        $user = $st->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $_SESSION['login_fails'] = ($_SESSION['login_fails'] ?? 0) + 1;
            if ($_SESSION['login_fails'] >= 5) {
                $_SESSION['login_lock'] = time() + 30;
                $_SESSION['login_fails'] = 0;
            }
            audit_log(['username' => $username], 'login_failed', 'user', null, 'Failed login for ' . $username);
            json_out(['success' => false, 'message' => 'Invalid username or password.']);
        }
        if ($user['status'] !== 'active') {
            json_out(['success' => false, 'message' => 'Your account is deactivated. Contact the administrator.']);
        }

        unset($_SESSION['login_fails'], $_SESSION['login_lock']);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];

        $up = db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
        $up->execute([$user['id']]);

        audit_log($user, 'login', 'user', (int)$user['id'], $user['full_name'] . ' logged in');

        $home = match ($user['role']) {
            'cashier'     => 'cashier.php',
            'super_admin' => 'super_admin.php',
            default       => 'admin.php',
        };

        json_out([
            'success' => true,
            'message' => 'Welcome back, ' . $user['full_name'] . '!',
            'redirect' => $home,
            'user' => [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'full_name' => $user['full_name'],
                'role' => $user['role'],
            ],
        ]);

    
    case 'logout':
        $user = current_user();
        if ($user) {
            audit_log($user, 'logout', 'user', (int)$user['id'], $user['full_name'] . ' logged out');
        }
        session_unset();
        session_destroy();
        json_out(['success' => true, 'message' => 'Logged out successfully.']);

    
    case 'register':
        $username = trim($input['username'] ?? '');
        $fullName = trim($input['full_name'] ?? '');
        $password = $input['password'] ?? '';
        $role     = in_array($input['role'] ?? '', ['admin', 'cashier'], true) ? $input['role'] : 'cashier';

        if ($username === '' || $fullName === '' || strlen($password) < 6) {
            json_out(['success' => false, 'message' => 'Fill up all fields. Password must be at least 6 characters.']);
        }

        $st = db()->prepare('SELECT id FROM users WHERE username = ?');
        $st->execute([$username]);
        if ($st->fetch()) {
            json_out(['success' => false, 'message' => 'Username is already taken.']);
        }

        $st = db()->prepare(
            'INSERT INTO users (username, password_hash, full_name, role, status) VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([$username, password_hash($password, PASSWORD_BCRYPT), $fullName, $role, 'inactive']);

        $newId = (int)db()->lastInsertId();
        notify(
            'role:super_admin',
            'new_user',
            'New registration',
            $fullName . ' (' . $username . ') registered as ' . $role . ' and needs activation.',
            'super_admin.php'
        );
        audit_log(['username' => $username], 'register', 'user', $newId, $fullName . ' registered as ' . $role);

        json_out(['success' => true, 'message' => 'Registered! Wait for the super admin to activate your account.']);

    
    case 'reset_password':
        
        $user = require_role('super_admin');
        $old = $input['old_password'] ?? '';
        $new = $input['new_password'] ?? '';

        if (strlen($new) < 6) {
            json_out(['success' => false, 'message' => 'New password must be at least 6 characters.']);
        }
        $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$user['id']]);
        $row = $st->fetch();

        if (!password_verify($old, $row['password_hash'])) {
            json_out(['success' => false, 'message' => 'Current password is incorrect.']);
        }
        $st = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $st->execute([password_hash($new, PASSWORD_BCRYPT), $user['id']]);

        audit_log($user, 'reset_password', 'user', (int)$user['id'], $user['full_name'] . ' changed own password');
        json_out(['success' => true, 'message' => 'Password updated successfully.']);

    default:
        json_out(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)], 400);
}
