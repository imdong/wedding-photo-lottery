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
        Schema::create('draw_rounds', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('round_number')->unique();
            $table->unsignedSmallInteger('draw_count');
            $table->string('status', 20)->default('prepared');
            $table->json('candidate_snapshot');
            $table->json('settings_snapshot');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('draw_rounds');
    }
};
