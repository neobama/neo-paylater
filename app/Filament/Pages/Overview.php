<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\Settlements\SettlementResource;
use App\Services\LedgerService;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class Overview extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static string | \UnitEnum | null $navigationGroup = 'Neo Paylater';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Dashboard Utama';

    protected static ?string $slug = 'overview';

    protected string $view = 'filament.pages.overview';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bills')
                ->label('Bills')
                ->url(BillResource::getUrl('index')),
            Action::make('settlements')
                ->label('Pelunasan')
                ->color('danger')
                ->url(SettlementResource::getUrl('index')),
            Action::make('history')
                ->label('Lihat history')
                ->url(History::getUrl(panel: 'admin')),
        ];
    }

    protected function getViewData(): array
    {
        $user = Auth::user();
        $summary = app(LedgerService::class)->getDashboardSummary($user);

        return [
            'user' => $user,
            'summary' => $summary,
        ];
    }
}
