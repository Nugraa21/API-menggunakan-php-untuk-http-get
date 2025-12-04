<?php
// presensi_pending.php (UPDATED: Include new fields, filter only Pending)
include 'config.php';
header('Content-Type: application/json');
ini_set('display_errors', 0);
$sql = "SELECT p.*, u.nama_lengkap
        FROM absensi p
        JOIN users u ON p.user_id = u.id
        WHERE p.status='Pending'";
$result = $conn->query($sql);
if (!$result) {
    echo json_encode(["status" => false, "error" => "Query gagal: " . mysqli_error($conn)]);
    exit;
}
$data = [];
while ($row = $result->fetch_assoc()) {
    $row['status'] = $row['status'] ?? 'Pending';
    $data[] = $row;
}
echo json_encode(["status" => true, "data" => $data]);
?>