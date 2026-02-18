<?php

namespace Database\Seeders;

use App\Models\SlaSetting;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        SlaSetting::query()->upsert([
            ['priority' => 'low', 'response_minutes' => 240, 'resolution_minutes' => 1440],
            ['priority' => 'medium', 'response_minutes' => 120, 'resolution_minutes' => 720],
            ['priority' => 'high', 'response_minutes' => 60, 'resolution_minutes' => 360],
            ['priority' => 'critical', 'response_minutes' => 30, 'resolution_minutes' => 120],
        ], ['priority'], ['response_minutes', 'resolution_minutes']);

        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
