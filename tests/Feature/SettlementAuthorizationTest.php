<?php

namespace Tests\Feature;

use App\Filament\Resources\Settlements\SettlementResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SettlementAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_can_only_create_settlement_to_themself(): void
    {
        $neo = User::query()->create([
            'name' => 'Neo',
            'username' => 'neo',
            'email' => 'neo@example.com',
            'password' => Hash::make('password123'),
        ]);

        $rendi = User::query()->create([
            'name' => 'Rendi',
            'username' => 'rendi',
            'email' => 'rendi@example.com',
            'password' => Hash::make('password123'),
        ]);

        $bagus = User::query()->create([
            'name' => 'Bagus',
            'username' => 'bagus',
            'email' => 'bagus@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->actingAs($neo);

        SettlementResource::prepareSettlementData([
            'from_user_id' => $rendi->id,
            'to_user_id' => $neo->id,
            'amount' => 15000,
        ]);

        $this->expectException(ValidationException::class);

        SettlementResource::prepareSettlementData([
            'from_user_id' => $rendi->id,
            'to_user_id' => $bagus->id,
            'amount' => 15000,
        ]);
    }

    public function test_non_admin_cannot_create_outgoing_settlement_from_themself(): void
    {
        $neo = User::query()->create([
            'name' => 'Neo',
            'username' => 'neo',
            'email' => 'neo@example.com',
            'password' => Hash::make('password123'),
        ]);

        $rendi = User::query()->create([
            'name' => 'Rendi',
            'username' => 'rendi',
            'email' => 'rendi@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->actingAs($neo);

        $this->expectException(ValidationException::class);

        SettlementResource::prepareSettlementData([
            'from_user_id' => $neo->id,
            'to_user_id' => $rendi->id,
            'amount' => 15000,
        ]);
    }

    public function test_admin_can_create_settlement_for_any_pair(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password123'),
            'is_admin' => true,
        ]);

        $neo = User::query()->create([
            'name' => 'Neo',
            'username' => 'neo',
            'email' => 'neo@example.com',
            'password' => Hash::make('password123'),
        ]);

        $rendi = User::query()->create([
            'name' => 'Rendi',
            'username' => 'rendi',
            'email' => 'rendi@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->actingAs($admin);

        $data = SettlementResource::prepareSettlementData([
            'from_user_id' => $neo->id,
            'to_user_id' => $rendi->id,
            'amount' => 15000,
        ]);

        $this->assertSame($neo->id, $data['from_user_id']);
        $this->assertSame($rendi->id, $data['to_user_id']);
        $this->assertSame(15000, $data['amount']);
    }
}
