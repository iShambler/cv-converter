<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de CVs - CV Converter</title>
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
        <a href="index.php" class="nav-tab">Converter</a>
        <a href="historial.php" class="nav-tab active">Historial</a>
    </div>

    <!-- ===== VISTA 1: Carpetas ===== -->
    <div id="viewFolders">
        <div class="folders-grid" id="foldersGrid">
            <div class="cv-list-loading">Cargando...</div>
        </div>
    </div>

    <!-- ===== VISTA 2: Detalle de plantilla ===== -->
    <div id="viewDetail" style="display:none;">
        <button class="btn-back-folder" id="btnBackFolders">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Volver a carpetas
        </button>

        <div class="card">
            <h2 id="detailTitle"></h2>

            <div class="search-bar">
                <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="searchInput" placeholder="Buscar por nombre..." autocomplete="off">
            </div>

            <div id="cvList" class="cv-list">
                <div class="cv-list-loading">Cargando...</div>
            </div>

            <div class="pagination" id="pagination"></div>
        </div>
    </div>

    <footer>
        ARELANCE &middot; CV Converter
    </footer>
</div>

<!-- Delete confirmation modal -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box">
        <h3>Eliminar CV</h3>
        <p>¿Seguro que quieres eliminar este curriculum? Esta accion no se puede deshacer.</p>
        <div class="modal-actions">
            <button class="btn btn-outline" id="btnCancelDelete">Cancelar</button>
            <button class="btn btn-danger" id="btnConfirmDelete">Eliminar</button>
        </div>
    </div>
</div>

<script>
<?php require_once 'config.php'; ?>
const TEMPLATES = <?php echo json_encode(TEMPLATES); ?>;

const PER_PAGE = 5;
let currentTemplate = '';
let currentPage = 1;
let currentSearch = '';
let deleteId = null;
let debounceTimer = null;

const viewFolders = document.getElementById('viewFolders');
const viewDetail = document.getElementById('viewDetail');
const foldersGrid = document.getElementById('foldersGrid');
const cvList = document.getElementById('cvList');
const pagination = document.getElementById('pagination');
const searchInput = document.getElementById('searchInput');
const deleteModal = document.getElementById('deleteModal');

// ===== FOLDERS VIEW =====
async function loadFolders() {
    foldersGrid.innerHTML = '<div class="cv-list-loading">Cargando...</div>';

    try {
        const resp = await fetch('api/cvs.php?action=counts');
        const data = await resp.json();
        if (!data.success) throw new Error(data.error);

        const counts = data.counts;
        let html = '';

        for (const [key, tpl] of Object.entries(TEMPLATES)) {
            const count = counts[key] || 0;
            html += `
            <div class="folder-card${count === 0 ? ' folder-empty' : ''}" onclick="${count > 0 ? `openFolder('${key}')` : ''}">
                <div class="folder-icon">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                </div>
                <div class="folder-name">${escapeHtml(tpl.name)}</div>
                <div class="folder-count">${count} CV${count !== 1 ? 's' : ''}</div>
            </div>`;
        }

        foldersGrid.innerHTML = html;

    } catch (err) {
        foldersGrid.innerHTML = `<div class="cv-list-empty cv-list-error">
            <p>Error: ${escapeHtml(err.message)}</p>
            <button class="btn btn-outline" onclick="initDb()">Inicializar base de datos</button>
        </div>`;
    }
}

// ===== DETAIL VIEW =====
function openFolder(templateKey) {
    currentTemplate = templateKey;
    currentPage = 1;
    currentSearch = '';
    searchInput.value = '';

    document.getElementById('detailTitle').textContent = TEMPLATES[templateKey].name;

    viewFolders.style.display = 'none';
    viewDetail.style.display = 'block';

    loadCvs();
}

function backToFolders() {
    viewDetail.style.display = 'none';
    viewFolders.style.display = 'block';
    loadFolders();
}

document.getElementById('btnBackFolders').addEventListener('click', backToFolders);

