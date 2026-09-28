<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `idempotency_keys` (TASK-009; SDD §2.12, §1.7; DD-45, RNF-079): respuesta guardada 24 h por
 * (subject, key) para que un reintento de escritura no duplique efectos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->string('subject', 80);
            $table->uuid('key');
            $table->char('request_hash', 64);
            $table->string('method', 7);
            $table->string('route_name', 120)->nullable();
            $table->smallInteger('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->nullable();

            $table->unique(['subject', 'key']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
