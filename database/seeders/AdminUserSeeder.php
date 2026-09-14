<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * The single admin account.
 *
 * DESTRUCTIVE BY DESIGN: every existing user is removed before the account is
 * created, so running this always leaves exactly one known login. That is what
 * makes it a recovery tool — "I cannot get in" is answered by running the
 * seeder rather than by hunting for whichever row holds the right hash.
 *
 * The cost of that is real: on a box with other admin accounts, this deletes
 * them. Invoke it by name, never as part of a blanket `db:seed`.
 *
 *   php artisan db:seed --class=AdminUserSeeder --force
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        /*
         * Credentials come from the environment, never from this file.
         *
         * This is committed source in a repo with a remote: a real password here
         * would be permanently readable in git history by anyone who clones it,
         * and would stay readable long after the password itself was changed.
         *
         * No fallback. A seeder that silently invents a password is worse than
         * one that stops and says what is missing — especially this one, which
         * has already deleted nothing at the point it checks.
         */
        $email = (string) config('admin.seed_email');
        $password = (string) config('admin.seed_password');

        if (trim($password) === '') {
            $this->command?->error('ADMIN_SEED_PASSWORD is not set — refusing to seed an admin account.');
            $this->command?->line('Add it to .env (it is never committed), then run this again.');

            return;
        }

        $existing = User::count();

        /*
         * Tokens and role rows go first, and neither is optional.
         *
         * `personal_access_tokens` is a MORPH table — the migration uses
         * morphs('tokenable'), so there is no foreign key and nothing cascades.
         * Deleting the user without this leaves live bearer tokens pointing at a
         * user id that no longer exists, and Sanctum keeps accepting them until
         * they expire. The same applies to spatie's `model_has_roles`, where a
         * stale row would hand super-admin to whatever id is reused next.
         */
        DB::table('personal_access_tokens')->where('tokenable_type', User::class)->delete();
        DB::table('model_has_roles')->where('model_type', User::class)->delete();

        // Plain delete, not forceDelete: User does not use SoftDeletes.
        User::query()->delete();

        $superAdmin = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

        $user = User::create([
            'name'     => 'Super Admin',
            'email'    => $email,
            'password' => $password,
        ]);

        // Not in #[Fillable], so it is set after create() rather than through it.
        $user->forceFill(['email_verified_at' => now()])->save();

        $user->assignRole($superAdmin);

        $this->command?->warn("Removed {$existing} existing user(s) and their tokens.");
        $this->command?->info('Admin user ready: ' . $email);
    }
}
