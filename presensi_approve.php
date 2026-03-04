<?php
// presensi_approve.php - ENCRYPTED
include "config.php";
require_once "encryption.php";

randomDelay();
validateApiKey();

// --- DECRYPT INPUT ---
$raw = file_get_contents('php://input');
$input_json = json_decode($raw, true);

$data = [];
if (isset($input_json['encrypted_data'])) {
    $decrypted = Encryption::decrypt($input_json['encrypted_data']);
    $data = $decrypted ? json_decode($decrypted, true) : [];
} else {
    $data = $input_json ?? [];
}
// ---------------------

$response = [];

if (!$data) {
    $response = ["status" => false, "message" => "Data tidak diterima/kosong"];
} else {
    $id = $data['id'] ?? '';
    $status = $data['status'] ?? '';

    if (empty($id) || empty($status)) {
        $response = ["status" => false, "message" => "ID atau status kosong"];
    } elseif (!in_array($status, ['Disetujui', 'Ditolak'])) {
        $response = ["status" => false, "message" => "Status tidak valid"];
    } else {
        // Update status
        $stmt = $conn->prepare("UPDATE absensi SET status = ? WHERE id = ?");
        if (!$stmt) {
            $response = ["status" => false, "message" => "Prepare failed: " . $conn->error];
        } else {
            $stmt->bind_param("si", $status, $id);
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $response = ["status" => true, "message" => "Status berhasil diubah menjadi '$status'"];
                } else {
                    $response = ["status" => false, "message" => "ID presensi tidak ditemukan"];
                }
            } else {
                $response = ["status" => false, "message" => "Gagal update: " . $stmt->error];
            }
            $stmt->close();
        }
    }
}

// --- ENCRYPT OUTPUT ---
echo json_encode([
    "encrypted_data" => Encryption::encrypt(json_encode($response))
]);
$conn->close();
?>