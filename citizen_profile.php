<?php
require_once 'config.php';
if (empty($_SESSION['user_id'])) { header("Location: login.php"); exit; }
if ($_SESSION['role'] !== 'Citizen') { header("Location: dashboard.php"); exit; }

$taxpayerId = $_SESSION['taxpayer_id'] ?? null;
$error = "";
$data = ['full_name' => '', 'phone' => '', 'email' => '', 'address' => ''];

if (!$taxpayerId) {
    // No taxpayer record linked — create one from user info
    $stmt = $conn->prepare("INSERT INTO taxpayers (kra_pin, full_name, phone, email, taxpayer_type, user_id) VALUES (:pin, :n, '', '', 'Individual', :uid)");
    $stmt->execute([':pin' => $_SESSION['username'], ':uid' => $_SESSION['user_id']]);
    $taxpayerId = (int)$conn->lastInsertId();
    $stmt = $conn->prepare("UPDATE users SET taxpayer_id = :tid WHERE id = :uid");
    $stmt->execute([':tid' => $taxpayerId, ':uid' => $_SESSION['user_id']]);
    $_SESSION['taxpayer_id'] = $taxpayerId;
}

$stmt = $conn->prepare("SELECT * FROM taxpayers WHERE id = :id AND user_id = :uid");
$stmt->execute([':id' => $taxpayerId, ':uid' => $_SESSION['user_id']]);
$taxpayer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$taxpayer) {
    flash("Taxpayer profile not found.", "danger");
    header("Location: citizen_dashboard.php");
    exit;
}

$data = $taxpayer;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = "Invalid form submission. Please try again.";
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if ($full_name === '') {
            $error = "Full name is required.";
        } else {
            $stmt = $conn->prepare("UPDATE taxpayers SET full_name = :n, phone = :ph, email = :e, address = :a WHERE id = :id AND user_id = :uid");
            $stmt->execute([':n' => $full_name, ':ph' => $phone, ':e' => $email, ':a' => $address, ':id' => $taxpayerId, ':uid' => $_SESSION['user_id']]);
            audit_log('UPDATE', 'taxpayers', $taxpayerId, ['full_name' => $taxpayer['full_name']], ['full_name' => $full_name, 'phone' => $phone, 'email' => $email, 'address' => $address]);
            $_SESSION['full_name'] = $full_name;
            $data['full_name'] = $full_name;
            $data['phone'] = $phone;
            $data['email'] = $email;
            $data['address'] = $address;
            flash("Profile updated successfully.");
        }
    }
}

$page_title = "My Profile";
include 'header.php';
?>

<div class="page-heading">
    <div>
        <p class="eyebrow">MY PROFILE</p>
        <h1>Taxpayer details</h1>
        <p class="muted">Update your contact information.</p>
    </div>
    <a class="btn secondary" href="citizen_dashboard.php">Back</a>
</div>

<section class="panel form-panel">
    <?php if ($error): ?><div class="alert danger"><?=e($error)?></div><?php endif; ?>
    <form method="post" class="form-grid">
        <?php csrf_field(); ?>
        <div class="span-2">
            <label>KRA PIN</label>
            <input value="<?=e($taxpayer['kra_pin'])?>" disabled>
            <small class="muted">Cannot be changed</small>
        </div>
        <div class="span-2">
            <label>Full name *</label>
            <input name="full_name" required value="<?=e($data['full_name'])?>">
        </div>
        <div>
            <label>Phone</label>
            <input name="phone" value="<?=e($data['phone'])?>">
        </div>
        <div>
            <label>Email</label>
            <input type="email" name="email" value="<?=e($data['email'])?>">
        </div>
        <div class="span-2">
            <label>Address</label>
            <input name="address" value="<?=e($data['address'])?>">
        </div>
        <div class="span-2">
            <button class="btn">Save changes</button>
            <a class="text-link" href="citizen_dashboard.php">Cancel</a>
        </div>
    </form>
</section>

<?php include 'footer.php'; ?>
