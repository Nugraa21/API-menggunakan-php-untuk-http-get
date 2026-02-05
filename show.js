fetch("https://nonlitigious-alene-uninfinitely.ngrok-free.dev/presensi_rekap.php?debug=true", {
    headers: {
        "X-App-Key": "Skaduta2025!@#SecureAPIKey1234567890"
    }
})
.then(r => r.json())
.then(console.log);