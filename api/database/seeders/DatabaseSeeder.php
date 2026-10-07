<?php

namespace Database\Seeders;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Cuenta de demostración para desarrollo local: correo verificado y empresa
     * creada, así se puede entrar sin pasar por el correo de verificación.
     *
     * Es idempotente: se puede ejecutar varias veces (`php artisan db:seed`) sin
     * duplicar nada ni pisar la contraseña de una cuenta que ya existe.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('El seeder de demo no debe ejecutarse en producción.');
        }

        $user = User::where('email', 'demo@hamster.test')->first()
            ?? User::factory()->create([
                'name' => 'Demo Hamster',
                'email' => 'demo@hamster.test',
                'password' => 'password',
            ]);

        // El demo es también superadmin de la plataforma (solo desarrollo): permite ver /admin sin pasos extra.
        $user->forceFill(['is_superadmin' => true])->save();

        $organization = Organization::where('slug', 'barberia-demo')->first()
            ?? Organization::factory()->create(['name' => 'Barbería Demo', 'slug' => 'barberia-demo']);

        $organization->users()->syncWithoutDetaching([
            $user->id => ['role' => OrganizationRole::Owner->value],
        ]);
        $user->forceFill(['current_organization_id' => $organization->id])->save();
    }
}
