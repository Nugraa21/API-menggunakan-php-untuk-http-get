<?php
// absen.php - FIX LINUX + APACHE + PROXMOX
date_default_timezone_set('Asia/Jakarta');

// BASE PATH (PENTING DI LINUX)
$BASE_PATH = __DIR__ . "/";

include "config.php";
randomDelay();
validateApiKey();

$sekolah_lat = -7.7771639173358516;
$sekolah_lng = 110.36716347232226;
$max_distance = 120; // meter

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

// ===================== INPUT =====================
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input) $input = $_POST;

$userId     = sanitizeInput($input['userId'] ?? $input['user_id'] ?? '');
$jenis      = sanitizeInput($input['jenis'] ?? '');
$keterangan = sanitizeInput($input['keterangan'] ?? '');
$informasi  = sanitizeInput($input['informasi'] ?? '');
$dokumen64  = $input['dokumenBase64'] ?? '';
$selfie64   = $input['base64Image'] ?? '';
$lat        = floatval($input['latitude'] ?? 0);
$lng        = floatval($input['longitude'] ?? 0);

// ===================== VALIDASI =====================
if (empty($userId) || empty($jenis)) {
    echo json_encode(["status"=>false,"message"=>"User ID atau jenis presensi kosong"]);
    exit;
}

// Cek user
$stmt = $conn->prepare("SELECT id FROM users WHERE id=?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows == 0) {
    echo json_encode(["status"=>false,"message"=>"User tidak ditemukan"]);
    exit;
}
$stmt->close();

// Validasi jenis tertentu
if (in_array($jenis, ['Izin','Pulang Cepat']) && empty($keterangan)) {
    echo json_encode(["status"=>false,"message"=>"Keterangan wajib diisi"]);
    exit;
}

if (strpos($jenis, 'Penugasan') === 0) {
    if (empty($informasi) || empty($dokumen64)) {
        echo json_encode(["status"=>false,"message"=>"Informasi & dokumen penugasan wajib"]);
        exit;
    }
}

// ===================== LOKASI & JAM =====================
if (in_array($jenis, ['Masuk','Pulang'])) {
    if ($lat == 0 || $lng == 0) {
        echo json_encode(["status"=>false,"message"=>"Lokasi tidak valid"]);
        exit;
    }

    $jarak = calculateDistance($sekolah_lat, $sekolah_lng, $lat, $lng);
    if ($jarak > $max_distance) {
        echo json_encode(["status"=>false,"message"=>"Di luar radius sekolah"]);
        exit;
    }

    $today = date('Y-m-d');
    $stmt = $conn->prepare("
        SELECT jenis FROM absensi 
        WHERE user_id=? AND DATE(created_at)=? AND jenis IN ('Masuk','Pulang')
    ");
    $stmt->bind_param("is", $userId, $today);
    $stmt->execute();
    $res = $stmt->get_result();
    $done = [];
    while ($r = $res->fetch_assoc()) $done[] = $r['jenis'];
    $stmt->close();

    if ($jenis == 'Masuk' && in_array('Masuk', $done)) {
        echo json_encode(["status"=>false,"message"=>"Sudah absen masuk"]);
        exit;
    }
    if ($jenis == 'Pulang' && in_array('Pulang', $done)) {
        echo json_encode(["status"=>false,"message"=>"Sudah absen pulang"]);
        exit;
    }

    $nowMin = (int)date('H') * 60 + (int)date('i');
    if ($jenis == 'Masuk' && $nowMin > 540) {
        echo json_encode(["status"=>false,"message"=>"Absen masuk ditutup jam 09:00"]);
        exit;
    }
    if ($jenis == 'Pulang' && $nowMin < 780) {
        echo json_encode(["status"=>false,"message"=>"Absen pulang mulai jam 13:00"]);
        exit;
    }
}

$status = in_array($jenis, ['Masuk','Pulang']) ? 'Disetujui' : 'Waiting';

// ===================== UPLOAD FILE =====================
$selfie_name = '';
$dokumen_name = '';

// ---- SELFIE ----
if (!empty($selfie64)) {
    $selfie_dir = $BASE_PATH . "selfie/";

    if (!is_dir($selfie_dir)) mkdir($selfie_dir, 0755, true);
    if (!is_writable($selfie_dir)) {
        echo json_encode(["status"=>false,"message"=>"Folder selfie tidak writable"]);
        exit;
    }

    $selfie_name = "selfie_{$userId}_" . time() . ".jpg";
    $selfie_path = $selfie_dir . $selfie_name;

    $decoded = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $selfie64));
    if (!$decoded || file_put_contents($selfie_path, $decoded) === false) {
        echo json_encode(["status"=>false,"message"=>"Gagal simpan selfie"]);
        exit;
    }
}

// ---- DOKUMEN ----
if (!empty($dokumen64)) {
    $dokumen_dir = $BASE_PATH . "dokumen/";

    if (!is_dir($dokumen_dir)) mkdir($dokumen_dir, 0755, true);
    if (!is_writable($dokumen_dir)) {
        echo json_encode(["status"=>false,"message"=>"Folder dokumen tidak writable"]);
        exit;
    }

    $ext = strpos($dokumen64, 'data:image') === 0 ? 'jpg' : 'pdf';
    $dokumen_name = "dokumen_{$userId}_" . time() . "." . $ext;
    $dokumen_path = $dokumen_dir . $dokumen_name;

    $decoded = base64_decode(preg_replace('#^data:\w+/\w+;base64,#i', '', $dokumen64));
    if (!$decoded || file_put_contents($dokumen_path, $decoded) === false) {
        echo json_encode(["status"=>false,"message"=>"Gagal simpan dokumen"]);
        exit;
    }
}

// ===================== INSERT DB =====================
$stmt = $conn->prepare("
INSERT INTO absensi 
(user_id, jenis, keterangan, informasi, dokumen, selfie, latitude, longitude, status)
VALUES (?,?,?,?,?,?,?,?,?)
");
$stmt->bind_param(
    "isssssdss",
    $userId, $jenis, $keterangan, $informasi,
    $dokumen_name, $selfie_name,
    $lat, $lng, $status
);

if ($stmt->execute()) {
    echo json_encode([
        "status"=>true,
        "message"=>"Presensi $jenis berhasil",
        "data"=>[
            "jenis"=>$jenis,
            "status"=>$status
        ]
    ]);
} else {
    if ($selfie_name) @unlink($BASE_PATH."selfie/".$selfie_name);
    if ($dokumen_name) @unlink($BASE_PATH."dokumen/".$dokumen_name);
    echo json_encode(["status"=>false,"message"=>$stmt->error]);
}

$stmt->close();
$conn->close();
?>
