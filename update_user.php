<?php
// update_user.php - ENCRYPTED
include "config.php";
require_once "encryption.php";

header('Content-Type: application/json');

// --- DECRYPT INPUT ---
$raw = file_get_contents('php://input');
$input_json = json_decode($raw, true);

$data = [];
if (isset($input_json['encrypted_data'])) {
    $decrypted = Encryption::decrypt($input_json['encrypted_data']);
    if ($decrypted === false) {
        $res = ["status" => "error", "message" => "Gagal dekripsi data"];
        echo json_encode(["encrypted_data" => Encryption::encrypt(json_encode($res))]);
        exit;
    }
    $data = json_decode($decrypted, true);
} else {
    $data = array_merge($_POST, $input_json ?? []);
}
// ---------------------

// Ambil data
$id = $data['id'] ?? null;
$user_id = $data['user_id'] ?? null;
$username = trim($data['username'] ?? '');
$nama_lengkap = trim($data['nama_lengkap'] ?? '');
$password = $data['password'] ?? '';
$nip_nisn = trim($data['nip_nisn'] ?? '');
$role = $data['role'] ?? 'user';
$status = $data['status'] ?? 'Karyawan';
$reset_device = $data['reset_device'] ?? false;

// Tentukan ID yang akan dipakai
$target_id = $id ?? $user_id;

$response = ["status" => "error", "message" => "Unknown error"];

if ($target_id === null || $target_id === '') {
    // ================== MODE TAMBAH USER BARU ==================
    if ($username === '' || $nama_lengkap === '' || $password === '') {
        $response = ["status" => "error", "message" => "Username, nama lengkap, dan password wajib diisi"];
    } else {
        // Cek username sudah ada
        $check = mysqli_query($conn, "SELECT id FROM users WHERE username = '" . mysqli_real_escape_string($conn, $username) . "'");
        if (mysqli_num_rows($check) > 0) {
            $response = ["status" => "error", "message" => "Username sudah digunakan"];
        } else {
            // Validasi role & status
            $valid_roles = ['user', 'admin', 'superadmin'];
            $valid_statuses = ['Karyawan', 'Guru', 'Staff Lain'];
            if (!in_array($role, $valid_roles))
                $role = 'user';
            if (!in_array($status, $valid_statuses))
                $status = 'Karyawan';

            $hashed = password_hash($password, PASSWORD_DEFAULT);

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
        }
    }

} else {
    // ================== MODE EDIT USER ATAU RESET DEVICE ==================
    $target_id = (int) $target_id;

    // Cek user ada
    $check = mysqli_query($conn, "SELECT id FROM users WHERE id = $target_id");
    if (mysqli_num_rows($check) == 0) {
        $response = ["status" => "error", "message" => "User tidak ditemukan"];
    } else {
        $updates = [];

        // Username
        if ($username !== '') {
            $esc_username = mysqli_real_escape_string($conn, $username);
            $dup = mysqli_query($conn, "SELECT id FROM users WHERE username = '$esc_username' AND id != $target_id");
            if (mysqli_num_rows($dup) > 0) {
                $response = ["status" => "error", "message" => "Username sudah digunakan oleh user lain"];
            } else {
                $updates[] = "username = '$esc_username'";
            }
        }

        // Jika response belum error karena username duplikat, lanjut update kolom lain
        if ($response["message"] == "Unknown error") {
            if ($nama_lengkap !== '') {
                $updates[] = "nama_lengkap = '" . mysqli_real_escape_string($conn, $nama_lengkap) . "'";
            }
            if ($nip_nisn !== '') {
                $updates[] = "nip_nisn = '" . mysqli_real_escape_string($conn, $nip_nisn) . "'";
            }
            if ($password !== '') {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $updates[] = "password = '$hashed'";
            }
            if ($role !== '' && in_array($role, ['user', 'admin', 'superadmin'])) {
                $updates[] = "role = '" . mysqli_real_escape_string($conn, $role) . "'";
            }
            if ($status !== '' && in_array($status, ['Karyawan', 'Guru', 'Staff Lain'])) {
                $updates[] = "status = '" . mysqli_real_escape_string($conn, $status) . "'";
            }
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
    }
}

// --- ENCRYPT OUTPUT ---
echo json_encode([
    "encrypted_data" => Encryption::encrypt(json_encode($response))
]);
mysqli_close($conn);
?>