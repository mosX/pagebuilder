<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Visit statistics of company sites as daily totals: no personal data is stored.
     */
    public function up(): void
    {
        // Per page; the row with an empty path holds the whole site's totals for the day.
        Schema::create('site_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('path', 191);
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('visitors')->default(0);

            $table->unique(['tenant_id', 'date', 'path']);
        });

        Schema::create('site_referrers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('host', 191);
            $table->unsignedInteger('views')->default(0);

            $table->unique(['tenant_id', 'date', 'host']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_referrers');
        Schema::dropIfExists('site_visits');
    }
};
