<?php
/**
 * PRESENSI APPROVE - UNTUK TESTING (TIDAK ENKRIPSI)
 */

require_once "config.php";

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Input Handling
$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?? $_POST;

$id = $data['id'] ?? '';
$status = $data['status'] ?? '';

if (empty($id) || empty($status)) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "ID atau status kosong"]);
    exit;
}

if (!in_array($status, ['Disetujui', 'Ditolak'])) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "Status tidak valid. Harus 'Disetujui' atau 'Ditolak'"]);
    exit;
}

// Update DB
$stmt = $conn->prepare("UPDATE absensi SET status = ? WHERE id = ?");
$stmt->bind_param("si", $status, $id);

if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        echo json_encode(["status" => true, "message" => "Presensi telah $status (Test Mode)"]);
    } else {
        echo json_encode(["status" => false, "message" => "ID tidak ditemukan atau status sudah sama"]);
    }
} else {
    echo json_encode(["status" => false, "message" => "DB Error: " . $stmt->error]);
}

$stmt->close();
$conn->close();
?>