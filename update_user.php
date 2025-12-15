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

if (empty($id)) {
    echo json_encode(["status" => "error", "message" => "ID user wajib diisi"]);
    exit;
}

// Cek user ada
$check = mysqli_query($conn, "SELECT id FROM users WHERE id = '" . mysqli_real_escape_string($conn, $id) . "'");
if (mysqli_num_rows($check) == 0) {
    echo json_encode(["status" => "error", "message" => "User tidak ditemukan"]);
    exit;
}

$updates = [];

// Username (cek duplikat kecuali diri sendiri)
if ($username !== null && trim($username) !== '') {
    $esc_username = mysqli_real_escape_string($conn, trim($username));
    $dup_check = mysqli_query($conn, "SELECT id FROM users WHERE username = '$esc_username' AND id != '$id'");
    if (mysqli_num_rows($dup_check) > 0) {
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
    $updates[] = "nip_nisn = '" . mysqli_real_escape_string($conn, trim($nip_nisn ?? '')) . "'";
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