<?php
header("Access-Control-Allow-Origin: *");

require "config.php";

$result = mysqli_query($conn, "SELECT * FROM presensi ORDER BY id DESC");

$data = [];

while ($row = mysqli_fetch_assoc($result)) {
    $data[] = $row;
}

echo json_encode([
    "status" => true,
    "count" => count($data),
    "data" => $data
]);
?>
