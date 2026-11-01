<?php

require __DIR__ . '/db.php';

$user = require_role('super_admin');
$dir  = __DIR__ . '/backups';
if (!is_dir($dir)) mkdir($dir, 0775, true);
$settingsFile = $dir . '/auto_settings.json';

function log_history(string $file, string $action, string $type, string $status, string $note, array $user): void
{
    db()->prepare('INSERT INTO backup_history (file, action, type, status, note, created_by) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$file, $action, $type, $status, mb_substr($note, 0, 255), $user['full_name']]);
}

function db_dump_to(string $path): void
{
    $tables = ['users', 'categories', 'products', 'sales', 'sale_items',
               'inventory_movements', 'stock_requests', 'notifications',
               'cashier_sessions', 'audit_logs', 'backup_history'];
    $out = "-- Marina's Hardware POS — database backup\n";
    $out .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $out .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
    foreach ($tables as $t) {
        $create = db()->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_ASSOC);
        $out .= "DROP TABLE IF EXISTS `$t`;\n";
        $out .= $create['Create Table'] . ";\n\n";
        $rows = db()->query("SELECT * FROM `$t`");
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $vals = array_map(fn($v) => $v === null ? 'NULL' : db()->quote($v), $row);
            $out .= "INSERT INTO `$t` VALUES (" . implode(', ', $vals) . ");\n";
        }
        $out .= "\n";
    }
    $out .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    file_put_contents($path, $out);
}

function get_auto_settings(): array
{
    global $settingsFile;
    $defaults = ['enabled' => true, 'frequency' => 'daily', 'time' => '22:00', 'retention_days' => 7];
    if (is_file($settingsFile)) {
        $saved = json_decode(file_get_contents($settingsFile), true);
        return array_merge($defaults, is_array($saved) ? $saved : []);
    }
    return $defaults;
}

function save_auto_settings(array $settings): void
{
    global $settingsFile;
    file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));
}

function run_auto_backup(): array
{
    global $dir;
    $settings = get_auto_settings();
    if (!$settings['enabled']) return ['created' => false, 'message' => 'Automatic backup is disabled.'];

    $today = 'backup_auto_' . date('Y-m-d');
    $exists = glob($dir . '/' . $today . '*.sql');
    if ($exists) return ['created' => false, 'message' => 'Auto-backup already exists today.'];

    $name = 'backup_auto_' . date('Y-m-d_His') . '.sql';
    db_dump_to($dir . '/' . $name);

    
    $retention = max(1, (int)($settings['retention_days'] ?? 7));
    $cutoff = time() - ($retention * 86400);
    foreach (glob($dir . '/backup_auto_*.sql') as $old) {
        if (filemtime($old) < $cutoff) unlink($old);
    }

    return ['created' => true, 'file' => $name, 'message' => 'Daily automatic backup created: ' . $name];
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($action === 'overview') {
    $files   = glob($dir . '/*.sql');
    rsort($files);
    $totalSize = 0;
    foreach ($files as $f) $totalSize += filesize($f);
    $lastBackup = $files[0] ?? null;
    $settings = get_auto_settings();

    json_out([
        'success' => true,
        'overview' => [
            'lastBackup'     => $lastBackup ? date('M j, Y g:i A', filemtime($lastBackup)) : 'Never',
            'lastBackupFile' => $lastBackup ? basename($lastBackup) : null,
            'backupStatus'   => $settings['enabled'] ? 'Automatic backup enabled' : 'Automatic backup disabled',
            'totalFiles'     => count($files),
            'storageUsed'    => $totalSize,
            'autoEnabled'    => (bool)$settings['enabled'],
            'frequency'      => $settings['frequency'],
            'retentionDays'  => $settings['retention_days'],
        ],
    ]);
}

if ($action === 'auto_settings') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_input();
        $settings = get_auto_settings();
        $settings['enabled']        = isset($in['enabled']) ? (bool)$in['enabled'] : $settings['enabled'];
        $settings['frequency']      = in_array($in['frequency'] ?? '', ['daily', 'weekly', 'monthly']) ? $in['frequency'] : $settings['frequency'];
        $settings['time']           = preg_match('/^\d{2}:\d{2}$/', $in['time'] ?? '') ? $in['time'] : $settings['time'];
        $settings['retention_days'] = max(1, (int)($in['retention_days'] ?? $settings['retention_days']));
        save_auto_settings($settings);
        audit_log($user, 'backup_settings', 'backup', null, json_encode($settings));
        json_out(['success' => true, 'message' => 'Auto-backup settings saved.', 'settings' => $settings]);
    }
    json_out(['success' => true, 'settings' => get_auto_settings()]);
}

