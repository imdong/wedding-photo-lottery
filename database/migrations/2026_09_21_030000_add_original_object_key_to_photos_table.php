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
        Schema::table('photos', function (Blueprint $table): void {
            $table->string('original_object_key')
                ->nullable()
                ->unique()
                ->after('object_key');
        });

        DB::table('photos')->update([
            'original_object_key' => DB::raw('object_key'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table): void {
            $table->dropUnique(['original_object_key']);
            $table->dropColumn('original_object_key');
        });
    }
};
