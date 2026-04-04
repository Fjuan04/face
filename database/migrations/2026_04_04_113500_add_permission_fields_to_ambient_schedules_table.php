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
            $table->boolean('admin_permission')->default(false)->after('break_time');
            $table->unsignedBigInteger('user_allowed')->nullable()->after('admin_permission');
            $table->unsignedBigInteger('granted_by')->nullable()->after('user_allowed');

            $table->foreign('user_allowed')->references('id')->on('users')->nullOnDelete();
            $table->foreign('granted_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ambient_schedules', function (Blueprint $table) {
            $table->dropForeign(['user_allowed']);
            $table->dropForeign(['granted_by']);
            $table->dropColumn(['admin_permission', 'user_allowed', 'granted_by']);
        });
    }
};
