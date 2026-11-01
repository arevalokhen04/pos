<?php

require __DIR__ . '/db.php';

$user = require_login();
$saleId = (int)($_GET['sale_id'] ?? 0);

$st = db()->prepare(
    'SELECT s.*, u.full_name AS cashier_name FROM sales s JOIN users u ON u.id = s.cashier_id WHERE s.id = ?'
);
$st->execute([$saleId]);
$sale = $st->fetch();
if (!$sale) {
    echo '<h3 style="font-family:sans-serif">Receipt not found.</h3>';
    exit;
}
if ($user['role'] === 'cashier' && (int)$sale['cashier_id'] !== (int)$user['id']) {
    echo '<h3 style="font-family:sans-serif">You can only reprint your own receipts.</h3>';
    exit;
}

$st = db()->prepare(
    'SELECT si.*, p.unit FROM sale_items si JOIN products p ON p.id = si.product_id WHERE si.sale_id = ? ORDER BY si.id'
);
$st->execute([$saleId]);
$items = $st->fetchAll();

$total       = (float)$sale['total_amount'];
$discount    = (float)$sale['discount'];
$gross       = $total + $discount;      
$vatable     = $gross / 1.12;
$vat         = $gross - $vatable;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Receipt <?= htmlspecialchars($sale['receipt_no']) ?></title>
<style>
  @page { margin: 0; }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: 'Courier New', 'Consolas', monospace;
    background: #f2f2f2;
    display: flex; justify-content: center; padding: 16px 0;
  }
  .receipt {
    width: 80mm; background: #fff; padding: 8px 10px 12px;
    font-size: 12px; color: #000; line-height: 1.5;
  }
  .brand-color { color: #15803d; font-weight: bold; }
  .c { text-align: center; }
  .store { font-size: 17px; font-weight: bold; letter-spacing: 1px; }
  .tiny { font-size: 10.5px; }
  .hr  { border-top: 1px dashed #000; margin: 5px 0; }
  .hr-solid { border-top: 2px solid #000; margin: 5px 0; }
  .row { display: flex; justify-content: space-between; gap: 8px; }
  .row .l { flex: 1; white-space: pre-wrap; }
  .row .r { white-space: nowrap; text-align: right; }
  .sec { font-weight: bold; letter-spacing: .5px; }
  /* text-only items — no boxes */
  .items { width: 100%; border-collapse: collapse; font-size: 10px; margin: 1px 0 2px; table-layout: fixed; }
  .items th {
    color: #111; font-weight: 700; text-transform: uppercase;
    padding: 2px; text-align: center; font-size: 8.5px; line-height: 1.15;
    border: none; border-bottom: 1px dashed #444; overflow-wrap: normal;
  }
  .items td { border: none; padding: 2.5px 2px; vertical-align: top; overflow-wrap: break-word; }
  .items col.w-qty  { width: 22px; }
  .items col.w-unit { width: 30px; }
  .items col.w-up   { width: 47px; }
  .items col.w-ta   { width: 51px; }
  .items .c-qty  { text-align: center; }
  .items .c-unit { text-align: center; }
  .items .c-desc { text-align: left; }
  .items .c-up, .items .c-ta { text-align: right; white-space: nowrap; }
  @media print { .items { font-size: 8.5px; } .items th { font-size: 7.5px; } }
  .big { font-size: 14px; font-weight: bold; }
  .foot { margin-top: 7px; }
  @media print {
    body { background: #fff; padding: 0; }
    .receipt { width: 80mm; }
  }
</style>
</head>
<body>
<div class="receipt">

  
  <div class="c">
    <div class="store"><span class="brand-color">MARINA'S</span> HARDWARE</div>
    <div class="tiny">Hardware &amp; Construction Supplies</div>
    <div class="tiny">Purok 4, Brgy. San Isidro, Santa Rosa, Laguna</div>
    <div class="tiny">VAT REG TIN:124-839-761-00000</div>
    
  </div>

  <div class="hr-solid"></div>
  <div class="row sec">
    <span class="l">SALES INVOICE</span>
    <span class="r"><?= htmlspecialchars($sale['receipt_no']) ?></span>
  </div>
  <div class="row tiny">
    <span class="l">Date: <?= date('m/d/Y', strtotime($sale['created_at'])) ?> <?= date('g:i:s A', strtotime($sale['created_at'])) ?></span>
  </div>
  <div class="row tiny">
    <span class="l">Cashier: <?= htmlspecialchars($sale['cashier_name']) ?></span>
    <span class="r">Terms: CASH</span>
  </div>

  <div class="hr"></div>

  
  <table class="items">
    <colgroup>
      <col class="w-qty"><col class="w-unit"><col><col class="w-up"><col class="w-ta">
    </colgroup>
    <thead>
      <tr>
        <th class="c-qty">Qty</th>
        <th class="c-unit">Unit</th>
        <th class="c-desc">Item Description</th>
        <th class="c-up">Price</th>
        <th class="c-ta">Total</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $it): ?>
      <tr>
        <td class="c-qty"><?= (int)$it['qty'] ?></td>
        <td class="c-unit"><?= htmlspecialchars($it['unit']) ?></td>
        <td class="c-desc"><?= htmlspecialchars($it['product_name']) ?></td>
        <td class="c-up"><?= number_format((float)$it['unit_price'], 2) ?></td>
        <td class="c-ta"><?= number_format((float)$it['subtotal'], 2) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="hr"></div>

  
  <div class="row"><span class="l">Subtotal (VAT inclusive)</span><span class="r"><?= number_format($gross, 2) ?></span></div>
  <?php if ($discount > 0): ?>
  <div class="row"><span class="l">Less: Discount</span><span class="r">-<?= number_format($discount, 2) ?></span></div>
  <?php endif; ?>
  <div class="row big"><span class="l">TOTAL AMOUNT DUE</span><span class="r">P <?= number_format($total, 2) ?></span></div>

  <div class="hr"></div>

  
  <div class="row"><span class="l"><b>TAX BREAKDOWN</b></span><span class="r"></span></div>
  <div class="row"><span class="l">VAT Rate</span><span class="r">12%</span></div>
  <div class="row"><span class="l">VATable Sales</span><span class="r"><?= number_format($vatable, 2) ?></span></div>
  <div class="row"><span class="l">VAT-Exempt Sales</span><span class="r">0.00</span></div>
  <div class="row"><span class="l">Zero-Rated Sales</span><span class="r">0.00</span></div>
  <div class="row"><span class="l">VAT Amount (12%)</span><span class="r"><?= number_format($vat, 2) ?></span></div>
  <div class="row"><span class="l"><b>Total Tax</b></span><span class="r"><b><?= number_format($vat, 2) ?></b></span></div>

  <div class="hr"></div>

  <div class="row big"><span class="l">CASH</span><span class="r"><?= number_format((float)$sale['amount_paid'], 2) ?></span></div>
  <div class="row big"><span class="l">CHANGE</span><span class="r"><?= number_format((float)$sale['change_amount'], 2) ?></span></div>

  <div class="hr-solid"></div>

  
  <div class="c foot">
    <div class="tiny">Received the above goods in good condition.</div>
    <div class="tiny">Goods sold are returnable/exchangeable</div>
    <div class="tiny">within 7 days ONLY with this receipt</div>
    <div class="tiny">and items in original packaging.</div>
    <br>
    <div class="brand-color">*** THANK YOU ***</div>
  </div>

</div>
<?php if (empty($_GET['preview'])): ?>
<script>window.onload = () => setTimeout(() => window.print(), 400);</script>
<?php endif; ?>
</body>
</html>
