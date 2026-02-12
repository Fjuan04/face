<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registro de Docente</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #0f172a;
            color: #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
        }
        .card {
            background: #020617;
            border-radius: 1rem;
            padding: 2rem;
            max-width: 900px;
            width: 100%;
            box-shadow: 0 25px 50px -12px rgba(15,23,42,0.8);
            border: 1px solid rgba(148,163,184,0.2);
            display: grid;
            grid-template-columns: 1.1fr 1fr;
            gap: 2rem;
        }
        @media (max-width: 768px) {
            .card {
                grid-template-columns: 1fr;
            }
        }
        h1 {
            margin-top: 0;
            font-size: 1.75rem;
            margin-bottom: 0.5rem;
        }
        p.subtitle {
            margin-top: 0;
            color: #9ca3af;
            font-size: 0.9rem;
        }
        label {
            display: block;
            font-size: 0.9rem;
            margin-bottom: 0.35rem;
            color: #e5e7eb;
        }
        input[type="text"],
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border-radius: 0.5rem;
            border: 1px solid #1f2937;
            background: #020617;
            color: #e5e7eb;
            font-size: 0.9rem;
        }
        input:focus {
            outline: none;
            border-color: #38bdf8;
            box-shadow: 0 0 0 1px rgba(56,189,248,0.5);
        }
        .field {
            margin-bottom: 0.9rem;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            padding: 0.6rem 1.1rem;
            border-radius: 999px;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
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
            font-size: 0.85rem;
            margin-top: 0.5rem;
        }
        .status-ok {
            color: #22c55e;
        }
        .status-error {
            color: #f97316;
        }
        .camera-container {
            background: radial-gradient(circle at top left, rgba(56,189,248,0.18), transparent 55%),
                        radial-gradient(circle at bottom right, rgba(129,140,248,0.22), transparent 55%);
            border-radius: 1rem;
            padding: 1rem;
            border: 1px solid rgba(148,163,184,0.25);
        }
        video, canvas, img {
            width: 100%;
            border-radius: 0.75rem;
            background: #020617;
        }
        .camera-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.75rem;
            flex-wrap: wrap;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.6rem;
            border-radius: 999px;
            font-size: 0.72rem;
            background: rgba(15,23,42,0.85);
            border: 1px solid rgba(148,163,184,0.3);
            color: #9ca3af;
        }
        .error-list {
            background: rgba(248,113,113,0.08);
            border: 1px solid rgba(248,113,113,0.35);
            color: #fecaca;
            padding: 0.75rem;
            border-radius: 0.75rem;
            font-size: 0.8rem;
            margin-bottom: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="card">
        <div>
            <h1>Registro de docente</h1>
            <p class="subtitle">
                Completa los datos del docente y toma una foto desde la cámara. La imagen se guardará en el servidor y
                en la base de datos solo se almacenará la ruta.
            </p>

            @if (session('status'))
                <div class="status status-ok">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="error-list">
                    <strong>Revisa los siguientes campos:</strong>
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form id="docente-form" method="POST" enctype="multipart/form-data" action="{{ route('docentes.store') }}">
                @csrf
                <div class="field">
                    <label for="name">Nombre</label>
                    <input type="text" id="name" name="name" value="{{ old('name') }}" required>
                </div>
                <div class="field">
                    <label for="surname">Apellido</label>
                    <input type="text" id="surname" name="surname" value="{{ old('surname') }}" required>
                </div>
                <div class="field">
                    <label for="email">Correo institucional</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required>
                </div>
                <div class="field">
                    <label for="password">Contraseña (para pruebas)</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <input type="file" id="photo-input" name="photo" accept="image/*" class="hidden" style="display:none;">

                <div class="field" style="margin-top: 1.2rem;">
                    <button type="submit" class="btn btn-primary" id="submit-btn">
                        Guardar docente
                    </button>
                    <div class="status" id="form-status"></div>
                </div>
            </form>
        </div>

        <div class="camera-container">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem;">
                <span style="font-size:0.9rem; font-weight:500;">Cámara / captura de rostro</span>
                <span class="badge">
                    <span style="width:8px; height:8px; border-radius:999px; background:#22c55e;"></span>
                    WebRTC
                </span>
            </div>

            <video id="video" autoplay playsinline style="display:block;"></video>
            <canvas id="canvas" style="display:none;"></canvas>
            <img id="preview" alt="Previsualización de la foto" style="display:none; margin-top:0.5rem;">

            <div class="camera-actions">
                <button class="btn btn-secondary" id="start-camera-btn" type="button">
                    Iniciar cámara
                </button>
                <button class="btn btn-secondary" id="capture-btn" type="button" disabled>
                    Tomar foto
                </button>
                <button class="btn btn-secondary" id="reset-photo-btn" type="button" disabled>
                    Repetir foto
                </button>
                <span class="status" id="camera-status"></span>
            </div>

            <p style="font-size:0.75rem; color:#9ca3af; margin-top:0.75rem;">
                En móviles, si el navegador no soporta la cámara embebida, puedes usar directamente la selección de
                imagen del sistema (se abrirá la cámara nativa).
            </p>
        </div>
    </div>

    <script>
        const video = document.getElementById('video');
        const canvas = document.getElementById('canvas');
        const preview = document.getElementById('preview');
        const startCameraBtn = document.getElementById('start-camera-btn');
        const captureBtn = document.getElementById('capture-btn');
        const resetPhotoBtn = document.getElementById('reset-photo-btn');
        const cameraStatus = document.getElementById('camera-status');
        const form = document.getElementById('docente-form');
        const submitBtn = document.getElementById('submit-btn');
        const formStatus = document.getElementById('form-status');
        const photoInput = document.getElementById('photo-input');

        let stream = null;
        let lastBlob = null;

        async function startCamera() {
            cameraStatus.textContent = '';
            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    video: { width: 640, height: 480 }
                });
                video.srcObject = stream;
                captureBtn.disabled = false;
                resetPhotoBtn.disabled = true;
                cameraStatus.textContent = 'Cámara activada. Enfoca el rostro del docente.';
                cameraStatus.className = 'status';
            } catch (error) {
                console.error(error);
                cameraStatus.textContent = 'No se pudo acceder a la cámara. Permite el acceso o usa la subida manual.';
                cameraStatus.className = 'status status-error';
            }
        }

        function stopCamera() {
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
                stream = null;
            }
        }

        function capturePhoto() {
            if (!stream) return;

            const videoWidth = video.videoWidth || 640;
            const videoHeight = video.videoHeight || 480;

            canvas.width = videoWidth;
            canvas.height = videoHeight;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0, videoWidth, videoHeight);

            canvas.toBlob((blob) => {
                if (!blob) {
                    cameraStatus.textContent = 'No se pudo capturar la imagen.';
                    cameraStatus.className = 'status status-error';
                    return;
                }

                lastBlob = blob;

                // Mostrar previsualización
                const url = URL.createObjectURL(blob);
                preview.src = url;
                preview.style.display = 'block';

                cameraStatus.textContent = 'Foto capturada. Ahora puedes guardar el docente.';
                cameraStatus.className = 'status status-ok';

                resetPhotoBtn.disabled = false;
            }, 'image/jpeg', 0.95);
        }

        function resetPhoto() {
            lastBlob = null;
            preview.src = '';
            preview.style.display = 'none';
            cameraStatus.textContent = 'Vuelve a tomar la foto cuando estés listo.';
            cameraStatus.className = 'status';
        }

        startCameraBtn.addEventListener('click', startCamera);
        captureBtn.addEventListener('click', capturePhoto);
        resetPhotoBtn.addEventListener('click', resetPhoto);

        window.addEventListener('beforeunload', stopCamera);

        form.addEventListener('submit', function (event) {
            if (!lastBlob && !photoInput.files.length) {
                event.preventDefault();
                formStatus.textContent = 'Debes tomar una foto o seleccionar una imagen antes de guardar.';
                formStatus.className = 'status status-error';
                return;
            }

            if (lastBlob) {
                event.preventDefault();

                const formData = new FormData();
                const csrfToken = document.querySelector('meta[name=\"csrf-token\"]').getAttribute('content');

                formData.append('_token', csrfToken);
                formData.append('name', document.getElementById('name').value);
                formData.append('surname', document.getElementById('surname').value);
                formData.append('email', document.getElementById('email').value);
                formData.append('password', document.getElementById('password').value);
                formData.append('photo', lastBlob, 'docente.jpg');

                submitBtn.disabled = true;
                formStatus.textContent = 'Enviando datos...';
                formStatus.className = 'status';

                fetch(form.action, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'Accept': 'application/json'
                    }
                })
                    .then(async (response) => {
                        const data = await response.json().catch(() => ({}));
                        if (response.ok) {
                            formStatus.textContent = 'Docente registrado correctamente.';
                            formStatus.className = 'status status-ok';
                            form.reset();
                            resetPhoto();
                        } else {
                            formStatus.textContent = data.message || 'Error al registrar el docente.';
                            formStatus.className = 'status status-error';
                        }
                    })
                    .catch((error) => {
                        console.error(error);
                        formStatus.textContent = 'Error de red al enviar el formulario.';
                        formStatus.className = 'status status-error';
                    })
                    .finally(() => {
                        submitBtn.disabled = false;
                    });
            }
        });
    </script>
</body>
</html>

