<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * System collections (e.g. the company's pages) and their system fields are created
     * by the platform and cannot be removed by users.
     */
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->string('kind', 32)->nullable()->after('display_field');
            $table->unique(['tenant_id', 'kind']);
        });

        Schema::table('collection_fields', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('is_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('collection_fields', function (Blueprint $table) {
            $table->dropColumn('is_system');
        });

        Schema::table('collections', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'kind']);
            $table->dropColumn('kind');
        });
    }
};
