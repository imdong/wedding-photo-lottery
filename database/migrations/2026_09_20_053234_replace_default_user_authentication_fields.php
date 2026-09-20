<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->dropColumn(['name', 'email', 'email_verified_at', 'password', 'remember_token']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->unique();
            $table->char('access_token_hash', 64)->nullable()->index();
            $table->string('invite_code', 12)->unique();
            $table->foreignId('invited_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invited_by_user_id');
            $table->dropIndex(['access_token_hash']);
            $table->dropUnique(['phone']);
            $table->dropUnique(['invite_code']);
            $table->dropColumn(['phone', 'access_token_hash', 'invite_code']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
        });
    }
};
