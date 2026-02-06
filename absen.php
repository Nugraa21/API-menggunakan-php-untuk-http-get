<?php
// absen.php - DIPERBAIKI: WAKTU TERCATAT BENAR DI WIB (JOGJA)

date_default_timezone_set('Asia/Jakarta'); // Untuk fungsi date() di PHP

include "config.php";
randomDelay();
validateApiKey();

// === TAMBAHAN PENTING: Paksa MySQL pakai timezone WIB (+07:00) untuk request ini ===
$conn->query("SET time_zone = '+07:00'");
// ===============================================================================

$sekolah_lat = -7.7771639173358516;
$sekolah_lng = 110.36716347232226;
$max_distance = 12000000000000; // meter

function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earth_radius * $c;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input) $input = $_POST;

$userId     = sanitizeInput($input['userId'] ?? $input['user_id'] ?? '');
$jenis      = sanitizeInput($input['jenis'] ?? '');
$keterangan = sanitizeInput($input['keterangan'] ?? '');
$informasi  = sanitizeInput($input['informasi'] ?? '');
$dokumen64  = $input['dokumenBase64'] ?? '';
$lat        = floatval($input['latitude'] ?? 0);
$lng        = floatval($input['longitude'] ?? 0);
$selfie64   = $input['base64Image'] ?? '';

// Validasi dasar
if (empty($userId) || empty($jenis)) {
    echo json_encode(["status" => false, "message" => "User ID atau jenis presensi kosong!"]);
    exit;
}

// Cek user ada
$stmt = $conn->prepare("SELECT id, role FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows == 0) {
    echo json_encode(["status" => false, "message" => "User tidak ditemukan"]);
    exit;
}
$stmt->close();

// Validasi khusus per jenis
if (in_array($jenis, ['Izin', 'Pulang Cepat']) && empty($keterangan)) {
    echo json_encode(["status" => false, "message" => "Keterangan wajib diisi untuk $jenis!"]);
    exit;
}

if (strpos($jenis, 'Penugasan') === 0) {
    if (empty($informasi)) {
        echo json_encode(["status" => false, "message" => "Informasi penugasan wajib diisi!"]);
        exit;
    }
    if (empty($dokumen64)) {
        echo json_encode(["status" => false, "message" => "Dokumen penugasan wajib diunggah!"]);
        exit;
    }
}

// Cek jarak & jam hanya untuk Masuk & Pulang
if (in_array($jenis, ['Masuk', 'Pulang'])) {
    if ($lat == 0 || $lng == 0) {
        echo json_encode(["status" => false, "message" => "Lokasi tidak terdeteksi!"]);
        exit;
    }
    $jarak = calculateDistance($sekolah_lat, $sekolah_lng, $lat, $lng);
    if ($jarak > $max_distance) {
        echo json_encode(["status" => false, "message" => "Di luar radius sekolah! Jarak: " . round($jarak, 1) . "m"]);
        exit;
    }

    // Cek absen ganda hari ini
    $today = date('Y-m-d');
    $stmt = $conn->prepare("SELECT jenis FROM absensi WHERE user_id = ? AND DATE(created_at) = ? AND jenis IN ('Masuk', 'Pulang')");
    $stmt->bind_param("is", $userId, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $absen_hari_ini = [];
    while ($row = $result->fetch_assoc()) {
        $absen_hari_ini[] = $row['jenis'];
    }
    $stmt->close();

    if ($jenis == 'Masuk' && in_array('Masuk', $absen_hari_ini)) {
        echo json_encode(["status" => false, "message" => "Kamu sudah absen Masuk hari ini!"]);
        exit;
    }
    if ($jenis == 'Pulang' && in_array('Pulang', $absen_hari_ini)) {
        echo json_encode(["status" => false, "message" => "Kamu sudah absen Pulang hari ini!"]);
        exit;
    }

    $currentHour = (int)date('H');
    $currentMinute = (int)date('i');
    $currentTimeInMinutes = $currentHour * 60 + $currentMinute;

    if ($jenis == 'Masuk') {
        if ($currentTimeInMinutes > 540) { // setelah 09:00
            echo json_encode(["status" => false, "message" => "Absen masuk ditutup setelah jam 09:00!"]);
            exit;
        }
    }

    if ($jenis == 'Pulang') {
        if ($currentTimeInMinutes < 780) { // sebelum 13:00
            echo json_encode(["status" => false, "message" => "Absen pulang baru dibuka mulai jam 13:00!"]);
            exit;
        }
    }
}

// Status otomatis disetujui untuk Masuk & Pulang
$status = in_array($jenis, ['Masuk', 'Pulang']) ? 'Disetujui' : 'Waiting';

// Upload selfie & dokumen
$selfie_name = '';
if (!empty($selfie64)) {
    $selfie_dir = "selfie/";
    if (!is_dir($selfie_dir)) mkdir($selfie_dir, 0777, true);
    $selfie_name = "selfie_" . $userId . "_" . time() . ".jpg";
    $selfie_path = $selfie_dir . $selfie_name;
    $decoded = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $selfie64));
    if ($decoded === false || file_put_contents($selfie_path, $decoded) === false) {
        echo json_encode(["status" => false, "message" => "Gagal simpan selfie"]);
        exit;
    }
}

$dokumen_name = '';
if (!empty($dokumen64)) {
    $dokumen_dir = "dokumen/";
    if (!is_dir($dokumen_dir)) mkdir($dokumen_dir, 0777, true);
    $prefix = strpos($dokumen64, 'data:image') === 0 ? 'jpg' : 'pdf';
    $dokumen_name = "dokumen_" . $userId . "_" . time() . "." . $prefix;
    $dokumen_path = $dokumen_dir . $dokumen_name;
    $decoded = base64_decode(preg_replace('#^data:\w+/\w+;base64,#i', '', $dokumen64));
    if ($decoded === false || file_put_contents($dokumen_path, $decoded) === false) {
        echo json_encode(["status" => false, "message" => "Gagal simpan dokumen"]);
        exit;
    }
}

// Insert presensi (created_at otomatis dari MySQL dengan timezone WIB)
$stmt = $conn->prepare("INSERT INTO absensi 
    (user_id, jenis, keterangan, informasi, dokumen, selfie, latitude, longitude, status) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("isssssdss", $userId, $jenis, $keterangan, $informasi, $dokumen_name, $selfie_name, $lat, $lng, $status);

if ($stmt->execute()) {
    $jarak_str = in_array($jenis, ['Masuk', 'Pulang']) ? round(calculateDistance($sekolah_lat, $sekolah_lng, $lat, $lng), 1) . "m" : null;
    echo json_encode([
        "status" => true,
        "message" => "Presensi $jenis berhasil!",
        "data" => [
            "jenis" => $jenis,
            "status" => $status,
            "jarak" => $jarak_str
        ]
    ]);
} else {
    // Hapus file jika gagal insert
    if ($selfie_name && file_exists("selfie/$selfie_name")) unlink("selfie/$selfie_name");
    if ($dokumen_name && file_exists("dokumen/$dokumen_name")) unlink("dokumen/$dokumen_name");
    echo json_encode(["status" => false, "message" => "Gagal simpan presensi: " . $stmt->error]);
}

$stmt->close();
$conn->close();
?>