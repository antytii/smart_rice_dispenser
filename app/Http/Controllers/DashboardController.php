<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\Firebase\PerangkatService;

class DashboardController extends Controller
{
    protected PerangkatService $perangkatService;

    public function __construct(PerangkatService $perangkatService)
    {
        $this->perangkatService = $perangkatService;
    }

    public function index() 
    {
        // Data perangkat sudah include status Online/Offline dari PerangkatService
        $perangkat = $this->perangkatService->getAll();

        return view('dashboard', compact('perangkat'));
    }
}