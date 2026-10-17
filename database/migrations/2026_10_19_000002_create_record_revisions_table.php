<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * History of records: the values after every change, so a mistake can be rolled back.
     */
    public function up(): void
    {
        Schema::create('record_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('record_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Who changed it when it was not a signed-in user: an API key, an automation, the site.
            $table->string('actor')->nullable();
            $table->json('changes');
            $table->json('values');
            $table->timestamp('created_at');

            $table->index(['collection_id', 'record_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('record_revisions');
    }
};
