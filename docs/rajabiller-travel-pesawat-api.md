# Rajabiller Travel — PESAWAT (Flight) API Reference

> Sumber: Postman "travel" collection `2s93XvX5VE` folder pesawat. Raw: `_pesawat-api-raw.txt`.
> Base PROD: `https://rajabiller.fastpay.co.id/travel`. Auth: JWT token (sama dgn kereta), TANPA signature.

## Flow: airport → configuration → search → fare → book → payment

### 1. `POST /flight/airport` — daftar bandara
Req: `{ token }` → `data[]{ code(CGK), name, bandara, group(Domestik/Internasional) }`

### 2. `POST /flight/configuration` — daftar maskapai (settings)
Req: `{ token }` → `data.settings[]{ airline(TPxx), airlineName, isActive, isChild, isInfant, icon, isCaptcha }`
→ Kode maskapai pakai prefix "TP": TPGA=Garuda, TPQG=Citilink, TPJT=Lion, dll.

### 3. `POST /flight/search` — cari penerbangan
Req: `{ airline:"TPJT", departure:"SUB", arrival:"CGK", departureDate:"YYYY-MM-DD", returnDate:"", isLowestPrice:true, adult, child, infant, token }`
Resp: `data[]{ classes[][]{ availability, seatKey, class, price, departureTime, arrivalTime, flightCode, departure, arrival, ..., seat(STRING token), duration, departureDate }, title, isTransit, detailTitle[] }`
→ ⚠️ Field **`seat`** = string token opaque (mis `"9;M0_C1_F0_S16;Q;729980;..."`). Dipakai apa adanya ke fare & book.

### 4. `POST /flight/fare` — konfirmasi harga
Req: `{ token, airline, departure, arrival, departureDate, returnDate:"", adult, child, infant, seats:[<seat string dari search>] }`
Resp: `data{ departureTime, arrivalTime, price, baggage, settings{} }`

### 5. `POST /flight/book` — booking
Req: `{ airline, departure, arrival, departureDate, returnDate, adult, child, infant, flights:[<seat string>], passengers{ adults[], children[], infants[] }, token }`
→ ⚠️ **passengers = array STRING delimited titik-koma**, BUKAN object. Format adult:
`"ADT;MR;<firstName>;<lastName>;MM/DD/YYYY;<idNumber>;::<phone>;::<phone>;;;;<email>;1;ID;ID;;;ID;"`
child: `"CHD;MSTR;<firstName>;<lastName>;MM/DD/YYYY;<idNumber>;ID;ID;;;ID;"`
(ADT=adult, CHD=child, INF=infant; MR/MRS/MSTR = title)
Resp: `data{ bookingCode, paymentCode, transactionId, flightCode1, departureTime1, arrivalTime1, timeLimit, timeLimitYMD, nominal, comission, nominalAdmin, komisi }`, rc:"00"

### 6. `POST /flight/payment` — bayar/issue
Req: `{ airline, transactionId, bookingCode, paymentCode, simulateSuccess:"yes", token }`
→ ✅ **`simulateSuccess:"yes"` = mode SIMULASI** (tidak potong saldo asli — AMAN untuk tes!). Production asli: hilangkan/`"no"`.
Resp: `data{ transaction_id, url_etiket, url_image, url_struk, nominal, komisi }`, rc:"00"

### 7. Lainnya
- `POST /flight/booking_list` `{ token }` — riwayat
- `POST /flight/booking_info` `{ airline, departure, arrival, transactionId, token }` — detail (message string panjang delimited)
- Transit/multi-segmen: search/fare/book sama, tapi `flights`/`seats` berisi >1 string segmen.

## Perbedaan vs KERETA (lebih kompleks)
1. Penumpang = **string delimited titik-koma** (bukan JSON object) → perlu encoder hati-hati (title, nama, DOB MM/DD/YYYY, NIK, phone, email, nationality).
2. `seat`/`flights` = **token opaque** dari search, harus diteruskan persis ke fare→book.
3. Ada **kode maskapai** (TPxx) + filter per maskapai.
4. `flight/payment` punya **`simulateSuccess`** → bisa tes tanpa potong saldo (kereta tidak ada).
5. Ada langkah **fare** (konfirmasi harga) antara search dan book.

## Yang dibutuhkan untuk implementasi
- ✅ Doc (sudah ada). Credential & auth (sudah jalan, sama dgn kereta).
- ❓ **Aktivasi produk pesawat** untuk outlet SP347829 (cek: apakah flight/airport & flight/search balas data atau kosong — kemungkinan perlu CS aktifkan seperti KAI).
- 🛠️ Backend: tambah method flight di `TravelService` + encoder passenger string + controller.
- 🛠️ Frontend: UI search (autocomplete bandara, filter maskapai, tanggal) → hasil → form penumpang (title/nama/DOB/NIK) → bayar → e-tiket.
