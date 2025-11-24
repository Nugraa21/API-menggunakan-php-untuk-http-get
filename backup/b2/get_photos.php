<?php
// get_photos.php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

$host = "localhost";
$user = "root";
$pass = "";
$db   = "skaduta_presensi";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["status"=>false,"message"=>"Koneksi database gagal"]);
    exit;
}

$result = $conn->query("SELECT * FROM photos ORDER BY id DESC");
$data = [];

while ($row = $result->fetch_assoc()) {
    $row['image_url'] = "http://" . $_SERVER['HTTP_HOST'] . "/backendapk/" . $row['file_path'];
    $data[] = $row;
}

echo json_encode([
    "status" => true,
    "data" => $data
]);

$conn->close();
