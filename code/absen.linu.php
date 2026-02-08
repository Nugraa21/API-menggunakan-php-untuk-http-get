<?php
/**
 * ABSEN (LINUX VERSION) - UNTUK TESTING (TIDAK ENKRIPSI)
 */

date_default_timezone_set('Asia/Jakarta');

// BASE PATH (PENTING DI LINUX)
$BASE_PATH = __DIR__ . "/";

require_once "config.php";

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Koordinat Sekolah (Hardcoded untuk testing)
$sekolah_lat = -7.7771639173358516;
$sekolah_lng = 110.36716347232226;
$max_distance = 2500; // meter (diperbesar lagi)

function calculateDistance($lat1, $lon1, $lat2, $lon2)
{
    if (($lat1 == $lat2) && ($lon1 == $lon2))
        return 0;

    $theta = $lon1 - $lon2;
    $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
    $dist = acos($dist);
    $dist = rad2deg($dist);
    $miles = $dist * 60 * 1.1515;
    $unit = "K";

    return ($miles * 1.609344 * 1000); // meter
}

// Input Handling
$raw = file_get_contents('php://input');
$input = json_decode($raw, true) ?? $_POST;

$userId = $input['userId'] ?? $input['user_id'] ?? '';
$jenis = $input['jenis'] ?? '';
$keterangan = $input['keterangan'] ?? '';
$informasi = $input['informasi'] ?? '';
$dokumen64 = $input['dokumenBase64'] ?? '';
$lat = floatval($input['latitude'] ?? 0);
$lng = floatval($input['longitude'] ?? 0);
$selfie64 = $input['base64Image'] ?? '';

// Debug info
if (empty($userId) || empty($jenis)) {
    echo json_encode(["status" => false, "message" => "Test Error: userId atau jenis kosong"]);
    exit;
}

// Cek User
$stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows == 0) {
    echo json_encode(["status" => false, "message" => "Test Error: User ID tidak ditemukan di DB"]);
    exit;
}
$stmt->close();

if (in_array($jenis, ['Masuk', 'Pulang'])) {
    if ($lat == 0 || $lng == 0) {
        echo json_encode(["status" => false, "message" => "Lokasi 0,0 (Invalid)"]);
        exit;
    }

    $jarak = calculateDistance($sekolah_lat, $sekolah_lng, $lat, $lng);

    // Matikan validasi jarak ketat untuk testing
    // if ($jarak > $max_distance) { ... }
}

// Simpan Selfie
$selfie_name = '';
if (!empty($selfie64)) {
    $dir = $BASE_PATH . "selfie/";
    if (!is_dir($dir))
        mkdir($dir, 0777, true);

    $selfie_name = "linux_selfie_" . time() . ".jpg";
    $decoded = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $selfie64));
    file_put_contents($dir . $selfie_name, $decoded);
}

// Simpan Dokumen
$dokumen_name = '';
if (!empty($dokumen64)) {
    $dir = $BASE_PATH . "dokumen/";
    if (!is_dir($dir))
        mkdir($dir, 0777, true);

    $ext = strpos($dokumen64, 'data:image') === 0 ? 'jpg' : 'pdf';
    $dokumen_name = "linux_dok_" . time() . "." . $ext;
    $decoded = base64_decode(preg_replace('#^data:\w+/\w+;base64,#i', '', $dokumen64));
    file_put_contents($dir . $dokumen_name, $decoded);
}

$status = 'Disetujui'; // Auto approve

// Insert DB
$stmt = $conn->prepare("INSERT INTO absensi (user_id, jenis, keterangan, informasi, dokumen, selfie, latitude, longitude, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("isssssdds", $userId, $jenis, $keterangan, $informasi, $dokumen_name, $selfie_name, $lat, $lng, $status);

if ($stmt->execute()) {
    echo json_encode([
        "status" => true,
        "message" => "Absen $jenis Berhasil (Linux Version Test)",
        "data" => [
            "jenis" => $jenis,
            "status" => $status
        ]
    ]);
} else {
    echo json_encode(["status" => false, "message" => "DB Error: " . $stmt->error]);
}

$stmt->close();
$conn->close();
?>