<?php
// config.php - Versi "Encrypted" tapi tanpa HTML output error
// Mereplika proteksi di root, tapi return JSON 401 jika salah

error_reporting(0);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// === KEAMANAN (SAMA SEPERTI ROOT) ===
define('API_SECRET_KEY', 'Skaduta2025!@#SecureAPIKey1234567890');
set_time_limit(10);
ini_set('max_execution_time', 10);

// Koneksi DB
$host = "localhost";
$user = "root";
$pass = "";
$db = "database_smk_01";

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Database connection failed"]);
    exit;
}

// Fungsi Validate API Key - Hybrid (JSON / HTML Form)
function validateApiKey()
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];

    // Cek key dari berbagai sumber: Header, POST, GET
    $key = $headers['X-App-Key'] ?? $_SERVER['HTTP_X_APP_KEY'] ?? $_POST['x_app_key'] ?? $_GET['x_app_key'] ?? '';

    // Jika API Key Valid -> Lanjut (Return)
    if ($key === API_SECRET_KEY) {
        return;
    }

    // Jika Salah/Kosong -> Tampilkan Form HTML Sangat Sederhana
    header('Content-Type: text/html; charset=UTF-8');
    http_response_code(401);
    ?>
    <div style="font-family: monospace; text-align: center; margin-top: 50px;">
        <h2>API Key Required</h2>
        <?php if (!empty($key))
            echo "<p style='color:red'>Key Salah!</p>"; ?>
        <form method="POST">
            <?php foreach ($_POST as $k => $v)
                if ($k != 'x_app_key')
                    echo "<input type='hidden' name='$k' value='$v'>"; ?>
            <input type="text" name="x_app_key" placeholder="Masukkan Key..." required style="padding: 5px;">
            <button type="submit" style="padding: 5px;">Submit</button>
        </form>
    </div>
    <?php
    exit;
}

function randomDelay()
{
    $delay = rand(300000, 1000000); // 0.3 – 1 detik
    usleep($delay);
}

function sanitizeInput($data)
{
    if (is_array($data))
        return array_map('sanitizeInput', $data);
    return trim(htmlspecialchars(stripslashes($data), ENT_QUOTES, 'UTF-8'));
}
?>