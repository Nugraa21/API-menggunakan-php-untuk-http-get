<?php
// delete_photo.php

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

$id = $_POST['id'] ?? null;
if (!$id) {
    echo json_encode(["status"=>false,"message"=>"ID tidak dikirim"]);
    exit;
}

$stmt = $conn->prepare("SELECT file_path FROM photos WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $filePath = __DIR__ . "/" . $row['file_path'];
    if (file_exists($filePath)) {
        unlink($filePath);
    }
}
$stmt->close();

$stmt2 = $conn->prepare("DELETE FROM photos WHERE id = ?");
$stmt2->bind_param("i", $id);
if ($stmt2->execute()) {
    echo json_encode(["status"=>true,"message"=>"Foto berhasil dihapus"]);
} else {
    http_response_code(500);
    echo json_encode(["status"=>false,"message"=>"Gagal menghapus data"]);
}
$stmt2->close();
$conn->close();
