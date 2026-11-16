<?php
require_once 'config.php';
$id = (int)($_GET['id'] ?? 0);
$error = "";
$data = ['kra_pin' => '', 'full_name' => '', 'phone' => '', 'email' => '', 'address' => '', 'taxpayer_type' => 'Individual'];

if ($id) {
    $s = $conn->prepare("SELECT * FROM taxpayers WHERE id=:id");
    $s->execute([':id' => $id]);
    $found = $s->fetch(PDO::FETCH_ASSOC);
    if ($found) $data = $found;
    else { flash("Taxpayer not found.", "danger"); header("Location: taxpayers.php"); exit; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = "Invalid form submission. Please try again.";
    } else {
        $pin = trim($_POST['kra_pin'] ?? '');
        $name = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $type = $_POST['taxpayer_type'] ?? 'Individual';

        if ($pin === '' || $name === '') {
            $error = "KRA PIN and full name are required.";
        } elseif ($id) {
            // Update existing taxpayer
            $s = $conn->prepare("UPDATE taxpayers SET kra_pin=:p,full_name=:n,phone=:ph,email=:e,address=:a,taxpayer_type=:t WHERE id=:id");
            if ($s->execute([':p' => $pin, ':n' => $name, ':ph' => $phone, ':e' => $email, ':a' => $address, ':t' => $type, ':id' => $id])) {
                audit_log('UPDATE', 'taxpayers', $id, ['kra_pin' => $data['kra_pin'], 'full_name' => $data['full_name']], ['kra_pin' => $pin, 'full_name' => $name]);
                flash("Taxpayer updated.");
                header("Location: taxpayers.php"); exit;
            } else {
                $error = "Could not save. Check that the KRA PIN is not already registered.";
            }
        } else {
            // Prevent duplicate entries — check KRA PIN before inserting
            $s = $conn->prepare("SELECT id FROM taxpayers WHERE kra_pin=:p");
            $s->execute([':p' => $pin]);
            if ($s->fetch(PDO::FETCH_ASSOC)) {
                $error = "Duplicate entry: A taxpayer with KRA PIN " . e($pin) . " already exists.";
            } else {
                $s = $conn->prepare("INSERT INTO taxpayers(kra_pin,full_name,phone,email,address,taxpayer_type) VALUES(:p,:n,:ph,:e,:a,:t)");
                if ($s->execute([':p' => $pin, ':n' => $name, ':ph' => $phone, ':e' => $email, ':a' => $address, ':t' => $type])) {
                    $newId = (int)$conn->lastInsertId();
                    audit_log('CREATE', 'taxpayers', $newId, null, ['kra_pin' => $pin, 'full_name' => $name, 'taxpayer_type' => $type]);
                    flash("Taxpayer registered.");
                    header("Location: taxpayers.php"); exit;
                } else {
                    $error = "Could not save. Check that the KRA PIN is not already registered.";
                }
            }
        }
    }
}

$page_title = $id ? "Edit taxpayer" : "Add taxpayer";
include 'header.php';
?>
<div class="page-heading"><div><p class="eyebrow">TAXPAYER RECORD</p><h1><?=$id?'Edit taxpayer':'Register taxpayer'?></h1></div><a class="btn secondary" href="taxpayers.php">Back</a></div>
<section class="panel form-panel">
<?php if($error): ?><div class="alert danger"><?=e($error)?></div><?php endif; ?>
<form method="post" class="form-grid">
<?php csrf_field(); ?>
<div><label>KRA PIN *</label><input name="kra_pin" required value="<?=e($data['kra_pin'])?>"></div>
<div><label>Full name *</label><input name="full_name" required value="<?=e($data['full_name'])?>"></div>
<div><label>Phone</label><input name="phone" value="<?=e($data['phone'])?>"></div>
<div><label>Email</label><input type="email" name="email" value="<?=e($data['email'])?>"></div>
<div><label>Taxpayer type</label><select name="taxpayer_type"><option <?=($data['taxpayer_type']==='Individual'?'selected':'')?>>Individual</option><option <?=($data['taxpayer_type']==='Business'?'selected':'')?>>Business</option></select></div>
<div class="span-2"><label>Address</label><input name="address" value="<?=e($data['address'])?>"></div>
<div class="span-2"><button class="btn">Save taxpayer</button> <a class="text-link" href="taxpayers.php">Cancel</a></div>
</form>
</section>
<?php include 'footer.php'; ?>
