<?php
include "proteksi.php";
// Matikan semua error HTML agar tidak muncul <br /> atau warning
error_reporting(0);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// === KEAMANAN ===
define('API_SECRET_KEY', 'Skaduta2025!@#SecureAPIKey1234567890');
set_time_limit(10);
ini_set('max_execution_time', 10);

// Koneksi DB
$host = "localhost";
$user = "root";
$pass = "081328nugra";
$db   = "database_smk_4";

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Database connection failed"]);
    exit;
}

function validateApiKey() {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $key = $headers['X-App-Key'] ?? $_SERVER['HTTP_X_APP_KEY'] ?? '';

    if ($key !== API_SECRET_KEY) {
        http_response_code(401);
        header('Content-Type: text/html; charset=UTF-8');

        echo '
        <!DOCTYPE html>
        <html lang="id">
        <head>
            <meta charset="UTF-8">
            <title>API Service</title>
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
            <div class="wrapper">
                <h1>API Service</h1>
                <p>
                    Layanan backend ini digunakan untuk komunikasi data aplikasi.
                    Akses langsung melalui browser tidak disarankan.
                </p>

                <div class="badge">Status: Online</div>

                <div class="author">
                    Dikembangkan oleh<br>
                    <strong>Ludang Prasetyo Nugroho</strong>
                </div>

                <footer>
                    ©  Backend API
                </footer>
            </div>
        </body>
        </html>
        ';
        exit;
    }
}


function randomDelay() {
    $delay = rand(300000, 1000000); // 0.3 – 1 detik
    usleep($delay);
}

function sanitizeInput($data) {
    if (is_array($data)) return array_map('sanitizeInput', $data);
    return trim(htmlspecialchars(stripslashes($data), ENT_QUOTES, 'UTF-8'));
}
?>