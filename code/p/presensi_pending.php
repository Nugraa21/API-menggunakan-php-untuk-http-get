<?php
// presensi_pending.php - VERSI ENKRIPSI (CODE/P)
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

$sql = "SELECT p.*, u.nama_lengkap 
        FROM absensi p 
        JOIN users u ON p.user_id = u.id 
        WHERE p.status = 'Waiting' 
        ORDER BY p.id DESC";

$result = $conn->query($sql);

if (!$result) {
    sendEncryptedResponse(["status" => false, "message" => "SQL Error"], 500);
}

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}
$conn->close();

sendEncryptedResponse(["status" => true, "data" => $data], 200);
?>