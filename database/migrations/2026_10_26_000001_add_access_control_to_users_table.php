<?php

use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', array_map(
                static fn (Role $role): string => $role->value,
                Role::cases(),
            ))
                ->default(Role::Ouvrier->value)
                ->after('email')
                ->index();

            $table->boolean('is_active')->default(true)->after('role')->index();
            $table->timestamp('deactivated_at')->nullable()->after('is_active');
            $table->string('deactivation_reason', 500)->nullable()->after('deactivated_at');
            $table->timestamp('activated_at')->nullable()->after('email_verified_at');
            $table->timestamp('last_login_at')->nullable()->after('activated_at');

            // Secrets TOTP Filament, chiffrés par les casts du modèle User.
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropIndex(['is_active']);
            $table->dropColumn([
                'role',
                'is_active',
                'deactivated_at',
                'deactivation_reason',
                'activated_at',
                'last_login_at',
                'app_authentication_secret',
                'app_authentication_recovery_codes',
            ]);
        });
    }
};
