<?php
/**
 * CV Converter - TemplateFiller v13
 *
 * Mejoras v11:
 * - Las secciones EXPERIENCIA, FORMACIÓN y CONOCIMIENTOS se construyen
 *   directamente en PHP desde el JSON parseado — sin depender de Claude
 *   para el contenido de esas secciones.
 * - Claude solo decide las sustituciones de tabla (cell_after_label) y
 *   párrafos simples (paragraph_with_label / replace_placeholder).
 * - Fix: applySectionContent busca el siguiente título de forma más robusta.
 * - Fix: cleanResidualPlaceholders limpia también nbsp (\xc2\xa0).
 * - Logging detallado de aplicación (debug_apply_log.txt).
 * - API key desde .env / variable de entorno.
 */
class TemplateFiller
{
    private string $templatePath;
    private string $outputPath;
    private string $downloadName;
    private array  $cv;
    private string $templateKey;
    private string $apiKey;
    private string $model;
    private string $apiUrl;
    private array  $applyLog = [];

    public function __construct(string $templateKey, array $cvData)
    {
        $this->templateKey = $templateKey;
        $this->cv          = $cvData;
        $this->apiKey      = getenv('ANTHROPIC_API_KEY') ?: ANTHROPIC_API_KEY;
        $this->model       = ANTHROPIC_MODEL;
        $this->apiUrl      = ANTHROPIC_API_URL;

        if (!isset(TEMPLATES[$templateKey])) {
            throw new Exception("Plantilla no encontrada: $templateKey");
        }

        $this->templatePath = TEMPLATE_PATH . TEMPLATES[$templateKey]['file'];
        if (!file_exists($this->templatePath)) {
            throw new Exception("Archivo de plantilla no encontrado: " . $this->templatePath);
        }

        $nombre = $this->cv['datos_personales']['nombre_completo'] ?? 'candidato';
        $nombre = preg_replace('/[^a-zA-Z0-9áéíóúñÁÉÍÓÚÑ\s]/u', '', $nombre);
        $nombreFile = str_replace(' ', '_', trim($nombre));
        $this->outputPath = OUTPUT_PATH . "CV_{$nombreFile}_" . ucfirst($templateKey) . '_' . date('Ymd_His') . '.docx';

        // Nombre de descarga limpio: "Nombre Apellido_Plantilla.docx"
        $templateName = ucfirst($templateKey);
        $this->downloadName = trim($nombre) . '_' . $templateName . '.docx';
    }

    public function getDownloadName(): string
    {
        return $this->downloadName ?? basename($this->outputPath);
    }

    // =========================================================================
    // ENTRY POINT
    // =========================================================================

    public function fill(): string
    {
        copy($this->templatePath, $this->outputPath);
        $zip = new ZipArchive();
        if ($zip->open($this->outputPath) !== true) {
            throw new Exception("No se pudo abrir la plantilla DOCX");
        }
        $xml = $zip->getFromName('word/document.xml');

        // PASO 1: Claude decide sustituciones de tabla y párrafos simples
        $templateText  = $this->extractTemplateText($xml);
        $substitutions = $this->askClaudeForSubstitutions($templateText);
        $xml           = $this->applySubstitutions($xml, $substitutions);

        // PASO 2: PHP construye y aplica las secciones de contenido directamente
        $xml = $this->applyExperienciaSection($xml);
        $xml = $this->applyFormacionAcademicaSection($xml);
        $xml = $this->applyFormacionComplementariaSection($xml);
        $xml = $this->applyConocimientosTecnicosParas($xml);
        $xml = $this->applyIdiomasSection($xml);

        // PASO 2E: Campos específicos de Avanade (página 2 - Summary)
        if ($this->templateKey === 'avanade') {
            $xml = $this->applyAvanadeSummaryFields($xml);
        }

        // PASO 2F: Limpieza específica Ricoh — celda "Datos personales:" sin placeholder
        if ($this->templateKey === 'ricoh') {
            $xml = $this->cleanRicohDatosPersonales($xml);
        }

        // PASO 3: Limpiar placeholders residuales
        $xml = $this->cleanResidualPlaceholders($xml);

        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        $this->saveLog($substitutions);
        return $this->outputPath;
    }

    // =========================================================================
    // PASO 1A: Extraer estructura de texto de la plantilla (para Claude)
    // =========================================================================

    private function extractTemplateText(string $xml): string
    {
        $items = [];
        preg_match_all('/<w:tbl\b[^>]*>.*?<\/w:tbl>/s', $xml, $tm, PREG_OFFSET_CAPTURE);

        preg_match_all('/<w:tr\b[^>]*>((?:(?!<\/w:tr>).)*?)<\/w:tr>/s', $xml, $rowMatches, PREG_OFFSET_CAPTURE);
        foreach ($rowMatches[0] as $idx => $rowMatch) {
            $rowXml = $rowMatch[0];
            $rowOff = $rowMatch[1];
            preg_match_all('/<w:tc\b[^>]*>((?:(?!<\/w:tc>).)*?)<\/w:tc>/s', $rowXml, $cells);
            $cellTexts = [];
            foreach ($cells[0] as $cell) {
                preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', $cell, $ct);
                $cellTexts[] = trim(implode('', $ct[1]));
            }
            $rowText = implode(' | ', $cellTexts);
            if (trim($rowText, ' |') !== '') {
                $items[$rowOff . '_r' . $idx] = '[FILA] ' . $rowText;
            }
        }

        preg_match_all('/<w:p\b[^>]*>.*?<\/w:p>/s', $xml, $pm, PREG_OFFSET_CAPTURE);
        foreach ($pm[0] as $idx => $pMatch) {
            $paraOff = $pMatch[1];
            $inTable = false;
            foreach ($tm[0] as $tMatch) {
                if ($paraOff > $tMatch[1] && $paraOff < $tMatch[1] + strlen($tMatch[0])) {
                    $inTable = true;
                    break;
                }
            }
            if (!$inTable) {
                preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', $pMatch[0], $pt);
                $t = trim(implode('', $pt[1]));
                if ($t !== '') {
                    $items[$paraOff . '_p' . $idx] = '[PARRAFO] ' . $t;
                }
            }
        }

        ksort($items, SORT_NATURAL);
        return implode("\n", $items);
    }

    // =========================================================================
    // PASO 1B: Pedir a Claude sustituciones de tabla y párrafos simples
    // =========================================================================

