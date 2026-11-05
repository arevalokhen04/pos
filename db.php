<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

function image_keyword(string $name, string $category): string
{
    $clean = preg_replace('/\d+([.,]\d+)?\s*(mm|cm|m|kg|g|ml|l|lbs|pcs?|pack|set|ft|in|inch|inches|oz|roll|pair|box|bag|can|bottle)?/i', ' ', $name);
    $words = preg_split('/\s+/', trim((string)$clean), -1, PREG_SPLIT_NO_EMPTY);
    $words = array_slice($words, 0, 3);
    if (!$words) {
        $words = array_slice(preg_split('/\s+/', trim($category), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 2);
    }
    return implode(' ', $words) ?: 'hardware tool';
}

function auto_product_image(int $productId, string $name, string $category): void
{
    try {
        $kw  = image_keyword($name, $category);
        $api = 'https://commons.wikimedia.org/w/api.php?action=query&generator=search&gsrsearch='
             . rawurlencode($kw) . '&gsrnamespace=6&gsrlimit=5&prop=imageinfo&iiprop=url|mime&iiurlwidth=320&format=json';
        $ctx = stream_context_create(['http' => ['header' => "User-Agent: MarinasPOS/1.0 (school project)

", 'timeout' => 20]]);

        $data  = json_decode(@file_get_contents($api, false, $ctx), true);
        $pages = $data['query']['pages'] ?? [];
        $thumb = null;
        foreach ($pages as $page) {
            $ii = $page['imageinfo'][0] ?? null;
            if ($ii && in_array($ii['mime'] ?? '', ['image/jpeg', 'image/png'], true)) {
                $thumb = $ii['thumburl'];
                break;
            }
        }
        if (!$thumb) return;

        $bin = @file_get_contents($thumb, false, $ctx);
        if ($bin === false || strlen($bin) < 3000) return;

        $dir = __DIR__ . '/Assets/uploads';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $fname = 'prod_' . $productId . '_' . date('His') . '.jpg';
        file_put_contents($dir . '/' . $fname, $bin);

        $st = db()->prepare('UPDATE products SET image = ? WHERE id = ?');
        $st->execute(['Assets/uploads/' . $fname, $productId]);
    } catch (Throwable $e) {
        
    }
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/* HTTPS enforcement — production only (localhost/XAMPP dev is exempt).
   Any non-HTTPS request to a real domain is 301-redirected so credentials
   always travel encrypted. Needs an SSL certificate on the host. */
$mhHost    = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
$mhIsLocal = in_array($mhHost, ['localhost', '127.0.0.1', '::1'], true) || PHP_SAPI === 'cli';
$mhHttpsOn = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
          || (($_SERVER['SERVER_PORT'] ?? '') == 443);
if ($mhHttpsOn) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
} elseif (!$mhIsLocal && $mhHost !== '') {
    header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
    exit;
}

define('DB_HOST', 'localhost');
define('DB_NAME', 'posinventory');
define('DB_USER', 'root');
define('DB_PASS', '');
define('VOID_PASSWORD', 'admin123');

/* Password policy (r4: uniform across registration, user creation and resets):
   at least 8 characters with both letters and numbers. */
function validate_password(string $password): ?string
{
    if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return 'Password must be at least 8 characters and contain both letters and numbers.';
    }
    return null;
}

/* Session idle limit (seconds) — a session idle longer than this is killed
   server-side on the next request; the UI also auto-logs out proactively. */
define('SESSION_IDLE_LIMIT', 1800);

/* Token for the scheduled auto-backup entry point (api_backup.php?action=auto_check&token=...).
   Used by Windows Task Scheduler / cron so backups run even when nobody is logged in.
   CHANGE THIS to a long random string before deploying online. */
define('BACKUP_CRON_TOKEN', 'mh-cron-CHANGE-ME-9f4c2b');

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Database connection failed']);
            exit;
        }
    }
    return $pdo;
}

function mh_session_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_input(): array
{
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw ?: '[]', true);
    return is_array($data) ? $data : [];
}

function current_user(): ?array
{
    mh_session_start();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    /* idle timeout: kill sessions with no activity for SESSION_IDLE_LIMIT seconds */
    if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > SESSION_IDLE_LIMIT) {
        session_unset();
        session_destroy();
        return null;
    }
    $_SESSION['last_activity'] = time();
    static $cached = null;
    if ($cached === null) {
        $st = db()->prepare('SELECT id, username, full_name, role, status FROM users WHERE id = ?');
        $st->execute([$_SESSION['user_id']]);
        $cached = $st->fetch() ?: false;
        if (!$cached || $cached['status'] !== 'active') {
            session_destroy();
            return null;
        }
    }
    return $cached ?: null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        json_out(['success' => false, 'message' => 'Not logged in', 'logout' => true], 401);
    }
    return $user;
}

function require_role(string ...$roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        json_out(['success' => false, 'message' => 'Access denied for your role'], 403);
    }
    return $user;
}

function audit_log(?array $user, string $action, string $entity = null, int $entityId = null, string $details = null): void
{
    $st = db()->prepare(
        'INSERT INTO audit_logs (user_id, user_name, action, entity, entity_id, details, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $user['id'] ?? null,
        $user['full_name'] ?? ($user['username'] ?? 'system'),
        $action,
        $entity,
        $entityId,
        $details,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

function notify(string $audience, string $type, string $title, string $message, string $link = null): void
{
    $st = db()->prepare(
        'INSERT INTO notifications (audience, type, title, message, link) VALUES (?, ?, ?, ?, ?)'
    );
    $st->execute([$audience, $type, $title, mb_substr($message, 0, 400), $link]);
}

function check_low_stock(int $productId): void
{
    $st = db()->prepare(
        'SELECT name, display_qty, reorder_level FROM products WHERE id = ?'
    );
    $st->execute([$productId]);
    $p = $st->fetch();
    if (!$p || (int)$p['display_qty'] > (int)$p['reorder_level']) {
        return;
    }
    
    $chk = db()->prepare(
        "SELECT id FROM notifications
          WHERE type = 'low_stock' AND is_read = 0 AND message LIKE ?
          LIMIT 1"
    );
    $chk->execute(['%' . $p['name'] . '%']);
    if ($chk->fetch()) {
        return;
    }
    notify(
        'role:admin',
        'low_stock',
        'Low stock alert',
        $p['name'] . ' display stock is down to ' . $p['display_qty'] . ' left (reorder level: ' . $p['reorder_level'] . ').',
        'admin.php'
    );
}

function peso(float $n): string
{
    return '₱' . number_format($n, 2);
}
