<?php
/**
 * MARINA'S HARDWARE POS - Standalone diagnostic page
 * Checks PHP version, DB connection, tables, seed data and API reachability.
 * Also offers demo data generation (seed_data.php).
 *
 * ★ PROTECTED: admin / super admin login required (exposes system info).
 */
require __DIR__ . '/db.php';

$me = current_user();
if (!$me || !in_array($me['role'], ['admin', 'super_admin'], true)) {
    http_response_code(403);
    echo '<h3 style="font-family:sans-serif">🔒 Admin login required.</h3>
          <p style="font-family:sans-serif"><a href="Login.php">Sign in</a></p>';
    exit;
}

$checks = [];
$ok = fn($c) => $c ? '✅' : '❌';

/* PHP */
$checks[] = ['PHP version', PHP_VERSION, version_compare(PHP_VERSION, '8.0.0', '>=')];
$checks[] = ['PDO MySQL driver', extension_loaded('pdo_mysql') ? 'loaded' : 'missing', extension_loaded('pdo_mysql')];
$checks[] = ['Timezone', date_default_timezone_get(), true];

/* DB */
try {
    $pdo = db();
    $checks[] = ['Database connection', DB_NAME . ' @ ' . DB_HOST, true];
    $tables = ['users', 'categories', 'products', 'sales', 'sale_items', 'inventory_movements',
               'stock_requests', 'notifications', 'cashier_sessions', 'audit_logs'];
    foreach ($tables as $t) {
        try {
            $c = $pdo->query("SELECT COUNT(*) c FROM `$t`")->fetch()['c'];
            $checks[] = ["Table `$t`", $c . ' rows', true];
        } catch (Throwable $e) {
            $checks[] = ["Table `$t`", 'MISSING', false];
        }
    }
    $lowStock = $pdo->query('SELECT COUNT(*) c FROM products WHERE stock_qty <= reorder_level AND status="active"')->fetch()['c'];
    $pending  = $pdo->query('SELECT COUNT(*) c FROM stock_requests WHERE status="pending"')->fetch()['c'];
    $unread   = $pdo->query('SELECT COUNT(*) c FROM notifications WHERE is_read=0')->fetch()['c'];
} catch (Throwable $e) {
    $checks[] = ['Database connection', $e->getMessage(), false];
    $lowStock = $pending = $unread = '-';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Diagnostics — Marina's Hardware POS</title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Segoe UI',Arial,sans-serif; background:#f0fdf4; color:#0f172a; padding:36px 20px; }
  .wrap { max-width: 880px; margin: 0 auto; }
  h1 { color:#14532d; margin-bottom:4px; }
  .sub { color:#52636f; margin-bottom:24px; }
  .card { background:#fff; border:1px solid #e4eae5; border-radius:16px; overflow:hidden; margin-bottom:20px; }
  .card h2 { font-size:16px; padding:14px 20px; background:#dcfce7; color:#14532d; border-bottom:1px solid #e4eae5; }
  table { width:100%; border-collapse:collapse; font-size:14px; }
  td { padding:10px 20px; border-bottom:1px solid #eef2ef; }
  td.status { text-align:right; font-weight:700; }
  .grid { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:20px; }
  .mini { background:#fff; border:1px solid #e4eae5; border-radius:14px; padding:18px; text-align:center; }
  .mini .v { font-size:26px; font-weight:800; color:#15803d; }
  .mini .k { font-size:12.5px; color:#52636f; font-weight:600; margin-top:4px; }
  a.btn { display:inline-block; background:linear-gradient(135deg,#16a34a,#15803d); color:#fff; text-decoration:none;
          font-weight:700; padding:10px 18px; border-radius:10px; margin-right:8px; }
  .warn { background:#fffbeb; border:1px solid #fde68a; padding:12px 16px; border-radius:10px; font-size:13.5px; margin-top:14px; }
</style>
</head>
<body>
<div class="wrap">
  <h1>🔧 System Diagnostics</h1>
  <div class="sub">Marina's Hardware POS &amp; Inventory — health check</div>

  <div class="grid">
    <div class="mini"><div class="v"><?= htmlspecialchars((string)$lowStock) ?></div><div class="k">Low stock products</div></div>
    <div class="mini"><div class="v"><?= htmlspecialchars((string)$pending) ?></div><div class="k">Pending approvals</div></div>
    <div class="mini"><div class="v"><?= htmlspecialchars((string)$unread) ?></div><div class="k">Unread notifications</div></div>
  </div>

  <div class="card">
    <h2>Environment &amp; database</h2>
    <table>
      <?php foreach ($checks as [$name, $val, $pass]): ?>
      <tr>
        <td><?= htmlspecialchars($name) ?></td>
        <td class="status" style="color:<?= $pass ? '#15803d' : '#dc2626' ?>">
          <?= $ok($pass) ?> <?= htmlspecialchars((string)$val) ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="card">
    <h2>Quick links</h2>
    <div style="padding:18px 20px">
      <a class="btn" href="Login.php">🔐 Login page</a>
      <a class="btn" href="test_approvals_direct.php" style="background:#fff;color:#15803d;border:1.5px solid #bbf7d0">🧪 Run approvals API test</a>
      <a class="btn" href="seed_data.php" style="background:#fff;color:#15803d;border:1.5px solid #bbf7d0">🎲 Generate demo sales data</a>
      <div class="warn">
        Demo accounts — <b>superadmin/admin123</b> · <b>admin1/admin123</b> · <b>cashier1/cashier123</b>.
        The demo data generator creates ~2 months of random sales so the Reports page has something to show.
      </div>
    </div>
  </div>
</div>
</body>
</html>
