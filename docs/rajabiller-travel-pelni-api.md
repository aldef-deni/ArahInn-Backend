# Rajabiller Travel — PELNI (Kapal Laut) API Reference

> Sumber: Postman "travel" collection `2s93XvX5VE` folder pelni. Raw: `_pelni-api-raw.txt`.
> Base PROD: `https://rajabiller.fastpay.co.id/travel`. Auth: JWT token (sama). TANPA signature.
> Status: PRODUCTION (seperti pesawat). Perlu cek aktivasi inventory pelni untuk outlet.

## Flow: get_origin → get_destination → search → check_availability → book → payment

### 1. `POST /pelni/get_origin` — daftar pelabuhan asal
Req: `{ token }` → `data[]{ CODE, NAME }` (mis CODE "563" = TANJUNG PERAK)

### 2. `POST /pelni/get_destination` — daftar pelabuhan tujuan
Req: `{ token }` → `data[]{ CODE, NAME }`

### 3. `POST /pelni/search` — cari jadwal kapal
Req: `{ origin(int code), destination(int code), startDate(YYYY-MM-DD), endDate(YYYY-MM-DD), token }`
→ rentang tanggal (kapal Pelni jadwal mingguan). Resp: `data[]{ fares[]{ SUBCLASS, CLASS, AVAILABILITY{F,M}, FARE_DETAIL{ A{TOTAL,FARE,INSURANCE,PORT_PASS,...}, I{...}, C{...} } }, ship info (shipNumber, shipName, departureDate, originCall, destinationCall...) }`
→ A=Adult, I=Infant, C=Child. TOTAL = harga termasuk asuransi + port pass.

### 4. `POST /pelni/check_availability` — cek kuota kursi subclass
Req: `{ origin, originCall, destination, destinationCall, departureDate(YYYYMMDD), shipNumber, subClass, male, female, token }`
Resp: `data{ F, M }` (sisa kuota Female / Male), rc:"00"

### 5. `POST /pelni/book` — booking
Req: `{ harga_dewasa, harga_anak, harga_infant, pelabuhan_asal, pelabuhan_tujuan, shipName, origin, originCall, destination, destinationCall, departureDate(YYYYMMDD), shipNumber, subClass, male, female, adult, child, infant, isFamily(Y/N), contact{email,phone}, passengers{ adults[{name, birthDate, identityNumber, gender(M/F)}], children[], infants[] }, token }`
Resp: `data{ paymentCode, transactionId, departureTime, arrivalDate, arrivalTime, seats[[deck,kamar,no]], normalSales, nominal_admin, bookingBalance, payLimit, komisi }`, rc:"00"

### 6. `POST /pelni/payment` — bayar/issue
Req: `{ paymentCode, transactionId, simulateSuccess:"yes", token }`
Resp: rc/rd (contoh balas `06 SALDO TIDAK MENCUKUPI` di outlet test — Pelni harga ASLI, butuh saldo deposit cukup).

## Perbedaan vs kereta/pesawat (lebih kompleks)
1. **Gender wajib** (male/female count + per-penumpang gender M/F) — kapal pisah kabin pria/wanita.
2. **subClass + CLASS** (mis EK1/Ekonomi) + **FARE_DETAIL** bertingkat (Adult/Infant/Child, dengan insurance + port pass).
3. **2 kode call** (originCall, destinationCall) — leg pelabuhan singgah.
4. **shipNumber + shipName** dari search diteruskan ke check_availability & book.
5. Ada langkah **check_availability** (cek kuota kabin sebelum book).
6. Search pakai **rentang tanggal** (startDate–endDate), bukan 1 tanggal.
7. departureDate format **YYYYMMDD** (book/check) vs **YYYY-MM-DD** (search).
8. Penumpang field beda: `birthDate`, `identityNumber`, `gender` (bukan idNumber/birthdate).

## Yang dibutuhkan untuk implementasi
- ✅ Doc (sudah ada). Auth (sudah jalan, channel CH_PROD).
- ❓ **Aktivasi inventory pelni** untuk outlet SP347829 (cek: get_origin & search balas data atau kosong — kemungkinan perlu CS aktifkan seperti pesawat).
- 🛠️ Backend: method pelni di TravelService (origin/destination/search/checkAvail/book/pay) + controller + routes. Lebih banyak field dari pesawat.
- 🛠️ Frontend: UI search (picker pelabuhan, rentang tanggal) → pilih kelas/kabin → form penumpang (+ gender) → checkout → bayar.
