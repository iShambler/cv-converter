<?php
/**
 * CV Converter - Script de instalación
 * Ejecutar una vez: http://localhost/cv-converter/setup.php
 */

echo "<h1>CV Converter - Setup</h1><pre>\n";

$sourceDir = 'C:\\Proyectos\\Curriculums\\';
$targetDir = __DIR__ . '\\templates\\';

// Crear directorios necesarios
$dirs = ['uploads', 'output', 'templates'];
foreach ($dirs as $dir) {
    $path = __DIR__ . DIRECTORY_SEPARATOR . $dir;
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
        echo "✅ Creado directorio: $dir\n";
    } else {
        echo "✔️  Directorio existe: $dir\n";
    }
}

// Mapeo de archivos origen -> destino
$files = [
    'Accenture 2025.docx' => 'Accenture_2025.docx',
    'Arelance.docx' => 'Arelance.docx',
    'EVIDEN.DOCX' => 'EVIDEN.docx',
    'Plantilla_ATOS.DOCX' => 'Plantilla_ATOS.docx',
    'Informe Ricoh- Nombre A. A. (plantilla CV).docx' => 'Ricoh.docx',
];

// Los .doc necesitan conversión manual
$needsConversion = [
    'Avanade.doc' => 'Avanade.docx',
    'Inetum.doc' => 'Inetum.docx',
];

echo "\n--- Copiando plantillas .docx ---\n";
foreach ($files as $source => $target) {
    $sourcePath = $sourceDir . $source;
    $targetPath = $targetDir . $target;
    
    if (file_exists($sourcePath)) {
        if (copy($sourcePath, $targetPath)) {
            echo "✅ $source → $target\n";
        } else {
            echo "❌ Error copiando: $source\n";
        }
    } else {
        echo "⚠️  No encontrado: $sourcePath\n";
    }
}

echo "\n--- Archivos .doc (necesitan conversión) ---\n";
foreach ($needsConversion as $source => $target) {
    $sourcePath = $sourceDir . $source;
    $targetPath = $targetDir . $target;
    
    if (file_exists($targetPath)) {
        echo "✔️  Ya existe: $target\n";
        continue;
    }
    
    if (file_exists($sourcePath)) {
        // Intentar copiar el .doc y avisar que necesita conversión
        // En XAMPP con LibreOffice instalado podríamos convertir automáticamente
        $docTarget = $targetDir . $source;
        copy($sourcePath, $docTarget);
        echo "⚠️  $source copiado pero necesita conversión a .docx\n";
        echo "    Abre $docTarget en Word y guarda como .docx → $target\n";
    } else {
        echo "⚠️  No encontrado: $sourcePath\n";
    }
}

// Verificar configuración
echo "\n--- Verificando configuración ---\n";
require_once 'config.php';

if (empty(ANTHROPIC_API_KEY)) {
    echo "API Key de Anthropic NO configurada. Edita config.php\n";
} else {
    echo "API Key de Anthropic configurada\n";
}

// Verificar extensiones PHP
$extensions = ['zip', 'curl', 'json', 'mbstring'];
foreach ($extensions as $ext) {
    if (extension_loaded($ext)) {
        echo "✅ PHP ext: $ext\n";
    } else {
        echo "❌ PHP ext faltante: $ext\n";
    }
}

// Verificar que se puede escribir
if (is_writable(__DIR__ . '/uploads')) {
    echo "✅ Directorio uploads escribible\n";
} else {
    echo "❌ Directorio uploads NO escribible\n";
}

if (is_writable(__DIR__ . '/output')) {
    echo "✅ Directorio output escribible\n";
} else {
    echo "❌ Directorio output NO escribible\n";
}

echo "\n--- Plantillas disponibles ---\n";
$templateDir = __DIR__ . '/templates/';
foreach (glob($templateDir . '*.docx') as $file) {
    $size = round(filesize($file) / 1024, 1);
    echo "📄 " . basename($file) . " ({$size} KB)\n";
}

echo "\n✅ Setup completado. Accede a: http://localhost/cv-converter/\n";
echo "</pre>";
