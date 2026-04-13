<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\AmbientSchedule;
use App\Models\User;

class OllamaAgentService
{
    protected string $baseUrl = 'http://localhost:11434/api/chat';
    protected string $model   = 'qwen2.5:3b';

    protected array $ambientMap = [
        'mantenimiento'                 => 1,
        'mecanizado'                    => 2,
        'soldadura'                     => 3,
        'automotriz'                    => 4,
        'diesel'                        => 5,
        'motos'                         => 6,
        'dibujo'                        => 7,
        'autocad'                       => 8,
        'maderas'                       => 9,
        'sistemas 1'                    => 10,
        'sistemas1'                     => 10,
        'sistemas 2'                    => 11,
        'sistemas2'                     => 11,
        'sistemas 3'                    => 12,
        'sistemas3'                     => 12,
        'electricidad 1'                => 13,
        'electricidad1'                 => 13,
        'electricidad 2'                => 14,
        'electricidad2'                 => 14,
        'electricidad 3'                => 15,
        'electricidad3'                 => 15,
        'electricidad 4'                => 16,
        'electricidad4'                 => 16,
        'energias renovables'           => 17,
        'energías renovables'           => 17,
        'sistemas integrados'           => 18,
        'confeccion'                    => 19,
        'confección'                    => 19,
        'patronaje'                     => 20,
        'apoyo 2'                       => 21,
        'apoyo2'                        => 21,
        'apoyo 3'                       => 22,
        'apoyo3'                        => 22,
        'simuladores maquinaria pesada' => 24,
        'simuladores'                   => 24,
        'hidraulica automa'             => 26,
        'hidráulica automa'             => 26,
        'hidraulica'                    => 26,
        'construccion'                  => 34,
        'construcción'                  => 34,
    ];

    protected array $ambientNames = [
        1  => 'Mantenimiento',
        2  => 'Mecanizado',
        3  => 'Soldadura',
        4  => 'Automotriz',
        5  => 'Diesel',
        6  => 'Motos',
        7  => 'Dibujo',
        8  => 'AutoCAD',
        9  => 'Maderas',
        10 => 'Sistemas 1',
        11 => 'Sistemas 2',
        12 => 'Sistemas 3',
        13 => 'Electricidad 1',
        14 => 'Electricidad 2',
        15 => 'Electricidad 3',
        16 => 'Electricidad 4',
        17 => 'Energías Renovables',
        18 => 'Sistemas Integrados',
        19 => 'Confección',
        20 => 'Patronaje',
        21 => 'Apoyo 2',
        22 => 'Apoyo 3',
        24 => 'Simuladores Maquinaria Pesada',
        26 => 'Hidráulica Automa',
        34 => 'Construcción',
    ];

    protected array $programs = [
        '228118'  => 'Análisis y Desarrollo de Software',
        '821222'  => 'Electricidad Industrial',
        '821620'  => 'Gestión del Mantenimiento de Automotores',
        '834258'  => 'Soldadura de Productos Metálicos en Platina',
        '223206'  => 'Mantenimiento Mecánico Industrial',
        '837101'  => 'Mecánica de Maquinaria Industrial',
        '226701'  => 'Coordinador de Sistemas Integrados de Gestión',
        '225219'  => 'Dibujo y Modelado Arquitectónico y de Ingeniería',
        '838318'  => 'Mantenimiento de Motocicletas y Motocarros',
        '223104'  => 'Construcción en Edificaciones',
        '225311'  => 'Levantamientos Topográficos y Georreferenciación',
        '831102'  => 'Operación en Torno y Fresadora',
        '838100'  => 'Mantenimiento de los Motores Diésel',
        '835123'  => 'Auxiliar Carpintero Instalador',
        '935105'  => 'Manejo de Maquinaria de Confección Industrial para Ropa Exterior',
        '524500'  => 'Patronaje Industrial de Prendas de Vestir',
        '137136'  => 'Integración de Operaciones Logísticas',
        '225220'  => 'Dibujo Mecánico',
        '223213'  => 'Mantenimiento Electromecánico Industrial',
    ];

    // ─────────────────────────────────────────────────────────────
    // MÉTODO PRINCIPAL
    // ─────────────────────────────────────────────────────────────

