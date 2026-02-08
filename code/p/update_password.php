<?php
// update_password.php - VERSI ENKRIPSI (CODE/P)
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

$id = $input["id"] ?? $_POST["id"] ?? '';
$password = $input["password"] ?? $_POST["password"] ?? '';

if (empty($id) || empty($password)) {
    sendEncryptedResponse(["status" => "error", "message" => "ID atau password kosong"], 400);
}

$hashed = password_hash($password, PASSWORD_BCRYPT);
$stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
$stmt->bind_param("si", $hashed, $id);

if ($stmt->execute()) {
    sendEncryptedResponse(["status" => "success", "message" => "Password diperbarui (Encrypted)"], 200);
} else {
    $error = $stmt->error;
    $stmt->close();
    sendEncryptedResponse(["status" => "error", "message" => "Gagal update: " . $error], 500);
}
?>