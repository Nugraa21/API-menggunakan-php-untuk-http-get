<?php
include "config.php";
randomDelay();
validateApiKey();

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) $data = $_POST;

$username = sanitizeInput($data['username'] ?? '');
$nama = sanitizeInput($data['nama_lengkap'] ?? '');
$nip_nisn = sanitizeInput($data['nip_nisn'] ?? '');
$password_raw = $data['password'] ?? '';
$role = sanitizeInput($data['role'] ?? 'user');
$is_karyawan = !empty($data['is_karyawan']);

if (empty($username) || empty($nama) || empty($password_raw)) {
    echo json_encode(["status" => "error", "message" => "Data tidak lengkap"]);
    exit;
}

if (strlen($password_raw) < 6) {
    echo json_encode(["status" => "error", "message" => "Password minimal 6 karakter"]);
    exit;
}

if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
    echo json_encode(["status" => "error", "message" => "Username tidak valid"]);
    exit;
}

if (!$is_karyawan && empty($nip_nisn)) {
    echo json_encode(["status" => "error", "message" => "NIP/NISN wajib diisi"]);
    exit;
}

$password = password_hash($password_raw, PASSWORD_DEFAULT);

if (in_array($role, ['admin', 'superadmin'])) {
    $check = $conn->prepare("SELECT id FROM users WHERE role = ?");
    $check->bind_param("s", $role);
    $check->execute();
    $check->store_result();
    if ($check->num_rows > 0) {
        echo json_encode(["status" => "error", "message" => ucfirst($role) . " sudah ada"]);
        exit;
    }
    $check->close();
}

$stmt = $conn->prepare("INSERT INTO users (username, nama_lengkap, nip_nisn, password, role) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("sssss", $username, $nama, $nip_nisn, $password, $role);

if ($stmt->execute()) {
    echo json_encode(["status" => "success", "message" => "Akun berhasil dibuat"]);
} else {
    echo json_encode(["status" => "error", "message" => "Gagal mendaftar"]);
}

$stmt->close();
$conn->close();
?>