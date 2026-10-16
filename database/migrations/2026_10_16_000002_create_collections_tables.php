<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Metadata describing user-defined collections. Each collection owns a real table
     * (collections.table_name) that is created and altered by SchemaManager.
     */
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('table_name', 64)->unique();
            $table->string('display_field', 40)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('collection_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('label');
            $table->string('type', 32);
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_unique')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('related_collection_id')->nullable()->constrained('collections')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['collection_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collection_fields');
        Schema::dropIfExists('collections');
    }
};
