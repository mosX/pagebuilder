<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Password reset of site visitors (per account: the same e-mail may exist on several
     * sites), and the site's own "page not found" built in the block constructor.
     */
    public function up(): void
    {
        Schema::create('site_member_password_resets', function (Blueprint $table) {
            $table->foreignId('site_member_id')->primary()->constrained()->cascadeOnDelete();
            // sha256 of the token from the link.
            $table->string('token_hash', 64);
            $table->timestamp('created_at');
        });

        Schema::table('site_settings', function (Blueprint $table) {
            $table->json('not_found')->nullable()->after('footer');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn('not_found');
        });

        Schema::dropIfExists('site_member_password_resets');
    }
};
