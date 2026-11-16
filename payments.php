<?php
require_once 'config.php';
require_login();
if ($_SESSION['role'] === 'Citizen') { header("Location: citizen_dashboard.php"); exit; }
$page_title = "Payments";
$modes = ['M-Pesa', 'Bank transfer', 'Cash', 'Cheque', 'Card'];
$error = "";
$returnId = (int)($_GET['return_id'] ?? $_POST['return_id'] ?? 0);

function load_return($conn, $rid) {
    $s = $conn->prepare("SELECT r.id, r.tax_type, r.tax_period, r.amount, r.status, t.full_name, t.kra_pin,
        COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.return_id = r.id), 0) AS paid,
        COALESCE((SELECT SUM(s.amount) FROM mpesa_stk_requests s
            WHERE s.return_id = r.id AND s.status IN ('Pending', 'Review')), 0) AS reserved,
        COALESCE((SELECT MAX(s.id) FROM mpesa_stk_requests s
            WHERE s.return_id = r.id AND s.status IN ('Pending', 'Review')), 0) AS active_request_id
        FROM tax_returns r JOIN taxpayers t ON t.id = r.taxpayer_id WHERE r.id = :id");
    $s->execute([':id' => $rid]);
    return $s->fetch(PDO::FETCH_ASSOC);
}

$ret = $returnId ? load_return($conn, $returnId) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mode'])) {
    $mode = $_POST['mode'] ?? '';
    $amount = round((float)str_replace(',', '', $_POST['amount'] ?? '0'), 2);
    if (!verify_csrf()) { $error = 'Invalid form submission.'; }
    else if (!$ret) { $error = 'Tax return not found.'; }
    else if ($ret['status'] === 'Rejected') { $error = 'Payments cannot be recorded against a rejected return.'; }
    else if (!in_array($mode, $modes, true)) { $error = 'Choose a payment mode.'; }
    else if ($amount <= 0) { $error = 'Enter an amount greater than zero.'; }
    else {
        try {
            $conn->beginTransaction();
            // Lock the return so two payments at the same time cannot overpay it
            $lock = $conn->prepare("SELECT amount FROM tax_returns WHERE id = :id FOR UPDATE");
            $lock->execute([':id' => $returnId]);
            $due = (float)$lock->fetchColumn();
            $paidStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE return_id = :id");
            $paidStmt->execute([':id' => $returnId]);
            $reservedStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM mpesa_stk_requests
                WHERE return_id = :id AND status IN ('Pending', 'Review')");
            $reservedStmt->execute([':id' => $returnId]);
            $balance = round($due - (float)$paidStmt->fetchColumn() - (float)$reservedStmt->fetchColumn(), 2);
            if ($amount > $balance) {
                $conn->rollBack();
                $error = 'The amount is more than the outstanding balance of KSh ' . number_format($balance, 2) . '.';
            } else {
                $ins = $conn->prepare("INSERT INTO payments (return_id, amount, mode, receipt_no, recorded_by) VALUES (:r, :a, :m, :tmp, :u) RETURNING id");
                $ins->execute([':r' => $returnId, ':a' => $amount, ':m' => $mode, ':tmp' => 'TMP' . bin2hex(random_bytes(8)), ':u' => $_SESSION['user_id']]);
                $pid = (int)$ins->fetchColumn();
                $receiptNo = 'RCT-' . date('Ymd') . '-' . str_pad((string)$pid, 5, '0', STR_PAD_LEFT);
                $upd = $conn->prepare("UPDATE payments SET receipt_no = :n WHERE id = :id");
                $upd->execute([':n' => $receiptNo, ':id' => $pid]);
                $conn->commit();
                audit_log('create', 'payments', $pid, null, ['return_id' => $returnId, 'amount' => $amount, 'mode' => $mode, 'receipt_no' => $receiptNo]);
                flash("Payment recorded. Receipt $receiptNo issued.");
                header("Location: receipt.php?id=" . $pid); exit;
            }
        } catch (Exception $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            $error = 'Could not record the payment. Please try again.';
        }
    }
    $ret = $returnId ? load_return($conn, $returnId) : null;
}

// Summary figures
$totalPaid = (float)$conn->query("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN tax_returns r ON r.id = p.return_id WHERE r.status <> 'Rejected'")->fetchColumn();
$totalDue = (float)$conn->query("SELECT COALESCE(SUM(amount),0) FROM tax_returns WHERE status <> 'Rejected'")->fetchColumn();
$paymentCount = (int)$conn->query("SELECT COUNT(*) FROM payments")->fetchColumn();

