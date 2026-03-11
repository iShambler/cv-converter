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

header('Content-Type: ' . ($mimeTypes[$ext] ?? 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

readfile($filePath);
exit;
