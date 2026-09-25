<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * technical_specs.md §3.4, con dos desviaciones confirmadas con el usuario:
     * - document_id y phone son `text` (no varchar(20)): guardan el texto cifrado con
     *   AES-256, mucho más largo que el valor original. El límite de 20 caracteres se
     *   valida sobre el valor en claro.
     * - document_id_hash (índice ciego HMAC-SHA256) lleva la unicidad por clínica, ya que
     *   el texto cifrado de un mismo DNI es distinto en cada cifrado.
     */
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            // Una cuenta de portal (role='patient') se vincula como máximo a una ficha.
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $table->text('document_id');
            $table->char('document_id_hash', 64);
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->date('birth_date');
            $table->text('phone')->nullable();
            $table->string('email', 180)->nullable();
            $table->jsonb('medical_history')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'document_id_hash']);
            $table->index(['tenant_id', 'last_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
