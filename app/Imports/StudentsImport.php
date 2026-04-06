<?php

namespace App\Imports;

use App\Models\User;
use App\Models\Group;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Hash;

class StudentsImport implements ToModel, WithHeadingRow
{
    /**
     * @param array $row
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function model(array $row)
    {
        // Limpiamos los datos del row por si vienen con espacios
        $codeTab = trim($row['codetab'] ?? $row['ficha'] ?? '');
        $document = trim($row['document'] ?? '');

        if (empty($codeTab) || empty($document)) {
            return null;
        }

        // 1. Encontrar o crear el grupo (ficha)
        $group = Group::firstOrCreate(
            ['code_tab' => $codeTab],
            ['name' => $row['group_name'] ?? "Grupo $codeTab"]
        );

        // 2. Crear o actualizar el estudiante
        // Dejamos que el ID se asigne automáticamente (empezando desde 1M)
        // Pero usamos el documento para encontrar al usuario si ya existe
        $user = User::updateOrCreate(
            ['document' => $document],
            [
                'fullname' => $row['fullname'] ?? $row['nombre_completo'] ?? '',
                'email'    => $row['email'] ?? ($document . '@ejemplo.com'),
                'gender'   => $row['gender'] ?? 'N/A',
                'password' => $document, 
                'role_id'  => 3, 
                'must_change_password' => true,
                'is_active' => true,
            ]
        );

        // 3. Asociar el estudiante al grupo (evitando duplicados en la tabla pivote)
        $user->groups()->syncWithoutDetaching([$group->id]);

        return $user;
    }
}
