<?php

namespace App\Filament\Resources\Bills\Pages;

use App\Filament\Resources\Bills\BillResource;
use App\Models\Bill;
use App\Services\BillService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditBill extends EditRecord
{
    protected static string $resource = BillResource::class;

    protected static ?string $title = 'Atur item & assignment';

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        abort_unless(BillResource::canManage($this->getRecord()), 403);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        if ($record instanceof Bill) {
            return BillResource::formDataFromRecord($record);
        }

        return parent::mutateFormDataBeforeFill($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return BillResource::prepareBillData($data);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(BillService::class)->updateBill($record, $data);
    }

    protected function getRedirectUrl(): ?string
    {
        return BillResource::getUrl('index');
    }
}
