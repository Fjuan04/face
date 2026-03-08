<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Role;
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
        // User::factory(10)->create();
        Role::create([
            "name"=> "administrator",
        ]);

        Role::create([
            "name"=> "docent",
        ]);

        User::create([
            'fullname' => 'super',
            'password' => 'admin',
            'email' => 'admin@gmail.com',
            'role_id'=> 1
        ]);
    }
}
