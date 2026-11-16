<?php
require_once 'config.php';
require_once 'mpesa.php';
require_login();

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['Admin', 'Officer', 'Citizen'], true)) {
    http_response_code(403);
    exit('Access denied.');
}
$isCitizen = $role === 'Citizen';
$userId = (int)$_SESSION['user_id'];
$returnId = (int)($_GET['return_id'] ?? $_POST['return_id'] ?? 0);
$requestId = (int)($_GET['id'] ?? 0);
$error = '';
$request = null;
$taxReturn = null;

function stk_load_return($conn, $returnId, $userId, $isCitizen) {
    $ownerClause = $isCitizen ? ' AND t.user_id = :uid' : '';
    $stmt = $conn->prepare("SELECT r.id, r.tax_type, r.tax_period, r.amount, r.status, t.full_name, t.kra_pin, t.phone,
        COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.return_id = r.id), 0) AS paid,
        COALESCE((SELECT SUM(s.amount) FROM mpesa_stk_requests s
            WHERE s.return_id = r.id AND s.status IN ('Pending', 'Review')), 0) AS reserved,
        COALESCE((SELECT MAX(s.id) FROM mpesa_stk_requests s
            WHERE s.return_id = r.id AND s.status IN ('Pending', 'Review')), 0) AS active_request_id
        FROM tax_returns r JOIN taxpayers t ON t.id = r.taxpayer_id
        WHERE r.id = :id $ownerClause");
    $params = [':id' => $returnId];
    if ($isCitizen) { $params[':uid'] = $userId; }
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($requestId > 0) {
    $ownerClause = $isCitizen ? ' AND t.user_id = :uid' : '';
    $stmt = $conn->prepare("SELECT s.*, r.tax_type, r.tax_period, t.user_id AS taxpayer_user_id
        FROM mpesa_stk_requests s JOIN tax_returns r ON r.id = s.return_id
        JOIN taxpayers t ON t.id = r.taxpayer_id
        WHERE s.id = :id $ownerClause");
    $params = [':id' => $requestId];
    if ($isCitizen) { $params[':uid'] = $userId; }
    $stmt->execute($params);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$request) {
        http_response_code(404);
        exit('Payment request not found.');
    }
    $returnId = (int)$request['return_id'];
} else {
    $taxReturn = $returnId > 0 ? stk_load_return($conn, $returnId, $userId, $isCitizen) : false;
    if (!$taxReturn) {
        http_response_code(404);
        exit('Tax return not found.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$requestId) {
    if (!verify_csrf()) {
        $error = 'Invalid form submission. Please try again.';
    } elseif ($taxReturn['status'] === 'Rejected') {
        $error = 'Payments cannot be made against a rejected return.';
    } else {
        try {
            $config = mpesa_config();
            $phone = mpesa_normalize_phone($_POST['phone'] ?? '');
            $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_INT);
            if ($amount === false || $amount < 1) {
                throw new RuntimeException('Enter an amount in whole Kenyan shillings greater than zero.');
            }
            $accessToken = mpesa_access_token($config);
            $callbackToken = bin2hex(random_bytes(32));

            $conn->beginTransaction();
            $ownerClause = $isCitizen ? ' AND t.user_id = :uid' : '';
            $lock = $conn->prepare("SELECT r.amount, r.status FROM tax_returns r
                JOIN taxpayers t ON t.id = r.taxpayer_id
                WHERE r.id = :id $ownerClause FOR UPDATE OF r");
            $params = [':id' => $returnId];
            if ($isCitizen) { $params[':uid'] = $userId; }
            $lock->execute($params);
            $lockedReturn = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$lockedReturn) {
                throw new RuntimeException('Tax return not found or you do not have permission to pay it.');
            }
            if ($lockedReturn['status'] === 'Rejected') {
                throw new RuntimeException('Payments cannot be made against a rejected return.');
            }

            $paidStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE return_id = :id");
            $paidStmt->execute([':id' => $returnId]);
            $pendingStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM mpesa_stk_requests
                WHERE return_id = :id AND status IN ('Pending', 'Review')");
            $pendingStmt->execute([':id' => $returnId]);
            $reserved = (float)$pendingStmt->fetchColumn();
            if ($reserved > 0) {
                throw new RuntimeException('An M-Pesa request is already pending or under review for this return. Check its status before starting another.');
            }
            $balance = round((float)$lockedReturn['amount'] - (float)$paidStmt->fetchColumn() - $reserved, 2);
            if ($amount > floor($balance)) {
                throw new RuntimeException('The amount exceeds the available whole-shilling balance of KSh ' . number_format(max(0, floor($balance)), 2) . '.');
            }

            $insert = $conn->prepare("INSERT INTO mpesa_stk_requests
                (return_id, requested_by, phone, amount, callback_token_hash)
                VALUES (:return_id, :requested_by, :phone, :amount, :token_hash)
                RETURNING id");
            $insert->execute([
                ':return_id' => $returnId,
                ':requested_by' => $userId,
                ':phone' => $phone,
                ':amount' => $amount,
                ':token_hash' => hash('sha256', $callbackToken),
            ]);
            $requestId = (int)$insert->fetchColumn();
            $conn->commit();

            try {
                $response = mpesa_send_stk($config, $accessToken, $phone, $amount, $returnId, $callbackToken);
                $update = $conn->prepare("UPDATE mpesa_stk_requests
                    SET merchant_request_id = :merchant, checkout_request_id = :checkout, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id");
                $update->execute([
                    ':merchant' => $response['MerchantRequestID'],
                    ':checkout' => $response['CheckoutRequestID'],
                    ':id' => $requestId,
                ]);
            } catch (MpesaRejectedException $e) {
                $update = $conn->prepare("UPDATE mpesa_stk_requests
                    SET status = 'Failed', result_desc = :desc, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $update->execute([':desc' => $e->getMessage(), ':id' => $requestId]);
                throw $e;
            } catch (RuntimeException $e) {
                // A timeout may happen after Safaricom accepted the request; keep the balance reserved.
                $update = $conn->prepare("UPDATE mpesa_stk_requests
                    SET result_desc = :desc, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $update->execute([
                    ':desc' => 'Safaricom response is pending. Do not retry until this request is resolved.',
                    ':id' => $requestId,
                ]);
                header('Location: stk_push.php?id=' . $requestId);
                exit;
            }

            header('Location: stk_push.php?id=' . $requestId);
            exit;
        } catch (RuntimeException $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            $error = $e->getMessage();
        } catch (PDOException $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            error_log('M-Pesa request database error: ' . $e->getMessage());
            $error = 'Could not save the M-Pesa request. Please contact an administrator.';
        }
    }
    $taxReturn = stk_load_return($conn, $returnId, $userId, $isCitizen);
    $requestId = 0;
}

$page_title = $request ? 'M-Pesa payment status' : 'Pay with M-Pesa';
include 'header.php';
?>
<div class="page-heading">
    <div>
        <p class="eyebrow">M-PESA</p>
        <h1><?=$request ? 'Payment status' : 'Pay with M-Pesa'?></h1>
        <p class="muted"><?=e(($request['tax_type'] ?? $taxReturn['tax_type']) . ', ' . ($request['tax_period'] ?? $taxReturn['tax_period']))?></p>
    </div>
    <a class="btn secondary" href="<?=$isCitizen ? 'citizen_filings.php' : 'payments.php'?>">Back</a>
</div>
<?php if ($error): ?><div class="alert danger"><?=e($error)?></div><?php endif; ?>

<?php if ($request): ?>
<?php if ($request['status'] === 'Pending'): ?><script>setTimeout(function(){ location.reload(); }, 8000);</script><?php endif; ?>
<section class="panel">
    <h2><?=e($request['status'])?></h2>
    <p><?=e($request['result_desc'] ?: ($request['status'] === 'Pending'
        ? 'An M-Pesa prompt was sent to ' . $request['phone'] . '. Enter your PIN on your phone to approve the payment.'
        : ''))?></p>
    <p><strong>Amount:</strong> KSh <?=number_format((float)$request['amount'], 2)?></p>
    <p><strong>Phone:</strong> <?=e($request['phone'])?></p>
    <?php if ($request['mpesa_receipt_no']): ?><p><strong>M-Pesa transaction:</strong> <?=e($request['mpesa_receipt_no'])?></p><?php endif; ?>
    <?php if ($request['payment_id']): ?><a class="btn" href="receipt.php?id=<?=(int)$request['payment_id']?>">View receipt</a><?php endif; ?>
    <?php if ($request['status'] === 'Pending'): ?><p class="muted">This page refreshes while Safaricom processes the request. Do not send another prompt while it is pending.</p><?php endif; ?>
</section>
<?php else:
    $available = round((float)$taxReturn['amount'] - (float)$taxReturn['paid'] - (float)$taxReturn['reserved'], 2);
    $wholeAmount = max(0, (int)floor($available)); ?>
<section class="panel form-panel">
    <p><strong><?=e($taxReturn['full_name'])?></strong> (<?=e($taxReturn['kra_pin'])?>)
        &middot; Available balance: <strong>KSh <?=number_format(max(0, $available), 2)?></strong></p>
    <?php if ($taxReturn['status'] === 'Rejected'): ?>
        <p class="muted">This return was rejected and cannot take payments.</p>
    <?php elseif ((float)$taxReturn['reserved'] > 0): ?>
        <p class="muted">An M-Pesa request is already pending or under review. Check its status before starting another.</p>
        <a class="btn" href="stk_push.php?id=<?=(int)$taxReturn['active_request_id']?>">Check M-Pesa status</a>
    <?php elseif ($wholeAmount < 1): ?>
        <p class="muted">No whole-shilling balance is available for STK Push. Use the existing staff-recorded payment method for any fractional balance.</p>
    <?php else: ?>
    <form method="post" class="form-grid"><?php csrf_field(); ?>
        <input type="hidden" name="return_id" value="<?=$returnId?>">
        <div><label>Phone number</label><input name="phone" type="tel" required value="<?=e($_POST['phone'] ?? $taxReturn['phone'])?>" placeholder="0712345678"></div>
        <div><label>Amount (KSh, whole shillings)</label><input name="amount" type="number" step="1" min="1" max="<?=$wholeAmount?>" required value="<?=e($_POST['amount'] ?? $wholeAmount)?>"></div>
        <div class="span-2"><button class="btn">Send M-Pesa prompt</button></div>
    </form>
    <p class="muted">Enter the M-Pesa PIN on your phone. The system issues a receipt only after Safaricom confirms payment.</p>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php include 'footer.php'; ?>
