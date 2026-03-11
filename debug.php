<?php
/**
 * CV Converter - Endpoint de DEBUG
 * Muestra: extraccion de texto, JSON del candidato, y mapa de sustituciones de Claude
 */

header('Content-Type: application/json; charset=utf-8');

try {
    require_once 'config.php';
    require_once 'lib/Security.php';
    require_once 'lib/TextExtractor.php';
    require_once 'lib/CvParser.php';
    require_once 'lib/TemplateFiller.php';

    Security::initSession();
    Security::validateCsrf();
    Security::enforceRateLimit('debug', 10, 60);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Metodo no permitido');
    }

    if (!isset($_FILES['cv_file']) || $_FILES['cv_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Error al subir el archivo');
    }

    $file = $_FILES['cv_file'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, ALLOWED_EXTENSIONS)) {
        throw new Exception("Formato no soportado: .$ext");
    }

    $uploadFile = UPLOAD_PATH . uniqid('dbg_') . '.' . $ext;
    move_uploaded_file($file['tmp_name'], $uploadFile);

    $templateKey = $_POST['template'] ?? array_key_first(TEMPLATES);

    // PASO 1: Extraer texto del CV
    $cvInput      = TextExtractor::extract($uploadFile);
    $isDirectFile = str_starts_with($cvInput, '__FILE__:');

    // PASO 2: Claude extrae el JSON del candidato
    $parsedData = null;
    $parseError = null;
    try {
        $parser     = new CvParser();
        $parsedData = $parser->parse($cvInput);
    } catch (Exception $e) {
        $parseError = $e->getMessage();
    }

    // PASO 3: Claude genera el mapa de sustituciones para la plantilla
    $substitutions    = null;
    $templateText     = null;
    $substitutionError = null;

    if ($parsedData) {
        try {
            $filler = new TemplateFiller($templateKey, $parsedData);

            // Acceder a metodos internos de debug via reflection
            $ref  = new ReflectionClass($filler);

            // Extraer XML de la plantilla
            $tplPath = TEMPLATE_PATH . TEMPLATES[$templateKey]['file'];
            $zip = new ZipArchive();
            $zip->open($tplPath);
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();

            // Llamar al metodo privado de extraccion de texto
            $extractMethod = $ref->getMethod('extractTemplateText');
            $extractMethod->setAccessible(true);
            $templateText = $extractMethod->invoke($filler, $xml);

            // Llamar al metodo privado de sustituciones
            $askMethod = $ref->getMethod('askClaudeForSubstitutions');
            $askMethod->setAccessible(true);
            $substitutions = $askMethod->invoke($filler, $templateText);

        } catch (Exception $e) {
            $substitutionError = $e->getMessage();
        }
    }

    // Generar archivo de debug
    $debug  = "=== CV CONVERTER - DEBUG ===\n";
    $debug .= "Fecha: " . date('Y-m-d H:i:s') . "\n";
    $debug .= "Archivo: " . $file['name'] . "\n";
    $debug .= "Tamano: " . round($file['size'] / 1024, 1) . " KB\n";
    $debug .= "Extension: $ext\n";
    $debug .= "Plantilla: $templateKey\n";
    $debug .= "Modo extraccion: " . ($isDirectFile ? "ARCHIVO DIRECTO (PDF/imagen)" : "TEXTO EXTRAIDO") . "\n";

    $debug .= "\n" . str_repeat('=', 60) . "\n";
    $debug .= "PASO 1: TEXTO EXTRAIDO / MODO\n";
    $debug .= str_repeat('=', 60) . "\n";
    if ($isDirectFile) {
        $debug .= "El archivo se envio DIRECTAMENTE a Claude (PDF con vision).\n";
    } else {
        $debug .= $cvInput . "\n";
    }

    $debug .= "\n" . str_repeat('=', 60) . "\n";
    $debug .= "PASO 2: JSON DEL CANDIDATO (extraccion Claude)\n";
    $debug .= str_repeat('=', 60) . "\n\n";
    if ($parseError) {
        $debug .= "ERROR: $parseError\n";
    } else {
        $debug .= json_encode($parsedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }

    $debug .= "\n" . str_repeat('=', 60) . "\n";
    $debug .= "PASO 3: ESTRUCTURA DE PLANTILLA ($templateKey)\n";
    $debug .= str_repeat('=', 60) . "\n\n";
    $debug .= $templateText ?? "No generado\n";

    $debug .= "\n" . str_repeat('=', 60) . "\n";
    $debug .= "PASO 4: SUSTITUCIONES GENERADAS POR CLAUDE\n";
    $debug .= str_repeat('=', 60) . "\n\n";
    if ($substitutionError) {
        $debug .= "ERROR: $substitutionError\n";
    } else {
        $debug .= json_encode($substitutions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }

    // Guardar debug
    $debugFile = OUTPUT_PATH . 'DEBUG_' . basename($file['name'], '.' . $ext) . '_' . date('Ymd_His') . '.txt';
    file_put_contents($debugFile, "\xEF\xBB\xBF" . $debug);

    @unlink($uploadFile);

    echo json_encode([
        'success'           => true,
        'debug_url'         => 'output/' . basename($debugFile),
        'debug_filename'    => basename($debugFile),
        'mode'              => $isDirectFile ? 'direct_file' : 'text_extraction',
        'parsed_data'       => $parsedData,
        'template_text'     => $templateText,
        'substitutions'     => $substitutions,
        'parse_error'       => $parseError,
        'substitution_error'=> $substitutionError,
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}
