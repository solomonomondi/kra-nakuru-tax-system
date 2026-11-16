
<?php
class MpesaRejectedException extends RuntimeException {}

function mpesa_config() {
    $environment = getenv('MPESA_ENV') ?: 'sandbox';
    if (!in_array($environment, ['sandbox', 'production'], true)) {
        throw new RuntimeException('MPESA_ENV must be sandbox or production.');
    }

    $config = [
        'environment' => $environment,
        'consumer_key' => getenv('MPESA_CONSUMER_KEY') ?: '',
        'consumer_secret' => getenv('MPESA_CONSUMER_SECRET') ?: '',
        'shortcode' => getenv('MPESA_SHORTCODE') ?: '',
        'passkey' => getenv('MPESA_PASSKEY') ?: '',
        'callback_url' => getenv('MPESA_CALLBACK_URL') ?: '',
        'transaction_type' => getenv('MPESA_TRANSACTION_TYPE') ?: 'CustomerPayBillOnline',
    ];
    foreach (['consumer_key', 'consumer_secret', 'shortcode', 'passkey', 'callback_url'] as $key) {
        if ($config[$key] === '') {
            throw new RuntimeException('M-Pesa is not configured. Set the required MPESA_* environment variables.');
        }
    }
    if (filter_var($config['callback_url'], FILTER_VALIDATE_URL) === false
        || parse_url($config['callback_url'], PHP_URL_SCHEME) !== 'https') {
        throw new RuntimeException('MPESA_CALLBACK_URL must be a publicly reachable HTTPS URL.');
    }

    $config['base_url'] = $environment === 'production'
        ? 'https://api.safaricom.co.ke'
        : 'https://sandbox.safaricom.co.ke';
    return $config;
}

function mpesa_http_request($url, $headers, $body = null) {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Enable the PHP cURL extension to use M-Pesa payments.');
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Could not initialize the M-Pesa connection.');
    }
$curlOptions = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
];

if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
    $curlOptions[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
}

curl_setopt_array($ch, $curlOptions);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false) {
        throw new RuntimeException('cURL error: ' . ($curlErr ?: 'Unknown connection failure'));
    }

    $decoded = json_decode($response, true);
    if ($status >= 400 && $status < 500) {
        $description = is_array($decoded)
            ? ($decoded['errorMessage'] ?? $decoded['ResponseDescription'] ?? '')
            : '';
        throw new MpesaRejectedException($description !== ''
            ? 'Safaricom rejected the request: ' . $description
            : 'Safaricom rejected the M-Pesa request (HTTP ' . $status . ').');
    }
    if ($status < 200 || $status >= 300 || !is_array($decoded)) {
        throw new RuntimeException('Safaricom returned an unexpected response. The request may still be processing.');
    }
    return $decoded;
}

function mpesa_access_token($config) {
    $auth = base64_encode($config['consumer_key'] . ':' . $config['consumer_secret']);
    $response = mpesa_http_request(
        $config['base_url'] . '/oauth/v1/generate?grant_type=client_credentials',
        ['Authorization: Basic ' . $auth]
    );
    if (empty($response['access_token'])) {
        throw new RuntimeException('Safaricom did not return an M-Pesa access token.');
    }
    return $response['access_token'];
}

function mpesa_normalize_phone($phone) {
    $phone = preg_replace('/[\s().-]+/', '', trim((string)$phone));
    if (strpos($phone, '+') === 0) {
        $phone = substr($phone, 1);
    }
    if (preg_match('/^0[17]\d{8}$/', $phone)) {
        $phone = '254' . substr($phone, 1);
    }
    if (!preg_match('/^254[17]\d{8}$/', $phone)) {
        throw new RuntimeException('Enter a valid Kenyan M-Pesa number, such as 0712345678.');
    }
    return $phone;
}

function mpesa_callback_url($baseUrl, $token) {
    $separator = strpos($baseUrl, '?') === false ? '?' : '&';
    return $baseUrl . $separator . 't=' . rawurlencode($token);
}

function mpesa_send_stk($config, $accessToken, $phone, $amount, $returnId, $callbackToken) {
    $timestamp = date('YmdHis');
    $password = base64_encode($config['shortcode'] . $config['passkey'] . $timestamp);
    $response = mpesa_http_request(
        $config['base_url'] . '/mpesa/stkpush/v1/processrequest',
        ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
        [
            'BusinessShortCode' => $config['shortcode'],
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => $config['transaction_type'],
            'Amount' => (int)$amount,
            'PartyA' => $phone,
            'PartyB' => $config['shortcode'],
            'PhoneNumber' => $phone,
            'CallBackURL' => mpesa_callback_url($config['callback_url'], $callbackToken),
            'AccountReference' => 'TAX' . (int)$returnId,
            'TransactionDesc' => 'Tax payment',
        ]
    );

    if ((string)($response['ResponseCode'] ?? '') !== '0'
        || empty($response['CheckoutRequestID'])
        || empty($response['MerchantRequestID'])) {
        $description = $response['ResponseDescription'] ?? 'Request was not accepted.';
        throw new MpesaRejectedException('M-Pesa could not start the STK prompt: ' . $description);
    }
    return $response;
}
