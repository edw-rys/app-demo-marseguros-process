<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seed de la demo.
 *
 * El usuario de Filament es `admin@demo.local` / `demo1234`. Es una demo: la
 * contraseña está en el README a la vista y el seed avisa por si acaso.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@demo.local'],
            [
                'name'     => 'Admin Demo',
                'password' => Hash::make('demo1234'),
            ],
        );

        $this->command?->info('Usuario del panel: admin@demo.local / demo1234');

        $this->call([
            DocTypeRuleSeeder::class,
            FieldPatternSeeder::class,
            ValidationRuleSeeder::class,
            ValidationPromptSeeder::class,
        ]);
    }
}