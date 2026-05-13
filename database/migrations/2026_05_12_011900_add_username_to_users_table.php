<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username')->nullable()->after('name');
        });

        $existingUsernames = [];

        DB::table('users')
            ->select(['id', 'name', 'email'])
            ->orderBy('id')
            ->get()
            ->each(function (object $user) use (&$existingUsernames): void {
                $baseUsername = Str::of($user->name)
                    ->lower()
                    ->slug('_')
                    ->value();

                if (blank($baseUsername) && filled($user->email)) {
                    $baseUsername = Str::before((string) $user->email, '@');
                }

                if (blank($baseUsername)) {
                    $baseUsername = "user_{$user->id}";
                }

                $username = $baseUsername;
                $suffix = 2;

                while (in_array($username, $existingUsernames, true)) {
                    $username = "{$baseUsername}_{$suffix}";
                    $suffix++;
                }

                $existingUsernames[] = $username;

                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['username' => $username]);
            });

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
