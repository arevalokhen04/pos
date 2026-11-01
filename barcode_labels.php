<?php
/**
 * MARINA'S HARDWARE POS - Barcode label sheet (printable)
 * barcode_labels.php?product_id=X&qty=N
 * Renders N scannable CODE128 labels (50 x 30 mm) for boxed/warehouse stock.
 * Print with any thermal/A4 label sheet — scanners work straight off the page.
 */
require __DIR__ . '/db.php';

$user = require_role('admin', 'super_admin');

$pid = (int)($_GET['product_id'] ?? 0);
$qty = max(1, min(96, (int)($_GET['qty'] ?? 12)));

$st = db()->prepare('SELECT p.*, c.name AS category FROM products p JOIN categories c ON c.id = p.category_id WHERE p.id = ?');
$st->execute([$pid]);
$p = $st->fetch();
if (!$p) {
    http_response_code(404);
    die('<h3 style="font-family:sans-serif">Product not found.</h3>');
}

$code  = $p['barcode'] !== '' ? $p['barcode'] : ('MH-P' . str_pad((string)$p['id'], 6, '0', STR_PAD_LEFT));
$price = '₱' . number_format((float)$p['selling_price'], 2);
$name  = htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8');
$unit  = htmlspecialchars($p['unit'], ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Barcode labels — <?= $name ?></title>
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
  .toolbar input, .toolbar button {
    font-family: inherit; font-size: 13.5px; padding: 9px 14px;
    border: 1.6px solid #cfe3d6; border-radius: 10px; background: #fff;
  }
  .toolbar input { width: 70px; text-align: center; font-weight: 700; }
  .toolbar button { font-weight: 800; cursor: pointer; }
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
  <h3>🏷 Barcode labels — <?= $name ?></h3>
  <span class="info">Scannable CODE128 · 50 × 30 mm labels</span>
  <label class="hint">Labels:
    <input id="qty" type="number" min="1" max="96" value="<?= $qty ?>">
  </label>
  <button class="print" onclick=" reprint()">🖨 Print</button>
  <button class="close" onclick="window.close()">✕ Close</button>
  <span class="hint">Tip: change the count then Print. Same sheet works for a plain A4 — cut along the dashed lines.</span>
</div>

<div class="sheet" id="sheet">
  <?php for ($i = 0; $i < $qty; $i++): ?>
  <div class="label">
    <div class="l-name"><?= $name ?> (<?= $unit ?>)</div>
    <div class="l-price"><?= $price ?></div>
    <div class="l-bar"><svg class="bc"></svg></div>
    <div class="l-code"><?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?></div>
  </div>
  <?php endfor; ?>
</div>

<script src="Assets/jsbarcode.all.min.js?v=1"></script>
<script>
  var CODE = <?= json_encode($code) ?>;

  function renderLabels() {
    JsBarcode('.bc', CODE, {
      format: 'CODE128',
      width: 1.55,
      height: 34,
      displayValue: false,
      margin: 1,
      background: '#ffffff',
      lineColor: '#000000'
    });
  }

  function reprint() {
    renderLabels();
    setTimeout(function () { window.print(); }, 150);
  }

  document.getElementById('qty').addEventListener('change', function () {
    var n = Math.max(1, Math.min(96, parseInt(this.value || '12', 10)));
    this.value = n;
    var sheet = document.getElementById('sheet');
    var labels = sheet.querySelectorAll('.label');
    /* rebuild the label list to the requested count */
    for (var i = labels.length; i < n; i++) sheet.appendChild(labels[0].cloneNode(true));
    while (sheet.querySelectorAll('.label').length > n) sheet.removeChild(sheet.lastChild);
    renderLabels();
  });

  renderLabels();
</script>
</body>
</html>
