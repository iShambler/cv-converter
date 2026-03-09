<?php
/**
 * CV Converter - Configuración
 *
 * La API key se carga desde .env (variable de entorno).
 * Si no existe .env, se usa la constante como fallback (no recomendado en producción).
 */

// Cargar .env si existe
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (str_contains($line, '=')) {
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val);
            if (!getenv($key)) putenv("$key=$val");
            $_ENV[$key] = $val;
        }
    }
}

// API de Anthropic (Claude)
// La constante es solo fallback; en producción usar .env
define('ANTHROPIC_API_KEY', getenv('ANTHROPIC_API_KEY') ?: '');
define('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514');
define('ANTHROPIC_API_URL', 'https://api.anthropic.com/v1/messages');

// Rutas
define('BASE_PATH', __DIR__);
define('UPLOAD_PATH',   BASE_PATH . '/uploads/');
define('TEMPLATE_PATH', BASE_PATH . '/templates/');
define('OUTPUT_PATH',   BASE_PATH . '/output/');

// Límites
define('MAX_FILE_SIZE',       10 * 1024 * 1024); // 10 MB
define('ALLOWED_EXTENSIONS',  ['pdf', 'docx', 'doc', 'txt', 'rtf', 'jpg', 'jpeg', 'png', 'webp']);

// Plantillas disponibles
define('TEMPLATES', [
    'accenture' => ['name' => 'Accenture 2025', 'file' => 'Accenture_2025.docx'],
    'arelance'  => ['name' => 'Arelance',        'file' => 'Arelance.docx'],
    'avanade'   => ['name' => 'Avanade',          'file' => 'Avanade.docx'],
    'eviden'    => ['name' => 'Eviden',            'file' => 'EVIDEN.docx'],
    'inetum'    => ['name' => 'Inetum',            'file' => 'Inetum.docx'],
    'ricoh'     => ['name' => 'Ricoh',             'file' => 'Ricoh.docx'],
    'atos'      => ['name' => 'ATOS',              'file' => 'Plantilla_ATOS.docx'],
]);

// Base de datos
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'cv_converter');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

// Zona horaria
date_default_timezone_set('Europe/Madrid');
