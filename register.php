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

if (!$is_karyawan && empty($nip_nisn)) {
    echo json_encode(["status" => "error", "message" => "NIP/NISN wajib diisi"]);
    exit;
}

// ===================== DEVICE BINDING UNTUK ROLE 'user' SAJA DAN JIKA DEVICE_ID DIKIRIM =====================
$final_device_id = null;
if ($role === 'user') {
    if ($device_id === '') {
        // Skip untuk Windows desktop, set null
        $final_device_id = null;
    } else {
        // Check if device_id already registered for another user
        $check_device = $conn->prepare("SELECT id FROM users WHERE device_id = ?");
        $check_device->bind_param("s", $device_id);
        $check_device->execute();
        $check_device->store_result();
        if ($check_device->num_rows > 0) {
            echo json_encode(["status" => "error", "message" => "Perangkat ini sudah terdaftar dengan akun lain. 1 perangkat hanya untuk 1 user."]);
            $check_device->close();
            exit;
        }
        $check_device->close();
        $final_device_id = $device_id;
    }
} else {
    // Untuk admin/superadmin, abaikan device_id dan set ke NULL
    $final_device_id = null;
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

$stmt = $conn->prepare("INSERT INTO users (username, nama_lengkap, nip_nisn, password, role, device_id) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->bind_param("ssssss", $username, $nama, $nip_nisn, $password, $role, $final_device_id);

if ($stmt->execute()) {
    $msg = "Akun berhasil dibuat";
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