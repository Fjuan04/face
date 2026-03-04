<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>ESP32-CAM · Test de Reconocimiento Facial</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Rajdhani:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --green:   #00ff9d;
            --green2:  #00c97a;
            --green-dim: rgba(0,255,157,0.08);
            --red:     #ff3c5a;
            --amber:   #ffb800;
            --bg:      #050a07;
            --bg2:     #0a1210;
            --bg3:     #0f1c18;
            --border:  rgba(0,255,157,0.18);
            --text:    #b6ffe0;
            --text-dim:#4d8a6a;
            --mono:    'Share Tech Mono', monospace;
            --sans:    'Rajdhani', sans-serif;
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

        /* Scanlines overlay */
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
            max-width: 900px;
        }

        /* ── HEADER ─────────────────────────────── */
        .header {
            border: 1px solid var(--border);
            background: var(--bg2);
            padding: 1rem 1.5rem;
            margin-bottom: 0;
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
            position: relative;
            flex-shrink: 0;
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
        .header-sub  { font-family: var(--mono); font-size: 0.72rem; color: var(--text-dim); margin-top: 2px; }
        .header-meta { font-family: var(--mono); font-size: 0.7rem; color: var(--text-dim); text-align: right; line-height: 1.6; }
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
            gap: 0;
            border: 1px solid var(--border);
            background: var(--bg2);
        }

        /* ── CAM PANEL ───────────────────────────── */
        .cam-panel {
            border-right: 1px solid var(--border);
            padding: 1.25rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
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

        .viewfinder {
            position: relative;
            background: #000;
            border: 1px solid var(--border);
            aspect-ratio: 4/3;
            overflow: hidden;
        }
        .viewfinder video,
        .viewfinder canvas,
        .viewfinder img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

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
        @keyframes scan {
            0%   { top: 0%; }
            100% { top: 100%; }
        }

        .cam-overlay {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 0.5rem;
            background: rgba(5,10,7,0.85);
            z-index: 4;
        }
        .cam-overlay svg { width: 32px; height: 32px; stroke: var(--text-dim); fill: none; stroke-width: 1.5; }
        .cam-overlay span { font-family: var(--mono); font-size: 0.72rem; color: var(--text-dim); }

        .controls {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
        }
        .controls .btn-wide { grid-column: span 2; }

        .btn {
            font-family: var(--sans);
            font-weight: 600;
            font-size: 0.85rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            padding: 0.55rem 1rem;
            border: 1px solid;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            transition: background 0.15s, color 0.15s, border-color 0.15s, box-shadow 0.15s;
            background: transparent;
            clip-path: polygon(0 0, calc(100% - 8px) 0, 100% 8px, 100% 100%, 8px 100%, 0 calc(100% - 8px));
        }
        .btn svg { width: 14px; height: 14px; flex-shrink: 0; }

        .btn-start  { border-color: var(--text-dim); color: var(--text-dim); }
        .btn-start:hover:not(:disabled)  { border-color: var(--text); color: var(--text); background: rgba(182,255,224,0.06); }

        .btn-capture { border-color: var(--amber); color: var(--amber); }
        .btn-capture:hover:not(:disabled) { background: rgba(255,184,0,0.1); box-shadow: 0 0 12px rgba(255,184,0,0.2); }

        .btn-send { border-color: var(--green); color: var(--green); }
        .btn-send:hover:not(:disabled) { background: rgba(0,255,157,0.1); box-shadow: 0 0 18px rgba(0,255,157,0.25); }

        .btn-reset { border-color: var(--red); color: var(--red); }
        .btn-reset:hover:not(:disabled) { background: rgba(255,60,90,0.08); }

        .btn:disabled { opacity: 0.3; cursor: not-allowed; }

        /* Checkbox toggle */
        .toggle-row {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            font-family: var(--mono);
            font-size: 0.72rem;
            color: var(--text-dim);
            cursor: pointer;
            user-select: none;
        }
        .toggle-row input { display: none; }
        .toggle-track {
            width: 30px; height: 16px;
            border: 1px solid var(--text-dim);
            border-radius: 8px;
            position: relative;
            transition: border-color 0.2s, background 0.2s;
            flex-shrink: 0;
        }
        .toggle-track::after {
            content: '';
            position: absolute;
            top: 2px; left: 2px;
            width: 10px; height: 10px;
            background: var(--text-dim);
            border-radius: 50%;
            transition: transform 0.2s, background 0.2s;
        }
        .toggle-row input:checked ~ .toggle-track { border-color: var(--green); background: rgba(0,255,157,0.1); }
        .toggle-row input:checked ~ .toggle-track::after { transform: translateX(14px); background: var(--green); }

        /* ── INFO PANEL ───────────────────────────── */
        .info-panel {
            padding: 1.25rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .status-block {
            border: 1px solid var(--border);
            padding: 0.75rem;
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }
        .status-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-family: var(--mono);
            font-size: 0.72rem;
        }
        .status-key { color: var(--text-dim); }
        .status-val { color: var(--text); }
        .status-val.ok  { color: var(--green); }
        .status-val.err { color: var(--red); }
        .status-val.warn { color: var(--amber); }

        .result-block {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .result-display {
            border: 1px solid var(--border);
            background: #000;
            padding: 0.75rem;
            flex: 1;
            min-height: 120px;
            position: relative;
            overflow: hidden;
        }
        .result-display::before {
            content: 'RESPUESTA DEL SERVIDOR';
            display: block;
            font-family: var(--mono);
            font-size: 0.6rem;
            color: var(--text-dim);
            letter-spacing: 0.15em;
            margin-bottom: 0.6rem;
            padding-bottom: 0.4rem;
            border-bottom: 1px solid var(--border);
        }

        .result-inner {
            font-family: var(--mono);
            font-size: 0.75rem;
            color: var(--text);
            white-space: pre-wrap;
            word-break: break-all;
            max-height: 200px;
            overflow-y: auto;
            line-height: 1.5;
        }
        .result-inner::-webkit-scrollbar { width: 4px; }
        .result-inner::-webkit-scrollbar-track { background: transparent; }
        .result-inner::-webkit-scrollbar-thumb { background: var(--text-dim); border-radius: 2px; }

        /* Access result banner */
        .access-banner {
            display: none;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            padding: 0.75rem;
            border: 1px solid;
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            clip-path: polygon(0 0, calc(100% - 10px) 0, 100% 10px, 100% 100%, 10px 100%, 0 calc(100% - 10px));
        }
        .access-banner.granted { border-color: var(--green); color: var(--green); background: rgba(0,255,157,0.06); animation: flash-green 0.6s ease; }
        .access-banner.denied  { border-color: var(--red);   color: var(--red);   background: rgba(255,60,90,0.06);  animation: flash-red  0.6s ease; }
        .access-banner.show { display: flex; }
        @keyframes flash-green { 0%,100% { box-shadow: none; } 50% { box-shadow: 0 0 30px rgba(0,255,157,0.5); } }
        @keyframes flash-red   { 0%,100% { box-shadow: none; } 50% { box-shadow: 0 0 30px rgba(255,60,90,0.5);  } }

        /* ── FOOTER / LOG ─────────────────────────── */
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
        .log-footer::before {
            content: '//';
            color: var(--green);
            opacity: 0.5;
        }
        #log-msg { transition: color 0.2s; }
        #log-msg.ok   { color: var(--green); }
        #log-msg.err  { color: var(--red); }
        #log-msg.warn { color: var(--amber); }

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

        /* ── DIVIDER ─────────────────────────────── */
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

        /* ── COUNTER PILLS ───────────────────────── */
        .counters {
            display: flex;
            gap: 0.5rem;
        }
        .counter-pill {
            flex: 1;
            border: 1px solid var(--border);
            padding: 0.5rem 0.6rem;
            text-align: center;
        }
        .counter-pill .num { font-size: 1.4rem; font-weight: 700; color: var(--green); font-family: var(--mono); display: block; line-height: 1; }
        .counter-pill .lbl { font-family: var(--mono); font-size: 0.6rem; color: var(--text-dim); letter-spacing: 0.1em; display: block; margin-top: 3px; }
        .counter-pill.denied  .num { color: var(--red); }
        .counter-pill.pending .num { color: var(--amber); }

        @media (max-width: 640px) {
            .main { grid-template-columns: 1fr; }
            .cam-panel { border-right: none; border-bottom: 1px solid var(--border); }
            .controls { grid-template-columns: 1fr; }
            .controls .btn-wide { grid-column: span 1; }
        }
    </style>
</head>
<body>
<div class="shell">

    <!-- HEADER -->
    <div class="header">
        <div class="header-left">
            <div class="header-icon">
                <svg viewBox="0 0 24 24"><path d="M12 2C9.243 2 7 4.243 7 7s2.243 5 5 5 5-2.243 5-5-2.243-5-5-5zM2.001 22l.001-.791C2.002 16.622 6.622 12 12.244 12h-.245C17.513 12 22 16.487 22 21.999V22H2.001z"/></svg>
            </div>
            <div>
                <div class="header-title">Sistema de Reconocimiento Facial</div>
                <div class="header-sub">SENA · Control de Acceso Biométrico · ESP32-CAM Emulator</div>
            </div>
        </div>
        <div class="header-meta">
            <span class="badge">v1.0</span><br>
            <span id="clock">--:--:--</span>
        </div>
    </div>

    <!-- MAIN GRID -->
    <div class="main">

        <!-- CAM PANEL -->
        <div class="cam-panel">
            <div class="panel-label">Entrada de cámara</div>

            <div class="viewfinder" id="viewfinder">
                <div class="bracket-bl"></div>
                <div class="bracket-br"></div>
                <div class="scan-line" id="scanLine"></div>

                <!-- Overlay: mostrar cuando cámara está apagada -->
                <div class="cam-overlay" id="camOverlay">
                    <svg viewBox="0 0 24 24"><path d="M15 8v8H5V8h10m1-2H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4V7c0-.55-.45-1-1-1z"/></svg>
                    <span>Cámara no iniciada</span>
                </div>

                <video id="video" autoplay playsinline style="display:none;"></video>
                <canvas id="canvas" style="display:none;"></canvas>
                <img id="preview" alt="" style="display:none; position:absolute; inset:0; width:100%; height:100%; object-fit:cover;">
            </div>

            <div class="section-divider">Controles</div>

            <div class="controls">
                <button id="start-btn" class="btn btn-start" type="button">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8" fill="currentColor" stroke="none"/></svg>
                    Iniciar cámara
                </button>
                <button id="capture-btn" class="btn btn-capture" type="button" disabled>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M20 5h-3.17L15 3H9L7.17 5H4a2 2 0 00-2 2v12a2 2 0 002 2h16a2 2 0 002-2V7a2 2 0 00-2-2z"/></svg>
                    Capturar
                </button>
                <button id="send-btn" class="btn btn-send btn-wide" type="button" disabled>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
                    Enviar a /api/recognize
                </button>
                <button id="reset-btn" class="btn btn-reset" type="button">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                    Reset
                </button>
                <label class="toggle-row" title="Imita el comportamiento real de la ESP32: envía multipart/form-data en lugar de JSON+Base64">
                    <input type="checkbox" id="esp32-mode" checked>
                    <span class="toggle-track"></span>
                    Modo ESP32 (multipart)
                </label>
            </div>
        </div>

        <!-- INFO PANEL -->
        <div class="info-panel">
            <div class="panel-label">Estado del sistema</div>

            <div class="status-block">
                <div class="status-row">
                    <span class="status-key">ENDPOINT</span>
                    <span class="status-val" style="font-size:0.65rem;">POST /api/recognize</span>
                </div>
                <div class="status-row">
                    <span class="status-key">MODO DE ENVÍO</span>
                    <span class="status-val ok" id="mode-display">multipart/form-data</span>
                </div>
                <div class="status-row">
                    <span class="status-key">IP SIMULADA</span>
                    <span class="status-val" id="ip-display">127.0.0.1</span>
                </div>
                <div class="status-row">
                    <span class="status-key">CÁMARA</span>
                    <span class="status-val warn" id="cam-status">OFFLINE</span>
                </div>
                <div class="status-row">
                    <span class="status-key">IMAGEN</span>
                    <span class="status-val warn" id="img-status">SIN CAPTURA</span>
                </div>
            </div>

            <div class="counters">
                <div class="counter-pill">
                    <span class="num" id="cnt-total">0</span>
                    <span class="lbl">TOTAL</span>
                </div>
                <div class="counter-pill">
                    <span class="num" id="cnt-ok">0</span>
                    <span class="lbl">ACCESO OK</span>
                </div>
                <div class="counter-pill denied">
                    <span class="num" id="cnt-deny">0</span>
                    <span class="lbl">DENEGADO</span>
                </div>
            </div>

            <div id="access-banner" class="access-banner"></div>

            <div class="result-block">
                <div class="section-divider">Respuesta API</div>
                <div class="result-display">
                    <div class="result-inner" id="response-box">{}</div>
                </div>
            </div>
        </div>
    </div>

    <!-- LOG FOOTER -->
    <div class="log-footer">
        <div class="spinner" id="spinner"></div>
        <span id="log-msg">Sistema listo. Inicia la cámara para comenzar.</span>
    </div>

</div>

<script>
    // ── ELEMENTS ──────────────────────────────────────────────
    const video       = document.getElementById('video');
    const canvas      = document.getElementById('canvas');
    const preview     = document.getElementById('preview');
    const startBtn    = document.getElementById('start-btn');
    const captureBtn  = document.getElementById('capture-btn');
    const sendBtn     = document.getElementById('send-btn');
    const resetBtn    = document.getElementById('reset-btn');
    const esp32Mode   = document.getElementById('esp32-mode');
    const scanLine    = document.getElementById('scanLine');
    const camOverlay  = document.getElementById('camOverlay');
    const responseBox = document.getElementById('response-box');
    const logMsg      = document.getElementById('log-msg');
    const spinner     = document.getElementById('spinner');
    const camStatus   = document.getElementById('cam-status');
    const imgStatus   = document.getElementById('img-status');
    const modeDisplay = document.getElementById('mode-display');
    const accessBanner= document.getElementById('access-banner');
    const cntTotal    = document.getElementById('cnt-total');
    const cntOk       = document.getElementById('cnt-ok');
    const cntDeny     = document.getElementById('cnt-deny');

    let stream  = null;
    let lastBlob = null;
    let stats = { total: 0, ok: 0, deny: 0 };

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
    function loading(active) {
        spinner.classList.toggle('active', active);
    }

    // ── MODE TOGGLE ───────────────────────────────────────────
    esp32Mode.addEventListener('change', () => {
        modeDisplay.textContent = esp32Mode.checked ? 'multipart/form-data' : 'application/json (base64)';
        log(esp32Mode.checked
            ? 'Modo ESP32: envío como multipart/form-data (comportamiento real).'
            : 'Modo estándar: envío como JSON con imagen en base64.',
            'warn');
    });

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
            startBtn.disabled = true;
            camStatus.textContent = 'ONLINE';
            camStatus.className = 'status-val ok';
            log('Cámara activa. Enfoca el rostro y captura.', 'ok');
        } catch (e) {
            log('No se pudo acceder a la cámara. Revisa los permisos.', 'err');
            camStatus.textContent = 'ERROR';
            camStatus.className = 'status-val err';
        }
    }

    // ── CAPTURE ───────────────────────────────────────────────
    function capturePhoto() {
        if (!stream) return;
        const w = video.videoWidth  || 640;
        const h = video.videoHeight || 480;
        canvas.width  = w;
        canvas.height = h;
        canvas.getContext('2d').drawImage(video, 0, 0, w, h);

        canvas.toBlob((blob) => {
            if (!blob) { log('No se pudo capturar la imagen.', 'err'); return; }
            lastBlob = blob;
            const url = URL.createObjectURL(blob);
            preview.src = url;
            preview.style.display = 'block';
            video.style.display = 'none';
            scanLine.classList.remove('active');
            sendBtn.disabled = false;
            imgStatus.textContent = `LISTA (${(blob.size / 1024).toFixed(1)} KB)`;
            imgStatus.className = 'status-val ok';
            log('Imagen capturada. Presiona "Enviar" para reconocer.', 'ok');
        }, 'image/jpeg', 0.92);
    }

    // ── RESET ─────────────────────────────────────────────────
    function resetCapture() {
        if (stream) {
            preview.style.display = 'none';
            video.style.display = 'block';
            scanLine.classList.add('active');
        }
        lastBlob = null;
        sendBtn.disabled = true;
        imgStatus.textContent = 'SIN CAPTURA';
        imgStatus.className = 'status-val warn';
        accessBanner.className = 'access-banner';
        accessBanner.textContent = '';
        log('Captura descartada. Listo para nueva captura.', '');
    }

    // ── SEND ──────────────────────────────────────────────────
    async function sendToApi() {
        if (!lastBlob) { log('Primero captura una imagen.', 'err'); return; }

        log('Enviando imagen al servidor…');
        loading(true);
        sendBtn.disabled = true;
        accessBanner.className = 'access-banner';
        responseBox.textContent = '…';

        try {
            let response;

            if (esp32Mode.checked) {
                // ── Modo ESP32: multipart/form-data (idéntico al .ino) ──
                const formData = new FormData();
                formData.append('imagen', lastBlob, 'esp32cam.jpg');
                // El .ino también puede enviar la IP como dato adicional si se configura
                response = await fetch('{{ route('api.recognize') }}', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    // NO Content-Type manual — fetch lo genera con boundary correcto
                    body: formData,
                });
            } else {
                // ── Modo JSON base64 (comportamiento anterior) ──
                const dataUrl = await blobToDataUrl(lastBlob);
                response = await fetch('{{ route('api.recognize') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ ip: '127.0.0.1', imagen: dataUrl }),
                });
            }

            const data = await response.json().catch(() => ({}));
            responseBox.textContent = JSON.stringify(data, null, 2);
            stats.total++;
            cntTotal.textContent = stats.total;

            const granted = data.coincidencia === true
                         || data.access === true
                         || data.resultado === 'ok'
                         || (data.status && String(data.status).toLowerCase().includes('ok'));

            if (granted) {
                stats.ok++;
                cntOk.textContent = stats.ok;
                accessBanner.textContent = '✓  ACCESO CONCEDIDO';
                accessBanner.className = 'access-banner granted show';
                log('Acceso concedido.', 'ok');
            } else {
                stats.deny++;
                cntDeny.textContent = stats.deny;
                accessBanner.textContent = '✗  ACCESO DENEGADO';
                accessBanner.className = 'access-banner denied show';
                log(response.ok ? 'Reconocimiento completado — acceso denegado.' : `Error HTTP ${response.status}.`, 'err');
            }

        } catch (e) {
            console.error(e);
            responseBox.textContent = `Error de red:\n${e.message}`;
            log('Error de red al contactar la API.', 'err');
        } finally {
            loading(false);
            sendBtn.disabled = false;
        }
    }

    // ── HELPERS ───────────────────────────────────────────────
    function blobToDataUrl(blob) {
        return new Promise((res, rej) => {
            const r = new FileReader();
            r.onloadend = () => res(r.result);
            r.onerror   = rej;
            r.readAsDataURL(blob);
        });
    }

    // ── EVENTS ────────────────────────────────────────────────
    startBtn.addEventListener('click', startCamera);
    captureBtn.addEventListener('click', capturePhoto);
    sendBtn.addEventListener('click', sendToApi);
    resetBtn.addEventListener('click', resetCapture);

    window.addEventListener('beforeunload', () => {
        if (stream) stream.getTracks().forEach(t => t.stop());
    });
</script>
</body>
</html>