    public function handleChat(array $messages): string
    {
        $dateContext = $this->buildDateContext(now());

        array_unshift($messages, [
            'role'    => 'system',
            'content' => $this->buildSystemPrompt($dateContext),
        ]);

        $tools = $this->getAvailableTools();

        // ── Primera llamada ──
        $response = Http::post($this->baseUrl, [
            'model'    => $this->model,
            'messages' => $messages,
            'tools'    => $tools,
            'stream'   => false,
        ])->json();

        $message = $response['message'] ?? null;

        if (!$message) {
            Log::error('OllamaAgentService: respuesta vacía', $response ?? []);
            return 'No pude conectarme con el asistente. Intenta de nuevo.';
        }

        // ── El modelo usó herramientas ──
        if (!empty($message['tool_calls'])) {
            $messages[] = $message;

            foreach ($message['tool_calls'] as $toolCall) {
                $functionName = $toolCall['function']['name'];
                $arguments    = $toolCall['function']['arguments'];

                if (is_string($arguments)) {
                    $arguments = json_decode($arguments, true) ?? [];
                }

                Log::info("FACE → tool: {$functionName}", $arguments);

                $messages[] = [
                    'role'    => 'tool',
                    'content' => json_encode(
                        $this->executeTool($functionName, $arguments),
                        JSON_UNESCAPED_UNICODE
                    ),
                ];
            }

            $final = Http::post($this->baseUrl, [
                'model'    => $this->model,
                'messages' => $messages,
                'stream'   => false,
            ])->json();

            return $final['message']['content']
                ?? 'No pude generar una respuesta. Intenta de nuevo.';
        }

        return $message['content'];
    }

    // ─────────────────────────────────────────────────────────────
    // CONTEXTO DE FECHAS
    // ─────────────────────────────────────────────────────────────

