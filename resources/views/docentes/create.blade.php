<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registro de Docente · SENA</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Rajdhani:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --green:     #00ff9d;
            --green2:    #00c97a;
            --green-dim: rgba(0,255,157,0.08);
            --red:       #ff3c5a;
            --amber:     #ffb800;
            --blue:      #38bdf8;
            --bg:        #050a07;
            --bg2:       #0a1210;
            --bg3:       #0f1c18;
            --border:    rgba(0,255,157,0.18);
            --text:      #b6ffe0;
            --text-dim:  #4d8a6a;
            --mono:      'Share Tech Mono', monospace;
            --sans:      'Rajdhani', sans-serif;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--sans);
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            overflow-x: hidden;
        }

        /* Scanlines */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: repeating-linear-gradient(
                0deg,
                transparent,
                transparent 2px,
                rgba(0,0,0,0.18) 2px,
                rgba(0,0,0,0.18) 4px
            );
            pointer-events: none;
            z-index: 9999;
        }

        /* Grid BG */
        body::after {
            content: '';
            position: fixed;
            inset: 0;
            background-image:
                linear-gradient(rgba(0,255,157,0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0,255,157,0.03) 1px, transparent 1px);
            background-size: 40px 40px;
            pointer-events: none;
            z-index: 0;
        }

        .shell {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 960px;
        }

        /* ── HEADER ─────────────────────────────── */
        .header {
            border: 1px solid var(--border);
            background: var(--bg2);
            padding: 1rem 1.5rem;
            border-bottom: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            clip-path: polygon(0 0, calc(100% - 20px) 0, 100% 20px, 100% 100%, 0 100%);
        }
        .header-left { display: flex; align-items: center; gap: 1rem; }
        .header-icon {
            width: 40px; height: 40px;
            border: 1px solid var(--green);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            position: relative; flex-shrink: 0;
        }
        .header-icon::after {
            content: '';
            position: absolute;
            inset: 4px;
            border: 1px solid var(--green2);
            border-radius: 50%;
            animation: pulse-ring 2s ease-in-out infinite;
        }
        @keyframes pulse-ring {
            0%, 100% { opacity: 0.3; transform: scale(0.9); }
            50%       { opacity: 1;   transform: scale(1.05); }
        }
        .header-icon svg { width: 18px; height: 18px; fill: var(--green); }
        .header-title { font-size: 1.2rem; font-weight: 700; letter-spacing: 0.08em; color: var(--green); text-transform: uppercase; }
        .header-sub   { font-family: var(--mono); font-size: 0.72rem; color: var(--text-dim); margin-top: 2px; }
        .header-meta  { font-family: var(--mono); font-size: 0.7rem; color: var(--text-dim); text-align: right; line-height: 1.6; }
        .badge {
            display: inline-block;
            background: var(--green-dim);
            border: 1px solid var(--green);
            color: var(--green);
            font-family: var(--mono);
            font-size: 0.65rem;
            padding: 2px 8px;
            letter-spacing: 0.1em;
        }

        /* ── MAIN GRID ───────────────────────────── */
        .main {
            display: grid;
            grid-template-columns: 1fr 1fr;
            border: 1px solid var(--border);
            background: var(--bg2);
        }

        /* ── FORM PANEL ──────────────────────────── */
        .form-panel {
            border-right: 1px solid var(--border);
            padding: 1.25rem;
            display: flex;
            flex-direction: column;
            gap: 0.9rem;
        }

        .panel-label {
            font-family: var(--mono);
            font-size: 0.68rem;
            color: var(--text-dim);
            letter-spacing: 0.15em;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .panel-label::before {
            content: '';
            display: block;
            width: 6px; height: 6px;
            background: var(--green);
            border-radius: 50%;
            animation: blink 1.4s step-end infinite;
        }
        @keyframes blink { 50% { opacity: 0; } }

        /* Alerts */
        .alert-ok {
            background: rgba(0,255,157,0.06);
            border: 1px solid rgba(0,255,157,0.3);
            color: var(--green);
            font-family: var(--mono);
            font-size: 0.75rem;
            padding: 0.6rem 0.8rem;
        }
        .alert-err {
            background: rgba(255,60,90,0.06);
            border: 1px solid rgba(255,60,90,0.3);
            color: #fca5a5;
            font-family: var(--mono);
            font-size: 0.75rem;
            padding: 0.6rem 0.8rem;
        }
        .alert-err ul { padding-left: 1.1rem; margin-top: 0.35rem; }
        .alert-err li { margin-bottom: 0.2rem; }
        .alert-title { font-weight: 700; letter-spacing: 0.06em; display: block; margin-bottom: 0.25rem; }

        /* Fields */
        .field { display: flex; flex-direction: column; gap: 0.3rem; }

        .field label {
            font-family: var(--mono);
            font-size: 0.68rem;
            color: var(--text-dim);
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }

        .field input {
            background: #000;
            border: 1px solid var(--border);
            color: var(--text);
            font-family: var(--mono);
            font-size: 0.82rem;
            padding: 0.5rem 0.7rem;
            outline: none;
            width: 100%;
            transition: border-color 0.15s, box-shadow 0.15s;
            clip-path: polygon(0 0, calc(100% - 6px) 0, 100% 6px, 100% 100%, 6px 100%, 0 calc(100% - 6px));
        }
        .field input:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 1px rgba(0,255,157,0.2);
        }
        .field input::placeholder { color: var(--text-dim); opacity: 0.6; }

        .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.6rem; }

        /* Buttons */
        .btn {
            font-family: var(--sans);
            font-weight: 600;
            font-size: 0.85rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            padding: 0.55rem 1rem;
            border: 1px solid;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            transition: background 0.15s, box-shadow 0.15s;
            background: transparent;
            clip-path: polygon(0 0, calc(100% - 8px) 0, 100% 8px, 100% 100%, 8px 100%, 0 calc(100% - 8px));
        }
        .btn svg { width: 14px; height: 14px; flex-shrink: 0; }
        .btn:disabled { opacity: 0.3; cursor: not-allowed; }

        .btn-primary  { border-color: var(--green); color: var(--green); }
        .btn-primary:hover:not(:disabled) { background: rgba(0,255,157,0.1); box-shadow: 0 0 18px rgba(0,255,157,0.25); }

        .btn-secondary { border-color: var(--text-dim); color: var(--text-dim); }
        .btn-secondary:hover:not(:disabled) { border-color: var(--text); color: var(--text); background: rgba(182,255,224,0.06); }

        .btn-amber { border-color: var(--amber); color: var(--amber); }
        .btn-amber:hover:not(:disabled) { background: rgba(255,184,0,0.08); }

        .btn-red { border-color: var(--red); color: var(--red); }
        .btn-red:hover:not(:disabled) { background: rgba(255,60,90,0.08); }

        .btn-full { width: 100%; }

        /* Spinner */
        .spinner {
            width: 12px; height: 12px;
            border: 1.5px solid var(--text-dim);
            border-top-color: var(--green);
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            display: none;
        }
        .spinner.active { display: block; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── CAM PANEL ───────────────────────────── */
        .cam-panel {
            padding: 1.25rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .viewfinder {
            position: relative;
            background: #000;
            border: 1px solid var(--border);
            aspect-ratio: 4/3;
            overflow: hidden;
        }
        .viewfinder video,
        .viewfinder img {
            width: 100%; height: 100%;
            object-fit: cover;
            display: block;
        }
        .viewfinder canvas { display: none; }

        /* Corner brackets */
        .viewfinder::before, .viewfinder::after,
        .bracket-bl, .bracket-br {
            content: '';
            position: absolute;
            width: 18px; height: 18px;
            border-color: var(--green);
            border-style: solid;
            z-index: 2;
            pointer-events: none;
        }
        .viewfinder::before { top: 8px; left: 8px;  border-width: 2px 0 0 2px; }
        .viewfinder::after  { top: 8px; right: 8px; border-width: 2px 2px 0 0; }
        .bracket-bl { bottom: 8px; left: 8px;  border-width: 0 0 2px 2px; }
        .bracket-br { bottom: 8px; right: 8px; border-width: 0 2px 2px 0; }

        .scan-line {
            position: absolute;
            left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--green), transparent);
            opacity: 0;
            z-index: 3;
            pointer-events: none;
        }
        .scan-line.active {
            animation: scan 1.8s ease-in-out infinite;
            opacity: 1;
        }
        @keyframes scan { 0% { top: 0%; } 100% { top: 100%; } }

        .cam-overlay {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 0.5rem;
            background: rgba(5,10,7,0.9);
            z-index: 4;
        }
        .cam-overlay svg { width: 32px; height: 32px; stroke: var(--text-dim); fill: none; stroke-width: 1.5; }
        .cam-overlay span { font-family: var(--mono); font-size: 0.72rem; color: var(--text-dim); }

        /* Capture indicator */
        .img-indicator {
            border: 1px solid var(--border);
            padding: 0.5rem 0.75rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-family: var(--mono);
            font-size: 0.7rem;
        }
        .img-indicator .key { color: var(--text-dim); }
        .img-indicator .val { color: var(--text); }
        .img-indicator .val.ok   { color: var(--green); }
        .img-indicator .val.warn { color: var(--amber); }

        .cam-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
        }

        /* ── LOG FOOTER ──────────────────────────── */
        .log-footer {
            border: 1px solid var(--border);
            border-top: none;
            background: var(--bg2);
            padding: 0.75rem 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-family: var(--mono);
            font-size: 0.7rem;
            color: var(--text-dim);
            min-height: 36px;
        }
        .log-footer::before { content: '//'; color: var(--green); opacity: 0.5; }
        #log-msg.ok   { color: var(--green); }
        #log-msg.err  { color: var(--red); }
        #log-msg.warn { color: var(--amber); }

        .section-divider {
            font-family: var(--mono);
            font-size: 0.62rem;
            color: var(--text-dim);
            letter-spacing: 0.12em;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .section-divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }

        @media (max-width: 640px) {
            .main { grid-template-columns: 1fr; }
            .form-panel { border-right: none; border-bottom: 1px solid var(--border); }
            .field-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="shell">

    <!-- HEADER -->
    <div class="header">
        <div class="header-left">
            <div class="header-icon">
                <svg viewBox="0 0 24 24"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg>
            </div>
            <div>
                <div class="header-title">Registro de Docente</div>
                <div class="header-sub">SENA · Control de Acceso Biométrico · Nuevo registro</div>
            </div>
        </div>
        <div class="header-meta">
            <span class="badge">ADMIN</span><br>
            <span id="clock">--:--:--</span>
        </div>
    </div>

    <!-- MAIN -->
    <div class="main">

        <!-- FORM PANEL -->
        <div class="form-panel">
            <div class="panel-label">Datos del docente</div>

            @if (session('status'))
                <div class="alert-ok">
                    <span class="alert-title">✓ OPERACIÓN EXITOSA</span>
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="alert-err">
                    <span class="alert-title">✗ REVISA LOS CAMPOS</span>
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form id="docente-form" method="POST" enctype="multipart/form-data" action="{{ route('docentes.store') }}">
                @csrf
                <input type="file" id="photo-input" name="photo" accept="image/*" style="display:none;">

                <div style="display:flex; flex-direction:column; gap:0.75rem;">
                    <div class="field">
                        <label for="fullname">Nombre completo</label>
                        <input type="text" id="fullname" name="fullname" value="{{ old('fullname') }}" placeholder="Ej. Ana María García" required>
                    </div>

                    <div class="field">
                        <label for="email">Correo institucional</label>
                        <input type="email" id="email" name="email" value="{{ old('email') }}" placeholder="docente@sena.edu.co" required>
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label for="password">Contraseña</label>
                            <input type="password" id="password" name="password" placeholder="••••••••" required>
                        </div>
                        <div class="field">
                            <label for="password_confirmation">Confirmar</label>
                            <input type="password" id="password_confirmation" name="password_confirmation" placeholder="••••••••" required>
                        </div>
                    </div>

                    <div class="section-divider" style="margin-top:0.25rem;">Acción</div>

                    <button type="submit" class="btn btn-primary btn-full" id="submit-btn">
                        <div class="spinner" id="submit-spinner"></div>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v14a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        Guardar docente
                    </button>
                </div>
            </form>
        </div>

        <!-- CAM PANEL -->
        <div class="cam-panel">
            <div class="panel-label">Captura biométrica</div>

            <div class="viewfinder" id="viewfinder">
                <div class="bracket-bl"></div>
                <div class="bracket-br"></div>
                <div class="scan-line" id="scan-line"></div>

                <div class="cam-overlay" id="cam-overlay">
                    <svg viewBox="0 0 24 24"><path d="M15 8v8H5V8h10m1-2H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4V7c0-.55-.45-1-1-1z"/></svg>
                    <span>Cámara no iniciada</span>
                </div>

                <video id="video" autoplay playsinline style="display:none;"></video>
                <canvas id="canvas" style="display:none;"></canvas>
                <img id="preview" alt="Foto capturada" style="display:none; position:absolute; inset:0; width:100%; height:100%; object-fit:cover;">
            </div>

            <div class="img-indicator">
                <span class="key">FOTO</span>
                <span class="val warn" id="img-status">SIN CAPTURA</span>
            </div>

            <div class="cam-actions">
                <button class="btn btn-secondary" id="start-btn" type="button">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8" fill="currentColor" stroke="none"/></svg>
                    Iniciar cámara
                </button>
                <button class="btn btn-amber" id="capture-btn" type="button" disabled>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M20 5h-3.17L15 3H9L7.17 5H4a2 2 0 00-2 2v12a2 2 0 002 2h16a2 2 0 002-2V7a2 2 0 00-2-2z"/></svg>
                    Capturar
                </button>
                <button class="btn btn-secondary" id="reset-btn" type="button" disabled>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                    Repetir foto
                </button>
                <button class="btn btn-red" id="stop-btn" type="button" disabled>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
                    Detener
                </button>
            </div>

            <p style="font-family:var(--mono); font-size:0.65rem; color:var(--text-dim); line-height:1.6;">
                // La imagen se adjunta automáticamente al formulario.<br>
                // En móviles puedes usar la cámara nativa del sistema.
            </p>
        </div>
    </div>

    <!-- LOG FOOTER -->
    <div class="log-footer">
        <div class="spinner active" id="spinner" style="display:none;"></div>
        <span id="log-msg">Sistema listo. Completa los datos e inicia la cámara.</span>
    </div>

</div>

<script>
    // ── ELEMENTS ──────────────────────────────────────────────
    const video       = document.getElementById('video');
    const canvas      = document.getElementById('canvas');
    const preview     = document.getElementById('preview');
    const startBtn    = document.getElementById('start-btn');
    const captureBtn  = document.getElementById('capture-btn');
    const resetBtn    = document.getElementById('reset-btn');
    const stopBtn     = document.getElementById('stop-btn');
    const scanLine    = document.getElementById('scan-line');
    const camOverlay  = document.getElementById('cam-overlay');
    const imgStatus   = document.getElementById('img-status');
    const logMsg      = document.getElementById('log-msg');
    const spinner     = document.getElementById('spinner');
    const submitBtn   = document.getElementById('submit-btn');
    const submitSpinner = document.getElementById('submit-spinner');
    const form        = document.getElementById('docente-form');

    let stream   = null;
    let lastBlob = null;

    // ── CLOCK ─────────────────────────────────────────────────
    function tick() {
        document.getElementById('clock').textContent =
            new Date().toLocaleTimeString('es-CO', { hour12: false });
    }
    tick(); setInterval(tick, 1000);

    // ── LOG ───────────────────────────────────────────────────
    function log(msg, type = '') {
        logMsg.textContent = msg;
        logMsg.className = type;
    }

    // ── CAMERA ────────────────────────────────────────────────
    async function startCamera() {
        log('Solicitando acceso a la cámara…');
        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480 } });
            video.srcObject = stream;
            video.style.display = 'block';
            camOverlay.style.display = 'none';
            scanLine.classList.add('active');
            captureBtn.disabled = false;
            stopBtn.disabled = false;
            startBtn.disabled = true;
            log('Cámara activa. Enfoca el rostro del docente y captura.', 'ok');
        } catch (e) {
            log('No se pudo acceder a la cámara. Revisa los permisos del navegador.', 'err');
        }
    }

    function stopCamera() {
        if (stream) {
            stream.getTracks().forEach(t => t.stop());
            stream = null;
        }
        video.style.display = 'none';
        camOverlay.style.display = 'flex';
        scanLine.classList.remove('active');
        startBtn.disabled = false;
        captureBtn.disabled = true;
        stopBtn.disabled = true;
        log('Cámara detenida.', '');
    }

    // ── CAPTURE ───────────────────────────────────────────────
    function capturePhoto() {
        if (!stream) return;
        const w = video.videoWidth  || 640;
        const h = video.videoHeight || 480;
        canvas.width = w; canvas.height = h;
        canvas.getContext('2d').drawImage(video, 0, 0, w, h);

        canvas.toBlob((blob) => {
            if (!blob) { log('No se pudo capturar la imagen.', 'err'); return; }
            lastBlob = blob;
            preview.src = URL.createObjectURL(blob);
            preview.style.display = 'block';
            video.style.display = 'none';
            scanLine.classList.remove('active');
            resetBtn.disabled = false;
            imgStatus.textContent = `OK  (${(blob.size / 1024).toFixed(1)} KB · 640×480)`;
            imgStatus.className = 'val ok';
            log('Foto capturada. Completa los datos y guarda el docente.', 'ok');
        }, 'image/jpeg', 0.95);
    }

    // ── RESET PHOTO ───────────────────────────────────────────
    function resetPhoto() {
        lastBlob = null;
        preview.style.display = 'none';
        preview.src = '';
        if (stream) {
            video.style.display = 'block';
            scanLine.classList.add('active');
        }
        resetBtn.disabled = true;
        imgStatus.textContent = 'SIN CAPTURA';
        imgStatus.className = 'val warn';
        log('Foto descartada. Captura de nuevo cuando estés listo.', '');
    }

    // ── EVENTS ────────────────────────────────────────────────
    startBtn.addEventListener('click', startCamera);
    captureBtn.addEventListener('click', capturePhoto);
    resetBtn.addEventListener('click', resetPhoto);
    stopBtn.addEventListener('click', stopCamera);
    window.addEventListener('beforeunload', stopCamera);

    // ── FORM SUBMIT ───────────────────────────────────────────
    form.addEventListener('submit', function (e) {
        const photoInput = document.getElementById('photo-input');

        if (!lastBlob && !photoInput.files.length) {
            e.preventDefault();
            log('Debes capturar una foto antes de guardar el docente.', 'err');
            return;
        }

        if (lastBlob) {
            e.preventDefault();

            submitBtn.disabled = true;
            submitSpinner.style.display = 'block';
            log('Enviando datos al servidor…', 'warn');

            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            const formData  = new FormData();
            formData.append('_token',                csrfToken);
            formData.append('fullname',              document.getElementById('fullname').value);
            formData.append('email',                 document.getElementById('email').value);
            formData.append('password',              document.getElementById('password').value);
            formData.append('password_confirmation', document.getElementById('password_confirmation').value);
            formData.append('photo', lastBlob, 'docente.jpg');

            fetch(form.action, {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: formData,
            })
            .then(async (res) => {
                const data = await res.json().catch(() => ({}));
                if (res.ok) {
                    log('Docente registrado correctamente.', 'ok');
                    form.reset();
                    resetPhoto();
                } else {
                    const msg = data.message
                        || (data.errors ? Object.values(data.errors).flat().join(' · ') : '')
                        || `Error HTTP ${res.status}`;
                    log(`✗ ${msg}`, 'err');
                }
            })
            .catch((err) => {
                console.error(err);
                log('Error de red al enviar el formulario.', 'err');
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitSpinner.style.display = 'none';
            });
        }
        // Si viene de photo-input (archivo manual), dejar envío normal del form
    });
</script>
</body>
</html>