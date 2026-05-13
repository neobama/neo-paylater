<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable(['name', 'username', 'email', 'password', 'is_admin', 'phone', 'avatar_path', 'notes'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_admin' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function createdBills(): HasMany
    {
        return $this->hasMany(Bill::class, 'created_by_user_id');
    }

    public function paidBills(): HasMany
    {
        return $this->hasMany(Bill::class, 'paid_by_user_id');
    }

    public function owedSplits(): HasMany
    {
        return $this->hasMany(BillSplit::class, 'debtor_user_id');
    }

    public function settlementsPaid(): HasMany
    {
        return $this->hasMany(Settlement::class, 'from_user_id');
    }

    public function settlementsReceived(): HasMany
    {
        return $this->hasMany(Settlement::class, 'to_user_id');
    }

    public function scopeFriends($query)
    {
        return $query->orderBy('name');
    }

    public function getAvatarUrl(): ?string
    {
        if (! filled($this->avatar_path)) {
            return null;
        }

        return Storage::disk('public')->url($this->avatar_path);
    }

    public function getInitials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))
            ->implode('');
    }

    public function getAvatarOptionLabel(): string
    {
        $name = e($this->name);

        if ($this->getAvatarUrl()) {
            $avatarUrl = e($this->getAvatarUrl());

            return <<<HTML
<div style="display:flex;align-items:center;gap:10px;">
    <img src="{$avatarUrl}" alt="{$name}" style="width:28px;height:28px;border-radius:9999px;object-fit:cover;border:1px solid rgba(226,232,240,1);" />
    <span>{$name}</span>
</div>
HTML;
        }

        $initials = e($this->getInitials());

        return <<<HTML
<div style="display:flex;align-items:center;gap:10px;">
    <div style="width:28px;height:28px;border-radius:9999px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#fb7185,#e11d48);color:white;font-size:11px;font-weight:700;">{$initials}</div>
    <span>{$name}</span>
</div>
HTML;
    }
}
