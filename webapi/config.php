<?php
// --- CORS HEADERS (WAJIB PALING ATAS) ---
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization, X-App-Key, ngrok-skip-browser-warning");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE, PUT");

// Handle preflight request browser (OPTIONS)
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

include "proteksi.php";

// Matikan error visual agar tidak merusak JSON
error_reporting(0);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

header("Content-Type: application/json; charset=UTF-8");

// === KEAMANAN ===
define('API_SECRET_KEY', 'Skaduta2025!@#SecureAPIKey1234567890');

set_time_limit(20);
ini_set('max_execution_time', 20);

// Koneksi DB
$host = "localhost";
$user = "root";
$pass = "";
$db = "database_smk_01";

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Database connection failed: " . mysqli_connect_error()
    ]);
    exit;
}

function validateApiKey()
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $key = $headers['X-App-Key'] ?? $_SERVER['HTTP_X_APP_KEY'] ?? '';

    // Cek header case-insensitive untuk X-App-Key
    if (empty($key)) {
        foreach ($headers as $header => $value) {
            if (strtolower($header) === 'x-app-key') {
                $key = $value;
                break;
            }
        }
    }

    if ($key !== API_SECRET_KEY) {
        http_response_code(401);
        echo json_encode([
            "status" => false,
            "message" => "Unauthorized: Invalid API Key"
        ]);
        exit;
    }
}

function randomDelay()
{
    // Optional delay for testing/simulation
    // $delay = rand(100000, 300000); 
    // usleep($delay);
}

function sanitizeInput($data)
{
    if (is_array($data))
        return array_map('sanitizeInput', $data);
    return trim(htmlspecialchars(stripslashes($data), ENT_QUOTES, 'UTF-8'));
}
?>