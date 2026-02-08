<?php
// presensi_add.php - VERSI ENKRIPSI (CODE/P)
// Wraps absen.php logic or create simple wrapper since presensi_add was legacy
// But user requested full encryption

require_once "config.php";
require_once "encryption.php";

header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

function sendEncryptedResponse($data, $httpCode = 200)
{
    global $conn;
    http_response_code($httpCode);
    $json = json_encode($data);
    $encrypted = Encryption::encrypt($json);
    echo json_encode(["encrypted_data" => $encrypted]);
    if ($conn && $conn instanceof mysqli)
        $conn->close();
    exit;
}

randomDelay();
validateApiKey();

// Mapped logic from original presensi_add.php but secure
$user_id = $_POST['user_id'] ?? '';
$status = $_POST['status'] ?? '';
$latitude = $_POST['latitude'] ?? '';
$longitude = $_POST['longitude'] ?? '';
$keterangan = $_POST['keterangan'] ?? '';

if (empty($user_id)) {
    // Try JSON
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?? [];
    $user_id = $input['user_id'] ?? '';
    $status = $input['status'] ?? '';
    $latitude = $input['latitude'] ?? '';
    $longitude = $input['longitude'] ?? '';
    $keterangan = $input['keterangan'] ?? '';
}

$school_lat = -7.791415;
$school_lng = 110.374817;

function distance($lat1, $lon1, $lat2, $lon2)
{
    if (($lat1 == $lat2) && ($lon1 == $lon2))
        return 0;
    $earthRadius = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

$jarak = distance($latitude, $longitude, $school_lat, $school_lng);

if ($jarak > 1500 && $status == 'MASUK') {
    sendEncryptedResponse(["success" => false, "message" => "Kamu berada di luar area sekolah! " . round($jarak) . "m"], 400);
}

$foto_name = "";
if (!empty($_FILES['foto']['name'])) {
    $upload_dir = "../uploads/absen/";
    if (!is_dir($upload_dir))
        mkdir($upload_dir, 0777, true);
    $foto_name = time() . "_" . $_FILES['foto']['name'];
    move_uploaded_file($_FILES['foto']['tmp_name'], $upload_dir . $foto_name);
}

$status_db = 'Disetujui';
$stmt_fix = $conn->prepare("INSERT INTO absensi (user_id, jenis, keterangan, selfie, latitude, longitude, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
$stmt_fix->bind_param("issssss", $user_id, $status, $keterangan, $foto_name, $latitude, $longitude, $status_db);

if ($stmt_fix->execute()) {
    $stmt_fix->close();
    sendEncryptedResponse(["success" => true, "message" => "Presensi berhasil (Encrypted)"], 200);
} else {
    $error = $stmt_fix->error;
    $stmt_fix->close();
    sendEncryptedResponse(["success" => false, "message" => "Gagal menyimpan: " . $error], 500);
}
?>