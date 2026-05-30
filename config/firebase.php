<?php

declare(strict_types=1);

return [
    /*
     * ------------------------------------------------------------------------
     * Firebase Credentials
     * ------------------------------------------------------------------------
     *
     * Path ke file Service Account JSON dari Firebase Console.
     * Download dari: Firebase Console > Project Settings > Service Accounts
     *
     */
    'credentials' => [
        'file' => env('FIREBASE_CREDENTIALS', storage_path('app/firebase/service-account.json')),
    ],

    /*
     * ------------------------------------------------------------------------
     * Firebase Realtime Database URL
     * ------------------------------------------------------------------------
     *
     * URL Realtime Database kamu.
     * Contoh: https://your-project-id-default-rtdb.asia-southeast1.firebasedatabase.app
     *
     */
    'database' => [
        'url' => env('FIREBASE_DATABASE_URL'),
    ],
];
