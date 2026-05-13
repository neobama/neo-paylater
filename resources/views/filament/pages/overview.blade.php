<x-filament-panels::page>
    <div class="space-y-6">
        @if (filled($banner))
            <div
                role="status"
                @class([
                    'flex items-start gap-3 rounded-2xl border bg-white p-4 shadow-xl sm:items-center sm:justify-between',
                    'border-emerald-200/80 shadow-emerald-900/10' => $bannerVariant === 'success',
                    'border-amber-200/80 shadow-amber-900/10' => $bannerVariant === 'warning',
                    'border-rose-200/80 shadow-rose-900/10' => $bannerVariant === 'danger',
                ])
            >
                <p class="min-w-0 flex-1 text-sm leading-snug text-slate-600 sm:text-[0.9375rem]">
                    {{ $banner }}
                </p>
                <button
                    type="button"
                    wire:click="dismissBanner"
                    class="shrink-0 rounded-full border border-slate-200/80 bg-slate-50 p-1.5 text-slate-600 transition hover:bg-slate-100 hover:text-slate-900"
                    aria-label="Tutup pesan"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        @endif

        <section class="relative overflow-hidden rounded-[32px] border border-white/70 bg-gradient-to-br from-white via-rose-50/70 to-red-100/70 p-7 shadow-[0_30px_90px_-44px_rgba(15,23,42,0.25)]">
            <div class="absolute inset-y-0 right-0 hidden w-1/2 bg-[radial-gradient(circle_at_top_right,rgba(244,63,94,0.18),transparent_52%)] lg:block"></div>

            <div class="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-2xl">
                    <span class="inline-flex rounded-full bg-rose-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-rose-700">
                        Neo Paylater
                    </span>
                    <h1 class="mt-4 text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl">
                        Ringkasan hutang dan piutang yang gampang dibaca.
                    </h1>
                    <p class="mt-3 max-w-xl text-sm leading-7 text-slate-600 sm:text-base">
                        Lihat siapa yang harus kamu bayar, siapa yang harus bayar ke kamu, lalu cek bukti bill dan itemnya langsung dari satu halaman.
                    </p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-2xl border border-white/80 bg-white/85 px-5 py-4 shadow-sm shadow-rose-100/80 backdrop-blur">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Total hutang</p>
                        <p class="mt-2 text-2xl font-semibold tracking-tight text-amber-700">
                            {{ \App\Support\Money::format($summary['total_payable']) }}
                        </p>
                    </div>

                    <div class="rounded-2xl border border-white/80 bg-white/85 px-5 py-4 shadow-sm shadow-rose-100/80 backdrop-blur">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Total piutang</p>
                        <p class="mt-2 text-2xl font-semibold tracking-tight text-rose-700">
                            {{ \App\Support\Money::format($summary['total_receivable']) }}
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-2">
        @php
            $widgets = [
                [
                    'key' => 'payable',
                    'title' => 'Hutang',
                    'description' => 'Yang perlu kamu lunasi ke teman-teman.',
                    'badge' => 'Bayar',
                    'theme' => 'amber',
                    'data' => $summary['payables_widget'],
                ],
                [
                    'key' => 'receivable',
                    'title' => 'Piutang',
                    'description' => 'Yang masih perlu dibayar teman ke kamu.',
                    'badge' => 'Tagih',
                    'theme' => 'rose',
                    'data' => $summary['receivables_widget'],
                ],
            ];
        @endphp

        @foreach ($widgets as $widget)
            <section @class([
                'rounded-[30px] border p-6 shadow-[0_26px_100px_-48px_rgba(15,23,42,0.28)] backdrop-blur',
                'border-amber-200/80 bg-[linear-gradient(180deg,rgba(255,255,255,0.96),rgba(255,251,235,0.94))]' => $widget['theme'] === 'amber',
                'border-rose-200/80 bg-[linear-gradient(180deg,rgba(255,255,255,0.98),rgba(255,241,242,0.96))]' => $widget['theme'] === 'rose',
            ])>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p @class([
                            'text-sm font-semibold uppercase tracking-[0.18em]',
                            'text-amber-700' => $widget['theme'] === 'amber',
                            'text-rose-700' => $widget['theme'] === 'rose',
                        ])>
                            {{ $widget['title'] }}
                        </p>
                        <h2 class="mt-2 text-3xl font-semibold tracking-tight text-slate-900">
                            {{ \App\Support\Money::format($widget['data']['total_amount']) }}
                        </h2>
                        <p class="mt-2 max-w-xl text-sm leading-6 text-slate-600">
                            {{ $widget['description'] }}
                        </p>
                    </div>

                    <span @class([
                        'inline-flex w-fit rounded-full px-3 py-1 text-xs font-semibold',
                        'bg-amber-100 text-amber-800' => $widget['theme'] === 'amber',
                        'bg-rose-100 text-rose-800' => $widget['theme'] === 'rose',
                    ])>
                        {{ count($widget['data']['groups']) }} orang
                    </span>
                </div>

                <div class="mt-6 space-y-4">
                    @forelse ($widget['data']['groups'] as $group)
                        <article class="rounded-2xl border border-slate-200 bg-white/95 p-4 shadow-sm shadow-slate-200/60">
                            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                <div>
                                    <div class="flex items-center gap-3">
                                        @if ($group['counterparty']->getAvatarUrl())
                                            <img
                                                src="{{ $group['counterparty']->getAvatarUrl() }}"
                                                alt="{{ $group['counterparty']->name }}"
                                                class="h-[80px] w-[80px] rounded-full object-cover ring-1 ring-white shadow-sm"
                                            >
                                        @else
                                            <div class="flex h-[80px] w-[80px] items-center justify-center rounded-full bg-gradient-to-br from-rose-500 to-red-600 text-[24px] font-semibold text-white shadow-sm">
                                                {{ $group['counterparty']->getInitials() }}
                                            </div>
                                        @endif

                                        <div>
                                            <h3 class="text-base font-semibold text-slate-900">{{ $group['counterparty']->name }}</h3>
                                            <p class="mt-1 text-sm text-slate-600">
                                                Total {{ strtolower($widget['title']) }} saat ini
                                                <span class="font-semibold text-slate-900">{{ \App\Support\Money::format($group['total_amount']) }}</span>
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end">
                                    @if ($widget['key'] === 'receivable')
                                        <button
                                            type="button"
                                            wire:click="openFullSettleModal({{ $group['counterparty']->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="openFullSettleModal({{ $group['counterparty']->id }})"
                                            class="neo-paylater-btn-emerald inline-flex min-h-9 min-w-[5.5rem] items-center justify-center rounded-full px-4 py-2 text-sm font-semibold shadow-sm ring-1 ring-emerald-700/20 transition disabled:opacity-60"
                                        >
                                            Lunasi
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="openPartialReceivable({{ $group['counterparty']->id }})"
                                            class="neo-paylater-btn-amber inline-flex min-h-9 items-center justify-center rounded-full px-4 py-2 text-sm font-semibold shadow-sm ring-1 ring-amber-500/30 transition disabled:opacity-60"
                                        >
                                            Lunasi sebagian
                                        </button>
                                    @endif

                                    <a
                                        href="{{ \App\Filament\Pages\History::getUrl(panel: 'admin') }}?counterparty={{ $group['counterparty']->id }}"
                                        class="inline-flex items-center justify-center rounded-full bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-200 hover:text-slate-900"
                                    >
                                        Lihat history lengkap
                                    </a>
                                </div>
                            </div>

                            <div class="mt-4 space-y-3">
                                @foreach ($group['entries'] as $entry)
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50/70 px-4 py-3">
                                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                            <div class="min-w-0">
                                                <p class="text-sm font-semibold text-slate-900">
                                                    {{ $entry['effective_date']->format('d M Y') }} · {{ $entry['display_name'] }}
                                                </p>
                                                <p class="mt-1 text-sm text-slate-600">
                                                    {{ $entry['bill_title'] ?? $entry['title'] }}
                                                    @if ($entry['merchant'])
                                                        · {{ $entry['merchant'] }}
                                                    @endif
                                                </p>

                                                <div class="mt-2 flex flex-wrap gap-2">
                                                    <span @class([
                                                        'inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold',
                                                        'bg-slate-200 text-slate-700' => ! $entry['is_offset'],
                                                        'bg-emerald-100 text-emerald-700' => $entry['is_offset'],
                                                    ])>
                                                        {{ $entry['is_offset'] ? 'Pengurang' : 'Menambah ' . strtolower($widget['title']) }}
                                                    </span>

                                                    @if ($entry['receipt_image_path'])
                                                        <a
                                                            href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($entry['receipt_image_path']) }}"
                                                            target="_blank"
                                                            class="inline-flex rounded-full bg-white px-2.5 py-1 text-[11px] font-semibold text-rose-700 ring-1 ring-inset ring-rose-200 transition hover:bg-rose-50"
                                                        >
                                                            Lihat receipt
                                                        </a>
                                                    @endif
                                                </div>
                                            </div>

                                            <div class="text-left sm:text-right">
                                                <p @class([
                                                    'text-sm font-semibold',
                                                    'text-slate-900' => ! $entry['is_offset'],
                                                    'text-emerald-700' => $entry['is_offset'],
                                                ])>
                                                    {{ $entry['is_offset'] ? '-' : '' }}{{ \App\Support\Money::format(abs($entry['signed_amount'])) }}
                                                </p>
                                                @if ($entry['notes'])
                                                    <p class="mt-1 text-xs leading-5 text-slate-500">{{ $entry['notes'] }}</p>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </article>
                    @empty
                        <div class="rounded-2xl border border-dashed border-slate-300 bg-white/70 px-4 py-12 text-center text-sm text-slate-500">
                            Belum ada {{ strtolower($widget['title']) }} aktif.
                        </div>
                    @endforelse
                </div>
            </section>
        @endforeach
        </div>
    </div>

    @if ($fullSettleCounterpartyId)
        <div
            class="fixed inset-0 z-[100] flex items-center justify-center p-4 sm:p-6"
            role="dialog"
            aria-modal="true"
            aria-labelledby="full-settle-title"
            wire:key="full-settle-modal"
        >
            <div
                class="absolute inset-0 bg-slate-950/50 backdrop-blur-[2px]"
                wire:click="closeFullSettleModal"
            ></div>

            <div class="relative w-full max-w-md rounded-2xl border border-amber-200/80 bg-white p-6 shadow-2xl shadow-amber-900/10">
                <h3 id="full-settle-title" class="text-lg font-semibold text-slate-900">
                    Lunasi penuh
                </h3>
                <p class="mt-1 text-sm text-slate-600">
                    Dari <span class="font-medium text-slate-900">{{ $fullSettleCounterpartyName }}</span>
                    — total <span class="font-semibold text-amber-800">{{ \App\Support\Money::format($fullSettleAmount) }}</span>.
                    Setelah dicatat, orang ini hilang dari daftar piutang di dashboard.
                </p>

                @if (filled($fullSettleError))
                    <div class="mt-3 rounded-xl border border-rose-200/80 bg-rose-50/90 px-3 py-2.5">
                        <p class="text-sm font-medium text-rose-900">{{ $fullSettleError }}</p>
                    </div>
                @endif

                <div class="mt-6 flex flex-wrap items-center justify-end gap-2">
                    <button
                        type="button"
                        wire:click="closeFullSettleModal"
                        class="inline-flex items-center justify-center rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                    >
                        Batal
                    </button>
                    <button
                        type="button"
                        wire:click="confirmFullSettle"
                        wire:loading.attr="disabled"
                        wire:target="confirmFullSettle"
                        class="neo-paylater-btn-amber-solid inline-flex min-w-[7rem] items-center justify-center rounded-full px-4 py-2 text-sm font-semibold shadow-sm transition disabled:opacity-60"
                    >
                        Catat lunas
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if ($partialCounterpartyId)
        <div
            class="fixed inset-0 z-[100] flex items-center justify-center p-4 sm:p-6"
            role="dialog"
            aria-modal="true"
            wire:key="partial-receivable-modal"
        >
            <div
                class="absolute inset-0 bg-slate-950/50 backdrop-blur-[2px]"
                wire:click="closePartialReceivable"
            ></div>

            <div class="relative w-full max-w-md rounded-2xl border border-amber-200/80 bg-white p-6 shadow-2xl shadow-amber-900/10">
                <h3 class="text-lg font-semibold text-slate-900">
                    Lunasi sebagian
                </h3>
                <p class="mt-1 text-sm text-slate-600">
                    Dari <span class="font-medium text-slate-900">{{ $partialCounterpartyName }}</span>
                    — sisa piutang maks. <span class="font-semibold text-amber-800">{{ \App\Support\Money::format($partialMaxAmount) }}</span>
                </p>

                <div class="mt-5">
                    <label for="partial-amount-input" class="block text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Nominal (Rp)
                    </label>
                    <input
                        id="partial-amount-input"
                        type="text"
                        inputmode="numeric"
                        autocomplete="off"
                        wire:model="partialAmountInput"
                        class="mt-1.5 block w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-900 shadow-inner outline-none ring-0 transition focus:border-amber-400 focus:bg-white focus:ring-2 focus:ring-amber-200"
                        placeholder="Contoh: 50000"
                    />
                </div>

                @if (filled($partialError))
                    <div class="mt-3 rounded-xl border border-rose-200/80 bg-rose-50/90 px-3 py-2.5">
                        <p class="text-sm font-medium text-rose-900">{{ $partialError }}</p>
                    </div>
                @endif

                <div class="mt-6 flex flex-wrap items-center justify-end gap-2">
                    <button
                        type="button"
                        wire:click="closePartialReceivable"
                        class="inline-flex items-center justify-center rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                    >
                        Batal
                    </button>
                    <button
                        type="button"
                        wire:click="submitPartialReceivable"
                        wire:loading.attr="disabled"
                        wire:target="submitPartialReceivable"
                        class="neo-paylater-btn-amber-solid inline-flex min-w-[7rem] items-center justify-center rounded-full px-4 py-2 text-sm font-semibold shadow-sm transition disabled:opacity-60"
                    >
                        Simpan
                    </button>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
