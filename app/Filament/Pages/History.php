<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Bills\BillResource;
use App\Services\LedgerService;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class History extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'History';

    protected static string | \UnitEnum | null $navigationGroup = 'Neo Paylater';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Riwayat Hutang & Piutang';

    protected static ?string $slug = 'history';

    protected string $view = 'filament.pages.history';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('overview')
                ->label('Kembali ke dashboard')
                ->url(Overview::getUrl(panel: 'admin')),
            Action::make('bills')
                ->label('Lihat bills')
                ->color('danger')
                ->url(BillResource::getUrl('index')),
        ];
    }

    protected function getViewData(): array
    {
        $user = Auth::user();
        $selectedCounterpartyId = request()->integer('counterparty') ?: null;
        $ledgerService = app(LedgerService::class);
        $history = $ledgerService->getHistory($user, $selectedCounterpartyId);
        $counterparties = $ledgerService->getCounterpartyOptions($user);
        $selectedCounterpartyName = $selectedCounterpartyId
            ? ($counterparties[$selectedCounterpartyId] ?? null)
            : null;

        return [
            'counterparties' => $counterparties,
            'selectedCounterpartyId' => $selectedCounterpartyId,
            'selectedCounterpartyName' => $selectedCounterpartyName,
            'payables' => $history->where('direction', 'payable')->values(),
            'receivables' => $history->where('direction', 'receivable')->values(),
            'historyTitle' => $selectedCounterpartyName
                ? 'History dengan ' . Str::title($selectedCounterpartyName)
                : 'Riwayat semua teman',
        ];
    }
}
