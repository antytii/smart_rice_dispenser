<?php

namespace App\Services\Firebase;

class WargaService
{
    protected FirebaseService $firebase;
    protected string $path = 'wargas';

    public function __construct(FirebaseService $firebase)
    {
        $this->firebase = $firebase;
    }

    /**
     * Cari warga berdasarkan UID kartu (primary key)
     */
    public function findByUid(string $uidKartu): ?array
    {
        $snapshot = $this->firebase->getReference("{$this->path}/{$uidKartu}")->getSnapshot();

        if (!$snapshot->exists()) {
            return null;
        }

        $data = $snapshot->getValue();
        $data['uid_kartu'] = $uidKartu; // Sertakan key sebagai field
        return $data;
    }

    /**
     * Ambil semua data warga
     */
    public function getAll(): array
    {
        $snapshot = $this->firebase->getReference($this->path)->getSnapshot();

        if (!$snapshot->exists()) {
            return [];
        }

        $wargas = [];
        foreach ($snapshot->getValue() as $uid => $data) {
            $data['uid_kartu'] = $uid;
            $wargas[] = $data;
        }

        return $wargas;
    }

    /**
     * Buat data warga baru
     */
    public function create(string $uidKartu, array $data): void
    {
        $this->firebase->getReference("{$this->path}/{$uidKartu}")->set($data);
    }

    /**
     * Update data warga
     */
    public function update(string $uidKartu, array $data): void
    {
        $this->firebase->getReference("{$this->path}/{$uidKartu}")->update($data);
    }

    /**
     * Hapus data warga
     */
    public function delete(string $uidKartu): void
    {
        $this->firebase->getReference("{$this->path}/{$uidKartu}")->remove();
    }
}
