<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        .fade { animation: fade .3s ease-in-out; }
        @keyframes fade {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>

<body class="bg-gray-100 text-gray-900">

    <div class="max-w-3xl mx-auto px-6 py-10 fade">

        <!-- Title -->
        <h1 class="text-3xl font-semibold mb-8 text-center">
            
        </h1>

        <!-- Card -->
        <div class="bg-white shadow-md rounded-xl p-6 border border-gray-200">

            <!-- NGROK URL -->
            <div class="mb-6">
                <div class="flex items-center gap-2 mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-gray-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.5 4.5L21 12l-7.5 7.5m-9-15L12 12 4.5 19.5" />
                    </svg>
                    <h2 class="text-lg font-medium">Ngrok Public URL</h2>
                </div>
                <p id="ngrok-url" class="font-mono text-gray-700 text-sm">Loading...</p>
            </div>

            <hr class="my-6">

            <!-- API STATUS -->
            <div>
                <div class="flex items-center gap-2 mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-gray-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m7 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <h2 class="text-lg font-medium">API Status</h2>
                </div>

                <p id="api-status" class="text-gray-600">Menunggu...</p>

                <button 
                    onclick="testAPI()" 
                    class="mt-4 px-4 py-2 bg-gray-900 text-white rounded-md text-sm hover:bg-gray-800 transition">
                    Cek API
                </button>

                <pre id="response" class="mt-4 bg-gray-50 p-3 rounded-md text-sm text-gray-800 border border-gray-200 overflow-auto"></pre>
            </div>
        </div>

        <p class="text-center text-sm text-gray-500 mt-10">
            Nugra21 &bull; API Dashboard
        </p>

    </div>

    <script>
        // Ambil URL ngrok
        fetch("ngrok.json")
            .then(res => res.json())
            .then(data => {
                document.getElementById("ngrok-url").textContent = data.url;
            })
            .catch(() => {
                document.getElementById("ngrok-url").textContent = "Belum terkoneksi ke ngrok.";
            });

        // Test API
        function testAPI() {
            fetch("/api/status")
                .then(res => res.json())
                .then(data => {
                    document.getElementById("api-status").innerHTML = 
                        `<span class='text-green-600 font-medium'>Online</span>`;
                    document.getElementById("response").textContent = JSON.stringify(data, null, 4);
                })
                .catch(() => {
                    document.getElementById("api-status").innerHTML = 
                        `<span class='text-red-600 font-medium'>Offline</span>`;
                });
        }
    </script>

</body>
</html>
