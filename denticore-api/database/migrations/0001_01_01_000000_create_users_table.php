<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique()->default(DB::raw('gen_random_uuid()'));
            // tenant_id es NULL únicamente para role='super_admin' (invariante de aplicación,
            // no expresable como columna NOT NULL condicional; se aplica en Fase 2/RBAC).
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('email', 180);
            $table->string('password');
            $table->enum('role', ['super_admin', 'clinic_admin', 'dentist', 'receptionist', 'patient']);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'email']);
            $table->index('role');
        });

        // Postgres no considera dos NULL iguales, así que UNIQUE(tenant_id, email) por sí sola
        // no impide dos super_admin (tenant_id NULL) con el mismo email. Índice único parcial
        // adicional, no contemplado literalmente en el SDD pero necesario para cerrar ese hueco.
        DB::statement(
            'CREATE UNIQUE INDEX users_super_admin_email_unique ON users (email) WHERE tenant_id IS NULL'
        );

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