// Search with debounce
searchInput.addEventListener('input', () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        currentSearch = searchInput.value.trim();
        currentPage = 1;
        loadCvs();
    }, 300);
});

async function loadCvs() {
    cvList.innerHTML = '<div class="cv-list-loading">Cargando...</div>';
    pagination.innerHTML = '';

    try {
        const params = new URLSearchParams({
            action: 'list',
            template: currentTemplate,
            search: currentSearch,
            page: currentPage,
            limit: PER_PAGE,
        });
        const resp = await fetch('api/cvs.php?' + params);
        const data = await resp.json();

        if (!data.success) throw new Error(data.error);

        if (data.data.length === 0) {
            cvList.innerHTML = `<div class="cv-list-empty">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <p>${currentSearch ? 'Sin resultados para "' + escapeHtml(currentSearch) + '"' : 'No hay CVs en esta carpeta'}</p>
            </div>`;
            return;
        }

        let html = '';
        for (const cv of data.data) {
            const date = new Date(cv.created_at).toLocaleDateString('es-ES', {
                day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit'
            });

            html += `
            <div class="cv-item">
                <div class="cv-item-info">
                    <div class="cv-item-name">${escapeHtml(cv.nombre)}</div>
                    <div class="cv-item-meta">
                        <span class="cv-item-date">${date}</span>
                    </div>
                </div>
                <div class="cv-item-actions">
                    <a href="output/${encodeURIComponent(cv.archivo)}" download class="btn btn-sm btn-success" title="Descargar">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    </a>
                    <button class="btn btn-sm btn-danger-outline" title="Eliminar" onclick="confirmDelete(${cv.id})">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                    </button>
                </div>
            </div>`;
        }
        cvList.innerHTML = html;

        renderPagination(data.page, data.pages, data.total);

    } catch (err) {
        cvList.innerHTML = `<div class="cv-list-empty cv-list-error">
            <p>Error: ${escapeHtml(err.message)}</p>
        </div>`;
    }
}

function renderPagination(page, pages, total) {
    if (pages <= 1) { pagination.innerHTML = ''; return; }

    let html = `<span class="pagination-info">${total} CV${total !== 1 ? 's' : ''}</span>`;
    html += `<div class="pagination-buttons">`;

    if (page > 1) {
        html += `<button class="btn btn-sm btn-outline" onclick="goToPage(${page - 1})">Anterior</button>`;
    }
    html += `<span class="pagination-current">${page} / ${pages}</span>`;
    if (page < pages) {
        html += `<button class="btn btn-sm btn-outline" onclick="goToPage(${page + 1})">Siguiente</button>`;
    }
    html += `</div>`;
    pagination.innerHTML = html;
}

function goToPage(p) {
    currentPage = p;
    loadCvs();
}

// ===== DELETE =====
function confirmDelete(id) {
    deleteId = id;
    deleteModal.classList.add('active');
}

document.getElementById('btnCancelDelete').addEventListener('click', () => {
    deleteModal.classList.remove('active');
    deleteId = null;
});

deleteModal.addEventListener('click', (e) => {
    if (e.target === deleteModal) {
        deleteModal.classList.remove('active');
        deleteId = null;
    }
});

document.getElementById('btnConfirmDelete').addEventListener('click', async () => {
    if (!deleteId) return;

    try {
        const resp = await fetch(`api/cvs.php?action=delete&id=${deleteId}`, { method: 'POST' });
        const data = await resp.json();
        if (!data.success) throw new Error(data.error);
        deleteModal.classList.remove('active');
        deleteId = null;
        loadCvs();
    } catch (err) {
        alert('Error al eliminar: ' + err.message);
    }
});

// ===== UTILS =====
async function initDb() {
    try {
        const resp = await fetch('api/cvs.php?action=init');
        const data = await resp.json();
        if (!data.success) throw new Error(data.error);
        loadFolders();
    } catch (err) {
        alert('Error al inicializar: ' + err.message);
    }
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Initial load
loadFolders();
</script>
</body>
</html>
