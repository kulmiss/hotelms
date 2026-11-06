<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Security\TwoFactor;
use Illuminate\Console\Command;

/** Server-only rescue when a Super Admin lost the phone and the recovery codes. */
class ResetTwoFactor extends Command
{
    protected $signature = 'hotelms:reset-two-factor {email}';

    protected $description = 'Turn off two-step sign-in for one account (they set it up again at the next sign-in)';

    public function handle(TwoFactor $twoFactor): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('No account with that email.');

            return self::FAILURE;
        }
        $twoFactor->disable($user);
        $this->info("Two-step sign-in turned off for {$user->email}.");

        return self::SUCCESS;
    }
}
