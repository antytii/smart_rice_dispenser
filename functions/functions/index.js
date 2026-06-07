/**
 * ================================================================
 * Firebase Cloud Functions — Smart Rice Dispenser
 * ================================================================
 * 
 * Fungsi ini berperan sebagai "kurir" yang otomatis mendeteksi
 * perubahan data di Firebase Realtime Database, lalu mendorongnya
 * (PUSH) ke endpoint webhook Laravel di server VPS.
 * 
 * Alur: ESP32 → Firebase RTDB → Cloud Functions → Laravel Webhook → MySQL
 * 
 * PENTING: Ganti LARAVEL_WEBHOOK_URL dengan URL server Laravel Anda.
 * ================================================================
 */

const { onValueWritten, onValueCreated } = require("firebase-functions/v2/database");
const { setGlobalOptions } = require("firebase-functions/v2");
const admin = require("firebase-admin");

admin.initializeApp();

setGlobalOptions({ maxInstances: 10 });

// ============================================================
// KONFIGURASI — SESUAIKAN DENGAN SERVER ANDA
// ============================================================
const LARAVEL_WEBHOOK_URL = "https://6f05-182-2-235-217.ngrok-free.app/api/webhook/sensor-data";
const WEBHOOK_SECRET = "smart-rice-webhook-secret-2026";

// ============================================================
// HELPER: Kirim data ke Laravel webhook
// ============================================================
async function pushToLaravel(event, data) {
  try {
    const response = await fetch(LARAVEL_WEBHOOK_URL, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-Webhook-Secret": WEBHOOK_SECRET,
      },
      body: JSON.stringify({ event, data }),
    });

    if (!response.ok) {
      const errorText = await response.text();
      console.error(`[WEBHOOK ERROR] ${event}: HTTP ${response.status} — ${errorText}`);
    } else {
      console.log(`[WEBHOOK OK] ${event}: Data berhasil dikirim ke Laravel.`);
    }
  } catch (error) {
    console.error(`[WEBHOOK FATAL] ${event}: ${error.message}`);
  }
}

// ============================================================
// TRIGGER 1: Transaksi Baru
// ============================================================
exports.onTransaksiBaru = onValueCreated(
  { ref: "/transaksis/{pushId}", instance: "iot-smartrice-default-rtdb", region: "asia-southeast1" },
  (event) => {
    const data = event.data.val();
    if (!data) return null;

    const pushId = event.params.pushId;
    console.log(`[TRIGGER] Transaksi baru terdeteksi: ${pushId}`);

    return pushToLaravel("transaksi_baru", {
      firebase_key: pushId,
      uid_kartu: data.uid_kartu || "",
      nik: data.nik || "",
      jumlah_diambil: data.jumlah_diambil || 0,
      keterangan: data.keterangan || "",
      created_at: data.created_at || new Date().toISOString(),
    });
  }
);

// ============================================================
// TRIGGER 2: Update Perangkat (Heartbeat ESP32)
// ============================================================
exports.onPerangkatUpdate = onValueWritten(
  { ref: "/perangkats/{idAlat}", instance: "iot-smartrice-default-rtdb", region: "asia-southeast1" },
  (event) => {
    const data = event.data.after.val();
    if (!data) return null;

    const idAlat = event.params.idAlat;
    console.log(`[TRIGGER] Perangkat diupdate: ${idAlat}`);

    return pushToLaravel("perangkat_update", {
      id_alat: idAlat,
      sisa_stok_beras: data.sisa_stok_beras || 0,
      persentase_stok: data.persentase_stok || 0,
      status_alat: data.status_alat || "Online",
      last_ping: data.last_ping || new Date().toISOString(),
    });
  }
);

// ============================================================
// TRIGGER 3: Update Jatah Warga
// ============================================================
exports.onJatahUpdate = onValueWritten(
  { ref: "/jatah_wargas/{uid}/{periode}", instance: "iot-smartrice-default-rtdb", region: "asia-southeast1" },
  (event) => {
    const data = event.data.after.val();
    if (!data) return null;

    const uid = event.params.uid;
    const periode = event.params.periode;
    console.log(`[TRIGGER] Jatah diupdate: ${uid} / ${periode}`);

    return pushToLaravel("jatah_update", {
      uid_kartu: uid,
      periode_bulan: periode,
      jumlah_kg: data.jumlah_kg || 10,
      status: data.status || "Belum Diambil",
      diambil_pada: data.diambil_pada || null,
    });
  }
);
