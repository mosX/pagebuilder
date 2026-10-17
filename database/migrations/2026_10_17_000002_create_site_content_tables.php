<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Site visitors with accounts, translations of records, and site settings
     * for languages and the visitor account area.
     */
    public function up(): void
    {
        Schema::create('site_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->boolean('is_blocked')->default(false);
            $table->rememberToken();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            // Accounts belong to one company site: the same e-mail may register on several sites.
            $table->unique(['tenant_id', 'email']);
        });

        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('record_id');
            $table->string('locale', 8);
            // Translated values of the record's text and block fields.
            $table->json('data');
            $table->timestamps();

            $table->unique(['collection_id', 'record_id', 'locale']);
        });

        Schema::table('site_settings', function (Blueprint $table) {
            $table->json('languages')->nullable()->after('footer');
            $table->json('account')->nullable()->after('languages');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn(['languages', 'account']);
        });

        Schema::dropIfExists('translations');
        Schema::dropIfExists('site_members');
    }
};
