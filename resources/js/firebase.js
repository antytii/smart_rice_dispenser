import { initializeApp, getApps, getApp } from 'firebase/app';
import { getDatabase } from 'firebase/database';

export function getFirebaseDb(databaseUrl) {
    if (!databaseUrl) return null;
    try {
        let app;
        if (getApps().length === 0) {
            app = initializeApp({
                databaseURL: databaseUrl
            });
        } else {
            app = getApp();
        }
        return getDatabase(app);
    } catch (e) {
        console.error("Firebase initialization failed:", e);
        return null;
    }
}
