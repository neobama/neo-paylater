<?php

namespace App\Filament\Resources\Settlements;

use App\Filament\Resources\Settlements\Pages\ManageSettlements;
use App\Models\Settlement;
use App\Models\User;
use App\Services\LedgerService;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class SettlementResource extends Resource
{
    protected static ?string $model = Settlement::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Pelunasan';

    protected static string|\UnitEnum|null $navigationGroup = 'Neo Paylater';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Catat pelunasan')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Select::make('from_user_id')
                                    ->label('Yang melunasi')
                                    ->options(fn (): array => static::getFromUserOptions(withAvatar: true))
                                    ->searchable()
                                    ->preload()
                                    ->allowHtml()
                                    ->required(),
                                Select::make('to_user_id')
                                    ->label('Penerima pelunasan')
                                    ->options(fn (): array => static::getToUserOptions(withAvatar: true))
                                    ->searchable()
                                    ->preload()
                                    ->allowHtml()
                                    ->required()
                                    ->default(fn (): ?int => static::isAdmin() ? null : Auth::id())
                                    ->disabled(fn (): bool => ! static::isAdmin())
                                    ->dehydrated(true),
                                TextInput::make('amount')
                                    ->label('Nominal')
                                    ->numeric()
                                    ->prefix('Rp')
                                    ->required(),
                                DatePicker::make('paid_at')
                                    ->label('Tanggal bayar')
                                    ->default(now())
                                    ->required(),
                            ]),
                        Textarea::make('notes')
                            ->label('Catatan')
                            ->rows(3)
                            ->placeholder('Contoh: transfer setelah dinner.'),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ringkasan pelunasan')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('fromUser.name')
                            ->label('Dari'),
                        \Filament\Infolists\Components\TextEntry::make('toUser.name')
                            ->label('Ke'),
                        \Filament\Infolists\Components\TextEntry::make('paid_at')
                            ->label('Tanggal')
                            ->date('d M Y'),
                        \Filament\Infolists\Components\TextEntry::make('amount')
                            ->label('Nominal')
                            ->formatStateUsing(fn ($state): string => Money::format($state)),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('paid_at')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('fromUser.name')
                    ->label('Dari')
                    ->searchable(),
                TextColumn::make('toUser.name')
                    ->label('Ke')
                    ->searchable(),
                TextColumn::make('amount')
                    ->label('Nominal')
                    ->formatStateUsing(fn ($state): string => Money::format($state))
                    ->sortable(),
                TextColumn::make('notes')
                    ->label('Catatan')
                    ->limit(40),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateDataUsing(fn (array $data): array => static::prepareSettlementData($data))
                    ->after(fn (Model $record, LedgerService $ledgerService): mixed => $ledgerService->syncSettlement($record))
                    ->visible(fn (Settlement $record): bool => static::canManage($record)),
                DeleteAction::make()
                    ->visible(fn (Settlement $record): bool => static::canManage($record)),
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
            'index' => ManageSettlements::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['fromUser', 'toUser'])
            ->latest('paid_at');

        if (static::isAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $builder): void {
            $builder
                ->where('from_user_id', Auth::id())
                ->orWhere('to_user_id', Auth::id());
        });
    }

    public static function prepareSettlementData(array $data): array
    {
        if (($data['from_user_id'] ?? null) === ($data['to_user_id'] ?? null)) {
            throw ValidationException::withMessages([
                'to_user_id' => 'Penerima pelunasan harus berbeda dengan yang melunasi.',
            ]);
        }

        $data['amount'] = Money::normalize($data['amount'] ?? 0);

        if ($data['amount'] <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Nominal pelunasan harus lebih dari nol.',
            ]);
        }

        $user = Auth::user();

        if ($user && ! $user->is_admin) {
            if ((int) ($data['to_user_id'] ?? 0) !== $user->id) {
                throw ValidationException::withMessages([
                    'to_user_id' => 'User biasa hanya bisa mencatat pelunasan piutang ke dirinya sendiri.',
                ]);
            }

            if ((int) ($data['from_user_id'] ?? 0) === $user->id) {
                throw ValidationException::withMessages([
                    'from_user_id' => 'User biasa tidak bisa membuat settlement keluar dari dirinya sendiri.',
                ]);
            }
        }

        return $data;
    }

    public static function canViewAny(): bool
    {
        return Auth::check();
    }

    public static function canCreate(): bool
    {
        return Auth::check();
    }

    public static function canManage(Settlement $record): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        return $record->created_by_user_id === $user->id
            && $record->to_user_id === $user->id;
    }

    public static function isAdmin(): bool
    {
        return Auth::user()?->is_admin ?? false;
    }

    public static function getFromUserOptions(bool $withAvatar = false): array
    {
        $users = User::query()
            ->friends()
            ->when(! static::isAdmin(), fn (Builder $query) => $query->whereKeyNot(Auth::id()))
            ->get();

        if (! $withAvatar) {
            return $users->pluck('name', 'id')->all();
        }

        return $users
            ->mapWithKeys(fn (User $user): array => [$user->id => $user->getAvatarOptionLabel()])
            ->all();
    }

    public static function getToUserOptions(bool $withAvatar = false): array
    {
        if (! static::isAdmin()) {
            return Auth::check()
                ? [
                    Auth::id() => ($withAvatar ? Auth::user()->getAvatarOptionLabel() : Auth::user()->name),
                ]
                : [];
        }

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
}
