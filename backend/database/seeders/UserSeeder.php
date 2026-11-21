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
        User::create([
            'name'     => 'Admin',
            'email'    => 'admin@learnloop.test',
            'password' => Hash::make('password123'),
            'role'     => 'admin',
        ]);

        // Lecturers
        User::create([
            'name'     => 'Dr. Sarah Chen',
            'email'    => 'dr.sarah.chen@learnloop.test',
            'password' => Hash::make('password123'),
            'role'     => 'lecturer',
        ]);

        User::create([
            'name'     => 'Prof. James Okafor',
            'email'    => 'prof.james.okafor@learnloop.test',
            'password' => Hash::make('password123'),
            'role'     => 'lecturer',
        ]);

        // Students
        User::create([
            'name'     => 'Alex Morgan',
            'email'    => 'alex.morgan@student.learnloop.test',
            'password' => Hash::make('password123'),
            'role'     => 'student',
        ]);

        User::create([
            'name'     => 'Jamie Lee',
            'email'    => 'jamie.lee@student.learnloop.test',
            'password' => Hash::make('password123'),
            'role'     => 'student',
        ]);

        User::create([
            'name'     => 'Sam Patel',
            'email'    => 'sam.patel@student.learnloop.test',
            'password' => Hash::make('password123'),
            'role'     => 'student',
        ]);
    }
}
