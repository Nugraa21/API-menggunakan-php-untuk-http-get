<?php
include "config.php";
randomDelay();
validateApiKey();

$id = sanitizeInput($_POST['id'] ?? '');
$status = sanitizeInput($_POST['status'] ?? '');

if (empty($id) || empty($status)) {
    echo json_encode(["status" => false, "message" => "ID atau status kosong"]);
    exit;
}

$stmt = $conn->prepare("UPDATE absensi SET status = ? WHERE id = ?");
$stmt->bind_param("si", $status, $id);

if ($stmt->execute()) {
    echo json_encode(["status" => true, "message" => "Status diperbarui"]);
} else {
    echo json_encode(["status" => false, "message" => $stmt->error]);
}
$stmt->close();
?>