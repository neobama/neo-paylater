<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Teman';

    protected static string|\UnitEnum|null $navigationGroup = 'Neo Paylater';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Akun teman')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('name')
                                    ->label('Nama')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('username')
                                    ->label('Username')
                                    ->required()
                                    ->alphaDash()
                                    ->unique(ignoreRecord: true)
                                    ->helperText('Dipakai untuk login, boleh huruf, angka, strip, dan underscore.')
                                    ->maxLength(255),
                                TextInput::make('email')
                                    ->label('Email')
                                    ->email()
                                    ->required()
                                    ->unique(ignoreRecord: true)
                                    ->maxLength(255),
                                TextInput::make('phone')
                                    ->label('No. HP')
                                    ->tel()
                                    ->maxLength(30),
                                FileUpload::make('avatar_path')
                                    ->label('Foto profil')
                                    ->disk('public')
                                    ->directory('avatars')
                                    ->avatar()
                                    ->imageEditor(),
                                Toggle::make('is_admin')
                                    ->label('Admin')
                                    ->inline(false),
                                TextInput::make('password')
                                    ->label('Password')
                                    ->password()
                                    ->revealable()
                                    ->minLength(8)
                                    ->dehydrated(fn (?string $state): bool => filled($state))
                                    ->required(fn (string $operation): bool => $operation === 'create'),
                            ]),
                        Textarea::make('notes')
                            ->label('Catatan')
                            ->rows(3),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ringkasan akun')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('name')
                            ->label('Nama'),
                        \Filament\Infolists\Components\TextEntry::make('username')
                            ->label('Username')
                            ->copyable(),
                        \Filament\Infolists\Components\ImageEntry::make('avatar_path')
                            ->label('Foto')
                            ->disk('public')
                            ->visibility('public')
                            ->circular()
                            ->imageSize(56),
                        \Filament\Infolists\Components\TextEntry::make('email')
                            ->label('Email'),
                        \Filament\Infolists\Components\IconEntry::make('is_admin')
                            ->label('Admin')
                            ->boolean(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('avatar_path')
                    ->label('Foto')
                    ->disk('public')
                    ->circular()
                    ->defaultImageUrl('https://placehold.co/80x80/f43f5e/ffffff?text=%20'),
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('username')
                    ->label('Username')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                TextColumn::make('phone')
                    ->label('Phone'),
                IconColumn::make('is_admin')
                    ->label('Admin')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->since(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (User $record): bool => $record->id !== Auth::id()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->latest();
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->is_admin ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->is_admin ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return Auth::user()?->is_admin ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return (Auth::user()?->is_admin ?? false) && $record->getKey() !== Auth::id();
    }
}
