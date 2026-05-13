<?php

namespace App\Filament\Resources\Settlements\Pages;

use App\Filament\Resources\Settlements\SettlementResource;
use App\Services\LedgerService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ManageSettlements extends ManageRecords
{
    protected static string $resource = SettlementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateDataUsing(function (array $data): array {
                    $data = SettlementResource::prepareSettlementData($data);
                    $data['created_by_user_id'] = Auth::id();

                    return $data;
                })
                ->after(fn (Model $record, LedgerService $ledgerService): mixed => $ledgerService->syncSettlement($record)),
        ];
    }
}
