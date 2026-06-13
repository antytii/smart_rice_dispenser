#include <Arduino.h>
#include <WiFi.h>
#include "Firebase_ESP_Client.h"
#include <MFRC522.h>
#include <Keypad.h>
#include <HX711.h>
#include <ESP32Servo.h>
#include <LiquidCrystal_I2C.h>
#include <time.h>
#include <map>

// =========================================================================
// 1. KONFIGURASI PENGGUNA
// =========================================================================
#define WIFI_SSID       "Hotspot"
#define WIFI_PASSWORD   "12345678"
#define FIREBASE_HOST   "https://iot-smartrice-default-rtdb.asia-southeast1.firebasedatabase.app/" 
#define FIREBASE_AUTH   "WTA27Yg56Ll7VtINJ0ft6JdNghS51vZnhNgf3hQI"

float nilai_kalibrasi_hx711 = -462.30; 
const float TINGGI_TANGKI_MAKSIMAL = 50.0; 
const String ID_ALAT = "ALAT-001"; // ID Alat sesuai node 'perangkats'

// PIN default yang wajib diganti oleh warga pada transaksi pertama
const String DEFAULT_PIN = "0000";

// Durasi blokir KTP sementara setelah 3x salah PIN (dalam milidetik)
const unsigned long DURASI_BLOKIR_MS = 30000; // 30 detik

// =========================================================================
// 2. DEKLARASI PIN HARDWARE (SUDAH DISELARASKAN & BEBAS BENTROK)
// =========================================================================
// JALUR SPI MURNI (Untuk RFID RC522)
#define PIN_RST          4   // RFID Reset (Kabel RST RFID wajib pasang ke D4)
#define PIN_SS           5   // RFID SDA/SS (Kembalikan ke D5, jalur default SPI)

// JALUR SENSOR & AKTUATOR
#define PIN_TRIG         12  // Ultrasonic Trigger
#define PIN_ECHO         35  // Ultrasonic Echo (Pindah ke D35 karena ini pin KHUSUS INPUT)
#define PIN_DOUT         34  // Load Cell HX711 Data (Pin KHUSUS INPUT, aman)
#define PIN_SCK          15  // Load Cell HX711 Clock (Pindah dari D5 agar tidak tabrakan dengan RFID)
#define PIN_SERVO        13  // Motor Servo Katup
#define PIN_BUZZER       2   // Buzzer Positif

// JALUR KEYPAD 4x4
const byte ROWS = 4;
const byte COLS = 4;
char keys[ROWS][COLS] = {
  {'1','2','3','A'},
  {'4','5','6','B'},
  {'7','8','9','C'},
  {'*','0','#','D'}
};

// Baris (Row) butuh pin dengan internal pull-up. D15 diganti ke D14 agar aman saat booting:
byte rowPins[ROWS] = {32, 33, 27, 14};    
// Kolom (Col) sebagai output:
byte colPins[COLS] = {16, 17, 25, 26};

// =========================================================================
// 3. INISIALISASI OBJEK & VARIABEL GLOBAL
// =========================================================================
MFRC522 rfid(PIN_SS, PIN_RST);
Keypad keypad = Keypad(makeKeymap(keys), colPins, rowPins, ROWS, COLS);
HX711 scale;
Servo katupServo;
LiquidCrystal_I2C lcd(0x27, 16, 2); // Menggunakan internal I2C default (SDA: D21, SCL: D22)

FirebaseData fbDo;
FirebaseAuth auth;
FirebaseConfig config;

// Map untuk menyimpan UID kartu yang sedang terblokir beserta waktu blokirnya
// Key: UID string kartu, Value: millis() saat diblokir
std::map<String, unsigned long> blockedCards;

// Variable global untuk heartbeat berkala
unsigned long lastHeartbeatTime = 0;
const unsigned long HEARTBEAT_INTERVAL = 2000; // 5 detik

// =========================================================================
// POLA BUNYI STANDAR
// =========================================================================
// Konstanta pola bunyi
#define BUNYI_SUKSES      1  // 1x bunyi panjang (800ms)        — transaksi sukses, PIN benar
#define BUNYI_PERINGATAN  2  // 2x bunyi pendek (150ms)         — stok menipis, perhatian
#define BUNYI_ERROR       3  // 3x bunyi pendek (100ms)         — KTP tidak terdaftar, PIN salah
#define BUNYI_BLOKIR      4  // 1x bunyi sangat panjang (2000ms) — terblokir, stok tidak cukup

