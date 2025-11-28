<?php
// <!-- absen.php -->
include "config.php";

// ================================
// KOORDINAT SEKOLAH (SINKRON SAMA FLUTTER)
$sekolah_lat = -7.777047019078815;
$sekolah_lng = 110.3671540164373;
$max_distance = 100; // Meter, sinkron sama Flutter

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

// DEBUG: Log raw input (JSON atau POST)
$rawInput = file_get_contents('php://input');
error_log("DEBUG: Raw input length: " . strlen($rawInput));
error_log("DEBUG: Raw input preview: " . substr($rawInput, 0, 500));

// Fallback: Coba JSON dulu, kalau gagal pake $_POST
$input = json_decode($rawInput, true);
if (!$input || json_last_error() !== JSON_ERROR_NONE) {
    $input = $_POST;
}
error_log("DEBUG: Input data: " . json_encode($input));

// Ambil data (support kedua key: lama & baru)
$user_id = $input['userId'] ?? $input['user_id'] ?? '';
$jenis = $input['jenis'] ?? '';
$keterangan = trim($input['keterangan'] ?? '');
$lat = floatval($input['latitude'] ?? $input['lat'] ?? 0);
$lng = floatval($input['longitude'] ?? $input['lng'] ?? 0);
$base64Image = $input['base64Image'] ?? $input['image'] ?? '';

// DEBUG: Log extracted
error_log("DEBUG: user_id=$user_id, jenis=$jenis, lat=$lat, lng=$lng, image_length=" . strlen($base64Image));

// VALIDASI: Cek wajib (skip image buat test)
if (empty($user_id) || empty($jenis)) {
    echo json_encode(["status" => false, "message" => "Data tidak lengkap! userId/jenis kosong."]);
    exit;
}

// Validasi keterangan hanya untuk Izin & Pulang Cepat
if (($jenis == 'Izin' || $jenis == 'Pulang Cepat') && empty($keterangan)) {
    echo json_encode(["status" => false, "message" => "Keterangan wajib diisi untuk $jenis!"]);
    exit;
}

$distance = calculateDistance($sekolah_lat, $sekolah_lng, $lat, $lng);
error_log("DEBUG: Distance: " . round($distance) . "m");

// CEK RADIUS (sinkron sama Flutter)
if ($distance > $max_distance || $distance == 0) {
    echo json_encode(["status" => false, "message" => "Di luar jangkauan sekolah! Jarak: " . round($distance) . "m"]);
    exit;
}

// CEK 2x ABSEN / HARI (hanya Masuk/Pulang)
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
            
// UPLOAD FOTO (skip kalau kosong buat test, pake dummy)
$target_dir = "selfie/";
if (!file_exists($target_dir)) {
    mkdir($target_dir, 0777, true);
}

$image_name = "selfie_" . $user_id . "_" . time() . ".jpg";
$image_path = $target_dir . $image_name;

if (!empty($base64Image)) {
    $decoded = base64_decode($base64Image);
    if ($decoded && file_put_contents($image_path, $decoded)) {
        error_log("DEBUG: Foto uploaded OK");
    } else {
        unlink($image_path); // Hapus kalau gagal
        $image_name = ''; // Skip foto kalau gagal
    }
} else {  
    // Buat test: Dummy empty   
    file_put_contents($image_path, ""); // File kosong
    error_log("DEBUG: No image, dummy created");
}

// INSERT DATA (escape basic, ganti prepared kalau bisa)
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
    if ($image_name) unlink($image_path);
    echo json_encode(["status" => false, "message" => "Gagal simpan data: " . $conn->error]);
}
?>