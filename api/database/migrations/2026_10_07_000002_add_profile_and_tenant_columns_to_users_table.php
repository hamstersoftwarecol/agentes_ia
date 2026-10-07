<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('email');
            // Empresa activa de la cuenta. El MVP solo permite una, pero el
            // modelo ya soporta varias (US-014, post-MVP).
            $table->foreignId('current_organization_id')
                ->nullable()
                ->after('remember_token')
                ->constrained('organizations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_organization_id');
            $table->dropColumn('phone');
        });
    }
};
