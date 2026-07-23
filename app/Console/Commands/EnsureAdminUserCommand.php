<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class EnsureAdminUserCommand extends Command
{
    protected $signature = 'admin:ensure-user
                            {--email=admin@sundoritoma.com : Admin email}
                            {--phone=01700000000 : Admin phone}
                            {--password=password : Admin password}
                            {--name=Admin : Admin display name}';

    protected $description = 'Create or update an admin user with admin role';

    public function handle(): int
    {
        foreach (['dev', 'admin', 'moderator', 'customers', 'reseller'] as $role) {
            Role::findOrCreate($role);
        }

        $user = User::query()->updateOrCreate(
            ['email' => $this->option('email')],
            [
                'name' => $this->option('name'),
                'phone' => $this->option('phone'),
                'password' => Hash::make($this->option('password')),
                'is_active' => true,
            ],
        );

        $user->syncRoles(['admin']);

        $this->info('Admin user ready: '.$user->email);
        $this->line('Login at /login then open /admin');

        return self::SUCCESS;
    }
}