    private function askClaudeForSubstitutions(string $templateText): array
    {
        $dp           = $this->cv['datos_personales'] ?? [];
        $idiomas      = $this->cv['idiomas'] ?? [];
        $expTotal     = $this->calcExperiencia();
        $templateName = TEMPLATES[$this->templateKey]['name'] ?? $this->templateKey;

        // Construir resumen COMPLETO de datos para Claude
        $dataResumen = [
            'nombre_completo'             => $dp['nombre_completo']             ?? '',
            'email'                       => $dp['email']                       ?? '',
            'telefono'                    => $dp['telefono']                    ?? '',
            'residencia'                  => $dp['residencia']                  ?? '',
            'fecha_nacimiento'            => $dp['fecha_nacimiento']            ?? '',
            'nacionalidad'                => $dp['nacionalidad']                ?? '',
            'dni'                         => $dp['dni']                         ?? '',
            'linkedin'                    => $dp['linkedin']                    ?? '',
            'disponibilidad_incorporacion'=> $dp['disponibilidad_incorporacion']?? '',
            'disponibilidad_entrevista'   => $dp['disponibilidad_entrevista']   ?? '',
            'situacion_laboral'           => $dp['situacion_laboral']           ?? '',
            'exp_total'                   => $expTotal,
            'perfil_profesional'          => $this->cv['perfil_profesional']    ?? '',
            'idiomas'                     => $idiomas,
            'conocimientos_tecnicos'      => $this->cv['conocimientos_tecnicos'] ?? [],
            'certificaciones'             => $this->cv['certificaciones']       ?? [],
            'soft_skills'                 => $this->cv['soft_skills']           ?? '',
            'fecha_actualizacion'         => date('d/m/Y'),
        ];

        $dataJson = json_encode($dataResumen, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $systemPrompt = <<<'SYSTEM'
Eres un experto en relleno de plantillas de CV corporativas.
Tu misión: INTERPRETAR cada campo de la plantilla y decidir qué datos del candidato encajan, aunque los nombres no coincidan exactamente.

SECCIONES QUE NO DEBES TOCAR (las gestiona otro proceso automático):
- Títulos de sección como EXPERIENCIA, FORMACIÓN, CONOCIMIENTOS, COMPETENCIAS TÉCNICAS, IDIOMAS, ENTORNOS y sus equivalentes en inglés (Experience, Training, Education, Technical Skills).
- "Entornos/conocimientos técnicos" y variantes — NO usar cell_after_label ni ninguna sustitución sobre este título.
- Los párrafos placeholder DENTRO de esas secciones (ej: "Mes año – mes año", "Empresa", "Categoria", "Funciones:", "Entorno:", "Cliente:").
- Esos se rellenan automáticamente — si los incluyes, se duplicarán.
- EXCEPCIÓN: SÍ puedes rellenar celdas de tabla del cuadro de control/evaluación que contengan datos de idiomas (columna "Nivel"), experiencia, etc. Solo NO toques la sección de contenido libre de idiomas.
- AVANADE: SÍ debes rellenar los párrafos del Summary (página 2): "Nombre" con nombre completo, "Madrid" con residencia, "Nivel de inglés:" con el nivel, disponibilidad entrevista/incorporación. Usa full_paragraph para estos campos.

TODO LO DEMÁS: RELLÉNALO. Interpreta creativamente.

TIPOS DE SUSTITUCIÓN:

1. "cell_after_label" — celda de tabla: valor a la derecha del label
   { "type": "cell_after_label", "label": "texto EXACTO del label en la celda izquierda", "value": "valor" }

2. "paragraph_with_label" — párrafo que contiene un label seguido de placeholder
   { "type": "paragraph_with_label", "label": "fragmento único del label", "placeholder": "texto EXACTO a reemplazar", "value": "valor nuevo" }

3. "full_paragraph" — reemplaza TODO el texto de un párrafo completo (útil para campos compuestos)
   { "type": "full_paragraph", "original": "texto COMPLETO actual del párrafo", "value": "texto nuevo completo" }

REGLAS DE INTERPRETACIÓN:
- INTERPRETA los campos: si ves "Ubicacion, año nacimiento XXXX." y tienes residencia="Málaga" y fecha_nacimiento="15/03/1995", genera "Málaga, año nacimiento 1995."
- Si un párrafo tiene VARIOS datos mezclados, usa "full_paragraph" y construye el texto completo manteniendo la estructura del párrafo original. Ejemplo: "Ubicacion, año nacimiento XXXX." → "Málaga, año nacimiento XXXX." (si solo tienes residencia pero no fecha).
- Para idiomas en párrafos tipo "Inglés: Técnico." usa "full_paragraph" con el nivel real.
- exp_total: úsalo para campos de experiencia total/años de experiencia.
- fecha_actualizacion: úsalo para campos de "Fecha actualización", "Fecha de actualización" o similares.
- Para campos de tabla (FILA), usa "cell_after_label".
- Para párrafos simples, usa "paragraph_with_label" o "full_paragraph" según convenga.

PROHIBICIONES ABSOLUTAS:
- Si el dato está vacío, no existe, o no tienes información → NO incluir esa sustitución. DÉJALO COMO ESTÁ.
- NUNCA inventes valores. NUNCA pongas "No especificado", "No disponible", "N/A", "Sin datos", "texto", "XXXX", ni NINGÚN valor genérico o inventado.
- NUNCA borres información que ya tiene la plantilla si no tienes un dato real para reemplazarla.
- NO RELLENAR: ISLAS CANARIAS, Prueba realizada SI/NO, Soft Skills, Valoracion Final, Otras habilidades, Cumple este candidato, Recomendaría (esos son campos de evaluación interna).
- PLANTILLA ATOS: NO tocar la tabla de IDIOMAS (LECTURA, ESCRITURA, EXPRESIÓN ORAL) — la gestiona PHP automáticamente.
- SÍ RELLENAR: En tablas del cuadro de control (Accenture/Arelance), si hay una fila de Idiomas con columna "Nivel", rellena el nivel del idioma (ej: "Avanzado", "B2", etc.) usando cell_after_label. También rellena "Experiencia" en tecnología principal si hay datos.
- IMPORTANTE: El campo "Experiencia (meses/años) en tecnología principal" debe rellenarse con exp_total. NO ponerlo en "otras tecnologías". El label para cell_after_label es "Experiencia (meses/años) en tecnología principal".

REGLAS CRÍTICAS PARA RICOH (cabecera y datos personales):
- "Datos personales" o "Datos personales:" es ÚNICAMENTE el título de una cabecera de sección que ya tiene su formato y su contenido en la plantilla. NO TOQUES esa celda bajo ningún concepto. PROHIBIDO generar cualquier sustitución (cell_after_label, paragraph_with_label, full_paragraph) que tenga "Datos personales" en label, original o cualquier campo. Debe quedar literalmente "Datos personales:" sin modificación alguna.
- El nombre del candidato va exclusivamente en el label "Nombre" (usa cell_after_label con label="Nombre"). Si en la plantilla NO existe un label "Nombre" separado, NO inventes uno y NO pongas el nombre en otro sitio que se le parezca.
- En la cabecera superior tipo "Nombre puesto - ESPOR00" (o similar): la palabra "Nombre" es un PLACEHOLDER que debes SUSTITUIR por el nombre completo del candidato dentro del mismo párrafo, manteniendo el resto del texto intacto. Usa "full_paragraph" con original="Nombre puesto - ESPOR00" y value="[Nombre del candidato] puesto - ESPOR00" (preservando exactamente el resto del texto del párrafo original). NUNCA añadas el nombre AL FINAL del párrafo ni después del código ESPOR00 — debe REEMPLAZAR la palabra "Nombre" en su posición original. Esta regla es SOLO para el párrafo de cabecera "Nombre puesto", NO para "Datos personales".

Responde SOLO con JSON válido. Sin markdown. Sin comentarios.
{"substitutions": [...]}
SYSTEM;

        $userPrompt = "PLANTILLA: {$templateName}\n\nESTRUCTURA:\n---\n{$templateText}\n---\n\nDATOS:\n---\n{$dataJson}\n---";

        $payload = json_encode([
            'model'      => $this->model,
            'max_tokens' => 4096,
            'system'     => $systemPrompt,
            'messages'   => [['role' => 'user', 'content' => $userPrompt]],
        ]);

        $response = $this->doRequest($payload);
        file_put_contents(OUTPUT_PATH . 'debug_claude_response.txt', $response);
        return $this->parseSubstitutions($response);
    }

    // =========================================================================
    // PASO 2A: Construir y aplicar EXPERIENCIA LABORAL desde el JSON
    // =========================================================================

    private function applyExperienciaSection(string $xml): string
    {
        $experiencias = $this->cv['experiencia_laboral'] ?? [];
        if (empty($experiencias)) return $xml;

        $lines = [];
        $tpl = $this->templateKey;

        foreach ($experiencias as $exp) {
            $inicio   = trim($exp['fecha_inicio']        ?? '');
            $fin      = trim($exp['fecha_fin']           ?? '');
            $empresa  = trim($exp['empresa']             ?? '');
            $cargo    = trim($exp['cargo']               ?? '');
            $cat      = trim($exp['categoria']           ?? '');
            $cliente  = trim($exp['cliente']             ?? '');
            $func     = trim($exp['funciones']           ?? '');
            $entorno  = trim($exp['entorno_tecnologico'] ?? '');

            $fechaStr   = $inicio . ($fin ? ' - ' . $fin : '');
            $cargoFinal = $cargo ?: $cat;

            // Solo Ricoh conserva los markers **...** de palabras clave en funciones
            if ($tpl !== 'ricoh') {
                $func = $this->stripBoldMarkers($func);
            }

            if ($tpl === 'atos') {
                // Atos: fecha+empresa+cargo en una línea, funciones con bullets, Tecnologías:
                $fechaIni = $this->fechaExpToSpanishText($inicio);
                $fechaFin = $fin ? $this->fechaExpToSpanishText($fin) : '';
                $fechaDisplay = $fechaIni . ($fechaFin ? ' – ' . $fechaFin : '');

                $headerParts = array_filter([$fechaDisplay, $empresa, $cargoFinal]);
                if (!empty($headerParts)) $lines[] = implode('/ ', $headerParts);

                if ($func) {
                    $lines[] = '';
                    $bullets = $this->splitFuncionesIntoBullets($func);
                    foreach ($bullets as $b) $lines[] = '• ' . $b;
                }
                if ($entorno) {
                    $lines[] = '';
                    $lines[] = 'Tecnologías: ' . $this->normalizeEntornoCommas($entorno);
                }
            } elseif ($tpl === 'inetum') {
                // Inetum: líneas separadas, funciones con guiones
                if ($fechaStr)   $lines[] = $fechaStr;
                if ($empresa)    $lines[] = $empresa;
                if ($cargoFinal) $lines[] = $cargoFinal;
                if ($entorno)    $lines[] = 'Entorno: ' . $this->normalizeEntornoCommas($entorno);
                if ($cliente)    $lines[] = 'Cliente: ' . $cliente;
                if ($func) {
                    $lines[] = 'Funciones:';
                    $bullets = $this->splitFuncionesIntoBullets($func);
                    foreach ($bullets as $b) $lines[] = '- ' . $b;
                }
            } elseif ($tpl === 'ricoh') {
                // Ricoh: fechas con mes escrito, sin labels, empresa y rol negrita, bullets con keywords negrita
                $fechaIni = $this->fechaExpToSpanishText($inicio);
                $fechaFin = $fin ? $this->fechaExpToSpanishText($fin) : '';
                $fechaDisplay = $fechaIni . ($fechaFin ? ' – ' . $fechaFin : '');

                if ($fechaDisplay) $lines[] = $fechaDisplay;
                if ($empresa)      $lines[] = '[BOLD]' . $this->toTitleCase($empresa);
                if ($cargoFinal)   $lines[] = '[BOLD]' . $this->toTitleCase($cargoFinal);
                if ($func) {
                    $lines[] = 'Funciones:';
                    $lines[] = '';
                    $bullets = $this->splitFuncionesIntoBullets($func);
                    foreach ($bullets as $b) $lines[] = '• ' . $b;
                }
            } elseif ($tpl === 'avanade') {
                // Avanade: fechas con mes escrito, sin labels, rol en negrita, bullets
                $fechaIni = $this->fechaExpToSpanishText($inicio);
                $fechaFin = $fin ? $this->fechaExpToSpanishText($fin) : '';
                $fechaDisplay = $fechaIni . ($fechaFin ? ' – ' . $fechaFin : '');

                if ($fechaDisplay) $lines[] = $fechaDisplay;
                if ($empresa)      $lines[] = '[BOLD]' . $this->toTitleCase($empresa);
                if ($cargoFinal)   $lines[] = '[BOLD]' . $this->toTitleCase($cargoFinal);
                if ($func) {
                    $lines[] = 'Funciones:';
                    $bullets = $this->splitFuncionesIntoBullets($func);
                    foreach ($bullets as $b) $lines[] = '• ' . $b;
                }
                if ($entorno) {
                    $lines[] = 'Herramientas: ' . $this->normalizeEntornoCommas($entorno);
                }
            } elseif (in_array($tpl, ['accenture', 'arelance'])) {
                // Accenture/Arelance: fechas con mes escrito, sin labels, rol en azul, bullets
                $fechaIni = $this->fechaExpToSpanishText($inicio);
                $fechaFin = $fin ? $this->fechaExpToSpanishText($fin) : '';
                $fechaDisplay = $fechaIni . ($fechaFin ? ' – ' . $fechaFin : '');

                if ($fechaDisplay) $lines[] = $fechaDisplay;
                if ($empresa)      $lines[] = '[BOLD]' . $this->toTitleCase($empresa);
                if ($cargoFinal)   $lines[] = '[ROLE_BLUE]' . $this->toTitleCase($cargoFinal);
                if ($func) {
                    $lines[] = 'Funciones:';
                    $bullets = $this->splitFuncionesIntoBullets($func);
                    foreach ($bullets as $b) $lines[] = '• ' . $b;
                }
                if ($entorno) $lines[] = 'Entorno: ' . $this->normalizeEntornoCommas($entorno);
            } else {
                // Formato genérico
                if ($fechaStr)   $lines[] = $fechaStr;
                if ($empresa)    $lines[] = 'Empresa: ' . $empresa;
                if ($cargoFinal) $lines[] = 'Rol/Categoría: ' . $cargoFinal;
                if ($cliente)    $lines[] = 'Cliente: ' . $cliente;
                if ($func) {
                    $lines[] = 'Funciones:';
                    $lines[] = $func;
                }
                if ($entorno) {
                    $lines[] = 'Entorno: ' . $this->normalizeEntornoCommas($entorno);
                }
            }

            $lines[] = ''; // separador entre experiencias
        }

        // Quitar último separador vacío
        while (!empty($lines) && end($lines) === '') array_pop($lines);

        // Avanade/Ricoh: añadir separación inicial tras la línea del título
        if (in_array($tpl, ['avanade', 'ricoh'])) array_unshift($lines, '');

        $content = implode("\n", $lines);
        $xml = $this->applySectionContent($xml, 'EXPERIENCIA LABORAL', $content);
        $xml = $this->applySectionContent($xml, 'EXPERIENCIA PROFESIONAL', $content);
        $xml = $this->applySectionContent($xml, 'TRAYECTORIA PROFESIONAL', $content);
        $xml = $this->applySectionContent($xml, 'TRAYECTORIA', $content);
        $xml = $this->applySectionContent($xml, 'Experience', $content);
        return $xml;
    }

    /**
     * Divide el texto de funciones en items individuales.
     * Separa por ". " (oraciones), ";" o saltos de línea.
     */
    private function splitFuncionesIntoBullets(string $funciones): array
    {
        // Primero intentar por saltos de línea
        if (str_contains($funciones, "\n")) {
            $items = explode("\n", $funciones);
        } else {
            // Separar por ". " preservando abreviaciones comunes
            $items = preg_split('/\.\s+(?=[A-ZÁÉÍÓÚÑ])/', $funciones);
        }

        $result = [];
        foreach ($items as $item) {
            $item = trim($item, " .\t\n\r");
            if ($item !== '') {
                // Capitalizar primera letra
                $result[] = mb_strtoupper(mb_substr($item, 0, 1, 'UTF-8'), 'UTF-8')
                          . mb_substr($item, 1, null, 'UTF-8');
            }
        }
        return $result;
    }

    /**
     * Asegura que los skills del entorno estén separados por comas.
     * Si ya tienen comas, los deja. Si no, intenta separarlos inteligentemente.
     */
    private function normalizeEntornoCommas(string $entorno): string
    {
        $entorno = trim($entorno);
        if (empty($entorno)) return '';

        // Si ya contiene comas, asumir que está bien formateado
        if (str_contains($entorno, ',')) {
            // Limpiar posibles espacios extras alrededor de las comas
            return preg_replace('/\s*,\s*/', ', ', $entorno);
        }

        // Sin comas: puede ser skills separados por saltos de línea o solo espacios
        if (str_contains($entorno, "\n")) {
            $parts = array_filter(array_map('trim', explode("\n", $entorno)));
            return implode(', ', $parts);
        }

        // Solo espacios — devolver tal cual (Claude debería haberlo separado con comas)
        return $entorno;
    }

    // =========================================================================
    // PASO 2B: Construir y aplicar FORMACIÓN ACADÉMICA desde el JSON
    // =========================================================================

    private function applyFormacionAcademicaSection(string $xml): string
    {
        $items = $this->cv['formacion_academica'] ?? [];
        if (empty($items)) return $xml;

        $tpl = $this->templateKey;
        $isCompactStyle = in_array($tpl, ['arelance', 'accenture', 'avanade']);

        $lines = [];
        foreach ($items as $item) {
            $fecha  = trim($item['fecha']  ?? '');
            $titulo = trim($item['titulo'] ?? '');
            $centro = trim($item['centro'] ?? '');

            if ($isCompactStyle) {
                // Formato compacto: Año + "Título. Centro" en una línea
                $year = $this->extractYear($fecha);
                if ($year) $lines[] = $year;
                $tituloLine = $titulo;
                if ($centro) $tituloLine .= '. ' . $centro;
                if ($tituloLine) $lines[] = $tituloLine;
            } else {
                if ($fecha)  $lines[] = $fecha;
                if ($titulo) $lines[] = $titulo;
                if ($centro) $lines[] = $centro;
            }
            $lines[] = '';
        }
        while (!empty($lines) && end($lines) === '') array_pop($lines);

        $content = implode("\n", $lines);

        // Ricoh: inyectar en tabla-contenido vacía después del título
        if ($this->templateKey === 'ricoh') {
            $xml = $this->applyRicohContentTable($xml, 'Formación', $content);
            return $xml;
        }

        // Intentar con y sin tilde, y en inglés
        $xml = $this->applySectionContent($xml, 'FORMACION ACADEMICA', $content);
        $xml = $this->applySectionContent($xml, 'FORMACIÓN ACADÉMICA', $content);
        $xml = $this->applySectionContent($xml, 'Formación', $content);
        $xml = $this->applySectionContent($xml, 'Education', $content);
        return $xml;
    }

    // =========================================================================
    // PASO 2C: Construir y aplicar FORMACIÓN COMPLEMENTARIA desde el JSON
    // =========================================================================

    private function applyFormacionComplementariaSection(string $xml): string
    {
        $items = $this->cv['formacion_complementaria'] ?? [];
        if (empty($items)) return $xml;

        $tpl = $this->templateKey;
        $isCompactStyle = in_array($tpl, ['arelance', 'accenture', 'avanade']);

        $lines = [];
        foreach ($items as $item) {
            $fecha  = trim($item['fecha']  ?? '');
            $titulo = trim($item['titulo'] ?? '');
            $centro = trim($item['centro'] ?? '');
            $horas  = trim($item['horas']  ?? '');

            if ($isCompactStyle) {
                $year = $this->extractYear($fecha);
                if ($year) $lines[] = $year;
                $tituloLine = $titulo;
                if ($centro) $tituloLine .= '. ' . $centro;
                if ($tituloLine) $lines[] = $tituloLine;
            } else {
                if ($fecha)  $lines[] = $fecha;
                if ($titulo) $lines[] = $titulo;
                if ($centro) $lines[] = $centro;
                if ($horas)  $lines[] = 'Horas: ' . $horas;
            }
            $lines[] = '';
        }
        while (!empty($lines) && end($lines) === '') array_pop($lines);

        $content = implode("\n", $lines);

        $xml = $this->applySectionContent($xml, 'FORMACION COMPLEMENTARIA', $content);
        $xml = $this->applySectionContent($xml, 'FORMACIÓN COMPLEMENTARIA', $content);
        $xml = $this->applySectionContent($xml, 'Training', $content);
        return $xml;
    }

    // =========================================================================
    // PASO 2D: Rellenar párrafos de CONOCIMIENTOS TÉCNICOS directamente
    // =========================================================================

    private function applyConocimientosTecnicosParas(string $xml): string
    {
        $kt = $this->cv['conocimientos_tecnicos'] ?? [];

        $lenguajes = trim($kt['lenguajes_programacion'] ?? '');
        $ssoo      = trim($kt['sistemas_operativos']    ?? '');
        $bbdd      = trim($kt['bases_datos']            ?? '');

        // Otros = frameworks + cloud + otros, todo junto si existen
        $otrosParts = array_filter([
            trim($kt['frameworks'] ?? ''),
            trim($kt['cloud']      ?? ''),
            trim($kt['otros']      ?? ''),
        ]);
        $otros = implode(', ', $otrosParts);

        if ($lenguajes) {
            $xml = $this->applyParagraphWithLabel($xml, 'Lenguajes de programaci', 'texto, texto', $lenguajes);
        }
        if ($ssoo) {
            $xml = $this->applyParagraphWithLabel($xml, 'Sistemas operativos', 'texto, texto', $ssoo);
        }
        if ($bbdd) {
            $xml = $this->applyParagraphWithLabel($xml, 'Bases de datos', 'texto, texto', $bbdd);
        }
        if ($otros) {
            $xml = $this->applyParagraphWithLabel($xml, 'Otros conocimientos', 'texto, texto', $otros);
        }

        // Fallback: rellenar como sección para plantillas sin párrafos con label
        $allParts = array_filter([$lenguajes, $ssoo, $bbdd, $otros]);
        if (!empty($allParts)) {
            $sectionContent = implode("\n", $allParts);

            // Inetum: COMPETENCIAS TÉCNICAS tiene una tabla placeholder después del título
            if ($this->templateKey === 'inetum') {
                $xml = $this->applySectionContentIntoTable($xml, 'COMPETENCIAS TÉCNICAS', $sectionContent);
            } elseif ($this->templateKey === 'ricoh') {
                // Ricoh: inyectar en la tabla-contenido vacía que sigue al título
                $xml = $this->applyRicohContentTable($xml, 'Entornos/conocimientos', $sectionContent);
            } elseif ($this->templateKey === 'avanade') {
                // Avanade: Technical Skills y Microsoft Specific Skill, ambos en negrita
                // Separar Microsoft skills si existen
                $msSkills = '';
                $otherSkills = "\n" . $sectionContent; // línea vacía inicial para separación del título
                // Por ahora todo va a Technical Skills; Microsoft Specific se deja vacío si no hay datos específicos
                $xml = $this->applySectionContent($xml, 'Technical Skills', $otherSkills, true);
                if ($msSkills) {
                    $xml = $this->applySectionContent($xml, 'Microsoft Specific Skill', $msSkills, true);
                }
            } else {
                // Arelance/Accenture: conocimientos en negrita según Comentarios
                $boldKnowledge = in_array($this->templateKey, ['arelance', 'accenture']);
                $xml = $this->applySectionContent($xml, 'CONOCIMIENTOS TECNICOS', $sectionContent, $boldKnowledge);
                $xml = $this->applySectionContent($xml, 'COMPETENCIAS TECNICAS', $sectionContent, $boldKnowledge);
                $xml = $this->applySectionContent($xml, 'COMPETENCIAS TÉCNICAS', $sectionContent, $boldKnowledge);
                $xml = $this->applySectionContent($xml, 'Entornos/conocimientos técnicos', $sectionContent);
                $xml = $this->applySectionContent($xml, 'Technical Skills', $sectionContent);
            }
        }

        return $xml;
    }

    /**
     * Extrae solo el año de una fecha o rango de fechas.
     * "08/2018 - 10/2022" → "2022" (año final)
     * "08/2018" → "2018"
     * "2022" → "2022"
     */
    private function extractYear(string $date): string
    {
        $date = trim($date);
        if (empty($date)) return '';

        // Si es rango, tomar el año final
        if (str_contains($date, ' - ')) {
            $parts = explode(' - ', $date);
            $date = trim(end($parts));
        }

        // Extraer año de 4 dígitos
        if (preg_match('/(\d{4})/', $date, $m)) {
            return $m[1];
        }
        return $date;
    }

    // =========================================================================
    // PASO 2F: Construir y aplicar IDIOMAS desde el JSON
    // =========================================================================

    private function applyIdiomasSection(string $xml): string
    {
        $idiomas = $this->cv['idiomas'] ?? [];
        if (empty($idiomas)) return $xml;

        // Avanade: el nivel de inglés ya va en Summary, no necesita sección IDIOMAS.
        if ($this->templateKey === 'avanade') return $xml;

        // Atos: idiomas van en tabla columnar (LECTURA/ESCRITURA/EXPRESIÓN ORAL)
        if ($this->templateKey === 'atos') {
            return $this->applyAtosIdiomasTable($xml);
        }

        // Ricoh: idiomas van en tabla con columnas Hablado/Escrito/Leído
        if ($this->templateKey === 'ricoh') {
            return $this->applyRicohIdiomasTable($xml);
        }

        $lines = [];
        foreach ($idiomas as $idioma) {
            $nombre = trim($idioma['idioma'] ?? '');
            $nivel  = trim($idioma['nivel_general'] ?? '');
            if (empty($nombre)) continue;

            // Capitalizar nombre del idioma
            $nombre = mb_strtoupper(mb_substr($nombre, 0, 1, 'UTF-8'), 'UTF-8')
                    . mb_substr($nombre, 1, null, 'UTF-8');

            if ($nivel) {
                $lines[] = $nombre . ': ' . $nivel;
            } else {
                $lines[] = $nombre;
            }
        }

        if (empty($lines)) return $xml;

        $content = implode("\n", $lines);

        // Buscar la sección IDIOMAS fuera de tablas para evitar confundir con
        // "Idiomas" en la tabla del cuadro de control
        $xml = $this->applySectionContentOutsideTable($xml, 'IDIOMAS', $content);
        return $xml;
    }

    /**
     * Rellena la tabla de idiomas de Atos.
     * Estructura: fila header [vacio|LECTURA|ESCRITURA|EXPRESIÓN ORAL]
     *             fila datos  [idioma|nivel|nivel|nivel]
     * Busca la fila con "LECTURA" y rellena la fila siguiente.
     */
    private function applyAtosIdiomasTable(string $xml): string
    {
        $idiomas = $this->cv['idiomas'] ?? [];
        if (empty($idiomas)) return $xml;

        // Ordenar: idiomas no nativos primero (inglés, francés, etc.), nativos al final
        usort($idiomas, function ($a, $b) {
            $nivelA = mb_strtolower(trim($a['nivel_general'] ?? ''), 'UTF-8');
            $nivelB = mb_strtolower(trim($b['nivel_general'] ?? ''), 'UTF-8');
            $aIsNative = in_array($nivelA, ['nativo', 'native', 'lengua materna', 'mother tongue']);
            $bIsNative = in_array($nivelB, ['nativo', 'native', 'lengua materna', 'mother tongue']);
            if ($aIsNative && !$bIsNative) return 1;
            if (!$aIsNative && $bIsNative) return -1;
            return 0;
        });

        // Buscar la fila que contiene "LECTURA" (header de la tabla de idiomas)
        preg_match_all('/<w:tr\b[^>]*>((?:(?!<\/w:tr>).)*?)<\/w:tr>/s', $xml, $rowMatches, PREG_OFFSET_CAPTURE);

        $headerRowIdx = -1;
        foreach ($rowMatches[0] as $idx => $rowMatch) {
            if (stripos($rowMatch[0], 'LECTURA') !== false) {
                $headerRowIdx = $idx;
                break;
            }
        }

        if ($headerRowIdx === -1) return $xml;

        // La fila de datos es la siguiente al header
        $dataRowIdx = $headerRowIdx + 1;
        if (!isset($rowMatches[0][$dataRowIdx])) return $xml;

        $templateRowXml = $rowMatches[0][$dataRowIdx][0];
        $insertOffset   = $rowMatches[0][$dataRowIdx][1];

        // Construir todas las filas de idiomas
        $allRowsXml = '';
        foreach ($idiomas as $idioma) {
            $nombre  = trim($idioma['idioma'] ?? '');
            $nivel   = trim($idioma['nivel_general'] ?? '');
            $lectura = trim($idioma['lectura'] ?? '') ?: $nivel;
            $escrit  = trim($idioma['escritura'] ?? '') ?: $nivel;
            $oral    = trim($idioma['expresion_oral'] ?? '') ?: $nivel;

            if (empty($nombre) || empty($nivel)) continue;

            // Clonar la fila template y rellenar celdas
            $rowXml = $templateRowXml;
            preg_match_all('/(<w:tc\b[^>]*>(?:(?!<\/w:tc>).)*?<\/w:tc>)/s', $rowXml, $cells);
            if (count($cells[1]) < 4) continue;

            $values = [
                htmlspecialchars($nombre, ENT_XML1, 'UTF-8'),
                htmlspecialchars($lectura, ENT_XML1, 'UTF-8'),
                htmlspecialchars($escrit, ENT_XML1, 'UTF-8'),
                htmlspecialchars($oral, ENT_XML1, 'UTF-8'),
            ];

            foreach ($cells[1] as $i => $cell) {
                if ($i >= 4) break;
                $newCell = $this->injectInCell($cell, $values[$i]);
                $rowXml  = str_replace($cell, $newCell, $rowXml);
            }
            $allRowsXml .= $rowXml;
        }

        if (empty($allRowsXml)) return $xml;

        // Reemplazar la fila template vacía con todas las filas de idiomas
        return substr_replace($xml, $allRowsXml, $insertOffset, strlen($templateRowXml));
    }

    /**
     * Ricoh: Busca una tabla cuyo texto contenga $titleFragment, y luego inyecta
     * contenido en la SIGUIENTE tabla (la tabla-contenido vacía).
     * Patrón Ricoh: [tabla-título] → [párrafo vacío] → [tabla-contenido vacía]
     */
    private function applyRicohContentTable(string $xml, string $titleFragment, string $content): string
    {
        if (empty($content)) return $xml;

        $normalize = [$this, 'normalizeForMatch'];
        $titleNorm = $normalize($titleFragment);

        // Encontrar TODAS las tablas
        preg_match_all('~<w:tbl\b[^>]*>.*?</w:tbl>~s', $xml, $allTables, PREG_OFFSET_CAPTURE);

        // Buscar la tabla cuyo texto normalizado contenga el fragmento del título
        $titleTableIdx = -1;
        foreach ($allTables[0] as $idx => $tbl) {
            preg_match_all('~<w:t[^>]*>([^<]*)</w:t>~', $tbl[0], $texts);
            $tableText = $normalize(implode('', $texts[1]));
            if (str_contains($tableText, $titleNorm)) {
                $titleTableIdx = $idx;
                break;
            }
        }

        if ($titleTableIdx === -1) return $xml;

        // La tabla-contenido es la SIGUIENTE
        $contentTableIdx = $titleTableIdx + 1;
        if (!isset($allTables[0][$contentTableIdx])) return $xml;

        $contentTable  = $allTables[0][$contentTableIdx][0];
        $contentOffset = $allTables[0][$contentTableIdx][1];

        // Generar XML del contenido
        $contentXml = $this->textToParaXml($content);

        // Inyectar en la primera celda de la tabla-contenido
        $newTable = preg_replace(
            '~(<w:tc\b[^>]*>(?:\s*<w:tcPr>.*?</w:tcPr>)?).*?(</w:tc>)~s',
            '$1' . $contentXml . '$2',
            $contentTable,
            1
        );

        return substr_replace($xml, $newTable, $contentOffset, strlen($contentTable));
    }

    /**
     * Rellena la tabla de idiomas de Ricoh.
     * Estructura: Row 0: [vacio|Hablado|Escrito|Leído], Row 1: [Español|...|...|...], Row 2: [Inglés|...|...|...]
     * Busca filas con "Español"/"Inglés" y rellena las celdas de nivel.
     */
    private function applyRicohIdiomasTable(string $xml): string
    {
        $idiomas = $this->cv['idiomas'] ?? [];
        if (empty($idiomas)) return $xml;

        // Construir mapa: nombre idioma → niveles
        $idiomaMap = [];
        foreach ($idiomas as $idioma) {
            $nombre = mb_strtolower(trim($idioma['idioma'] ?? ''), 'UTF-8');
            $nivel  = trim($idioma['nivel_general'] ?? '');
            if (empty($nombre) || empty($nivel)) continue;
            $idiomaMap[$nombre] = [
                'hablado' => trim($idioma['expresion_oral'] ?? '') ?: $nivel,
                'escrito' => trim($idioma['escritura'] ?? '') ?: $nivel,
                'leido'   => trim($idioma['lectura'] ?? '') ?: $nivel,
            ];
        }

        // Buscar filas de la tabla que contengan "Español" o "Inglés" y rellenar
        preg_match_all('/<w:tr\b[^>]*>((?:(?!<\/w:tr>).)*?)<\/w:tr>/s', $xml, $rowMatches, PREG_OFFSET_CAPTURE);

        // Procesar de atrás hacia adelante para no romper offsets
        for ($idx = count($rowMatches[0]) - 1; $idx >= 0; $idx--) {
            $rowXml    = $rowMatches[0][$idx][0];
            $rowOffset = $rowMatches[0][$idx][1];

            // Extraer texto de la primera celda para ver si es un idioma
            preg_match_all('/(<w:tc\b[^>]*>(?:(?!<\/w:tc>).)*?<\/w:tc>)/s', $rowXml, $cells);
            if (count($cells[1]) < 4) continue;

            preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', $cells[1][0], $ct);
            $cellText = mb_strtolower(trim(implode('', $ct[1])), 'UTF-8');

            // Buscar en nuestro mapa de idiomas
            $match = null;
            foreach ($idiomaMap as $nombre => $niveles) {
                if (stripos($cellText, $nombre) !== false || stripos($nombre, $cellText) !== false) {
                    $match = $niveles;
                    break;
                }
            }

            if (!$match) continue;

            // Rellenar celdas 1, 2, 3 (Hablado, Escrito, Leído)
            $values = [
                1 => htmlspecialchars($match['hablado'], ENT_XML1, 'UTF-8'),
                2 => htmlspecialchars($match['escrito'], ENT_XML1, 'UTF-8'),
                3 => htmlspecialchars($match['leido'], ENT_XML1, 'UTF-8'),
            ];

            $newRow = $rowXml;
            foreach ($values as $i => $val) {
                if (isset($cells[1][$i])) {
                    $newCell = $this->injectInCell($cells[1][$i], $val);
                    $newRow  = str_replace($cells[1][$i], $newCell, $newRow);
                }
            }

            $xml = substr_replace($xml, $newRow, $rowOffset, strlen($rowXml));
        }

        return $xml;
    }

    /**
     * Ricoh: la celda "Datos personales:" tiene un placeholder tipo "Nombre A.A"
     * que debe quedar limpio. Encuentra cualquier párrafo que empiece por
     * "Datos personales" y reemplaza todo el texto del párrafo por solo "Datos personales:".
     */
    private function cleanRicohDatosPersonales(string $xml): string
    {
        return preg_replace_callback(
            '~<w:p\b[^>]*>.*?</w:p>~s',
            function ($m) {
                $paraXml = $m[0];
                preg_match_all('~<w:t[^>]*>([^<]*)</w:t>~', $paraXml, $pt);
                $text = trim(implode('', $pt[1]));
                if (stripos($text, 'datos personales') !== 0) return $paraXml;

                // Reemplazar el texto de TODOS los <w:t> de este párrafo:
                // dejar solo "Datos personales:" en el primero y vaciar el resto.
                $first = true;
                return preg_replace_callback(
                    '~(<w:t[^>]*>)([^<]*)(</w:t>)~',
                    function ($mt) use (&$first) {
                        if ($first) {
                            $first = false;
                            return $mt[1] . 'Datos personales:' . $mt[3];
                        }
                        return $mt[1] . $mt[3];
                    },
                    $paraXml
                );
            },
            $xml
        ) ?? $xml;
    }

    /**
     * Variante de applySectionContent que SOLO busca el título en párrafos
     * que NO están dentro de tablas. Evita confundir labels de tabla
     * (ej: "Idiomas" en el cuadro de control) con títulos de sección reales.
     */
    private function applySectionContentOutsideTable(string $xml, string $sectionTitle, string $content): string
    {
        if (empty($sectionTitle) || empty($content)) return $xml;

        $normalize = [$this, 'normalizeForMatch'];

        $titleNorm = $normalize($sectionTitle);

        // Obtener rangos de tablas para excluir
        preg_match_all('~<w:tbl\b[^>]*>.*?</w:tbl>~s', $xml, $tblMatches, PREG_OFFSET_CAPTURE);
        $tableRanges = [];
        foreach ($tblMatches[0] as $tm) {
            $tableRanges[] = [$tm[1], $tm[1] + strlen($tm[0])];
        }

        // Buscar el párrafo de título SOLO fuera de tablas
        $headingEnd = -1;
        $offset = 0;
        while (preg_match('~<w:p\b[^>]*>.*?</w:p>~s', $xml, $pm, PREG_OFFSET_CAPTURE, $offset)) {
            $paraXml = $pm[0][0];
            $paraOff = $pm[0][1];

            // Saltar párrafos dentro de tablas
            $inTable = false;
            foreach ($tableRanges as [$tStart, $tEnd]) {
                if ($paraOff >= $tStart && $paraOff < $tEnd) { $inTable = true; break; }
            }
            if ($inTable) { $offset = $paraOff + strlen($paraXml); continue; }

            preg_match_all('~<w:t[^>]*>([^<]*)</w:t>~', $paraXml, $pt);
            $paraTextNorm = $normalize(implode('', $pt[1]));

            if ($paraTextNorm === $titleNorm) {
                $headingEnd = $paraOff + strlen($paraXml);
                break;
            }
            $offset = $paraOff + strlen($paraXml);
        }

        if ($headingEnd === -1) {
            // Fallback: usar applySectionContent normal si no se encontró fuera de tabla
            return $this->applySectionContent($xml, $sectionTitle, $content);
        }

        // Desde headingEnd, buscar hasta el siguiente título de sección o fin del body
        $sectionTitles = array_map($normalize, [
            'EXPERIENCIA LABORAL', 'EXPERIENCIA PROFESIONAL',
            'TRAYECTORIA PROFESIONAL', 'TRAYECTORIA',
            'FORMACION ACADEMICA', 'FORMACION COMPLEMENTARIA',
            'FORMACION', 'CONOCIMIENTOS TECNICOS', 'COMPETENCIAS TECNICAS',
            'IDIOMAS', 'CERTIFICACIONES', 'DATOS PERSONALES',
            'PERFIL PROFESIONAL', 'SOFT SKILLS',
            'PROTECCION DE DATOS PERSONALES',
            'SUMMARY', 'TECHNICAL SKILLS', 'EXPERIENCE', 'TRAINING', 'EDUCATION',
        ]);

        $afterHeading = substr($xml, $headingEnd);
        $nextSectionStart = strlen($afterHeading);
        $lastParaEnd = 0;
        $searchOffset = 0;

        while (preg_match('~<w:p\b[^>]*>.*?</w:p>~s', $afterHeading, $nm, PREG_OFFSET_CAPTURE, $searchOffset)) {
            $paraXml = $nm[0][0];
            $paraOff = $nm[0][1];

            // Saltar párrafos en tablas
            $globalOff = $headingEnd + $paraOff;
            $inTable = false;
            foreach ($tableRanges as [$tStart, $tEnd]) {
                if ($globalOff >= $tStart && $globalOff < $tEnd) { $inTable = true; break; }
            }
            if ($inTable) { $searchOffset = $paraOff + strlen($paraXml); continue; }

            preg_match_all('~<w:t[^>]*>([^<]*)</w:t>~', $paraXml, $pt);
            $paraText = $normalize(implode('', $pt[1]));

            foreach ($sectionTitles as $kt) {
                if ($kt !== $titleNorm && $paraText === $kt) {
                    $nextSectionStart = $paraOff;
                    goto idiomasFound;
                }
            }

            $lastParaEnd = $paraOff + strlen($paraXml);
            $searchOffset = $lastParaEnd;
        }
        $nextSectionStart = $lastParaEnd;
        idiomasFound:

        $contentXml = $this->textToParaXml($content);
        return substr($xml, 0, $headingEnd) . $contentXml . substr($xml, $headingEnd + $nextSectionStart);
    }

    // =========================================================================
    // PASO 2E: Campos específicos de Avanade (página 2 - Summary)
    // =========================================================================

    /**
     * Avanade Summary: Claude ya rellena los párrafos simples (Nombre, Madrid,
     * Nivel de inglés, Disponibilidad...) via full_paragraph / paragraph_with_label.
     * PHP solo interviene como fallback si Claude no rellenó algo.
     */
    private function applyAvanadeSummaryFields(string $xml): string
    {
        $dp = $this->cv['datos_personales'] ?? [];
        $idiomas = $this->cv['idiomas'] ?? [];

        // "Nombre" → solo reemplazar si el párrafo todavía dice literalmente "Nombre"
        $nombre = trim($dp['nombre_completo'] ?? '');
        if ($nombre) {
            $xml = $this->replaceParaText($xml, 'Nombre', $nombre);
        }

        // "Madrid" → solo reemplazar si el párrafo todavía dice literalmente "Madrid"
        // Si Claude ya lo reemplazó, replaceParaText no encontrará "Madrid" y no hará nada
        $residencia = trim($dp['residencia'] ?? '');
        if ($residencia) {
            $xml = $this->replaceParaText($xml, 'Madrid', $residencia);
        }

        // Nivel de inglés, disponibilidad: Claude se encarga vía full_paragraph.
        // No hacemos append aquí para evitar duplicaciones.

        return $xml;
    }

    /**
     * Busca un párrafo (NO dentro de tabla) cuyo texto visible sea exactamente $textFragment
     * y reemplaza TODO su texto visible con $newText.
     */
    private function replaceParaText(string $xml, string $textFragment, string $newText): string
    {
        $valEsc  = htmlspecialchars($newText, ENT_XML1, 'UTF-8');

        // Obtener rangos de tablas para excluir
        preg_match_all('~<w:tbl\b[^>]*>.*?</w:tbl>~s', $xml, $tblMatches, PREG_OFFSET_CAPTURE);
        $tableRanges = [];
        foreach ($tblMatches[0] as $tm) {
            $tableRanges[] = [$tm[1], $tm[1] + strlen($tm[0])];
        }

        $offset = 0;
        while (preg_match('~<w:p\b[^>]*>.*?</w:p>~s', $xml, $pm, PREG_OFFSET_CAPTURE, $offset)) {
            $paraXml = $pm[0][0];
            $paraOff = $pm[0][1];

            // Saltar párrafos dentro de tablas
            $inTable = false;
            foreach ($tableRanges as [$tStart, $tEnd]) {
                if ($paraOff >= $tStart && $paraOff < $tEnd) { $inTable = true; break; }
            }
            if ($inTable) { $offset = $paraOff + strlen($paraXml); continue; }

            preg_match_all('~<w:t[^>]*>([^<]*)</w:t>~', $paraXml, $pt);
            $fullText = implode('', $pt[1]);

            if (trim($fullText) === $textFragment) {
                // Reemplazar primer <w:t> con el valor y vaciar los demás
                $replaced = false;
                $newPara = preg_replace_callback('~(<w:t[^>]*>)[^<]*(</w:t>)~', function($m) use ($valEsc, &$replaced) {
                    if (!$replaced) {
                        $replaced = true;
                        return $m[1] . $valEsc . $m[2];
                    }
                    return $m[1] . $m[2]; // vaciar tags siguientes
                }, $paraXml);

                return substr_replace($xml, $newPara, $paraOff, strlen($paraXml));
            }

            $offset = $paraOff + strlen($paraXml);
        }
        return $xml;
    }

    /**
     * Busca un párrafo (NO dentro de tabla) cuyo texto visible contenga $textFragment
     * y añade un run con $appendText al final.
     */
    private function appendToParaByText(string $xml, string $textFragment, string $appendText): string
    {
        $valEsc = htmlspecialchars($appendText, ENT_XML1, 'UTF-8');

        // Obtener rangos de tablas para excluir párrafos dentro de ellas
        preg_match_all('~<w:tbl\b[^>]*>.*?</w:tbl>~s', $xml, $tblMatches, PREG_OFFSET_CAPTURE);
        $tableRanges = [];
        foreach ($tblMatches[0] as $tm) {
            $tableRanges[] = [$tm[1], $tm[1] + strlen($tm[0])];
        }

        $offset = 0;
        while (preg_match('~<w:p\b[^>]*>.*?</w:p>~s', $xml, $pm, PREG_OFFSET_CAPTURE, $offset)) {
            $paraXml = $pm[0][0];
            $paraOff = $pm[0][1];

            // Saltar párrafos dentro de tablas
            $inTable = false;
            foreach ($tableRanges as [$tStart, $tEnd]) {
                if ($paraOff >= $tStart && $paraOff < $tEnd) { $inTable = true; break; }
            }
            if ($inTable) { $offset = $paraOff + strlen($paraXml); continue; }

            preg_match_all('~<w:t[^>]*>([^<]*)</w:t>~', $paraXml, $pt);
            $fullText = implode('', $pt[1]);

            if (mb_stripos($fullText, $textFragment) !== false) {
                // Copiar formato del último run y añadir nuevo run con el valor
                $newRun = '<w:r><w:t xml:space="preserve"> ' . $valEsc . '</w:t></w:r>';

                // Si hay un <w:rPr> en el último run, copiar su formato
                if (preg_match('~<w:r\b[^>]*>((?:(?!</w:r>).)*)</w:r>(?!.*<w:r)~s', $paraXml, $lastRun)) {
                    if (preg_match('~<w:rPr>.*?</w:rPr>~s', $lastRun[1], $rPr)) {
                        $newRun = '<w:r>' . $rPr[0] . '<w:t xml:space="preserve"> ' . $valEsc . '</w:t></w:r>';
                    }
                }

                // Insertar antes de </w:p>
                $newPara = preg_replace('~</w:p>$~', $newRun . '</w:p>', $paraXml);
                return substr_replace($xml, $newPara, $paraOff, strlen($paraXml));
            }

            $offset = $paraOff + strlen($paraXml);
        }
        return $xml;
    }

    // =========================================================================
    // PASO 1C: Aplicar sustituciones de Claude (tabla + párrafos simples)
    // =========================================================================

    private function applySubstitutions(string $xml, array $substitutions): string
    {
        // Labels que PHP gestiona — bloquear cualquier sustitución de Claude sobre ellos
        $blockedLabels = [
            'entornos', 'conocimientos', 'competencias', 'idiomas',
            'experiencia', 'formación', 'formacion', 'trayectoria',
            'datos personales',
        ];

        foreach ($substitutions as $sub) {
            $type  = $sub['type']  ?? '';
            $value = trim($sub['value'] ?? '');
            if ($value === '') continue;

            // Filtrar sustituciones sobre secciones gestionadas por PHP
            $label = mb_strtolower($sub['label'] ?? $sub['original'] ?? $sub['section_title'] ?? '', 'UTF-8');
            $blocked = false;
            foreach ($blockedLabels as $bl) {
                if (str_contains($label, $bl)) { $blocked = true; break; }
            }
            if ($blocked) continue;

            $before  = $xml;
            switch ($type) {
                case 'cell_after_label':
                    $xml = $this->applyCellAfterLabel($xml, $sub['label'] ?? '', $value);
                    break;
                case 'paragraph_with_label':
                    $xml = $this->applyParagraphWithLabel($xml, $sub['label'] ?? '', $sub['placeholder'] ?? '', $value);
                    break;
                case 'replace_placeholder':
                    $xml = $this->applyReplacePlaceholder($xml, $sub['placeholder'] ?? '', $value);
                    break;
                case 'full_paragraph':
                    $xml = $this->applyFullParagraph($xml, $sub['original'] ?? '', $value);
                    break;
                case 'section_content':
                    // Las secciones principales las gestiona PHP — ignorar las de Claude
                    // para EXPERIENCIA, FORMACION y CONOCIMIENTOS
                    $title = strtoupper($sub['section_title'] ?? '');
                    if (str_contains($title, 'EXPERIENCIA')
                        || str_contains($title, 'FORMAC')
                        || str_contains($title, 'CONOCIM')) {
                        continue 2;
                    }
                    $xml = $this->applySectionContent($xml, $sub['section_title'] ?? '', $value);
                    break;
            }

            $applied = ($xml !== $before);
            $logEntry = ['type' => $type, 'applied' => $applied];
            if (isset($sub['label']))         $logEntry['label']       = $sub['label'];
            if (isset($sub['placeholder']))   $logEntry['placeholder'] = $sub['placeholder'];
            if (isset($sub['original']))      $logEntry['original']    = $sub['original'];
            if (isset($sub['section_title'])) $logEntry['section_title'] = $sub['section_title'];
            $logEntry['value'] = mb_substr($value, 0, 80);
            $this->applyLog[] = $logEntry;
        }
        return $xml;
    }

    /**
     * Busca un párrafo cuyo texto visible normalizado coincida con $originalText
     * y reemplaza TODO su contenido de texto con $newText.
     * Normaliza espacios y puntuación para matching flexible.
     */
    private function applyFullParagraph(string $xml, string $originalText, string $newText): string
    {
        if (empty($originalText)) return $xml;
        $valEsc = htmlspecialchars($newText, ENT_XML1, 'UTF-8');

        // Normalizar para comparación flexible
        $normOriginal = preg_replace('/\s+/', ' ', trim($originalText));

        $offset = 0;
        while (preg_match('~<w:p\b[^>]*>.*?</w:p>~s', $xml, $pm, PREG_OFFSET_CAPTURE, $offset)) {
            $paraXml = $pm[0][0];
            $paraOff = $pm[0][1];

            preg_match_all('~<w:t[^>]*>([^<]*)</w:t>~', $paraXml, $pt);
            $fullText = preg_replace('/\s+/', ' ', trim(implode('', $pt[1])));

            if (strcasecmp($fullText, $normOriginal) === 0 || $fullText === $normOriginal) {
                // Reemplazar primer <w:t> con el valor y vaciar los demás
                $replaced = false;
                $newPara = preg_replace_callback('~(<w:t[^>]*>)[^<]*(</w:t>)~', function($m) use ($valEsc, &$replaced) {
                    if (!$replaced) {
                        $replaced = true;
                        return $m[1] . $valEsc . $m[2];
                    }
                    return $m[1] . $m[2];
                }, $paraXml);

                return substr_replace($xml, $newPara, $paraOff, strlen($paraXml));
            }

            $offset = $paraOff + strlen($paraXml);
        }
        return $xml;
    }

    /**
     * Normaliza texto para comparación: quita tildes, mayúsculas, colapsa espacios,
     * normaliza barras (quita espacios alrededor de /).
     */
    private function normalizeForMatch(string $s): string
    {
        $s = mb_strtoupper($s, 'UTF-8');
        $s = str_replace(
            ['Á','É','Í','Ó','Ú','Ü','Ñ'],
            ['A','E','I','O','U','U','N'],
            $s
        );
        // Normalizar barras: "ENTORNOS / CONOCIMIENTOS" → "ENTORNOS/CONOCIMIENTOS"
        $s = preg_replace('/\s*\/\s*/', '/', $s);
        return preg_replace('/\s+/', ' ', trim($s));
    }

    private function cleanResidualPlaceholders(string $xml): string
    {
        $xml = preg_replace('/(<w:t[^>]*>)texto,\s*texto(<\/w:t>)/iu', '$1$2', $xml);
        $xml = preg_replace('/(<w:t[^>]*>)texto(<\/w:t>)/iu',           '$1$2', $xml);
        $xml = preg_replace('/(<w:t[^>]*>)\xc2\xa0(<\/w:t>)/u',         '$1$2', $xml);
        // Limpiar placeholders de formación complementaria vacía
        $xml = preg_replace('/(<w:t[^>]*>)Fecha(<\/w:t>)/u',            '$1$2', $xml);
        $xml = preg_replace('/(<w:t[^>]*>)Horas(<\/w:t>)/u',            '$1$2', $xml);
        $xml = preg_replace('/(<w:t[^>]*>)Informaci[óo]n(<\/w:t>)/iu',  '$1$2', $xml);
        $xml = preg_replace('/(<w:t[^>]*>)cursos(<\/w:t>)/iu',          '$1$2', $xml);
        $xml = preg_replace('/(<w:t[^>]*>)Madrid(<\/w:t>)/u',           '$1$2', $xml);
        return $xml;
    }

    // =========================================================================
    // OPERACIONES XML
    // =========================================================================

    private function applyCellAfterLabel(string $xml, string $label, string $value): string
    {
        if (empty($label)) return $xml;
        $valEsc = htmlspecialchars($value, ENT_XML1, 'UTF-8');

        preg_match_all('/<w:tr\b[^>]*>(?:(?!<\/w:tr>).)*?<\/w:tr>/s', $xml, $rowMatches, PREG_OFFSET_CAPTURE);

        foreach ($rowMatches[0] as $rowMatch) {
            $rowXml    = $rowMatch[0];
            $rowOffset = $rowMatch[1];

            preg_match_all('/(<w:tc\b[^>]*>(?:(?!<\/w:tc>).)*?<\/w:tc>)/s', $rowXml, $cells);
            if (empty($cells[1])) continue;

            $labelIdx = -1;
            foreach ($cells[1] as $i => $cell) {
                preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', $cell, $ct);
                $cellText = preg_replace('/\s+/', ' ', trim(implode('', $ct[1])));
                $labelN   = preg_replace('/\s+/', ' ', trim($label));
                if (stripos($cellText, $labelN) !== false) {
                    $labelIdx = $i;
                    break;
                }
            }

            if ($labelIdx === -1) continue;

            if (isset($cells[1][$labelIdx + 1])) {
                // Caso normal: la etiqueta está en una celda y el valor va en la siguiente
                $targetCell = $cells[1][$labelIdx + 1];
                $newCell    = $this->injectInCell($targetCell, $valEsc);
                $newRow     = str_replace($targetCell, $newCell, $rowXml);
                return substr_replace($xml, $newRow, $rowOffset, strlen($rowXml));
            }

            // Caso celda única: etiqueta y placeholder están en la misma celda en runs separados
            // Reemplazar el texto del último <w:r> que NO contiene la etiqueta
            $cellXml = $cells[1][$labelIdx];
            $labelN  = preg_replace('/\s+/', ' ', trim($label));
            preg_match_all('/(<w:r\b[^>]*>(?:(?!<\/w:r>).)*?<\/w:r>)/s', $cellXml, $runs);
            if (!empty($runs[1])) {
                // Buscar el último run cuyo texto NO sea la etiqueta
                for ($r = count($runs[1]) - 1; $r >= 0; $r--) {
                    preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', $runs[1][$r], $rt);
                    $runText = preg_replace('/\s+/', ' ', trim(implode('', $rt[1])));
                    if ($runText !== '' && stripos($runText, $labelN) === false) {
                        // Reemplazar el texto de este run con el valor
                        $oldRun = $runs[1][$r];
                        $newRun = preg_replace('/(<w:t[^>]*>)[^<]*(<\/w:t>)/', '${1}' . $valEsc . '${2}', $oldRun, 1);
                        $newCellXml = str_replace($oldRun, $newRun, $cellXml);
                        $newRow = str_replace($cellXml, $newCellXml, $rowXml);
                        return substr_replace($xml, $newRow, $rowOffset, strlen($rowXml));
                    }
                }
            }
        }

        return $xml;
    }

    private function applyParagraphWithLabel(string $xml, string $label, string $placeholder, string $value): string
    {
        if (empty($label)) return $xml;
        $labelEsc = htmlspecialchars($label, ENT_XML1, 'UTF-8');
        $phEsc    = htmlspecialchars($placeholder, ENT_XML1, 'UTF-8');
        $valEsc   = htmlspecialchars($value, ENT_XML1, 'UTF-8');

        $paraPat = '/<w:p\b[^>]*>(?:(?!<\/w:p>).)*?' . preg_quote($labelEsc, '/') . '.*?<\/w:p>/s';
        if (!preg_match($paraPat, $xml, $paraMatch, PREG_OFFSET_CAPTURE)) return $xml;

        $paraXml = $paraMatch[0][0];
        $paraOff = $paraMatch[0][1];

        if (!empty($phEsc)) {
            $newPara = preg_replace(
                '/(<w:t[^>]*>)' . preg_quote($phEsc, '/') . '(<\/w:t>)/s',
                '${1}' . $valEsc . '${2}',
                $paraXml, 1
            );
        } else {
            $newPara = preg_replace(
                '/(<w:t[^>]*>)[^<]*(<\/w:t>)(?!.*<w:t)/s',
                '${1}' . $valEsc . '${2}',
                $paraXml, 1
            );
        }

        if ($newPara === $paraXml) return $xml;
        return substr_replace($xml, $newPara, $paraOff, strlen($paraXml));
    }

    private function applyReplacePlaceholder(string $xml, string $placeholder, string $value): string
    {
        if (empty($placeholder)) return $xml;
        $phEsc  = htmlspecialchars($placeholder, ENT_XML1, 'UTF-8');
        $valEsc = htmlspecialchars($value, ENT_XML1, 'UTF-8');
        return preg_replace(
            '/(<w:t[^>]*>)' . preg_quote($phEsc, '/') . '(<\/w:t>)/s',
            '${1}' . $valEsc . '${2}',
            $xml, 1
        );
    }

    /**
     * Inserta contenido libre justo después del párrafo de título de sección,
     * borrando los párrafos placeholder hasta el siguiente título conocido.
     *
     * Fix v12: el título puede estar partido en múltiples runs (ej: "FORMACI" + "O" + "N COMPLEMENTARIA").
     * Se busca el párrafo cuyo texto VISIBLE CONCATENADO coincida con el título buscado,
     * comparando texto normalizado (sin tildes, mayúsculas) para evitar fallos de encoding.
     */
    private function applySectionContent(string $xml, string $sectionTitle, string $content, bool $forceBold = false): string
    {
        if (empty($sectionTitle) || empty($content)) return $xml;

        $normalize = [$this, 'normalizeForMatch'];

        $titleNorm = $normalize($sectionTitle);

        // Títulos de sección conocidos normalizados
        $sectionTitles = array_map($normalize, [
            // Español — variantes comunes
            'EXPERIENCIA LABORAL', 'EXPERIENCIA PROFESIONAL',
            'TRAYECTORIA PROFESIONAL', 'TRAYECTORIA',
            'FORMACION ACADEMICA', 'FORMACION COMPLEMENTARIA',
            'FORMACION',
            'CONOCIMIENTOS TECNICOS', 'COMPETENCIAS TECNICAS',
            'ENTORNOS/CONOCIMIENTOS TECNICOS', 'ENTORNOS / CONOCIMIENTOS TECNICOS',
            'IDIOMAS', 'INGLES',
            'COMPETENCIAS', 'SUMARIO', 'SUMARIO PROFESIONAL',
            'INFORME DE ENTREVISTA',
            'CERTIFICACIONES', 'CERTIFICACIONES OFICIALES',
            'DATOS PERSONALES', 'PERFIL PROFESIONAL', 'PERFIL', 'RESUMEN',
            'SOFT SKILLS', 'HABILIDADES',
            'INFORME DE EVALUACION CANDIDATURA',
            'PROTECCION DE DATOS PERSONALES',
            // Inglés (Avanade)
            'SUMMARY', 'GEOGRAPHIC AVAILABILITY', 'TECHNICAL SKILLS',
            'MICROSOFT SPECIFIC SKILL', 'EXPERIENCE', 'TRAINING', 'EDUCATION',
        ]);

        // Buscar el párrafo de título iterando TODOS los párrafos y concatenando sus runs
        $headingEnd = -1;
        $offset = 0;
        while (preg_match('~<w:p\b[^>]*>.*?</w:p>~s', $xml, $pm, PREG_OFFSET_CAPTURE, $offset)) {
            $paraXml = $pm[0][0];
            $paraOff = $pm[0][1];

            preg_match_all('~<w:t[^>]*>([^<]*)</w:t>~', $paraXml, $pt);
            $paraTextNorm = $normalize(implode('', $pt[1]));

            if ($paraTextNorm === $titleNorm) {
                $headingEnd = $paraOff + strlen($paraXml);
                break;
            }
            $offset = $paraOff + strlen($paraXml);
        }

        if ($headingEnd === -1) return $xml; // título no encontrado

        // Si el heading está dentro de una tabla, avanzar headingEnd hasta después de </w:tbl>
        $beforeHeading = substr($xml, 0, $headingEnd);
        $openTbls  = preg_match_all('/<w:tbl[\s>]/', $beforeHeading);
        $closeTbls = substr_count($beforeHeading, '</w:tbl>');
        if ($openTbls > $closeTbls) {
            // Estamos dentro de una tabla — buscar el cierre
            $tblClosePos = strpos($xml, '</w:tbl>', $headingEnd);
            if ($tblClosePos !== false) {
                $headingEnd = $tblClosePos + strlen('</w:tbl>');
            }
        }

        $afterHeading = substr($xml, $headingEnd);

        // Encontrar el siguiente título de sección distinto
        $offset      = 0;
        $lastParaEnd = 0; // posición tras el último párrafo encontrado
        $nextSectionStart = strlen($afterHeading); // por defecto, hasta el final

        while (preg_match('~<w:p\b[^>]*>.*?</w:p>~s', $afterHeading, $nm, PREG_OFFSET_CAPTURE, $offset)) {
            $paraXml  = $nm[0][0];
            $paraOff  = $nm[0][1];

            preg_match_all('~<w:t[^>]*>([^<]*)</w:t>~', $paraXml, $pt);
            $paraText = $normalize(implode('', $pt[1]));

            foreach ($sectionTitles as $kt) {
                if ($kt !== $titleNorm && $paraText === $kt) {
                    // Si este título está dentro de una tabla, retroceder hasta <w:tbl
                    $beforeNext = substr($afterHeading, 0, $paraOff);
                    $nextOpenTbls  = preg_match_all('/<w:tbl[\s>]/', $beforeNext);
                    $nextCloseTbls = substr_count($beforeNext, '</w:tbl>');
                    if ($nextOpenTbls > $nextCloseTbls) {
                        // Buscar el último <w:tbl> real (no <w:tblPr, <w:tblGrid, etc.)
                        if (preg_match_all('/<w:tbl[\s>]/', $beforeNext, $tblMatches, PREG_OFFSET_CAPTURE)) {
                            $lastTblOpen = end($tblMatches[0]);
                            $nextSectionStart = $lastTblOpen[1];
                        } else {
                            $nextSectionStart = $paraOff;
                        }
                    } else {
                        $nextSectionStart = $paraOff;
                    }
                    goto found;
                }
            }

            $lastParaEnd = $paraOff + strlen($paraXml);
            $offset = $lastParaEnd;
        }
        $nextSectionStart = $lastParaEnd;
        found:

        $contentXml = $this->textToParaXml($content, $forceBold);
        return substr($xml, 0, $headingEnd) . $contentXml . substr($xml, $headingEnd + $nextSectionStart);
    }

    /**
     * Variante de applySectionContent para plantillas donde el título está en una
     * tabla-header y el contenido debe ir DENTRO de una tabla-contenido posterior.
     * Patrón: [tabla con título] → [párrafos vacíos opcionales] → [tabla contenido] → [siguiente sección]
     * En vez de reemplazar la tabla-contenido con párrafos sueltos, inyecta el contenido
     * dentro de la primera celda de esa tabla.
     */
    private function applySectionContentIntoTable(string $xml, string $sectionTitle, string $content): string
    {
        if (empty($sectionTitle) || empty($content)) return $xml;

        $normalize = [$this, 'normalizeForMatch'];

        $titleNorm = $normalize($sectionTitle);

        // Buscar el párrafo del título
        $headingEnd = -1;
        $offset = 0;
        while (preg_match('~<w:p\b[^>]*>.*?</w:p>~s', $xml, $pm, PREG_OFFSET_CAPTURE, $offset)) {
            $paraXml = $pm[0][0];
            $paraOff = $pm[0][1];
            preg_match_all('~<w:t[^>]*>([^<]*)</w:t>~', $paraXml, $pt);
            $paraTextNorm = $normalize(implode('', $pt[1]));
            if ($paraTextNorm === $titleNorm) {
                $headingEnd = $paraOff + strlen($paraXml);
                break;
            }
            $offset = $paraOff + strlen($paraXml);
        }

        if ($headingEnd === -1) return $xml;

        // Si el heading está dentro de una tabla, avanzar headingEnd hasta después de </w:tbl>
        $beforeHeading = substr($xml, 0, $headingEnd);
        $openTbls  = preg_match_all('/<w:tbl[\s>]/', $beforeHeading);
        $closeTbls = substr_count($beforeHeading, '</w:tbl>');
        if ($openTbls > $closeTbls) {
            $tblClosePos = strpos($xml, '</w:tbl>', $headingEnd);
            if ($tblClosePos !== false) {
                $headingEnd = $tblClosePos + strlen('</w:tbl>');
            }
        }

        // Buscar la tabla-contenido después del heading
        $afterHeading = substr($xml, $headingEnd);

        // Buscar la próxima tabla (saltando párrafos vacíos opcionales)
        if (!preg_match('~^((?:\s*<w:p\b[^>]*>.*?</w:p>\s*)*?)\s*(<w:tbl\b[^>]*>.*?</w:tbl>)~s', $afterHeading, $tblMatch)) {
            // No hay tabla-contenido — fallback a applySectionContent normal
            return $this->applySectionContent($xml, $sectionTitle, $content);
        }

        $beforeTable  = $tblMatch[1];
        $contentTable = $tblMatch[2];

        // Generar el XML del contenido
        $contentXml = $this->textToParaXml($content);

        // Inyectar en la primera celda de la tabla-contenido
        // Preservar las propiedades de la celda (tcPr) pero reemplazar el contenido
        $newTable = preg_replace(
            '~(<w:tc\b[^>]*>(?:\s*<w:tcPr>.*?</w:tcPr>)?).*?(</w:tc>)~s',
            '$1' . $contentXml . '$2',
            $contentTable,
            1
        );

        $tableOffset = strlen($beforeTable);
        $tableEnd    = $tableOffset + strlen($contentTable);

        return substr($xml, 0, $headingEnd)
             . $beforeTable
             . $newTable
             . substr($afterHeading, $tableEnd);
    }

    private function injectInCell(string $cell, string $valueEsc): string
    {
        // Caso 1: hay texto existente → reemplazarlo
        if (preg_match('/<w:t([^>]*)>[^<]*<\/w:t>/', $cell)) {
            return preg_replace('/<w:t([^>]*)>[^<]*<\/w:t>/', '<w:t$1>' . $valueEsc . '</w:t>', $cell, 1);
        }
        // Caso 2: hay párrafo pero sin texto → insertar run
        if (preg_match('/<w:p\b[^>]*>/', $cell)) {
            $rPr = '';
            if (preg_match('/<w:rPr>((?:(?!<\/w:rPr>).)*?)<\/w:rPr>/', $cell, $m)) {
                $rPr = '<w:rPr>' . $m[1] . '</w:rPr>';
            }
            $run     = '<w:r>' . $rPr . '<w:t xml:space="preserve">' . $valueEsc . '</w:t></w:r>';
            $newCell = preg_replace('/(<\/w:pPr>)/', '$1' . $run, $cell, 1);
            if ($newCell === $cell) {
                $newCell = preg_replace('/<\/w:p>/', $run . '</w:p>', $cell, 1);
            }
            return $newCell;
        }
        // Caso 3: sin párrafo
        return str_replace('</w:tc>', '<w:p><w:r><w:t>' . $valueEsc . '</w:t></w:r></w:p></w:tc>', $cell);
    }

    /**
     * Convierte texto multilínea a párrafos OOXML.
     * Negrita para: fechas (06/2024), etiquetas (Funciones:, Entorno:…), texto TODO MAYÚSCULAS.
     * Líneas "Entorno: ..." → label "Entorno:" en negrita + skills en negrita.
     * Líneas de funciones (texto libre tras "Funciones:") → sin negrita.
     */
    private function textToParaXml(string $text, bool $forceBold = false): string
    {
        // Determinar fuente según plantilla
        $fontXml = '';
        $fontMap = [
            'avanade'   => 'Times New Roman',
            'arelance'  => 'Myriad Pro',
            'accenture' => 'Myriad Pro',
            'ricoh'     => 'Calibri',
        ];
        if (isset($fontMap[$this->templateKey])) {
            $font = $fontMap[$this->templateKey];
            $fontXml = '<w:rFonts w:ascii="' . $font . '" w:hAnsi="' . $font . '"/>';
        }

        $lines = explode("\n", $text);
        $xml   = '';
        $afterFunciones = false; // Indica si la línea anterior fue "Funciones:"

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                $xml .= '<w:p><w:pPr><w:spacing w:before="60" w:after="60"/></w:pPr></w:p>';
                $afterFunciones = false;
                continue;
            }

            // Detectar y procesar markers de formato
            $isBlueRole = false;
            $isMarkerBold = false;
            if (str_starts_with($line, '[ROLE_BLUE]')) {
                $line = substr($line, strlen('[ROLE_BLUE]'));
                $isBlueRole = true;
            } elseif (str_starts_with($line, '[BOLD]')) {
                $line = substr($line, strlen('[BOLD]'));
                $isMarkerBold = true;
            }

            // Detectar bullets (• o -) para aplicar indentación
            $isBullet = (bool) preg_match('/^[•\-]\s/u', $line);
            $indent = $isBullet
                ? '<w:ind w:left="360" w:hanging="180"/>'
                : '';

            // Detectar si esta línea es texto de funciones (justo después de "Funciones:")
            $isFunctionText = $afterFunciones && !preg_match('/^(Entorno:|Cliente:|Funciones:|\d{2}\/\d{4}|\d{4})/', $line);

            // Resetear flag
            $afterFunciones = ($line === 'Funciones:');

            $esc    = htmlspecialchars($line, ENT_XML1, 'UTF-8');

            // Renderizar rol en azul+negrita o empresa en negrita, y continuar
            if ($isBlueRole) {
                $rprBlue = '<w:rPr>' . $fontXml . '<w:b/><w:color w:val="0070C0"/><w:sz w:val="20"/></w:rPr>';
                $xml .= '<w:p>'
                      . '<w:pPr><w:spacing w:before="0" w:after="40"/></w:pPr>'
                      . '<w:r>' . $rprBlue . '<w:t xml:space="preserve">' . $esc . '</w:t></w:r>'
                      . '</w:p>';
                continue;
            }
            if ($isMarkerBold) {
                $rprBoldM = '<w:rPr>' . $fontXml . '<w:b/><w:sz w:val="20"/></w:rPr>';
                $xml .= '<w:p>'
                      . '<w:pPr><w:spacing w:before="0" w:after="40"/></w:pPr>'
                      . '<w:r>' . $rprBoldM . '<w:t xml:space="preserve">' . $esc . '</w:t></w:r>'
                      . '</w:p>';
                continue;
            }

            // Labels que van completamente en negrita (label + contenido)
            $isFullBold = (bool) preg_match(
                '/^(?:\d{2}\/\d{4}|\d{4}|(?:Enero|Febrero|Marzo|Abril|Mayo|Junio|Julio|Agosto|Septiembre|Octubre|Noviembre|Diciembre)\s+\d{4}|Entorno:|Herramientas:|Tecnologías:|[A-ZÁÉÍÓÚÑ\s]{4,}$)/',
                $line
            );

            // Labels donde solo el label es negrita, pero el contenido no
            $isSplitLabel = (bool) preg_match(
                '/^(Empresa:|Cargo:|Cliente:|Rol:|Rol\/Categoría:|Título de posición:|Funciones:|Horas:|Categoría:)\s+.+/',
                $line
            );

            // Labels standalone sin contenido (ej: "Funciones:" sola) → negrita
            $isStandaloneLabel = (bool) preg_match(
                '/^(Empresa:|Cargo:|Cliente:|Rol:|Rol\/Categoría:|Título de posición:|Funciones:|Horas:|Categoría:)$/',
                $line
            );

            // Bullets con **keywords** en negrita: generar runs mixtos
            if ($isBullet && str_contains($line, '**')) {
                $bulletText = preg_replace('/^[•\-]\s/u', '', $line);
                $runs = $this->buildBoldKeywordRuns($bulletText, $fontXml);
                $xml .= '<w:p>'
                      . '<w:pPr><w:spacing w:before="0" w:after="40"/>' . $indent . '</w:pPr>'
                      . '<w:r>' . '<w:rPr>' . $fontXml . '<w:sz w:val="20"/></w:rPr>'
                      . '<w:t xml:space="preserve">• </w:t></w:r>'
                      . $runs
                      . '</w:p>';
                continue;
            }

            // Los bullets no son negrita (son contenido de funciones)
            if ($isBullet) { $isFullBold = false; $isSplitLabel = false; }

            // El texto descriptivo de funciones NO es negrita
            if ($isFunctionText) { $isFullBold = false; $isSplitLabel = false; }

            // Líneas de idiomas (ej: "Inglés: Avanzado") → no negrita
            if (preg_match('/^(Inglés|Español|Francés|Alemán|Portugués|Italiano|Catalán|Valenciano|Euskera|Gallego|English|Spanish|French|German|Chino|Japonés|Árabe|Ruso):/iu', $line)) {
                $isFullBold = false;
                $isSplitLabel = false;
            }

            // Si se fuerza negrita (ej: conocimientos técnicos), aplicar siempre a toda la línea
            if ($forceBold) { $isFullBold = true; $isSplitLabel = false; }

            $rprBold   = '<w:rPr>' . $fontXml . '<w:b/><w:sz w:val="20"/></w:rPr>';
            $rprNormal = '<w:rPr>' . $fontXml . '<w:sz w:val="20"/></w:rPr>';

            if ($isSplitLabel && preg_match('/^([^:]+:\s*)(.+)$/', $line, $parts)) {
                // Dos runs: label en negrita + contenido normal
                $labelEsc   = htmlspecialchars($parts[1], ENT_XML1, 'UTF-8');
                $contentEsc = htmlspecialchars($parts[2], ENT_XML1, 'UTF-8');
                $xml .= '<w:p>'
                      . '<w:pPr><w:spacing w:before="0" w:after="40"/>' . $indent . '</w:pPr>'
                      . '<w:r>' . $rprBold . '<w:t xml:space="preserve">' . $labelEsc . '</w:t></w:r>'
                      . '<w:r>' . $rprNormal . '<w:t xml:space="preserve">' . $contentEsc . '</w:t></w:r>'
                      . '</w:p>';
            } else {
                // Un solo run: todo negrita o todo normal
                $rpr = ($isFullBold || $isStandaloneLabel)
                    ? $rprBold
                    : $rprNormal;
                $xml .= '<w:p>'
                      . '<w:pPr><w:spacing w:before="0" w:after="40"/>' . $indent . '</w:pPr>'
                      . '<w:r>' . $rpr . '<w:t xml:space="preserve">' . $esc . '</w:t></w:r>'
                      . '</w:p>';
            }
        }

