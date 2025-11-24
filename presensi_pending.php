<?php
include 'config.php';

$sql = "SELECT p.*, u.nama_lengkap 
        FROM presensi p 
        JOIN users u ON p.user_id = u.id
        WHERE approve_status='PENDING'";

$result = $conn->query($sql);
$data = [];

while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

echo json_encode($data);
?>
