#include <Arduino.h>
#include <WiFi.h>
#include "Firebase_ESP_Client.h"
#include <MFRC522.h>
#include <Keypad.h>
#include <HX711.h>
#include <ESP32Servo.h>
#include <LiquidCrystal_I2C.h>
#include <time.h>

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

// Deklarasi fungsi pendukung agar tidak error saat kompilasi
void resetTampilanStandby();
void bipBuzzer(int durasi) {
  digitalWrite(PIN_BUZZER, HIGH);
  delay(durasi);
  digitalWrite(PIN_BUZZER, LOW);
}
String proresInputPIN();
void updateStatusDanStokAlat(float sisaKg, float persentase);

// Variable global untuk heartbeat berkala
unsigned long lastHeartbeatTime = 0;
const unsigned long HEARTBEAT_INTERVAL = 5000; // 1 detik

// Fungsi pendukung untuk membaca sensor ultrasonik dan menghitung sisa stok beras
void hitungStokBeras(float &sisaKg, float &persentase, float &jarakOut) {
  digitalWrite(PIN_TRIG, LOW);
  delayMicroseconds(2);
  digitalWrite(PIN_TRIG, HIGH);
  delayMicroseconds(10);
  digitalWrite(PIN_TRIG, LOW);
  
  long durasi = pulseIn(PIN_ECHO, HIGH);
  float jarak = durasi * 0.034 / 2;
  jarakOut = jarak; // Kembalikan jarak asli
  
  const float JARAK_KOSONG = 13.7;
  const float JARAK_PENUH = 4.9;
  const float KAPASITAS_MAKSIMAL = 1.0; // Kapasitas 1 kg
  
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

  // Teks diagnostik ini akan muncul di Serial Monitor
  rfid.PCD_DumpVersionToSerial();

  // ---> TAMBAHKAN BARIS INI UNTUK MENGAKALI CHIP CLONE <---
  rfid.PCD_SetAntennaGain(rfid.RxGain_max);

  scale.begin(PIN_DOUT, PIN_SCK);
  
  // --- FITUR BARU: PENGECEKAN STATUS LOAD CELL ---
  Serial.print("Mengecek Load Cell (HX711)... ");
  if (scale.is_ready()) {
    Serial.println("[SUKSES] Load Cell Siap dan Terbaca Tanpa Masalah!");
  } else {
    Serial.println("[ERROR] Load Cell Tidak Merespon! Cek kabel DOUT dan SCK.");
  }
  // -----------------------------------------------

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

  // Tambahkan kode di bawah ini untuk tes koneksi awal ke database
  lcd.clear();
  lcd.print("Cek Firebase...");
  Serial.println("\nMengecek koneksi ke Firebase Realtime Database...");

  // Kita coba ambil atau tes fungsi ready untuk memastikan komunikasi aman
  unsigned long timeout = millis();
  while (!Firebase.ready()) {
    delay(500);
    Serial.print("#");
    // Jika dalam 10 detik tidak ready, kita paksa keluar agar tahu error-nya
    if (millis() - timeout > 10000) {
      break;
    }
  }

  // Cek apakah Firebase siap atau memunculkan error rahasia
  if (Firebase.ready()) {
    Serial.println("\n[SUKSES] Terhubung ke Firebase dengan Aman!");
    lcd.clear();
    lcd.print("Firebase OK!");
    delay(1500);
  } else {
    // Di sini sistem akan membongkar alasan kenapa Firebase Anda menolak koneksi
    Serial.print("\n[ERROR] Gagal Konek Firebase. Alasan: ");
    Serial.println(fbDo.errorReason().c_str());
    
    lcd.clear();
    lcd.print("Firebase Error!");
    lcd.setCursor(0, 1);
    lcd.print("Cek Serial Mon");
    
    // Biarkan pesan error terbaca selama 4 detik sebelum masuk menu utama
    delay(4000); 
  }

  resetTampilanStandby();
}

