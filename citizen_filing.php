<?php
require_once 'config.php';
if (empty($_SESSION['user_id'])) { header("Location: login.php"); exit; }
if ($_SESSION['role'] !== 'Citizen') { header("Location: dashboard.php"); exit; }

$taxpayerId = $_SESSION['taxpayer_id'] ?? null;
$error = "";
$data = ['tax_type' => 'Income Tax', 'tax_period' => '', 'amount' => '0.00', 'filing_date' => date('Y-m-d'), 'notes' => ''];

// Ensure taxpayer record exists
if (!$taxpayerId) {
    header("Location: citizen_profile.php");
    exit;
}

$stmt = $conn->prepare("SELECT id FROM taxpayers WHERE id = :id AND user_id = :uid");
$stmt->execute([':id' => $taxpayerId, ':uid' => $_SESSION['user_id']]);
if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
    flash("You must complete your profile before filing.", "danger");
    header("Location: citizen_profile.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = "Invalid form submission. Please try again.";
    } else {
        $tax = trim($_POST['tax_type'] ?? '');
        $period = trim($_POST['tax_period'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $date = $_POST['filing_date'] ?? date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');

        if ($tax === '' || $period === '') {
            $error = "Tax type and period are required.";
        } else {
            $stmt = $conn->prepare("INSERT INTO tax_returns (taxpayer_id, tax_type, tax_period, amount, filing_date, status, notes, submitted_by) VALUES (:tid, :tx, :p, :a, :d, 'Pending', :n, :uid)");
            if ($stmt->execute([':tid' => $taxpayerId, ':tx' => $tax, ':p' => $period, ':a' => $amount, ':d' => $date, ':n' => $notes, ':uid' => $_SESSION['user_id']])) {
                $newId = (int)$conn->lastInsertId();
                audit_log('CREATE', 'tax_returns', $newId, null, ['taxpayer_id' => $taxpayerId, 'tax_type' => $tax, 'status' => 'Pending']);
                flash("Tax return submitted successfully. Status: Pending.");
                header("Location: citizen_filings.php");
                exit;
            } else {
                $error = "Could not save the return. Please try again.";
            }
        }
    }
}

$page_title = "File a Return";
include 'header.php';
?>

<div class="page-heading">
    <div>
        <p class="eyebrow">TAX FILING</p>
        <h1>Submit a tax return</h1>
        <p class="muted">Fill in the details below to file your return.</p>
    </div>
    <a class="btn secondary" href="citizen_dashboard.php">Back</a>
</div>

<section class="panel form-panel">
    <?php if ($error): ?><div class="alert danger"><?=e($error)?></div><?php endif; ?>
    <form method="post" class="form-grid">
        <?php csrf_field(); ?>
        <div>
            <label>Tax type *</label>
            <select name="tax_type" required>
                <?php foreach (['Income Tax', 'VAT', 'PAYE', 'Turnover Tax', 'Corporation Tax', 'Other'] as $v): ?>
                    <option <?=($data['tax_type'] === $v ? 'selected' : '')?>><?=e($v)?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Tax period *</label>
            <input name="tax_period" placeholder="e.g. October 2026" required value="<?=e($data['tax_period'])?>">
        </div>
        <div>
            <label>Amount (KSh)</label>
            <input type="number" name="amount" min="0" step="0.01" value="<?=e($data['amount'])?>">
        </div>
        <div>
            <label>Filing date</label>
            <input type="date" name="filing_date" required value="<?=e($data['filing_date'])?>">
        </div>
        <div class="span-2">
            <label>Notes (optional)</label>
            <textarea name="notes" rows="3"><?=e($data['notes'])?></textarea>
        </div>
        <div class="span-2">
            <button class="btn">Submit return</button>
            <a class="text-link" href="citizen_dashboard.php">Cancel</a>
        </div>
    </form>
</section>

<?php include 'footer.php'; ?>
