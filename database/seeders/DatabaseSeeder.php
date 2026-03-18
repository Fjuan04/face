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

        Role::create([
            "name"=> "student",
        ]);

        User::create([
            'fullname' => 'super',
            'password' => 'admin',
            'email' => 'admin@gmail.com',
            'role_id'=> 1
        ]);

        User::create([
            'fullname' => 'docent',
            'password' => 'docent',
            'email' => 'docent@gmail.com',
            'role_id'=> 2
        ]);

        User::create([
            'fullname' => 'student',
            'password' => 'student',
            'email' => 'student@gmail.com',
            'role_id'=> 3
        ]);
    }
}
