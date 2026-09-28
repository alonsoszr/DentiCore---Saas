<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `outbox_messages` (SDD §2.12; DD-41, RNF-078): base BU con tenant_id nulable. Todo
 * efecto asíncrono se escribe aquí en la misma transacción que la operación de negocio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->uuid()->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->string('type', 80);
            $table->string('queue', 20);
            $table->jsonb('payload');
            $table->timestampTz('available_at')->useCurrent();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('consumed_at')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->string('last_error', 300)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->nullable();
        });

        DB::statement('CREATE INDEX outbox_messages_pending_idx ON outbox_messages (available_at) WHERE dispatched_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
