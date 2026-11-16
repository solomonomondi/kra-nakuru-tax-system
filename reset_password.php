<?php
require_once 'config.php';
// Only reachable after verifying identity on forgot_password.php (valid for 10 minutes)
if (empty($_SESSION['reset_user_id']) || empty($_SESSION['reset_expires']) || time() > $_SESSION['reset_expires']) {
    unset($_SESSION['reset_user_id'], $_SESSION['reset_expires']);
    header("Location: forgot_password.php"); exit;
}
$error = "";
$un = $conn->prepare("SELECT username FROM users WHERE id = :id");
$un->execute([':id' => (int)$_SESSION['reset_user_id']]);
$resetUsername = (string)$un->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { $error = 'Invalid form submission.'; }
    else {
        $pw = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';
        if (strlen($pw) < 6) { $error = "Password must be at least 6 characters."; }
        else if ($pw !== $confirm) { $error = "Passwords do not match."; }
        else {
            try {
                $uid = (int)$_SESSION['reset_user_id'];
                $stmt = $conn->prepare("UPDATE users SET password = :p WHERE id = :id AND role = 'Citizen'");
                $stmt->execute([':p' => password_hash($pw, PASSWORD_DEFAULT), ':id' => $uid]);

                // Record the reset in the audit trail
                $stmt = $conn->prepare("INSERT INTO audit_log (user_id, action, table_name, record_id, ip_address) VALUES (:uid, 'password_reset', 'users', :rid, :ip)");
                $stmt->execute([':uid' => $uid, ':rid' => $uid, ':ip' => $_SERVER['REMOTE_ADDR'] ?? null]);

                unset($_SESSION['reset_user_id'], $_SESSION['reset_expires']);
                session_regenerate_id(true);
                flash("Your password has been reset. Please log in with your new password.");
                header("Location: login.php"); exit;
            } catch (Exception $e) {
                $error = "Could not reset the password. Please try again.";
            }
        }
    }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Reset Password | KRA Nakuru</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:#fff;color:#1b3a5c;min-height:100vh}
.topbar{background:#1b3a5c;color:#fff;padding:.9rem 1.5rem;font-weight:700;font-size:1.05rem}
.wrap{display:flex;justify-content:center;padding:3rem 1rem}
.card{width:100%;max-width:400px;background:#f1f6f5;border:1px solid #c5d2d8;border-radius:14px;padding:1.75rem}
.card h1{margin:0 0 .25rem;text-align:center;font-size:1.3rem}
.sub{text-align:center;color:#6b7a8a;font-size:.85rem;margin:0 0 .5rem}
label{display:block;font-size:.8rem;color:#4a5b6c;margin:.9rem 0 .3rem}
input{width:100%;padding:.65rem .75rem;border:1px solid #b6c3cb;border-radius:6px;background:#fff;font-size:.95rem}
input:focus{outline:2px solid #2e7d32}
.btn{width:100%;margin-top:1.25rem;padding:.75rem;border:0;border-radius:6px;background:#2e7d32;color:#fff;font-weight:700;letter-spacing:.04em;cursor:pointer;font-size:.95rem}
.btn:hover{background:#27692b}
.alert{background:#fdecea;color:#a32920;border:1px solid #f4c2be;padding:.6rem .8rem;border-radius:6px;font-size:.85rem;margin-top:.75rem}
.links{margin-top:.8rem;font-size:.82rem}
.links a{color:#1b3a5c;cursor:pointer;text-decoration:underline}
.foot{text-align:center;margin-top:1.25rem;font-size:.85rem}
.foot a{color:#1b3a5c;font-weight:600}
</style></head>
<body>
<div class="topbar">KRA Tax Filing Management System</div>
<div class="wrap"><div class="card">
    <h1>Set New Password</h1>
    <p class="sub">Identity confirmed. Choose a new password.</p>
    <?php if ($error): ?><div class="alert"><?=e($error)?></div><?php endif; ?>
    <form method="post"><?php csrf_field(); ?>
        <input type="text" name="username" value="<?=e($resetUsername)?>" autocomplete="username" readonly tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;opacity:0;height:0;width:0">
        <label>New password (min 6 characters)</label>
        <input type="password" id="pw1" name="password" minlength="6" required autocomplete="new-password">
        <label>Confirm new password</label>
        <input type="password" id="pw2" name="password_confirm" minlength="6" required autocomplete="new-password">
        <div class="links"><a onclick="togglePw(this)">Show passwords</a></div>
        <button class="btn">RESET PASSWORD</button>
    </form>
    <p class="foot"><a href="login.php">Cancel</a></p>
</div></div>
<script>
function togglePw(link){
    var ids = ['pw1','pw2'];
    var show = document.getElementById(ids[0]).type === 'password';
    ids.forEach(function(id){ document.getElementById(id).type = show ? 'text' : 'password'; });
    link.textContent = show ? 'Hide passwords' : 'Show passwords';
}
</script>
</body></html>