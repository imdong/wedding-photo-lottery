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
        Schema::create('draw_winners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draw_round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('photo_id')->constrained()->restrictOnDelete();
            $table->decimal('photo_weight', 10, 6);
            $table->unsignedSmallInteger('position');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['draw_round_id', 'user_id']);
            $table->unique(['draw_round_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('draw_winners');
    }
};
