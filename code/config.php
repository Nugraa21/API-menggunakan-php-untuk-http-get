<?php
// config.php - Versi untuk pengetesan (Tanpa Enkripsi / API Key)

// Matikan error visual agar return JSON bersih
error_reporting(0);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Koneksi Database (Sama seperti aslinya)
$host = "localhost";
$user = "root";
$pass = "";
$db = "database_smk_01";

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Database connection failed: " . mysqli_connect_error()]);
    exit;
}

// Fungsi Helper (Disalin dari config asli untuk kompabilitas)
function sanitizeInput($data)
{
    if (is_array($data))
        return array_map('sanitizeInput', $data);
    return trim(htmlspecialchars(stripslashes($data), ENT_QUOTES, 'UTF-8'));
}

// Fungsi dummy agar kode lain tidak error saat memanggil randomDelay()
function randomDelay()
{
    // Tidak ada delay untuk versi testing agar cepat
}

// Fungsi dummy untuk validateApiKey (jika ada file yang memanggilnya, tidak akan error dan lolos terus)
function validateApiKey()
{
    // Bypass: Selalu sukses, tidak mengecek apa-apa
    return true;
}
?>