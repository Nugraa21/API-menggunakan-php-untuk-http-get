<?php
// register.php - VERSI ENKRIPSI (CODE/P)
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

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?? $_POST;

$username = $data['username'] ?? '';
$password_raw = $data['password'] ?? '';
$nama = $data['nama_lengkap'] ?? '';
$device_id = $data['device_id'] ?? '';
$role = $data['role'] ?? 'user';

if (!$username || !$password_raw || !$nama) {
    sendEncryptedResponse(["status" => false, "message" => "Gagal: Data tidak lengkap"], 400);
}

// Cek username duplikat
$check = $conn->prepare("SELECT id FROM users WHERE username = ?");
$check->bind_param("s", $username);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    $check->close();
    sendEncryptedResponse(["status" => false, "message" => "Username sudah digunakan"], 409);
}
$check->close();

// Hash password
$password = password_hash($password_raw, PASSWORD_BCRYPT);

// Insert User
$stmt = $conn->prepare("INSERT INTO users (username, password, nama_lengkap, role, device_id) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("sssss", $username, $password, $nama, $role, $device_id);

if ($stmt->execute()) {
    $stmt->close();
    sendEncryptedResponse(["status" => true, "message" => "Registrasi Berhasil (Encrypted)"], 201);
} else {
    $error = $stmt->error;
    $stmt->close();
    sendEncryptedResponse(["status" => false, "message" => "Gagal Registrasi: " . $error], 500);
}
?>