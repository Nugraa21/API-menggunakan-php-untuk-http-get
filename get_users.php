<?php
// get_users.php - VERSI DIPERBAIKI SESUAI CODE ASLI KAMU + FIX ID KOSONG
error_reporting(0);
ini_set('display_errors', 0);
include "config.php";
randomDelay();
validateApiKey();
include "encryption.php";
header('Content-Type: application/json');

// Query sama persis seperti code asli kamu, tapi tambah kolom status & device_id biar lengkap
$sql = "SELECT id, username, nama_lengkap, nip_nisn, role, status, device_id FROM users ORDER BY id DESC";

$run = mysqli_query($conn, $sql);

$data = [];
while ($row = mysqli_fetch_assoc($run)) {
    // *** INI YANG DIPERBAIKI: FORCE id jadi string dan pastikan TIDAK KOSONG ***
    $row['id'] = (string)$row['id']; // Konversi ke string
    if (empty($row['id']) || $row['id'] === '0') {
        // Skip user kalau id invalid (jarang terjadi, tapi safety)
        continue;
    }

    // Bersihkan null biar aman di Flutter
    $row['username'] = $row['username'] ?? '';
    $row['nama_lengkap'] = $row['nama_lengkap'] ?? '';
    $row['nip_nisn'] = $row['nip_nisn'] ?? '';
    $row['role'] = $row['role'] ?? 'user';
    $row['status'] = $row['status'] ?? 'Karyawan';
    $row['device_id'] = $row['device_id'] ?? '';

    $data[] = $row;
}

$response = ["status" => "success", "data" => $data];
$json = json_encode($response, JSON_UNESCAPED_UNICODE);
$encrypted = Encryption::encrypt($json);

echo json_encode(["encrypted_data" => $encrypted]);

mysqli_close($conn);
?>