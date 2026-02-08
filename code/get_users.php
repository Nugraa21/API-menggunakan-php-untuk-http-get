<?php
/**
 * GET USERS - UNTUK TESTING (TIDAK ENKRIPSI)
 * Menampilkan data user secara langsung tanpa enkripsi AES
 */

require_once "config.php"; // Menggunakan config lokal tanpa proteksi

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$sql = "SELECT id, username, nama_lengkap, nip_nisn, role, status, device_id FROM users ORDER BY id DESC";
$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "SQL Error: " . mysqli_error($conn)]);
    exit;
}

$data = [];
while ($row = mysqli_fetch_assoc($result)) {
    // Logic pembersihan data (sama seperti asli)
    $row['id'] = (string) $row['id'];

    // Skip invalid ID
    if (empty($row['id']) || $row['id'] === '0') {
        continue;
    }

    $row['username'] = $row['username'] ?? '';
    $row['nama_lengkap'] = $row['nama_lengkap'] ?? '';
    $row['nip_nisn'] = $row['nip_nisn'] ?? '';
    $row['role'] = $row['role'] ?? 'user';
    $row['status'] = $row['status'] ?? 'Karyawan';
    $row['device_id'] = $row['device_id'] ?? '';

    $data[] = $row;
}
// Return JSON langsung (TIDAK DI-ENKRIPSI)
echo json_encode([
    "status" => "success",
    "data" => $data,
    "info" => "Ini adalah versi testing tanpa enkripsi"
], JSON_UNESCAPED_UNICODE);

mysqli_close($conn);
?>