// =========================================================================
// 5. LOGIKA UTAMA PERANGKAT (LOOPING)
// =========================================================================
void loop() {
  if (WiFi.status() != WL_CONNECTED) return;

  // ========================================================
  // TAMBAHAN DEBUGGING REAL-TIME TIMBANGAN (RAW ADC)
  // ========================================================
  static unsigned long waktuDebugTerakhir = 0;
  if (millis() - waktuDebugTerakhir > 1000) { // Cetak setiap 1000 ms (1 detik)
    waktuDebugTerakhir = millis();
    
    if (scale.is_ready()) {
      long nilaiMentahRealtime = scale.read(); // Baca murni dari hardware
      Serial.print("[REAL-TIME] Nilai Mentah (RAW) Timbangan: ");
      Serial.println(nilaiMentahRealtime);
    } else {
      Serial.println("[REAL-TIME] ERROR: Kabel HX711 terputus/longgar!");
    }
  }
  // ========================================================

  // Heartbeat berkala untuk update status & stok ke Firebase (agar status Online/stok terupdate berkala)
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
  
  bipBuzzer(100);
  lcd.clear();
  lcd.print("KARTU COCOK");
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
        lcd.clear();
        lcd.print("KARTU TIDAK");
        lcd.setCursor(0, 1);
        lcd.print("TERDAFTAR!");
        bipBuzzer(1000);
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
      lcd.clear();
      lcd.print("KARTU TIDAK");
      lcd.setCursor(0, 1);
      lcd.print("TERDAFTAR!");
      bipBuzzer(1000);
      delay(2000);
      resetTampilanStandby();
      return;
    }
  } else {
    lcd.clear();
    lcd.print("GANGGUAN SERVER!");
    delay(2000);
    resetTampilanStandby();
    return;
  }

