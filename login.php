<?php
require_once 'config.php';
if (!empty($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'Citizen') { header("Location: citizen_dashboard.php"); exit; }
    header("Location: dashboard.php"); exit;
}
$error = "";
$notice = "";
if (!empty($_SESSION['flash'])) {
    // Show success messages only (e.g. "password reset"); drop leftover error flashes
    if (($_SESSION['flash'][1] ?? 'success') === 'success') { $notice = $_SESSION['flash'][0]; }
    unset($_SESSION['flash']);
}
$maxAttempts = 5;      // failed attempts allowed
$lockSeconds = 300;    // lock time after too many failures (5 minutes)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { $error = 'Invalid form submission.'; }
    else if (!empty($_SESSION['login_lock_until']) && time() < $_SESSION['login_lock_until']) {
        $mins = (int)ceil(($_SESSION['login_lock_until'] - time()) / 60);
        $error = "Too many failed attempts. Please try again in $mins minute(s).";
    }
    else {
        $identifier = trim($_POST['identifier'] ?? '');
        $password = $_POST['password'] ?? '';

        // 1) Staff: username + password (Admin and Officer accounts)
        $stmt = $conn->prepare("SELECT id, full_name, username, password, role, taxpayer_id FROM users WHERE LOWER(username)=LOWER(:u) AND role IN ('Admin','Officer')");
        $stmt->execute([':u' => $identifier]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // 2) Citizen: KRA PIN or national ID number + password
        if (!$row) {
            $stmt = $conn->prepare("SELECT u.id, u.full_name, u.username, u.password, u.role, u.taxpayer_id
                FROM users u JOIN taxpayers t ON t.id = u.taxpayer_id
                WHERE u.role = 'Citizen' AND (UPPER(t.kra_pin) = UPPER(:i1) OR t.id_number = :i2)");
            $stmt->execute([':i1' => $identifier, ':i2' => $identifier]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Always run password_verify so wrong usernames and wrong passwords take the same time
        $hash = $row['password'] ?? password_hash('dummy-password', PASSWORD_DEFAULT);
        $valid = password_verify($password, $hash) && $row;

        if ($valid) {
            unset($_SESSION['login_fails'], $_SESSION['login_lock_until']);
            session_regenerate_id(true);
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['full_name'] = $row['full_name'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['role'] = $row['role'];
            $_SESSION['taxpayer_id'] = $row['taxpayer_id'] ?? null;
            if ($row['role'] === 'Citizen') { header("Location: citizen_dashboard.php"); exit; }
            header("Location: dashboard.php"); exit;
        } else {
            $_SESSION['login_fails'] = ($_SESSION['login_fails'] ?? 0) + 1;
            if ($_SESSION['login_fails'] >= $maxAttempts) {
                $_SESSION['login_lock_until'] = time() + $lockSeconds;
                $_SESSION['login_fails'] = 0;
                $error = "Too many failed attempts. Please try again in " . ($lockSeconds / 60) . " minutes.";
            } else {
                $left = $maxAttempts - $_SESSION['login_fails'];
                $error = "Incorrect username / KRA PIN / ID number or password. ($left attempt(s) left)";
            }
        }
    }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Login | KRA Nakuru</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:#fff;color:#1b3a5c;min-height:100vh}
.topbar{background:#1b3a5c;color:#fff;padding:.9rem 1.5rem;font-weight:700;font-size:1.05rem}
.wrap{display:flex;justify-content:center;padding:3.5rem 1rem}
.card{width:100%;max-width:400px;background:#f1f6f5;border:1px solid #c5d2d8;border-radius:14px;padding:1.75rem}
.card h1{margin:0 0 .25rem;text-align:center;font-size:1.3rem;color:#1b3a5c}
.sub{text-align:center;color:#6b7a8a;font-size:.85rem;margin:0 0 .5rem}
label{display:block;font-size:.8rem;color:#4a5b6c;margin:1rem 0 .3rem}
input{width:100%;padding:.65rem .75rem;border:1px solid #b6c3cb;border-radius:6px;background:#fff;font-size:.95rem}
input:focus{outline:2px solid #2e7d32;outline-offset:0}
.btn{width:100%;margin-top:1.25rem;padding:.75rem;border:0;border-radius:6px;background:#2e7d32;color:#fff;font-weight:700;letter-spacing:.06em;cursor:pointer;font-size:.95rem}
.btn:hover{background:#27692b}
.links{display:flex;justify-content:space-between;margin-top:.9rem;font-size:.82rem}
.links a{color:#1b3a5c;cursor:pointer;text-decoration:underline}
.alert{background:#fdecea;color:#a32920;border:1px solid #f4c2be;padding:.6rem .8rem;border-radius:6px;font-size:.85rem;margin-top:.75rem}
.alert.ok{background:#e8f5e9;color:#1b5e20;border-color:#b7dfb9}
.foot{text-align:center;margin-top:1.25rem;font-size:.85rem;color:#4a5b6c}
.foot a{color:#1b3a5c;font-weight:600}
input::placeholder{color:#9aa8b3}
</style></head>
<body>
<div class="topbar">KRA Tax Filing Management System</div>
<div class="wrap"><div class="card">
    <h1>System Login</h1>
    <p class="sub">Nakuru Branch</p>
    <?php if ($notice): ?><div class="alert ok"><?=e($notice)?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert"><?=e($error)?></div><?php endif; ?>
    <form method="post"><?php csrf_field(); ?>
        <label>Username / KRA PIN / ID number</label>
        <input name="identifier" required autofocus autocomplete="username" placeholder="Staff username, KRA PIN or ID number" value="<?=e($_POST['identifier'] ?? '')?>">
        <label>Password</label>
        <input type="password" id="pw" name="password" required autocomplete="current-password">
        <button class="btn">LOGIN</button>
        <div class="links"><a onclick="togglePw(this)">Show password</a><a href="forgot_password.php">Forgot password?</a></div>
    </form>
    <p class="foot">New taxpayer? <a href="signup.php">Create an account</a> to file returns online.</p>
</div></div>
<script>
function togglePw(link){
    var f = document.getElementById('pw');
    var show = f.type === 'password';
    f.type = show ? 'text' : 'password';
    link.textContent = show ? 'Hide password' : 'Show password';
}
</script>
</body></html>