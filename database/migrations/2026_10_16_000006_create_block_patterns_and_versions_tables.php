<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reusable sections (patterns) and drafts/revisions of everything edited
     * in the block constructor.
     */
    public function up(): void
    {
        Schema::create('block_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('tree');
            $table->timestamps();
        });

        Schema::create('block_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // What was edited: field:{collection}:{record}:{field}, template:{collection} or pattern:{id}.
            $table->string('target', 191);
            $table->string('kind', 16); // draft | revision
            $table->json('tree');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'target', 'kind']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('block_versions');
        Schema::dropIfExists('block_patterns');
    }
};
