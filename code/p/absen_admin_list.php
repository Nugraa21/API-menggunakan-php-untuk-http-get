<?php
// absen_admin_list.php - VERSI ENKRIPSI (CODE/P)
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
$method = $_SERVER['REQUEST_METHOD'];

// DELETE ACTION
if ($method === 'POST' && isset($input['action']) && $input['action'] === 'delete' && !empty($input['id'])) {
    $id = $conn->real_escape_string(trim($input['id']));

    $check = $conn->query("SELECT id, selfie, dokumen FROM absensi WHERE id = '$id'");
    if ($check->num_rows == 0) {
        sendEncryptedResponse(["status" => false, "message" => "Absensi tidak ditemukan"], 404);
    }

    $row = $check->fetch_assoc();

    // Hapus file (path relatif ke ../ so shared with root)
    if (!empty($row['selfie'])) {
        $selfiePath = "../selfie/" . basename($row['selfie']);
        if (file_exists($selfiePath))
            @unlink($selfiePath);
    }

    if (!empty($row['dokumen'])) {
        $dokumenPath = "../dokumen/" . basename($row['dokumen']);
        if (file_exists($dokumenPath))
            @unlink($dokumenPath);
    }

    $delete = $conn->query("DELETE FROM absensi WHERE id = '$id'");
    if ($delete) {
        sendEncryptedResponse(["status" => true, "message" => "Absensi berhasil dihapus (Encrypted)"], 200);
    } else {
        sendEncryptedResponse(["status" => false, "message" => "Gagal: " . $conn->error], 500);
    }
}

// LIST ACTION
$sql = "
    SELECT 
        a.id, a.user_id, a.jenis, a.keterangan, a.informasi,
        a.selfie, a.dokumen, a.latitude, a.longitude,
        a.status, a.created_at,
        u.nama_lengkap
    FROM absensi AS a
    JOIN users AS u ON u.id = a.user_id
    ORDER BY a.created_at DESC, a.id DESC
";

$result = $conn->query($sql);
if (!$result) {
    sendEncryptedResponse(["status" => false, "message" => "Query Error"], 500);
}

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

sendEncryptedResponse(["status" => true, "data" => $data], 200);
?>