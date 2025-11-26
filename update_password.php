<?php
include "config.php";

header('Content-Type: application/json');
ini_set('display_errors', 0);

$id = $_POST["id"] ?? '';
$password = $_POST["password"] ?? '';

if (empty($id) || empty($password)) {
    echo json_encode(["status" => "error", "message" => "ID atau password kosong"]);
    exit;
}

$hashed = password_hash($password, PASSWORD_DEFAULT);
$update = mysqli_query($conn, "UPDATE users SET password='$hashed' WHERE id='$id'");

if ($update) {
    echo json_encode(["status" => "success", "message" => "Password diperbarui"]);
} else {
    echo json_encode(["status" => "error", "message" => mysqli_error($conn)]);
}
?>