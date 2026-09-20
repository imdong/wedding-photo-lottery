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
        Schema::table('photos', function (Blueprint $table) {
            $table->renameColumn('file_hash', 'upload_file_hash');
        });

        Schema::table('photos', function (Blueprint $table) {
            $table->char('original_file_hash', 64)
                ->nullable()
                ->unique()
                ->after('original_filename');
        });

        DB::table('photos')->update([
            'original_file_hash' => DB::raw('upload_file_hash'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->dropUnique(['original_file_hash']);
            $table->dropColumn('original_file_hash');
        });

        Schema::table('photos', function (Blueprint $table) {
            $table->renameColumn('upload_file_hash', 'file_hash');
        });
    }
};
