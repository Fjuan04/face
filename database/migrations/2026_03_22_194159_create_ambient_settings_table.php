<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('ambient_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ambient_id')->unique();
            $table->decimal('x_coordinate', 5, 2)->nullable();
            $table->decimal('y_coordinate', 5, 2)->nullable();
            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ambient_settings');
    }
};
