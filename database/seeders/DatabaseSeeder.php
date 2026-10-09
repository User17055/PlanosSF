<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AccessSeeder::class);

        if (config('planos.is_homologation')) {
            $this->call(HomologationSeeder::class);
        }
    }
}
