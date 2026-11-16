<?php
require_once 'config.php';
if ($_SESSION['role'] === 'Citizen') { header("Location: citizen_dashboard.php"); exit; }
$page_title="Dashboard";
$taxpayers=(int)$conn->query("SELECT COUNT(*) n FROM taxpayers")->fetch()['n'];
$returns=(int)$conn->query("SELECT COUNT(*) n FROM tax_returns")->fetch()['n'];
$pending=(int)$conn->query("SELECT COUNT(*) n FROM tax_returns WHERE status='Pending'")->fetch()['n'];
$submitted=(int)$conn->query("SELECT COUNT(*) n FROM tax_returns WHERE status='Submitted'")->fetch()['n'];
$approved=(int)$conn->query("SELECT COUNT(*) n FROM tax_returns WHERE status='Approved'")->fetch()['n'];
// Overdue returns: pending for more than 7 days (delayed processing alert)
$overdue=(int)$conn->query("SELECT COUNT(*) n FROM tax_returns WHERE status='Pending' AND created_at < NOW() - INTERVAL '7 days'")->fetch()['n'];
$recent=$conn->query("SELECT r.id,r.tax_type,r.tax_period,r.amount,r.status,r.filing_date,t.full_name,t.kra_pin FROM tax_returns r JOIN taxpayers t ON t.id=r.taxpayer_id ORDER BY r.id DESC LIMIT 7");
// Overdue returns list
$overdueList=$conn->query("SELECT r.id,r.tax_type,r.tax_period,r.amount,r.status,r.filing_date,r.created_at,t.full_name,t.kra_pin FROM tax_returns r JOIN taxpayers t ON t.id=r.taxpayer_id WHERE r.status='Pending' AND r.created_at < NOW() - INTERVAL '7 days' ORDER BY r.created_at ASC LIMIT 10");

// ---- Advanced dashboard additions ----
// Revenue collected: total amount of approved returns
$revenue=(float)$conn->query("SELECT COALESCE(SUM(amount),0) n FROM tax_returns WHERE status='Approved'")->fetch()['n'];
// Pending reviews: returns still waiting for an officer (Pending or Submitted), with the amount involved
$pr=$conn->query("SELECT COUNT(*) c, COALESCE(SUM(amount),0) a FROM tax_returns WHERE status IN ('Pending','Submitted')")->fetch(PDO::FETCH_ASSOC);
$pendingReviews=(int)$pr['c']; $pendingAmount=(float)$pr['a'];
// Monthly filing trend: returns filed per month for the last 7 months
$trend=[];
$m0=new DateTime('first day of this month'); $m0->modify('-6 months');
for($i=0;$i<7;$i++){ $trend[$m0->format('Y-m')]=['label'=>$m0->format('M'),'count'=>0]; $m0->modify('+1 month'); }
$trendRows=$conn->query("SELECT to_char(filing_date,'YYYY-MM') ym, COUNT(*) c FROM tax_returns WHERE filing_date >= (date_trunc('month', CURRENT_DATE) - INTERVAL '6 months') GROUP BY 1")->fetchAll(PDO::FETCH_ASSOC);
foreach($trendRows as $tr){ if(isset($trend[$tr['ym']])) $trend[$tr['ym']]['count']=(int)$tr['c']; }
$trendMax=max(1,max(array_column($trend,'count')));
include 'header.php';
?>
<div class="page-heading"><div><p class="eyebrow">OVERVIEW</p><h1>Dashboard</h1><p class="muted">Welcome back, <?=e($_SESSION['full_name'])?>. Here is the branch filing overview.</p></div><a class="btn" href="return_form.php">+ Record tax return</a></div>
<div class="stats"><div class="stat"><span>Total taxpayers</span><strong><?=$taxpayers?></strong><small>Registered records</small></div><div class="stat"><span>Tax returns</span><strong><?=$returns?></strong><small>All filing records</small></div><div class="stat"><span>Pending returns</span><strong><?=$pending?></strong><small>Awaiting action</small></div><div class="stat"><span>Approved returns</span><strong><?=$approved?></strong><small>Processed filings</small></div></div>

<div class="stats"><div class="stat"><span>Revenue collected</span><strong>KSh <?=number_format($revenue,2)?></strong><small>Total of approved returns</small></div><div class="stat"><span>Pending reviews</span><strong><?=$pendingReviews?></strong><small>KSh <?=number_format($pendingAmount,2)?> awaiting review</small></div></div>

<style>
.trend-bars{display:flex;gap:.75rem;padding:.5rem 0 0}
.trend-col{flex:1;display:flex;flex-direction:column;align-items:center}
.trend-barwrap{height:170px;width:100%;display:flex;align-items:flex-end;justify-content:center}
.trend-bar{width:100%;max-width:56px;background:#6f9fd8;border-radius:6px 6px 0 0;min-height:2px}
.trend-count{font-size:.85rem;font-weight:700;margin-top:.4rem}
.trend-month{font-size:.8rem;opacity:.7}
</style>
<section class="panel"><div class="panel-head"><div><h2>Monthly filing trend</h2><p class="muted">Returns filed per month, last 7 months</p></div></div>
<div class="trend-bars">
<?php foreach($trend as $t): ?><div class="trend-col"><div class="trend-barwrap"><div class="trend-bar" style="height:<?=round($t['count']/$trendMax*100)?>%"></div></div><div class="trend-count"><?=(int)$t['count']?></div><div class="trend-month"><?=e($t['label'])?></div></div><?php endforeach; ?>
</div></section>

<?php if ($overdue > 0): ?>
<section class="panel" style="border-left:4px solid #e74c3c;">
<div class="panel-head"><div><h2>⚠ Overdue returns</h2><p class="muted">Returns pending for more than 7 days — requires immediate attention to reduce processing delays.</p></div><a href="returns.php?status=Pending" class="text-link">View all pending →</a></div>
<div class="table-wrap"><table><thead><tr><th>Taxpayer</th><th>PIN</th><th>Tax type</th><th>Period</th><th>Amount (KSh)</th><th>Days pending</th></tr></thead><tbody>
<?php while($r=$overdueList->fetch(PDO::FETCH_ASSOC)):
$daysPending = floor((time() - strtotime($r['created_at'])) / 86400);
?><tr><td><?=e($r['full_name'])?></td><td><?=e($r['kra_pin'])?></td><td><?=e($r['tax_type'])?></td><td><?=e($r['tax_period'])?></td><td><?=number_format((float)$r['amount'],2)?></td><td><strong><?=$daysPending?> days</strong></td></tr><?php endwhile; ?>
</tbody></table></div></section>
<?php endif; ?>

<section class="panel"><div class="panel-head"><div><h2>Recent tax returns</h2><p class="muted">Latest recorded filing activity</p></div><a href="returns.php" class="text-link">View all →</a></div>
<div class="table-wrap"><table><thead><tr><th>Taxpayer</th><th>PIN</th><th>Tax type</th><th>Period</th><th>Amount (KSh)</th><th>Status</th></tr></thead><tbody>
<?php if($recent && $recent->rowCount()): while($r=$recent->fetch(PDO::FETCH_ASSOC)): ?><tr><td><?=e($r['full_name'])?></td><td><?=e($r['kra_pin'])?></td><td><?=e($r['tax_type'])?></td><td><?=e($r['tax_period'])?></td><td><?=number_format((float)$r['amount'],2)?></td><td><span class="status <?=strtolower(e($r['status']))?>"><?=e($r['status'])?></span></td></tr><?php endwhile; else: ?><tr><td colspan="6" class="empty">No returns recorded yet. Add a taxpayer, then record a return.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php include 'footer.php'; ?>