<script src="https://cdn.tailwindcss.com"></script>

<div class="p-8 bg-gray-50 min-h-screen">
    <div class="max-w-6xl mx-auto">
        <h1 class="text-2xl font-bold text-gray-800 mb-6">Monitoring Perangkat Bansos</h1>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <table class="w-full text-left border-collapse">
                <thead class="bg-gray-50 border-b border-gray-100">
                    <tr>
                        <th class="px-6 py-4 text-sm font-semibold text-gray-600">ID Alat</th>
                        <th class="px-6 py-4 text-sm font-semibold text-gray-600">Kapasitas Stok</th>
                        <th class="px-6 py-4 text-sm font-semibold text-gray-600">Status</th>
                        <th class="px-6 py-4 text-sm font-semibold text-gray-600">Terakhir Aktif</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @foreach($perangkat as $item)
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="px-6 py-4">
                            <span class="font-mono text-blue-600 font-bold">{{ $item['id_alat'] }}</span>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-full bg-gray-200 rounded-full h-2.5 max-w-[100px]">
                                    <div class="bg-blue-500 h-2.5 rounded-full" style="width: {{ $item['persentase_stok'] ?? 0 }}%"></div>
                                </div>
                                <span class="text-sm font-medium text-gray-700">{{ $item['sisa_stok_beras'] ?? 0 }} Kg</span>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            @if(($item['status_alat'] ?? 'Offline') == 'Online')
                                <div class="flex items-center gap-2 text-green-600">
                                    <span class="relative flex h-3 w-3">
                                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                                        <span class="relative inline-flex rounded-full h-3 w-3 bg-green-500"></span>
                                    </span>
                                    <span class="text-sm font-bold uppercase">Online</span>
                                </div>
                            @else
                                <div class="flex items-center gap-2 text-gray-400">
                                    <span class="h-3 w-3 rounded-full bg-gray-300"></span>
                                    <span class="text-sm font-bold uppercase tracking-wide">Offline</span>
                                </div>
                            @endif
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-sm text-gray-500 italic">
                                {{ $item['updated_at_human'] ?? 'Belum pernah aktif' }}
                            </span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>