// ==========================================
  // LANJUT KE PROSES VALIDASI PIN
  // ==========================================
  // Validasi Teks Layar
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
      break; // PIN cocok, langsung keluar dari pengulangan
    } else {
      batasSalah++; // Tambah hitungan salah
      lcd.clear();
      lcd.print("PIN ANDA SALAH!");
      
      if (batasSalah < 3) {
        lcd.setCursor(0, 1);
        lcd.print("Sisa Coba: " + String(3 - batasSalah));
        bipBuzzer(1000);
        delay(2000);
        // Otomatis berputar ke atas lagi dan memanggil proresInputPIN() ulang
      } else {
        lcd.setCursor(0, 1);
        lcd.print("AKSES DITOLAK!");
        bipBuzzer(2000); // Bunyi lebih panjang tanda terblokir
        delay(2500);
      }
    }
  }

  // Jika sampai 3 kali salah dan pinBenar tetap false, batalkan proses
  if (!pinBenar) {
    resetTampilanStandby();
    return;
  }


  // -----------------------------------------------------
  // 2. CEK & AKUMULASI JATAH BERAS (SISTEM RAPEL)
  // -----------------------------------------------------
  float totalTargetBeras = 0.0;
  String teksBulanLCD = ""; // Untuk menyimpan teks bulan, misal: "04,05"
  String arrayBulanUpdate[12]; // Menyimpan key bulan untuk di-update nanti
  int jumlahBulanDiambil = 0;

  lcd.clear();
  lcd.print("Cek Jatah...");

  // Ambil SEMUA data bulan di dalam node jatah_warga/UID
  if (Firebase.RTDB.getJSON(&fbDo, pathJatah)) {
    if (fbDo.dataType() != "null") {
      FirebaseJson jsonSemuaJatah;
      jsonSemuaJatah.setJsonData(fbDo.jsonString());
      
      // Looping untuk membedah setiap bulan yang ada di database
      size_t len = jsonSemuaJatah.iteratorBegin();
      String key, value;
      int type;
      
      for (size_t i = 0; i < len; i++) {
        jsonSemuaJatah.iteratorGet(i, type, key, value);
        // 'key' berisi nama bulan, contoh: "2026-04"
        
        FirebaseJsonData dataStatus;
        FirebaseJsonData dataKg;
        
        jsonSemuaJatah.get(dataStatus, key + "/status");
        jsonSemuaJatah.get(dataKg, key + "/jumlah_kg");
        
        // Jika statusnya bukan "Sudah Diambil" dan jatahnya lebih dari 0 kg
        if (dataStatus.stringValue != "Sudah Diambil" && dataKg.floatValue > 0) {
          totalTargetBeras += dataKg.floatValue; // Tambahkan ke total akumulasi
          
          // Ambil 2 digit terakhir dari tahun-bulan (misal "2026-05" jadi "05")
          String bulanPendek = key.substring(5); 
          if (teksBulanLCD != "") teksBulanLCD += ",";
          teksBulanLCD += bulanPendek;
          
          // Simpan key lengkap untuk sinkronisasi update nanti
          arrayBulanUpdate[jumlahBulanDiambil] = key;
          jumlahBulanDiambil++;
        }
      }
      jsonSemuaJatah.iteratorEnd();
    }
  }

  // Jika tidak ada bulan yang bisa diambil (atau sudah diambil semua)
  if (totalTargetBeras == 0 || jumlahBulanDiambil == 0) {
    lcd.clear();
    lcd.print("SEMUA JATAH");
    lcd.setCursor(0, 1);
    lcd.print("SUDAH DIAMBIL!");
    delay(3000);
    resetTampilanStandby();
    return;
  }

  // Tampilkan akumulasi di LCD
  lcd.clear();
  lcd.print("Bln: " + teksBulanLCD); // Contoh output: "Bln: 04,05"
  lcd.setCursor(0, 1);
  lcd.print("Total: " + String(totalTargetBeras, 2) + " kg"); // Contoh: "Total: 20 kg"
  delay(3000);

 // -----------------------------------------------------
  // 2.5 VALIDASI KETERSEDIAAN STOK DI TANGKI UTAMA (FITUR PENGAMAN)
  // -----------------------------------------------------
  lcd.clear();
  lcd.print("Memeriksa Stok...");

  float sisaBerasUtamaKgAwal = 0.0;
  float persentaseLevelAwal = 0.0;
  float jarakPantulanAwal = 0.0;
  hitungStokBeras(sisaBerasUtamaKgAwal, persentaseLevelAwal, jarakPantulanAwal);
  
  const float JARAK_SETENGAH = 8.5;  // Titik tengah dimana peringatan tambah beras muncul 

  // --- FITUR BARU: PERINGATAN STOK MENIPIS ---
  // Jika jarak sudah mencapai 8.5 cm atau lebih (artinya beras makin turun/kosong)
  if (jarakPantulanAwal >= JARAK_SETENGAH) {
    lcd.clear();
    lcd.print("STOK MENIPIS!");
    lcd.setCursor(0, 1);
    lcd.print("MOHON ISI BERAS");
    
    // Laporan ke Serial Monitor untuk Admin
    Serial.println("\n[INFO] Peringatan: Stok beras mencapai setengah atau kurang. Mohon segera isi ulang.");
    
    // Bunyikan buzzer 2 kali cepat sebagai peringatan dini
    bipBuzzer(150); delay(100); bipBuzzer(150);
    delay(2500); // Beri waktu agar tulisan terbaca oleh petugas/warga
  }

  // --- PENGECEKAN KRITIS (BLOKIR TRANSAKSI) ---
  // Jika isi tangki ternyata lebih sedikit daripada jatah yang mau dikeluarkan
  if (sisaBerasUtamaKgAwal < totalTargetBeras) {
    lcd.clear();
    lcd.print("BERAS DI TANGKI");
    lcd.setCursor(0, 1);
    lcd.print("TIDAK CUKUP!");
    
    // Kirim laporan detail ke Serial Monitor
    Serial.println("\n[PERINGATAN] Transaksi dibatalkan! Isi tangki utama tidak cukup.");
    Serial.print("Jarak Sensor Aktual : "); Serial.print(jarakPantulanAwal); Serial.println(" cm");
    Serial.print("Sisa Beras di Tangki: "); Serial.print(sisaBerasUtamaKgAwal); Serial.println(" kg");
    Serial.print("Total Jatah Warga   : "); Serial.print(totalTargetBeras); Serial.println(" kg");
    
    bipBuzzer(1500); // Buzzer berbunyi panjang sebagai tanda error stok macet
    delay(4000);     
    
    resetTampilanStandby();
    return; // Batalkan transaksi dan kembali ke posisi standby awal
  }
  // -----------------------------------------------------
  // -----------------------------------------------------
  // 3. PROSES PENIMBANGAN FISIK
  // -----------------------------------------------------
  lcd.clear();
  
  // Tampilkan teks bulan yang diambil di baris pertama secara permanen selama menimbang
  // Akan muncul teks seperti: "Bulan: 04,05"
  String teksAtas = "Bulan: " + teksBulanLCD;
  lcd.print(teksAtas.substring(0, 16)); // Dibatasi 16 karakter agar LCD tidak error/geser
  
  scale.tare(); 
  katupServo.write(90); 
  
  float beratSekarang = 0;
  unsigned long batasWaktuMulai = millis();
  bool statusSukses = true;

  while (beratSekarang < totalTargetBeras) {
    float nilaiMentah = scale.get_units(2); // Ini bisa negatif jika kalibrasi negatif
    beratSekarang = fabs(nilaiMentah) / 1000.0; // fabs() = nilai absolut, aman untuk kalibrasi + atau -

    // Debug: cetak ke Serial setiap 500ms agar bisa dipantau
    static unsigned long debugTimbanganTerakhir = 0;
    if (millis() - debugTimbanganTerakhir > 500) {
      debugTimbanganTerakhir = millis();
      Serial.print("[TIMBANG] get_units()="); Serial.print(nilaiMentah, 2);
      Serial.print(" gram | fabs/1000="); Serial.print(beratSekarang, 4);
      Serial.print(" kg | Target="); Serial.print(totalTargetBeras, 2);
      Serial.println(" kg");
    }

    // Tampilkan pergerakan berat beras di baris kedua
    lcd.setCursor(0, 1);
    lcd.print(String(beratSekarang, 2) + " / " + String(totalTargetBeras) + "kg   ");

    if (millis() - batasWaktuMulai > 60000) {
      statusSukses = false;
      break;
    }
    delay(50); 
  }
  
  katupServo.write(0); 
  bipBuzzer(500);

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

    // B. Simpan riwayat transaksi (1 log untuk semua rapelan)
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
  } else {
    lcd.clear();
    lcd.print("ALAT TIMEOUT /");
    lcd.setCursor(0, 1);
    lcd.print("BERAS MACET!");
  }
  
  delay(4000);
  resetTampilanStandby();
}

