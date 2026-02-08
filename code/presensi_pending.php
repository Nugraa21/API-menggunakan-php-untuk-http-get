<?php
/**
 * PRESENSI PENDING - UNTUK TESTING (TIDAK ENKRIPSI)
 */

require_once "config.php";

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Query untuk mengambil data absensi dengan status Waiting + Nama User
$sql = "SELECT p.*, u.nama_lengkap 
        FROM absensi p 
        JOIN users u ON p.user_id = u.id 
        WHERE p.status = 'Waiting' 
        ORDER BY p.id DESC";

$result = $conn->query($sql);

if (!$result) {
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "SQL Error: " . $conn->error]);
    exit;
}

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

echo json_encode([
    "status" => true,
    "message" => "Data Pending Presensi (Test Mode)",
    "data" => $data
]);

$conn->close();
?>