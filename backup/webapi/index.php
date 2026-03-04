<?php
// index.php - API Landing Page
http_response_code(200);
header('Content-Type: text/html; charset=UTF-8');
?>
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
            © <?php echo date('Y'); ?> Backend API
        </footer>
    </div>
</body>
</html>
