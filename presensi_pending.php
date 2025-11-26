<?php
// <!-- presensi_pending.php -->
include 'config.php';

header('Content-Type: application/json');
ini_set('display_errors', 0);

$sql = "SELECT p.*, u.nama_lengkap 
        FROM absensi p 
        JOIN users u ON p.user_id = u.id
        WHERE p.approve_status='PENDING'";  /* Line 9: Filter pending, samain table */

$result = $conn->query($sql);
if (!$result) {
    echo json_encode(["error" => "Query gagal: " . mysqli_error($conn)]);
    exit;
}

$data = [];
while ($row = $result->fetch_assoc()) {
    // Fix Line ~14: Ganti ?? dengan isset
    $row['status'] = isset($row['approve_status']) ? $row['approve_status'] : 'Pending';
    unset($row['approve_status']);
    $data[] = $row;
}

echo json_encode($data);
?>