<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Prueba de reconocimiento facial</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #020617;
            color: #e5e7eb;
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card {
            background: #020617;
            border-radius: 1rem;
            border: 1px solid rgba(148,163,184,0.25);
            padding: 1.75rem;
            max-width: 800px;
            width: 100%;
            box-shadow: 0 25px 50px -12px rgba(15,23,42,0.8);
        }
        h1 {
            margin: 0 0 0.5rem 0;
            font-size: 1.6rem;
        }
        p {
            margin-top: 0;
            color: #9ca3af;
            font-size: 0.9rem;
        }
        video, canvas, img {
            width: 100%;
            border-radius: 0.75rem;
            background: #020617;
        }
        .buttons {
            margin-top: 0.75rem;
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.55rem 1.1rem;
            border-radius: 999px;
            border: none;
            font-size: 0.9rem;
            cursor: pointer;
        }
        .btn-primary {
            background: linear-gradient(to right, #0ea5e9, #6366f1);
            color: #f9fafb;
        }
        .btn-secondary {
            background: #020617;
            border: 1px solid #1f2937;
            color: #e5e7eb;
        }
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .status {
            margin-top: 0.5rem;
            font-size: 0.85rem;
        }
        .status-ok {
            color: #22c55e;
        }
        .status-error {
            color: #f97316;
        }
        pre {
            background: #020617;
            border-radius: 0.5rem;
            padding: 0.75rem;
            font-size: 0.8rem;
            color: #e5e7eb;
            max-height: 220px;
            overflow: auto;
            border: 1px solid rgba(148,163,184,0.25);
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Prueba de reconocimiento facial</h1>
        <p>
            Esta vista es solo para pruebas manuales. Toma una foto con la cámara y envíala al
            endpoint `POST /api/recognize`. Cuando todo funcione, este flujo lo hará la ESP32.
        </p>

        <video id="video" autoplay playsinline></video>
        <canvas id="canvas" style="display:none;"></canvas>
        <img id="preview" alt="Previsualización" style="display:none; margin-top:0.5rem;">

        <div class="buttons">
            <button id="start-btn" class="btn btn-secondary" type="button">
                Iniciar cámara
            </button>
            <button id="capture-btn" class="btn btn-secondary" type="button" disabled>
                Tomar foto
            </button>
            <button id="send-btn" class="btn btn-primary" type="button" disabled>
                Enviar a /api/recognize
            </button>
        </div>

        <div id="status" class="status"></div>

        <h2 style="margin-top:1.5rem; font-size:1rem;">Respuesta de la API</h2>
        <pre id="response-box">{}</pre>

        <script>
            const video = document.getElementById('video');
            const canvas = document.getElementById('canvas');
            const preview = document.getElementById('preview');
            const startBtn = document.getElementById('start-btn');
            const captureBtn = document.getElementById('capture-btn');
            const sendBtn = document.getElementById('send-btn');
            const statusEl = document.getElementById('status');
            const responseBox = document.getElementById('response-box');

            let stream = null;
            let lastBlob = null;

            async function startCamera() {
                statusEl.textContent = '';
                try {
                    stream = await navigator.mediaDevices.getUserMedia({
                        video: { width: 640, height: 480 }
                    });
                    video.srcObject = stream;
                    captureBtn.disabled = false;
                    statusEl.textContent = 'Cámara iniciada. Enfoca el rostro.';
                    statusEl.className = 'status';
                } catch (e) {
                    console.error(e);
                    statusEl.textContent = 'No se pudo acceder a la cámara. Revisa permisos o prueba en otro navegador.';
                    statusEl.className = 'status status-error';
                }
            }

            function capturePhoto() {
                if (!stream) return;

                const w = video.videoWidth || 640;
                const h = video.videoHeight || 480;

                canvas.width = w;
                canvas.height = h;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(video, 0, 0, w, h);

                canvas.toBlob((blob) => {
                    if (!blob) {
                        statusEl.textContent = 'No se pudo capturar la foto.';
                        statusEl.className = 'status status-error';
                        return;
                    }
                    lastBlob = blob;
                    const url = URL.createObjectURL(blob);
                    preview.src = url;
                    preview.style.display = 'block';
                    sendBtn.disabled = false;
                    statusEl.textContent = 'Foto capturada. Lista para enviar.';
                    statusEl.className = 'status status-ok';
                }, 'image/jpeg', 0.95);
            }

            async function sendToApi() {
                if (!lastBlob) {
                    statusEl.textContent = 'Primero toma una foto.';
                    statusEl.className = 'status status-error';
                    return;
                }

                statusEl.textContent = 'Convirtiendo imagen a Base64 y enviando a /api/recognize...';
                statusEl.className = 'status';
                responseBox.textContent = '{}';

                // Convertir el Blob capturado a Base64 (Data URL) para imitar lo que hará la ESP32
                const reader = new FileReader();
                reader.onloadend = async () => {
                    const dataUrl = reader.result; // ej: data:image/jpeg;base64,/9j/4AAQ...

                    const payload = {
                        ip: '127.0.0.1', // IP de prueba; la ESP32 enviará su propia IP
                        imagen: dataUrl,
                    };

                    try {
                        const response = await fetch('{{ route('api.recognize') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify(payload),
                        });

                        const data = await response.json().catch(() => ({}));
                        responseBox.textContent = JSON.stringify(data, null, 2);

                        if (response.ok) {
                            statusEl.textContent = 'Petición completada.';
                            statusEl.className = 'status status-ok';
                        } else {
                            statusEl.textContent = 'La API respondió con un error (ver detalle abajo).';
                            statusEl.className = 'status status-error';
                        }
                    } catch (e) {
                        console.error(e);
                        statusEl.textContent = 'Error de red al llamar a la API.';
                        statusEl.className = 'status status-error';
                    }
                };

                reader.readAsDataURL(lastBlob);
            }

            startBtn.addEventListener('click', startCamera);
            captureBtn.addEventListener('click', capturePhoto);
            sendBtn.addEventListener('click', sendToApi);

            window.addEventListener('beforeunload', () => {
                if (stream) {
                    stream.getTracks().forEach(t => t.stop());
                }
            });
        </script>
    </div>
</body>
</html>

