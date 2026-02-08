<?php
/**
 * ABSEN ADMIN LIST (HAPUS & LIHAT) - UNTUK TESTING (TIDAK ENKRIPSI)
 */

require_once "config.php";

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Baca method
$method = $_SERVER['REQUEST_METHOD'];

// Baca input POST kalau ada
$postData = file_get_contents("php://input");
$input = json_decode($postData, true);

// KALAU POST + action delete → HAPUS ABSENSI
if ($method === 'POST' && isset($input['action']) && $input['action'] === 'delete' && !empty($input['id'])) {
    $id = mysqli_real_escape_string($conn, trim($input['id']));

    // Cek absensi ada
    $check = mysqli_query($conn, "SELECT id, selfie, dokumen FROM absensi WHERE id = '$id'");
    if (mysqli_num_rows($check) == 0) {
        http_response_code(404);
        echo json_encode(["status" => false, "message" => "Absensi tidak ditemukan"]);
        exit;
    }

    $row = mysqli_fetch_assoc($check);

    // Hapus file selfie (path relatif ke folder code/)
    if (!empty($row['selfie'])) {
        $selfiePath = "selfie/" . basename($row['selfie']);
        if (file_exists($selfiePath))
            @unlink($selfiePath);
    }

    // Hapus file dokumen (path relatif ke folder code/)
    if (!empty($row['dokumen'])) {
        $dokumenPath = "dokumen/" . basename($row['dokumen']);
        if (file_exists($dokumenPath))
            @unlink($dokumenPath);
    }

    // Hapus dari database
    $delete = mysqli_query($conn, "DELETE FROM absensi WHERE id = '$id'");

    if ($delete) {
        echo json_encode(["status" => true, "message" => "Absensi berhasil dihapus (Test Mode)"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => false, "message" => "Gagal: " . mysqli_error($conn)]);
    }
    exit;
}

// KALAU GET → TAMPILKAN SEMUA DATA ABSENSI
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
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Query Error: " . $conn->error]);
    exit;
}

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

echo json_encode([
    "status" => true,
    "message" => "Admin Absensi List (Test Mode)",
    "data" => $data
]);

$conn->close();
?>