// Deklarasi fungsi pendukung agar tidak error saat kompilasi
void resetTampilanStandby();
String proresInputPIN();
void updateStatusDanStokAlat(float sisaKg, float persentase);
bool prosesGantiPIN(String uidKartu);

// -------------------------------------------------------------------------
// bipBuzzer: Bunyi tunggal dengan durasi tertentu (ms)
// -------------------------------------------------------------------------
void bipBuzzer(int durasi) {
  digitalWrite(PIN_BUZZER, HIGH);
  delay(durasi);
  digitalWrite(PIN_BUZZER, LOW);
}

// -------------------------------------------------------------------------
// bipStandar: Fungsi bunyi standar berdasarkan pola yang telah ditetapkan
//   BUNYI_SUKSES     = 1x panjang  (800ms)
//   BUNYI_PERINGATAN = 2x pendek   (150ms, jeda 100ms)
//   BUNYI_ERROR      = 3x pendek   (100ms, jeda 100ms)
//   BUNYI_BLOKIR     = 1x sangat panjang (2000ms)
// -------------------------------------------------------------------------
void bipStandar(int pola) {
  switch (pola) {
    case BUNYI_SUKSES:
      bipBuzzer(800);
      break;

    case BUNYI_PERINGATAN:
      bipBuzzer(150); delay(100);
      bipBuzzer(150);
      break;

    case BUNYI_ERROR:
      bipBuzzer(100); delay(100);
      bipBuzzer(100); delay(100);
      bipBuzzer(100);
      break;

    case BUNYI_BLOKIR:
      bipBuzzer(2000);
      break;

    default:
      bipBuzzer(200);
      break;
  }
}

// -------------------------------------------------------------------------
// hitungStokBeras: Membaca sensor ultrasonik dan menghitung sisa stok beras
// -------------------------------------------------------------------------
void hitungStokBeras(float &sisaKg, float &persentase, float &jarakOut) {
  digitalWrite(PIN_TRIG, LOW);
  delayMicroseconds(2);
  digitalWrite(PIN_TRIG, HIGH);
  delayMicroseconds(10);
  digitalWrite(PIN_TRIG, LOW);
  
  long durasi = pulseIn(PIN_ECHO, HIGH);
  float jarak = durasi * 0.034 / 2;
  jarakOut = jarak; // Kembalikan jarak asli
  
  const float JARAK_KOSONG = 14;
  const float JARAK_PENUH = 4.9;
  const float KAPASITAS_MAKSIMAL = 1.1; // Kapasitas 1 kg
  
  if (jarak > JARAK_KOSONG) jarak = JARAK_KOSONG;
  if (jarak < JARAK_PENUH) jarak = JARAK_PENUH;
  
  persentase = ((JARAK_KOSONG - jarak) / (JARAK_KOSONG - JARAK_PENUH)) * 100.0;
  sisaKg = (persentase / 100.0) * KAPASITAS_MAKSIMAL;
}

// =========================================================================
// 4. SETUP SISTEM
// =========================================================================
void setup() {
  Serial.begin(115200);
  
  lcd.init();
  lcd.backlight();
  lcd.setCursor(0, 0);
  lcd.print("Sistem Memulai...");

  SPI.begin();
  rfid.PCD_Init();

  rfid.PCD_DumpVersionToSerial();

  // ---> Mengakali chip clone RFID <---
  rfid.PCD_SetAntennaGain(rfid.RxGain_max);

  scale.begin(PIN_DOUT, PIN_SCK);
  
  Serial.print("Mengecek Load Cell (HX711)... ");
  if (scale.is_ready()) {
    Serial.println("[SUKSES] Load Cell Siap dan Terbaca Tanpa Masalah!");
  } else {
    Serial.println("[ERROR] Load Cell Tidak Merespon! Cek kabel DOUT dan SCK.");
  }

  scale.set_scale(nilai_kalibrasi_hx711);
  scale.tare();
  
  katupServo.attach(PIN_SERVO);
  katupServo.write(0); // Katup terkunci rapat (0 derajat)
  
  pinMode(PIN_TRIG, OUTPUT);
  pinMode(PIN_ECHO, INPUT);
  pinMode(PIN_BUZZER, OUTPUT);
  digitalWrite(PIN_BUZZER, LOW);

  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  lcd.clear();
  lcd.print("Mencari Wi-Fi...");
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }

  // Hubungkan ke NTP server untuk sinkronisasi waktu UTC
  configTime(0, 0, "pool.ntp.org", "time.nist.gov");

  // ==========================================
  // KONFIGURASI & PENGECEKAN FIREBASE
  // ==========================================
  config.host = FIREBASE_HOST;
  config.signer.tokens.legacy_token = FIREBASE_AUTH;
  
  Firebase.begin(&config, &auth);
  Firebase.reconnectWiFi(true);

  lcd.clear();
  lcd.print("Cek Firebase...");
  Serial.println("\nMengecek koneksi ke Firebase Realtime Database...");

  unsigned long timeout = millis();
  while (!Firebase.ready()) {
    delay(500);
    Serial.print("#");
    if (millis() - timeout > 10000) {
      break;
    }
  }

  if (Firebase.ready()) {
    Serial.println("\n[SUKSES] Terhubung ke Firebase dengan Aman!");
    lcd.clear();
    lcd.print("Firebase OK!");
    delay(1500);
  } else {
    Serial.print("\n[ERROR] Gagal Konek Firebase. Alasan: ");
    Serial.println(fbDo.errorReason().c_str());
    
    lcd.clear();
    lcd.print("Firebase Error!");
    lcd.setCursor(0, 1);
    lcd.print("Cek Serial Mon");
    
    delay(4000); 
  }

  // Bunyi tanda sistem siap
  bipStandar(BUNYI_SUKSES);
  resetTampilanStandby();
}

