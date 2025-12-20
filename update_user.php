<?php
// update_user.php - SEKARANG BISA: Edit User + Tambah User Baru + Reset Device ID
include "config.php";

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$data = array_merge($_POST, $input ?? []);

// Ambil data
$id           = $data['id'] ?? null; // Kalau null/kosong = mode tambah user baru
$username     = trim($data['username'] ?? '');
$nama_lengkap = trim($data['nama_lengkap'] ?? '');
$password     = $data['password'] ?? '';
$nip_nisn     = trim($data['nip_nisn'] ?? '');
$role         = $data['role'] ?? 'user';
$status       = $data['status'] ?? 'Karyawan';
$reset_device = $data['reset_device'] ?? false;

// Validasi wajib
if ($id === null || $id === '') {
    // MODE TAMBAH USER BARU
    if ($username === '' || $nama_lengkap === '' || $password === '') {
        echo json_encode(["status" => "error", "message" => "Username, nama lengkap, dan password wajib diisi untuk user baru"]);
        exit;
    }

    // Cek username sudah ada
    $check = mysqli_query($conn, "SELECT id FROM users WHERE username = '" . mysqli_real_escape_string($conn, $username) . "'");
    if (mysqli_num_rows($check) > 0) {
        echo json_encode(["status" => "error", "message" => "Username sudah digunakan"]);
        exit;
    }

    // Validasi role & status
    $valid_roles = ['user', 'admin', 'superadmin'];
    $valid_status = ['Karyawan', 'Guru', 'Staff Lain'];
    if (!in_array($role, $valid_roles)) $role = 'user';
    if (!in_array($status, $valid_status)) $status = 'Karyawan';

    // Hash password
    $hashed = password_hash($password, PASSWORD_DEFAULT);

    // Insert user baru
    $sql = "INSERT INTO users 
            (username, nama_lengkap, nip_nisn, password, role, status, device_id) 
            VALUES (
                '" . mysqli_real_escape_string($conn, $username) . "',
                '" . mysqli_real_escape_string($conn, $nama_lengkap) . "',
                '" . mysqli_real_escape_string($conn, $nip_nisn) . "',
                '$hashed',
                '" . mysqli_real_escape_string($conn, $role) . "',
                '" . mysqli_real_escape_string($conn, $status) . "',
                NULL
            )";

    if (mysqli_query($conn, $sql)) {
        echo json_encode(["status" => "success", "message" => "User baru berhasil ditambahkan"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Gagal tambah user: " . mysqli_error($conn)]);
    }
} else {
    // MODE EDIT USER / RESET DEVICE
    // Cek user ada
    $check = mysqli_query($conn, "SELECT id FROM users WHERE id = '" . mysqli_real_escape_string($conn, $id) . "'");
    if (mysqli_num_rows($check) == 0) {
        echo json_encode(["status" => "error", "message" => "User tidak ditemukan"]);
        exit;
    }

    $updates = [];

    // Username
    if ($username !== '') {
        $esc_username = mysqli_real_escape_string($conn, $username);
        $dup_check = mysqli_query($conn, "SELECT id FROM users WHERE username = '$esc_username' AND id != '$id'");
        if (mysqli_num_rows($dup_check) > 0) {
            echo json_encode(["status" => "error", "message" => "Username sudah digunakan"]);
            exit;
        }
        $updates[] = "username = '$esc_username'";
    }

    // Nama lengkap
    if ($nama_lengkap !== '') {
        $updates[] = "nama_lengkap = '" . mysqli_real_escape_string($conn, $nama_lengkap) . "'";
    }

    // NIP/NISN
    if ($nip_nisn !== '') {
        $updates[] = "nip_nisn = '" . mysqli_real_escape_string($conn, $nip_nisn) . "'";
    }

    // Password
    if ($password !== '') {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $updates[] = "password = '$hashed'";
    }

    // Role
    if ($role !== '' && in_array($role, ['user', 'admin', 'superadmin'])) {
        $updates[] = "role = '" . mysqli_real_escape_string($conn, $role) . "'";
    }

    // Status
    if ($status !== '' && in_array($status, ['Karyawan', 'Guru', 'Staff Lain'])) {
        $updates[] = "status = '" . mysqli_real_escape_string($conn, $status) . "'";
    }

    // Reset device_id
    if ($reset_device === true || $reset_device === 'true') {
        $updates[] = "device_id = NULL";
    }

    if (empty($updates)) {
        echo json_encode(["status" => "success", "message" => "Tidak ada perubahan"]);
        exit;
    }

    $sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = '" . mysqli_real_escape_string($conn, $id) . "'";
    if (mysqli_query($conn, $sql)) {
        $msg = $reset_device ? "User diperbarui dan device direset" : "User berhasil diperbarui";
        echo json_encode(["status" => "success", "message" => $msg]);
    } else {
        echo json_encode(["status" => "error", "message" => "Gagal update: " . mysqli_error($conn)]);
    }
}

mysqli_close($conn);
?>