    private function buildDateContext($now): array
    {
        return [
            'today'          => $now->toDateString(),
            'yesterday'      => $now->copy()->subDay()->toDateString(),
            'weekStart'      => $now->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->toDateString(),
            'weekEnd'        => $now->copy()->endOfWeek(\Carbon\Carbon::SUNDAY)->toDateString(),
            'lastWeekStart'  => $now->copy()->subWeek()->startOfWeek(\Carbon\Carbon::MONDAY)->toDateString(),
            'lastWeekEnd'    => $now->copy()->subWeek()->endOfWeek(\Carbon\Carbon::SUNDAY)->toDateString(),
            'monthStart'     => $now->copy()->startOfMonth()->toDateString(),
            'monthEnd'       => $now->copy()->endOfMonth()->toDateString(),
            'lastMonthStart' => $now->copy()->subMonth()->startOfMonth()->toDateString(),
            'lastMonthEnd'   => $now->copy()->subMonth()->endOfMonth()->toDateString(),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // SYSTEM PROMPT
    // ─────────────────────────────────────────────────────────────

    private function buildSystemPrompt(array $d): string
    {
        $now      = now();
        $time     = $now->format('H:i');
        $dayName  = $now->locale('es')->dayName;
        $today    = $d['today'];

        $ambientList = implode("\n", array_map(
            fn($id, $name) => "  - {$name} (ID: {$id})",
            array_keys($this->ambientNames),
            $this->ambientNames
        ));

        $programList = implode("\n", array_map(
            fn($code, $name) => "  - {$name} (código: {$code})",
            array_keys($this->programs),
            $this->programs
        ));

        return <<<PROMPT
Eres FACE, el asistente inteligente del sistema de gestión de ambientes del centro de formación.
Respondes SIEMPRE en español, con tono amable y profesional.

════════════════════════════════════
FECHA Y HORA ACTUAL
════════════════════════════════════
- Hoy         : {$dayName} {$today}, {$time}
- Ayer        : {$d['yesterday']}
- Esta semana : {$d['weekStart']} al {$d['weekEnd']}
- Sem. pasada : {$d['lastWeekStart']} al {$d['lastWeekEnd']}
- Este mes    : {$d['monthStart']} al {$d['monthEnd']}
- Mes pasado  : {$d['lastMonthStart']} al {$d['lastMonthEnd']}

Cuando el usuario use expresiones relativas (hoy, ayer, esta semana, este mes, etc.)
usa EXACTAMENTE las fechas de la tabla anterior. NUNCA las calcules tú mismo.
NUNCA pidas al usuario que proporcione fechas si ya puedes inferirlas de la tabla.

════════════════════════════════════
VOCABULARIO — BILINGÜE
════════════════════════════════════
Los usuarios pueden usar vocabulario informal. Tú siempre respondes con el vocabulario oficial del sistema.

| El usuario puede decir | Tú siempre respondes con |
|------------------------|--------------------------|
| salón / salones        | ambiente / ambientes      |
| docente / profe        | instructor                |
| estudiante / alumno    | aprendiz                  |
| curso / grupo          | programa de formación     |
| sede                   | centro de formación       |

REGLA DE ORO: sin importar cómo pregunte el usuario, tú siempre usas el vocabulario
de la columna derecha. NUNCA mezcles los dos vocabularios en una misma respuesta.

Ejemplo correcto:
  Usuario: "¿Cuántos salones hay?"
  FACE: "Hay 25 ambientes en el centro de formación."

Ejemplo incorrecto:
  FACE: "Hay 25 ambientes/salones..." ← NUNCA hagas esto.

════════════════════════════════════
CONOCIMIENTO ESTÁTICO DEL SISTEMA
(Responde directamente sin usar herramientas)
════════════════════════════════════

## Ambientes de formación registrados: 25 en total
{$ambientList}

## Programas de formación activos:
{$programList}

## Roles en el sistema:
- Administrador: gestiona el sistema completo
- Instructor: imparte clases en los ambientes
- Aprendiz: asiste a las clases

════════════════════════════════════
REGLAS DE USO DE HERRAMIENTAS
════════════════════════════════════

USA UNA HERRAMIENTA cuando pregunten por:
  ✓ Estado actual de un ambiente (ocupado / libre)
  ✓ Horario de clases de un día o rango de fechas
  ✓ Estadísticas: cuántas clases, ranking, horas pico
  ✓ Carga de un instructor específico
  ✓ Información de un aprendiz o instructor por nombre

NO USES HERRAMIENTAS (responde directamente) cuando pregunten por:
  ✗ Cuántos ambientes hay en total → son 25
  ✗ Qué ambientes existen → usa la lista de arriba
  ✗ Qué programas de formación ofrece el centro → usa la lista de arriba
  ✗ Qué roles existen → usa la información de arriba

════════════════════════════════════
GUÍA DE SELECCIÓN DE HERRAMIENTAS
════════════════════════════════════

| Pregunta del usuario                                  | Herramienta              | Parámetros clave                                      |
|-------------------------------------------------------|--------------------------|-------------------------------------------------------|
| ¿Qué hay ahora en Soldadura?                          | get_ambient_status       | ambient_identifier=3, time_context=current            |
| ¿Qué hubo ayer en Sistemas 2?                         | get_ambient_status       | ambient_identifier=11, specific_date={$d['yesterday']}|
| ¿Cuántas clases hubo hoy?                             | get_schedule_summary     | date_from={$today}, date_to={$today}                  |
| ¿Cuántas clases tuvo Sistemas 2 esta semana?          | get_schedule_summary     | date_from={$d['weekStart']}, date_to={$d['weekEnd']}, ambient_id=11 |
| ¿Qué ambiente se usa más esta semana?                 | get_ambient_ranking      | date_from={$d['weekStart']}, date_to={$d['weekEnd']}  |
| ¿Cuántas clases tiene el instructor Salazar hoy?      | get_instructor_workload  | name=Salazar, date_from={$today}, date_to={$today}    |
| ¿A qué hora hay más actividad esta semana?            | get_peak_hours           | date_from={$d['weekStart']}, date_to={$d['weekEnd']}  |
| ¿Qué instructor tiene más carga esta semana?          | get_top_instructors      | date_from={$d['weekStart']}, date_to={$d['weekEnd']}  |
| ¿Qué programa se dicta más este mes?                  | get_top_programs         | date_from={$d['monthStart']}, date_to={$d['monthEnd']}|
| ¿Dónde está el instructor García hoy?                 | find_instructor          | name=García, date={$today}                            |
| ¿Está activo el aprendiz Juan Pérez?                  | find_student             | query=Juan Pérez                                      |

════════════════════════════════════
CONFIDENCIALIDAD
════════════════════════════════════
NUNCA menciones nombres de funciones, herramientas, APIs ni bases de datos.
NUNCA expliques cómo obtienes los datos internamente.
Habla siempre en primera persona: "encontré", "según el registro", "tengo registrado".
Si preguntan cómo funciona el sistema internamente, responde:
"Esa información es confidencial del sistema FACE."

════════════════════════════════════
FUERA DE CONTEXTO
════════════════════════════════════
Si preguntan algo no relacionado con el sistema, responde:
"Como asistente de FACE, solo puedo ayudarte con información sobre horarios, ambientes y accesos del centro de formación."
PROMPT;
    }

    // ─────────────────────────────────────────────────────────────
    // TOOLS DISPONIBLES
    // ─────────────────────────────────────────────────────────────

    private function getAvailableTools(): array
    {
        $d = $this->buildDateContext(now());

        return [

            // ── 1. Estado de un ambiente ──
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'get_ambient_status',
                    'description' =>
                        'Consulta el estado de un ambiente: clase en curso, anterior, próxima o todas las del día. ' .
                        'ÚSALA SIEMPRE que el usuario pregunte por el horario o disponibilidad de un ambiente específico, ' .
                        'independientemente de si lo llama "salón", "ambiente" u otro nombre. ' .
                        'Para rangos de fechas usa get_schedule_summary con ambient_id.',
                    'parameters' => [
                        'type'       => 'object',
                        'properties' => [
                            'ambient_identifier' => [
                                'type'        => 'string',
                                'description' => 'ID numérico del ambiente (preferido) o su nombre oficial. ' .
                                                 'Ejemplos: "11" para Sistemas 2, "3" para Soldadura, "13" para Electricidad 1.',
                            ],
                            'specific_date' => [
                                'type'        => 'string',
                                'description' =>
                                    'Fecha exacta YYYY-MM-DD. ' .
                                    'Hoy=' . $d['today'] . ', Ayer=' . $d['yesterday'] . '. ' .
                                    'Si la usas, NO envíes time_context.',
                            ],
                            'time_context' => [
                                'type'        => 'string',
                                'enum'        => ['current', 'past', 'future'],
                                'description' => 'Solo si NO hay fecha específica. ' .
                                                 'current=ahora mismo, past=última clase terminada hoy, future=próxima clase hoy.',
                            ],
                        ],
                        'required' => ['ambient_identifier'],
                    ],
                ],
            ],

            // ── 2. Resumen estadístico ──
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'get_schedule_summary',
                    'description' =>
                        'Devuelve resumen estadístico de clases en un período: total, promedio por día, ' .
                        'horas de formación, ambientes e instructores activos, día más ocupado. ' .
                        'También filtra por un ambiente específico si se indica. ' .
                        'Úsala para: "¿cuántas clases/salones hubo hoy/esta semana?", ' .
                        '"¿cuántas clases tuvo Sistemas 2 esta semana?", "resumen del mes".',
                    'parameters' => [
                        'type'       => 'object',
                        'properties' => [
                            'date_from' => [
                                'type'        => 'string',
                                'description' =>
                                    'Fecha inicio YYYY-MM-DD. ' .
                                    'Hoy=' . $d['today'] .
                                    ', Inicio semana=' . $d['weekStart'] .
                                    ', Inicio mes=' . $d['monthStart'] . '.',
                            ],
                            'date_to' => [
                                'type'        => 'string',
                                'description' =>
                                    'Fecha fin YYYY-MM-DD. ' .
                                    'Hoy=' . $d['today'] .
                                    ', Fin semana=' . $d['weekEnd'] .
                                    ', Fin mes=' . $d['monthEnd'] . '. ' .
                                    'Si es un solo día, igual a date_from.',
                            ],
                            'ambient_id' => [
                                'type'        => 'integer',
                                'description' => 'Opcional. Filtra por un ambiente específico. ' .
                                                 'Sistemas 2=11, Soldadura=3, Electricidad 1=13.',
                            ],
                        ],
                        'required' => ['date_from', 'date_to'],
                    ],
                ],
            ],

