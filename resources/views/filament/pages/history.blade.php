<x-filament-panels::page>
    <div class="space-y-6">
        <section class="rounded-3xl border border-red-200 bg-white/90 p-6 shadow-sm shadow-red-100">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">{{ $historyTitle }}</h2>
                    <p class="text-sm text-gray-500">
                        Pilih teman untuk melihat bill-bill yang membentuk hutang atau piutang dengan orang tersebut.
                    </p>
                </div>

                @if ($selectedCounterpartyId)
                    <a
                        href="{{ \App\Filament\Pages\History::getUrl(panel: 'admin') }}"
                        class="inline-flex items-center justify-center rounded-full bg-red-50 px-4 py-2 text-sm font-medium text-red-600 transition hover:bg-red-100"
                    >
                        Reset filter
                    </a>
                @endif
            </div>

            <div class="mt-5 flex flex-wrap gap-2">
                <a
                    href="{{ \App\Filament\Pages\History::getUrl(panel: 'admin') }}"
                    @class([
                        'rounded-full px-4 py-2 text-sm font-medium transition',
                        'bg-red-600 text-white shadow-sm shadow-red-200' => ! $selectedCounterpartyId,
                        'bg-gray-100 text-gray-600 hover:bg-gray-200' => $selectedCounterpartyId,
                    ])
                >
                    Semua teman
                </a>

                @foreach ($counterparties as $counterpartyId => $counterpartyName)
                    <a
                        href="{{ \App\Filament\Pages\History::getUrl(panel: 'admin') }}?counterparty={{ $counterpartyId }}"
                        @class([
                            'rounded-full px-4 py-2 text-sm font-medium transition',
                            'bg-red-600 text-white shadow-sm shadow-red-200' => (int) $selectedCounterpartyId === (int) $counterpartyId,
                            'bg-gray-100 text-gray-600 hover:bg-gray-200' => (int) $selectedCounterpartyId !== (int) $counterpartyId,
                        ])
                    >
                        {{ $counterpartyName }}
                    </a>
                @endforeach
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-2">
        <section class="rounded-3xl border border-red-200 bg-white/90 p-6 shadow-sm shadow-red-100">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">Riwayat piutang</h2>
                    <p class="text-sm text-gray-500">Semua transaksi saat orang lain punya kewajiban ke kamu.</p>
                </div>
                <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">
                    {{ $receivables->count() }} entry
                </span>
            </div>

            <div class="mt-5 space-y-3">
                @forelse ($receivables as $entry)
                    <div class="rounded-2xl border border-gray-100 px-4 py-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="font-medium text-gray-900">{{ $entry['counterparty_name'] }}</p>
                                <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-gray-500">
                                    <span>{{ $entry['title'] }}</span>
                                    @if ($entry['merchant'])
                                        <span>· {{ $entry['merchant'] }}</span>
                                    @endif
                                    @if ($entry['bill_title'])
                                        <span class="rounded-full bg-red-50 px-2.5 py-1 text-xs font-medium text-red-600">
                                            Bill: {{ $entry['bill_title'] }}
                                        </span>
                                    @endif
                                    @if ($entry['receipt_image_path'])
                                        <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600">
                                            Receipt lokal
                                        </span>
                                    @endif
                                </div>
                            </div>
                            <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">
                                {{ $entry['label'] }}
                            </span>
                        </div>
                        <div class="mt-3 flex items-center justify-between text-sm">
                            <span class="text-gray-500">{{ $entry['effective_date']->format('d M Y') }}</span>
                            <span class="font-semibold text-gray-900">{{ \App\Support\Money::format($entry['amount']) }}</span>
                        </div>
                        @if ($entry['notes'])
                            <p class="mt-3 text-sm text-gray-500">{{ $entry['notes'] }}</p>
                        @endif
                    </div>
                @empty
                    <div class="rounded-2xl border border-dashed border-red-200 bg-red-50/40 px-4 py-10 text-center text-sm text-gray-500">
                        Belum ada riwayat piutang.
                    </div>
                @endforelse
            </div>
        </section>

        <section class="rounded-3xl border border-red-200 bg-white/90 p-6 shadow-sm shadow-red-100">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">Riwayat hutang</h2>
                    <p class="text-sm text-gray-500">Semua transaksi saat kamu punya kewajiban ke orang lain.</p>
                </div>
                <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700">
                    {{ $payables->count() }} entry
                </span>
            </div>

            <div class="mt-5 space-y-3">
                @forelse ($payables as $entry)
                    <div class="rounded-2xl border border-gray-100 px-4 py-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="font-medium text-gray-900">{{ $entry['counterparty_name'] }}</p>
                                <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-gray-500">
                                    <span>{{ $entry['title'] }}</span>
                                    @if ($entry['merchant'])
                                        <span>· {{ $entry['merchant'] }}</span>
                                    @endif
                                    @if ($entry['bill_title'])
                                        <span class="rounded-full bg-red-50 px-2.5 py-1 text-xs font-medium text-red-600">
                                            Bill: {{ $entry['bill_title'] }}
                                        </span>
                                    @endif
                                    @if ($entry['receipt_image_path'])
                                        <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600">
                                            Receipt lokal
                                        </span>
                                    @endif
                                </div>
                            </div>
                            <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700">
                                {{ $entry['label'] }}
                            </span>
                        </div>
                        <div class="mt-3 flex items-center justify-between text-sm">
                            <span class="text-gray-500">{{ $entry['effective_date']->format('d M Y') }}</span>
                            <span class="font-semibold text-gray-900">{{ \App\Support\Money::format($entry['amount']) }}</span>
                        </div>
                        @if ($entry['notes'])
                            <p class="mt-3 text-sm text-gray-500">{{ $entry['notes'] }}</p>
                        @endif
                    </div>
                @empty
                    <div class="rounded-2xl border border-dashed border-red-200 bg-red-50/40 px-4 py-10 text-center text-sm text-gray-500">
                        Belum ada riwayat hutang.
                    </div>
                @endforelse
            </div>
        </section>
        </div>
    </div>
</x-filament-panels::page>
