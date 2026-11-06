<?php

/* ── Raw thermal receipt printing (r4: automatic receipt printing) ──────
   Builds an ESC/POS byte stream from the sale record and sends it straight
   to the local thermal printer queue ("POS58 Receipt") through the Windows
   spooler with datatype RAW — no print dialog, works from any browser.
   The HTML receipt (api_print.php) stays available for previews. ──────── */

define('MH_PRINTER_NAME', 'POS58 Receipt');
define('MH_RAW_PRINT_PS1', 'C:\xampp\pos_dev_archive\mh_raw_print.ps1');
define('MH_RCPT_WIDTH', 32); /* 58mm thermal, font A = 32 columns */

function mh_ascii(string $s): string
{
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
    if ($t === false) {
        $t = $s;
    }
    return preg_replace('/[^\x20-\x7E]/', '', $t);
}

function mh_rcpt_center(string $s): string
{
    $s = mh_ascii($s);
    $pad = max(0, intdiv(MH_RCPT_WIDTH - strlen($s), 2));
    return str_repeat(' ', $pad) . $s . "\n";
}

function mh_rcpt_wrap_center(string $s): string
{
    /* printer center mode (ESC a 1) is ON at every call site — no manual
       padding here: spaces would be centered too and shift the line right */
    $out = '';
    foreach (str_split(mh_ascii($s), MH_RCPT_WIDTH) as $chunk) {
        $out .= trim($chunk) . "\n";
    }
    return $out;
}

function mh_rcpt_row(string $left, string $right): string
{
    $left  = mh_ascii($left);
    $right = mh_ascii($right);
    $space = MH_RCPT_WIDTH - strlen($right) - strlen($left);
    if ($space < 1) {
        $left  = substr($left, 0, MH_RCPT_WIDTH - strlen($right) - 1);
        $space = MH_RCPT_WIDTH - strlen($right) - strlen($left);
    }
    return $left . str_repeat(' ', $space) . $right . "\n";
}

function mh_rcpt_rule(string $ch = '-'): string
{
    return str_repeat($ch, MH_RCPT_WIDTH) . "\n";
}

/* Build the full ESC/POS byte stream for a sale. Returns null if the sale
   does not exist or is voided (voided receipts are never re-printed). */
function mh_receipt_escpos(int $saleId): ?string
{
    $st = db()->prepare(
        'SELECT s.*, u.full_name AS cashier_name
           FROM sales s JOIN users u ON u.id = s.cashier_id
          WHERE s.id = ?'
    );
    $st->execute([$saleId]);
    $sale = $st->fetch();
    if (!$sale || $sale['status'] === 'voided') {
        return null;
    }

    $st = db()->prepare(
        'SELECT si.product_name, si.qty, si.unit_price, si.subtotal, p.unit
           FROM sale_items si JOIN products p ON p.id = si.product_id
          WHERE si.sale_id = ? ORDER BY si.id'
    );
    $st->execute([$saleId]);
    $items = $st->fetchAll();

    $total    = (float)$sale['total_amount'];
    $discount = (float)$sale['discount'];
    $gross    = $total + $discount;
    $pctTax   = round($total * 0.03, 2); /* 3% percentage tax — non-VAT business */

    $E  = "\x1B"; /* ESC */
    $GS = "\x1D"; /* GS  */

    $txt  = $E . '@';                       /* initialize            */
    $txt .= $E . 'a' . "\x01";              /* center on             */
    /* double-HEIGHT + bold only (normal width): "MARINA'S HARDWARE" is 17
       chars — double-WIDTH would make it 34 columns and wrap on the 32-col
       58mm printer (r4: title must fit on the first line) */
    $txt .= $E . '!' . "\x18";
    $txt .= "MARINA'S HARDWARE\n";
    $txt .= $E . '!' . "\x00";              /* normal                */
    $txt .= mh_rcpt_wrap_center('Hardware & Construction Supplies');
    $txt .= mh_rcpt_wrap_center('Purok Gulod, Barangay Bago, General Tinio, Nueva Ecija');
    $txt .= mh_rcpt_wrap_center('VAT REG TIN:124-839-761-00000');
    $txt .= $E . 'a' . "\x00";              /* center off            */
    $txt .= mh_rcpt_rule('=');
    $txt .= mh_rcpt_row('Date', date('m/d/Y g:i A', strtotime($sale['created_at'])));
    $txt .= mh_rcpt_row('Cashier', $sale['cashier_name']);
    $txt .= mh_rcpt_rule();

    foreach ($items as $it) {
        $name = ((int)$it['qty']) . ' ' . $it['unit'] . ' ' . $it['product_name'];
        $txt .= mh_rcpt_row($name, number_format((float)$it['subtotal'], 2));
    }

    $txt .= mh_rcpt_rule();
    $txt .= mh_rcpt_row('Subtotal', number_format($gross, 2));
    if ($discount > 0) {
        $txt .= mh_rcpt_row('Less: Discount', '-' . number_format($discount, 2));
    }
    $txt .= $E . '!' . "\x28";              /* double-height bold    */
    $txt .= mh_rcpt_row('TOTAL AMOUNT', 'P ' . number_format($total, 2));
    $txt .= $E . '!' . "\x00";
    $txt .= mh_rcpt_rule();
    $txt .= mh_rcpt_row('Tax Type', 'Percentage');
    $txt .= mh_rcpt_row('Tax Rate', '3%');
    $txt .= mh_rcpt_row('Tax Amount', number_format($pctTax, 2));
    $txt .= mh_rcpt_rule();
    $txt .= $E . '!' . "\x28";
    $txt .= mh_rcpt_row('CASH', number_format((float)$sale['amount_paid'], 2));
    $txt .= mh_rcpt_row('CHANGE', number_format((float)$sale['change_amount'], 2));
    $txt .= $E . '!' . "\x00";
    $txt .= mh_rcpt_rule();
    $txt .= $E . 'a' . "\x01";
    $txt .= mh_rcpt_wrap_center('Goods sold are returnable/exchangeable within 7 days ONLY with this receipt and items in original packaging.');
    $txt .= "\n";
    $txt .= mh_rcpt_wrap_center('*** THANK YOU ***');
    $txt .= $E . 'a' . "\x00";
    $txt .= "\n\n\n" . $GS . 'V' . "\x42\x00"; /* feed + partial cut */

    return $txt;
}

/* Send the receipt bytes to the thermal printer. Synchronous (~1s). */
function mh_print_sale_raw(int $saleId): bool
{
    $txt = mh_receipt_escpos($saleId);
    if ($txt === null) {
        return false;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'mhrcpt');
    file_put_contents($tmp, $txt);
    $cmd = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File '
         . escapeshellarg(MH_RAW_PRINT_PS1)
         . ' -Path ' . escapeshellarg($tmp)
         . ' -Printer ' . escapeshellarg(MH_PRINTER_NAME);
    exec($cmd, $out, $code);
    @unlink($tmp);
    return $code === 0;
}

/* Fire-and-forget variant for checkout — the sale response is never delayed
   by the printer. Paper starts coming out ~1s later. */
function mh_print_sale_raw_async(int $saleId): void
{
    $txt = mh_receipt_escpos($saleId);
    if ($txt === null) {
        return;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'mhrcpt');
    file_put_contents($tmp, $txt);
    $cmd = 'start /B "" powershell.exe -NoProfile -ExecutionPolicy Bypass -File '
         . escapeshellarg(MH_RAW_PRINT_PS1)
         . ' -Path ' . escapeshellarg($tmp)
         . ' -Printer ' . escapeshellarg(MH_PRINTER_NAME);
    pclose(popen($cmd, 'r'));
}
