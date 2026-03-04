<?php
// update_user.php - Handle: Tambah User Baru + Edit User + Reset Device ID
include "config.php";

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$data = array_merge($_POST, $input ?? []);

// Ambil data
$id           = $data['id'] ?? null;           // null atau kosong = mode tambah user baru
$user_id      = $data['user_id'] ?? null;      // untuk reset device (alternatif id)
$username     = trim($data['username'] ?? '');
$nama_lengkap = trim($data['nama_lengkap'] ?? '');
$password     = $data['password'] ?? '';
$nip_nisn     = trim($data['nip_nisn'] ?? '');
$role         = $data['role'] ?? 'user';
$status       = $data['status'] ?? 'Karyawan';
$reset_device = $data['reset_device'] ?? false;

// Tentukan ID yang akan dipakai (bisa dari 'id' atau 'user_id' untuk kompatibilitas reset)
$target_id = $id ?? $user_id;

$response = ["status" => "error", "message" => "Unknown error"];

if ($target_id === null || $target_id === '') {
    // ================== MODE TAMBAH USER BARU ==================
    if ($username === '' || $nama_lengkap === '' || $password === '') {
        $response = ["status" => "error", "message" => "Username, nama lengkap, dan password wajib diisi"];
        echo json_encode($response);
        exit;
    }

    // Cek username sudah ada
    $check = mysqli_query($conn, "SELECT id FROM users WHERE username = '" . mysqli_real_escape_string($conn, $username) . "'");
    if (mysqli_num_rows($check) > 0) {
        $response = ["status" => "error", "message" => "Username sudah digunakan"];
        echo json_encode($response);
        exit;
    }

    // Validasi role & status
    $valid_roles = ['user', 'admin', 'superadmin'];
    $valid_statuses = ['Karyawan', 'Guru', 'Staff Lain'];
    if (!in_array($role, $valid_roles)) $role = 'user';
    if (!in_array($status, $valid_statuses)) $status = 'Karyawan';

    // Hash password
    $hashed = password_hash($password, PASSWORD_DEFAULT);

    // Insert
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
        $response = ["status" => "success", "message" => "User baru berhasil ditambahkan"];
    } else {
        $response = ["status" => "error", "message" => "Gagal tambah user: " . mysqli_error($conn)];
    }

} else {
    // ================== MODE EDIT USER ATAU RESET DEVICE ==================
    $target_id = (int)$target_id; // pastikan integer

    // Cek user ada
    $check = mysqli_query($conn, "SELECT id FROM users WHERE id = $target_id");
    if (mysqli_num_rows($check) == 0) {
        $response = ["status" => "error", "message" => "User tidak ditemukan"];
        echo json_encode($response);
        exit;
    }

    $updates = [];

    // Username (hanya jika diisi)
    if ($username !== '') {
        $esc_username = mysqli_real_escape_string($conn, $username);
        // Cek duplikat kecuali dirinya sendiri
        $dup = mysqli_query($conn, "SELECT id FROM users WHERE username = '$esc_username' AND id != $target_id");
        if (mysqli_num_rows($dup) > 0) {
            $response = ["status" => "error", "message" => "Username sudah digunakan oleh user lain"];
            echo json_encode($response);
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

    // Reset Device ID (bisa standalone atau bareng edit)
    if ($reset_device === true || $reset_device === 'true' || $reset_device === '1') {
        $updates[] = "device_id = NULL";
    }

    if (empty($updates)) {
        $response = ["status" => "success", "message" => "Tidak ada perubahan yang dilakukan"];
    } else {
        $sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = $target_id";
        if (mysqli_query($conn, $sql)) {
            $msg = (in_array("device_id = NULL", $updates)) 
                ? "User diperbarui dan device ID telah direset" 
                : "User berhasil diperbarui";
            $response = ["status" => "success", "message" => $msg];
        } else {
            $response = ["status" => "error", "message" => "Gagal update: " . mysqli_error($conn)];
        }
    }
}

echo json_encode($response);
mysqli_close($conn);
?>