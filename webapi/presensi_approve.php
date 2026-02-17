<?php
include "config.php";
randomDelay();
validateApiKey();

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    echo json_encode(["status" => false, "message" => "Data tidak diterima"]);
    exit;
}

$id = $data['id'] ?? '';
$status = $data['status'] ?? '';

if (empty($id) || empty($status)) {
    echo json_encode(["status" => false, "message" => "ID atau status kosong"]);
    exit;
}

if (!in_array($status, ['Disetujui', 'Ditolak'])) {
    echo json_encode(["status" => false, "message" => "Status tidak valid"]);
    exit;
}

// Update status
$stmt = $conn->prepare("UPDATE absensi SET status = ? WHERE id = ?");
if (!$stmt) {
    echo json_encode(["status" => false, "message" => "Prepare failed: " . $conn->error]);
    exit;
}

$stmt->bind_param("si", $status, $id);

if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        echo json_encode(["status" => true, "message" => "Status berhasil diubah menjadi '$status'"]);
    } else {
        echo json_encode(["status" => false, "message" => "ID presensi tidak ditemukan"]);
    }
} else {
    echo json_encode(["status" => false, "message" => "Gagal update: " . $stmt->error]);
}

$stmt->close();
$conn->close();
?>