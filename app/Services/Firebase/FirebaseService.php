<?php

namespace App\Services\Firebase;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Contract\Database;

class FirebaseService
{
    protected Database $database;

    public function __construct()
    {
        $factory = (new Factory)
            ->withServiceAccount(config('firebase.credentials.file'))
            ->withDatabaseUri(config('firebase.database.url'));

        $this->database = $factory->createDatabase();
    }

    /**
     * Mendapatkan instance Database
     */
    public function getDatabase(): Database
    {
        return $this->database;
    }

    /**
     * Mendapatkan reference ke path tertentu
     */
    public function getReference(string $path)
    {
        return $this->database->getReference($path);
    }
}
