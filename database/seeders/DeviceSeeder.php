<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DeviceSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            ['ambient_id' => 1, 'name' => 'MANTENIMIENTO'],
            ['ambient_id' => 2, 'name' => 'MECANIZADO'],
            ['ambient_id' => 3, 'name' => 'SOLDADURA'],
            ['ambient_id' => 4, 'name' => 'AUTOMOTRIZ'],
            ['ambient_id' => 5, 'name' => 'DIESEL'],
            ['ambient_id' => 6, 'name' => 'MOTOS'],
            ['ambient_id' => 7, 'name' => 'DIBUJO'],
            ['ambient_id' => 8, 'name' => 'AUTOCAD'],
            ['ambient_id' => 9, 'name' => 'MADERAS'],
            ['ambient_id' => 10, 'name' => 'SISTEMAS 1'],
            ['ambient_id' => 11, 'name' => 'SISTEMAS 2'],
            ['ambient_id' => 12, 'name' => 'SISTEMAS 3'],
            ['ambient_id' => 13, 'name' => 'ELECTRICIDAD 1'],
            ['ambient_id' => 14, 'name' => 'ELECTRICIDAD 2'],
            ['ambient_id' => 15, 'name' => 'ELECTRICIDAD 3'],
            ['ambient_id' => 16, 'name' => 'ELECTRICIDAD 4'],
            ['ambient_id' => 17, 'name' => 'ENERGIAS RENOVABLES'],
            ['ambient_id' => 18, 'name' => 'SISTEMAS INTEGRADOS'],
            ['ambient_id' => 19, 'name' => 'CONFECCION'],
            ['ambient_id' => 20, 'name' => 'PATRONAJE'],
            ['ambient_id' => 21, 'name' => 'APOYO 2'],
            ['ambient_id' => 22, 'name' => 'APOYO 3'],
            ['ambient_id' => 24, 'name' => 'SIMULADORES MAQUINARIA PESADA'],
            ['ambient_id' => 26, 'name' => 'HIDRAULICA AUTOMA'],
            ['ambient_id' => 34, 'name' => 'CONSTRUCCION'],
        ];

        foreach ($data as &$item) {
            $item['ip_address'] = null;
            $item['status'] = 1;
            $item['created_at'] = now();
            $item['updated_at'] = now();
        }

        DB::table('devices')->upsert(
            $data,
            ['ambient_id'], // columna única
            ['name', 'ip_address', 'status', 'updated_at'] // columnas a actualizar
        );
    }
}