// =========================================================================
// 5. LOGIKA UTAMA PERANGKAT (LOOPING)
// =========================================================================
void loop() {
  if (WiFi.status() != WL_CONNECTED) return;

  // ========================================================
  // DEBUGGING REAL-TIME TIMBANGAN (RAW ADC)
  // ========================================================
  static unsigned long waktuDebugTerakhir = 0;
  if (millis() - waktuDebugTerakhir > 1000) {
    waktuDebugTerakhir = millis();
    
    if (scale.is_ready()) {
      long nilaiMentahRealtime = scale.read();
      Serial.print("[REAL-TIME] Nilai Mentah (RAW) Timbangan: ");
      Serial.println(nilaiMentahRealtime);
    } else {
      Serial.println("[REAL-TIME] ERROR: Kabel HX711 terputus/longgar!");
    }
  }

  // Heartbeat berkala
  if (millis() - lastHeartbeatTime >= HEARTBEAT_INTERVAL || lastHeartbeatTime == 0) {
    lastHeartbeatTime = millis();
    float sisaKg = 0.0;
    float persentase = 0.0;
    float dummyJarak = 0.0;
    hitungStokBeras(sisaKg, persentase, dummyJarak);
    updateStatusDanStokAlat(sisaKg, persentase);
    Serial.print("[HEARTBEAT] Stok terupdate: ");
    Serial.print(sisaKg);
    Serial.print(" kg (");
    Serial.print(persentase);
    Serial.println("%)");
  }

  if (!rfid.PICC_IsNewCardPresent() || !rfid.PICC_ReadCardSerial()) {
    return; 
  }

  String uidKartu = "";
  for (byte i = 0; i < rfid.uid.size; i++) {
    uidKartu += String(rfid.uid.uidByte[i] < 0x10 ? "0" : "");
    uidKartu += String(rfid.uid.uidByte[i], HEX);
  }
  uidKartu.toUpperCase();
  
  // ============================================================
  // CEK BLOKIR SEMENTARA (per UID)
  // ============================================================
  if (blockedCards.count(uidKartu) > 0) {
    unsigned long waktuBlokir = blockedCards[uidKartu];
    unsigned long selisih = millis() - waktuBlokir;

    if (selisih < DURASI_BLOKIR_MS) {
      // Masih dalam masa blokir
      unsigned long sisaDetik = (DURASI_BLOKIR_MS - selisih) / 1000;
      lcd.clear();
      lcd.print("KTP TERKUNCI!");
      lcd.setCursor(0, 1);
      lcd.print("Tunggu: " + String(sisaDetik) + " detik ");
      Serial.println("[INFO] KTP " + uidKartu + " masih terblokir, sisa " + String(sisaDetik) + " detik.");
      bipStandar(BUNYI_ERROR);
      delay(2000);
      resetTampilanStandby();
      return;
    } else {
      // Masa blokir sudah habis, hapus dari map
      blockedCards.erase(uidKartu);
      Serial.println("[INFO] Blokir KTP " + uidKartu + " telah berakhir.");
    }
  }
  
  bipBuzzer(100); // Bunyi pendek tanda kartu terbaca
  lcd.clear();
  lcd.print("KARTU TERBACA");
  lcd.setCursor(0, 1);
  lcd.print("Memvalidasi...");

  String pathWarga = "/wargas/" + uidKartu;
  String pathJatah = "/jatah_wargas/" + uidKartu;

  String pinDatabase = "";
  String namaWarga = "";
  String nikWarga = "";
  FirebaseJsonData jsonData; 

  // -----------------------------------------------------
  // 1. CEK DATA WARGA
  // -----------------------------------------------------
  if (Firebase.ready()) {
    if (Firebase.RTDB.getJSON(&fbDo, pathWarga)) {
      if (fbDo.dataType() == "null") {
        // KTP tidak terdaftar di database
        lcd.clear();
        lcd.print("KARTU TIDAK");
        lcd.setCursor(0, 1);
        lcd.print("TERDAFTAR!");
        Serial.println("[INFO] KTP " + uidKartu + " tidak terdaftar di database.");
        bipStandar(BUNYI_ERROR); // 3x bunyi pendek = error
        delay(2000);
        resetTampilanStandby();
        return;
      }

      FirebaseJson jsonWarga;
      jsonWarga.setJsonData(fbDo.jsonString());
      jsonWarga.get(jsonData, "pin");
      pinDatabase = jsonData.stringValue;
      jsonWarga.get(jsonData, "nama");
      namaWarga = jsonData.stringValue;
      jsonWarga.get(jsonData, "nik");
      nikWarga = jsonData.stringValue;

    } else {
      // Koneksi Firebase gagal saat getJSON
      lcd.clear();
      lcd.print("KARTU TIDAK");
      lcd.setCursor(0, 1);
      lcd.print("TERDAFTAR!");
      Serial.println("[ERROR] Gagal ambil data warga: " + fbDo.errorReason());
      bipStandar(BUNYI_ERROR); // 3x bunyi pendek = error
      delay(2000);
      resetTampilanStandby();
      return;
    }
  } else {
    lcd.clear();
    lcd.print("GANGGUAN SERVER!");
    bipStandar(BUNYI_PERINGATAN); // 2x bunyi = peringatan server
    delay(2000);
    resetTampilanStandby();
    return;
  }

  // ==========================================
  // CEK PIN DEFAULT — WAJIB GANTI DULU
  // ==========================================
  if (pinDatabase == DEFAULT_PIN || pinDatabase == "") {
    lcd.clear();
    lcd.print("PIN MASIH DEFAULT");
    lcd.setCursor(0, 1);
    lcd.print("Wajib Ganti PIN!");
    Serial.println("[INFO] Warga " + namaWarga + " masih pakai PIN default. Wajib ganti.");
    bipStandar(BUNYI_PERINGATAN); // 2x bunyi = perhatian
    delay(2500);

    // Panggil proses ganti PIN, jika gagal batalkan
    bool berhasilGanti = prosesGantiPIN(uidKartu);
    if (!berhasilGanti) {
      lcd.clear();
      lcd.print("GANTI PIN GAGAL");
      lcd.setCursor(0, 1);
      lcd.print("Coba Lagi Nanti");
      bipStandar(BUNYI_ERROR);
      delay(2500);
      resetTampilanStandby();
      return;
    }

    // Ambil PIN yang baru dari Firebase setelah berhasil ganti
    if (Firebase.RTDB.getJSON(&fbDo, pathWarga)) {
      FirebaseJson jsonWargaBaru;
      jsonWargaBaru.setJsonData(fbDo.jsonString());
      jsonWargaBaru.get(jsonData, "pin");
      pinDatabase = jsonData.stringValue;
    }
  }

  // ==========================================
  // LANJUT KE PROSES VALIDASI PIN
  // ==========================================
  lcd.clear();
  lcd.print("Halo, Penerima:");
  lcd.setCursor(0, 1);
  lcd.print(namaWarga.substring(0, 16)); 
  delay(2000);
  
  // LOGIKA 3 KALI PERCOBAAN PIN
  int batasSalah = 0;
  bool pinBenar = false;

  while (batasSalah < 3) {
    String pinInput = proresInputPIN();
    
    if (pinInput == pinDatabase) {
      pinBenar = true;
      bipStandar(BUNYI_SUKSES); // 1x panjang = PIN benar
      break;
    } else {
      batasSalah++;
      lcd.clear();
      lcd.print("PIN ANDA SALAH!");
      
      if (batasSalah < 3) {
        lcd.setCursor(0, 1);
        lcd.print("Sisa Coba: " + String(3 - batasSalah));
        bipStandar(BUNYI_ERROR); // 3x bunyi = PIN salah
        delay(2000);
      } else {
        // 3x salah PIN -> blokir UID ini sementara
        lcd.setCursor(0, 1);
        lcd.print("AKSES DITOLAK!");
        blockedCards[uidKartu] = millis(); // Simpan waktu blokir
        Serial.println("[PERINGATAN] KTP " + uidKartu + " diblokir 30 detik karena 3x salah PIN.");
        bipStandar(BUNYI_BLOKIR); // 1x sangat panjang = blokir
        delay(2500);
      }
    }
  }

  // Jika sampai 3 kali salah, batalkan proses
  if (!pinBenar) {
    resetTampilanStandby();
    return;
  }

  // -----------------------------------------------------
  // 2. CEK & AKUMULASI JATAH BERAS (SISTEM RAPEL)
  // -----------------------------------------------------
  float totalTargetBeras = 0.0;
  String teksBulanLCD = "";
  String arrayBulanUpdate[12];
  int jumlahBulanDiambil = 0;

  lcd.clear();
  lcd.print("Cek Jatah...");

  if (Firebase.RTDB.getJSON(&fbDo, pathJatah)) {
    if (fbDo.dataType() != "null") {
      FirebaseJson jsonSemuaJatah;
      jsonSemuaJatah.setJsonData(fbDo.jsonString());
      
      size_t len = jsonSemuaJatah.iteratorBegin();
      String key, value;
      int type;
      
      for (size_t i = 0; i < len; i++) {
        jsonSemuaJatah.iteratorGet(i, type, key, value);
        
        FirebaseJsonData dataStatus;
        FirebaseJsonData dataKg;
        
        jsonSemuaJatah.get(dataStatus, key + "/status");
        jsonSemuaJatah.get(dataKg, key + "/jumlah_kg");
        
        if (dataStatus.stringValue != "Sudah Diambil" && dataKg.floatValue > 0) {
          totalTargetBeras += dataKg.floatValue;
          
          String bulanPendek = key.substring(5); 
          if (teksBulanLCD != "") teksBulanLCD += ",";
          teksBulanLCD += bulanPendek;
          
          arrayBulanUpdate[jumlahBulanDiambil] = key;
          jumlahBulanDiambil++;
        }
      }
      jsonSemuaJatah.iteratorEnd();
    }
  }

  if (totalTargetBeras == 0 || jumlahBulanDiambil == 0) {
    lcd.clear();
    lcd.print("SEMUA JATAH");
    lcd.setCursor(0, 1);
    lcd.print("SUDAH DIAMBIL!");
    bipStandar(BUNYI_PERINGATAN); // 2x bunyi = info/peringatan
    delay(3000);
    resetTampilanStandby();
    return;
  }

  lcd.clear();
  lcd.print("Bln: " + teksBulanLCD);
  lcd.setCursor(0, 1);
  lcd.print("Total: " + String(totalTargetBeras, 2) + " kg");
  delay(3000);

  // -----------------------------------------------------
  // 2.5 VALIDASI KETERSEDIAAN STOK DI TANGKI UTAMA
  // -----------------------------------------------------
  lcd.clear();
  lcd.print("Memeriksa Stok...");

  float sisaBerasUtamaKgAwal = 0.0;
  float persentaseLevelAwal = 0.0;
  float jarakPantulanAwal = 0.0;
  hitungStokBeras(sisaBerasUtamaKgAwal, persentaseLevelAwal, jarakPantulanAwal);
  
  const float JARAK_SETENGAH = 8.5;

  // --- PERINGATAN STOK MENIPIS ---
  if (jarakPantulanAwal >= JARAK_SETENGAH) {
    lcd.clear();
    lcd.print("STOK MENIPIS!");
    lcd.setCursor(0, 1);
    lcd.print("MOHON ISI BERAS");
    Serial.println("\n[INFO] Peringatan: Stok beras mencapai setengah atau kurang. Mohon segera isi ulang.");
    bipStandar(BUNYI_PERINGATAN); // 2x bunyi = peringatan stok menipis
    delay(2500);
  }

  // --- PENGECEKAN KRITIS (BLOKIR TRANSAKSI) ---
  if (sisaBerasUtamaKgAwal < totalTargetBeras) {
    lcd.clear();
    lcd.print("BERAS DI TANGKI");
    lcd.setCursor(0, 1);
    lcd.print("TIDAK CUKUP!");
    
    Serial.println("\n[PERINGATAN] Transaksi dibatalkan! Isi tangki utama tidak cukup.");
    Serial.print("Jarak Sensor Aktual : "); Serial.print(jarakPantulanAwal); Serial.println(" cm");
    Serial.print("Sisa Beras di Tangki: "); Serial.print(sisaBerasUtamaKgAwal); Serial.println(" kg");
    Serial.print("Total Jatah Warga   : "); Serial.print(totalTargetBeras); Serial.println(" kg");
    
    bipStandar(BUNYI_BLOKIR); // 1x panjang = error kritis
    delay(4000);     
    
    resetTampilanStandby();
    return;
  }

  // -----------------------------------------------------
  // 3. PROSES PENIMBANGAN FISIK
  // -----------------------------------------------------
  lcd.clear();
  
  String teksAtas = "Bulan: " + teksBulanLCD;
  lcd.print(teksAtas.substring(0, 16));
  

  // =====================================================
  // CEK WADAH VIA LOAD CELL (sebelum tare per-transaksi)
  // =====================================================
  const float BERAT_MIN_WADAH = 40.0;        // gram — threshold deteksi wadah (~65g wadah, margin 40g)
  const unsigned long TIMEOUT_WADAH = 30000; // 30 detik tunggu wadah dipasang

  float bacaanAwal = fabs(scale.get_units(5)); // baca 5x rata-rata sebelum tare
  Serial.print("[CEK WADAH] Berat sebelum tare: "); Serial.print(bacaanAwal, 1); Serial.println(" gram");

  if (bacaanAwal < BERAT_MIN_WADAH) {
    // Wadah belum ada di timbangan
    lcd.clear();
    lcd.print("PASANG WADAH!");
    lcd.setCursor(0, 1);
    lcd.print("Timbangan kosong");
    bipStandar(BUNYI_PERINGATAN);
    Serial.println("[CEK WADAH] Wadah tidak terdeteksi, menunggu...");

    unsigned long waktuTunggu = millis();
    bool wadahTerdeteksi = false;
    unsigned long waktuMulaiStabil = 0;
    const unsigned long DURASI_STABIL_MS = 1500; // Stabil selama 1.5 detik

    while (millis() - waktuTunggu < TIMEOUT_WADAH) {
      float cekBerat = fabs(scale.get_units(1)); // Baca cepat 1x
      unsigned long sisa = (TIMEOUT_WADAH - (millis() - waktuTunggu)) / 1000;
      
      if (cekBerat >= BERAT_MIN_WADAH) {
        if (waktuMulaiStabil == 0) {
          waktuMulaiStabil = millis();
          Serial.println("[CEK WADAH] Berat terdeteksi, menunggu stabilitas...");
        }
        
        // Tampilkan progres stabilitas di LCD
        int progres = (millis() - waktuMulaiStabil) * 100 / DURASI_STABIL_MS;
        if (progres > 100) progres = 100;
        lcd.setCursor(0, 1);
        lcd.print("Stabilizing: " + String(progres) + "% ");
        
        if (millis() - waktuMulaiStabil >= DURASI_STABIL_MS) {
          wadahTerdeteksi = true;
          lcd.clear();
          lcd.print("Wadah STABIL");
          lcd.setCursor(0, 1);
          lcd.print(String(cekBerat, 0) + " gram   ");
          bipStandar(BUNYI_SUKSES);
          Serial.print("[CEK WADAH] Wadah STABIL! Berat: "); Serial.print(cekBerat, 1); Serial.println(" gram");
          delay(1000);
          break;
        }
      } else {
        if (waktuMulaiStabil > 0) {
          Serial.println("[CEK WADAH] Berat hilang/tidak stabil, reset timer.");
        }
        waktuMulaiStabil = 0;
        lcd.setCursor(0, 1);
        lcd.print("Tunggu: " + String(sisa) + "s        ");
      }
      delay(50); // Loop lebih cepat untuk responsivitas stabilitas
    }

    if (!wadahTerdeteksi) {
      lcd.clear();
      lcd.print("TIMEOUT! Proses");
      lcd.setCursor(0, 1);
      lcd.print("dibatalkan.");
      bipStandar(BUNYI_BLOKIR);
      Serial.println("[CEK WADAH] Timeout! Transaksi dibatalkan.");
      delay(3000);
      resetTampilanStandby();
      return;
    }
  } else {
    Serial.print("[CEK WADAH] Wadah sudah ada. Berat: "); Serial.print(bacaanAwal, 1); Serial.println(" gram");
  }

  scale.tare(); 
  katupServo.write(90); 
  
  float beratSekarang = 0;
  unsigned long batasWaktuMulai = millis();
  bool statusSukses = true;

  while (beratSekarang < totalTargetBeras) {
    float nilaiMentah = scale.get_units(2);
    beratSekarang = fabs(nilaiMentah) / 1000.0;

    static unsigned long debugTimbanganTerakhir = 0;
    if (millis() - debugTimbanganTerakhir > 500) {
      debugTimbanganTerakhir = millis();
      Serial.print("[TIMBANG] get_units()="); Serial.print(nilaiMentah, 2);
      Serial.print(" gram | fabs/1000="); Serial.print(beratSekarang, 4);
      Serial.print(" kg | Target="); Serial.print(totalTargetBeras, 2);
      Serial.println(" kg");
    }

    lcd.setCursor(0, 1);
    lcd.print(String(beratSekarang, 2) + " / " + String(totalTargetBeras) + "kg   ");

    if (millis() - batasWaktuMulai > 60000) {
      statusSukses = false;
      break;
    }
    delay(50); 
  }
  
  katupServo.write(0); 
  bipStandar(BUNYI_SUKSES); // 1x bunyi panjang = selesai menimbang

  // -----------------------------------------------------
  // 4. SINKRONISASI DATA KE FIREBASE
  // -----------------------------------------------------
  if (statusSukses) {
    lcd.clear();
    lcd.print("Menyimpan Data...");

    // A. Update status "Sudah Diambil" untuk SEMUA bulan yang di-rapel
    for (int i = 0; i < jumlahBulanDiambil; i++) {
      FirebaseJson updateJatah;
      updateJatah.add("status", "Sudah Diambil");
      updateJatah.add("diambil_pada", "2026-05-24T15:00:00Z"); 
      Firebase.RTDB.updateNode(&fbDo, pathJatah + "/" + arrayBulanUpdate[i], &updateJatah);
    }

    // B. Simpan riwayat transaksi
    FirebaseJson jsonTx;
    jsonTx.add("uid_kartu", uidKartu);
    jsonTx.add("nik", nikWarga);
    jsonTx.add("jumlah_diambil", beratSekarang);
    jsonTx.add("keterangan", "Ambil rapel bulan: " + teksBulanLCD);
    jsonTx.add("created_at", "2026-05-24T15:00:00Z");
    Firebase.RTDB.pushJSON(&fbDo, "/transaksis", &jsonTx);

    // C. Update stok alat
    float sisaBerasUtamaKg = 0.0;
    float persentaseLevel = 0.0;
    float dummyJarak = 0.0;
    hitungStokBeras(sisaBerasUtamaKg, persentaseLevel, dummyJarak);
    updateStatusDanStokAlat(sisaBerasUtamaKg, persentaseLevel);

    lcd.clear();
    lcd.print("TRANSAKSI SUKSES");
    lcd.setCursor(0, 1);
    lcd.print("AMBIL BERAS ANDA");
    bipStandar(BUNYI_SUKSES); // Konfirmasi akhir sukses
  } else {
    lcd.clear();
    lcd.print("ALAT TIMEOUT /");
    lcd.setCursor(0, 1);
    lcd.print("BERAS MACET!");
    bipStandar(BUNYI_BLOKIR); // Bunyi error panjang
  }
  
  delay(4000);
  resetTampilanStandby();
}

