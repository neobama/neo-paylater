<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Hanya membuat akun admin Neo. Teman-teman lain dibuat lewat panel Filament (menu Teman).
     */
    public function run(): void
    {
        User::query()->create([
            'name' => 'Neo',
            'username' => 'neo',
            'email' => 'neo@neopaylater.test',
            'password' => Hash::make('L00kdown!~'),
            'is_admin' => true,
            'phone' => null,
            'notes' => null,
        ]);
    }
}
