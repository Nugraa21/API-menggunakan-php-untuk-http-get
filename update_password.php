<?php
// update_password.php - ENCRYPTED
include "config.php";
require_once "encryption.php";

header('Content-Type: application/json');
ini_set('display_errors', 0);

// --- DECRYPT INPUT ---
$raw = file_get_contents('php://input');
$input_json = json_decode($raw, true);

$data = [];
if (isset($input_json['encrypted_data'])) {
    $decrypted = Encryption::decrypt($input_json['encrypted_data']);
    $data = $decrypted ? json_decode($decrypted, true) : [];
} else {
    $data = array_merge($_POST, $input_json ?? []);
}
// ---------------------

$id = $data["id"] ?? '';
$password = $data["password"] ?? '';

$response = [];

if (empty($id) || empty($password)) {
    $response = ["status" => "error", "message" => "ID atau password kosong"];
} else {
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $update = mysqli_query($conn, "UPDATE users SET password='$hashed' WHERE id='$id'");

    if ($update) {
        $response = ["status" => "success", "message" => "Password diperbarui"];
    } else {
        $response = ["status" => "error", "message" => mysqli_error($conn)];
    }
}

// --- ENCRYPT OUTPUT ---
echo json_encode([
    "encrypted_data" => Encryption::encrypt(json_encode($response))
]);
?>