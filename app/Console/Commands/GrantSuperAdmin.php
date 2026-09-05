<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use Illuminate\Console\Command;

/**
 * Grants or revokes platform Super Admin.
 *
 * The User model has said since Phase 1 that `is_super_admin` "is settable
 * only through an audited console command -- a mass-assignment path to this
 * column is a privilege-escalation vulnerability." The guard was correct and
 * the command did not exist, so the entire /admin surface -- 38 tests' worth
 * of working screens -- was reachable only by editing the database by hand.
 *
 * Console only, by construction. There is deliberately no HTTP route that
 * grants this: a web endpoint that can make somebody Super Admin is the single
 * most valuable target in the application, and the one privilege no tenant may
 * ever assign.
 */
final class GrantSuperAdmin extends Command
{
    protected $signature = 'platform:super-admin
                            {email : The account to change}
                            {--revoke : Take the privilege away instead}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Grant or revoke platform Super Admin for an account.';

    public function handle(AuditLogger $audit): int
    {
        $email = (string) $this->argument('email');
        $revoking = (bool) $this->option('revoke');

        /*
         | withoutGlobalScopes: this runs from a console with no tenant
         | context, and a Super Admin is a platform principal rather than a
         | member of any one agency. The scope would hide every candidate.
         */
        $user = User::query()
            ->withoutGlobalScopes()
            ->where('email', $email)
            ->first();

        if ($user === null) {
            $this->error("No account with the address {$email}.");

            return self::FAILURE;
        }

        if ($user->is_super_admin === ! $revoking) {
            $this->line($revoking
                ? "{$email} is not a Super Admin."
                : "{$email} is already a Super Admin.");

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(
            $revoking
                ? "Revoke Super Admin from {$email}?"
                : "Make {$email} a Super Admin of the whole platform?",
        )) {
            $this->line('Nothing changed.');

            return self::SUCCESS;
        }

        // forceFill, because the column is guarded against mass assignment on
        // purpose -- this command is the sanctioned way past that guard, and
        // the only one.
        $user->forceFill(['is_super_admin' => ! $revoking])->save();

        /*
         | Audited, as the model's docblock requires. Who holds the platform's
         | highest privilege, and when they were given it, is the first
         | question asked after any incident.
         |
         | No actor: a console run has no authenticated user, and recording a
         | System actor is more honest than attributing it to whoever happens
         | to be in the session table.
         */
        $audit->log(
            action: $revoking ? 'user.super_admin_revoked' : 'user.super_admin_granted',
            auditable: $user,
            oldValues: ['is_super_admin' => $revoking],
            newValues: ['is_super_admin' => ! $revoking],
        );

        if ($revoking) {
            $this->info("{$email} is no longer a Super Admin.");

            return self::SUCCESS;
        }

        $this->info("{$email} is now a Super Admin.");

        /*
         | Said explicitly, because the middleware will otherwise bounce them
         | to enrolment with no explanation of why the screen they were
         | promised is refusing them.
         */
        if (! $user->hasTwoFactorEnabled()) {
            $this->newLine();
            $this->warn('Two-factor authentication is mandatory for /admin.');
            $this->line('They must enrol before the admin surface will open, at /user/two-factor.');
        }

        return self::SUCCESS;
    }
}
