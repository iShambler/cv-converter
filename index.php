<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CV Converter</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<div class="container">

    <!-- Logo + App title -->
    <div class="app-title">
        <img src="assets/img/logo.png" alt="Arelance" class="app-logo">
        <h1><span class="letter-badge">C</span>V <span class="letter-badge">C</span>onverter</h1>
    </div>

    <!-- Navigation -->
    <div class="nav-tabs">
        <a href="index.php" class="nav-tab active">Converter</a>
        <a href="historial.php" class="nav-tab">Historial</a>
    </div>

    <!-- Stepper -->
    <div class="stepper" id="stepper">
        <div class="stepper-step active" data-step="1">
            <div class="stepper-circle">1</div>
            <div class="stepper-label">Cargar</div>
        </div>
        <div class="stepper-line" id="stepperLine1"></div>
        <div class="stepper-step" data-step="2">
            <div class="stepper-circle">2</div>
            <div class="stepper-label">Plantilla</div>
        </div>
        <div class="stepper-line" id="stepperLine2"></div>
        <div class="stepper-step" data-step="3">
            <div class="stepper-circle">3</div>
            <div class="stepper-label">Convertir</div>
        </div>
        <div class="stepper-line" id="stepperLine3"></div>
        <div class="stepper-step" data-step="4">
            <div class="stepper-circle">4</div>
            <div class="stepper-label">Descargar</div>
        </div>
    </div>

    <div class="alert alert-danger" id="alertError"></div>
    <div class="alert alert-warning" id="alertWarning"></div>

    <form id="cvForm" enctype="multipart/form-data">

        <!-- ========== PASO 1: Subir CV ========== -->
        <div class="wizard-panel active" id="panel1">
            <div class="card card-with-cta">
                <h2>Sube tu curriculum</h2>
                <p class="card-subtitle">Convierte tu CV a la plantilla corporativa que necesites</p>
                <div class="upload-area" id="uploadArea">
                    <input type="file" name="cv_file" id="cvFile" accept=".pdf,.docx,.doc,.txt,.rtf,.jpg,.jpeg,.png,.webp" required>
                    <div class="upload-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    </div>
                    <p>Arrastra tu archivo aqui o <span class="accent-link">selecciona</span></p>
                    <p class="upload-formats">.pdf, .docx, .doc, .txt, .rtf, .jpg, .png &mdash; max. 10 MB</p>
                    <div class="filename" id="fileName"></div>
                </div>
                <button type="button" class="btn-cta" id="btnNext" disabled>
                    Analizar y continuar
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </button>
            </div>
        </div>

        <!-- ========== PASO 2: Plantilla + Acciones ========== -->
        <div class="wizard-panel" id="panel2">
            <div class="card">
                <h2>Elige la plantilla de destino</h2>
                <div class="file-badge" id="fileBadge"></div>
                <div class="template-grid" id="templateGrid">
                    <!-- Se llena dinamicamente -->
                </div>
            </div>

            <div class="actions-row">
                <button type="button" class="btn btn-outline-light btn-lg" id="btnBack">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                    Volver
                </button>
                <button type="submit" class="btn btn-accent btn-lg" id="btnConvert">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    Convertir CV
                </button>
            </div>

            <div class="progress-container" id="progress">
                <div class="progress-bar-bg">
                    <div class="progress-bar" id="progressBar"></div>
                </div>
                <div class="progress-text" id="progressText">Preparando...</div>
            </div>

            <div class="result" id="result">
                <div class="result-icon" id="resultIcon"></div>
                <h3></h3>
                <p></p>
                <div class="result-buttons" id="resultButtons">
                    <a href="#" class="btn btn-success" id="downloadBtn" style="display:none;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        Descargar CV convertido
                    </a>
                    <a href="#" class="btn btn-secondary" id="debugDownloadBtn" style="display:none;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        Descargar debug TXT
                    </a>
                </div>
                <br>
                <div class="json-preview" id="jsonPreview" style="display:none;">
                    <pre id="jsonContent"></pre>
                </div>
                <button type="button" class="toggle-json" id="toggleText" style="display:none;">Ver texto extraido del PDF</button>
                <div class="json-preview" id="textPreview">
                    <pre id="textContent"></pre>
                </div>
            </div>
        </div>

    </form>

    <footer>
        ARELANCE &middot; CV Converter
    </footer>