            // ── 3. Ranking de ambientes ──
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'get_ambient_ranking',
                    'description' =>
                        'Muestra los ambientes con más clases en un período. ' .
                        'Úsala para: "¿qué ambiente/salón se usa más?", "ranking de ambientes", "cuál es el más activo".',
                    'parameters' => [
                        'type'       => 'object',
                        'properties' => [
                            'date_from' => ['type' => 'string', 'description' => 'Fecha inicio YYYY-MM-DD. Esta semana=' . $d['weekStart'] . ', Este mes=' . $d['monthStart'] . '.'],
                            'date_to'   => ['type' => 'string', 'description' => 'Fecha fin YYYY-MM-DD. Hoy=' . $d['today'] . ', Fin semana=' . $d['weekEnd'] . '.'],
                            'limit'     => ['type' => 'integer', 'description' => 'Cuántos mostrar. Por defecto 5.'],
                        ],
                        'required' => ['date_from', 'date_to'],
                    ],
                ],
            ],

            // ── 4. Carga de un instructor ──
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'get_instructor_workload',
                    'description' =>
                        'Muestra cuántas clases tiene un instructor en un período, en qué ambientes y qué programas dicta. ' .
                        'Úsala para: "¿cuántas clases tiene el docente/instructor X?", "horario del docente Y esta semana".',
                    'parameters' => [
                        'type'       => 'object',
                        'properties' => [
                            'name'      => ['type' => 'string', 'description' => 'Nombre completo o parcial del instructor.'],
                            'date_from' => ['type' => 'string', 'description' => 'Fecha inicio YYYY-MM-DD. Si omite, usa ' . $d['today'] . '.'],
                            'date_to'   => ['type' => 'string', 'description' => 'Fecha fin YYYY-MM-DD. Si omite, usa ' . $d['today'] . '.'],
                        ],
                        'required' => ['name'],
                    ],
                ],
            ],

            // ── 5. Horas pico ──
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'get_peak_hours',
                    'description' =>
                        'Analiza en qué franjas horarias el centro tiene más clases activas simultáneamente. ' .
                        'Úsala para: "¿a qué hora hay más actividad?", "hora pico", "cuándo está más ocupado el centro/la sede".',
                    'parameters' => [
                        'type'       => 'object',
                        'properties' => [
                            'date_from' => ['type' => 'string', 'description' => 'Fecha inicio YYYY-MM-DD. Esta semana=' . $d['weekStart'] . '.'],
                            'date_to'   => ['type' => 'string', 'description' => 'Fecha fin YYYY-MM-DD. Hoy=' . $d['today'] . '.'],
                        ],
                        'required' => ['date_from', 'date_to'],
                    ],
                ],
            ],

            // ── 6. Top instructores ──
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'get_top_instructors',
                    'description' =>
                        'Lista los instructores con más clases en un período. ' .
                        'Úsala para: "¿qué instructor/docente tiene más carga?", "ranking de instructores esta semana".',
                    'parameters' => [
                        'type'       => 'object',
                        'properties' => [
                            'date_from' => ['type' => 'string', 'description' => 'Fecha inicio YYYY-MM-DD. Esta semana=' . $d['weekStart'] . ', Este mes=' . $d['monthStart'] . '.'],
                            'date_to'   => ['type' => 'string', 'description' => 'Fecha fin YYYY-MM-DD. Hoy=' . $d['today'] . ', Fin semana=' . $d['weekEnd'] . '.'],
                            'limit'     => ['type' => 'integer', 'description' => 'Cuántos mostrar. Por defecto 5.'],
                        ],
                        'required' => ['date_from', 'date_to'],
                    ],
                ],
            ],

            // ── 7. Top programas ──
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'get_top_programs',
                    'description' =>
                        'Muestra los programas de formación más dictados en el período. ' .
                        'Úsala para: "¿qué se enseña más?", "programa/curso con más clases", ' .
                        '"¿qué curso/grupo tiene más actividad este mes?".',
                    'parameters' => [
                        'type'       => 'object',
                        'properties' => [
                            'date_from' => ['type' => 'string', 'description' => 'Fecha inicio YYYY-MM-DD. Esta semana=' . $d['weekStart'] . ', Este mes=' . $d['monthStart'] . '.'],
                            'date_to'   => ['type' => 'string', 'description' => 'Fecha fin YYYY-MM-DD. Hoy=' . $d['today'] . ', Fin mes=' . $d['monthEnd'] . '.'],
                            'limit'     => ['type' => 'integer', 'description' => 'Cuántos mostrar. Por defecto 5.'],
                        ],
                        'required' => ['date_from', 'date_to'],
                    ],
                ],
            ],

            // ── 8. Buscar instructor ──
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'find_instructor',
                    'description' =>
                        'Busca un instructor por nombre y muestra sus clases del día. ' .
                        'Úsala cuando el usuario pregunte por un docente/instructor específico por nombre.',
                    'parameters' => [
                        'type'       => 'object',
                        'properties' => [
                            'name' => ['type' => 'string', 'description' => 'Nombre completo o parcial del instructor.'],
                            'date' => ['type' => 'string', 'description' => 'Fecha YYYY-MM-DD. Si omite, usa hoy: ' . $d['today'] . '.'],
                        ],
                        'required' => ['name'],
                    ],
                ],
            ],

            // ── 9. Buscar aprendiz ──
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'find_student',
                    'description' =>
                        'Busca un aprendiz por nombre o documento. ' .
                        'Úsala cuando el usuario pregunte por un estudiante/aprendiz específico.',
                    'parameters' => [
                        'type'       => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'Nombre, apellido o número de documento del aprendiz.'],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],

        ];
    }

    // ─────────────────────────────────────────────────────────────
    // DISPATCHER
    // ─────────────────────────────────────────────────────────────

    private function executeTool(string $name, array $arguments): array
    {
        return match ($name) {
            'get_ambient_status'      => $this->toolGetAmbientStatus($arguments),
            'get_schedule_summary'    => $this->toolGetScheduleSummary($arguments),
            'get_ambient_ranking'     => $this->toolGetAmbientRanking($arguments),
            'get_instructor_workload' => $this->toolGetInstructorWorkload($arguments),
            'get_peak_hours'          => $this->toolGetPeakHours($arguments),
            'get_top_instructors'     => $this->toolGetTopInstructors($arguments),
            'get_top_programs'        => $this->toolGetTopPrograms($arguments),
            'find_instructor'         => $this->toolFindInstructor($arguments),
            'find_student'            => $this->toolFindStudent($arguments),
            default                   => ['error' => 'Función no disponible.'],
        };
    }

    // ─────────────────────────────────────────────────────────────
    // TOOL 1 — Estado de un ambiente
    // ─────────────────────────────────────────────────────────────

    private function toolGetAmbientStatus(array $args): array
    {
        $identifier   = trim($args['ambient_identifier'] ?? '');
        $specificDate = $args['specific_date'] ?? null;
        $timeContext  = $args['time_context']  ?? 'current';

        if ($identifier === '') {
            return ['error' => 'Falta el identificador del ambiente.'];
        }

        $ambientId = $this->resolveAmbientId($identifier);
        if ($ambientId === null) {
            return ['error' => "No se encontró el ambiente '{$identifier}'."];
        }

        $ambientName = $this->ambientNames[$ambientId] ?? "Ambiente {$ambientId}";
        $query       = AmbientSchedule::where('ambient_id', $ambientId);

        // Caso A: fecha específica → todas las clases del día
        if ($specificDate) {
            $schedules = $query->whereDate('date', $specificDate)
                               ->orderBy('start_time')
                               ->get();

            if ($schedules->isEmpty()) {
                return [
                    'status'  => 'libre',
                    'ambiente'=> $ambientName,
                    'fecha'   => $specificDate,
                    'mensaje' => "No hay clases registradas en el ambiente {$ambientName} el {$specificDate}.",
                ];
            }

            return [
                'status'       => 'historial',
                'ambiente'     => $ambientName,
                'fecha'        => $specificDate,
                'total_clases' => $schedules->count(),
                'clases'       => $schedules->map(fn($s) => [
                    'programa'   => $s->class        ?? 'Sin nombre',
                    'instructor' => $s->teacher_name ?? 'Sin asignar',
                    'ficha'      => $s->codeTab       ?? 'N/A',
                    'inicio'     => substr($s->start_time, 0, 5),
                    'fin'        => substr($s->end_time,   0, 5),
                    'en_receso'  => (bool) $s->break_time,
                ])->toArray(),
            ];
        }

        // Caso B: contexto de tiempo
        $today       = now()->toDateString();
        $currentTime = now()->format('H:i:s');

        $schedule = match ($timeContext) {
            'past'   => $query->whereDate('date', $today)
                              ->where('end_time', '<=', $currentTime)
                              ->orderBy('end_time', 'desc')->first(),
            'future' => $query->whereDate('date', $today)
                              ->where('start_time', '>', $currentTime)
                              ->orderBy('start_time')->first(),
            default  => $query->whereDate('date', $today)
                              ->where('start_time', '<=', $currentTime)
                              ->where('end_time',   '>=', $currentTime)->first(),
        };

        if (!$schedule) {
            return [
                'status'  => 'libre',
                'ambiente'=> $ambientName,
                'mensaje' => match ($timeContext) {
                    'past'   => "No hubo clases previas hoy en el ambiente {$ambientName}.",
                    'future' => "No hay más clases programadas hoy en el ambiente {$ambientName}.",
                    default  => "El ambiente {$ambientName} está libre en este momento.",
                },
            ];
        }

        return [
            'status'     => 'ocupado',
            'ambiente'   => $ambientName,
            'fecha'      => $schedule->date,
            'programa'   => $schedule->class        ?? 'Sin nombre',
            'instructor' => $schedule->teacher_name ?? 'Sin asignar',
            'ficha'      => $schedule->codeTab       ?? 'N/A',
            'inicio'     => substr($schedule->start_time, 0, 5),
            'fin'        => substr($schedule->end_time,   0, 5),
            'en_receso'  => (bool) $schedule->break_time,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // TOOL 2 — Resumen estadístico
    // ─────────────────────────────────────────────────────────────

    private function toolGetScheduleSummary(array $args): array
    {
        $dateFrom  = $args['date_from']  ?? now()->toDateString();
        $dateTo    = $args['date_to']    ?? $dateFrom;
        $ambientId = $args['ambient_id'] ?? null;

        $query = AmbientSchedule::whereBetween('date', [$dateFrom, $dateTo]);
        if ($ambientId) {
            $query->where('ambient_id', $ambientId);
        }

        $schedules = $query->get();

        if ($schedules->isEmpty()) {
            return [
                'mensaje'      => "No se encontraron clases entre {$dateFrom} y {$dateTo}" .
                                  ($ambientId ? " para el ambiente " . ($this->ambientNames[$ambientId] ?? $ambientId) : '') . '.',
                'total_clases' => 0,
            ];
        }

        $byDay           = $schedules->groupBy('date')->map(fn($g) => $g->count())->sortKeys();
        $daysWithClasses = $byDay->count();
        $avgPerDay       = $daysWithClasses > 0 ? round($schedules->count() / $daysWithClasses, 1) : 0;

        $totalMinutes = $schedules->sum(fn($s) =>
            max(0, (strtotime($s->end_time) - strtotime($s->start_time)) / 60)
        );

        $busiestDay   = $byDay->sortDesc()->keys()->first();
        $busiestCount = $byDay->sortDesc()->first();

        return [
            'periodo'              => ['desde' => $dateFrom, 'hasta' => $dateTo],
            'filtro_ambiente'      => $ambientId
                ? ($this->ambientNames[$ambientId] ?? "Ambiente {$ambientId}")
                : 'Todos los ambientes',
            'total_clases'         => $schedules->count(),
            'dias_con_actividad'   => $daysWithClasses,
            'promedio_clases_dia'  => $avgPerDay,
            'horas_formacion'      => round($totalMinutes / 60, 1),
            'ambientes_activos'    => $schedules->pluck('ambient_id')->unique()->count(),
            'instructores_activos' => $schedules->pluck('teacher_name')->filter()->unique()->count(),
            'programas_dictados'   => $schedules->pluck('class')->filter()->unique()->count(),
            'dia_mas_activo'       => ['fecha' => $busiestDay, 'clases' => $busiestCount],
            'clases_por_dia'       => $byDay->toArray(),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // TOOL 3 — Ranking de ambientes
    // ─────────────────────────────────────────────────────────────

    private function toolGetAmbientRanking(array $args): array
    {
        $dateFrom = $args['date_from'] ?? now()->toDateString();
        $dateTo   = $args['date_to']   ?? $dateFrom;
        $limit    = (int) ($args['limit'] ?? 5);

        $ranking = AmbientSchedule::whereBetween('date', [$dateFrom, $dateTo])
            ->select('ambient_id', DB::raw('COUNT(*) as total_clases'))
            ->groupBy('ambient_id')
            ->orderByDesc('total_clases')
            ->limit($limit)
            ->get();

        if ($ranking->isEmpty()) {
            return ['mensaje' => "No hay datos entre {$dateFrom} y {$dateTo}.", 'ranking' => []];
        }

        return [
            'periodo' => ['desde' => $dateFrom, 'hasta' => $dateTo],
            'ranking' => $ranking->map(fn($r) => [
                'ambiente'     => $this->ambientNames[$r->ambient_id] ?? "Ambiente {$r->ambient_id}",
                'total_clases' => $r->total_clases,
            ])->values()->toArray(),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // TOOL 4 — Carga de un instructor
    // ─────────────────────────────────────────────────────────────

    private function toolGetInstructorWorkload(array $args): array
    {
        $name     = trim($args['name'] ?? '');
        $dateFrom = $args['date_from'] ?? now()->toDateString();
        $dateTo   = $args['date_to']   ?? $dateFrom;

        if ($name === '') {
            return ['error' => 'Falta el nombre del instructor.'];
        }

        $schedules = AmbientSchedule::where('teacher_name', 'like', "%{$name}%")
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->orderBy('date')->orderBy('start_time')
            ->get();

        if ($schedules->isEmpty()) {
            return ['mensaje' => "No se encontraron clases para '{$name}' entre {$dateFrom} y {$dateTo}."];
        }

        $totalMinutes = $schedules->sum(fn($s) =>
            max(0, (strtotime($s->end_time) - strtotime($s->start_time)) / 60)
        );

        return [
            'instructor'    => $schedules->first()->teacher_name,
            'periodo'       => ['desde' => $dateFrom, 'hasta' => $dateTo],
            'total_clases'  => $schedules->count(),
            'horas_totales' => round($totalMinutes / 60, 1),
            'ambientes'     => $schedules->pluck('ambient_id')->unique()
                                ->map(fn($id) => $this->ambientNames[$id] ?? "Ambiente {$id}")
                                ->values()->toArray(),
            'programas'     => $schedules->pluck('class')->filter()->unique()->values()->toArray(),
            'detalle'       => $schedules->map(fn($s) => [
                'fecha'    => $s->date,
                'ambiente' => $this->ambientNames[$s->ambient_id] ?? "Ambiente {$s->ambient_id}",
                'inicio'   => substr($s->start_time, 0, 5),
                'fin'      => substr($s->end_time,   0, 5),
                'programa' => $s->class   ?? 'Sin nombre',
                'ficha'    => $s->codeTab ?? 'N/A',
            ])->toArray(),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // TOOL 5 — Horas pico
    // ─────────────────────────────────────────────────────────────

    private function toolGetPeakHours(array $args): array
    {
        $dateFrom = $args['date_from'] ?? now()->toDateString();
        $dateTo   = $args['date_to']   ?? $dateFrom;

        $schedules = AmbientSchedule::whereBetween('date', [$dateFrom, $dateTo])->get();

        if ($schedules->isEmpty()) {
            return ['mensaje' => "No hay datos entre {$dateFrom} y {$dateTo}."];
        }

        $hourCounts = array_fill(0, 24, 0);
        foreach ($schedules as $s) {
            $startHour = (int) substr($s->start_time, 0, 2);
            $endHour   = (int) substr($s->end_time,   0, 2);
            for ($h = $startHour; $h < $endHour; $h++) {
                $hourCounts[$h]++;
            }
        }

        $activeHours = collect($hourCounts)->filter(fn($c) => $c > 0)->sortDesc();
        $peakHour    = $activeHours->keys()->first();

        return [
            'periodo'           => ['desde' => $dateFrom, 'hasta' => $dateTo],
            'hora_pico'         => sprintf('%02d:00 - %02d:00', $peakHour, $peakHour + 1),
            'clases_en_pico'    => $activeHours->first(),
            'promedio_por_hora' => round($activeHours->average(), 1),
            'top_5_franjas'     => $activeHours->take(5)->map(fn($count, $hour) => [
                'franja'         => sprintf('%02d:00 - %02d:00', $hour, $hour + 1),
                'clases_activas' => $count,
            ])->values()->toArray(),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // TOOL 6 — Top instructores
    // ─────────────────────────────────────────────────────────────

    private function toolGetTopInstructors(array $args): array
    {
        $dateFrom = $args['date_from'] ?? now()->toDateString();
        $dateTo   = $args['date_to']   ?? $dateFrom;
        $limit    = (int) ($args['limit'] ?? 5);

        $results = AmbientSchedule::whereBetween('date', [$dateFrom, $dateTo])
            ->whereNotNull('teacher_name')
            ->select(
                'teacher_name',
                DB::raw('COUNT(*) as total_clases'),
                DB::raw('COUNT(DISTINCT ambient_id) as ambientes_distintos'),
                DB::raw('COUNT(DISTINCT class) as programas_distintos')
            )
            ->groupBy('teacher_name')
            ->orderByDesc('total_clases')
            ->limit($limit)
            ->get();

        if ($results->isEmpty()) {
            return ['mensaje' => "No hay datos entre {$dateFrom} y {$dateTo}.", 'ranking' => []];
        }

        return [
            'periodo' => ['desde' => $dateFrom, 'hasta' => $dateTo],
            'ranking' => $results->map(fn($r) => [
                'instructor'          => $r->teacher_name,
                'total_clases'        => $r->total_clases,
                'ambientes_distintos' => $r->ambientes_distintos,
                'programas_distintos' => $r->programas_distintos,
            ])->toArray(),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // TOOL 7 — Top programas
    // ─────────────────────────────────────────────────────────────

    private function toolGetTopPrograms(array $args): array
    {
        $dateFrom = $args['date_from'] ?? now()->toDateString();
        $dateTo   = $args['date_to']   ?? $dateFrom;
        $limit    = (int) ($args['limit'] ?? 5);

        $results = AmbientSchedule::whereBetween('date', [$dateFrom, $dateTo])
            ->whereNotNull('class')
            ->select(
                'class',
                DB::raw('COUNT(*) as total_clases'),
                DB::raw('COUNT(DISTINCT ambient_id) as ambientes_usados'),
                DB::raw('COUNT(DISTINCT teacher_name) as instructores')
            )
            ->groupBy('class')
            ->orderByDesc('total_clases')
            ->limit($limit)
            ->get();

        if ($results->isEmpty()) {
            return ['mensaje' => "No hay datos entre {$dateFrom} y {$dateTo}.", 'ranking' => []];
        }

        return [
            'periodo' => ['desde' => $dateFrom, 'hasta' => $dateTo],
            'ranking' => $results->map(fn($r) => [
                'programa'         => $r->class,
                'total_clases'     => $r->total_clases,
                'ambientes_usados' => $r->ambientes_usados,
                'instructores'     => $r->instructores,
            ])->toArray(),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // TOOL 8 — Buscar instructor
    // ─────────────────────────────────────────────────────────────

    private function toolFindInstructor(array $args): array
    {
        $name = trim($args['name'] ?? '');
        $date = $args['date'] ?? now()->toDateString();

        if ($name === '') {
            return ['error' => 'Falta el nombre del instructor.'];
        }

        $user = User::where('role_id', 2)
                    ->where('fullname', 'like', "%{$name}%")
                    ->first();

        if (!$user) {
            $exists = AmbientSchedule::where('teacher_name', 'like', "%{$name}%")->exists();
            if (!$exists) {
                return ['error' => "No se encontró el instructor '{$name}'."];
            }
        }

        $schedules = AmbientSchedule::where('teacher_name', 'like', "%{$name}%")
                                    ->whereDate('date', $date)
                                    ->orderBy('start_time')
                                    ->get();

        return [
            'instructor' => $user?->fullname ?? $name,
            'documento'  => $user?->document ?? 'N/A',
            'fecha'      => $date,
            'clases_hoy' => $schedules->count(),
            'clases'     => $schedules->map(fn($s) => [
                'ambiente' => $this->ambientNames[$s->ambient_id] ?? "Ambiente {$s->ambient_id}",
                'programa' => $s->class   ?? 'Sin nombre',
                'ficha'    => $s->codeTab ?? 'N/A',
                'inicio'   => substr($s->start_time, 0, 5),
                'fin'      => substr($s->end_time,   0, 5),
            ])->toArray(),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // TOOL 9 — Buscar aprendiz
    // ─────────────────────────────────────────────────────────────

    private function toolFindStudent(array $args): array
    {
        $query = trim($args['query'] ?? '');

        if ($query === '') {
            return ['error' => 'Falta el nombre o documento del aprendiz.'];
        }

        $user = User::where('role_id', 3)
                    ->where(function ($q) use ($query) {
                        $q->where('fullname', 'like', "%{$query}%")
                          ->orWhere('document', $query);
                    })
                    ->first();

        if (!$user) {
            return ['error' => "No se encontró ningún aprendiz con '{$query}'."];
        }

        return [
            'aprendiz'  => $user->fullname,
            'documento' => $user->document,
            'correo'    => $user->email,
            'activo'    => $user->is_active ? 'Sí' : 'No',
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // UTILIDAD — Resolver ambient_id
    // ─────────────────────────────────────────────────────────────

    private function resolveAmbientId(string $identifier): ?int
    {
        if (is_numeric($identifier)) {
            return (int) $identifier;
        }

        $normalized = strtolower(trim($identifier));
        $normalized = strtr($normalized, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        ]);

        if (isset($this->ambientMap[$normalized])) {
            return $this->ambientMap[$normalized];
        }

        foreach ($this->ambientMap as $key => $id) {
            if (str_contains($normalized, $key) || str_contains($key, $normalized)) {
                return $id;
            }
        }

        return null;
    }
}