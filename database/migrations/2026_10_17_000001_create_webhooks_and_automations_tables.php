<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Logic without code: webhooks (with a delivery log) and automations (with a run log).
     */
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('url', 2048);
            $table->json('events');
            // Only events of this collection; null means every collection.
            $table->string('collection', 40)->nullable();
            $table->string('secret', 64);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_called_at')->nullable();
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_id')->constrained()->cascadeOnDelete();
            $table->string('event', 64);
            $table->json('payload');
            $table->unsignedSmallInteger('status')->nullable();
            $table->boolean('success')->default(false);
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->string('error', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['webhook_id', 'id']);
        });

        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->json('trigger');
            $table->json('actions');
            $table->unsignedInteger('runs_count')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $table->boolean('success');
            $table->string('subject')->nullable();
            $table->string('message', 1000)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['automation_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automations');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhooks');
    }
};
