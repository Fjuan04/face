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
        Schema::create('ambient_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ambient_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('teacher_name')->nullable();
            $table->string('codeTab')->nullable();
            $table->string('class')->nullable();
            $table->time('start_time');
            $table->time('end_time');
            $table->date('date');
            $table->unsignedBigInteger('open_by')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->boolean('break_time')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ambient_schedules');
    }
};
