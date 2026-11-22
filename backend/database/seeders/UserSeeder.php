<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Admin
        User::forceCreate([
            'name'     => 'Admin',
            'email'    => 'admin@learnloop.test',
            'password' => Hash::make('password123'),
            'role'     => 'admin',
        ]);

        // Lecturers
        User::forceCreate([
            'name'     => 'Dr. Sarah Chen',
            'email'    => 'dr.sarah.chen@learnloop.test',
            'password' => Hash::make('password123'),
            'role'     => 'lecturer',
        ]);

        User::forceCreate([
            'name'     => 'Prof. James Okafor',
            'email'    => 'prof.james.okafor@learnloop.test',
            'password' => Hash::make('password123'),
            'role'     => 'lecturer',
        ]);

        // Students
        User::forceCreate([
            'name'     => 'Alex Morgan',
            'email'    => 'alex.morgan@student.learnloop.test',
            'password' => Hash::make('password123'),
            'role'     => 'student',
        ]);

        User::forceCreate([
            'name'     => 'Jamie Lee',
            'email'    => 'jamie.lee@student.learnloop.test',
            'password' => Hash::make('password123'),
            'role'     => 'student',
        ]);

        User::forceCreate([
            'name'     => 'Sam Patel',
            'email'    => 'sam.patel@student.learnloop.test',
            'password' => Hash::make('password123'),
            'role'     => 'student',
        ]);
    }
}
