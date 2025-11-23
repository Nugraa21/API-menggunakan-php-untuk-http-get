<?php
// update_photo.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status"=>false,"message"=>"Method Not Allowed"]);
    exit;
}

$id    = $_POST['id'] ?? null;
$label = $_POST['label'] ?? null;

if (!$id || $label === null) {
    echo json_encode(["status"=>false,"message"=>"ID atau label kosong"]);
    exit;
}

$stmt = $conn->prepare("UPDATE photos SET label = ? WHERE id = ?");
$stmt->bind_param("si", $label, $id);

if ($stmt->execute()) {
    echo json_encode(["status"=>true,"message"=>"Label berhasil diupdate"]);
} else {
    http_response_code(500);
    echo json_encode(["status"=>false,"message"=>"Gagal update data"]);
}
$stmt->close();
$conn->close();
