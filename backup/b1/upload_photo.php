<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

$host = "localhost";
$user = "root"; // ganti
$pass = "";     // ganti
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

if (!isset($_FILES['image'])) {
    http_response_code(400);
    echo json_encode(["status"=>false,"message"=>"Tidak ada file gambar"]);
    exit;
}

$latitude   = $_POST['latitude'] ?? null;
$longitude  = $_POST['longitude'] ?? null;
$capturedAt = $_POST['captured_at'] ?? null;

$uploadDir = __DIR__ . "/uploads/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$originalName = $_FILES['image']['name'];
$tmpName      = $_FILES['image']['tmp_name'];

$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$newName = uniqid("img_", true) . "." . $ext;
$targetPath   = $uploadDir . $newName;
$relativePath = "uploads/" . $newName;

if (!move_uploaded_file($tmpName, $targetPath)) {
    http_response_code(500);
    echo json_encode(["status"=>false,"message"=>"Gagal menyimpan file"]);
    exit;
}

$stmt = $conn->prepare(
    "INSERT INTO photos (file_name, file_path, latitude, longitude, captured_at)
     VALUES (?, ?, ?, ?, ?)"
);
$stmt->bind_param("ssdds", $newName, $relativePath, $latitude, $longitude, $capturedAt);

if ($stmt->execute()) {
    echo json_encode([
        "status" => true,
        "message" => "Upload berhasil",
        "file_url" => $relativePath
    ]);
} else {
    http_response_code(500);
    echo json_encode(["status"=>false,"message"=>"Gagal simpan ke database"]);
}

$stmt->close();
$conn->close();
