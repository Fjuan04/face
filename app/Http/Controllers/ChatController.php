<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\OllamaAgentService;

class ChatController extends Controller
{
    protected $agentService;

    public function __construct(OllamaAgentService $agentService)
    {
        $this->agentService = $agentService;
    }

    public function chat(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
            // Opcional: Si manejas memoria en el frontend, recibes el historial completo
            'history' => 'nullable|array' 
        ]);

        $userMessage = $request->input('message');
        $history = $request->input('history', []);

        // Construimos el arreglo de mensajes para la IA
        $messages = $history;
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        try {
            // Llamamos al servicio orquestador
            $reply = $this->agentService->handleChat($messages);

            // Actualizamos el historial para devolvérselo al frontend
            $messages[] = ['role' => 'assistant', 'content' => $reply];

            return response()->json([
                'success' => true,
                'reply' => $reply,
                'history' => $messages
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Error al comunicarse con la IA: ' . $e->getMessage()
            ], 500);
        }
    }
}