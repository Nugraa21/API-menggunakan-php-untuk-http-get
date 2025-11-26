<?php
include "config.php";

$sekolah_lat = -7.7770775; // Center dari Flutter
$sekolah_lng = 110.3670864; // Center dari Flutter
$max_distance = 400; // Radius ~400m untuk cover seluruh bounding box

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

// DEBUG: Log semua POST data (hapus nanti kalau udah fix)
error_log("DEBUG: Full POST data: " . json_encode($_POST));

// Ambil data dengan key sesuai Flutter (camelCase untuk userId)
$user_id = $_POST['userId'] ?? '';
$jenis = $_POST['jenis'] ?? '';
$keterangan = trim($_POST['keterangan'] ?? ''); 
$lat = floatval($_POST['latitude'] ?? 0); // Convert ke float
$lng = floatval($_POST['longitude'] ?? 0); // Convert ke float
$base64Image = $_POST['base64Image'] ?? '';

// VALIDASI: Cek data wajib
if (empty($user_id) || empty($jenis) || empty($base64Image)) {
    echo json_encode(["status" => false, "message" => "Data tidak lengkap! Cek koneksi atau form."]);
    exit;
}

// DIPERBAIKI: Validasi keterangan wajib hanya untuk Izin & Pulang Cepat
if (($jenis == 'Izin' || $jenis == 'Pulang Cepat') && empty($keterangan)) {
    echo json_encode(["status" => false, "message" => "Keterangan wajib diisi untuk $jenis!"]);
    exit;
}

$distance = calculateDistance($sekolah_lat, $sekolah_lng, $lat, $lng);

// DEBUG: Log jarak
error_log("DEBUG: User lat=$lat, lng=$lng, distance=" . round($distance) . " meter, jenis=$jenis, keterangan=$keterangan");

// CEK RADIUS
if ($distance > $max_distance || $distance == 0) {
    echo json_encode(["status" => false, "message" => "Di luar jangkauan sekolah! Jarak: " . round($distance) . "m"]);
    exit;
}

// CEK 2x ABSEN / HARI (hanya untuk Masuk/Pulang)
$date = date("Y-m-d");
if ($jenis == 'Masuk' || $jenis == 'Pulang') {
    $check = $conn->query("SELECT COUNT(*) AS jml FROM absensi 
                           WHERE user_id='$user_id' AND DATE(created_at)='$date' 
                           AND jenis IN ('Masuk', 'Pulang')");

    $row = $check->fetch_assoc();
    if ($row['jml'] >= 2) {
        echo json_encode(["status" => false, "message" => "Sudah absen Masuk & Pulang hari ini!"]);
        exit;
    }
}

// UPLOAD FOTO
$target_dir = "selfie/";
if (!file_exists($target_dir)) {
    mkdir($target_dir, 0777, true);
}

$image_name = "selfie_" . $user_id . "_" . time() . ".jpg";
$image_path = $target_dir . $image_name;
if (!file_put_contents($image_path, base64_decode($base64Image))) {
    echo json_encode(["status" => false, "message" => "Gagal upload foto!"]);
    exit;
}

// INSERT DATA
$q = $conn->query("INSERT INTO absensi 
(user_id, jenis, keterangan, selfie, latitude, longitude, created_at) 
VALUES 
('$user_id', '$jenis', '$keterangan', '$image_name', '$lat', '$lng', NOW())");

if ($q) {
    $absen_id = $conn->insert_id;
    echo json_encode([
        "status" => true, 
        "message" => "Presensi $jenis berhasil! ID: $absen_id", 
        "data" => ["id" => $absen_id, "jenis" => $jenis, "timestamp" => date('Y-m-d H:i:s')]
    ]);
} else {
    unlink($image_path);
    echo json_encode(["status" => false, "message" => "Gagal simpan data: " . $conn->error]);
}
?>