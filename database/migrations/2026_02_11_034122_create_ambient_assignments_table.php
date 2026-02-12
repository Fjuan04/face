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
        Schema::create('ambient_assignments', function (Blueprint $table) {
            $table->id();
            $table->integer('ambient_id')->unsigned();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('assigned_by')->references('id')->on('users');
            $table->enum('status',['in_progress','closing','finished'])->default('in_progress');
            $table->timestamp('start_time')->useCurrent();
            $table->timestamp('end_time')->nullable();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ambient_assignments');
    }
};
