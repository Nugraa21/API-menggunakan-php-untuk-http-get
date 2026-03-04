fetch("https://nonlitigious-alene-uninfinitely.ngrok-free.dev/presensi_rekap.php?debug=true", {
    headers: {
        "X-App-Key": "Skaduta2025!@#SecureAPIKey1234567890"
    }
})
.then(r => r.json())
.then(console.log);


// Penambahan keaman pada bagian Link api ( https://nonlitigious-alene-uninfinitely.ngrok-free.dev/presensi_rekap.php ) itu kan ada rekap php itu harusnya memunculkan json di web nya atau di console agar bisa di akses oleh Flutter tetapi ini di tambahkan block jadi datanya g langsung muncl jadi ada key unik untuk memunculkanya "X-App-Key": "Skaduta2025!@#SecureAPIKey1234567890" ini di pasang pada backend dan flutter 
