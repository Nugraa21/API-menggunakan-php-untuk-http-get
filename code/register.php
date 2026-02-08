<?php
/**
 * REGISTER API - UNTUK TESTING (TIDAK ENKRIPSI)
 */

require_once "config.php"; // Menggunakan config lokal tanpa proteksi

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?? $_POST;

$username = $data['username'] ?? '';
$password_raw = $data['password'] ?? '';
$nama = $data['nama_lengkap'] ?? '';
$device_id = $data['device_id'] ?? '';
$role = $data['role'] ?? 'user';

if (!$username || !$password_raw || !$nama) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "Gagal: Data tidak lengkap"]);
    exit;
}

// Cek username duplikat
$check = $conn->prepare("SELECT id FROM users WHERE username = ?");
$check->bind_param("s", $username);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    http_response_code(409); // Conflict
    echo json_encode(["status" => false, "message" => "Username sudah digunakan"]);
    $check->close();
    exit;
}
$check->close();

// Hash password
$password = password_hash($password_raw, PASSWORD_BCRYPT);

// Insert User
// Kolom 'device_id' mungkin null jika tidak dari aplikasi
$stmt = $conn->prepare("INSERT INTO users (username, password, nama_lengkap, role, device_id) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("sssss", $username, $password, $nama, $role, $device_id);

if ($stmt->execute()) {
    http_response_code(201); // Created
    echo json_encode(["status" => true, "message" => "Registrasi Berhasil (Mode Testing)"]);
} else {
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Gagal Registrasi: " . $stmt->error]);
}
$stmt->close();
$conn->close();
?>