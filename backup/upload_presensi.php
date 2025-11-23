<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => false, "message" => "Method Not Allowed"]);
    exit();
}

require "config.php";

if (!isset($_FILES['image']) || !isset($_POST['latitude']) || !isset($_POST['longitude'])) {
    echo json_encode([
        "status" => false,
        "message" => "Parameter kurang"
    ]);
    exit();
}

$lat = $_POST['latitude'];
$lng = $_POST['longitude'];

$filename = "IMG_" . time() . "_" . rand(1000, 9999) . ".jpg";
$targetPath = "upload/" . $filename;

if (!move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
    echo json_encode([
        "status" => false,
        "message" => "Gagal upload file"
    ]);
    exit();
}

$image_url = "https://" . $_SERVER['HTTP_HOST'] . "/upload/" . $filename;

$query = "INSERT INTO presensi (image_url, latitude, longitude) VALUES ('$image_url', '$lat', '$lng')";
$insert = mysqli_query($conn, $query);

if ($insert) {
    echo json_encode([
        "status" => true,
        "message" => "Upload sukses",
        "image_url" => $image_url,
        "latitude" => $lat,
        "longitude" => $lng
    ]);
} else {
    echo json_encode([
        "status" => false,
        "message" => "Gagal simpan database"
    ]);
}
?>
