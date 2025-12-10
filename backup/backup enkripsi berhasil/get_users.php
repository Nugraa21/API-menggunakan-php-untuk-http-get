<?php
error_reporting(0);
ini_set('display_errors', 0);
include "config.php";
include "encryption.php";
header('Content-Type: application/json');

$sql = "SELECT id, username, nama_lengkap, nip_nisn, role FROM users ORDER BY id DESC";
$run = mysqli_query($conn, $sql);
$data = [];
while ($row = mysqli_fetch_assoc($run)) {
    $data[] = $row;
}

$response = ["status" => "success", "data" => $data];
$json = json_encode($response, JSON_UNESCAPED_UNICODE);
$encrypted = Encryption::encrypt($json);

echo json_encode(["encrypted_data" => $encrypted]);
?>