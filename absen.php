<?php
// absen.php - SECURE ENCRYPTED VERSION

date_default_timezone_set('Asia/Jakarta');

include "config.php";
require_once "encryption.php";

randomDelay();
validateApiKey();

// Force Timezone SQL
$conn->query("SET time_zone = '+07:00'");

// --- DECRYPT INPUT ---
$raw = file_get_contents('php://input');
$input_json = json_decode($raw, true);

$input = [];
if (isset($input_json['encrypted_data'])) {
    $decrypted = Encryption::decrypt($input_json['encrypted_data']);
    if ($decrypted === false) {
        $res = ["status" => false, "message" => "Gagal dekripsi data absensi"];
        echo json_encode(["encrypted_data" => Encryption::encrypt(json_encode($res))]);
        exit;
    }
    $input = json_decode($decrypted, true);
} else {
    $input = $input_json ?? $_POST;
}
// ---------------------

$sekolah_lat = -7.7771639173358516;
$sekolah_lng = 110.36716347232226;
$max_distance = 12000000000000; // Radius lebar dulu buat testing

function calculateDistance($lat1, $lon1, $lat2, $lon2)
{
    $earth_radius = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
        cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
        sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earth_radius * $c;
}

$userId = sanitizeInput($input['userId'] ?? $input['user_id'] ?? '');
$jenis = sanitizeInput($input['jenis'] ?? '');
$keterangan = sanitizeInput($input['keterangan'] ?? '');
$informasi = sanitizeInput($input['informasi'] ?? '');
$dokumen64 = $input['dokumenBase64'] ?? '';
$lat = floatval($input['latitude'] ?? 0);
$lng = floatval($input['longitude'] ?? 0);
$selfie64 = $input['base64Image'] ?? '';

$response = [];

// -- LOGIC HELPER --
function sendResponse($data)
{
    global $conn;
    echo json_encode(["encrypted_data" => Encryption::encrypt(json_encode($data))]);
    if ($conn)
        $conn->close();
    exit;
}

// Validasi dasar
if (empty($userId) || empty($jenis)) {
    sendResponse(["status" => false, "message" => "User ID atau jenis presensi kosong!"]);
}

// Cek user ada
$stmt = $conn->prepare("SELECT id, role FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows == 0) {
    $stmt->close();
    sendResponse(["status" => false, "message" => "User tidak ditemukan"]);
}
$stmt->close();

// Validasi khusus per jenis
if (in_array($jenis, ['Izin', 'Pulang Cepat']) && empty($keterangan)) {
    sendResponse(["status" => false, "message" => "Keterangan wajib diisi untuk $jenis!"]);
}

if (strpos($jenis, 'Penugasan') === 0) {
    if (empty($informasi)) {
        sendResponse(["status" => false, "message" => "Informasi penugasan wajib diisi!"]);
    }
    if (empty($dokumen64)) {
        sendResponse(["status" => false, "message" => "Dokumen penugasan wajib diunggah!"]);
    }
}

// Cek jarak & jam hanya untuk Masuk & Pulang
if (in_array($jenis, ['Masuk', 'Pulang'])) {
    if ($lat == 0 || $lng == 0) {
        sendResponse(["status" => false, "message" => "Lokasi tidak terdeteksi!"]);
    }
    $jarak = calculateDistance($sekolah_lat, $sekolah_lng, $lat, $lng);
    if ($jarak > $max_distance) {
        sendResponse(["status" => false, "message" => "Di luar radius sekolah! Jarak: " . round($jarak, 1) . "m"]);
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
        sendResponse(["status" => false, "message" => "Kamu sudah absen Masuk hari ini!"]);
    }
    if ($jenis == 'Pulang' && in_array('Pulang', $absen_hari_ini)) {
        sendResponse(["status" => false, "message" => "Kamu sudah absen Pulang hari ini!"]);
    }
}

// Status otomatis disetujui untuk Masuk & Pulang
$status = in_array($jenis, ['Masuk', 'Pulang']) ? 'Disetujui' : 'Waiting';

// Upload selfie & dokumen
$selfie_name = '';
if (!empty($selfie64)) {
    $selfie_dir = "selfie/";
    if (!is_dir($selfie_dir))
        mkdir($selfie_dir, 0777, true);
    $selfie_name = "selfie_" . $userId . "_" . time() . ".jpg";
    $selfie_path = $selfie_dir . $selfie_name;
    $decoded = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $selfie64));
    if ($decoded === false || file_put_contents($selfie_path, $decoded) === false) {
        sendResponse(["status" => false, "message" => "Gagal simpan selfie"]);
    }
}

$dokumen_name = '';
if (!empty($dokumen64)) {
    $dokumen_dir = "dokumen/";
    if (!is_dir($dokumen_dir))
        mkdir($dokumen_dir, 0777, true);
    $prefix = strpos($dokumen64, 'data:image') === 0 ? 'jpg' : 'pdf';
    $dokumen_name = "dokumen_" . $userId . "_" . time() . "." . $prefix;
    $dokumen_path = $dokumen_dir . $dokumen_name;
    $decoded = base64_decode(preg_replace('#^data:\w+/\w+;base64,#i', '', $dokumen64));
    if ($decoded === false || file_put_contents($dokumen_path, $decoded) === false) {
        sendResponse(["status" => false, "message" => "Gagal simpan dokumen"]);
    }
}

// Insert presensi
$stmt = $conn->prepare("INSERT INTO absensi 
    (user_id, jenis, keterangan, informasi, dokumen, selfie, latitude, longitude, status) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("isssssdss", $userId, $jenis, $keterangan, $informasi, $dokumen_name, $selfie_name, $lat, $lng, $status);

if ($stmt->execute()) {
    $jarak_str = in_array($jenis, ['Masuk', 'Pulang']) ? round(calculateDistance($sekolah_lat, $sekolah_lng, $lat, $lng), 1) . "m" : null;
    sendResponse([
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
    if ($selfie_name && file_exists("selfie/$selfie_name"))
        unlink("selfie/$selfie_name");
    if ($dokumen_name && file_exists("dokumen/$dokumen_name"))
        unlink("dokumen/$dokumen_name");
    sendResponse(["status" => false, "message" => "Gagal simpan presensi: " . $stmt->error]);
}

$stmt->close();
?>