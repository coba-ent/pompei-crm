<?php

namespace Database\Seeders;

use App\Models\Deposito;
use Illuminate\Database\Seeder;

class DepositoSeeder extends Seeder
{
    public function run(): void
    {
        Deposito::firstOrCreate(['nombre' => 'Principal'], ['activo' => true]);
    }
}
