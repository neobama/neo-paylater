<?php

namespace App\Filament\Resources\Bills;

use App\Filament\Resources\Bills\Pages\EditBill;
use App\Filament\Resources\Bills\Pages\ManageBills;
use App\Models\Bill;
use App\Models\User;
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
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
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

    /**
     * Kompres/resize gambar di browser (Filepond) sebelum request ke server,
     * supaya foto kamera HP (sering 10–40MB) tidak mentok upload_max_filesize / Nginx.
     */
    public static function receiptPhotoFileUpload(FileUpload $component): FileUpload
    {
        return $component
            ->automaticallyResizeImagesMode('contain')
            ->automaticallyResizeImagesToWidth('2048')
            ->automaticallyResizeImagesToHeight('2048')
            ->automaticallyUpscaleImagesWhenResizing(false);
    }

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
                        static::receiptPhotoFileUpload(
                            FileUpload::make('receipt_image_path')
                                ->label('Foto bill / struk')
                                ->disk('public')
                                ->directory('receipts')
                                ->image()
                        )
                            ->imageEditor()
                            ->openable()
                            ->downloadable()
                            ->helperText('Upload opsional. Foto dari HP otomatis diperkecil di perangkat sebelum upload. File disimpan lokal di folder receipt. Untuk AI, gunakan "Import receipt AI" di halaman Bills.'),
                    ]),
                Section::make('Item & assignment')
                    ->description('Subtotal per baris belum termasuk pajak & service; bagian itu dibagi proporsional saat simpan. Matikan "split per item" bila tiap baris cukup satu orang.')
                    ->schema([
                        Toggle::make('split_per_item')
                            ->label('Split nominal per item (banyak orang per baris)')
                            ->helperText('Matikan: pilih satu orang per item. Nyalakan: pecah nominal per baris ke beberapa orang.')
                            ->default(false)
                            ->live(),
                        Repeater::make('items')
                            ->label('Item')
                            ->cloneable()
                            ->collapsible()
                            ->default(fn (): array => [static::defaultItemState()])
                            ->itemLabel(function (array $state): ?string {
                                $name = $state['name'] ?: 'Item';
                                $qty = (int) ($state['quantity'] ?? 1);
                                $line = $state['line_subtotal'] ?? $state['total_amount'] ?? 0;
                                $lineInt = is_numeric($line) ? (int) $line : 0;
                                $unit = $state['unit_price'] ?? null;
                                $unitLabel = is_numeric($unit) ? Money::format((int) $unit) : '—';

                                return "{$name} · qty {$qty} · @{$unitLabel} · subtotal ".Money::format($lineInt);
                            })
                            ->schema([
                                Grid::make(12)
                                    ->schema([
                                        TextInput::make('name')
                                            ->label('Nama item')
                                            ->required()
                                            ->columnSpan(4),
                                        TextInput::make('quantity')
                                            ->label('Qty')
                                            ->numeric()
                                            ->minValue(1)
                                            ->default(1)
                                            ->required()
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(function (Get $get, Set $set, $state): void {
                                                $qty = max(1, (int) $state);
                                                $unit = Money::normalize($get('unit_price'));
                                                if ($unit > 0) {
                                                    $set('line_subtotal', $qty * $unit);
                                                }
                                            })
                                            ->columnSpan(2),
                                        TextInput::make('unit_price')
                                            ->label('Harga satuan')
                                            ->numeric()
                                            ->prefix('Rp')
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(function (Get $get, Set $set, $state): void {
                                                $unitPrice = Money::normalize($state);
                                                $qty = max(1, (int) $get('quantity'));
                                                if ($unitPrice > 0) {
                                                    $set('line_subtotal', $qty * $unitPrice);
                                                }
                                            })
                                            ->columnSpan(2),
                                        TextInput::make('line_subtotal')
                                            ->label('Subtotal baris')
                                            ->numeric()
                                            ->prefix('Rp')
                                            ->required()
                                            ->helperText('Belum termasuk pajak & service di bawah.')
                                            ->live(onBlur: true)
                                            ->columnSpan(4),
                                    ]),
                                Select::make('assigned_debtor_user_id')
                                    ->label('Dibebankan ke (satu orang)')
                                    ->options(fn (): array => static::getUserOptions(withAvatar: true))
                                    ->searchable()
                                    ->preload()
                                    ->allowHtml()
                                    ->default(fn (): ?int => Auth::id())
                                    ->visible(fn (Get $get): bool => ! (bool) $get('../../split_per_item'))
                                    ->required(fn (Get $get): bool => ! (bool) $get('../../split_per_item')),
                                Repeater::make('splits')
                                    ->label('Dibebankan ke (split per item)')
                                    ->cloneable()
                                    ->default(fn (Get $get): array => [[
                                        'debtor_user_id' => $get('../../paid_by_user_id') ?: Auth::id(),
                                        'amount' => Money::normalize($get('../line_subtotal')),
                                        'notes' => null,
                                    ]])
                                    ->columns(12)
                                    ->visible(fn (Get $get): bool => (bool) $get('../../split_per_item'))
                                    ->schema([
                                        Select::make('debtor_user_id')
                                            ->label('User')
                                            ->options(fn (): array => static::getUserOptions(withAvatar: true))
                                            ->searchable()
                                            ->preload()
                                            ->allowHtml()
                                            ->default(fn (Get $get): mixed => $get('../../../../paid_by_user_id') ?: Auth::id())
                                            ->columnSpan(5),
                                        TextInput::make('amount')
                                            ->label('Nominal')
                                            ->numeric()
                                            ->prefix('Rp')
                                            ->default(fn (Get $get): int => Money::normalize($get('../../line_subtotal')))
                                            ->helperText('Total baris split + pajak/service dihitung saat simpan; proporsi di sini dipertahankan.')
                                            ->columnSpan(4),
                                        TextInput::make('notes')
                                            ->label('Catatan')
                                            ->maxLength(255)
                                            ->columnSpan(3),
                                    ]),
                            ]),
                        Grid::make(2)
                            ->schema([
                                TextInput::make('tax_amount')
                                    ->label('Pajak')
                                    ->numeric()
                                    ->prefix('Rp')
                                    ->default(0)
                                    ->helperText('Dibagi ke tiap item proporsional terhadap subtotal baris, lalu masuk ke total yang dibebankan.'),
                                TextInput::make('service_charge_amount')
                                    ->label('Service charge')
                                    ->numeric()
                                    ->prefix('Rp')
                                    ->default(0)
                                    ->helperText('Sama seperti pajak: proporsional per subtotal item.'),
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
                        TextEntry::make('title')
                            ->label('Nama bill'),
                        TextEntry::make('merchant')
                            ->label('Merchant'),
                        TextEntry::make('payer.name')
                            ->label('Yang bayar'),
                        TextEntry::make('transaction_date')
                            ->label('Tanggal')
                            ->date('d M Y'),
                        TextEntry::make('total_amount')
                            ->label('Total')
                            ->formatStateUsing(fn ($state): string => Money::format($state)),
                        TextEntry::make('tax_amount')
                            ->label('Pajak')
                            ->formatStateUsing(fn ($state): string => Money::format((int) ($state ?? 0))),
                        TextEntry::make('service_charge_amount')
                            ->label('Service')
                            ->formatStateUsing(fn ($state): string => Money::format((int) ($state ?? 0))),
                        TextEntry::make('my_assigned_total')
                            ->label('Tagihan ke kamu')
                            ->state(fn (Bill $record): ?int => static::getAssignedAmountForUser($record, Auth::id()))
                            ->formatStateUsing(fn ($state): string => Money::format($state))
                            ->visible(fn (): bool => ! (Auth::user()?->is_admin ?? false)),
                    ])->columns(2),
                Section::make('Foto receipt')
                    ->schema([
                        ImageEntry::make('receipt_image_path')
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
                RepeatableEntry::make('items')
                    ->label('Detail item')
                    ->schema([
                        TextEntry::make('name')
                            ->label('Item')
                            ->weight('bold'),
                        TextEntry::make('quantity')
                            ->label('Qty'),
                        TextEntry::make('unit_price')
                            ->label('Harga @')
                            ->formatStateUsing(fn ($state): string => Money::format((int) $state)),
                        TextEntry::make('line_subtotal')
                            ->label('Subtotal')
                            ->formatStateUsing(fn ($state): string => Money::format((int) ($state ?? 0)))
                            ->placeholder('—'),
                        TextEntry::make('total_amount')
                            ->label('Total dibebankan')
                            ->formatStateUsing(fn ($state): string => Money::format($state)),
                        RepeatableEntry::make('splits')
                            ->label('Assignment')
                            ->contained(false)
                            ->schema([
                                TextEntry::make('debtor.name')
                                    ->label('User'),
                                TextEntry::make('amount')
                                    ->label('Nominal')
                                    ->formatStateUsing(fn ($state): string => Money::format($state)),
                                TextEntry::make('notes')
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
            ->recordUrl(fn (Bill $record): ?string => static::canManage($record)
                ? static::getUrl('edit', ['record' => $record])
                : null)
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
                    ->url(fn (Bill $record): string => static::getUrl('edit', ['record' => $record]))
                    ->modal(false)
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
            'edit' => EditBill::route('/{record}/edit'),
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

    /**
     * @param  list<int>  $lineAmounts
     * @return list<int>
     */
    public static function allocateProportionalFees(array $lineAmounts, int $feePool): array
    {
        $n = count($lineAmounts);
        if ($feePool <= 0 || $n === 0) {
            return array_fill(0, $n, 0);
        }

        $subtotal = (int) array_sum($lineAmounts);
        if ($subtotal <= 0) {
            $base = intdiv($feePool, $n);
            $rem = $feePool % $n;
            $out = [];
            for ($i = 0; $i < $n; $i++) {
                $out[] = $base + ($i < $rem ? 1 : 0);
            }

            return $out;
        }

        $extras = [];
        foreach ($lineAmounts as $line) {
            $extras[] = (int) round($feePool * $line / $subtotal);
        }

        $drift = $feePool - array_sum($extras);
        if ($drift !== 0) {
            $extras[$n - 1] += $drift;
        }

        return $extras;
    }

    public static function prepareBillData(array $data): array
    {
        $items = array_values($data['items'] ?? []);

        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => 'Minimal harus ada satu item di dalam bill.',
            ]);
        }

        $splitPerItem = (bool) ($data['split_per_item'] ?? false);
        $tax = Money::normalize($data['tax_amount'] ?? 0);
        $service = Money::normalize($data['service_charge_amount'] ?? 0);
        $feePool = $tax + $service;

        $lines = [];

        foreach ($items as $itemIndex => $item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $unitPrice = Money::normalize($item['unit_price'] ?? 0);
            $line = Money::normalize($item['line_subtotal'] ?? 0);

            if ($line <= 0) {
                $line = Money::normalize($item['total_amount'] ?? 0);
            }

            if ($line <= 0 && $unitPrice > 0) {
                $line = $quantity * $unitPrice;
            }

            if ($line <= 0) {
                throw ValidationException::withMessages([
                    "items.{$itemIndex}.line_subtotal" => 'Subtotal item harus lebih dari nol.',
                ]);
            }

            if ($unitPrice <= 0) {
                $unitPrice = (int) max(1, round($line / $quantity));
            }

            $lines[$itemIndex] = $line;
            $items[$itemIndex]['quantity'] = $quantity;
            $items[$itemIndex]['unit_price'] = $unitPrice;
            $items[$itemIndex]['line_subtotal'] = $line;
        }

        $extras = static::allocateProportionalFees(array_values($lines), $feePool);
        $orderedIndexes = array_keys($items);

        $totalAmount = 0;

        foreach ($orderedIndexes as $idx => $itemIndex) {
            $line = $lines[$itemIndex];
            $extra = $extras[$idx];
            $itemTotal = $line + $extra;

            if ($itemTotal <= 0) {
                throw ValidationException::withMessages([
                    "items.{$itemIndex}.line_subtotal" => 'Total setelah pajak & service harus lebih dari nol.',
                ]);
            }

            if (! $splitPerItem) {
                $assignee = (int) ($items[$itemIndex]['assigned_debtor_user_id'] ?? 0);
                if ($assignee <= 0) {
                    $firstSplits = array_values($items[$itemIndex]['splits'] ?? []);
                    if (count($firstSplits) === 1) {
                        $assignee = (int) ($firstSplits[0]['debtor_user_id'] ?? 0);
                    }
                }
                if ($assignee <= 0) {
                    $assignee = (int) ($data['paid_by_user_id'] ?? 0);
                }

                if ($assignee <= 0) {
                    throw ValidationException::withMessages([
                        "items.{$itemIndex}.assigned_debtor_user_id" => 'Pilih orang yang dibebankan untuk item ini.',
                    ]);
                }

                $items[$itemIndex]['splits'] = [
                    [
                        'debtor_user_id' => $assignee,
                        'amount' => $itemTotal,
                        'notes' => null,
                    ],
                ];
            } else {
                $rawSplits = array_values($items[$itemIndex]['splits'] ?? []);

                if ($rawSplits === []) {
                    $rawSplits[] = [
                        'debtor_user_id' => (int) ($data['paid_by_user_id'] ?? 0),
                        'amount' => $line,
                        'notes' => null,
                    ];
                }

                $oldAmounts = [];
                foreach ($rawSplits as $split) {
                    $oldAmounts[] = Money::normalize($split['amount'] ?? 0);
                }

                $oldSum = (int) array_sum($oldAmounts);
                if ($oldSum <= 0) {
                    $debtor = (int) ($rawSplits[0]['debtor_user_id'] ?? $data['paid_by_user_id'] ?? 0);
                    $rawSplits = [['debtor_user_id' => $debtor, 'amount' => $line, 'notes' => null]];
                    $oldAmounts = [$line];
                    $oldSum = $line;
                }

                $count = count($rawSplits);
                $newSplits = [];
                $running = 0;

                foreach ($rawSplits as $splitIndex => $split) {
                    $prevAmt = (int) ($oldAmounts[$splitIndex] ?? 0);
                    $debtor = (int) ($split['debtor_user_id'] ?? $data['paid_by_user_id'] ?? 0);

                    if ($splitIndex === $count - 1) {
                        $amt = $itemTotal - $running;
                    } else {
                        $amt = $oldSum > 0
                            ? (int) round($prevAmt * $itemTotal / $oldSum)
                            : intdiv($itemTotal, $count);
                        $running += $amt;
                    }

                    if ($debtor <= 0) {
                        throw ValidationException::withMessages([
                            "items.{$itemIndex}.splits.{$splitIndex}.debtor_user_id" => 'Pilih user untuk split ini.',
                        ]);
                    }

                    if ($amt <= 0) {
                        throw ValidationException::withMessages([
                            "items.{$itemIndex}.splits.{$splitIndex}.amount" => 'Nominal split harus lebih dari nol.',
                        ]);
                    }

                    $newSplits[] = [
                        'debtor_user_id' => $debtor,
                        'amount' => $amt,
                        'notes' => $split['notes'] ?? null,
                    ];
                }

                $splitSum = (int) array_sum(array_column($newSplits, 'amount'));
                if ($splitSum !== $itemTotal) {
                    $newSplits[$count - 1]['amount'] += $itemTotal - $splitSum;
                }

                $items[$itemIndex]['splits'] = $newSplits;
            }

            $items[$itemIndex]['total_amount'] = $itemTotal;
            $items[$itemIndex]['source'] = $items[$itemIndex]['source'] ?? 'manual';
            $totalAmount += $itemTotal;
        }

        $data['items'] = $items;
        $data['split_per_item'] = $splitPerItem;
        $data['tax_amount'] = $tax;
        $data['service_charge_amount'] = $service;
        $data['total_amount'] = $totalAmount;
        $data['receipt_parse_status'] = filled($data['receipt_image_path'] ?? null)
            ? (($data['receipt_parse_status'] ?? null) ?: 'uploaded')
            : 'manual';

        foreach ($items as $k => $item) {
            unset($items[$k]['assigned_debtor_user_id']);
        }

        $data['items'] = $items;

        return $data;
    }

    public static function formDataFromRecord(Bill $record): array
    {
        $record->loadMissing(['items.splits']);

        $splitPerItem = $record->split_per_item;
        if (! $splitPerItem) {
            foreach ($record->items as $item) {
                if ($item->splits->count() > 1) {
                    $splitPerItem = true;
                    break;
                }
            }
        }

        return [
            'title' => $record->title,
            'merchant' => $record->merchant,
            'transaction_date' => $record->transaction_date?->toDateString(),
            'paid_by_user_id' => $record->paid_by_user_id,
            'notes' => $record->notes,
            'receipt_image_path' => $record->receipt_image_path,
            'receipt_parse_status' => $record->receipt_parse_status,
            'split_per_item' => $splitPerItem,
            'tax_amount' => $record->tax_amount,
            'service_charge_amount' => $record->service_charge_amount,
            'items' => $record->items
                ->sortBy('sort_order')
                ->values()
                ->map(fn ($item): array => [
                    'name' => $item->name,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'line_subtotal' => $item->line_subtotal ?? $item->total_amount,
                    'total_amount' => $item->total_amount,
                    'source' => $item->source,
                    'assigned_debtor_user_id' => $item->splits->count() === 1
                        ? $item->splits->first()->debtor_user_id
                        : null,
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
            'line_subtotal' => null,
            'total_amount' => null,
            'source' => 'manual',
            'assigned_debtor_user_id' => Auth::id(),
            'splits' => [[
                'debtor_user_id' => Auth::id(),
                'amount' => null,
                'notes' => null,
            ]],
        ];
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Bill && static::canManage($record);
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
