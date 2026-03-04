<?php
// register.php - ENCRYPTED VERSION
include "config.php";
require_once "encryption.php"; // Include encryption logic

randomDelay();
validateApiKey();

header('Content-Type: application/json');

// --- DECRYPT INPUT ---
$raw = file_get_contents('php://input');
$input_json = json_decode($raw, true);

$data = [];
if (isset($input_json['encrypted_data'])) {
    $decrypted = Encryption::decrypt($input_json['encrypted_data']);
    if ($decrypted === false) {
        http_response_code(400); // Bad Request
        echo json_encode(["encrypted_data" => Encryption::encrypt(json_encode(["status" => "error", "message" => "Gagal dekripsi data"]))]);
        exit;
    }
    $data = json_decode($decrypted, true);
} else {
    // Fallback unencrypted (bisa dihapus kalau mau strict)
    $data = $input_json ?? $_POST;
}
// ---------------------

$username = sanitizeInput($data['username'] ?? '');
$nama = sanitizeInput($data['nama_lengkap'] ?? '');
$nip_nisn = sanitizeInput($data['nip_nisn'] ?? '');
$password_raw = $data['password'] ?? '';
$role = sanitizeInput($data['role'] ?? 'user');
$status = sanitizeInput($data['status'] ?? 'Karyawan');
$device_id = sanitizeInput($data['device_id'] ?? '');

$response = [];

if (empty($username) || empty($nama) || empty($password_raw)) {
    $response = ["status" => "error", "message" => "Data tidak lengkap"];
} elseif (strlen($password_raw) < 6) {
    $response = ["status" => "error", "message" => "Password minimal 6 karakter"];
} elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
    $response = ["status" => "error", "message" => "Username tidak valid"];
} elseif ($status !== 'Karyawan' && empty($nip_nisn)) {
    $response = ["status" => "error", "message" => "NIP/NISN wajib diisi untuk $status"];
} elseif (!in_array($status, ['Karyawan', 'Guru', 'Staff Lain'])) {
    $response = ["status" => "error", "message" => "Status pegawai tidak valid"];
} else {
    // Validasi Device ID duplikat via DB
    $can_proceed = true;
    $final_device_id = null;

    if ($role === 'user' && $device_id !== '') {
        $check_device = $conn->prepare("SELECT id FROM users WHERE device_id = ?");
        $check_device->bind_param("s", $device_id);
        $check_device->execute();
        $check_device->store_result();
        if ($check_device->num_rows > 0) {
            $response = ["status" => "error", "message" => "Perangkat ini sudah terdaftar dengan akun lain."];
            $can_proceed = false;
        }
        $check_device->close();
        if ($can_proceed) {
            $final_device_id = $device_id;
        }
    }

    if ($can_proceed) {
        $password = password_hash($password_raw, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (username, nama_lengkap, nip_nisn, password, role, status, device_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssss", $username, $nama, $nip_nisn, $password, $role, $status, $final_device_id);

        if ($stmt->execute()) {
            $msg = "Akun berhasil dibuat sebagai $status";
            if ($role === 'user' && $device_id !== '') {
                $msg .= " dan terikat ke perangkat ini";
            }
            $response = ["status" => "success", "message" => $msg];
        } else {
            $response = ["status" => "error", "message" => "Gagal mendaftar (Username mungkin sudah ada)"];
        }
        $stmt->close();
    }
}

// --- ENCRYPT OUTPUT ---
echo json_encode([
    "encrypted_data" => Encryption::encrypt(json_encode($response))
]);

$conn->close();
?>