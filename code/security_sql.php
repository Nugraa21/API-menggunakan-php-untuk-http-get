<?php
/**
 * SECURITY SQL & AUTH MIDDLEWARE
 * Anti SQL Injection, Brute Force, Enumeration
 * Project : SKADUTA Presensi
 * Safe for TA & Production
 */

/* =========================================================
   SESSION & ERROR HANDLING
========================================================= */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

/* =========================================================
   SECURITY HEADERS
========================================================= */
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Permissions-Policy: geolocation=(), camera=()');

/* =========================================================
   SAFE EXIT RESPONSE
========================================================= */
function security_exit($code = 401, $message = "Unauthorized") {
    http_response_code($code);
    echo json_encode([
        "status"  => false,
        "message" => $message
    ]);
    exit;
}

/* =========================================================
   API KEY VALIDATION
========================================================= */
function validateApiKey() {
    if (!function_exists('getallheaders')) {
        security_exit(500, "Header function unavailable");
    }

    $headers = getallheaders();
    $apiKey  = $headers['X-API-KEY'] ?? $headers['x-api-key'] ?? '';

    $VALID_API_KEY = "Skaduta2025!@#SecureAPIKey1234567890";

    if ($apiKey === '' || !hash_equals($VALID_API_KEY, $apiKey)) {
        security_exit(401, "Unauthorized");
    }
}

/* =========================================================
   RANDOM DELAY (ANTI BRUTE FORCE)
========================================================= */
function randomDelay() {
    try {
        usleep(random_int(200000, 600000)); // 0.2 – 0.6 detik
    } catch (Exception $e) {
        usleep(300000);
    }
}

/* =========================================================
   RATE LIMITING (FILE BASED)
========================================================= */
function rateLimit($key, $limit = 5, $seconds = 60) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $hash = md5($ip . '_' . $key);
    $file = sys_get_temp_dir() . "/ratelimit_$hash.json";

    $data = [
        "count" => 0,
        "time"  => time()
    ];

    if (file_exists($file)) {
        $json = json_decode(file_get_contents($file), true);
        if (is_array($json)) {
            $data = $json;
        }
    }

    if ((time() - $data['time']) > $seconds) {
        $data = ["count" => 0, "time" => time()];
    }

    $data['count']++;

    if ($data['count'] > $limit) {
        security_exit(429, "Too many attempts");
    }

    file_put_contents($file, json_encode($data));
}

/* =========================================================
   INPUT SANITIZER
========================================================= */
function secureInput($input) {
    if (is_array($input)) {
        return array_map('secureInput', $input);
    }

    $input = trim($input);
    $input = strip_tags($input);
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

/* =========================================================
   USERNAME VALIDATION (WHITELIST)
========================================================= */
function validateUsername($username) {
    return preg_match('/^[a-zA-Z0-9_.]{3,30}$/', $username);
}

/* =========================================================
   AUTH FAIL (ANTI ENUMERATION)
========================================================= */
function authFail() {
    security_exit(401, "Login gagal");
}

/* =========================================================
   SQL SAFE PREPARE (ANTI SQL INJECTION)
========================================================= */
function sqlPrepare($conn, $query, $types = "", $params = []) {
    if (!$conn) {
        security_exit(500, "Database connection error");
    }

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        security_exit(500, "Query preparation failed");
    }

    if ($types !== "" && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    return $stmt;
}

/* =========================================================
   TOKEN HANDLER
========================================================= */
function generateToken() {
    return bin2hex(random_bytes(32));
}

function hashToken($token) {
    return hash('sha256', $token);
}

/* =========================================================
   TOKEN VALIDATION (FOR PROTECTED ENDPOINT)
========================================================= */
function validateToken($conn) {
    if (!function_exists('getallheaders')) {
        authFail();
    }

    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if ($auth === '' || stripos($auth, 'Bearer ') !== 0) {
        authFail();
    }

    $rawToken = trim(substr($auth, 7));
    if ($rawToken === '') {
        authFail();
    }

    $hashed = hashToken($rawToken);

    $stmt = sqlPrepare(
        $conn,
        "SELECT user_id FROM login_tokens 
         WHERE token = ? AND expires_at > NOW() 
         LIMIT 1",
        "s",
        [$hashed]
    );

    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows !== 1) {
        $stmt->close();
        authFail();
    }

    $stmt->close();
}

/* =========================================================
   END OF FILE
========================================================= */
?>