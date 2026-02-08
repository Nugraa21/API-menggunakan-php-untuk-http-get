<?php
/**
 * DELETE USER - UNTUK TESTING (TIDAK ENKRIPSI)
 */

require_once "config.php";

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

ini_set('display_errors', 0);

// Ambil ID dari body JSON atau POST
$input = json_decode(file_get_contents('php://input'), true);
$id = $input['id'] ?? $_POST['id'] ?? '';

if (empty($id)) {
    echo json_encode(["status" => "error", "message" => "ID kosong"]);
    exit;
}

// Cek user yang mau dihapus
$stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo json_encode(["status" => "error", "message" => "User tidak ditemukan"]);
    exit;
}

$user = $result->fetch_assoc();
$stmt->close();

// Tidak boleh hapus superadmin (sama seperti asli)
if ($user["role"] == "superadmin") {
    echo json_encode(["status" => "error", "message" => "Tidak boleh menghapus akun superadmin"]);
    exit;
}

// Hapus User
$stmt_del = $conn->prepare("DELETE FROM users WHERE id = ?");
$stmt_del->bind_param("i", $id);

if ($stmt_del->execute()) {
    echo json_encode(["status" => "success", "message" => "User berhasil dihapus (Test Mode)"]);
} else {
    echo json_encode(["status" => "error", "message" => "Gagal hapus: " . $stmt_del->error]);
}

$stmt_del->close();
$conn->close();
?>