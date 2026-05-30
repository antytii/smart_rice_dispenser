<?php

namespace App\Services\Firebase;

class TransaksiService
{
    protected FirebaseService $firebase;
    protected string $path = 'transaksis';

    public function __construct(FirebaseService $firebase)
    {
        $this->firebase = $firebase;
    }

    /**
     * Catat transaksi baru (auto-generate push ID)
     */
    public function create(array $data): string
    {
        $data['waktu_ambil'] = now()->toIso8601String();

        $newRef = $this->firebase->getReference($this->path)->push($data);

        return $newRef->getKey();
    }

    /**
     * Ambil semua transaksi
     */
    public function getAll(): array
    {
        $snapshot = $this->firebase->getReference($this->path)->getSnapshot();

        if (!$snapshot->exists()) {
            return [];
        }

        $transaksis = [];
        foreach ($snapshot->getValue() as $id => $data) {
            $data['id_transaksi'] = $id;
            $transaksis[] = $data;
        }

        return $transaksis;
    }

    /**
     * Ambil transaksi berdasarkan uid_kartu
     */
    public function getByUidKartu(string $uidKartu): array
    {
        $snapshot = $this->firebase->getReference($this->path)
            ->orderByChild('uid_kartu')
            ->equalTo($uidKartu)
            ->getSnapshot();

        if (!$snapshot->exists()) {
            return [];
        }

        $transaksis = [];
        foreach ($snapshot->getValue() as $id => $data) {
            $data['id_transaksi'] = $id;
            $transaksis[] = $data;
        }

        return $transaksis;
    }
}
