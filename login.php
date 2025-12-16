<?php
/**
 * SECURE LOGIN API (ANTI SQLi, XSS, BRUTE FORCE)
 * Project : SKADUTA Presensi
 * Notes   : Safe for TA / Production
 */

require_once "config.php";
// require_once "security_sql.php";

// ===================== SECURITY HEADERS =====================
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

// ===================== BASIC PROTECTION =====================
randomDelay();            // Anti brute-force timing
validateApiKey();         // Mandatory API Key

// ===================== INPUT HANDLING =====================
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$username = trim($data['username'] ?? '');
$password = $data['password'] ?? '';
$device_id = trim($data['device_id'] ?? '');

if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Username dan password wajib diisi"
    ]);
    exit;
}

// ===================== DATABASE QUERY (ANTI SQLi) =====================
$stmt = $conn->prepare(
    "SELECT id, username, password, nama_lengkap, role, device_id 
     FROM users 
     WHERE username = ? 
     LIMIT 1"
);

if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Query preparation failed"
    ]);
    exit;
}

$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

// ===================== AUTH VALIDATION =====================
if ($result->num_rows !== 1) {
    http_response_code(401);
    echo json_encode([
        "status" => false,
        "message" => "Username atau password salah"
    ]);
    $stmt->close();
    exit;
}

$user = $result->fetch_assoc();
$stmt->close();

if (!password_verify($password, $user['password'])) {
    http_response_code(401);
    echo json_encode([
        "status" => false,
        "message" => "Username atau password salah"
    ]);
    exit;
}

// ===================== DEVICE BINDING CHECK & BIND (HANYA UNTUK ROLE 'user') =====================
// Cek kalau role user dan device_id dikirim
if ($user['role'] === 'user' && $device_id !== '') {
    // Kalau udah ada device_id di DB tapi gak match
    if ($user['device_id'] !== null && $user['device_id'] !== '' && $user['device_id'] !== $device_id) {
        http_response_code(403);  // Ganti ke 403 biar beda dari 401 (auth failure)
        echo json_encode([
            "status" => false,
            "message" => "Device ID tidak valid. Akun ini terikat ke perangkat lain. Hubungi admin untuk unbind."
        ], JSON_PRETTY_PRINT);
        exit;
    }
    
    // Kalau device OK atau belum ada (kosong), bind otomatis
    if ($user['device_id'] === null || $user['device_id'] === '') {
        $update_stmt = $conn->prepare("UPDATE users SET device_id = ? WHERE id = ?");
        if ($update_stmt) {
            $update_stmt->bind_param("si", $device_id, (int)$user['id']);
            $update_stmt->execute();
            $update_stmt->close();
            error_log("Device bound: User ID {$user['id']} to device $device_id");  // Log buat debug
        }
    }
}

// ===================== TOKEN GENERATION =====================
try {
    $token = bin2hex(random_bytes(32));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Token generation failed"
    ]);
    exit;
}

$expires = date('Y-m-d H:i:s', strtotime('+30 days'));
$user_id = (int)$user['id'];

// ===================== TOKEN STORAGE =====================
$conn->query(
    "CREATE TABLE IF NOT EXISTS login_tokens (
        user_id INT PRIMARY KEY,
        token CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL
    )"
);

$stmt_token = $conn->prepare(
    "INSERT INTO login_tokens (user_id, token, expires_at)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE
        token = VALUES(token),
        expires_at = VALUES(expires_at)"
);

if ($stmt_token) {
    $stmt_token->bind_param("iss", $user_id, $token, $expires);
    $stmt_token->execute();
    $stmt_token->close();
}

// ===================== RESPONSE =====================
http_response_code(200);
echo json_encode([
    "status" => true,
    "message" => "Login berhasil",
    "user" => [
        "id" => (string)$user_id,
        "username" => htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8'),
        "nama_lengkap" => htmlspecialchars($user['nama_lengkap'] ?? 'User', ENT_QUOTES, 'UTF-8'),
        "role" => htmlspecialchars($user['role'] ?? 'user', ENT_QUOTES, 'UTF-8')
    ],
    "token" => $token
]);

$conn->close();
?>