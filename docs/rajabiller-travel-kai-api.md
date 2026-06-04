# Rajabiller Travel — KAI (Kereta) API Reference

> Sumber: Postman "Devel KAI" (https://documenter.getpostman.com/view/2547279/2sAXjM3rit)
> Raw dump lengkap: `_kai-api-raw.txt` di folder ini.

## Environment — KAI WAJIB DEVEL (per CS Rajabiller 2026-06-04)
- **KAI base:** `https://c-dev-travel.rajabiller.com` (DEVEL) — UID `SP347829`, **PIN devel `311575`**.
  CS: "untuk KAI hit ke devel". KAI TIDAK ada di production. Terbukti jalan dari server (search balas jadwal asli Argo Parahyangan, dll).
- Pesawat & Pelni: PRODUCTION `https://rajabiller.fastpay.co.id/travel` (PIN `681768`). Channel terpisah di `TravelService` (CH_KAI vs CH_PROD).
- Generate etiket/struk DEV: `https://h2h-api-travel-dev.kavl.ink/app/...`

## Auth — TANPA signature/hash (cukup JWT token)
`POST /app/sign_in`
```json
{ "outletId": "<uid>", "pin": "<pin>" }
```
Response: `{ token (JWT, valid 1 hari), data.balance, rc:"00", rd, mid }`
→ **Cache token harian**. Semua request lain kirim `token` di BODY (bukan header).

`rc` codes: `00`=sukses, `33`=tidak ditemukan, dll (pola sama PPOB).

## FLOW BOOKING KERETA (productCode = "WKAI")

### 1. Station list — `POST /train/station`
Req: `{ token }` → `data[]{ id_stasiun, nama_stasiun, nama_kota, is_active }`

### 2. Cari jadwal — `POST /train/search`
Req: `{ productCode:"WKAI", origin, destination, date:"YYYY-MM-DD", adult, infant, token }`
Resp: `data[]{ trainNumber, trainName, departureDate, arrivalDate, departureTime, arrivalTime, duration, seats[]{ class, availability, grade(E/K/B), priceAdult, priceChild, priceInfant } }`

### 3. Booking — `POST /train/book`
Req: `{ productCode, origin, destination, date, trainNumber, grade, class, adult, child, infant, priceAdult, priceChild, priceInfant, trainName, departureStation, departureTime, arrivalStation, arrivalTime, token, passengers:{ adults[]{name,birthdate,phone,idNumber}, infants[]{name,birthdate,idNumber} } }`
Resp: `data{ bookingCode, transactionId, passengers[], seats[], komisi, normalSales, extraFee, nominalAdmin, bookBalance, discount, timeLimit } `
→ **timeLimit** = batas waktu bayar. Simpan bookingCode + transactionId.

### 4. (Opsional) Seat layout — `POST /train/get-seat-layout`
Req: `{ productCode, origin, destination, date, trainNumber, token }`
Resp: `data[]{ wagonCode, wagonNumber, layout[]{ row, column, class, isFilled(0/1), groupColumn } }`

### 5. (Opsional) Ganti kursi — `POST /train/change_seat` (versi RECOMMENDED)
Req: `{ productCode, bookingCode, transactionId, seats[]{ wagonCode, wagonNumber, row, column }, token }`

### 6. (Opsional) Batal — `POST /train/cancel_book`
Req: `{ productCode, bookingCode, transactionId, reason, token }`

### 7. Bayar/Issue — `POST /train/payment`
Req: `{ productCode, bookingCode, transactionId, nominal, nominal_admin, discount, pay_type:"TUNAI", token }`
Resp: `data{ transaction_id, url_etiket, url_image, url_struk, komisi }, rc:"00" `
→ **pay_type "TUNAI" = potong saldo deposit Rajabiller.** url_etiket = e-tiket customer.

## General (cek transaksi)
- `POST /app/transaction_info` `{ product:"KERETA", transaction_id, token }` → detail + url_etiket/struk
- `POST /app/transaction_status` `{ product, bookCode, token }` → `{ Status, status_booking, status_payment }`
- `POST /app/transaction_list` `{ product, token }` → riwayat

## Catatan implementasi
- **Money flow ArahInn:** customer bayar ke ArahInn (gateway sendiri / DOKU) → ArahInn panggil `/train/payment` (potong saldo deposit Rajabiller) → dapat url_etiket → kirim ke customer. Markup ArahInn = selisih harga jual vs `nominal`+`nominal_admin`.
- Produk lain: PESAWAT, KAPAL (Pelni) pakai pola `/app/transaction_*` yang sama; flow booking spesifik kemungkinan beda path (perlu doc masing-masing).
- TIDAK ada signature/hash → integrasi relatif simpel (beda dgn PPOB RajaBillerService yang pakai signature).
