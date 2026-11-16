<?php
require_once 'config.php';
require_login();
$page_title = "Change Password";
$uid = (int)$_SESSION['user_id'];
$forced = !empty($_SESSION['must_change_password']);   // set at login while the default password is still in use
$error = "";
$maxAttempts = 5;      // wrong "current password" attempts allowed
$lockSeconds = 300;    // lock time after too many failures (5 minutes)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { $error = 'Invalid form submission.'; }
    else if (!empty($_SESSION['pwchange_lock_until']) && time() < $_SESSION['pwchange_lock_until']) {
        $mins = (int)ceil(($_SESSION['pwchange_lock_until'] - time()) / 60);
        $error = "Too many failed attempts. Please try again in $mins minute(s).";
    }
    else {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $stmt = $conn->prepare("SELECT password FROM users WHERE id = :id");
        $stmt->execute([':id' => $uid]);
        $hash = $stmt->fetchColumn();

        if (!$hash || !password_verify($current, $hash)) {
            $_SESSION['pwchange_fails'] = ($_SESSION['pwchange_fails'] ?? 0) + 1;
            if ($_SESSION['pwchange_fails'] >= $maxAttempts) {
                $_SESSION['pwchange_lock_until'] = time() + $lockSeconds;
                $_SESSION['pwchange_fails'] = 0;
                $error = "Too many failed attempts. Please try again in " . ($lockSeconds / 60) . " minutes.";
            } else {
                $error = "Your current password is incorrect.";
            }
        }
        else if (strlen($new) < 8) { $error = "The new password must be at least 8 characters."; }
        else if (!preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) { $error = "Use both letters and numbers in the new password."; }
        else if ($new !== $confirm) { $error = "The new passwords do not match."; }
        else if ($new === $current) { $error = "The new password must be different from your current one."; }
        else {
            try {
                $upd = $conn->prepare("UPDATE users SET password = :p WHERE id = :id");
                $upd->execute([':p' => password_hash($new, PASSWORD_DEFAULT), ':id' => $uid]);
                audit_log('update', 'users', $uid, null, ['change' => 'password_changed']);   // the password itself is never logged

                unset($_SESSION['pwchange_fails'], $_SESSION['pwchange_lock_until'], $_SESSION['must_change_password']);
                session_regenerate_id(true);
                flash("Your password has been changed.");
                header("Location: " . (($_SESSION['role'] === 'Citizen') ? 'citizen_dashboard.php' : 'dashboard.php')); exit;
            } catch (Exception $e) {
                $error = "Could not change the password. Please try again.";
            }
        }
    }
}
include 'header.php';
?>
<div class="page-heading"><div><p class="eyebrow">ACCOUNT</p><h1>Change password</h1><p class="muted"><?= $forced ? 'You are using the default password. Choose a new one to continue.' : 'Choose a new password for your account.' ?></p></div></div>

<section class="panel">
<?php if ($error): ?><div class="alert danger"><?=e($error)?></div><?php endif; ?>
<form method="post" class="form-grid"><?php csrf_field(); ?>
    <input type="text" name="username" value="<?=e($_SESSION['username'] ?? '')?>" autocomplete="username" readonly tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;opacity:0;height:0;width:0">
    <div class="span-2"><label>Current password</label><input type="password" id="pw-cur" name="current_password" required autocomplete="current-password"></div>
    <div><label>New password (at least 8 characters, letters and numbers)</label><input type="password" id="pw-new" name="new_password" minlength="8" required autocomplete="new-password"></div>
    <div><label>Confirm new password</label><input type="password" id="pw-conf" name="confirm_password" minlength="8" required autocomplete="new-password"></div>
    <div class="span-2"><a href="#" class="text-link" onclick="togglePw(this); return false;">Show passwords</a></div>
    <div class="span-2"><button class="btn">Change password</button>
        <?php if (!$forced): ?> <a class="text-link" href="<?= ($_SESSION['role'] === 'Citizen') ? 'citizen_dashboard.php' : 'dashboard.php' ?>">Cancel</a><?php endif; ?></div>
</form>
</section>
<script>
function togglePw(link){
    var ids = ['pw-cur','pw-new','pw-conf'];
    var show = document.getElementById(ids[0]).type === 'password';
    ids.forEach(function(id){ document.getElementById(id).type = show ? 'text' : 'password'; });
    link.textContent = show ? 'Hide passwords' : 'Show passwords';
}
</script>
<?php include 'footer.php'; ?>