<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Gives existing accounts the admin role (admins can manage every course's topics and
 * Canvas mappings). The account must already be registered.
 *
 *   php artisan learnloop:make-admin you@ashesi.edu.gh other@ashesi.edu.gh
 */
class MakeAdmin extends Command
{
    protected $signature = 'learnloop:make-admin {emails* : Email addresses of registered accounts}';

    protected $description = 'Make registered LearnLoop accounts admins';

    public function handle(): int
    {
        foreach ((array) $this->argument('emails') as $email) {
            $email = trim((string) $email);
            if ($email === '') {
                continue;
            }

            $user = User::where('email', $email)->first();
            if ($user === null) {
                $this->warn("No account for {$email} yet: register it first.");
                continue;
            }

            $user->forceFill(['role' => 'admin'])->save();
            $this->info("{$email} is now an admin.");
        }

        return self::SUCCESS;
    }
}
