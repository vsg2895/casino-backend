<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

        $user = User::updateOrCreate(
            ['email' => 'admin@casino-platform.local'],
            [
                'name'     => 'Super Admin',
                'password' => Hash::make('changeme123!'),
            ],
        );

        $user->assignRole($superAdmin);

        $this->command->info("Admin user ready: admin@casino-platform.local / changeme123!");
    }
}
