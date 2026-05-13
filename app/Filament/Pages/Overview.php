<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\Settlements\SettlementResource;
use App\Services\LedgerService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

class Overview extends Page
{
    public ?int $partialCounterpartyId = null;

    public string $partialCounterpartyName = '';

    public int $partialMaxAmount = 0;

    public string $partialAmountInput = '';

    public ?int $fullSettleCounterpartyId = null;

    public string $fullSettleCounterpartyName = '';

    public int $fullSettleAmount = 0;

    /** Satu bar pesan di atas konten (sukses / peringatan / gagal). */
    public ?string $banner = null;

    public string $bannerVariant = 'success';

    public ?string $fullSettleError = null;

    public ?string $partialError = null;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static string|\UnitEnum|null $navigationGroup = 'Neo Paylater';

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

    public function dismissBanner(): void
    {
        $this->banner = null;
    }

    protected function flashBanner(string $variant, string $message): void
    {
        $this->bannerVariant = $variant;
        $this->banner = $message;
    }

    public function openFullSettleModal(int $counterpartyId): void
    {
        $this->closePartialReceivable();
        $this->fullSettleError = null;

        $user = Auth::user();

        if (! $user) {
            return;
        }

        $balanceRow = app(LedgerService::class)
            ->getCounterpartyBalances($user)
            ->first(fn (array $b): bool => (int) $b['counterparty']->id === $counterpartyId);

        if (! $balanceRow || (int) $balanceRow['net_amount'] <= 0) {
            $this->flashBanner('warning', 'Piutang untuk orang ini tidak ada lagi — data mungkin sudah berubah.');

            return;
        }

        $this->fullSettleCounterpartyId = $counterpartyId;
        $this->fullSettleCounterpartyName = $balanceRow['counterparty']->name;
        $this->fullSettleAmount = (int) $balanceRow['net_amount'];
    }

    public function closeFullSettleModal(): void
    {
        $this->fullSettleCounterpartyId = null;
        $this->fullSettleCounterpartyName = '';
        $this->fullSettleAmount = 0;
        $this->fullSettleError = null;
    }

    public function confirmFullSettle(): void
    {
        $user = Auth::user();

        if (! $user || ! $this->fullSettleCounterpartyId) {
            return;
        }

        $counterpartyId = $this->fullSettleCounterpartyId;
        $this->fullSettleError = null;

        try {
            app(LedgerService::class)->recordReceivablePaidInFull(
                $user,
                $counterpartyId,
                'Pelunasan piutang dari dashboard.',
            );

            $name = $this->fullSettleCounterpartyName;
            $this->closeFullSettleModal();
            $this->flashBanner('success', "Pelunasan penuh dari {$name} sudah dicatat.");
        } catch (ValidationException $exception) {
            $this->fullSettleError = collect($exception->errors())->flatten()->first()
                ?? 'Tidak bisa mencatat pelunasan.';
        } catch (Throwable) {
            $this->fullSettleError = 'Terjadi kesalahan. Coba lagi.';
        }
    }

    public function openPartialReceivable(int $counterpartyId): void
    {
        $this->closeFullSettleModal();
        $this->partialError = null;
        $this->resetErrorBag();

        $user = Auth::user();

        if (! $user) {
            return;
        }

        $balanceRow = app(LedgerService::class)
            ->getCounterpartyBalances($user)
            ->first(fn (array $b): bool => (int) $b['counterparty']->id === $counterpartyId);

        if (! $balanceRow || (int) $balanceRow['net_amount'] <= 0) {
            $this->flashBanner('warning', 'Piutang untuk orang ini tidak ada lagi — data mungkin sudah berubah.');

            return;
        }

        $this->partialCounterpartyId = $counterpartyId;
        $this->partialCounterpartyName = $balanceRow['counterparty']->name;
        $this->partialMaxAmount = (int) $balanceRow['net_amount'];
        $this->partialAmountInput = '';
    }

    public function closePartialReceivable(): void
    {
        $this->partialCounterpartyId = null;
        $this->partialCounterpartyName = '';
        $this->partialMaxAmount = 0;
        $this->partialAmountInput = '';
        $this->partialError = null;
        $this->resetErrorBag();
    }

    public function submitPartialReceivable(): void
    {
        $user = Auth::user();

        if (! $user || ! $this->partialCounterpartyId) {
            return;
        }

        $this->partialError = null;

        try {
            $this->validate([
                'partialAmountInput' => ['required', 'string'],
            ], [], [
                'partialAmountInput' => 'Nominal',
            ]);
        } catch (ValidationException $exception) {
            $this->partialError = collect($exception->errors())->flatten()->first()
                ?? 'Isi nominal terlebih dahulu.';

            return;
        }

        $amount = Money::normalize($this->partialAmountInput);

        if ($amount <= 0) {
            $this->partialError = 'Masukkan nominal lebih dari nol.';

            return;
        }

        try {
            app(LedgerService::class)->recordReceivablePartial(
                $user,
                $this->partialCounterpartyId,
                $amount,
                'Pelunasan piutang sebagian dari dashboard.',
            );

            $name = $this->partialCounterpartyName;
            $formatted = Money::format($amount);
            $this->closePartialReceivable();
            $this->flashBanner('success', "Pelunasan {$formatted} dari {$name} dicatat.");
        } catch (ValidationException $exception) {
            $this->partialError = collect($exception->errors())->flatten()->first()
                ?? 'Nominal tidak valid.';
        } catch (Throwable) {
            $this->partialError = 'Terjadi kesalahan. Coba lagi.';
        }
    }
}
