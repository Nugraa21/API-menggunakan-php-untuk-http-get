<?php
// <!-- presensi_rekap.php -->
include 'config.php';

// Header JSON
header('Content-Type: application/json');

// Suppress HTML errors
ini_set('display_errors', 0);

$sql = "SELECT p.*, u.nama_lengkap, u.username
        FROM absensi p  /* Line 9: Samain table absensi */
        JOIN users u ON p.user_id = u.id
        ORDER BY p.created_at DESC";  /* Line 10: Field created_at */

$result = $conn->query($sql);
if (!$result) {
    echo json_encode(["error" => "Query gagal: " . mysqli_error($conn)]);
    exit;
}

$data = [];
while ($row = $result->fetch_assoc()) {
    // Fix Line 13: Ganti ?? dengan isset untuk kompatibilitas PHP lama
    $row['status'] = isset($row['approve_status']) ? $row['approve_status'] : 'Pending';
    unset($row['approve_status']);  /* Line 14: Hapus field lama */
    $data[] = $row;
}

echo json_encode($data);  /* Line 16: Pure JSON */
?>