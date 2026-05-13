<?php

namespace App\Filament\Resources\Bills;

use App\Filament\Resources\Bills\Pages\ManageBills;
use App\Models\Bill;
use App\Models\User;
use App\Services\BillService;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class BillResource extends Resource
{
    protected static ?string $model = Bill::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationLabel = 'Bills';

    protected static string|\UnitEnum|null $navigationGroup = 'Neo Paylater';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Info tagihan')
                    ->description('Catat siapa yang bayar, kapan, dan struknya kalau ada.')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('title')
                                    ->label('Nama bill')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('McD Tendean'),
                                TextInput::make('merchant')
                                    ->label('Merchant / tempat')
                                    ->maxLength(255)
                                    ->placeholder('McDonald\'s Tendean'),
                                DatePicker::make('transaction_date')
                                    ->label('Tanggal transaksi')
                                    ->required()
                                    ->default(now()),
                                Select::make('paid_by_user_id')
                                    ->label('Yang bayar')
                                    ->options(fn (): array => static::getUserOptions(withAvatar: true))
                                    ->searchable()
                                    ->preload()
                                    ->allowHtml()
                                    ->required()
                                    ->default(fn (): ?int => Auth::id()),
                            ]),
                        Textarea::make('notes')
                            ->label('Catatan')
                            ->rows(3)
                            ->placeholder('Contoh: patungan habis meeting kecil.'),
                        FileUpload::make('receipt_image_path')
                            ->label('Foto bill / struk')
                            ->disk('public')
                            ->directory('receipts')
                            ->image()
                            ->imageEditor()
                            ->openable()
                            ->downloadable()
                            ->helperText('Upload opsional. File disimpan di storage lokal server pada folder receipt. Untuk AI mode, gunakan tombol "Import receipt AI" dari halaman Bills.'),
                    ]),
                Section::make('Item & assignment')
                    ->description('Untuk bill paket, cukup buat 1 item lalu bagi nominalnya ke beberapa orang.')
                    ->schema([
                        Repeater::make('items')
                            ->label('Item')
                            ->cloneable()
                            ->collapsible()
                            ->default(fn (): array => [static::defaultItemState()])
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                            ->schema([
                                Grid::make(12)
                                    ->schema([
                                        TextInput::make('name')
                                            ->label('Nama item')
                                            ->required()
                                            ->columnSpan(5),
                                        TextInput::make('quantity')
                                            ->label('Qty')
                                            ->numeric()
                                            ->minValue(1)
                                            ->default(1)
                                            ->required()
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(function (Get $get, Set $set, $state): void {
                                                $set('total_amount', max(1, (int) $state) * Money::normalize($get('unit_price')));
                                            })
                                            ->columnSpan(2),
                                        TextInput::make('unit_price')
                                            ->label('Harga satuan')
                                            ->numeric()
                                            ->prefix('Rp')
                                            ->helperText('Opsional kalau kamu langsung isi total item.')
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(function (Get $get, Set $set, $state): void {
                                                $unitPrice = Money::normalize($state);

                                                if ($unitPrice > 0) {
                                                    $set('total_amount', max(1, (int) $get('quantity')) * $unitPrice);
                                                }
                                            })
                                            ->columnSpan(2),
                                        TextInput::make('total_amount')
                                            ->label('Total item')
                                            ->numeric()
                                            ->required()
                                            ->prefix('Rp')
                                            ->columnSpan(3),
                                    ]),
                                Repeater::make('splits')
                                    ->label('Dibebankan ke')
                                    ->cloneable()
                                    ->default(fn (Get $get): array => [[
                                        'debtor_user_id' => $get('../../paid_by_user_id') ?: Auth::id(),
                                        'amount' => Money::normalize($get('../total_amount')),
                                        'notes' => null,
                                    ]])
                                    ->columns(12)
                                    ->schema([
                                        Select::make('debtor_user_id')
                                            ->label('User')
                                            ->options(fn (): array => static::getUserOptions(withAvatar: true))
                                            ->searchable()
                                            ->preload()
                                            ->allowHtml()
                                            ->default(fn (Get $get): mixed => $get('../../paid_by_user_id') ?: Auth::id())
                                            ->columnSpan(5),
                                        TextInput::make('amount')
                                            ->label('Nominal')
                                            ->numeric()
                                            ->prefix('Rp')
                                            ->default(fn (Get $get): int => Money::normalize($get('../../total_amount')))
                                            ->helperText('Kalau cuma satu assignment, nominal akan otomatis ikut total item.')
                                            ->columnSpan(4),
                                        TextInput::make('notes')
                                            ->label('Catatan')
                                            ->maxLength(255)
                                            ->columnSpan(3),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ringkasan bill')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('title')
                            ->label('Nama bill'),
                        \Filament\Infolists\Components\TextEntry::make('merchant')
                            ->label('Merchant'),
                        \Filament\Infolists\Components\TextEntry::make('payer.name')
                            ->label('Yang bayar'),
                        \Filament\Infolists\Components\TextEntry::make('transaction_date')
                            ->label('Tanggal')
                            ->date('d M Y'),
                        \Filament\Infolists\Components\TextEntry::make('total_amount')
                            ->label('Total')
                            ->formatStateUsing(fn ($state): string => Money::format($state)),
                        \Filament\Infolists\Components\TextEntry::make('my_assigned_total')
                            ->label('Tagihan ke kamu')
                            ->state(fn (Bill $record): ?int => static::getAssignedAmountForUser($record, Auth::id()))
                            ->formatStateUsing(fn ($state): string => Money::format($state))
                            ->visible(fn (): bool => ! (Auth::user()?->is_admin ?? false)),
                    ])->columns(2),
                Section::make('Foto receipt')
                    ->schema([
                        \Filament\Infolists\Components\ImageEntry::make('receipt_image_path')
                            ->label('Receipt')
                            ->disk('public')
                            ->visibility('public')
                            ->imageHeight(320)
                            ->square(false)
                            ->defaultImageUrl('https://placehold.co/800x500/f5f5f5/999999?text=No+Receipt')
                            ->helperText('Foto receipt disimpan di storage lokal server.')
                            ->extraImgAttributes([
                                'class' => 'rounded-2xl object-contain bg-gray-50 p-2',
                            ]),
                    ])
                    ->visible(fn (Bill $record): bool => filled($record->receipt_image_path)),
                \Filament\Infolists\Components\RepeatableEntry::make('items')
                    ->label('Detail item')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('name')
                            ->label('Item')
                            ->weight('bold'),
                        \Filament\Infolists\Components\TextEntry::make('total_amount')
                            ->label('Total item')
                            ->formatStateUsing(fn ($state): string => Money::format($state)),
                        \Filament\Infolists\Components\RepeatableEntry::make('splits')
                            ->label('Assignment')
                            ->contained(false)
                            ->schema([
                                \Filament\Infolists\Components\TextEntry::make('debtor.name')
                                    ->label('User'),
                                \Filament\Infolists\Components\TextEntry::make('amount')
                                    ->label('Nominal')
                                    ->formatStateUsing(fn ($state): string => Money::format($state)),
                                \Filament\Infolists\Components\TextEntry::make('notes')
                                    ->label('Catatan')
                                    ->placeholder('-'),
                            ])
                            ->columns(3),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Bill')
                    ->searchable()
                    ->description(fn (Bill $record): ?string => $record->merchant ?: null)
                    ->weight('bold'),
                TextColumn::make('transaction_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('payer.name')
                    ->label('Yang bayar')
                    ->searchable(),
                TextColumn::make('my_assigned_total')
                    ->label('Tagihan kamu')
                    ->state(fn (Bill $record): ?int => static::getAssignedAmountForUser($record, Auth::id()))
                    ->formatStateUsing(fn ($state): string => $state ? Money::format($state) : '-')
                    ->badge()
                    ->color(fn (?int $state): string => $state ? 'danger' : 'gray')
                    ->visible(fn (): bool => ! (Auth::user()?->is_admin ?? false)),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->formatStateUsing(fn ($state): string => Money::format($state))
                    ->sortable(),
                TextColumn::make('receipt_parse_status')
                    ->label('Mode')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'parsed' => 'AI parsed',
                        'uploaded' => 'Receipt uploaded',
                        default => 'Manual',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'parsed' => 'success',
                        'uploaded' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->since(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make()
                    ->visible(fn (Bill $record): bool => static::canAccessRecord($record)),
                EditAction::make()
                    ->mutateRecordDataUsing(fn (Bill $record): array => static::formDataFromRecord($record))
                    ->mutateDataUsing(fn (array $data): array => static::prepareBillData($data))
                    ->using(fn (Bill $record, array $data, BillService $billService): Model => $billService->updateBill($record, $data))
                    ->visible(fn (Bill $record): bool => static::canManage($record)),
                DeleteAction::make()
                    ->visible(fn (Bill $record): bool => static::canManage($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => Auth::user()?->is_admin ?? false),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBills::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['payer', 'items.splits.debtor'])
            ->latest('transaction_date');

        if (Auth::user()?->is_admin ?? false) {
            return $query;
        }

        return $query->where(function (Builder $builder): void {
            $builder
                ->where('created_by_user_id', Auth::id())
                ->orWhere('paid_by_user_id', Auth::id())
                ->orWhereHas('items.splits', fn (Builder $query): Builder => $query->where('debtor_user_id', Auth::id()));
        });
    }

    public static function prepareBillData(array $data): array
    {
        $items = $data['items'] ?? [];

        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => 'Minimal harus ada satu item di dalam bill.',
            ]);
        }

        $totalAmount = 0;

        foreach ($items as $itemIndex => $item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $unitPrice = Money::normalize($item['unit_price'] ?? 0);
            $total = Money::normalize($item['total_amount'] ?? ($quantity * $unitPrice));

            if ($total <= 0) {
                throw ValidationException::withMessages([
                    "items.{$itemIndex}.total_amount" => 'Total item harus lebih dari nol.',
                ]);
            }

            if ($unitPrice <= 0) {
                $unitPrice = (int) max(1, round($total / $quantity));
            }

            $rawSplits = array_values($item['splits'] ?? []);

            if ($rawSplits === []) {
                $rawSplits[] = [
                    'debtor_user_id' => $data['paid_by_user_id'] ?? null,
                    'amount' => $total,
                    'notes' => null,
                ];
            }

            $splitTotal = 0;

            foreach ($rawSplits as $splitIndex => $split) {
                $debtorUserId = $split['debtor_user_id'] ?? null;
                $amount = Money::normalize($split['amount'] ?? 0);

                if (! $debtorUserId && count($rawSplits) === 1) {
                    $debtorUserId = $data['paid_by_user_id'] ?? null;
                }

                if ($amount <= 0 && count($rawSplits) === 1) {
                    $amount = $total;
                }

                if (! $debtorUserId) {
                    throw ValidationException::withMessages([
                        "items.{$itemIndex}.splits.{$splitIndex}.debtor_user_id" => 'Pilih user untuk assignment item ini.',
                    ]);
                }

                if ($amount <= 0) {
                    throw ValidationException::withMessages([
                        "items.{$itemIndex}.splits.{$splitIndex}.amount" => 'Nominal assignment harus lebih dari nol.',
                    ]);
                }

                $items[$itemIndex]['splits'][$splitIndex]['debtor_user_id'] = $debtorUserId;
                $items[$itemIndex]['splits'][$splitIndex]['amount'] = $amount;
                $splitTotal += $amount;
            }

            if ($splitTotal !== $total) {
                throw ValidationException::withMessages([
                    "items.{$itemIndex}.splits" => 'Total assignment harus sama dengan total item.',
                ]);
            }

            $items[$itemIndex]['quantity'] = $quantity;
            $items[$itemIndex]['unit_price'] = $unitPrice;
            $items[$itemIndex]['total_amount'] = $total;
            $items[$itemIndex]['source'] = $items[$itemIndex]['source'] ?? 'manual';

            $totalAmount += $total;
        }

        $data['items'] = $items;
        $data['total_amount'] = $totalAmount;
        $data['receipt_parse_status'] = filled($data['receipt_image_path'] ?? null)
            ? (($data['receipt_parse_status'] ?? null) ?: 'uploaded')
            : 'manual';

        return $data;
    }

    public static function formDataFromRecord(Bill $record): array
    {
        $record->loadMissing(['items.splits']);

        return [
            'title' => $record->title,
            'merchant' => $record->merchant,
            'transaction_date' => $record->transaction_date?->toDateString(),
            'paid_by_user_id' => $record->paid_by_user_id,
            'notes' => $record->notes,
            'receipt_image_path' => $record->receipt_image_path,
            'receipt_parse_status' => $record->receipt_parse_status,
            'items' => $record->items
                ->sortBy('sort_order')
                ->values()
                ->map(fn ($item): array => [
                    'name' => $item->name,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total_amount' => $item->total_amount,
                    'source' => $item->source,
                    'splits' => $item->splits
                        ->sortBy('sort_order')
                        ->values()
                        ->map(fn ($split): array => [
                            'debtor_user_id' => $split->debtor_user_id,
                            'amount' => $split->amount,
                            'notes' => $split->notes,
                        ])
                        ->all(),
                ])
                ->all(),
        ];
    }

    public static function getUserOptions(bool $withAvatar = false): array
    {
        $users = User::query()
            ->friends()
            ->get();

        if (! $withAvatar) {
            return $users->pluck('name', 'id')->all();
        }

        return $users
            ->mapWithKeys(fn (User $user): array => [$user->id => $user->getAvatarOptionLabel()])
            ->all();
    }

    public static function defaultItemState(): array
    {
        return [
            'name' => null,
            'quantity' => 1,
            'unit_price' => null,
            'total_amount' => null,
            'source' => 'manual',
            'splits' => [[
                'debtor_user_id' => Auth::id(),
                'amount' => null,
                'notes' => null,
            ]],
        ];
    }

    public static function canViewAny(): bool
    {
        return Auth::check();
    }

    public static function canCreate(): bool
    {
        return Auth::check();
    }

    public static function canManage(Bill $record): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        return $user->is_admin || $record->created_by_user_id === $user->id;
    }

    public static function canAccessRecord(Bill $record): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        if ($user->is_admin || $record->created_by_user_id === $user->id || $record->paid_by_user_id === $user->id) {
            return true;
        }

        $record->loadMissing('items.splits');

        return $record->items
            ->flatMap(fn ($item) => $item->splits)
            ->contains(fn ($split): bool => (int) $split->debtor_user_id === (int) $user->id);
    }

    public static function getAssignedAmountForUser(Bill $record, ?int $userId): ?int
    {
        if (! $userId) {
            return null;
        }

        $record->loadMissing('items.splits');

        $amount = $record->items
            ->flatMap(fn ($item) => $item->splits)
            ->where('debtor_user_id', $userId)
            ->sum('amount');

        return $amount > 0 ? (int) $amount : null;
    }
}
