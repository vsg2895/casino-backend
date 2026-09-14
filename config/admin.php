<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Admin account seeding
    |--------------------------------------------------------------------------
    |
    | Used by AdminUserSeeder, which is a RECOVERY tool: it deletes every user,
    | token and role assignment before creating this one login.
    |
    | The password lives in the environment and NOT in the seeder, because the
    | seeder is committed and `backend` has a remote. A working admin password in
    | git history is readable by anyone who ever clones the repo, cannot be
    | removed by a later commit, and survives the day the password is changed in
    | the database. There is no default on purpose — the seeder refuses to run
    | rather than mint an account with a password someone could guess from
    | source.
    |
    */

    'seed_email' => env('ADMIN_SEED_EMAIL', 'admin@casino-platform'),

    'seed_password' => env('ADMIN_SEED_PASSWORD'),

];
