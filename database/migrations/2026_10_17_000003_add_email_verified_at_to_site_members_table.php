<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Site visitors see their form submissions only after confirming the e-mail,
     * so nobody can read another person's submissions by registering their address.
     */
    public function up(): void
    {
        Schema::table('site_members', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable()->after('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_members', function (Blueprint $table) {
            $table->dropColumn('email_verified_at');
        });
    }
};
