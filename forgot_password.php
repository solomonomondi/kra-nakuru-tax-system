<?php
require_once 'config.php';
if (!empty($_SESSION['user_id'])) {
    header("Location: " . (($_SESSION['role'] === 'Citizen') ? 'citizen_dashboard.php' : 'dashboard.php')); exit;
}
$error = "";
$maxAttempts = 5;      // failed verification attempts allowed
$lockSeconds = 900;    // lock time after too many failures (15 minutes)

function norm_phone($s) { return substr(preg_replace('/\D+/', '', $s), -9); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { $error = 'Invalid form submission.'; }
    else if (!empty($_SESSION['reset_lock_until']) && time() < $_SESSION['reset_lock_until']) {
        $mins = (int)ceil(($_SESSION['reset_lock_until'] - time()) / 60);
        $error = "Too many failed attempts. Please try again in $mins minute(s).";
    }
    else {
        $pin = strtoupper(trim($_POST['kra_pin'] ?? ''));
        $idn = trim($_POST['id_number'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');

        $stmt = $conn->prepare("SELECT u.id AS uid, t.phone, t.email
            FROM taxpayers t JOIN users u ON u.taxpayer_id = t.id
            WHERE u.role = 'Citizen' AND UPPER(t.kra_pin) = :pin AND t.id_number = :idn");
        $stmt->execute([':pin' => $pin, ':idn' => $idn]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $ok = false;
        if ($row) {
            $hasPhone = !empty($row['phone']);
            $hasEmail = !empty($row['email']);
            // The account needs at least one contact on file, and every contact on file must match
            if ($hasPhone || $hasEmail) {
                $phoneOk = !$hasPhone || ($phone !== '' && norm_phone($phone) === norm_phone($row['phone']));
                $emailOk = !$hasEmail || ($email !== '' && strtolower($email) === strtolower($row['email']));
                $ok = $phoneOk && $emailOk;
            }
        }

        if ($ok) {
            unset($_SESSION['reset_fails'], $_SESSION['reset_lock_until']);
            session_regenerate_id(true);
            $_SESSION['reset_user_id'] = (int)$row['uid'];
            $_SESSION['reset_expires'] = time() + 600;   // 10 minutes to choose a new password
            header("Location: reset_password.php"); exit;
        } else {
            $_SESSION['reset_fails'] = ($_SESSION['reset_fails'] ?? 0) + 1;
            if ($_SESSION['reset_fails'] >= $maxAttempts) {
                $_SESSION['reset_lock_until'] = time() + $lockSeconds;
                $_SESSION['reset_fails'] = 0;
                $error = "Too many failed attempts. Please try again in " . ($lockSeconds / 60) . " minutes.";
            } else {
                $error = "The details you entered do not match our records. If your account has no phone number or email on file, please visit the KRA Nakuru branch.";
            }
        }
    }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Forgot Password | KRA Nakuru</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:#fff;color:#1b3a5c;min-height:100vh}
.topbar{background:#1b3a5c;color:#fff;padding:.9rem 1.5rem;font-weight:700;font-size:1.05rem}
.wrap{display:flex;justify-content:center;padding:2.5rem 1rem}
.card{width:100%;max-width:430px;background:#f1f6f5;border:1px solid #c5d2d8;border-radius:14px;padding:1.75rem}
.card h1{margin:0 0 .25rem;text-align:center;font-size:1.3rem}
.sub{text-align:center;color:#6b7a8a;font-size:.85rem;margin:0 0 .5rem}
label{display:block;font-size:.8rem;color:#4a5b6c;margin:.9rem 0 .3rem}
input{width:100%;padding:.65rem .75rem;border:1px solid #b6c3cb;border-radius:6px;background:#fff;font-size:.95rem}
input:focus{outline:2px solid #2e7d32}
input::placeholder{color:#9aa8b3}
.btn{width:100%;margin-top:1.25rem;padding:.75rem;border:0;border-radius:6px;background:#2e7d32;color:#fff;font-weight:700;letter-spacing:.04em;cursor:pointer;font-size:.95rem}
.btn:hover{background:#27692b}
.alert{background:#fdecea;color:#a32920;border:1px solid #f4c2be;padding:.6rem .8rem;border-radius:6px;font-size:.85rem;margin-top:.75rem}
.note{font-size:.82rem;color:#4a5b6c;margin:.5rem 0 0}
.foot{text-align:center;margin-top:1.25rem;font-size:.85rem}
.foot a{color:#1b3a5c;font-weight:600}
</style></head>
<body>
<div class="topbar">KRA Tax Filing Management System</div>
<div class="wrap"><div class="card">
    <h1>Forgot Password</h1>
    <p class="sub">Confirm your identity to set a new password</p>
    <?php if ($error): ?><div class="alert"><?=e($error)?></div><?php endif; ?>
    <form method="post" autocomplete="off"><?php csrf_field(); ?>
        <label>KRA PIN</label>
        <input name="kra_pin" required placeholder="e.g. A123456789Z" style="text-transform:uppercase" value="<?=e($_POST['kra_pin'] ?? '')?>">
        <label>National ID</label>
        <input name="id_number" required placeholder="e.g. 12345678" value="<?=e($_POST['id_number'] ?? '')?>">
        <label>Phone number you registered with</label>
        <input name="phone" placeholder="+254 700 000 000" value="<?=e($_POST['phone'] ?? '')?>">
        <label>Email address you registered with</label>
        <input type="email" name="email" placeholder="you@example.com" value="<?=e($_POST['email'] ?? '')?>">
        <p class="note">Enter the phone number and email exactly as you gave them when you registered.</p>
        <button class="btn">VERIFY AND CONTINUE</button>
    </form>
    <p class="foot"><a href="login.php">Back to login</a></p>
    <p class="note" style="text-align:center">Staff accounts: ask a system administrator to reset your password.</p>
</div></div>
</body></html>