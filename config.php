<?php
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val, " \t\n\r\0\x0B\"'");
        putenv("$key=$val");
        $_ENV[$key] = $val;
    }
}
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$databaseUrl = getenv('DATABASE_URL');
if ($databaseUrl) {
    $parts = parse_url($databaseUrl);
    $host = $parts['host'];
    $port = $parts['port'] ?? 5432;
    $db   = ltrim($parts['path'], '/');
    $user = $parts['user'];
    $password = $parts['pass'];
} else {
    $host = getenv('PGHOST') ?: 'localhost';
    $port = getenv('PGPORT') ?: '5432';
    $db   = getenv('PGDATABASE') ?: 'kra_nakuru';
    $user = getenv('PGUSER') ?: 'postgres';
    $password = getenv('PGPASSWORD') ?: '8017';
}

$dsn = "pgsql:host=$host;port=$port;dbname=$db";
$conn = new PDO($dsn, $user, $password);
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function require_login() {
    if (empty($_SESSION['user_id'])) { header("Location: login.php"); exit; }
}
function csrf_field() {
    echo '<input type="hidden" name="csrf_token" value="'.htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8').'">';
}
function verify_csrf() {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        flash('Invalid form submission. Please try again.', 'danger');
        return false;
    }
    return true;
}
function flash($message, $type="success") {
    $_SESSION['flash'] = [$message, $type];
}
function show_flash() {
    if (!empty($_SESSION['flash'])) {
        [$message, $type] = $_SESSION['flash']; unset($_SESSION['flash']);
        echo '<div class="alert '.e($type).'">'.e($message).'</div>';
    }
}
// Audit logging — records every create, update, delete for accountability
function audit_log($action, $table, $recordId, $oldValues = null, $newValues = null) {
    global $conn;
    if (empty($_SESSION['user_id'])) return;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $conn->prepare("INSERT INTO audit_log (user_id, action, table_name, record_id, old_values, new_values, ip_address) VALUES (:uid, :action, :table, :rid, :old, :new, :ip)");
    $stmt->execute([
        ':uid' => $_SESSION['user_id'],
        ':action' => $action,
        ':table' => $table,
        ':rid' => (int)$recordId,
        ':old' => $oldValues ? json_encode($oldValues) : null,
        ':new' => $newValues ? json_encode($newValues) : null,
        ':ip' => $ip
    ]);
}
// Get client IP address
function client_ip() {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}