if ($action === 'auto_check') {
    $settings = get_auto_settings();
    if (!$settings['enabled']) {
        json_out(['success' => true, 'created' => false, 'message' => 'Automatic backup is disabled.']);
    }
    $today = 'backup_auto_' . date('Y-m-d');
    if (glob($dir . '/' . $today . '*.sql')) {
        json_out(['success' => true, 'created' => false, 'message' => 'Auto-backup already exists today.']);
    }
    $name = 'backup_auto_' . date('Y-m-d_His') . '.sql';
    db_dump_to($dir . '/' . $name);
    log_history($name, 'auto_backup', 'auto', 'successful', 'Daily automatic backup', $user);
    audit_log($user, 'backup_auto', 'backup', null, $name);
    json_out(['success' => true, 'created' => true, 'message' => 'Daily automatic backup created: ' . $name, 'file' => $name]);
}

if ($action === 'list') {
    $files = glob($dir . '/*.sql');
    rsort($files);
    $list = [];
    foreach ($files as $f) {
        $list[] = [
            'file' => basename($f),
            'size' => filesize($f),
            'date' => date('M j, Y g:i A', filemtime($f)),
        ];
    }
    $history = db()->query('SELECT * FROM backup_history ORDER BY id DESC LIMIT 100')->fetchAll();
    json_out(['success' => true, 'backups' => $list, 'history' => $history]);
}

if ($action === 'create') {
    $name = 'backup_manual_' . date('Y-m-d_His') . '.sql';
    db_dump_to($dir . '/' . $name);
    log_history($name, 'backup', 'manual', 'successful', 'Manual backup', $user);
    audit_log($user, 'backup_create', 'backup', null, $name);
    json_out(['success' => true, 'message' => 'Backup created: ' . $name, 'file' => $name]);
}

if ($action === 'download') {
    $name = basename($_GET['file'] ?? '');
    $path = $dir . '/' . $name;
    if (!is_file($path)) json_out(['success' => false, 'message' => 'Backup not found.'], 404);
    audit_log($user, 'backup_download', 'backup', null, $name);
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if ($action === 'verify') {
    $in   = json_input();
    $name = basename($in['file'] ?? '');
    $path = $dir . '/' . $name;
    if (!is_file($path)) json_out(['success' => false, 'message' => 'Backup not found.'], 404);
    $sql = file_get_contents($path);
    $checks = [
        'hasCreateTable' => stripos($sql, 'CREATE TABLE') !== false,
        'hasInsertData'  => stripos($sql, 'INSERT INTO') !== false,
        'hasProducts'    => stripos($sql, 'products') !== false,
        'hasSales'       => stripos($sql, 'sales') !== false,
        'hasUsers'       => stripos($sql, 'users') !== false,
        'fileSize'       => filesize($path),
        'readable'       => is_readable($path),
    ];
    $valid = $checks['hasCreateTable'] && $checks['hasInsertData'] && $checks['hasProducts'] && $checks['hasSales'] && $checks['hasUsers'];
    audit_log($user, 'backup_verify', 'backup', null, $name . ' — ' . ($valid ? 'VALID' : 'INVALID'));
    json_out(['success' => true, 'valid' => $valid, 'checks' => $checks, 'message' => $valid ? 'Backup file is valid and complete.' : 'Backup file is incomplete or corrupted.']);
}

if ($action === 'delete') {
    $in   = json_input();
    $name = basename($in['file'] ?? '');
    $path = $dir . '/' . $name;
    if (!is_file($path)) json_out(['success' => false, 'message' => 'Backup not found.'], 404);
    unlink($path);
    audit_log($user, 'backup_delete', 'backup', null, $name);
    json_out(['success' => true, 'message' => 'Backup deleted: ' . $name]);
}

if ($action === 'safety') {
    $name = 'backup_safety_before_restore_' . date('Y-m-d_His') . '.sql';
    db_dump_to($dir . '/' . $name);
    log_history($name, 'safety', 'auto', 'successful', 'Safety backup before restore', $user);
    json_out(['success' => true, 'file' => $name]);
}

if ($action === 'restore_server') {
    $in      = json_input();
    $name    = basename($in['file'] ?? '');
    $confirm = trim($in['confirm'] ?? '');
    if ($confirm !== 'RESTORE') {
        json_out(['success' => false, 'message' => 'Type RESTORE exactly to confirm.']);
    }
    $path = $dir . '/' . $name;
    if (!is_file($path)) json_out(['success' => false, 'message' => 'Backup not found.'], 404);

    
    $safety = 'backup_safety_before_restore_' . date('Y-m-d_His') . '.sql';
    db_dump_to($dir . '/' . $safety);
    log_history($safety, 'safety', 'auto', 'successful', 'Safety backup before restore of ' . $name, $user);

    $sql = file_get_contents($path);
    if (stripos($sql, 'CREATE TABLE') === false) {
        json_out(['success' => false, 'message' => 'Invalid backup file.'], 400);
    }
    try {
        $pdo2 = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]
        );
        $pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo2->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $pdo2->exec($sql);
        log_history($name, 'restore', 'manual', 'successful', 'Restored from saved backup', $user);
        audit_log($user, 'backup_restore', 'backup', null, $name);
        json_out(['success' => true, 'message' => 'Database restored successfully! Please re-login.', 'safety' => $safety]);
    } catch (Throwable $e) {
        log_history($name, 'restore', 'manual', 'failed', $e->getMessage(), $user);
        json_out(['success' => false, 'message' => 'Restore failed: ' . $e->getMessage()], 500);
    }
}

