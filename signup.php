<?php
require_once 'config.php';
$error = "";
$taxTypes = ['Individual', 'Company', 'Partnership'];

// Helpers for comparing names and phone numbers
function norm_name($s) { return strtolower(preg_replace('/\s+/', ' ', trim($s))); }
function norm_phone($s) {
    $d = preg_replace('/\D+/', '', $s);
    return substr($d, -9); // compare the last 9 digits (ignores +254 / 0 prefix)
}

// If already logged in, redirect
if (!empty($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'Citizen') {
        header("Location: citizen_dashboard.php");
    } else {
        header("Location: dashboard.php");
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = "Invalid form submission. Please try again.";
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $kra_pin = strtoupper(trim($_POST['kra_pin'] ?? ''));
        $id_number = trim($_POST['id_number'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $taxpayer_type = $_POST['taxpayer_type'] ?? 'Individual';
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';

        if ($kra_pin === '' || $id_number === '' || $full_name === '' || $password === '') {
            $error = "Full name, KRA PIN, National ID, and password are required.";
        } else if (!in_array($taxpayer_type, $taxTypes, true)) {
            $error = "Please choose a valid tax obligation type.";
        } else if (!preg_match('/^[AP]\d{9}Z$/', $kra_pin)) {
            $error = "Enter a valid KRA PIN, for example A123456789Z.";
        } else if ($taxpayer_type === 'Individual' && $kra_pin[0] !== 'A') {
            $error = "An individual's KRA PIN starts with A. Company and partnership PINs start with P.";
        } else if ($taxpayer_type !== 'Individual' && $kra_pin[0] !== 'P') {
            $error = "A company or partnership KRA PIN starts with P. Individual PINs start with A.";
        } else if (!preg_match('/^\d{7,8}$/', $id_number)) {
            $error = "Enter a valid National ID number (7 or 8 digits).";
        } else if (strlen($address) > 255) {
            $error = "Physical address is too long (255 characters maximum).";
        } else if (strlen($password) < 6) {
            $error = "Password must be at least 6 characters.";
        } else if ($password !== $confirm) {
            $error = "Passwords do not match.";
        } else {
            // Does an account already use this PIN?
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = :pin");
            $stmt->execute([':pin' => $kra_pin]);
            if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                $error = "An account with this KRA PIN already exists. Please log in instead.";
            } else {
                // Existing taxpayer record for this PIN?
                $stmt = $conn->prepare("SELECT id, full_name, phone, id_number, user_id FROM taxpayers WHERE kra_pin = :pin");
                $stmt->execute([':pin' => $kra_pin]);
                $taxpayer = $stmt->fetch(PDO::FETCH_ASSOC);

                // Is this ID number already used by a different taxpayer?
                $stmt = $conn->prepare("SELECT id FROM taxpayers WHERE id_number = :idn");
                $stmt->execute([':idn' => $id_number]);
                $idOwner = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($idOwner && (!$taxpayer || (int)$idOwner['id'] !== (int)$taxpayer['id'])) {
                    $error = "This National ID is already registered to another KRA PIN.";
                }

                // An existing record can only be claimed if it is free and the details match
                if ($error === "" && $taxpayer) {
                    if (!empty($taxpayer['user_id'])) {
                        $error = "This KRA PIN is already linked to an account. Please log in or contact the KRA Nakuru branch.";
                    } else if (norm_name($taxpayer['full_name']) !== norm_name($full_name)
                        || (!empty($taxpayer['phone']) && norm_phone($taxpayer['phone']) !== norm_phone($phone))
                        || (!empty($taxpayer['id_number']) && $taxpayer['id_number'] !== $id_number)) {
                        $error = "The details you entered do not match our records for this KRA PIN. Please check them or visit the KRA Nakuru branch.";
                    }
                }

                if ($error === "") {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $username = $kra_pin;
                    $emailValue = ($email !== '') ? $email : null;
                    $addressValue = ($address !== '') ? $address : null;

                    try {
                        $conn->beginTransaction();

                        // Create user account
                        $stmt = $conn->prepare("INSERT INTO users (full_name, username, email, password, role, taxpayer_id) VALUES (:n, :u, :e, :p, 'Citizen', :tid)");
                        $stmt->execute([':n' => $full_name, ':u' => $username, ':e' => $emailValue, ':p' => $hash, ':tid' => $taxpayer['id'] ?? null]);
                        $userId = (int)$conn->lastInsertId();

                        // If no taxpayer record exists, create one
                        $taxpayerId = $taxpayer['id'] ?? null;
                        if (!$taxpayerId) {
                            $stmt = $conn->prepare("INSERT INTO taxpayers (kra_pin, id_number, full_name, phone, email, address, taxpayer_type, user_id) VALUES (:pin, :idn, :n, :ph, :e, :addr, :tt, :uid)");
                            $stmt->execute([':pin' => $kra_pin, ':idn' => $id_number, ':n' => $full_name, ':ph' => $phone, ':e' => $emailValue, ':addr' => $addressValue, ':tt' => $taxpayer_type, ':uid' => $userId]);
                            $taxpayerId = (int)$conn->lastInsertId();

                            // Update user with the new taxpayer_id
                            $stmt = $conn->prepare("UPDATE users SET taxpayer_id = :tid WHERE id = :uid");
                            $stmt->execute([':tid' => $taxpayerId, ':uid' => $userId]);
                        } else {
                            // Link existing taxpayer to this user, store the ID number, fill the address only if it was empty
                            $stmt = $conn->prepare("UPDATE taxpayers SET user_id = :uid, id_number = :idn, address = COALESCE(NULLIF(address, ''), :addr) WHERE id = :tid");
                            $stmt->execute([':uid' => $userId, ':idn' => $id_number, ':addr' => $addressValue, ':tid' => $taxpayerId]);
                        }

                        $conn->commit();

                        // Auto login
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $userId;
                        $_SESSION['full_name'] = $full_name;
                        $_SESSION['username'] = $username;
                        $_SESSION['role'] = 'Citizen';
                        $_SESSION['taxpayer_id'] = $taxpayerId;

                        flash("Account created successfully. Welcome, " . $full_name . "! Log in later with your KRA PIN or National ID.");
                        header("Location: citizen_dashboard.php");
                        exit;
                    } catch (Exception $e) {
                        if ($conn->inTransaction()) { $conn->rollBack(); }
                        $error = "Could not create account. Please try again.";
                    }
                }
            }
        }
    }
}

$selType = $_POST['taxpayer_type'] ?? 'Individual';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Taxpayer Registration — KRA Nakuru</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:#fff;color:#1b3a5c}
.topbar{background:#1b3a5c;color:#fff;padding:.95rem 1.5rem;font-weight:700;font-size:1.15rem}
.layout{display:grid;grid-template-columns:1fr 290px;gap:1.25rem;max-width:1020px;margin:1.75rem auto;padding:0 1rem}
@media(max-width:860px){.layout{grid-template-columns:1fr}}
.main{border:1px solid #c5d2d8;border-radius:10px;padding:1.5rem 1.75rem;background:#fff}
.main h2{margin:0 0 1.25rem;font-size:1.15rem;color:#1b3a5c}
.row{display:grid;grid-template-columns:150px 1fr;gap:.75rem;align-items:center;margin-bottom:.8rem}
@media(max-width:560px){.row{grid-template-columns:1fr;gap:.3rem}}
.row label{font-size:.8rem;color:#4a5b6c}
input,select{width:100%;padding:.6rem .75rem;border:1px solid #b6c3cb;border-radius:6px;background:#f1f6f5;font-size:.92rem;color:#1b3a5c}
input:focus,select:focus{outline:2px solid #2e7d32;background:#fff}
input::placeholder{color:#9aa8b3}
.hint{font-size:.78rem;color:#6b7a8a;margin:-.3rem 0 .9rem 162px}
@media(max-width:560px){.hint{margin-left:0}}
.actions{display:flex;gap:.75rem;margin:1.25rem 0 0 162px}
@media(max-width:560px){.actions{margin-left:0}}
.btn-save{padding:.7rem 2.2rem;border:0;border-radius:8px;background:#2e7d32;color:#fff;font-weight:700;cursor:pointer;font-size:.95rem}
.btn-save:hover{background:#27692b}
.btn-cancel{padding:.7rem 1.8rem;border:1px solid #b6c3cb;border-radius:8px;background:#fff;color:#1b3a5c;font-weight:700;text-decoration:none;font-size:.95rem}
.alert{background:#fdecea;color:#a32920;border:1px solid #f4c2be;padding:.65rem .85rem;border-radius:6px;font-size:.86rem;margin-bottom:1rem}
.side{background:#f1f6f5;border:1px solid #c5d2d8;border-radius:10px;padding:1.25rem 1.4rem;align-self:start}
.side h3{margin:0 0 .75rem;font-size:1rem;color:#1b3a5c}
.side ul{margin:0;padding-left:1.1rem;font-size:.83rem;color:#4a5b6c;line-height:1.55}
.side li{margin-bottom:.5rem}
.side p{font-size:.83rem;color:#4a5b6c;margin:1rem 0 0}
.side a{color:#1b3a5c;font-weight:600}
.showpw{font-size:.82rem;color:#1b3a5c;cursor:pointer;text-decoration:underline}
</style>
</head>
<body>
<div class="topbar">Taxpayer Registration</div>
<div class="layout">
  <div class="main">
    <h2>Register New Taxpayer</h2>
    <?php if ($error): ?><div class="alert"><?=e($error)?></div><?php endif; ?>
    <form method="post">
      <?php csrf_field(); ?>
      <div class="row"><label>Full Name</label><input name="full_name" required placeholder="As it appears on your KRA PIN certificate" value="<?=e($_POST['full_name'] ?? '')?>"></div>
      <div class="row"><label>KRA PIN</label><input name="kra_pin" required autocomplete="username" placeholder="e.g. A123456789Z" style="text-transform:uppercase" value="<?=e($_POST['kra_pin'] ?? '')?>"></div>
      <div class="row"><label>National ID</label><input name="id_number" required placeholder="e.g. 12345678" value="<?=e($_POST['id_number'] ?? '')?>"></div>
      <div class="row"><label>Email Address</label><input type="email" name="email" placeholder="you@example.com" value="<?=e($_POST['email'] ?? '')?>"></div>
      <div class="row"><label>Phone Number</label><input name="phone" placeholder="+254 700 000 000" value="<?=e($_POST['phone'] ?? '')?>"></div>
      <div class="row"><label>Physical Address</label><input name="address" maxlength="255" placeholder="e.g. Kenyatta Avenue, Nakuru" value="<?=e($_POST['address'] ?? '')?>"></div>
      <div class="row"><label>Tax Obligation Type</label>
        <select name="taxpayer_type" required>
          <?php foreach ($taxTypes as $opt): ?><option value="<?=e($opt)?>" <?= $selType === $opt ? 'selected' : '' ?>><?=e($opt)?></option><?php endforeach; ?>
        </select></div>
      <div class="row"><label>Password</label><input type="password" id="su-pw" name="password" minlength="6" required autocomplete="new-password" placeholder="At least 6 characters"></div>
      <div class="row"><label>Confirm Password</label><input type="password" id="su-pw2" name="password_confirm" minlength="6" required autocomplete="new-password"></div>
      <div class="hint"><a class="showpw" onclick="togglePw(this)">Show passwords</a></div>
      <div class="actions"><button class="btn-save">Save</button><a class="btn-cancel" href="login.php">Cancel</a></div>
    </form>
  </div>
  <aside class="side">
    <h3>Before you register</h3>
    <ul>
      <li><strong>KRA PIN:</strong> A for individuals, P for companies and partnerships, then 9 digits and a final letter Z.</li>
      <li><strong>National ID:</strong> 7 or 8 digits.</li>
      <li>Enter your name exactly as it appears on your KRA PIN certificate.</li>
      <li>After registering you can log in with either your KRA PIN or your National ID.</li>
    </ul>
    <p>Already registered? <a href="login.php">Log in</a></p>
  </aside>
</div>
<script>
function togglePw(link){
    var ids = ['su-pw', 'su-pw2'];
    var show = document.getElementById(ids[0]).type === 'password';
    ids.forEach(function(id){ document.getElementById(id).type = show ? 'text' : 'password'; });
    link.textContent = show ? 'Hide passwords' : 'Show passwords';
}
</script>
</body>
</html>