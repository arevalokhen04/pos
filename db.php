<?php
/**
 * MARINA'S HARDWARE POS - Database connection & shared helpers
 *
 * ★ EDIT THE VALUES BELOW WHEN DEPLOYING ONLINE (see README.md):
 *   DB_HOST / DB_NAME / DB_USER / DB_PASS  -> your hosting MySQL credentials
 *   VOID_PASSWORD                          -> password cashiers must enter to void a cart item
 */
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

/* ===== Product image helpers (awtomatikong litrato mula sa Wikimedia Commons) ===== */

/* Gagawing keyword ang product name — tanggalin ang mga sukat/numero */
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

/* Kukuha ng totoong litrato mula sa Wikimedia Commons at isasave sa product.
   Tahimik na nabibigo (fallback pa rin ang emoji) — hindi nasisira ang flow. */
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
        /* tahimik na fallback — emoji ang gagamitin */
    }
}

/* POS data should never be cached by the browser — laging fresh */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

define('DB_HOST', 'localhost');
define('DB_NAME', 'posinventory');
define('DB_USER', 'root');
define('DB_PASS', '');
define('VOID_PASSWORD', 'admin123'); // change this before going online

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

/* Hardened session start: HttpOnly + SameSite cookies (secure flag turns on automatically under HTTPS) */
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

/* ---------- JSON helpers ---------- */

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

/* ---------- Auth helpers (session based) ---------- */

function current_user(): ?array
{
    mh_session_start();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
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

/* ---------- Audit log ---------- */

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

/* ---------- Notifications ---------- */

/**
 * Push a notification to a specific audience:
 *   'role:super_admin' → approval requests & new registrations
 *   'role:admin'       → low-stock alerts (admins file the restock requests)
 *   'user:<id>'        → direct message (e.g. decision on your request)
 * Each notification goes ONLY to the given audience — no mirroring.
 */
function notify(string $audience, string $type, string $title, string $message, string $link = null): void
{
    $st = db()->prepare(
        'INSERT INTO notifications (audience, type, title, message, link) VALUES (?, ?, ?, ?, ?)'
    );
    $st->execute([$audience, $type, $title, mb_substr($message, 0, 400), $link]);
}

/** Create a low-stock notification once when a product falls to/below its reorder level. */
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
    // avoid spamming: skip if an unread low-stock notice exists for this product
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
