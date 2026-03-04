<?php
// absen_admin_list.php - ENCRYPTED
error_reporting(0);
ini_set('display_errors', 0);

include "config.php";
include "encryption.php";

randomDelay();
validateApiKey();

header('Content-Type: application/json');

// --- DECRYPT INPUT ---
$raw = file_get_contents('php://input');
$input_json = json_decode($raw, true);

$input = [];
if (isset($input_json['encrypted_data'])) {
    $decrypted = Encryption::decrypt($input_json['encrypted_data']);
    $input = $decrypted ? json_decode($decrypted, true) : [];
} else {
    $input = $input_json ?? [];
}
// ---------------------

$method = $_SERVER['REQUEST_METHOD'];

// Handle POST specific actions (Delete)
if ($method === 'POST' && isset($input['action']) && $input['action'] === 'delete' && !empty($input['id'])) {
    $id = mysqli_real_escape_string($conn, trim($input['id']));

    // Cek absensi ada
    $check = mysqli_query($conn, "SELECT id, selfie, dokumen FROM absensi WHERE id = '$id'");
    if (mysqli_num_rows($check) == 0) {
        http_response_code(404);
        echo json_encode(["encrypted_data" => Encryption::encrypt(json_encode(["status" => false, "message" => "Absensi tidak ditemukan"]))]);
        exit;
    }

    $row = mysqli_fetch_assoc($check);

    // Hapus file selfie
    if (!empty($row['selfie'])) {
        $selfiePath = "../selfie/" . basename($row['selfie']);
        if (file_exists($selfiePath))
            @unlink($selfiePath);
    }

    // Hapus file dokumen
    if (!empty($row['dokumen'])) {
        $dokumenPath = "../dokumen/" . basename($row['dokumen']);
        if (file_exists($dokumenPath))
            @unlink($dokumenPath);
    }

    // Hapus dari database
    $delete = mysqli_query($conn, "DELETE FROM absensi WHERE id = '$id'");

    if ($delete) {
        echo json_encode(["encrypted_data" => Encryption::encrypt(json_encode(["status" => true, "message" => "Absensi berhasil dihapus"]))]);
    } else {
        http_response_code(500);
        echo json_encode(["encrypted_data" => Encryption::encrypt(json_encode(["status" => false, "message" => "Gagal menghapus absensi"]))]);
    }
    exit;
}

// KALAU GET / POST tanpa action delete → TAMPILKAN SEMUA DATA ABSENSI (default)
$q = $conn->query("
    SELECT 
        a.id,
        a.user_id,
        a.jenis,
        a.keterangan,
        a.informasi,
        a.selfie,
        a.dokumen,
        a.latitude,
        a.longitude,
        a.status,
        a.created_at,
        u.nama_lengkap
    FROM absensi AS a
    JOIN users AS u ON u.id = a.user_id
    ORDER BY a.created_at DESC, a.id DESC
");

if (!$q) {
    http_response_code(500);
    echo json_encode(["encrypted_data" => Encryption::encrypt(json_encode(["status" => false, "message" => "Query gagal"]))]);
    exit;
}

$data = [];
while ($r = $q->fetch_assoc()) {
    $data[] = $r;
}

$response = [
    "status" => true,
    "data" => $data
];

$json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$encrypted = Encryption::encrypt($json);

echo json_encode(["encrypted_data" => $encrypted]);
?>