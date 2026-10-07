<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class PromoteSuperadmin extends Command
{
    protected $signature = 'admin:promote {email : Correo de una cuenta existente} {--revoke : Quitar el acceso en vez de darlo}';

    protected $description = 'Da (o quita) acceso al panel de superadministrador de la plataforma';

    public function handle(): int
    {
        $user = User::where('email', strtolower($this->argument('email')))->first();
        if (! $user) {
            $this->error('No existe una cuenta con ese correo. Primero debe registrarse.');

            return self::FAILURE;
        }

        $user->forceFill(['is_superadmin' => ! $this->option('revoke')])->save();
        $this->info($user->is_superadmin ? "{$user->email} ahora es superadmin." : "{$user->email} ya no es superadmin.");

        return self::SUCCESS;
    }
}
