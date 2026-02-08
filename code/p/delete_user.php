<?php
// delete_user.php - VERSI ENKRIPSI (CODE/P)
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

if (empty($id)) {
    sendEncryptedResponse(["status" => "error", "message" => "ID kosong"], 400);
}

$stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    $stmt->close();
    sendEncryptedResponse(["status" => "error", "message" => "User tidak ditemukan"], 404);
}

$user = $result->fetch_assoc();
$stmt->close();

if ($user["role"] == "superadmin") {
    sendEncryptedResponse(["status" => "error", "message" => "Tidak boleh menghapus akun superadmin"], 403);
}

$stmt_del = $conn->prepare("DELETE FROM users WHERE id = ?");
$stmt_del->bind_param("i", $id);

if ($stmt_del->execute()) {
    $stmt_del->close();
    sendEncryptedResponse(["status" => "success", "message" => "User berhasil dihapus (Encrypted)"], 200);
} else {
    $error = $stmt_del->error;
    $stmt_del->close();
    sendEncryptedResponse(["status" => "error", "message" => "Gagal hapus: " . $error], 500);
}
?>