<?php
require_once 'config.php';
if (empty($_SESSION['user_id'])) { header("Location: login.php"); exit; }
if ($_SESSION['role'] !== 'Citizen') { header("Location: dashboard.php"); exit; }

$taxpayerId = $_SESSION['taxpayer_id'] ?? null;

if (!$taxpayerId) {
    flash("Complete your profile first.", "warning");
    header("Location: citizen_profile.php");
    exit;
}

// Filter by status if selected
$statusFilter = trim($_GET['status'] ?? '');
$where = "WHERE t.id = :tid";
$params = [':tid' => $taxpayerId];
if ($statusFilter && in_array($statusFilter, ['Pending', 'Submitted', 'Approved', 'Rejected'])) {
    $where .= " AND r.status = :st";
    $params[':st'] = $statusFilter;
}

$params[':uid'] = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT r.*,
    COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.return_id = r.id), 0) AS paid,
    COALESCE((SELECT SUM(s.amount) FROM mpesa_stk_requests s
        WHERE s.return_id = r.id AND s.status IN ('Pending', 'Review')), 0) AS reserved,
    COALESCE((SELECT MAX(s.id) FROM mpesa_stk_requests s
        WHERE s.return_id = r.id AND s.status IN ('Pending', 'Review')), 0) AS active_request_id
    FROM tax_returns r JOIN taxpayers t ON t.id = r.taxpayer_id $where AND t.user_id = :uid ORDER BY r.id DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "My Filings";
include 'header.php';
?>

<div class="page-heading">
    <div>
        <p class="eyebrow">MY FILINGS</p>
        <h1>Tax return history</h1>
        <p class="muted">Track the status of your submitted returns.</p>
    </div>
    <a class="btn" href="citizen_filing.php">+ File a return</a>
</div>

<section class="panel">
    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:1rem;">
        <a href="citizen_filings.php" class="text-link <?=($statusFilter===''?'active':'')?>">All (<?=count($rows)?>)</a>
        <?php
        $statuses = ['Pending', 'Submitted', 'Approved', 'Rejected'];
        foreach ($statuses as $s):
            $stmt = $conn->prepare("SELECT COUNT(*) as n FROM tax_returns WHERE taxpayer_id = :tid AND status = :st");
            $stmt->execute([':tid' => $taxpayerId, ':st' => $s]);
            $count = (int)$stmt->fetch(PDO::FETCH_ASSOC)['n'];
        ?>
        <a href="citizen_filings.php?status=<?=$s?>" class="text-link <?=($statusFilter===$s?'active':'')?>"><?=$s?> (<?=$count?>)</a>
        <?php endforeach; ?>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Tax type</th>
                    <th>Period</th>
                    <th>Amount (KSh)</th>
                    <th>Balance (KSh)</th>
                    <th>Filing date</th>
                    <th>Status</th>
                    <th>Payment</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($rows)): foreach ($rows as $r): ?>
                <tr>
                    <td><?=e($r['tax_type'])?></td>
                    <td><?=e($r['tax_period'])?></td>
                    <td><?=number_format((float)$r['amount'], 2)?></td>
                    <td><?=number_format(max(0, (float)$r['amount'] - (float)$r['paid'] - (float)$r['reserved']), 2)?>
                        <?php if ((float)$r['reserved'] > 0): ?><small class="muted"><br>STK pending/review</small><?php endif; ?></td>
                    <td><?=e($r['filing_date'])?></td>
                    <td><span class="status <?=strtolower(e($r['status']))?>"><?=e($r['status'])?></span></td>
                    <td><?php if ($r['status'] === 'Rejected'): ?>—<?php elseif ((float)$r['reserved'] > 0): ?><a class="text-link" href="stk_push.php?id=<?=(int)$r['active_request_id']?>">Check M-Pesa status</a><?php elseif ((float)$r['amount'] > (float)$r['paid']): ?><a class="text-link" href="stk_push.php?return_id=<?=(int)$r['id']?>">Pay with M-Pesa</a><?php else: ?>Paid<?php endif; ?></td>
                    <td><?=e($r['notes'] ?: '—')?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="8" class="empty">No filings found. <a href="citizen_filing.php">File your first return</a></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include 'footer.php'; ?>
