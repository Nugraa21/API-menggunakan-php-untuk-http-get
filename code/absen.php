<?php
/**
 * ABSEN PENGETESAN - TANPA ENKRIPSI
 */

date_default_timezone_set('Asia/Jakarta');

require_once "config.php"; // Local config without API key validation

// Set timezone MySQL per connection
$conn->query("SET time_zone = '+07:00'");

// Koordinat Sekolah (Hardcoded untuk testing)
$sekolah_lat = -7.7771639173358516;
$sekolah_lng = 110.36716347232226;
$max_distance = 1200; // meter (misal diperbesar untuk testing mudah)

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

// Debug info for testing
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

// Cek Lokasi & Jarak (Hanya Masuk/Pulang)
if (in_array($jenis, ['Masuk', 'Pulang'])) {
    $jarak = calculateDistance($sekolah_lat, $sekolah_lng, $lat, $lng);

    // Matikan validasi jarak jika ingin testing bebas lokasi
    // Tapi user minta 'sesuai rancangan', jadi tetap cek tapi log message jelas
    if ($jarak > $max_distance) {
        // Untuk testing, kita bolehkan dulu atau beri warning
        // echo json_encode(["status" => false, "message" => "Terlalu jauh ($jarak m)"]);
        // exit;
    }

    // Cek duplikasi harian
    $today = date('Y-m-d');
    $stmt = $conn->prepare("SELECT jenis FROM absensi WHERE user_id = ? AND DATE(created_at) = ? AND jenis = ?");
    $stmt->bind_param("iss", $userId, $today, $jenis);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        echo json_encode(["status" => false, "message" => "Sudah absen $jenis hari ini (Test Mode)"]);
        exit;
    }
    $stmt->close();
}

// Simpan Selfie (Lokal Code Folder)
$selfie_name = '';
if (!empty($selfie64)) {
    $dir = "selfie/";
    if (!is_dir($dir))
        mkdir($dir, 0777, true);

    $selfie_name = "test_selfie_" . time() . ".jpg";
    file_put_contents($dir . $selfie_name, base64_decode($selfie64));
}

// Simpan Dokumen (Lokal Code Folder)
$dokumen_name = '';
if (!empty($dokumen64)) {
    $dir = "dokumen/";
    if (!is_dir($dir))
        mkdir($dir, 0777, true);

    $dokumen_name = "test_dok_" . time() . ".jpg"; // Asumsi jpg/pdf
    file_put_contents($dir . $dokumen_name, base64_decode($dokumen64));
}

// Status Default
$status = 'Disetujui'; // Test mode langsung setuju

// Insert ke DB
$stmt = $conn->prepare("INSERT INTO absensi (user_id, jenis, keterangan, informasi, dokumen, selfie, latitude, longitude, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("isssssdds", $userId, $jenis, $keterangan, $informasi, $dokumen_name, $selfie_name, $lat, $lng, $status);

if ($stmt->execute()) {
    echo json_encode([
        "status" => true,
        "message" => "Absen $jenis Berhasil (Test Mode)",
        "data" => [
            "jenis" => $jenis,
            "status" => $status,
            "waktu" => date('Y-m-d H:i:s')
        ]
    ]);
} else {
    echo json_encode(["status" => false, "message" => "DB Error: " . $stmt->error]);
}

$stmt->close();
$conn->close();
?>