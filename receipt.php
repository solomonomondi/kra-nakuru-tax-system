<?php
require_once 'config.php';
require_login();
$id = (int)($_GET['id'] ?? 0);

$s = $conn->prepare("SELECT p.id, p.amount, p.mode, p.receipt_no, p.paid_at, p.return_id,
    r.tax_type, r.tax_period, r.amount AS return_amount, r.taxpayer_id,
    t.full_name, t.kra_pin, u.full_name AS officer, s.mpesa_receipt_no
    FROM payments p
    JOIN tax_returns r ON r.id = p.return_id
    JOIN taxpayers t ON t.id = r.taxpayer_id
    LEFT JOIN users u ON u.id = p.recorded_by
    LEFT JOIN mpesa_stk_requests s ON s.payment_id = p.id
    WHERE p.id = :id");
$s->execute([':id' => $id]);
$p = $s->fetch(PDO::FETCH_ASSOC);

if (!$p) { http_response_code(404); echo "Receipt not found."; exit; }
// Citizens may only open receipts for their own returns
if ($_SESSION['role'] === 'Citizen' && (int)$p['taxpayer_id'] !== (int)($_SESSION['taxpayer_id'] ?? 0)) {
    http_response_code(403); echo "Access denied."; exit;
}

// Balance remaining after this payment
$q = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE return_id = :r AND id <= :id");
$q->execute([':r' => $p['return_id'], ':id' => $p['id']]);
$balance = max(0, round((float)$p['return_amount'] - (float)$q->fetchColumn(), 2));

$backUrl = ($_SESSION['role'] === 'Citizen') ? 'citizen_filings.php' : 'payments.php';
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Receipt <?=e($p['receipt_no'])?> | KRA Nakuru</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:#eef3f5;color:#1b3a5c}
.bar{max-width:640px;margin:1.25rem auto 0;padding:0 1rem;display:flex;justify-content:space-between;align-items:center}
.bar a,.bar button{color:#1b3a5c;font-size:.9rem}
.bar button{padding:.5rem 1.1rem;border:0;border-radius:6px;background:#2e7d32;color:#fff;font-weight:700;cursor:pointer}
.receipt{max-width:640px;margin:1rem auto 2rem;background:#fff;border:1px solid #c5d2d8;border-radius:10px;overflow:hidden}
.head{background:#1b3a5c;color:#fff;padding:1.1rem 1.5rem}
.head h1{margin:0;font-size:1.15rem}
.head p{margin:.25rem 0 0;font-size:.82rem;opacity:.85}
.body{padding:1.5rem}
.no{display:flex;justify-content:space-between;margin-bottom:1rem;font-size:.9rem}
.no strong{font-size:1.05rem}
table{width:100%;border-collapse:collapse;font-size:.92rem}
td{padding:.55rem 0;border-bottom:1px solid #e3e9f2}
td:first-child{color:#6b7a8a;width:42%}
.total td{border-bottom:0;font-weight:700;font-size:1.05rem;padding-top:1rem}
.foot{padding:1rem 1.5rem;background:#f1f6f5;font-size:.78rem;color:#6b7a8a;text-align:center}
@media print{body{background:#fff}.bar{display:none}.receipt{border:0;margin:0;max-width:none}}
</style></head>
<body>
<div class="bar"><a href="<?=e($backUrl)?>">&larr; Back</a><button onclick="window.print()">Print receipt</button></div>
<div class="receipt">
  <div class="head"><h1>Kenya Revenue Authority &middot; Nakuru Branch</h1><p>Tax Payment Receipt</p></div>
  <div class="body">
    <div class="no"><span>Receipt no.<br><strong><?=e($p['receipt_no'])?></strong></span><span style="text-align:right">Date<br><strong><?=e(date('d M Y, H:i', strtotime($p['paid_at'])))?></strong></span></div>
    <table>
      <tr><td>Taxpayer</td><td><?=e($p['full_name'])?></td></tr>
      <tr><td>KRA PIN</td><td><?=e($p['kra_pin'])?></td></tr>
      <tr><td>Tax type</td><td><?=e($p['tax_type'])?></td></tr>
      <tr><td>Tax period</td><td><?=e($p['tax_period'])?></td></tr>
      <tr><td>Return total</td><td>KSh <?=number_format((float)$p['return_amount'], 2)?></td></tr>
      <tr><td>Payment mode</td><td><?=e($p['mode'])?></td></tr>
      <?php if ($p['mpesa_receipt_no']): ?><tr><td>M-Pesa transaction</td><td><?=e($p['mpesa_receipt_no'])?></td></tr><?php endif; ?>
      <tr><td>Balance after this payment</td><td>KSh <?=number_format($balance, 2)?></td></tr>
      <tr class="total"><td>Amount paid</td><td>KSh <?=number_format((float)$p['amount'], 2)?></td></tr>
    </table>
  </div>
  <div class="foot">Received by <?=e($p['officer'] ?? 'KRA officer')?> &middot; Keep this receipt as proof of payment.</div>
</div>
</body></html>