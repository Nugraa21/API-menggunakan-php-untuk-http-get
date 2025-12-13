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
            <title>Akses Ditolak</title>
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
                .container {
                    text-align: center;
                }
                h1 {
                    font-size: 22px;
                    margin-bottom: 6px;
                }
                p {
                    font-size: 14px;
                    color: #64748b;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>Akses tidak diizinkan</h1>
                <p>Endpoint ini tidak dapat diakses secara langsung.</p>
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