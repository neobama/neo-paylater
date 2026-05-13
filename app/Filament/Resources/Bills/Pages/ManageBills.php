<?php

namespace App\Filament\Resources\Bills\Pages;

use App\Filament\Resources\Bills\BillResource;
use App\Models\User;
use App\Services\BillService;
use App\Services\ReceiptParserService;
use App\Support\Money;
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
                        ->required(),
                ])
                ->action(function (array $data, ReceiptParserService $receiptParserService, BillService $billService) {
                    $parsed = $receiptParserService->parseFromStoredReceipt($data['receipt_image_path']);

                    $items = [];
                    foreach ($parsed['items'] as $row) {
                        $line = (int) $row['total_amount'];
                        $payerId = (int) $data['paid_by_user_id'];
                        $items[] = [
                            'name' => $row['name'],
                            'quantity' => max(1, (int) ($row['quantity'] ?? 1)),
                            'unit_price' => max(0, (int) ($row['unit_price'] ?? 0)),
                            'line_subtotal' => $line,
                            'total_amount' => $line,
                            'source' => 'ai',
                            'raw_payload' => $row,
                            'assignee_user_ids' => [$payerId],
                            'splits' => [
                                [
                                    'debtor_user_id' => $payerId,
                                    'amount' => $line,
                                    'notes' => null,
                                ],
                            ],
                        ];
                    }

                    $payload = [
                        'title' => $parsed['title'],
                        'merchant' => $parsed['merchant'],
                        'transaction_date' => $parsed['transaction_date'] ?: $data['fallback_transaction_date'],
                        'paid_by_user_id' => $data['paid_by_user_id'],
                        'notes' => 'Draft hasil parsing. Atur assignment orang per item lalu simpan.',
                        'receipt_image_path' => $data['receipt_image_path'],
                        'receipt_parse_status' => 'parsed',
                        'receipt_raw_json' => $parsed['raw'],
                        'tax_amount' => Money::normalize($parsed['tax_amount'] ?? 0),
                        'service_charge_amount' => Money::normalize($parsed['service_charge_amount'] ?? 0),
                        'items' => $items,
                    ];

                    $bill = $billService->createBill(BillResource::prepareBillData($payload), Auth::id());
                    $bill->forceFill(['receipt_parsed_at' => now()])->save();

                    Notification::make()
                        ->success()
                        ->title('Receipt berhasil diparsing')
                        ->body('Lanjut atur assignment dan pajak/service di halaman berikut.')
                        ->send();

                    $this->redirect(BillResource::getUrl('edit', ['record' => $bill]), navigate: true);
                }),
        ];
    }
}
