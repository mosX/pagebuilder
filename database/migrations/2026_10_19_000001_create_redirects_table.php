<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redirects of company sites: created automatically when an address changes, or by hand
     * (e.g. old URLs of a previous site). "/blog/*" redirects a whole section.
     */
    public function up(): void
    {
        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('from_path', 191);
            $table->string('to_path', 1024);
            $table->unsignedSmallInteger('status')->default(301);
            // Created by the site itself when a slug or section path changed.
            $table->boolean('is_auto')->default(false);
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'from_path']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            // Local time of the company: scheduled publishing follows it.
            $table->string('timezone', 64)->default('Europe/Moscow')->after('slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });

        Schema::dropIfExists('redirects');
    }
};
