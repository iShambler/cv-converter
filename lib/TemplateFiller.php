<?php
/**
 * CV Converter - TemplateFiller v11
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
        $nombre = str_replace(' ', '_', trim($nombre));
        $this->outputPath = OUTPUT_PATH . "CV_{$nombre}_" . ucfirst($templateKey) . '_' . date('Ymd_His') . '.docx';
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

        // PASO 2E: Campos específicos de Avanade (página 2 - Summary)
        if ($this->templateKey === 'avanade') {
            $xml = $this->applyAvanadeSummaryFields($xml);
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
        ];

        $dataJson = json_encode($dataResumen, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $systemPrompt = <<<'SYSTEM'
Eres un experto en relleno de plantillas de CV corporativas.
Tu misión: INTERPRETAR cada campo de la plantilla y decidir qué datos del candidato encajan, aunque los nombres no coincidan exactamente.

SECCIONES QUE NO DEBES TOCAR (las gestiona otro proceso automático):
- Títulos de sección como EXPERIENCIA, FORMACIÓN, CONOCIMIENTOS, COMPETENCIAS TÉCNICAS, IDIOMAS y sus equivalentes en inglés (Experience, Training, Education, Technical Skills).
- Los párrafos placeholder DENTRO de esas secciones (ej: "Mes año – mes año", "Empresa", "Categoria", "Funciones:", "Entorno:", "Cliente:").
- Esos se rellenan automáticamente — si los incluyes, se duplicarán.

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
- Para campos de tabla (FILA), usa "cell_after_label".
- Para párrafos simples, usa "paragraph_with_label" o "full_paragraph" según convenga.

PROHIBICIONES ABSOLUTAS:
- Si el dato está vacío, no existe, o no tienes información → NO incluir esa sustitución. DÉJALO COMO ESTÁ.
- NUNCA inventes valores. NUNCA pongas "No especificado", "No disponible", "N/A", "Sin datos", "texto", "XXXX", ni NINGÚN valor genérico o inventado.
- NUNCA borres información que ya tiene la plantilla si no tienes un dato real para reemplazarla.
- NO RELLENAR: ISLAS CANARIAS, Prueba realizada SI/NO, Soft Skills, Valoracion Final, Otras habilidades, Cumple este candidato, Recomendaría (esos son campos de evaluación interna).

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
        foreach ($experiencias as $exp) {
            $inicio   = trim($exp['fecha_inicio']        ?? '');
            $fin      = trim($exp['fecha_fin']           ?? '');
            $empresa  = trim($exp['empresa']             ?? '');
            $cargo    = trim($exp['cargo']               ?? '');
            $cat      = trim($exp['categoria']           ?? '');
            $cliente  = trim($exp['cliente']             ?? '');
            $func     = trim($exp['funciones']           ?? '');
            $entorno  = trim($exp['entorno_tecnologico'] ?? '');

            $fechaStr = $inicio . ($fin ? ' - ' . $fin : '');
            if ($fechaStr) $lines[] = $fechaStr;
            if ($empresa)  $lines[] = $empresa;

            $cargoFinal = $cargo ?: $cat;
            if ($cargoFinal) $lines[] = $cargoFinal;
            if ($cliente)    $lines[] = 'Cliente: ' . $cliente;
            if ($func)       $lines[] = 'Funciones: ' . $func;
            if ($entorno)    $lines[] = 'Entorno: ' . $entorno;

            $lines[] = ''; // separador entre experiencias
        }

        // Quitar último separador vacío
        while (!empty($lines) && end($lines) === '') array_pop($lines);

        $content = implode("\n", $lines);
        $xml = $this->applySectionContent($xml, 'EXPERIENCIA LABORAL', $content);
        $xml = $this->applySectionContent($xml, 'EXPERIENCIA PROFESIONAL', $content);
        $xml = $this->applySectionContent($xml, 'TRAYECTORIA PROFESIONAL', $content);
        $xml = $this->applySectionContent($xml, 'TRAYECTORIA', $content);
        $xml = $this->applySectionContent($xml, 'Experience', $content);
        return $xml;
    }

    // =========================================================================
    // PASO 2B: Construir y aplicar FORMACIÓN ACADÉMICA desde el JSON
    // =========================================================================

    private function applyFormacionAcademicaSection(string $xml): string
    {
        $items = $this->cv['formacion_academica'] ?? [];
        if (empty($items)) return $xml;

        $lines = [];
        foreach ($items as $item) {
            $fecha  = trim($item['fecha']  ?? '');
            $titulo = trim($item['titulo'] ?? '');
            $centro = trim($item['centro'] ?? '');

            if ($fecha)  $lines[] = $fecha;
            if ($titulo) $lines[] = $titulo;
            if ($centro) $lines[] = $centro;
            $lines[] = '';
        }
        while (!empty($lines) && end($lines) === '') array_pop($lines);

        $content = implode("\n", $lines);

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

        $lines = [];
        foreach ($items as $item) {
            $fecha  = trim($item['fecha']  ?? '');
            $titulo = trim($item['titulo'] ?? '');
            $centro = trim($item['centro'] ?? '');
            $horas  = trim($item['horas']  ?? '');

            if ($fecha)  $lines[] = $fecha;
            if ($titulo) $lines[] = $titulo;
            if ($centro) $lines[] = $centro;
            if ($horas)  $lines[] = 'Horas: ' . $horas;
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
            $xml = $this->applySectionContent($xml, 'CONOCIMIENTOS TECNICOS', $sectionContent);
            $xml = $this->applySectionContent($xml, 'COMPETENCIAS TECNICAS', $sectionContent);
            $xml = $this->applySectionContent($xml, 'COMPETENCIAS TÉCNICAS', $sectionContent);
            $xml = $this->applySectionContent($xml, 'Entornos/conocimientos técnicos', $sectionContent);
            $xml = $this->applySectionContent($xml, 'Technical Skills', $sectionContent);
        }

        return $xml;
    }

    // =========================================================================
    // PASO 2E: Campos específicos de Avanade (página 2 - Summary)
    // =========================================================================

    private function applyAvanadeSummaryFields(string $xml): string
    {
        $dp = $this->cv['datos_personales'] ?? [];
        $idiomas = $this->cv['idiomas'] ?? [];

        // "Nombre" → reemplazar texto completo del párrafo
        $nombre = trim($dp['nombre_completo'] ?? '');
        if ($nombre) {
            $xml = $this->replaceParaText($xml, 'Nombre', $nombre);
        }

        // "Madrid" → reemplazar con residencia
        $residencia = trim($dp['residencia'] ?? '');
        if ($residencia) {
            $xml = $this->replaceParaText($xml, 'Madrid', $residencia);
        }

        // Nivel de inglés: buscar idioma inglés y añadir nivel
        $nivelIngles = '';
        foreach ($idiomas as $idioma) {
            $name = mb_strtolower(trim($idioma['idioma'] ?? ''), 'UTF-8');
            if (in_array($name, ['inglés', 'ingles', 'english'])) {
                $nivelIngles = trim($idioma['nivel_general'] ?? '');
                break;
            }
        }
        if ($nivelIngles) {
            $xml = $this->appendToParaByText($xml, 'ngl', $nivelIngles);
        }

        // Disponibilidad entrevista
        $dispEntrev = trim($dp['disponibilidad_entrevista'] ?? '');
        if ($dispEntrev) {
            $xml = $this->appendToParaByText($xml, 'Disponibilidad entrevista', $dispEntrev);
        }

        // Disponibilidad incorporación
        $dispIncorp = trim($dp['disponibilidad_incorporacion'] ?? '');
        if ($dispIncorp) {
            $xml = $this->appendToParaByText($xml, 'Disponibilidad incorporaci', $dispIncorp);
        }

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
        foreach ($substitutions as $sub) {
            $type  = $sub['type']  ?? '';
            $value = trim($sub['value'] ?? '');
            if ($value === '') continue;

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

    private function cleanResidualPlaceholders(string $xml): string
    {
        $xml = preg_replace('/(<w:t[^>]*>)texto,\s*texto(<\/w:t>)/iu', '$1$2', $xml);
        $xml = preg_replace('/(<w:t[^>]*>)texto(<\/w:t>)/iu',           '$1$2', $xml);
        $xml = preg_replace('/(<w:t[^>]*>)\xc2\xa0(<\/w:t>)/u',         '$1$2', $xml);
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
    private function applySectionContent(string $xml, string $sectionTitle, string $content): string
    {
        if (empty($sectionTitle) || empty($content)) return $xml;

        // Normaliza: quita tildes, mayúsculas, colapsa espacios
        $normalize = function(string $s): string {
            $s = mb_strtoupper($s, 'UTF-8');
            $s = str_replace(
                ['Á','É','Í','Ó','Ú','Ü','Ñ'],
                ['A','E','I','O','U','U','N'],
                $s
            );
            return preg_replace('/\s+/', ' ', trim($s));
        };

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

        $contentXml = $this->textToParaXml($content);
        return substr($xml, 0, $headingEnd) . $contentXml . substr($xml, $headingEnd + $nextSectionStart);
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
     */
    private function textToParaXml(string $text): string
    {
        $lines = explode("\n", $text);
        $xml   = '';

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                $xml .= '<w:p><w:pPr><w:spacing w:before="60" w:after="60"/></w:pPr></w:p>';
                continue;
            }

            $esc    = htmlspecialchars($line, ENT_XML1, 'UTF-8');
            $isBold = (bool) preg_match(
                '/^(?:\d{2}\/\d{4}|\d{4}|Empresa:|Cargo:|Cliente:|Funciones:|Entorno:|Horas:|Categoría:|[A-ZÁÉÍÓÚÑ\s]{4,}$)/',
                $line
            );

            $rpr = $isBold
                ? '<w:rPr><w:b/><w:sz w:val="20"/></w:rPr>'
                : '<w:rPr><w:sz w:val="20"/></w:rPr>';

            $xml .= '<w:p>'
                  . '<w:pPr><w:spacing w:before="0" w:after="40"/></w:pPr>'
                  . '<w:r>' . $rpr . '<w:t xml:space="preserve">' . $esc . '</w:t></w:r>'
                  . '</w:p>';
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
        $lines[] = '=== CV Converter v11 - Log de sustituciones ===';
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
