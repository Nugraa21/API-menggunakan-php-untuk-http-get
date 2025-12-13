<?php
include "config.php";
randomDelay();
validateApiKey();

// DEBUG: Pastikan sampai sini jalan
// echo json_encode(["debug" => "validateApiKey passed"]); exit;

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) $data = $_POST;

$username = sanitizeInput($data['username'] ?? '');
$password = $data['password'] ?? '';

if (empty($username) || empty($password)) {
    echo json_encode(["status" => false, "message" => "Username dan password wajib diisi"]);
    exit;
}

// Cek apakah tabel users ada kolomnya
$stmt = $conn->prepare("SELECT id, password, nama_lengkap, role FROM users WHERE username = ? LIMIT 1");
if (!$stmt) {
    echo json_encode(["status" => false, "message" => "Query error: " . $conn->error]);
    exit;
}

$stmt->bind_param("s", $username);
if (!$stmt->execute()) {
    echo json_encode(["status" => false, "message" => "Execute failed: " . $stmt->error]);
    exit;
}

$result = $stmt->get_result();
if ($result->num_rows == 0) {
    echo json_encode(["status" => false, "message" => "Username atau password salah"]);
    $stmt->close();
    exit;
}

$user = $result->fetch_assoc();
$stmt->close();

if (!password_verify($password, $user['password'])) {
    echo json_encode(["status" => false, "message" => "Username atau password salah"]);
    exit;
}

// Login berhasil - buat token
$token = bin2hex(random_bytes(32));
$expires = date('Y-m-d H:i:s', strtotime('+30 days'));
$user_id = $user['id'];

// Insert token (ignore error kalau tabel belum ada)
$conn->query("CREATE TABLE IF NOT EXISTS login_tokens (
    user_id INT PRIMARY KEY,
    token VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL
)");

$stmt_token = $conn->prepare("INSERT INTO login_tokens (user_id, token, expires_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at)");
if ($stmt_token) {
    $stmt_token->bind_param("iss", $user_id, $token, $expires);
    $stmt_token->execute();
    $stmt_token->close();
}

echo json_encode([
    "status" => true,
    "message" => "Login berhasil",
    "user" => [
        "id" => (string)$user_id,
        "nama_lengkap" => $user['nama_lengkap'] ?? 'User',
        "role" => $user['role'] ?? 'user'
    ],
    "token" => $token
]);

$conn->close();
?>