</div>

<script>
const TEMPLATES = <?php
    require_once 'config.php';
    echo json_encode(TEMPLATES);
?>;

// ===== WIZARD STATE =====
let currentStep = 1;
const panel1 = document.getElementById('panel1');
const panel2 = document.getElementById('panel2');
const btnNext = document.getElementById('btnNext');
const btnBack = document.getElementById('btnBack');

function goToStep(step) {
    currentStep = step;
    hideAlerts();

    panel1.classList.toggle('active', step === 1);
    panel2.classList.toggle('active', step === 2);

    updateStepper(step);

    if (step === 2 && fileInput.files.length > 0) {
        document.getElementById('fileBadge').textContent = fileInput.files[0].name;
    }

    window.scrollTo({ top: 0, behavior: 'smooth' });
}

btnNext.addEventListener('click', () => {
    if (!fileInput.files.length) {
        showError('Selecciona un archivo CV primero');
        return;
    }
    goToStep(2);
});

btnBack.addEventListener('click', () => {
    goToStep(1);
});

// ===== STEPPER =====
const checkSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';

function updateStepper(activeStep) {
    const steps = document.querySelectorAll('.stepper-step');
    const lines = document.querySelectorAll('.stepper-line');

    steps.forEach((step, i) => {
        const num = i + 1;
        step.classList.remove('active', 'completed');
        if (num < activeStep) {
            step.classList.add('completed');
            step.querySelector('.stepper-circle').innerHTML = checkSvg;
        } else if (num === activeStep) {
            step.classList.add('active');
            step.querySelector('.stepper-circle').textContent = num;
        } else {
            step.querySelector('.stepper-circle').textContent = num;
        }
    });

    lines.forEach((line, i) => {
        line.classList.toggle('completed', i + 1 < activeStep);
    });
}

// ===== TEMPLATE GRID =====
const grid = document.getElementById('templateGrid');
let first = true;
for (const [key, tpl] of Object.entries(TEMPLATES)) {
    const div = document.createElement('div');
    div.className = 'template-option';
    div.innerHTML = `
        <input type="radio" name="template" value="${key}" id="tpl_${key}" ${first ? 'checked' : ''}>
        <label for="tpl_${key}">${tpl.name}</label>
    `;
    grid.appendChild(div);
    first = false;
}

// ===== FILE UPLOAD =====
const fileInput = document.getElementById('cvFile');
const uploadArea = document.getElementById('uploadArea');
const fileNameEl = document.getElementById('fileName');

function onFileSelected() {
    if (fileInput.files.length > 0) {
        fileNameEl.textContent = fileInput.files[0].name;
        btnNext.disabled = false;
    }
}

fileInput.addEventListener('change', onFileSelected);

uploadArea.addEventListener('dragover', (e) => { e.preventDefault(); uploadArea.classList.add('dragover'); });
uploadArea.addEventListener('dragleave', () => { uploadArea.classList.remove('dragover'); });
uploadArea.addEventListener('drop', (e) => {
    e.preventDefault();
    uploadArea.classList.remove('dragover');
    fileInput.files = e.dataTransfer.files;
    onFileSelected();
});

// ===== ELEMENTS =====
const form = document.getElementById('cvForm');
const progress = document.getElementById('progress');
const progressBar = document.getElementById('progressBar');
const progressText = document.getElementById('progressText');
const result = document.getElementById('result');
const btnConvert = document.getElementById('btnConvert');

const iconSuccess = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
const iconError = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
const iconSearch = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';

// ===== CONVERT / DEBUG =====
form.addEventListener('submit', (e) => { e.preventDefault(); doProcess('convert'); });

