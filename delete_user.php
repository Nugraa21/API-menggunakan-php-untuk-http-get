<?php
// delete_user.php - ENCRYPTED
include "config.php";
require_once "encryption.php";

header('Content-Type: application/json');

// --- DECRYPT INPUT ---
$raw = file_get_contents('php://input');
$input_json = json_decode($raw, true);
$data = [];
if (isset($input_json['encrypted_data'])) {
    $decrypted = Encryption::decrypt($input_json['encrypted_data']);
    $data = $decrypted ? json_decode($decrypted, true) : [];
} else {
    $data = array_merge($_GET, $_POST, $input_json ?? []);
}
// ---------------------

$id = $data["id"] ?? '';
$response = [];

if (empty($id)) {
    exit;
}

// Hapus langsung (tanpa cek siapa yang request)
$del = mysqli_query($conn, "DELETE FROM users WHERE id = '$id'");
if ($del) {
    echo json_encode(["status" => "success", "message" => "User berhasil dihapus"]);
} else {
    echo json_encode(["status" => "error", "message" => mysqli_error($conn)]);
}
?>