<?php
/**
 * UPDATE PASSWORD - UNTUK TESTING (TIDAK ENKRIPSI)
 */

require_once "config.php";

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

ini_set('display_errors', 0);

$id = $_POST["id"] ?? '';
$password = $_POST["password"] ?? '';

if (empty($id) || empty($password)) {
    echo json_encode(["status" => "error", "message" => "ID atau password kosong"]);
    exit;
}

$hashed = password_hash($password, PASSWORD_BCRYPT);
$stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
$stmt->bind_param("si", $hashed, $id);

if ($stmt->execute()) {
    echo json_encode(["status" => "success", "message" => "Password diperbarui (Test Mode)"]);
} else {
    echo json_encode(["status" => "error", "message" => "Gagal update: " . $stmt->error]);
}

$stmt->close();
$conn->close();
?>