<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;

/**
 * Loads the demo data once on a hosted server: does nothing if any user exists, so it is
 * safe to run on every deploy and never overwrites real accounts.
 */
class SeedDemo extends Command
{
    protected $signature = 'learnloop:seed-demo';

    protected $description = 'Load the demo courses and accounts if the database has no users yet';

    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->info('Users already exist; demo data not loaded.');

            return self::SUCCESS;
        }

        $this->call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);
        $this->info('Demo data loaded.');

        return self::SUCCESS;
    }
}
