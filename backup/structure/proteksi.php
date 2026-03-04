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
            <title>API Service</title>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <style>
                body {
                    margin: 0;
                    height: 100vh;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    font-family: Arial, sans-serif;
                    background: #f8fafc;
                    color: #1e293b;
                }
                .wrapper {
                    text-align: center;
                    max-width: 440px;
                    padding: 20px;
                }
                h1 {
                    font-size: 24px;
                    margin-bottom: 8px;
                }
                p {
                    font-size: 14px;
                    color: #64748b;
                    line-height: 1.6;
                }
                .badge {
                    display: inline-block;
                    margin-top: 12px;
                    padding: 6px 12px;
                    font-size: 12px;
                    background: #e2e8f0;
                    border-radius: 20px;
                    color: #334155;
                }
                .author {
                    margin-top: 16px;
                    font-size: 13px;
                    color: #475569;
                }
                footer {
                    margin-top: 20px;
                    font-size: 12px;
                    color: #94a3b8;
                }
            </style>
        </head>
        <body>
            <div class='wrapper'>
                <h1>API Service</h1>
                <p>
                    Layanan backend ini digunakan untuk komunikasi data aplikasi.
                    Akses langsung melalui browser tidak disarankan.
                </p>

                <div class='badge'>Status: Online</div>

                <div class='author'>
                    Dikembangkan oleh<br>
                    <strong>Ludang Prasetyo Nugroho</strong>
                </div>

                <footer>
                    ©  Backend API
                </footer>
            </div>
        </body>
        </html>";
        exit;
    }
}
// Jika di-include dari file lain → lanjut normal (tidak ada output apa-apa)
?>