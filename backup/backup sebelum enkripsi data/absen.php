<?php
// absen.php
// FINAL VERSION – 100% SESUAI DENGAN FLUTTER PRESENSI TERBARU (Izin tanpa lokasi, Penugasan wajib dokumen, status otomatis)

include "config.php";

// ================================
// KOORDINAT SEKOLAH & RADIUS (WAJIB SAMA DENGAN FLUTTER!)
$sekolah_lat = -7.7771639173358516;
$sekolah_lng = 110.36716347232226;
$max_distance = 200; // meter (sama persis dengan Flutter)

function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371000; // meter
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earth_radius * $c;
}

// Log input untuk debug
$raw = file_get_contents('php://input');
error_log("RAW INPUT: " . $raw);

$input = json_decode($raw, true);
if (!$input || json_last_error() !== JSON_ERROR_NONE) {
    $input = $_POST;
}

$userId     = $input['userId'] ?? $input['user_id'] ?? '';
$jenis      = $input['jenis'] ?? '';
$keterangan = trim($input['keterangan'] ?? '');
$informasi  = trim($input['informasi'] ?? '');
$dokumen64  = $input['dokumenBase64'] ?? '';
$lat        = floatval($input['latitude'] ?? 0);
$lng        = floatval($input['longitude'] ?? 0);
$selfie64   = $input['base64Image'] ?? '';

// Validasi wajib
if (empty($userId) || empty($jenis)) {
    echo json_encode(["status" => false, "message" => "User ID atau jenis presensi kosong!"]);
    exit;
}

// Validasi khusus
if (in_array($jenis, ['Izin', 'Pulang Cepat']) && empty($keterangan)) {
    echo json_encode(["status" => false, "message" => "Keterangan wajib diisi untuk $jenis!"]);
    exit;
}

if (strpos($jenis, 'Penugasan') === 0) { // starts with 'Penugasan'
    if (empty($informasi)) {
        echo json_encode(["status" => false, "message" => "Informasi penugasan wajib diisi!"]);
        exit;
    }
    if (empty($dokumen64)) {
        echo json_encode(["status" => false, "message" => "Dokumen penugasan wajib diunggah!"]);
        exit;
    }
}

// Cek jarak HANYA untuk Masuk & Pulang
if (in_array($jenis, ['Masuk', 'Pulang'])) {
    if ($lat == 0 || $lng == 0) {
        echo json_encode(["status" => false, "message" => "Lokasi tidak terdeteksi! Nyalakan GPS."]);
        exit;
    }
    $jarak = calculateDistance($sekolah_lat, $sekolah_lng, $lat, $lng);
    if ($jarak > $max_distance) {
        echo json_encode(["status" => false, "message" => "Di luar radius sekolah! Jarak: " . round($jarak, 1) . "m"]);
        exit;
    }
}

// Cek absen ganda (hanya Masuk & Pulang)
if (in_array($jenis, ['Masuk', 'Pulang'])) {
    $today = date('Y-m-d');
    $check = $conn->query("SELECT jenis FROM absensi WHERE user_id = '$userId' AND DATE(created_at) = '$today' AND jenis IN ('Masuk', 'Pulang')");
    $absen_hari_ini = [];
    while ($row = $check->fetch_assoc()) {
        $absen_hari_ini[] = $row['jenis'];
    }
    if ($jenis == 'Masuk' && in_array('Masuk', $absen_hari_ini)) {
        echo json_encode(["status" => false, "message" => "Kamu sudah absen Masuk hari ini!"]);
        exit;
    }
    if ($jenis == 'Pulang' && in_array('Pulang', $absen_hari_ini)) {
        echo json_encode(["status" => false, "message" => "Kamu sudah absen Pulang hari ini!"]);
        exit;
    }
}

// Tentukan status otomatis
$status = (in_array($jenis, ['Masuk', 'Pulang'])) ? 'Disetujui' : 'Pending';

// Upload Selfie (opsional)
$selfie_name = '';
if (!empty($selfie64)) {
    $selfie_dir = "selfie/";
    if (!is_dir($selfie_dir)) mkdir($selfie_dir, 0777, true);
    $selfie_name = "selfie_" . $userId . "_" . time() . ".jpg";
    $selfie_path = $selfie_dir . $selfie_name;
    $decoded = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $selfie64));
    if ($decoded && file_put_contents($selfie_path, $decoded)) {
        error_log("Selfie berhasil disimpan: $selfie_path");
    } else {
        $selfie_name = '';
        error_log("Gagal upload selfie");
    }
}

// Upload Dokumen (wajib untuk Penugasan)
$dokumen_name = '';
if (!empty($dokumen64)) {
    $dokumen_dir = "dokumen/";
    if (!is_dir($dokumen_dir)) mkdir($dokumen_dir, 0777, true);
    $ext = strpos($dokumen64, 'data:image') === 0 ? 'jpg' : 'pdf';
    $dokumen_name = "dokumen_" . $userId . "_" . time() . "." . $ext;
    $dokumen_path = $dokumen_dir . $dokumen_name;
    $decoded = base64_decode(preg_replace('#^data:\w+/\w+;base64,#i', '', $dokumen64));
    if ($decoded && file_put_contents($dokumen_path, $decoded)) {
        error_log("Dokumen berhasil disimpan: $dokumen_path");
    } else {
        $dokumen_name = '';
        echo json_encode(["status" => false, "message" => "Gagal upload dokumen!"]);
        exit;
    }
}

// Simpan ke database
$sql = "INSERT INTO absensi 
        (user_id, jenis, keterangan, informasi, dokumen, selfie, latitude, longitude, status, created_at) 
        VALUES 
        ('$userId', '$jenis', '$keterangan', '$informasi', '$dokumen_name', '$selfie_name', '$lat', '$lng', '$status', NOW())";

if ($conn->query($sql) === TRUE) {
    $id = $conn->insert_id;
    echo json_encode([
        "status" => true,
        "message" => "Presensi $jenis berhasil dikirim!",
        "data" => [
            "id" => $id,
            "jenis" => $jenis,
            "status" => $status,
            "jarak" => $jenis == 'Masuk' || $jenis == 'Pulang' ? round(calculateDistance($sekolah_lat, $sekolah_lng, $lat, $lng), 1) . "m" : null
        ]
    ]);
} else {
    // Hapus file jika gagal insert
    if ($selfie_name && file_exists("selfie/$selfie_name")) unlink("selfie/$selfie_name");
    if ($dokumen_name && file_exists("dokumen/$dokumen_name")) unlink("dokumen/$dokumen_name");
    
    echo json_encode(["status" => false, "message" => "Gagal simpan data: " . $conn->error]);
}

$conn->close();
?>