if ($action === 'restore') {
    $confirm = trim($_POST['confirm'] ?? '');
    if (empty($_FILES['sqlfile']) || $_FILES['sqlfile']['error'] !== UPLOAD_ERR_OK) {
        json_out(['success' => false, 'message' => 'No .sql file uploaded.']);
    }
    if ($confirm !== 'RESTORE') {
        json_out(['success' => false, 'message' => 'Type RESTORE exactly to confirm.']);
    }
    $safety = 'backup_safety_before_restore_' . date('Y-m-d_His') . '.sql';
    db_dump_to($dir . '/' . $safety);
    log_history($safety, 'safety', 'auto', 'successful', 'Safety backup before restore of uploaded file', $user);

    $sql = file_get_contents($_FILES['sqlfile']['tmp_name']);
    if (stripos($sql, 'CREATE TABLE') === false) {
        json_out(['success' => false, 'message' => 'Invalid backup file.'], 400);
    }
    try {
        $pdo2 = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]
        );
        $pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo2->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $pdo2->exec($sql);
        log_history('uploaded_' . date('His') . '.sql', 'restore', 'manual', 'successful', 'Restored from uploaded file', $user);
        audit_log($user, 'backup_restore', 'backup', null, 'uploaded file');
        json_out(['success' => true, 'message' => 'Database restored successfully! Please re-login.', 'safety' => $safety]);
    } catch (Throwable $e) {
        log_history('uploaded_' . date('His') . '.sql', 'restore', 'manual', 'failed', $e->getMessage(), $user);
        json_out(['success' => false, 'message' => 'Restore failed: ' . $e->getMessage()], 500);
    }
}

if ($action === 'factory_reset') {
    $in       = json_input();
    $password = (string)($in['password'] ?? '');

    
    $row = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $row->execute([$user['id']]);
    $hash = $row->fetchColumn();
    if (!$hash || !password_verify($password, $hash)) {
        json_out(['success' => false, 'message' => 'Wrong password. Factory reset cancelled.'], 403);
    }

    
    $safety = 'backup_safety_before_factory_reset_' . date('Y-m-d_His') . '.sql';
    db_dump_to($dir . '/' . $safety);
    

    
    $wipe = ['sale_items', 'sales', 'inventory_movements', 'stock_requests', 'notifications',
             'cashier_sessions', 'audit_logs', 'backup_history', 'products'];
    try {
        db()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($wipe as $t) db()->exec("TRUNCATE TABLE `$t`");
        db()->exec('SET FOREIGN_KEY_CHECKS = 1');
    } catch (Throwable $e) {
        db()->exec('SET FOREIGN_KEY_CHECKS = 1');
        json_out(['success' => false, 'message' => 'Factory reset failed: ' . $e->getMessage()], 500);
    }

    
    audit_log($user, 'factory_reset', 'system', null, 'All data wiped; safety backup: ' . $safety);
    json_out(['success' => true, 'message' => 'Factory reset complete. Only default accounts remain.', 'safety' => $safety]);
}

json_out(['success' => false, 'message' => 'Unknown action'], 400);
