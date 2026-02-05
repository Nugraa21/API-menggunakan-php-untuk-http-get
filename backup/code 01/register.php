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
$status = sanitizeInput($data['status'] ?? 'Karyawan'); // Default Karyawan
$device_id = sanitizeInput($data['device_id'] ?? '');

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

// Validasi NIP: wajib jika status bukan Karyawan
if ($status !== 'Karyawan' && empty($nip_nisn)) {
    echo json_encode(["status" => "error", "message" => "NIP/NISN wajib diisi untuk $status"]);
    exit;
}

// Validasi status hanya boleh 3 pilihan ini
if (!in_array($status, ['Karyawan', 'Guru', 'Staff Lain'])) {
    echo json_encode(["status" => "error", "message" => "Status pegawai tidak valid"]);
    exit;
}

// Device binding logic tetap sama
$final_device_id = null;
if ($role === 'user') {
    if ($device_id !== '') {
        $check_device = $conn->prepare("SELECT id FROM users WHERE device_id = ?");
        $check_device->bind_param("s", $device_id);
        $check_device->execute();
        $check_device->store_result();
        if ($check_device->num_rows > 0) {
            echo json_encode(["status" => "error", "message" => "Perangkat ini sudah terdaftar dengan akun lain."]);
            $check_device->close();
            exit;
        }
        $check_device->close();
        $final_device_id = $device_id;
    }
}

$password = password_hash($password_raw, PASSWORD_DEFAULT);

$stmt = $conn->prepare("INSERT INTO users (username, nama_lengkap, nip_nisn, password, role, status, device_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("sssssss", $username, $nama, $nip_nisn, $password, $role, $status, $final_device_id);

if ($stmt->execute()) {
    $msg = "Akun berhasil dibuat sebagai $status";
    if ($role === 'user' && $device_id !== '') {
        $msg .= " dan terikat ke perangkat ini";
    }
    echo json_encode(["status" => "success", "message" => $msg]);
} else {
    echo json_encode(["status" => "error", "message" => "Gagal mendaftar"]);
}

$stmt->close();
$conn->close();
?>