// =========================================================================
// 6. FUNGSI PENDUKUNG HARDWARE
// =========================================================================
String proresInputPIN() {
  lcd.clear();
  lcd.print("MASUKKAN PIN:");
  String pin = "";
  lcd.setCursor(0, 1);
  
  while (pin.length() < 4) {
    char key = keypad.getKey();
    if (key >= '0' && key <= '9') {
      pin += key;
      lcd.print(key);
      bipBuzzer(50);
    } else if (key == '*') { 
      if (pin.length() > 0) {
        pin.remove(pin.length() - 1);
        lcd.setCursor(pin.length(), 1);
        lcd.print(" ");
        lcd.setCursor(pin.length(), 1);
      }
    }
    
    // WAJIB DITAMBAHKAN: Memberi napas pada ESP32 agar tidak freeze
    delay(10); 
  }
  return pin;
}

void updateStatusDanStokAlat(float sisaKg, float persentase) {
  // Fungsi pembaruan data real-time sisa stok ke node /perangkats/{id_alat}
  String pathAlat = "/perangkats/" + ID_ALAT;
  FirebaseJson jsonAlat;
  jsonAlat.add("sisa_stok_beras", sisaKg);
  jsonAlat.add("persentase_stok", persentase);
  jsonAlat.add("status_alat", "Online");
  
  // Gunakan Firebase server timestamp agar akurat tanpa bergantung NTP lokal
  jsonAlat.set("last_ping/.sv", "timestamp");

  Firebase.RTDB.updateNode(&fbDo, pathAlat, &jsonAlat);
}

void resetTampilanStandby() {
  rfid.PICC_HaltA();
  rfid.PCD_StopCrypto1();
  lcd.clear();
  lcd.print("   ALAT READY   ");
  lcd.setCursor(0, 1);
  lcd.print("TEMPELKAN KARTU");
}