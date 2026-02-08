<?php
/**
 * ABSEN APPROVE - UNTUK TESTING (TIDAK ENKRIPSI)
 */

require_once "config.php";

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Input Handling via POST
$id = $_POST['id'] ?? '';
$status = $_POST['status'] ?? ''; // Disetujui / Ditolak / etc

// Support JSON input juga
if (empty($id)) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    $id = $input['id'] ?? '';
    $status = $input['status'] ?? '';
}

if (empty($id) || empty($status)) {
    echo json_encode(["status" => false, "message" => "ID atau status kosong"]);
    exit;
}

// Update Status Absensi
$stmt = $conn->prepare("UPDATE absensi SET status = ? WHERE id = ?");
$stmt->bind_param("si", $status, $id);

if ($stmt->execute()) {
    echo json_encode(["status" => true, "message" => "Status absensi diperbarui (Test Mode)"]);
} else {
    echo json_encode(["status" => false, "message" => "Gagal: " . $stmt->error]);
}

$stmt->close();
$conn->close();
?>