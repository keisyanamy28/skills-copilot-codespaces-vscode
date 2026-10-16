# skills-copilot-codespaces-vscode

API POS online (siap untuk integrasi aplikasi Android) untuk **Tahu Uap Mas Heri**.

## Fitur inti
- POS khusus role **karyawan** (`/api/employee/pos/sales`)
- Role **owner** bisa:
  - tambah stok tahu + riwayat tanggal/jumlah
  - catat transaksi pemasukan/pengeluaran
  - lihat laporan ringkasan
  - lihat dashboard card penting + grafik penjualan harian 7 hari
- Database **SQLite** (`database/pos.sqlite`) untuk data kecil

## Menjalankan API
```bash
php -S localhost:8000 -t public
```

Semua request/response menggunakan JSON dan otorisasi role sederhana via header `X-Role` (`employee` atau `owner`).

## Endpoint
- `POST /api/owner/stocks`
- `GET /api/owner/stocks/history`
- `POST /api/employee/pos/sales`
- `POST /api/owner/transactions`
- `GET /api/owner/reports`
- `GET /api/owner/dashboard`

## Menjalankan test
```bash
composer test
```
