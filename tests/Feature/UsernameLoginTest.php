<?php

namespace Tests\Feature;

use App\Filament\Auth\Login;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UsernameLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_renders_username_field(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('Username');
        $response->assertSee('Ingat saya di browser ini');
    }

    public function test_user_can_login_with_username(): void
    {
        $user = User::query()->create([
            'name' => 'Neo',
            'username' => 'neo',
            'email' => 'neo@example.com',
            'password' => Hash::make('password123'),
        ]);

        Filament::setCurrentPanel('admin');

        Livewire::test(Login::class)
            ->assertSchemaStateSet([
                'remember' => true,
            ])
            ->fillForm([
                'username' => 'neo',
                'password' => 'password123',
                'remember' => true,
            ])
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }
}
