<?php
// login.php - VERSI ENKRIPSI (CODE/P)
// Output: JSON {"encrypted_data": "..."} berisi string enkripsi dari response asli.
// Tidak ada HTML error yang muncul.

require_once "config.php";
require_once "encryption.php";

header('Content-Type: application/json');

// Mencegah output HTML dari error PHP
error_reporting(0);
ini_set('display_errors', 0);

// Helper function untuk output encrypted response dan exit
function sendEncryptedResponse($data, $httpCode = 200)
{
    global $conn;
    http_response_code($httpCode);
    $json = json_encode($data);
    $encrypted = Encryption::encrypt($json);
    echo json_encode(["encrypted_data" => $encrypted]);
    if ($conn)
        $conn->close();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS')
    exit(0);

randomDelay();
validateApiKey();

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?? $_POST;

$login_input = trim($data['username'] ?? '');
$password = $data['password'] ?? '';
$device_id = trim($data['device_id'] ?? '');

if ($login_input === '' || $password === '') {
    sendEncryptedResponse(["status" => false, "message" => "Username dan Password wajib diisi"], 400);
}

$stmt = $conn->prepare("SELECT id, username, nip_nisn, password, nama_lengkap, role, device_id FROM users WHERE (username = ? OR nip_nisn = ?) LIMIT 1");
if (!$stmt) {
    sendEncryptedResponse(["status" => false, "message" => "DB Error"], 500);
}
$stmt->bind_param("ss", $login_input, $login_input);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    $stmt->close();
    sendEncryptedResponse(["status" => false, "message" => "User tidak ditemukan"], 401);
}

$user = $result->fetch_assoc();
$stmt->close();

if (!password_verify($password, $user['password'])) {
    sendEncryptedResponse(["status" => false, "message" => "Password salah"], 401);
}

// Device Binding Logic
if ($user['role'] === 'user' && $device_id !== '') {
    if ($user['device_id'] !== null && $user['device_id'] !== '' && $user['device_id'] !== $device_id) {
        sendEncryptedResponse(["status" => false, "message" => "Akun terikat di perangkat lain"], 403);
    }
    if ($user['device_id'] === null || $user['device_id'] === '') {
        $update = $conn->prepare("UPDATE users SET device_id = ? WHERE id = ?");
        $update->bind_param("si", $device_id, $user['id']);
        $update->execute();
        $update->close();
    }
}

$token = bin2hex(random_bytes(32));
$expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));

$stmt_token = $conn->prepare("INSERT INTO login_tokens (user_id, token, expires_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at)");
$stmt_token->bind_param("iss", $user['id'], $token, $expires_at);
$stmt_token->execute();
$stmt_token->close();

$userData = [
    "id" => (string) $user['id'],
    "username" => $user['username'],
    "nama_lengkap" => $user['nama_lengkap'],
    "role" => $user['role']
];

$response = [
    "status" => true,
    "message" => "Login Berhasil (Encrypted)",
    "user" => $userData,
    "token" => $token
];

sendEncryptedResponse($response, 200);
?>