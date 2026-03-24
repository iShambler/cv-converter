<?php
/**
 * CV Converter - Secure file download handler
 * Serves files from output/ with path traversal protection
 */

require_once 'config.php';

$file = $_GET['file'] ?? '';

if (empty($file)) {
    http_response_code(400);
    exit('Archivo no especificado');
}

// Sanitize: only allow filename, no path traversal
$file = basename($file);

// Only allow safe extensions
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$allowedOutputExts = ['docx', 'txt'];
if (!in_array($ext, $allowedOutputExts)) {
    http_response_code(403);
    exit('Tipo de archivo no permitido');
}

$filePath = OUTPUT_PATH . $file;

if (!file_exists($filePath) || !is_file($filePath)) {
    http_response_code(404);
    exit('Archivo no encontrado');
}

// Ensure the resolved path is within OUTPUT_PATH
$realPath = realpath($filePath);
$realOutputPath = realpath(OUTPUT_PATH);
if ($realPath === false || strpos($realPath, $realOutputPath) !== 0) {
    http_response_code(403);
    exit('Acceso denegado');
}

// Serve the file
$mimeTypes = [
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'txt'  => 'text/plain; charset=utf-8',
];

// Nombre de descarga: si se pasa ?name=..., usarlo; si no, usar el nombre del archivo
$downloadName = basename($_GET['name'] ?? $file);
// Sanitizar: solo permitir caracteres seguros en el nombre
$downloadName = preg_replace('/[^\w\s\-áéíóúñÁÉÍÓÚÑ\.]/u', '', $downloadName);
if (empty($downloadName) || !str_ends_with(strtolower($downloadName), '.docx')) {
    $downloadName = $file;
}

header('Content-Type: ' . ($mimeTypes[$ext] ?? 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

readfile($filePath);
exit;
