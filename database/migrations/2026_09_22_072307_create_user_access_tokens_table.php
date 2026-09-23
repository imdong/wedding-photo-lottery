<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamps();
        });

        DB::table('users')
            ->whereNotNull('access_token_hash')
            ->orderBy('id')
            ->chunkById(500, function ($users): void {
                DB::table('user_access_tokens')->insert(
                    $users->map(fn (object $user): array => [
                        'user_id' => $user->id,
                        'token_hash' => $user->access_token_hash,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->all(),
                );
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_access_tokens');
    }
};
