<?php
// <!-- get_users.php -->
include "config.php";

header('Content-Type: application/json');
ini_set('display_errors', 0);

$sql = "SELECT id, username, nama_lengkap, nip_nisn, role FROM users ORDER BY id DESC";
$run = mysqli_query($conn, $sql);

if (!$run) {
    echo json_encode(["status" => "error", "message" => mysqli_error($conn)]);
    exit;
}

$data = [];
while ($row = mysqli_fetch_assoc($run)) {
    $data[] = $row;
}

echo json_encode([
    "status" => "success",
    "message" => "Data user berhasil dimuat",
    "data" => $data
]);
?>