<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A collection can be published as a section of the company site:
     * every record gets a page at /{site_path}/{value of site_slug_field},
     * rendered with the collection's block template.
     */
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->string('site_path', 40)->nullable()->after('kind');
            $table->string('site_slug_field', 40)->nullable()->after('site_path');
            $table->string('site_published_field', 40)->nullable()->after('site_slug_field');
            $table->json('site_template')->nullable()->after('site_published_field');

            $table->unique(['tenant_id', 'site_path']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'site_path']);
            $table->dropColumn(['site_path', 'site_slug_field', 'site_published_field', 'site_template']);
        });
    }
};
