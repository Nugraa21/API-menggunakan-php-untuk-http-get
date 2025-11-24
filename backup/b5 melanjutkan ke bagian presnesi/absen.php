<?php
include "config.php";

$sekolah_lat = -7.795580;
$sekolah_lng = 110.369490;
$max_distance = 150; // meter

function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);

    $c = 2 * asin(sqrt($a));
    return $earth_radius * $c;
}

$user_id = $_POST['user_id'];
$jenis = $_POST['jenis'];
$keterangan = $_POST['keterangan'];
$lat = $_POST['lat'];
$lng = $_POST['lng'];

$distance = calculateDistance($sekolah_lat, $sekolah_lng, $lat, $lng);

// CEK RADIUS
if ($distance > $max_distance) {
    echo json_encode(["status" => false, "message" => "Di luar jangkauan sekolah!"]);
    exit;
}

// CEK 2x ABSEN / HARI
$date = date("Y-m-d");
$check = $conn->query("SELECT COUNT(*) AS jml FROM absensi 
                       WHERE user_id='$user_id' AND DATE(created_at)='$date'");

$row = $check->fetch_assoc();
if ($row['jml'] >= 2 && $jenis != "Izin" && $jenis != "Pulang Cepat") {
    echo json_encode(["status" => false, "message" => "Sudah absen 2 kali hari ini!"]);
    exit;
}

// UPLOAD FOTO
$target_dir = "selfie/";
if (!file_exists($target_dir)) mkdir($target_dir);

$image = $_POST['image']; 
$image_name = "selfie_" . time() . ".jpg";
file_put_contents($target_dir . $image_name, base64_decode($image));

$q = $conn->query("INSERT INTO absensi 
(user_id, jenis, keterangan, selfie, latitude, longitude) 
VALUES 
('$user_id', '$jenis', '$keterangan', '$image_name', '$lat', '$lng')");

echo json_encode(["status" => true, "message" => "Presensi berhasil! Pending menunggu admin"]);
