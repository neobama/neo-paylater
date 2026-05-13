<?php

namespace App\Filament\Resources\Bills;

use App\Filament\Resources\Bills\Pages\EditBill;
use App\Filament\Resources\Bills\Pages\ManageBills;
use App\Models\Bill;
use App\Models\User;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
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
                            ->helperText('Upload opsional. Untuk AI, gunakan "Import receipt AI" di halaman Bills.'),
                    ]),
                Section::make('Item & assignment')
                    ->description('Tabel ringkas: satu orang = full baris; pilih lebih dari satu = total baris (termasuk bagian pajak/service) dibagi rata. Edit nama & nominal lewat ikon pensil.')
                    ->schema([
                        Repeater::make('items')
                            ->label('Item')
                            ->cloneable()
                            ->compact()
                            ->table([
                                TableColumn::make('Item')->markAsRequired(),
                                TableColumn::make('Qty × harga')->width('140px'),
                                TableColumn::make('Subtotal')->width('120px')->markAsRequired(),
                                TableColumn::make('Dibebankan ke')->markAsRequired(),
                            ])
                            ->default(fn (): array => [static::defaultItemState()])
                            ->itemLabel(function (array $state): ?string {
                                $name = $state['name'] ?: 'Item';
                                $qty = (float) ($state['quantity'] ?? 1);
                                $line = $state['line_subtotal'] ?? $state['total_amount'] ?? 0;
                                $lineInt = is_numeric($line) ? (int) $line : 0;
                                $n = count(array_unique(array_filter(Arr::wrap($state['assignee_user_ids'] ?? []))));

                                return "{$name} · ".Money::format($lineInt).($n > 1 ? " · {$n} orang" : '');
                            })
                            ->schema([
                                Hidden::make('quantity')->default(1),
                                Hidden::make('unit_price')->default(0),
                                Hidden::make('source')->default('manual'),
                                Hidden::make('raw_payload'),
                                TextInput::make('name')
                                    ->label('Item')
                                    ->readOnly()
                                    ->required(),
                                Placeholder::make('qty_unit_preview')
                                    ->label('Qty × harga')
                                    ->content(function (Get $get): string {
                                        $qty = (float) ($get('quantity') ?? 1);
                                        $unit = Money::normalize($get('unit_price'));
                                        if ($qty <= 0) {
                                            $qty = 1;
                                        }
                                        if ($unit <= 0) {
                                            return '—';
                                        }
                                        $qtyLabel = fmod($qty, 1.0) === 0.0
                                            ? (string) (int) $qty
                                            : rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');

                                        return "{$qtyLabel} × ".Money::format($unit);
                                    }),
                                TextInput::make('line_subtotal')
                                    ->label('Subtotal')
                                    ->numeric()
                                    ->prefix('Rp')
                                    ->readOnly()
                                    ->required(),
                                Select::make('assignee_user_ids')
                                    ->label('Orang')
                                    ->multiple()
                                    ->options(fn (): array => static::getUserOptions(withAvatar: true))
                                    ->searchable()
                                    ->preload()
                                    ->allowHtml()
                                    ->rules(['required', 'array', 'min:1'])
                                    ->helperText('Satu orang: full subtotal. Lebih dari satu: dibagi rata (setelah pajak/service per baris).'),
                            ])
                            ->extraItemActions([
                                Action::make('editLineItem')
                                    ->label('Edit item')
                                    ->icon(Heroicon::PencilSquare)
                                    ->modalHeading('Edit item')
                                    ->schema([
                                        TextInput::make('edit_name')
                                            ->label('Nama item')
                                            ->required()
                                            ->maxLength(255),
                                        TextInput::make('edit_quantity')
                                            ->label('Qty')
                                            ->numeric()
                                            ->minValue(1)
                                            ->integer()
                                            ->required(),
                                        TextInput::make('edit_unit_price')
                                            ->label('Harga satuan')
                                            ->numeric()
                                            ->prefix('Rp')
                                            ->required(),
                                    ])
                                    ->fillForm(function (array $arguments, Repeater $component): array {
                                        $item = $component->getRawItemState($arguments['item']);

                                        return [
                                            'edit_name' => (string) ($item['name'] ?? ''),
                                            'edit_quantity' => (float) ($item['quantity'] ?? 1),
                                            'edit_unit_price' => Money::normalize($item['unit_price'] ?? 0),
                                        ];
                                    })
                                    ->action(function (array $arguments, Repeater $component, array $data): void {
                                        $key = $arguments['item'];
                                        $qty = max(1, (int) ($data['edit_quantity'] ?? 1));
                                        $unit = Money::normalize($data['edit_unit_price'] ?? 0);
                                        $name = trim((string) ($data['edit_name'] ?? ''));
                                        if ($name === '' || $unit <= 0) {
                                            return;
                                        }
                                        $line = $qty * $unit;
                                        if ($line <= 0) {
                                            return;
                                        }
                                        $state = $component->getState();
                                        $state[$key] = array_merge(
                                            $component->getRawItemState($key),
                                            [
                                                'name' => $name,
                                                'quantity' => $qty,
                                                'unit_price' => $unit,
                                                'line_subtotal' => $line,
                                                'total_amount' => $line,
                                            ],
                                        );
                                        $component->state($state);
                                    }),
                            ]),
                        Grid::make(2)
                            ->schema([
                                TextInput::make('tax_amount')
                                    ->label('Pajak')
                                    ->numeric()
                                    ->prefix('Rp')
                                    ->default(0),
                                TextInput::make('service_charge_amount')
                                    ->label('Service charge')
                                    ->numeric()
                                    ->prefix('Rp')
                                    ->default(0),
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
                            ->helperText('Foto receipt disimpan.')
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

            $ids = [];
            foreach (Arr::wrap($items[$itemIndex]['assignee_user_ids'] ?? []) as $rid) {
                $id = (int) $rid;
                if ($id > 0 && ! in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }

            if ($ids === []) {
                $legacyAssign = (int) ($items[$itemIndex]['assigned_debtor_user_id'] ?? 0);
                if ($legacyAssign > 0) {
                    $ids = [$legacyAssign];
                }
            }

            if ($ids === []) {
                $legacyMulti = array_values(array_unique(array_filter(array_map(
                    static fn ($id): int => (int) $id,
                    Arr::wrap($items[$itemIndex]['split_debtor_ids'] ?? [])
                ))));
                if ($legacyMulti !== []) {
                    $ids = $legacyMulti;
                }
            }

            if ($ids === []) {
                foreach (array_values($items[$itemIndex]['splits'] ?? []) as $split) {
                    $d = (int) ($split['debtor_user_id'] ?? 0);
                    if ($d > 0 && ! in_array($d, $ids, true)) {
                        $ids[] = $d;
                    }
                }
            }

            if ($ids === []) {
                $payer = (int) ($data['paid_by_user_id'] ?? 0);
                if ($payer > 0) {
                    $ids = [$payer];
                }
            }

            if ($ids === [] || $ids[0] <= 0) {
                throw ValidationException::withMessages([
                    "items.{$itemIndex}.assignee_user_ids" => 'Pilih minimal satu orang untuk tiap item.',
                ]);
            }

            $n = count($ids);
            $itemSplitPerItem = $n > 1;

            if ($n === 1) {
                $debtorId = $ids[0];
                if ($debtorId <= 0) {
                    throw ValidationException::withMessages([
                        "items.{$itemIndex}.assignee_user_ids" => 'User tidak valid.',
                    ]);
                }

                $items[$itemIndex]['splits'] = [
                    [
                        'debtor_user_id' => $debtorId,
                        'amount' => $itemTotal,
                        'notes' => null,
                    ],
                ];
            } else {
                $base = intdiv($itemTotal, $n);
                $rem = $itemTotal % $n;
                $newSplits = [];

                foreach ($ids as $i => $debtorId) {
                    if ($debtorId <= 0) {
                        throw ValidationException::withMessages([
                            "items.{$itemIndex}.assignee_user_ids" => 'User tidak valid.',
                        ]);
                    }

                    $amt = $base + ($i < $rem ? 1 : 0);

                    if ($amt <= 0) {
                        throw ValidationException::withMessages([
                            "items.{$itemIndex}.assignee_user_ids" => 'Nominal per orang harus lebih dari nol.',
                        ]);
                    }

                    $newSplits[] = [
                        'debtor_user_id' => $debtorId,
                        'amount' => $amt,
                        'notes' => null,
                    ];
                }

                $items[$itemIndex]['splits'] = $newSplits;
            }

            $items[$itemIndex]['split_per_item'] = $itemSplitPerItem;
            $items[$itemIndex]['total_amount'] = $itemTotal;
            $items[$itemIndex]['source'] = $items[$itemIndex]['source'] ?? 'manual';
            $totalAmount += $itemTotal;
        }

        $data['items'] = $items;
        $data['tax_amount'] = $tax;
        $data['service_charge_amount'] = $service;
        $data['total_amount'] = $totalAmount;
        $data['receipt_parse_status'] = filled($data['receipt_image_path'] ?? null)
            ? (($data['receipt_parse_status'] ?? null) ?: 'uploaded')
            : 'manual';

        foreach ($items as $k => $item) {
            unset(
                $items[$k]['assignee_user_ids'],
                $items[$k]['assigned_debtor_user_id'],
                $items[$k]['split_debtor_ids'],
            );
        }

        $data['items'] = $items;

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
                    'raw_payload' => $item->raw_payload,
                    'assignee_user_ids' => $item->splits
                        ->sortBy('sort_order')
                        ->pluck('debtor_user_id')
                        ->map(fn ($id): int => (int) $id)
                        ->unique()
                        ->values()
                        ->all(),
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
            'raw_payload' => null,
            'assignee_user_ids' => array_values(array_filter([(int) Auth::id()])),
            'splits' => [],
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
