<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Create or reset an admin login. Public registration is disabled, so this
 * is how accounts are made (the installer calls it for the first admin).
 */
class CreateAdmin extends Command
{
    protected $signature = 'softswitch:admin
        {email : Login email address}
        {--name= : Display name (defaults to the part of the email before @)}
        {--password= : Password to set (a random one is generated and printed if omitted)}
        {--if-none : Do nothing if any user already exists}';

    protected $description = 'Create an admin user, or reset the password of an existing one';

    public function handle(): int
    {
        if ($this->option('if-none') && User::query()->exists()) {
            $this->line('Users already exist; nothing to do.');

            return self::SUCCESS;
        }

        $email = Str::lower(trim((string) $this->argument('email')));
        $password = (string) ($this->option('password') ?: Str::password(20, symbols: false));

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email'], 'password' => ['required', 'string', 'min:12']],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $created = ! $user->exists;
        $user->name = (string) ($this->option('name') ?: ($user->name ?: Str::before($email, '@')));
        $user->password = Hash::make($password);
        $user->email_verified_at ??= now();
        $user->save();

        $this->info(($created ? 'Created' : 'Updated').' admin '.$email);
        if (! $this->option('password')) {
            $this->line('Password: '.$password);
        }

        return self::SUCCESS;
    }
}
