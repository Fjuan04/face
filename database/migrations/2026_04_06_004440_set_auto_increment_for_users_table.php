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
        // Ajustamos el contador de IDs para que los automáticos (aprendices) empiecen en 1,000,000
        // Esto deja el rango 1 - 999,999 libre para inserciones manuales de docentes de CRONODE.
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE users AUTO_INCREMENT = 1000000');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No es estrictamente reversible sin riesgo de conflicto, se deja vacío.
    }
};
