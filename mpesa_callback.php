<?php
require_once 'config.php';
require_once 'mpesa.php';

function mpesa_callback_response($statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mpesa_callback_response(405);
}

$token = (string)($_GET['t'] ?? '');
$payload = json_decode(file_get_contents('php://input'), true);
$callback = $payload['Body']['stkCallback'] ?? null;
$checkoutId = (string)($callback['CheckoutRequestID'] ?? '');
$resultCode = $callback['ResultCode'] ?? null;
if ($token === '' || !is_array($callback) || $checkoutId === '' || !is_numeric($resultCode)) {
    error_log('Received an invalid M-Pesa STK callback.');
    mpesa_callback_response(400);
}

try {
    $conn->beginTransaction();
    $stmt = $conn->prepare("SELECT * FROM mpesa_stk_requests WHERE checkout_request_id = :checkout FOR UPDATE");
    $stmt->execute([':checkout' => $checkoutId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$request) {
        $byToken = $conn->prepare("SELECT * FROM mpesa_stk_requests
            WHERE callback_token_hash = :token_hash AND status = 'Pending' FOR UPDATE");
        $byToken->execute([':token_hash' => hash('sha256', $token)]);
        $request = $byToken->fetch(PDO::FETCH_ASSOC);
        if ($request) {
            $bindCheckout = $conn->prepare("UPDATE mpesa_stk_requests
                SET checkout_request_id = :checkout, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $bindCheckout->execute([':checkout' => $checkoutId, ':id' => $request['id']]);
        }
    }
    if (!$request) {
        $conn->rollBack();
        error_log('Received an M-Pesa callback for an unknown CheckoutRequestID.');
        mpesa_callback_response(503);
    }
    if (!hash_equals($request['callback_token_hash'], hash('sha256', $token))) {
        $conn->rollBack();
        mpesa_callback_response(403);
    }
    if ($request['status'] !== 'Pending') {
        $conn->commit();
        mpesa_callback_response();
    }

    if ((int)$resultCode !== 0) {
        $update = $conn->prepare("UPDATE mpesa_stk_requests SET status = 'Failed', result_code = :code,
            result_desc = :desc, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $update->execute([
            ':code' => (string)$resultCode,
            ':desc' => substr((string)($callback['ResultDesc'] ?? 'M-Pesa payment was not completed.'), 0, 1000),
            ':id' => $request['id'],
        ]);
        $conn->commit();
        mpesa_callback_response();
    }

    $metadata = [];
    foreach (($callback['CallbackMetadata']['Item'] ?? []) as $item) {
        if (isset($item['Name'])) { $metadata[$item['Name']] = $item['Value'] ?? null; }
    }
    $amount = isset($metadata['Amount']) ? (float)$metadata['Amount'] : null;
    $receipt = trim((string)($metadata['MpesaReceiptNumber'] ?? ''));
    $phone = '';
    if (isset($metadata['PhoneNumber'])) {
        try { $phone = mpesa_normalize_phone((string)$metadata['PhoneNumber']); }
        catch (RuntimeException $e) { $phone = ''; }
    }
    if ($receipt !== '') {
        $duplicate = $conn->prepare("SELECT id FROM mpesa_stk_requests
            WHERE mpesa_receipt_no = :receipt AND id <> :id");
        $duplicate->execute([':receipt' => $receipt, ':id' => $request['id']]);
        if ($duplicate->fetchColumn()) {
            $update = $conn->prepare("UPDATE mpesa_stk_requests SET status = 'Review', result_code = '0',
                result_desc = 'This M-Pesa receipt is already linked to another request. Reconciliation is required.',
                updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $update->execute([':id' => $request['id']]);
            $conn->commit();
            mpesa_callback_response();
        }
    }
    if ($amount === null || $receipt === '' || $phone !== $request['phone']
        || round($amount, 2) !== round((float)$request['amount'], 2)) {
        $update = $conn->prepare("UPDATE mpesa_stk_requests SET status = 'Review', result_code = '0',
            result_desc = 'Safaricom reported success but the payment details did not match. Reconciliation is required.',
            mpesa_receipt_no = :receipt, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $update->execute([':receipt' => $receipt ?: null, ':id' => $request['id']]);
        $conn->commit();
        mpesa_callback_response();
    }

    $returnLock = $conn->prepare("SELECT amount, status FROM tax_returns WHERE id = :id FOR UPDATE");
    $returnLock->execute([':id' => $request['return_id']]);
    $taxReturn = $returnLock->fetch(PDO::FETCH_ASSOC);
    $paidStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE return_id = :id");
    $paidStmt->execute([':id' => $request['return_id']]);
    $otherPending = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM mpesa_stk_requests
        WHERE return_id = :id AND id <> :request_id AND status IN ('Pending', 'Review')");
    $otherPending->execute([':id' => $request['return_id'], ':request_id' => $request['id']]);
    $balance = round((float)$taxReturn['amount'] - (float)$paidStmt->fetchColumn() - (float)$otherPending->fetchColumn(), 2);
    if ($taxReturn['status'] === 'Rejected' || $amount > $balance) {
        $update = $conn->prepare("UPDATE mpesa_stk_requests SET status = 'Review', result_code = '0',
            result_desc = 'Safaricom confirmed payment, but the return balance changed. Reconciliation is required.',
            mpesa_receipt_no = :receipt, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $update->execute([':receipt' => $receipt, ':id' => $request['id']]);
        $conn->commit();
        mpesa_callback_response();
    }

    $payment = $conn->prepare("INSERT INTO payments (return_id, amount, mode, receipt_no, recorded_by)
        VALUES (:return_id, :amount, 'M-Pesa STK', :temporary_receipt, :recorded_by) RETURNING id");
    $payment->execute([
        ':return_id' => $request['return_id'],
        ':amount' => $amount,
        ':temporary_receipt' => 'TMP' . bin2hex(random_bytes(8)),
        ':recorded_by' => $request['requested_by'],
    ]);
    $paymentId = (int)$payment->fetchColumn();
    $receiptNo = 'RCT-' . date('Ymd') . '-' . str_pad((string)$paymentId, 5, '0', STR_PAD_LEFT);
    $updateReceipt = $conn->prepare("UPDATE payments SET receipt_no = :receipt WHERE id = :id");
    $updateReceipt->execute([':receipt' => $receiptNo, ':id' => $paymentId]);

    $updateRequest = $conn->prepare("UPDATE mpesa_stk_requests SET status = 'Completed', result_code = '0',
        result_desc = 'Payment confirmed by Safaricom.', mpesa_receipt_no = :mpesa_receipt,
        payment_id = :payment_id, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
    $updateRequest->execute([
        ':mpesa_receipt' => $receipt,
        ':payment_id' => $paymentId,
        ':id' => $request['id'],
    ]);

    $audit = $conn->prepare("INSERT INTO audit_log
        (user_id, action, table_name, record_id, new_values, ip_address)
        VALUES (:user_id, 'create', 'payments', :record_id, :new_values, :ip)");
    $audit->execute([
        ':user_id' => $request['requested_by'],
        ':record_id' => $paymentId,
        ':new_values' => json_encode([
            'return_id' => (int)$request['return_id'],
            'amount' => $amount,
            'mode' => 'M-Pesa STK',
            'receipt_no' => $receiptNo,
            'mpesa_receipt_no' => $receipt,
        ]),
        ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
    $conn->commit();
} catch (Throwable $e) {
    if ($conn->inTransaction()) { $conn->rollBack(); }
    error_log('M-Pesa callback processing failed: ' . $e->getMessage());
    mpesa_callback_response(500);
}

mpesa_callback_response();