// Returns with their balances
$balances = $conn->query("SELECT r.id, r.tax_type, r.tax_period, r.amount, r.status, t.full_name, t.kra_pin,
    COALESCE(SUM(p.amount), 0) AS paid,
    COALESCE((SELECT SUM(s.amount) FROM mpesa_stk_requests s
        WHERE s.return_id = r.id AND s.status IN ('Pending', 'Review')), 0) AS reserved,
    COALESCE((SELECT MAX(s.id) FROM mpesa_stk_requests s
        WHERE s.return_id = r.id AND s.status IN ('Pending', 'Review')), 0) AS active_request_id
    FROM tax_returns r JOIN taxpayers t ON t.id = r.taxpayer_id LEFT JOIN payments p ON p.return_id = r.id
    WHERE r.status <> 'Rejected'
    GROUP BY r.id, t.id ORDER BY r.id DESC LIMIT 50");

// Recent payments
$recentPayments = $conn->query("SELECT p.id, p.amount, p.mode, p.receipt_no, p.paid_at, t.full_name, t.kra_pin, r.tax_type, r.tax_period
    FROM payments p JOIN tax_returns r ON r.id = p.return_id JOIN taxpayers t ON t.id = r.taxpayer_id
    ORDER BY p.id DESC LIMIT 20");
include 'header.php';
?>
<div class="page-heading"><div><p class="eyebrow">FINANCE</p><h1>Payments</h1><p class="muted">Record payments against tax returns and issue receipts.</p></div></div>

<div class="stats">
<div class="stat"><span>Payments collected</span><strong>KSh <?=number_format($totalPaid, 2)?></strong><small><?=$paymentCount?> payment(s) recorded</small></div>
<div class="stat"><span>Outstanding balance</span><strong>KSh <?=number_format(max(0, $totalDue - $totalPaid), 2)?></strong><small>Across returns that are not rejected</small></div>
</div>

<?php if ($error): ?><div class="alert danger"><?=e($error)?></div><?php endif; ?>

<?php if ($returnId): ?>
<section class="panel">
<?php if (!$ret): ?>
    <div class="panel-head"><div><h2>Record payment</h2></div></div>
    <p class="muted">Tax return not found.</p>
<?php else:
    $balance = round((float)$ret['amount'] - (float)$ret['paid'] - (float)$ret['reserved'], 2); ?>
    <div class="panel-head"><div><h2>Record payment</h2><p class="muted"><?=e($ret['full_name'])?> (<?=e($ret['kra_pin'])?>) &middot; <?=e($ret['tax_type'])?>, <?=e($ret['tax_period'])?> &middot; Return total KSh <?=number_format((float)$ret['amount'], 2)?> &middot; Paid KSh <?=number_format((float)$ret['paid'], 2)?> &middot; Pending/review STK KSh <?=number_format((float)$ret['reserved'], 2)?> &middot; <strong>Available balance KSh <?=number_format($balance, 2)?></strong></p></div></div>
    <?php if ($ret['status'] === 'Rejected'): ?>
        <p class="muted">This return was rejected, so it cannot take payments.</p>
    <?php elseif ($balance <= 0): ?>
        <p class="muted"><?=(float)$ret['reserved'] > 0 ? 'The available balance is reserved by an M-Pesa request.' : 'This return is paid in full.'?></p>
        <?php if ((float)$ret['reserved'] > 0): ?><a class="btn" href="stk_push.php?id=<?=(int)$ret['active_request_id']?>">Check M-Pesa status</a><?php endif; ?>
    <?php else: ?>
    <form method="post" class="form-grid"><?php csrf_field(); ?>
        <input type="hidden" name="return_id" value="<?=$returnId?>">
        <div><label>Amount (KSh)</label><input type="number" name="amount" step="0.01" min="0.01" max="<?=number_format($balance, 2, '.', '')?>" required value="<?=e($_POST['amount'] ?? number_format($balance, 2, '.', ''))?>"></div>
        <div><label>Payment mode</label>
            <select name="mode" required>
                <option value="">Select mode</option>
                <?php foreach ($modes as $m): ?><option value="<?=e($m)?>" <?= (($_POST['mode'] ?? '') === $m) ? 'selected' : '' ?>><?=e($m)?></option><?php endforeach; ?>
            </select></div>
        <div class="span-2"><button class="btn">Record payment and issue receipt</button> <a class="text-link" href="stk_push.php?return_id=<?=$returnId?>">Send M-Pesa prompt</a> <a class="text-link" href="payments.php">Cancel</a></div>
    </form>
    <?php endif; ?>
<?php endif; ?>
</section>
<?php endif; ?>

<section class="panel"><div class="panel-head"><div><h2>Returns and balances</h2><p class="muted">Latest 50 returns that are not rejected</p></div></div>
<div class="table-wrap"><table><thead><tr><th>Taxpayer</th><th>PIN</th><th>Tax type</th><th>Period</th><th>Amount (KSh)</th><th>Paid (KSh)</th><th>Balance (KSh)</th><th>Status</th><th></th></tr></thead><tbody>
<?php $any = false; while ($r = $balances->fetch(PDO::FETCH_ASSOC)): $any = true;
    $bal = round((float)$r['amount'] - (float)$r['paid'] - (float)$r['reserved'], 2); ?>
<tr><td><?=e($r['full_name'])?></td><td><?=e($r['kra_pin'])?></td><td><?=e($r['tax_type'])?></td><td><?=e($r['tax_period'])?></td><td><?=number_format((float)$r['amount'], 2)?></td><td><?=number_format((float)$r['paid'], 2)?></td><td><?=number_format(max(0, $bal), 2)?><?php if ((float)$r['reserved'] > 0): ?><small class="muted"><br>KSh <?=number_format((float)$r['reserved'], 2)?> pending/review</small><?php endif; ?></td><td><span class="status <?=strtolower(e($r['status']))?>"><?=e($r['status'])?></span></td><td><?php if ((float)$r['reserved'] > 0): ?><a class="text-link" href="stk_push.php?id=<?=(int)$r['active_request_id']?>">Check M-Pesa status</a><br><?php endif; ?><?php if ($bal > 0): ?><a class="text-link" href="payments.php?return_id=<?=(int)$r['id']?>">Record payment</a><?php if ((float)$r['reserved'] <= 0): ?><br><a class="text-link" href="stk_push.php?return_id=<?=(int)$r['id']?>">Send M-Pesa prompt</a><?php endif; ?><?php else: ?><?php if ((float)$r['reserved'] <= 0): ?>Paid<?php endif; ?><?php endif; ?></td></tr>
<?php endwhile; if (!$any): ?><tr><td colspan="9" class="empty">No returns yet. Record a tax return first.</td></tr><?php endif; ?>
</tbody></table></div></section>

<section class="panel"><div class="panel-head"><div><h2>Recent payments</h2><p class="muted">Latest 20 receipts</p></div></div>
<div class="table-wrap"><table><thead><tr><th>Receipt no.</th><th>Date</th><th>Taxpayer</th><th>Tax type</th><th>Mode</th><th>Amount (KSh)</th><th></th></tr></thead><tbody>
<?php $anyP = false; while ($p = $recentPayments->fetch(PDO::FETCH_ASSOC)): $anyP = true; ?>
<tr><td><?=e($p['receipt_no'])?></td><td><?=e(date('d M Y H:i', strtotime($p['paid_at'])))?></td><td><?=e($p['full_name'])?> (<?=e($p['kra_pin'])?>)</td><td><?=e($p['tax_type'])?>, <?=e($p['tax_period'])?></td><td><?=e($p['mode'])?></td><td><?=number_format((float)$p['amount'], 2)?></td><td><a class="text-link" href="receipt.php?id=<?=(int)$p['id']?>">View receipt</a></td></tr>
<?php endwhile; if (!$anyP): ?><tr><td colspan="7" class="empty">No payments recorded yet.</td></tr><?php endif; ?>
</tbody></table></div></section>

<section class="panel"><div class="panel-head"><div><h2>Export report</h2><p class="muted">Download tax returns with amounts paid and balances as a CSV file you can open in Excel.</p></div></div>
<form method="get" action="export_report.php" class="form-grid">
    <div><label>Return status</label>
        <select name="status"><option value="">All statuses</option><?php foreach (['Pending', 'Submitted', 'Approved', 'Rejected'] as $s): ?><option value="<?=e($s)?>"><?=e($s)?></option><?php endforeach; ?></select></div>
    <div class="span-2"><button class="btn">Download CSV</button></div>
</form></section>
<?php include 'footer.php'; ?>