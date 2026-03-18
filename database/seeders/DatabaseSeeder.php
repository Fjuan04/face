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
            'id' => 1,
            'document' => '1234567890',
            'fullname' => 'super',
            'password' => 'admin',
            'email' => 'admin@gmail.com',
            'role_id'=> 1
        ]);

        User::create([
            'id' => 2,
            'document' => '12345678901',
            'fullname' => 'docent',
            'password' => 'docent',
            'email' => 'docent@gmail.com',
            'role_id'=> 2
        ]);

        User::create([
            'id' => 3,
            'document' => '12345678902',
            'fullname' => 'student',
            'password' => 'student',
            'email' => 'student@gmail.com',
            'role_id'=> 3
        ]);
    }
}
