<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Group;
use App\Imports\StudentsImport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class AttendanceManagementController extends Controller
{
    /**
     * Importar estudiantes desde un archivo Excel/CSV (Admin)
     */
    public function importStudents(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv,txt'
        ]);

        try {
            Excel::import(new StudentsImport, $request->file('file'));

            return response()->json([
                'success' => true,
                'message' => 'Estudiantes importados correctamente.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error durante la importación: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Listar los grupos asignados al docente autenticado
     */
    public function getTeacherGroups(Request $request)
    {
        $user = $request->user();
        
        // Obtenemos los grupos a través de la relación belongsToMany
        $groups = $user->groups()->get();

        return response()->json([
            'success' => true,
            'data' => $groups
        ]);
    }

    /**
     * Listar los estudiantes de un grupo específico
     */
    public function getGroupStudents($groupId)
    {
        $group = Group::findOrFail($groupId);
        
        // Estudiantes del grupo (rol 3)
        $students = $group->users()->where('role_id', 3)->get();

        return response()->json([
            'success' => true,
            'data' => $students
        ]);
    }

    /**
     * Subir/Actualizar la foto de un estudiante (Docente)
     */
    public function uploadStudentPhoto(Request $request, $studentId)
    {
        $request->validate([
            'photo' => 'required|image|mimes:jpeg,jpg,png|max:4096', // 4MB
        ]);

        $student = User::where('role_id', 3)->findOrFail($studentId);

        if ($request->hasFile('photo')) {
            // Eliminar foto anterior si existe para no llenar el disco
            if ($student->photo) {
                Storage::delete($student->photo);
            }

            // Guardar en el disco faces (similar a UserController)
            $path = $request->file('photo')->store('faces');
            
            $student->photo = $path;
            $student->save();

            return response()->json([
                'success' => true,
                'message' => 'Foto del estudiante actualizada correctamente.',
                'path' => $path
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'No se proporcionó ninguna imagen.'
        ], 400);
    }

    /**
     * Listar todos los docentes del sistema (Admin)
     */
    public function teachers()
    {
        $teachers = User::where('role_id', 2)->get();
        return response()->json([
            'success' => true,
            'data' => $teachers
        ]);
    }

    /**
     * Listar todos los grupos del sistema (Admin)
     */
    public function index()
    {
        $groups = Group::all();
        return response()->json([
            'success' => true,
            'data' => $groups
        ]);
    }

    /**
     * Asignar un docente a uno o varios grupos (Admin)
     */
    public function assignTeacherToGroups(Request $request)
    {
        $request->validate([
            'teacher_id' => 'required|exists:users,id',
            'group_ids' => 'required|array',
            'group_ids.*' => 'exists:groups,id'
        ]);

        $teacher = User::findOrFail($request->teacher_id);
        
        // syncWithoutDetaching añade los nuevos sin borrar los que ya tenía asignados.
        // Si prefieres que reemplace totalmente los grupos, cambia a: sync($request->group_ids)
        $teacher->groups()->syncWithoutDetaching($request->group_ids);

        return response()->json([
            'success' => true,
            'message' => 'Docente asignado a los grupos correctamente.'
        ]);
    }
}
