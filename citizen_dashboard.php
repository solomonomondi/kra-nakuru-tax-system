<?php
require_once 'config.php';
if (empty($_SESSION['user_id'])) { header("Location: login.php"); exit; }
if ($_SESSION['role'] !== 'Citizen') { header("Location: dashboard.php"); exit; }

$taxpayerId = $_SESSION['taxpayer_id'] ?? null;
$taxpayer = null;
$returns = [];
$totalReturns = 0;
$pendingReturns = 0;
$approvedReturns = 0;
$rejectedReturns = 0;

if ($taxpayerId) {
    $stmt = $conn->prepare("SELECT * FROM taxpayers WHERE id = :id");
    $stmt->execute([':id' => $taxpayerId]);
    $taxpayer = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare("SELECT COUNT(*) as n FROM tax_returns WHERE taxpayer_id = :tid");
    $stmt->execute([':tid' => $taxpayerId]);
    $totalReturns = (int)$stmt->fetch(PDO::FETCH_ASSOC)['n'];

    $stmt = $conn->prepare("SELECT COUNT(*) as n FROM tax_returns WHERE taxpayer_id = :tid AND status = 'Pending'");
    $stmt->execute([':tid' => $taxpayerId]);
    $pendingReturns = (int)$stmt->fetch(PDO::FETCH_ASSOC)['n'];

    $stmt = $conn->prepare("SELECT COUNT(*) as n FROM tax_returns WHERE taxpayer_id = :tid AND status = 'Approved'");
    $stmt->execute([':tid' => $taxpayerId]);
    $approvedReturns = (int)$stmt->fetch(PDO::FETCH_ASSOC)['n'];

    $stmt = $conn->prepare("SELECT COUNT(*) as n FROM tax_returns WHERE taxpayer_id = :tid AND status = 'Rejected'");
    $stmt->execute([':tid' => $taxpayerId]);
    $rejectedReturns = (int)$stmt->fetch(PDO::FETCH_ASSOC)['n'];

    $stmt = $conn->prepare("SELECT * FROM tax_returns WHERE taxpayer_id = :tid ORDER BY id DESC LIMIT 5");
    $stmt->execute([':tid' => $taxpayerId]);
    $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = "My Dashboard";
include 'header.php';
?>

<div class="page-heading">
    <div>
        <p class="eyebrow">WELCOME</p>
        <h1>Hello, <?=e($_SESSION['full_name'])?></h1>
        <p class="muted">Manage your tax filings from here.</p>
    </div>
    <?php if (!$taxpayerId): ?>
    <a class="btn" href="citizen_profile.php">Complete your profile</a>
    <?php else: ?>
    <a class="btn" href="citizen_filing.php">+ File a return</a>
    <?php endif; ?>
</div>

<?php if (!$taxpayer): ?>
<section class="panel">
    <div style="text-align:center;padding:2rem;">
        <h2>Complete your profile</h2>
        <p class="muted">Add your taxpayer details before you can file returns.</p>
        <a class="btn" href="citizen_profile.php">Set up profile</a>
    </div>
</section>
<?php else: ?>

<div class="stats">
    <div class="stat">
        <span>Total filings</span>
        <strong><?=$totalReturns?></strong>
        <small>All returns</small>
    </div>
    <div class="stat">
        <span>Pending</span>
        <strong><?=$pendingReturns?></strong>
        <small>Awaiting review</small>
    </div>
    <div class="stat">
        <span>Approved</span>
        <strong><?=$approvedReturns?></strong>
        <small>Accepted filings</small>
    </div>
    <div class="stat">
        <span>Rejected</span>
        <strong><?=$rejectedReturns?></strong>
        <small>Needs attention</small>
    </div>
</div>

<section class="panel">
    <div class="panel-head">
        <div>
            <h2>My taxpayer profile</h2>
            <p class="muted">Your registered details</p>
        </div>
        <a href="citizen_profile.php" class="text-link">Edit →</a>
    </div>
    <div class="table-wrap">
        <table>
            <tbody>
                <tr><td><strong>KRA PIN</strong></td><td><?=e($taxpayer['kra_pin'])?></td></tr>
                <tr><td><strong>Full name</strong></td><td><?=e($taxpayer['full_name'])?></td></tr>
                <tr><td><strong>Type</strong></td><td><?=e($taxpayer['taxpayer_type'])?></td></tr>
                <tr><td><strong>Phone</strong></td><td><?=e($taxpayer['phone'] ?: 'Not provided')?></td></tr>
                <tr><td><strong>Email</strong></td><td><?=e($taxpayer['email'] ?: 'Not provided')?></td></tr>
                <tr><td><strong>Address</strong></td><td><?=e($taxpayer['address'] ?: 'Not provided')?></td></tr>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <div class="panel-head">
        <div>
            <h2>Recent filings</h2>
            <p class="muted">Your latest tax return submissions</p>
        </div>
        <a href="citizen_filings.php" class="text-link">View all →</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Tax type</th>
                    <th>Period</th>
                    <th>Amount (KSh)</th>
                    <th>Date</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($returns)): foreach ($returns as $r): ?>
                <tr>
                    <td><?=e($r['tax_type'])?></td>
                    <td><?=e($r['tax_period'])?></td>
                    <td><?=number_format((float)$r['amount'], 2)?></td>
                    <td><?=e($r['filing_date'])?></td>
                    <td><span class="status <?=strtolower(e($r['status']))?>"><?=e($r['status'])?></span></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="5" class="empty">No filings yet. <a href="citizen_filing.php">File your first return</a></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php endif; ?>

<?php include 'footer.php'; ?>
