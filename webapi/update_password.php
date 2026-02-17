<?php
include "config.php";
validateApiKey();

// header('Content-Type: application/json');
ini_set('display_errors', 0);

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data)
    $data = $_POST;

$id = $data["id"] ?? '';
$password = $data["password"] ?? '';

if (empty($id) || empty($password)) {
    echo json_encode(["status" => "error", "message" => "ID atau password kosong"]);
    exit;
}

$hashed = password_hash($password, PASSWORD_DEFAULT);
$stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
$stmt->bind_param("si", $hashed, $id);

if ($stmt->execute()) {
    echo json_encode(["status" => "success", "message" => "Password diperbarui"]);
} else {
    echo json_encode(["status" => "error", "message" => "Gagal update password"]);
}
$stmt->close();
?>