<?php
// presensi_rekap.php (standarisasi ke status, return konsisten dengan absen_admin_list.php)
include 'config.php';
// Header JSON
header('Content-Type: application/json');
// Suppress HTML errors
ini_set('display_errors', 0);
$sql = "SELECT p.*, u.nama_lengkap, u.username
        FROM absensi p
        JOIN users u ON p.user_id = u.id
        ORDER BY p.created_at DESC";
$result = $conn->query($sql);
if (!$result) {
    echo json_encode(["status" => false, "error" => "Query gagal: " . mysqli_error($conn)]);
    exit;
}
$data = [];
while ($row = $result->fetch_assoc()) {
    // Gunakan status langsung
    $row['status'] = $row['status'] ?? 'Pending';
    $data[] = $row;
}
echo json_encode(["status" => true, "data" => $data]);
?>