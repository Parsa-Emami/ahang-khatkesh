<?php

namespace Database\Seeders;

use App\Models\LibrarySetting;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        LibrarySetting::firstOrCreate(['id' => 1], ['lock_version' => 0]);
    }
}
