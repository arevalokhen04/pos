<?php

require __DIR__ . '/db.php';

$user = require_role('admin', 'super_admin');

$pid = (int)($_GET['product_id'] ?? 0);
/* how many copies per label (1-100) — set by the Barcode dialog in admin.php */
$copies = max(1, min(100, (int)($_GET['copies'] ?? 1)));

if ($pid > 0) {
    $st = db()->prepare('SELECT p.name, p.unit, p.selling_price, p.barcode, p.id FROM products p WHERE p.id = ?');
    $st->execute([$pid]);
    $products = $st->fetchAll();
} else {
    $products = db()->query(
        'SELECT p.name, p.unit, p.selling_price, p.barcode, p.id FROM products p
          WHERE p.status = "active" ORDER BY p.name'
    )->fetchAll();
}
if (!$products) {
    http_response_code(404);
    die('<h3 style="font-family:sans-serif">No products found.</h3>');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Barcode labels — <?= count($products) ?> product<?= count($products) === 1 ? '' : 's' ?></title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Segoe UI', system-ui, sans-serif; background: #eef2f0; padding: 18px; }
  .toolbar {
    display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
    background: #fff; border: 1px solid #d7e2dc; border-radius: 12px;
    padding: 12px 16px; margin-bottom: 16px;
    box-shadow: 0 1px 4px rgba(22,101,52,.10);
  }
  .toolbar h3 { font-size: 15px; color: #14532d; margin-right: auto; }
  .toolbar .info { font-size: 13px; color: #52636f; }
  .toolbar button {
    font-family: inherit; font-size: 13.5px; padding: 9px 14px;
    border: 1.6px solid #cfe3d6; border-radius: 10px; background: #fff;
    font-weight: 800; cursor: pointer;
  }
  .toolbar .print { background: #16a34a; border-color: #16a34a; color: #fff; }
  .toolbar .print:hover { background: #15803d; }
  .toolbar .close { color: #52636f; }
  .hint { font-size: 12.5px; color: #7a8a80; }

  .sheet {
    display: grid;
    grid-template-columns: repeat(4, 50mm);
    gap: 4mm;
    justify-content: center;
  }
  .label {
    width: 50mm; height: 30mm;
    background: #fff; border: 1px dashed #c3d4ca;
    border-radius: 2mm;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    padding: 2mm 3mm;
    overflow: hidden;
    page-break-inside: avoid;
    break-inside: avoid;
  }
  .l-name {
    font-size: 8.2pt; font-weight: 800; color: #111;
    text-align: center; line-height: 1.15;
    max-width: 46mm;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
  }
  .l-price { font-size: 9.5pt; font-weight: 800; color: #15803d; margin-top: .6mm; }
  .l-code  { font-size: 7pt;  color: #444; letter-spacing: .4px; margin-top: .8mm; }
  .l-bar   { margin-top: 1mm; }
  .l-bar svg, .l-bar canvas { max-width: 46mm; }

  @media print {
    body { background: #fff; padding: 0; }
    .toolbar, .hint { display: none !important; }
    .sheet { gap: 0; }
    .label { border: none; border-radius: 0; }
  }
</style>
</head>
<body>

<div class="toolbar">
  <h3>🏷 Barcode labels — <?= count($products) ?> product<?= count($products) === 1 ? '' : 's' ?> × <?= $copies ?> label<?= $copies === 1 ? '' : 's' ?> = <?= count($products) * $copies ?> total</h3>
  <span class="info">Scannable CODE128 · 50 × 30 mm</span>
  <button class="print" onclick="printSheet()">🖨 Print</button>
  <button class="close" onclick="window.close()">✕ Close</button>
  <span class="hint">Tip: works on plain A4 too — cut along the dashed lines.</span>
</div>

<div class="sheet" id="sheet">
  <?php foreach ($products as $p): ?>
  <?php for ($copy = 0; $copy < $copies; $copy++): ?>
  <?php
      $code  = $p['barcode'] !== '' ? $p['barcode'] : ('MH-P' . str_pad((string)$p['id'], 6, '0', STR_PAD_LEFT));
      $price = '₱' . number_format((float)$p['selling_price'], 2);
      $name  = htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8');
      $unit  = htmlspecialchars($p['unit'], ENT_QUOTES, 'UTF-8');
      $codeH = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
  ?>
  <div class="label">
    <div class="l-name"><?= $name ?> (<?= $unit ?>)</div>
    <div class="l-price"><?= $price ?></div>
    <div class="l-bar"><svg class="bc" data-code="<?= $codeH ?>"></svg></div>
    <div class="l-code"><?= $codeH ?></div>
  </div>
  <?php endfor; ?>
  <?php endforeach; ?>
</div>

<script src="Assets/jsbarcode.all.min.js?v=1"></script>
<script>
  function renderLabels() {
    document.querySelectorAll('.bc').forEach(function (el) {
      JsBarcode(el, el.getAttribute('data-code'), {
        format: 'CODE128',
        width: 1.55,
        height: 34,
        displayValue: false,
        margin: 1,
        background: '#ffffff',
        lineColor: '#000000'
      });
    });
  }
  function printSheet() {
    renderLabels();
    setTimeout(function () { window.print(); }, 150);
  }
  renderLabels();
</script>
</body>
</html>
