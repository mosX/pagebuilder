<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A date field whose value schedules a record's appearance on the site.
     */
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->string('site_publish_at_field', 24)->nullable()->after('site_published_field');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropColumn('site_publish_at_field');
        });
    }
};
