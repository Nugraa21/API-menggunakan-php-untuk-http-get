<?php
// absen.php - VERSI ENKRIPSI (CODE/P)
// Output: JSON {"encrypted_data": "..."} berisi string enkripsi dari response asli.
// Tidak ada HTML error yang muncul.

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
    if ($conn)
        $conn->close();
    exit;
}

randomDelay();
validateApiKey();

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

if (empty($userId) || empty($jenis)) {
    sendEncryptedResponse(["status" => false, "message" => "Invalid Input"], 400);
}

// Cek User
$stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows == 0) {
    $stmt->close();
    sendEncryptedResponse(["status" => false, "message" => "User not found"], 404);
}
$stmt->close();

$selfie_name = '';
if (!empty($selfie64)) {
    // Save to ../selfie/ so it shares with root
    $dir = "../selfie/";
    if (!is_dir($dir))
        mkdir($dir, 0777, true);

    $selfie_name = "enc_selfie_" . time() . ".jpg";
    file_put_contents($dir . $selfie_name, base64_decode($selfie64));
}

$dokumen_name = '';
if (!empty($dokumen64)) {
    $dir = "../dokumen/";
    if (!is_dir($dir))
        mkdir($dir, 0777, true);

    $dokumen_name = "enc_dok_" . time() . ".jpg";
    file_put_contents($dir . $dokumen_name, base64_decode($dokumen64));
}

$status = 'Disetujui'; // Auto-approve for this version

$stmt = $conn->prepare("INSERT INTO absensi (user_id, jenis, keterangan, informasi, dokumen, selfie, latitude, longitude, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
$stmt->bind_param("isssssdds", $userId, $jenis, $keterangan, $informasi, $dokumen_name, $selfie_name, $lat, $lng, $status);

if ($stmt->execute()) {
    $data = [
        "jenis" => $jenis,
        "status" => $status,
        "waktu" => date('Y-m-d H:i:s')
    ];
    sendEncryptedResponse(["status" => true, "message" => "Absen Berhasil (Encrypted)", "data" => $data], 200);
} else {
    $error = $stmt->error;
    $stmt->close();
    sendEncryptedResponse(["status" => false, "message" => "DB Error: " . $error], 500);
}
?>