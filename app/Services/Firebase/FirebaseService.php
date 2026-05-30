<?php

namespace App\Services\Firebase;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Contract\Database;

class FirebaseService
{
    protected Database $database;

    public function __construct()
    {
        $factory = (new Factory())
            ->withServiceAccount(base_path(config('firebase.credentials')))
            ->withDatabaseUri(config('firebase.database_url'));

        $this->database = $factory->createDatabase();
    }

    /**
     * Ambil referensi ke path tertentu di Firebase Realtime Database
     */
    public function ref(string $path): \Kreait\Firebase\Database\Reference
    {
        return $this->database->getReference($path);
    }

    /**
     * Ambil data dari path tertentu (return array atau null)
     */
    public function get(string $path): mixed
    {
        return $this->database->getReference($path)->getValue();
    }

    /**
     * Simpan data ke path tertentu (set/overwrite)
     */
    public function set(string $path, mixed $data): void
    {
        $this->database->getReference($path)->set($data);
    }

    /**
     * Update sebagian field di path tertentu
     */
    public function update(string $path, array $data): void
    {
        $this->database->getReference($path)->update($data);
    }

    /**
     * Hapus node di path tertentu
     */
    public function delete(string $path): void
    {
        $this->database->getReference($path)->remove();
    }

    /**
     * Push data baru ke path (auto-generate key)
     */
    public function push(string $path, mixed $data): string
    {
        $newRef = $this->database->getReference($path)->push($data);
        return $newRef->getKey();
    }
}
