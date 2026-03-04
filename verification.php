<?php
// verification.php - Test Script for Encrypted Endpoints
include "encryption.php";

echo "=== Verification Script for Encrypted API ===\n\n";

function testEndpoint($url, $data, $description)
{
    echo "Testing: $description ($url)\n";

    // 1. Encrypt Request
    $json_req = json_encode($data);
    $encrypted_req = Encryption::encrypt($json_req);
    $payload = json_encode(["encrypted_data" => $encrypted_req]);

    // 2. Send via CURL
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-App-Key: Skaduta2025!@#SecureAPIKey1234567890']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "HTTP Code: $http_code\n";

    // 3. Decrypt Response
    $json_res = json_decode($response, true);
    if (isset($json_res['encrypted_data'])) {
        $decrypted_res = Encryption::decrypt($json_res['encrypted_data']);
        if ($decrypted_res) {
            echo "RESPONSE (Decrypted): " . $decrypted_res . "\n";
            $res_data = json_decode($decrypted_res, true);
            if (isset($res_data['status'])) {
                echo "Status: " . ($res_data['status'] ? "SUCCESS" : "FAIL") . "\n";
            }
        } else {
            echo "ERROR: Failed to decrypt response.\n";
            echo "Raw Response: $response\n";
        }
    } else {
        echo "ERROR: Response is not encrypted or invalid.\n";
        echo "Raw Response: $response\n";
    }
    echo "--------------------------------------------------\n\n";
}

$baseUrl = "http://localhost/backendapk";

// Test 1: Login (Assume user 'admin' exists or fails gracefully)
$loginData = [
    "username" => "admin",
    "password" => "admin123",
    "device_id" => "test_device_verification"
];
testEndpoint("$baseUrl/login.php", $loginData, "Login Endpoint");

// Test 2: Absen History (User ID 1)
$historyData = [
    "user_id" => 1
];
testEndpoint("$baseUrl/absen_history.php", $historyData, "Absen History Endpoint");

?>