<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extra profile fields of site visitors (phone, company…), configured per site.
     */
    public function up(): void
    {
        Schema::table('site_members', function (Blueprint $table) {
            $table->json('profile')->nullable()->after('email_verified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_members', function (Blueprint $table) {
            $table->dropColumn('profile');
        });
    }
};
