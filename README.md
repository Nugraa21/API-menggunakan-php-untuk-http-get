kalau mau pakai sql server 
```php
<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

// SQL Server config
$serverName = "SERVER_IP_OR_HOSTNAME";
$connectionOptions = [
    "Database" => "database_smk_2",
    "Uid" => "sqlserver_username",
    "PWD" => "sqlserver_password"
];

$conn = sqlsrv_connect($serverName, $connectionOptions);

if ($conn === false) {
    echo json_encode([
        "status" => "error",
        "message" => "Gagal koneksi SQL Server",
        "detail" => sqlsrv_errors()
    ]);
    exit;
}
?>

```      