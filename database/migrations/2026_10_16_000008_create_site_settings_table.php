<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Design and layout of a company site: theme, navigation menu, header and footer
     * block trees, logo and favicon.
     */
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('theme')->nullable();
            $table->json('menu')->nullable();
            $table->json('header')->nullable();
            $table->json('footer')->nullable();
            $table->string('logo', 1024)->nullable();
            $table->string('favicon', 1024)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
