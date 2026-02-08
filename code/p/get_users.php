<?php
// get_users.php - VERSI ENKRIPSI (CODE/P)
// Output: JSON {"encrypted_data": "..."} berisi string enkripsi dari response asli.
// Tidak ada HTML error yang muncul.

require_once "config.php";
require_once "encryption.php";

header('Content-Type: application/json');

// Mencegah output HTML dari error PHP
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

$sql = "SELECT id, username, nama_lengkap, nip_nisn, role FROM users ORDER BY id DESC";
$run = mysqli_query($conn, $sql);

if (!$run) {
    sendEncryptedResponse(["status" => false, "message" => "DB Error"], 500);
}

$data = [];
while ($row = mysqli_fetch_assoc($run)) {
    // Logic pembersihan data (sama)
    $row['id'] = (string) $row['id'];
    if (empty($row['id']) || $row['id'] === '0')
        continue;
    $row['username'] = $row['username'] ?? '';
    $row['nama_lengkap'] = $row['nama_lengkap'] ?? '';
    $row['nip_nisn'] = $row['nip_nisn'] ?? '';
    $row['role'] = $row['role'] ?? 'user';
    $data[] = $row;
}

sendEncryptedResponse(["status" => true, "data" => $data], 200);
?>