// =========================================================================
// 6. FUNGSI PENDUKUNG HARDWARE
// =========================================================================

// -------------------------------------------------------------------------
// proresInputPIN: Meminta warga menginput 4 digit PIN via keypad
//   Menampilkan '*' bukan angka asli untuk keamanan
// -------------------------------------------------------------------------
String proresInputPIN() {
  lcd.clear();
  lcd.print("MASUKKAN PIN:");
  String pin = "";
  lcd.setCursor(0, 1);
  
  while (pin.length() < 4) {
    char key = keypad.getKey();
    if (key >= '0' && key <= '9') {
      pin += key;
      lcd.print("*"); // Tampilkan bintang bukan angka (keamanan)
      bipBuzzer(50);
    } else if (key == '*') { 
      if (pin.length() > 0) {
        pin.remove(pin.length() - 1);
        lcd.setCursor(pin.length(), 1);
        lcd.print(" ");
        lcd.setCursor(pin.length(), 1);
      }
    }
    delay(10); 
  }
  return pin;
}

// -------------------------------------------------------------------------
// prosesGantiPIN: Memaksa warga untuk mengganti PIN default
//   Mengembalikan true jika PIN berhasil diganti, false jika gagal/batal
// -------------------------------------------------------------------------
bool prosesGantiPIN(String uidKartu) {
  int percobaan = 0;
  const int MAX_PERCOBAAN_GANTI = 3;

  while (percobaan < MAX_PERCOBAAN_GANTI) {
    // --- Langkah 1: Minta PIN baru ---
    lcd.clear();
    lcd.print("Buat PIN Baru:");
    lcd.setCursor(0, 1);
    String pinBaru = "";
    
    while (pinBaru.length() < 4) {
      char key = keypad.getKey();
      if (key >= '0' && key <= '9') {
        pinBaru += key;
        lcd.print("*");
        bipBuzzer(50);
      } else if (key == '*') {
        if (pinBaru.length() > 0) {
          pinBaru.remove(pinBaru.length() - 1);
          lcd.setCursor(pinBaru.length(), 1);
          lcd.print(" ");
          lcd.setCursor(pinBaru.length(), 1);
        }
      }
      delay(10);
    }

    // --- Langkah 2: Konfirmasi PIN baru ---
    lcd.clear();
    lcd.print("KONFIRMASI PIN:");
    lcd.setCursor(0, 1);
    String pinKonfirmasi = "";
    
    while (pinKonfirmasi.length() < 4) {
      char key = keypad.getKey();
      if (key >= '0' && key <= '9') {
        pinKonfirmasi += key;
        lcd.print("*");
        bipBuzzer(50);
      } else if (key == '*') {
        if (pinKonfirmasi.length() > 0) {
          pinKonfirmasi.remove(pinKonfirmasi.length() - 1);
          lcd.setCursor(pinKonfirmasi.length(), 1);
          lcd.print(" ");
          lcd.setCursor(pinKonfirmasi.length(), 1);
        }
      }
      delay(10);
    }

    // --- Langkah 3: Bandingkan kedua PIN ---
    if (pinBaru == pinKonfirmasi) {
      // PIN cocok, simpan ke Firebase
      if (Firebase.ready()) {
        FirebaseJson updatePin;
        updatePin.add("pin", pinBaru);
        String pathWarga = "/wargas/" + uidKartu;
        bool berhasil = Firebase.RTDB.updateNode(&fbDo, pathWarga, &updatePin);
        
        if (berhasil) {
          lcd.clear();
          lcd.print("PIN BERHASIL");
          lcd.setCursor(0, 1);
          lcd.print("DIGANTI!");
          bipStandar(BUNYI_SUKSES);
          delay(2000);
          Serial.println("[INFO] PIN warga " + uidKartu + " berhasil diperbarui.");
          return true;
        } else {
          lcd.clear();
          lcd.print("SIMPAN PIN GAGAL");
          lcd.setCursor(0, 1);
          lcd.print("Coba lagi...");
          bipStandar(BUNYI_ERROR);
          delay(2000);
          percobaan++;
        }
      } else {
        lcd.clear();
        lcd.print("GANGGUAN SERVER!");
        bipStandar(BUNYI_PERINGATAN);
        delay(2000);
        percobaan++;
      }
    } else {
      // PIN tidak cocok
      percobaan++;
      lcd.clear();
      lcd.print("PIN TIDAK SAMA!");
      lcd.setCursor(0, 1);
      if (percobaan < MAX_PERCOBAAN_GANTI) {
        lcd.print("Sisa Coba: " + String(MAX_PERCOBAAN_GANTI - percobaan));
      } else {
        lcd.print("Batas coba habis!");
      }
      bipStandar(BUNYI_ERROR);
      delay(2000);
    }
  }

  // Melebihi batas percobaan ganti PIN
  return false;
}

// -------------------------------------------------------------------------
// updateStatusDanStokAlat: Update data real-time ke Firebase
// -------------------------------------------------------------------------
void updateStatusDanStokAlat(float sisaKg, float persentase) {
  String pathAlat = "/perangkats/" + ID_ALAT;
  FirebaseJson jsonAlat;
  jsonAlat.add("sisa_stok_beras", sisaKg);
  jsonAlat.add("persentase_stok", persentase);
  jsonAlat.add("status_alat", "Online");
  
  // Gunakan Firebase server timestamp agar akurat tanpa bergantung NTP lokal
  jsonAlat.set("last_ping/.sv", "timestamp");

  Firebase.RTDB.updateNode(&fbDo, pathAlat, &jsonAlat);
}

// -------------------------------------------------------------------------
// resetTampilanStandby: Tampilkan layar standby & reset RFID reader
// -------------------------------------------------------------------------
void resetTampilanStandby() {
  rfid.PICC_HaltA();
  rfid.PCD_StopCrypto1();
  lcd.clear();
  lcd.print("   ALAT READY   ");
  lcd.setCursor(0, 1);
  lcd.print("TEMPELKAN KARTU");
}
