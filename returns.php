<?php
require_once 'config.php'; $page_title="Tax Returns";
if(isset($_GET['delete'])){$id=(int)$_GET['delete'];$s=$conn->prepare("SELECT tax_type,tax_period FROM tax_returns WHERE id=:id");$s->execute([':id'=>$id]);$rec=$s->fetch(PDO::FETCH_ASSOC);$s2=$conn->prepare("DELETE FROM tax_returns WHERE id=:id");$s2->execute([':id'=>$id]);if($rec)audit_log('DELETE','tax_returns',$id,['tax_type'=>$rec['tax_type'],'tax_period'=>$rec['tax_period']],null);flash("Tax return deleted.");header("Location: returns.php");exit;}
// Filter by status
$statusFilter = trim($_GET['status'] ?? '');
$where = "WHERE 1=1";
$params = [];
if ($statusFilter && in_array($statusFilter, ['Pending', 'Submitted', 'Approved', 'Rejected'])) {
    $where .= " AND r.status = :st";
    $params[':st'] = $statusFilter;
}
$q=trim($_GET['q']??'');
if($q!==''){$where.=" AND (t.full_name LIKE :a OR t.kra_pin LIKE :b OR r.tax_type LIKE :c)"; $params[':a']="%".$q."%"; $params[':b']="%".$q."%"; $params[':c']="%".$q."%";}
$s=$conn->prepare("SELECT r.*,t.full_name,t.kra_pin FROM tax_returns r JOIN taxpayers t ON t.id=r.taxpayer_id $where ORDER BY r.id DESC");$s->execute($params);$rows=$s;
include 'header.php';?>
<div class="page-heading"><div><p class="eyebrow">FILING RECORDS</p><h1>Tax returns</h1><p class="muted">Track submitted and pending tax filings.</p></div><a class="btn" href="return_form.php">+ Record return</a></div>
<section class="panel">
<div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:1rem;align-items:center;">
<form class="search" method="get" style="margin:0;flex:1;min-width:200px;"><input name="q" value="<?=e($q)?>" placeholder="Search taxpayer, PIN or tax type"><button class="btn secondary">Search</button></form>
<a href="returns.php" class="text-link <?=($statusFilter===''?'active':'')?>">All</a>
<?php foreach(['Pending','Submitted','Approved','Rejected'] as $st):
$stmt=$conn->prepare("SELECT COUNT(*) as n FROM tax_returns WHERE status=:s");$stmt->execute([':s'=>$st]);$cnt=(int)$stmt->fetch(PDO::FETCH_ASSOC)['n'];
?><a href="returns.php?status=<?=$st?>" class="text-link <?=($statusFilter===$st?'active':'')?>"><?=$st?> (<?=$cnt?>)</a><?php endforeach; ?>
</div>
<div class="table-wrap"><table><thead><tr><th>Taxpayer</th><th>PIN</th><th>Tax type</th><th>Period</th><th>Amount (KSh)</th><th>Filing date</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php $rowsArr=$rows->fetchAll(PDO::FETCH_ASSOC);if(!empty($rowsArr)):foreach($rowsArr as $r):?><tr><td><?=e($r['full_name'])?></td><td><?=e($r['kra_pin'])?></td><td><?=e($r['tax_type'])?></td><td><?=e($r['tax_period'])?></td><td><?=number_format((float)$r['amount'],2)?></td><td><?=e($r['filing_date'])?></td><td><span class="status <?=strtolower(e($r['status']))?>"><?=e($r['status'])?></span></td><td class="actions"><a href="return_form.php?id=<?=$r['id']?>">Edit</a><a class="delete-link" data-confirm="Delete this tax return?" href="returns.php?delete=<?=$r['id']?>">Delete</a></td></tr><?php endforeach;else:?><tr><td colspan="8" class="empty">No tax returns found. Record your first return.</td></tr><?php endif;?></tbody></table></div></section><?php include 'footer.php';?>
