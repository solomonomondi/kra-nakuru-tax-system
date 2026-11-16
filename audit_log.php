<?php
require_once 'config.php';
if ($_SESSION['role'] === 'Citizen') { header("Location: citizen_dashboard.php"); exit; }
// Only Admins can view the full audit log; Officers see only their own entries
$isAdmin = ($_SESSION['role'] === 'Admin');

// Filters
$tableFilter = trim($_GET['table'] ?? '');
$actionFilter = trim($_GET['action'] ?? '');
$where = "";
$params = [];

if (!$isAdmin) {
    $where = "WHERE user_id = :uid";
    $params[':uid'] = $_SESSION['user_id'];
}

if ($tableFilter && in_array($tableFilter, ['taxpayers', 'tax_returns', 'users'])) {
    $where .= $isAdmin ? " WHERE table_name = :tn" : " AND table_name = :tn";
    $params[':tn'] = $tableFilter;
}

if ($actionFilter && in_array($actionFilter, ['CREATE', 'UPDATE', 'DELETE'])) {
    $where .= $isAdmin ? " WHERE action = :ac" : " AND action = :ac";
    $params[':ac'] = $actionFilter;
}

$stmt = $conn->prepare("SELECT a.*, u.full_name, u.username FROM audit_log a JOIN users u ON u.id = a.user_id $where ORDER BY a.id DESC LIMIT 200");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Counts for filter tabs
$tableCounts = $conn->query("SELECT table_name, COUNT(*) as n FROM audit_log GROUP BY table_name ORDER BY table_name")->fetchAll(PDO::FETCH_KEY_PAIR);
$actionCounts = $conn->query("SELECT action, COUNT(*) as n FROM audit_log GROUP BY action ORDER BY action")->fetchAll(PDO::FETCH_KEY_PAIR);

$page_title = "Audit Log";
include 'header.php';
?>

<div class="page-heading">
    <div>
        <p class="eyebrow">ACCOUNTABILITY</p>
        <h1>Audit trail</h1>
        <p class="muted">Track all changes made in the system for accountability and record reconciliation.</p>
    </div>
</div>

<section class="panel">
    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:1rem;">
        <span style="font-weight:600;line-height:28px;">Table:</span>
        <a href="audit_log.php" class="text-link <?=($tableFilter===''?'active':'')?>">All</a>
        <?php foreach (['taxpayers', 'tax_returns', 'users'] as $t): ?>
        <a href="audit_log.php?table=<?=$t?>" class="text-link <?=($tableFilter===$t?'active':'')?>"><?=ucfirst($t)?> (<?=$tableCounts[$t] ?? 0?>)</a>
        <?php endforeach; ?>
    </div>

    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:1rem;">
        <span style="font-weight:600;line-height:28px;">Action:</span>
        <a href="audit_log.php<?=($tableFilter?'?table='.$tableFilter:'')?>" class="text-link <?=($actionFilter===''?'active':'')?>">All</a>
        <?php foreach (['CREATE', 'UPDATE', 'DELETE'] as $a): ?>
        <a href="audit_log.php<?=($tableFilter?'?table='.$tableFilter:'')?>&action=<?=$a?>" class="text-link <?=($actionFilter===$a?'active':'')?>"><?=$a?> (<?=$actionCounts[$a] ?? 0?>)</a>
        <?php endforeach; ?>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Table</th>
                    <th>Record</th>
                    <th>Details</th>
                    <th>IP Address</th>
                    <th>Timestamp</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($rows)): foreach ($rows as $r): ?>
                <tr>
                    <td><?=$r['id']?></td>
                    <td><?=e($r['full_name'])?> <small style="color:var(--muted)">(<?=e($r['username'])?>)</small></td>
                    <td><span class="status <?=strtolower(e($r['action']))?>"><?=e($r['action'])?></span></td>
                    <td><?=e($r['table_name'])?></td>
                    <td>#<?=$r['record_id']?></td>
                    <td>
                        <?php if ($r['old_values']): ?>
                            <small style="color:#e74c3c;">Before: <?=e(substr(json_decode($r['old_values'], true) ? json_encode(json_decode($r['old_values'], true), JSON_PRETTY_PRINT) : e($r['old_values']), 0, 120))?></small><br>
                        <?php endif; ?>
                        <?php if ($r['new_values']): ?>
                            <small style="color:#27ae60;">After: <?=e(substr(json_decode($r['new_values'], true) ? json_encode(json_decode($r['new_values'], true), JSON_PRETTY_PRINT) : e($r['new_values']), 0, 120))?></small>
                        <?php endif; ?>
                        <?php if (!$r['old_values'] && !$r['new_values']): ?>—<?php endif; ?>
                    </td>
                    <td><?=e($r['ip_address'] ?: '—')?></td>
                    <td><?=date('Y-m-d H:i:s', strtotime($r['created_at']))?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="8" class="empty">No audit records found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include 'footer.php'; ?>
