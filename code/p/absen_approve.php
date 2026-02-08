<?php
// absen_approve.php - VERSI ENKRIPSI (CODE/P)
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

$id = $input['id'] ?? $_POST['id'] ?? '';
$status = $input['status'] ?? $_POST['status'] ?? '';

if (empty($id) || empty($status)) {
    sendEncryptedResponse(["status" => false, "message" => "ID atau status kosong"], 400);
}

$stmt = $conn->prepare("UPDATE absensi SET status = ? WHERE id = ?");
$stmt->bind_param("si", $status, $id);

if ($stmt->execute()) {
    sendEncryptedResponse(["status" => true, "message" => "Status absensi diperbarui (Encrypted)"], 200);
} else {
    $error = $stmt->error;
    $stmt->close();
    sendEncryptedResponse(["status" => false, "message" => "Gagal: " . $error], 500);
}
?>