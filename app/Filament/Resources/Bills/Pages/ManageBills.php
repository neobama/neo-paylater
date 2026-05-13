<?php

namespace App\Filament\Resources\Bills\Pages;

use App\Filament\Resources\Bills\BillResource;
use App\Models\Bill;
use App\Models\User;
use App\Services\BillService;
use App\Services\LedgerService;
use App\Services\ReceiptParserService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ManageBills extends ManageRecords
{
    protected static string $resource = BillResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tambah bill manual')
                ->mutateDataUsing(function (array $data): array {
                    return BillResource::prepareBillData($data);
                })
                ->using(fn (array $data, BillService $billService): Model => $billService->createBill($data, Auth::id())),
            Action::make('importReceipt')
                ->label('Import receipt AI')
                ->icon('heroicon-o-sparkles')
                ->color('danger')
                ->schema([
                    Select::make('paid_by_user_id')
                        ->label('Yang bayar')
                        ->options(fn (): array => User::query()->friends()->get()->mapWithKeys(fn (User $user): array => [$user->id => $user->getAvatarOptionLabel()])->all())
                        ->searchable()
                        ->preload()
                        ->allowHtml()
                        ->required()
                        ->default(fn (): ?int => Auth::id()),
                    DatePicker::make('fallback_transaction_date')
                        ->label('Fallback tanggal')
                        ->default(now())
                        ->helperText('Dipakai kalau tanggal di receipt tidak kebaca.'),
                    BillResource::receiptPhotoFileUpload(
                        FileUpload::make('receipt_image_path')
                            ->label('Foto bill / struk')
                            ->disk('public')
                            ->directory('receipts')
                            ->image()
                    )
                        ->helperText('Foto dari HP otomatis diperkecil di perangkat sebelum upload, lalu disimpan di server (bukan cloud).')
                        ->required(),
                ])
                ->action(function (array $data, ReceiptParserService $receiptParserService, LedgerService $ledgerService): void {
                    $parsed = $receiptParserService->parseFromStoredReceipt($data['receipt_image_path']);

                    $bill = Bill::query()->create([
                        'title' => $parsed['title'],
                        'merchant' => $parsed['merchant'],
                        'transaction_date' => $parsed['transaction_date'] ?: $data['fallback_transaction_date'],
                        'paid_by_user_id' => $data['paid_by_user_id'],
                        'created_by_user_id' => Auth::id(),
                        'total_amount' => 0,
                        'receipt_parse_status' => 'parsed',
                        'receipt_image_path' => $data['receipt_image_path'],
                        'receipt_parsed_at' => now(),
                        'receipt_raw_json' => $parsed['raw'],
                        'notes' => 'Draft hasil parsing Gemini. Cek assignment sebelum dipakai.',
                    ]);

                    foreach ($parsed['items'] as $index => $itemData) {
                        $item = $bill->items()->create([
                            'name' => $itemData['name'],
                            'quantity' => $itemData['quantity'],
                            'unit_price' => $itemData['unit_price'],
                            'total_amount' => $itemData['total_amount'],
                            'source' => 'ai',
                            'sort_order' => $index,
                            'raw_payload' => $itemData,
                        ]);

                        $item->splits()->create([
                            'debtor_user_id' => $data['paid_by_user_id'],
                            'amount' => $itemData['total_amount'],
                            'sort_order' => 0,
                            'notes' => 'Default ke payer dulu, edit assignment setelah parsing.',
                        ]);
                    }

                    $ledgerService->syncBill($bill);

                    Notification::make()
                        ->success()
                        ->title('Receipt berhasil diparsing')
                        ->body('Draft bill sudah dibuat. Edit bill untuk assign nominal ke teman-teman.')
                        ->send();
                }),
        ];
    }
}
