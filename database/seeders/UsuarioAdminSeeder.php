<?php

namespace Database\Seeders;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsuarioAdminSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@contagram.local'],
            [
                'name' => 'Administrador',
                'password' => Hash::make('password'),
                'activo' => true,
            ]
        );

        $rolAdmin = Rol::where('nombre', 'Admin')->first();

        if ($rolAdmin) {
            $admin->roles()->syncWithoutDetaching([$rolAdmin->id]);
        }
    }
}
