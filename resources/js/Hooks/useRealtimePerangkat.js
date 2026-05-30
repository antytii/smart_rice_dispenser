import React from 'react';
import { usePage } from '@inertiajs/react';
import { getFirebaseDb } from '../firebase';
import { ref, onValue } from 'firebase/database';

export function useRealtimePerangkat(initialPerangkat = []) {
    const { firebaseDatabaseUrl } = usePage().props;
    const [perangkatData, setPerangkatData] = React.useState({});
    const [tick, setTick] = React.useState(0);

    // Mengubah data awal array menjadi object map
    React.useEffect(() => {
        const initialObj = {};
        initialPerangkat.forEach(item => {
            const { id_alat, ...rest } = item;
            initialObj[id_alat] = rest;
        });
        setPerangkatData(initialObj);
    }, [initialPerangkat]);

    // Listener Firebase
    React.useEffect(() => {
        const db = getFirebaseDb(firebaseDatabaseUrl);
        if (!db) return;

        const perangkatRef = ref(db, 'perangkats');
        const unsubscribe = onValue(perangkatRef, (snapshot) => {
            const data = snapshot.val();
            if (data) {
                setPerangkatData(data);
            } else {
                setPerangkatData({});
            }
        }, (error) => {
            console.error("Firebase database listener failed:", error);
        });

        return () => unsubscribe();
    }, [firebaseDatabaseUrl]);

    // Timer lokal untuk memicu re-render / re-evaluasi status setiap 3 detik
    React.useEffect(() => {
        const interval = setInterval(() => {
            setTick(t => t + 1);
        }, 3000);
        return () => clearInterval(interval);
    }, []);

    // Format data dengan status_alat terbaru secara real-time
    const formattedPerangkat = React.useMemo(() => {
        return Object.entries(perangkatData).map(([idAlat, item]) => {
            let status = item.status_alat || 'Offline';
            if (item.last_ping) {
                const lastPing = new Date(item.last_ping);
                const now = new Date();
                // Jika last ping > 30 detik lalu, ubah ke Offline secara real-time
                if ((now - lastPing) / 1000 >= 30) {
                    status = 'Offline';
                }
            }
            return {
                id_alat: idAlat,
                ...item,
                status_alat: status
            };
        });
    }, [perangkatData, tick]);

    return formattedPerangkat;
}
