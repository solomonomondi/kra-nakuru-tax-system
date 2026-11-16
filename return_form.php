<?php
require_once 'config.php';
$id = (int)($_GET['id'] ?? 0);
$error = "";
$data = ['taxpayer_id' => '', 'tax_type' => 'Income Tax', 'tax_period' => '', 'amount' => '0.00', 'filing_date' => date('Y-m-d'), 'status' => 'Pending', 'notes' => ''];

if ($id) {
    $s = $conn->prepare("SELECT * FROM tax_returns WHERE id=:id");
    $s->execute([':id' => $id]);
    $f = $s->fetch(PDO::FETCH_ASSOC);
    if ($f) $data = $f;
    else { flash("Return not found.", "danger"); header("Location: returns.php"); exit; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) $error = "Invalid form submission. Please try again.";
    else {
        $tid = (int)($_POST['taxpayer_id'] ?? 0);
        $tax = trim($_POST['tax_type'] ?? '');
        $period = trim($_POST['tax_period'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $date = $_POST['filing_date'] ?? date('Y-m-d');
        $status = $_POST['status'] ?? 'Pending';
        $notes = trim($_POST['notes'] ?? '');

        if (!$tid || $tax === '' || $period === '') $error = "Choose a taxpayer and complete tax type and period.";
        else if ($id) {
            // Track status changes with timestamps and reviewer info
            $oldStatus = $data['status'] ?? '';
            $updatedFields = ['taxpayer_id' => $tid, 'tax_type' => $tax, 'tax_period' => $period, 'amount' => $amount, 'filing_date' => $date, 'status' => $status, 'notes' => $notes];
            $sql = "UPDATE tax_returns SET taxpayer_id=:tid,tax_type=:tx,tax_period=:p,amount=:a,filing_date=:d,status=:s,notes=:n,updated_at=NOW()";
            $params = ['tid' => $tid, 'tx' => $tax, 'p' => $period, 'a' => $amount, 'd' => $date, 's' => $status, 'n' => $notes, 'id' => $id];
            // If status changed to Approved or Rejected, record review info
            if ($status !== $oldStatus && in_array($status, ['Approved', 'Rejected'])) {
                $sql .= ",reviewed_at=NOW(),reviewed_by=:rby";
                $params['rby'] = $_SESSION['user_id'];
            }
            $sql .= " WHERE id=:id";
            $s = $conn->prepare($sql);
            if ($s->execute($params)) {
                audit_log('UPDATE', 'tax_returns', $id, ['status' => $oldStatus], ['status' => $status, 'tax_type' => $tax]);
                flash("Tax return updated."); header("Location: returns.php"); exit;
            } else $error = "Could not update the return.";
        } else {
            $s = $conn->prepare("INSERT INTO tax_returns(taxpayer_id,tax_type,tax_period,amount,filing_date,status,notes) VALUES(:tid,:tx,:p,:a,:d,:s,:n)");
            if ($s->execute([':tid' => $tid, ':tx' => $tax, ':p' => $period, ':a' => $amount, ':d' => $date, ':s' => $status, ':n' => $notes])) {
                $newId = (int)$conn->lastInsertId();
                audit_log('CREATE', 'tax_returns', $newId, null, ['taxpayer_id' => $tid, 'tax_type' => $tax, 'status' => $status]);
                flash("Tax return recorded."); header("Location: returns.php"); exit;
            } else $error = "Could not save the return.";
        }
    }
}

$taxpayers = $conn->query("SELECT id,full_name,kra_pin FROM taxpayers ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
$page_title = $id ? "Edit return" : "Record return";
include 'header.php';
?>
<div class="page-heading">
    <div><p class="eyebrow">FILING RECORD</p><h1><?=$id ? 'Edit tax return' : 'Record tax return'?></h1></div>
    <a class="btn secondary" href="returns.php">Back</a>
</div>
<section class="panel form-panel">
    <?php if ($error): ?><div class="alert danger"><?=e($error)?></div><?php endif; ?>
    <?php if (empty($taxpayers)): ?>
        <div class="alert warning">Register a taxpayer before recording a tax return.</div>
        <a class="btn" href="taxpayer_form.php">Add taxpayer</a>
    <?php else: ?>
        <form method="post" class="form-grid">
            <?php csrf_field(); ?>
            <div class="span-2">
                <label>Taxpayer *</label>
                <select name="taxpayer_id" required>
                    <option value="">Select taxpayer</option>
                    <?php foreach ($taxpayers as $t): ?>
                        <option value="<?=$t['id']?>" <?=((int)$data['taxpayer_id'] === (int)$t['id'] ? 'selected' : '')?>><?=e($t['full_name'])?> — <?=e($t['kra_pin'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Tax type *</label>
                <select name="tax_type">
                    <?php foreach (['Income Tax', 'VAT', 'PAYE', 'Turnover Tax', 'Corporation Tax', 'Other'] as $v): ?>
                        <option <?=($data['tax_type'] === $v ? 'selected' : '')?>><?=e($v)?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label>Tax period *</label><input name="tax_period" placeholder="e.g. October 2026" required value="<?=e($data['tax_period'])?>"></div>
            <div><label>Amount (KSh)</label><input type="number" name="amount" min="0" step="0.01" value="<?=e($data['amount'])?>"></div>
            <div><label>Filing date</label><input type="date" name="filing_date" required value="<?=e($data['filing_date'])?>"></div>
            <div>
                <label>Status</label>
                <select name="status">
                    <?php foreach (['Pending', 'Submitted', 'Approved', 'Rejected'] as $v): ?>
                        <option <?=($data['status'] === $v ? 'selected' : '')?>><?=$v?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="span-2"><label>Notes</label><textarea name="notes" rows="3"><?=e($data['notes'])?></textarea></div>
            <div class="span-2"><button class="btn">Save tax return</button></div>
        </form>
    <?php endif; ?>
</section>
<?php include 'footer.php'; ?>
