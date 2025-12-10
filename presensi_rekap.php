<?php
error_reporting(0);
ini_set('display_errors', 0);
include "config.php";
include "encryption.php";
header('Content-Type: application/json');

$sql = "SELECT p.*, u.nama_lengkap, u.username
        FROM absensi p
        JOIN users u ON p.user_id = u.id
        ORDER BY p.created_at DESC";

$result = $conn->query($sql);
$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

$response = ["status" => true, "data" => $data];
$json = json_encode($response, JSON_UNESCAPED_UNICODE);
$encrypted = Encryption::encrypt($json);

echo json_encode(["encrypted_data" => $encrypted]);
?>