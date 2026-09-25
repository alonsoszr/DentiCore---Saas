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
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('name', 150);
            // Extensión no contemplada en el SDD original: necesaria para que /auth/login
            // pueda identificar el tenant cuando el email de un usuario no es único global,
            // sino por clínica (decisión confirmada con el usuario).
            $table->string('slug', 150)->unique();
            $table->enum('subscription_plan', ['basic', 'pro', 'enterprise']);
            $table->enum('status', ['active', 'suspended', 'cancelled'])->default('active');
            $table->jsonb('settings')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
