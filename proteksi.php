<?php
// proteksi.php - Lindungi file dari akses langsung

// Daftar file API yang BOLEH diakses langsung via URL
$allowed_direct_access = [
    'absen_admin_list.php',
    'absen_approve.php',
    'absen_history.php',
    'absen.php',
    'delete_user.php',
    // 'encryption.php',        // Jika kamu pakai untuk decrypt di client-side (opsional, bisa dihapus kalau tidak perlu)
    'get_users.php',
    'index.php',             // Jika ada halaman utama
    'license.php',
    'login.php',
    'presensi_add.php',
    'presensi_approve.php',
    'presensi_pending.php',
    'presensi_rekap.php',
    'presensi_user_history.php',
    'register.php',
    'update_password.php',
    'update_user.php',
    // Tambahkan file API lain yang boleh diakses langsung di sini
];

// Nama file yang sedang dijalankan
$current_file = basename($_SERVER['SCRIPT_NAME']);

if (!in_array($current_file, $allowed_direct_access)) {
    // Jika bukan file API yang diizinkan → blokir akses langsung
    if (isset($_SERVER['REQUEST_METHOD'])) {
        // Jika diakses via HTTP (browser atau curl)
        http_response_code(404);
        header("Content-Type: text/html; charset=UTF-8");
        echo "<!DOCTYPE html>
<html lang='id'>
<head>
    <meta charset='UTF-8'>
    <title>404 Not Found</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; color: #333; text-align: center; padding: 100px; }
        h1 { font-size: 50px; margin: 0; }
        p { font-size: 20px; }
    </style>
</head>
<body>
    <h1>--</h1>
    <p>Halaman sangat sensitif.</p>
</body>
</html>";
        exit;
    }
}
// Jika di-include dari file lain → lanjut normal (tidak ada output apa-apa)
?>