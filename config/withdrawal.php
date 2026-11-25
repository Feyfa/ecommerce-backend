<?php

/*
 * Pengecekan ulang penarikan saldo yang masih diproses (`withdrawal:check`).
 *
 * Saldo penarikan sudah ditahan sebelum Xendit dipanggil. Nilai di bawah ini menentukan kapan
 * status di Xendit dibaca ulang dan kapan saldo boleh dikembalikan, jadi perubahan nilai
 * langsung memengaruhi uang user dan risiko pembayaran ganda.
 */
return [
    // --- Batas per menit ---
    // Scheduler berjalan setiap menit. Xendit Test Mode membatasi 60 request per menit per
    // endpoint, jadi 20 pengecekan per run menyisakan ruang untuk request lain.
    'check_batch_size' => 20,

    // --- Jeda pengecekan per penarikan ---
    // Jeda dalam menit setelah percobaan ke-1, ke-2, dan seterusnya. Nilai terakhir dipakai untuk
    // semua percobaan berikutnya, sehingga penarikan yang lama tertahan dicek paling jarang
    // sekali per jam.
    'check_backoff_minutes' => [1, 2, 5, 15, 60],

    // --- Penarikan yang tidak ditemukan di Xendit ---
    // Request yang tidak pernah sampai ke Xendit tidak meninggalkan data di sana. Setelah lewat
    // batas ini dari pembuatan penarikan, not-found dianggap gagal dan saldo dikembalikan.
    // Sebelum batas ini, not-found mungkin hanya karena Xendit belum selesai menyimpan data.
    'not_found_grace_minutes' => 10,

    // --- Penarikan yang terlalu lama diproses ---
    // Penarikan yang masih diproses lewat batas ini ditulis sekali sebagai log critical untuk
    // dicek manual dengan `withdrawal:status`. Saldo tetap ditahan karena waktu saja tidak
    // membuktikan bahwa pencairan gagal.
    'overdue_hours' => 24,
];
