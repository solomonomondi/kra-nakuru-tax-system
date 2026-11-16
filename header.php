<?php require_once 'config.php'; require_login(); $role = $_SESSION['role'] ?? ''; ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?=e($page_title ?? 'Dashboard')?> | KRA Nakuru</title><link rel="stylesheet" href="style.css"></head><body>
<?php if ($role === 'Citizen'): ?>
<header class="topbar"><a class="brand" href="citizen_dashboard.php"><span class="brand-mark small">KRA</span><span>KRA Nakuru <small>Citizen Portal</small></span></a><div class="user-area"><span><?=e($_SESSION['full_name'])?></span><a href="logout.php" class="logout">Log out</a></div></header>
<div class="layout"><aside class="sidebar"><a href="citizen_dashboard.php">▦ &nbsp; Dashboard</a><a href="citizen_profile.php">♙ &nbsp; My Profile</a><a href="citizen_filings.php">▤ &nbsp; My Filings</a><a href="citizen_filing.php">+ &nbsp; File a Return</a></aside><main class="content"><?php show_flash(); ?>
<?php else: ?>
<header class="topbar"><a class="brand" href="dashboard.php"><span class="brand-mark small">KRA</span><span>KRA Nakuru <small>Tax Filing Management</small></span></a><div class="user-area"><span><?=e($_SESSION['full_name'])?> · <?=e($_SESSION['role'])?></span><a href="logout.php" class="logout">Log out</a></div></header>
<div class="layout"><aside class="sidebar"><a href="dashboard.php">▦ &nbsp; Dashboard</a><a href="taxpayers.php">♙ &nbsp; Taxpayers</a><a href="returns.php">▤ &nbsp; Tax Returns</a><a href="payments.php">KSh &nbsp; Payments</a><a href="audit_log.php">📋 &nbsp; Audit Trail</a><?php if ($role === 'Admin'): ?><a href="users.php">⚙ &nbsp; User Accounts</a><?php endif; ?></aside><main class="content"><?php show_flash(); ?>
<?php endif; ?>
