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

const functions = require("firebase-functions");
const admin = require("firebase-admin");
const fetch = require("node-fetch");

admin.initializeApp();

// ============================================================
// KONFIGURASI — SESUAIKAN DENGAN SERVER ANDA
// ============================================================
// URL webhook Laravel di VPS (gunakan IP publik atau domain)
// Untuk development lokal, gunakan ngrok atau sejenisnya
const LARAVEL_WEBHOOK_URL = "https://smart-rice-dispenser.syahkty.dev/api/webhook/sensor-data";

// Secret yang sama dengan WEBHOOK_SECRET di file .env Laravel
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
// Ketika ESP32 push data transaksi baru ke /transaksis/{pushId}
exports.onTransaksiBaru = functions.database
  .ref("/transaksis/{pushId}")
  .onCreate((snapshot, context) => {
    const data = snapshot.val();
    if (!data) return null;

    console.log(`[TRIGGER] Transaksi baru terdeteksi: ${context.params.pushId}`);

    return pushToLaravel("transaksi_baru", {
      firebase_key: context.params.pushId,
      uid_kartu: data.uid_kartu || "",
      nik: data.nik || "",
      jumlah_diambil: data.jumlah_diambil || 0,
      keterangan: data.keterangan || "",
      created_at: data.created_at || new Date().toISOString(),
    });
  });

// ============================================================
// TRIGGER 2: Update Perangkat (Heartbeat ESP32)
// ============================================================
// Ketika ESP32 update stok/status di /perangkats/{idAlat}
exports.onPerangkatUpdate = functions.database
  .ref("/perangkats/{idAlat}")
  .onWrite((change, context) => {
    const data = change.after.val();
    if (!data) return null; // Node dihapus

    console.log(`[TRIGGER] Perangkat diupdate: ${context.params.idAlat}`);

    return pushToLaravel("perangkat_update", {
      id_alat: context.params.idAlat,
      sisa_stok_beras: data.sisa_stok_beras || 0,
      persentase_stok: data.persentase_stok || 0,
      status_alat: data.status_alat || "Online",
      last_ping: data.last_ping || new Date().toISOString(),
    });
  });

// ============================================================
// TRIGGER 3: Update Jatah Warga
// ============================================================
// Ketika status jatah berubah di /jatah_wargas/{uid}/{periode}
exports.onJatahUpdate = functions.database
  .ref("/jatah_wargas/{uid}/{periode}")
  .onWrite((change, context) => {
    const data = change.after.val();
    if (!data) return null; // Node dihapus

    console.log(`[TRIGGER] Jatah diupdate: ${context.params.uid} / ${context.params.periode}`);

    return pushToLaravel("jatah_update", {
      uid_kartu: context.params.uid,
      periode_bulan: context.params.periode,
      jumlah_kg: data.jumlah_kg || 10,
      status: data.status || "Belum Diambil",
      diambil_pada: data.diambil_pada || null,
    });
  });
