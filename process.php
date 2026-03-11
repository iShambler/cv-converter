<?php
/**
 * CV Converter - Endpoint de procesamiento
 */

header('Content-Type: application/json');

try {
    require_once 'config.php';
    require_once 'lib/Security.php';
    require_once 'lib/TextExtractor.php';
    require_once 'lib/CvParser.php';
    require_once 'lib/TemplateFiller.php';
    require_once 'lib/Database.php';

    Security::initSession();
    Security::validateCsrf();
    Security::enforceRateLimit('convert', 20, 60);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido');
    }

    if (!isset($_FILES['cv_file']) || $_FILES['cv_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Error al subir el archivo');
    }

    $template = $_POST['template'] ?? '';
    if (!isset(TEMPLATES[$template])) {
        throw new Exception('Plantilla no válida');
    }

    $file = $_FILES['cv_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, ALLOWED_EXTENSIONS)) {
        throw new Exception("Formato no soportado: .$ext");
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        throw new Exception('Archivo demasiado grande');
    }

    // Validar MIME type real
    if (!Security::validateMimeType($file['tmp_name'], $ext)) {
        throw new Exception('El tipo de archivo no coincide con la extensión');
    }

    // Mover archivo subido
    $uploadFile = UPLOAD_PATH . uniqid('cv_') . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $uploadFile)) {
        throw new Exception('Error al guardar el archivo');
    }

    // PASO 1: Determinar cómo procesar el archivo
    $cvInput = TextExtractor::extract($uploadFile);

    $isDirectFile = str_starts_with($cvInput, '__FILE__:');

    // Validar texto extraído (solo si no es archivo directo)
    if (!$isDirectFile && strlen(trim($cvInput)) < 20) {
        throw new Exception('No se pudo extraer texto suficiente del archivo.');
    }

    // PASO 2: Parsear con Claude
    $parser = new CvParser();
    $parsedData = $parser->parse($cvInput);

    // PASO 3: Rellenar plantilla
    $filler = new TemplateFiller($template, $parsedData);
    $outputFile = $filler->fill();

    // Limpiar archivo subido
    @unlink($uploadFile);

    // PASO 4: Guardar en base de datos
    $outputFilename = basename($outputFile);
    $nombre = $parsedData['nombre']
        ?? $parsedData['datos_personales']['nombre']
        ?? pathinfo($file['name'], PATHINFO_FILENAME);

    try {
        Database::saveCv($nombre, $template, $outputFilename, $parsedData);
    } catch (Exception $dbErr) {
        // No bloquear la respuesta si falla el guardado en BD
    }

    // Respuesta
    echo json_encode([
        'success' => true,
        'filename' => $outputFilename,
        'download_url' => 'output/' . $outputFilename,
        'parsed_data' => $parsedData,
        'mode' => $isDirectFile ? 'direct_file' : 'text_extraction',
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
