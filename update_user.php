<?php
// update_user.php
include "config.php";

header('Content-Type: application/json');
ini_set('display_errors', 0);

// Baca JSON dari Flutter
$input = json_decode(file_get_contents('php://input'), true);
$_POST = array_merge($_POST, $input ?? []);

$id = $_POST["id"] ?? '';
$username = $_POST["username"] ?? null;
$nama_lengkap = $_POST["nama_lengkap"] ?? null;
$nip_nisn = $_POST["nip_nisn"] ?? null;
$password = $_POST["password"] ?? null;
$role = $_POST["role"] ?? null;
$device_id = $_POST["device_id"] ?? null; // bisa "" atau null untuk reset

if (empty($id)) {
    echo json_encode(["status" => "error", "message" => "ID user wajib diisi"]);
    exit;
}

// Cek apakah user ada
$check = mysqli_query($conn, "SELECT id, role FROM users WHERE id = '" . mysqli_real_escape_string($conn, $id) . "'");
if (mysqli_num_rows($check) == 0) {
    echo json_encode(["status" => "error", "message" => "User tidak ditemukan"]);
    exit;
}

$row = mysqli_fetch_assoc($check);
$current_role = $row['role'];

// Validasi: Tidak boleh ubah role superadmin (opsional, bisa disesuaikan)
if ($current_role === 'superadmin' && $role !== 'superadmin') {
    // echo json_encode(["status" => "error", "message" => "Tidak boleh downgrade superadmin"]);
    // exit;
    // Kalau mau boleh, hapus blok ini
}

$updates = [];

// Username
if ($username !== null && trim($username) !== '') {
    $esc_username = mysqli_real_escape_string($conn, trim($username));
    // Cek duplikat username (kecuali diri sendiri)
    $dup = mysqli_query($conn, "SELECT id FROM users WHERE username = '$esc_username' AND id != '$id'");
    if (mysqli_num_rows($dup) > 0) {
        echo json_encode(["status" => "error", "message" => "Username sudah digunakan"]);
        exit;
    }
    $updates[] = "username = '$esc_username'";
}

// Nama lengkap
if ($nama_lengkap !== null && trim($nama_lengkap) !== '') {
    $updates[] = "nama_lengkap = '" . mysqli_real_escape_string($conn, trim($nama_lengkap)) . "'";
}

// NIP/NISN (boleh kosong)
if ($nip_nisn !== null) {
    $updates[] = "nip_nisn = '" . mysqli_real_escape_string($conn, trim($nip_nisn)) . "'";
}

// Password
if ($password !== null && trim($password) !== '') {
    $hashed = password_hash(trim($password), PASSWORD_DEFAULT);
    $updates[] = "password = '$hashed'";
}

// Role
if ($role !== null && in_array($role, ['user', 'admin', 'superadmin'])) {
    $updates[] = "role = '" . mysqli_real_escape_string($conn, $role) . "'";
}

// Device ID: jika dikirim string kosong atau "null", maka set NULL (unbind device)
if (array_key_exists('device_id', $_POST)) {
    if ($device_id === '' || $device_id === null || $device_id === 'null') {
        $updates[] = "device_id = NULL";
    } else {
        $esc_device = mysqli_real_escape_string($conn, trim($device_id));
        // Cek duplikat device_id (kecuali diri sendiri)
        $dup_dev = mysqli_query($conn, "SELECT id FROM users WHERE device_id = '$esc_device' AND id != '$id'");
        if (mysqli_num_rows($dup_dev) > 0) {
            echo json_encode(["status" => "error", "message" => "Device sudah terikat ke user lain"]);
            exit;
        }
        $updates[] = "device_id = '$esc_device'";
    }
}

if (empty($updates)) {
    echo json_encode(["status" => "success", "message" => "Tidak ada perubahan"]);
    exit;
}

$sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = '" . mysqli_real_escape_string($conn, $id) . "'";
$update = mysqli_query($conn, $sql);

if ($update) {
    echo json_encode(["status" => "success", "message" => "User berhasil diperbarui"]);
} else {
    echo json_encode(["status" => "error", "message" => "Gagal update: " . mysqli_error($conn)]);
}
?>