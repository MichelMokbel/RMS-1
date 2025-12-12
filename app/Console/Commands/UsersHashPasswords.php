<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UsersHashPasswords extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'users:hash-passwords';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-hash any plaintext passwords stored on the users table.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $updated = 0;

        User::query()
            ->select(['id', 'password'])
            ->chunkById(100, function ($users) use (&$updated): void {
                foreach ($users as $user) {
                    $password = (string) $user->password;

                    $isHashed = Str::startsWith($password, ['$2y$', '$argon2i$', '$argon2id$']);

                    if (! $isHashed || Hash::needsRehash($password)) {
                        $user->forceFill([
                            'password' => Hash::make($password),
                        ])->save();

                        $updated++;
                    }
                }
            });

        $this->info("Hashed {$updated} password(s).");

        return self::SUCCESS;
    }
}