        return $xml;
    }

    // =========================================================================
    // CÁLCULO DE EXPERIENCIA
    // =========================================================================

    private function calcExperiencia(): string
    {
        $experiencias = $this->cv['experiencia_laboral'] ?? [];
        if (empty($experiencias)) return '';

        $hoy       = new DateTime();
        $totalDias = 0;

        foreach ($experiencias as $exp) {
            $inicio = trim($exp['fecha_inicio'] ?? '');
            $fin    = trim($exp['fecha_fin']    ?? '');
            if (empty($inicio)) continue;

            $dtInicio = $this->parseFechaExp($inicio);
            if (!$dtInicio) continue;

            $dtFin = (empty($fin) || preg_match('/^actualidad$/i', $fin))
                ? clone $hoy
                : ($this->parseFechaExp($fin) ?? clone $hoy);

            $totalDias += max(0, $dtInicio->diff($dtFin)->days);
        }

        if ($totalDias <= 0) return '';

        $meses = (int) round($totalDias / 30.44);
        $anios = (int) floor($meses / 12);
        $mRest = $meses % 12;

        if ($anios > 0 && $mRest > 0) return $anios . ' año' . ($anios > 1 ? 's' : '') . ' ' . $mRest . ' mes' . ($mRest > 1 ? 'es' : '');
        if ($anios > 0)               return $anios . ' año' . ($anios > 1 ? 's' : '');
        return $meses . ' mes' . ($meses > 1 ? 'es' : '');
    }

    private function parseFechaExp(string $fecha): ?DateTime
    {
        if (preg_match('/^(\d{1,2})\/(\d{4})$/', $fecha, $m)) {
            return DateTime::createFromFormat('d/m/Y', '01/' . str_pad($m[1], 2, '0', STR_PAD_LEFT) . '/' . $m[2]) ?: null;
        }
        if (preg_match('/^(\d{4})$/', $fecha, $m)) {
            return DateTime::createFromFormat('d/m/Y', '01/01/' . $m[1]) ?: null;
        }
        return null;
    }

    private function toTitleCase(string $text): string
    {
        $minusculas = ['de', 'del', 'la', 'las', 'los', 'el', 'en', 'y', 'e', 'o', 'u', 'a'];
        $words = explode(' ', $text);
        foreach ($words as $i => &$w) {
            if (mb_strlen($w, 'UTF-8') <= 1) continue;
            if (mb_strtoupper($w, 'UTF-8') !== $w) continue;
            $lower = mb_strtolower($w, 'UTF-8');
            if ($i > 0 && in_array($lower, $minusculas, true)) {
                $w = $lower;
            } else {
                $w = mb_strtoupper(mb_substr($lower, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($lower, 1, null, 'UTF-8');
            }
        }
        return implode(' ', $words);
    }

    private function stripBoldMarkers(string $text): string
    {
        return str_replace('**', '', $text);
    }

    private function buildBoldKeywordRuns(string $text, string $fontXml): string
    {
        $rprBold   = '<w:rPr>' . $fontXml . '<w:b/><w:sz w:val="20"/></w:rPr>';
        $rprNormal = '<w:rPr>' . $fontXml . '<w:sz w:val="20"/></w:rPr>';

        // Split por **...** preservando delimitadores
        $parts = preg_split('/\*\*(.+?)\*\*/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $xml = '';
        foreach ($parts as $i => $part) {
            if ($part === '') continue;
            $esc = htmlspecialchars($part, ENT_XML1, 'UTF-8');
            $rpr = ($i % 2 === 1) ? $rprBold : $rprNormal; // impares = captura (bold)
            $xml .= '<w:r>' . $rpr . '<w:t xml:space="preserve">' . $esc . '</w:t></w:r>';
        }
        return $xml;
    }

    private function fechaExpToSpanishText(string $fecha): string
    {
        $fecha = trim($fecha);
        if (preg_match('/^(\d{1,2})\/(\d{4})$/', $fecha, $m)) {
            $meses = [
                '01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril',
                '05'=>'Mayo','06'=>'Junio','07'=>'Julio','08'=>'Agosto',
                '09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre',
            ];
            $key = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            return ($meses[$key] ?? $m[1]) . ' ' . $m[2];
        }
        return $fecha;
    }

    private function parseSubstitutions(string $response): array
    {
        $response = trim(preg_replace('/\s*```$/m', '', preg_replace('/^```(?:json)?\s*/im', '', $response)));
        $data     = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            if (preg_match('/\{[\s\S]*\}/s', $response, $m)) {
                $data = json_decode($m[0], true);
            }
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("JSON invalido de Claude: " . json_last_error_msg());
            }
        }
        return $data['substitutions'] ?? [];
    }

    // =========================================================================
    // LOGGING
    // =========================================================================

    private function saveLog(array $substitutions): void
    {
        $lines   = [];
        $lines[] = '=== CV Converter v13 - Log de sustituciones ===';
        $lines[] = 'Fecha: ' . date('Y-m-d H:i:s');
        $lines[] = 'Plantilla: ' . $this->templateKey;
        $lines[] = 'Candidato: ' . ($this->cv['datos_personales']['nombre_completo'] ?? '?');
        $lines[] = '';
        $lines[] = '--- Claude propuso (' . count($substitutions) . ' sustituciones) ---';
        foreach ($substitutions as $i => $sub) {
            $type = $sub['type'] ?? '?';
            $id   = match($type) {
                'cell_after_label'    => 'label="' . ($sub['label'] ?? '') . '"',
                'paragraph_with_label'=> 'label="' . ($sub['label'] ?? '') . '" ph="' . ($sub['placeholder'] ?? '') . '"',
                'replace_placeholder' => 'ph="' . ($sub['placeholder'] ?? '') . '"',
                'section_content'     => 'section="' . ($sub['section_title'] ?? '') . '" [IGNORADA — gestiona PHP]',
                default               => json_encode($sub),
            };
            $lines[] = "  [{$i}] {$type} | {$id} → \"" . mb_substr(trim($sub['value'] ?? ''), 0, 60) . '"';
        }

        $lines[] = '';
        $lines[] = '--- Resultado de aplicación ---';
        $applied = 0; $failed = 0;
        foreach ($this->applyLog as $entry) {
            $ok = $entry['applied'] ? '✓ OK  ' : '✗ FAIL';
            if ($entry['applied']) $applied++; else $failed++;
            $type = $entry['type'];
            $id   = match($type) {
                'cell_after_label'    => 'label="' . ($entry['label'] ?? '') . '"',
                'paragraph_with_label'=> 'label="' . ($entry['label'] ?? '') . '" ph="' . ($entry['placeholder'] ?? '') . '"',
                'replace_placeholder' => 'ph="' . ($entry['placeholder'] ?? '') . '"',
                'section_content'     => 'section="' . ($entry['section_title'] ?? '') . '"',
                default               => '',
            };
            $lines[] = "  {$ok} | {$type} | {$id} → \"" . mb_substr($entry['value'] ?? '', 0, 60) . '"';
        }
        $lines[] = '';
        $lines[] = "RESUMEN Claude: {$applied} OK, {$failed} FAIL de " . count($this->applyLog);
        $lines[] = 'PHP sections: EXPERIENCIA, FORMACION ACADEMICA, FORMACION COMPLEMENTARIA, CONOCIMIENTOS aplicadas directamente.';

        file_put_contents(OUTPUT_PATH . 'debug_apply_log.txt', implode("\n", $lines) . "\n");
    }

    // =========================================================================
    // API
    // =========================================================================

    private function doRequest(string $payload): string
    {
        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) throw new Exception("Error de conexion: $error");
        $body = json_decode($response, true);
        if ($httpCode !== 200) {
            throw new Exception("Error Claude (HTTP $httpCode): " . ($body['error']['message'] ?? $response));
        }
        if (!isset($body['content'][0]['text'])) {
            throw new Exception("Respuesta inesperada de Claude");
        }
        return $body['content'][0]['text'];
    }
}
