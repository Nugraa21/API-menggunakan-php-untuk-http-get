<?php
include "config.php";

$sql = "SELECT id, username, nama_lengkap, nip_nisn, role FROM users ORDER BY id DESC";
$run = mysqli_query($conn, $sql);

$data = [];

while ($row = mysqli_fetch_assoc($run)) {
    $data[] = $row;
}

echo json_encode([
    "status" => "success",
    "data" => $data
]);
?>