async function doProcess(mode) {
    if (!fileInput.files.length) { showError('Selecciona un archivo CV'); return; }

    const template = document.querySelector('input[name="template"]:checked');
    if (mode === 'convert' && !template) { showError('Selecciona una plantilla'); return; }

    hideAlerts();
    result.className = 'result';
    progress.classList.add('active');
    btnConvert.disabled = true;
    btnBack.disabled = true;
    document.getElementById('downloadBtn').style.display = 'none';
    document.getElementById('debugDownloadBtn').style.display = 'none';
    document.getElementById('toggleText').style.display = 'none';
    document.getElementById('textPreview').classList.remove('active');
    document.getElementById('jsonPreview').classList.remove('active');

    // Move stepper to step 3
    updateStepper(3);

    const formData = new FormData();
    formData.append('cv_file', fileInput.files[0]);
    if (template) formData.append('template', template.value);

    let fakePct = 10;
    setProgress(10, mode === 'debug' ? 'Extrayendo texto...' : 'Subiendo archivo...');
    const progressInterval = setInterval(() => {
        fakePct += 2;
        if (fakePct > 85) fakePct = 85;
        const msgs = mode === 'debug' ? [
            [10, 'Extrayendo texto del archivo...'],
            [30, 'Enviando a Claude...'],
            [50, 'Analizando con IA...'],
            [70, 'Generando debug...'],
        ] : [
            [10, 'Subiendo archivo...'],
            [20, 'Extrayendo texto del CV...'],
            [35, 'Enviando a Claude para analisis...'],
            [50, 'Analizando contenido con IA...'],
            [65, 'Estructurando datos del CV...'],
            [75, 'Rellenando plantilla...'],
        ];
        for (const [threshold, msg] of [...msgs].reverse()) {
            if (fakePct >= threshold) { setProgress(fakePct, msg); break; }
        }
    }, 800);

    const endpoint = mode === 'debug' ? 'debug.php' : 'process.php';

    try {
        const resp = await fetch(endpoint, { method: 'POST', body: formData });
        clearInterval(progressInterval);
        setProgress(90, 'Procesando respuesta...');

        const text = await resp.text();
        console.log(`[${mode}] Respuesta:`, text);

        let data;
        try { data = JSON.parse(text); }
        catch(e) { throw new Error('Respuesta no valida: ' + text.substring(0, 300)); }

        if (!data.success) throw new Error(data.error || 'Error desconocido');

        setProgress(100, 'Completado!');
        await sleep(300);
        progress.classList.remove('active');

        // Move stepper to step 4
        updateStepper(4);

        if (mode === 'debug') {
            result.className = 'result success active';
            document.getElementById('resultIcon').innerHTML = iconSearch;
            result.querySelector('h3').textContent = 'Datos extraidos';
            result.querySelector('p').textContent = `Texto: ${data.text_length} caracteres`;

            const dbgBtn = document.getElementById('debugDownloadBtn');
            dbgBtn.href = data.debug_url;
            dbgBtn.download = data.debug_filename;
            dbgBtn.style.display = 'inline-flex';

            if (data.extracted_text) {
                document.getElementById('textContent').textContent = data.extracted_text;
                document.getElementById('toggleText').style.display = 'inline';
            }

            if (data.parsed_data) {
                document.getElementById('jsonContent').textContent = JSON.stringify(data.parsed_data, null, 2);
            } else if (data.ai_error) {
                document.getElementById('jsonContent').textContent = 'ERROR: ' + data.ai_error;
            }

        } else {
            result.className = 'result success active';
            document.getElementById('resultIcon').innerHTML = iconSuccess;
            result.querySelector('h3').textContent = 'CV convertido con exito';
            result.querySelector('p').textContent = `Plantilla: ${TEMPLATES[template.value].name}`;

            const dlBtn = document.getElementById('downloadBtn');
            dlBtn.href = data.download_url;
            dlBtn.download = data.filename;
            dlBtn.style.display = 'inline-flex';

            if (data.parsed_data) {
                document.getElementById('jsonContent').textContent = JSON.stringify(data.parsed_data, null, 2);
            }
        }

    } catch (err) {
        clearInterval(progressInterval);
        progress.classList.remove('active');
        result.className = 'result error active';
        document.getElementById('resultIcon').innerHTML = iconError;
        result.querySelector('h3').textContent = 'Error al procesar';
        result.querySelector('p').textContent = err.message;
    }

    btnConvert.disabled = false;
    btnBack.disabled = false;
}

// ===== TOGGLES =====
document.getElementById('toggleText').addEventListener('click', () => {
    document.getElementById('textPreview').classList.toggle('active');
});

// ===== UTILS =====
function setProgress(pct, txt) { progressBar.style.width = pct+'%'; progressText.textContent = txt; }
function showError(msg) { const el = document.getElementById('alertError'); el.textContent = msg; el.classList.add('active'); }
function hideAlerts() { document.querySelectorAll('.alert').forEach(a => a.classList.remove('active')); }
function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }


</script>
</body>
</html>
