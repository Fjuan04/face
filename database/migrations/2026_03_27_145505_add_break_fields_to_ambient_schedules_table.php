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
        Schema::table('ambient_schedules', function (Blueprint $table) {
            $table->timestamp('start_break')->nullable()->after('break_time');
            $table->timestamp('end_break')->nullable()->after('start_break');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ambient_schedules', function (Blueprint $table) {
            $table->dropColumn(['start_break', 'end_break']);
        });
    }
};
