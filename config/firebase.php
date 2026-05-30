<?php

declare(strict_types=1);

return [
    /*
     * Path ke file Service Account JSON dari Firebase Console.
     * Letakkan file di: storage/app/firebase/service-account.json
     */
    'credentials' => env('FIREBASE_CREDENTIALS', 'storage/app/firebase/service-account.json'),

    /*
     * URL Firebase Realtime Database project kamu.
     * Contoh: https://nama-project-default-rtdb.asia-southeast1.firebasedatabase.app
     */
    'database_url' => env('FIREBASE_DATABASE_URL', ''),
];
