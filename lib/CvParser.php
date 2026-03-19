<?php
/**
 * CV Converter - Parser de CV usando Anthropic Claude API
 * Soporta CVs de LinkedIn, Tecnoempleo, Infojobs y CVs manuales
 */

class CvParser
{
    private string $apiKey;
    private string $model;
    private string $apiUrl;

    public function __construct()
    {
        $this->apiKey = ANTHROPIC_API_KEY;
        $this->model  = ANTHROPIC_MODEL;
        $this->apiUrl = ANTHROPIC_API_URL;

        if (empty($this->apiKey)) {
            throw new Exception("API Key de Anthropic no configurada. Edita config.php");
        }
    }

    public function parse(string $input): array
    {
        if (str_starts_with($input, '__FILE__:')) {
            $filePath = substr($input, 9);
            $response = $this->callWithFile($filePath);
        } elseif (str_starts_with($input, '__IMAGE__:')) {
            $filePath = substr($input, 10);
            $response = $this->callWithFile($filePath);
        } else {
            $response = $this->callWithText($input);
        }

        $data = $this->extractJson($response);
        return $this->normalize($data);
    }

    // =========================================================================
    // PROMPTS
    // =========================================================================

    private function getSystemPrompt(): string
    {
        return <<<'SYSTEM'
Eres un sistema experto en extracción de datos de currículums vitae.
Los CVs pueden venir de distintas fuentes: LinkedIn, Tecnoempleo, Infojobs, o ser documentos manuales.

REGLAS ESTRICTAS:
1. Extrae SOLO información que aparezca LITERALMENTE en el documento. NUNCA inventes datos.
2. Si un campo no existe en el documento, devuelve string vacío "" o array vacío [].
3. Copia nombres, empresas y títulos EXACTAMENTE como aparecen.
4. Fechas: usa formato "MM/AAAA". Si solo hay año usa "AAAA". Si dice "Actualidad" o "Present" usa "Actualidad".
5. Experiencia ordenada de más reciente a más antigua.
6. Responde ÚNICAMENTE con el JSON pedido, sin markdown, sin explicaciones, sin backticks.

GUÍA POR FUENTE:
- LinkedIn PDF: el nombre suele estar en la primera línea grande. La sección "Acerca de" es el perfil profesional. Las fechas aparecen como "ene. 2023 - actualidad", conviértelas a MM/AAAA.
- Tecnoempleo: tiene secciones "Experiencias profesionales", "Estudios", "Perfil tecnológico" (=conocimientos técnicos), "Idiomas". El campo "Situación laboral" puede ser "Desempleado", "Empleado", etc. Los campos "Disp. Viajes", "Disp. Cambio de Residencia" ignóralos. "sinpref" significa sin preferencia, déjalo vacío.
- Infojobs: formato especial. La estructura es:
  * Primero aparecen datos personales (Edad, Dirección, Nacionalidad, etc.)
  * "Killer questions" con preguntas y respuestas del candidato — IGNÓRALAS para datos del CV
  * "Experiencias" con bloques: FECHA inicio - FECHA fin (duración) / CARGO / EMPRESA | descripción / skills separados con salto de línea
  * "Estudios" con bloques: FECHA inicio - FECHA fin (duración) / TITULO / CENTRO / skills
  * "Conocimientos" con lista de skills
  * "Idiomas" con nivel
  * IMPORTANTE para InfoJobs:
    - Las habilidades/skills listadas DENTRO de cada experiencia van en "entorno_tecnologico"
    - Las habilidades en sección "Conocimientos" van en conocimientos_tecnicos.otros (o campo apropiado)
    - Para perfiles NO IT (diseño, audiovisual, etc.) todos los conocimientos técnicos van en "otros"
    - El campo "Categoría y subcategoría" de InfoJobs NO es la empresa ni el cargo, IGNÓRALO
    - "Nivel Especialista", "Nivel Empleado/a", "A quién reporta" son metadatos de InfoJobs, IGNÓRALOS
    - Si aparece "Menos de 1 mes" como duración, es una entrada de formación/estudio dudosa — evalúa si es real
    - La fecha de nacimiento puede aparecer como "Edad: 28 años (01/11/1997)" — extrae solo la fecha en formato dd/mm/aaaa
    - El nombre aparece en negrita grande junto al puesto actual: "Ailyn Garrido / Content creator en Voces Imaginarias"
    - Las habilidades dentro de experiencias/estudios en InfoJobs van separadas por espacios o saltos de línea como lista plana

CAMPO "residencia":
- Extrae SOLO la ciudad o provincia. NUNCA la calle, número, piso ni código postal.
- "Calle Jardín 5, 2B - 29010 Málaga" → "Málaga"
- "Rosario, Málaga" → "Málaga"  (Rosario es un barrio/calle, Málaga es la ciudad)
- "C/ Rosario 12, Sevilla" → "Sevilla"
- "28001 Madrid" → "Madrid"
- "Madrid, España" → "Madrid"
- Regla: la ciudad/provincia es el nombre geográfico principal, nunca el nombre de calle o barrio.

CAMPO "funciones" de experiencia:
- Extrae SOLO las funciones/responsabilidades reales del puesto.
- NO incluyas texto de perfil personal, frases como "Soy trabajador", "He cursado...", etc.
- Si hay texto mezclado de perfil con funciones, sepáralos: el perfil va a "perfil_profesional", las funciones a "funciones".

CAMPO "entorno_tecnologico":
- Incluye herramientas, tecnologías y software mencionados en esa experiencia concreta.
- Para perfiles NO tecnológicos (audiovisual, diseño, marketing...) incluye igualmente el software específico (Adobe Premiere, etc.).

CAMPO "conocimientos_tecnicos":
- Para perfiles IT: lenguajes, frameworks, BBDDs, cloud, SO.
- Para perfiles NO IT (audiovisual, diseño, etc.): pon todo en "otros" (software creativo, herramientas, etc.). Deja vacíos lenguajes_programacion, bases_datos si no aplican.

IDIOMAS:
- nivel_general: usa el nivel tal como aparece ("Nativo", "Alto", "Avanzado", "Negociación", "B2", etc.)
- lectura / escritura / expresion_oral: extráelos si aparecen separados (como en Tecnoempleo). Si no aparecen separados, déjalos vacíos y pon todo en nivel_general.
- NO pongas "IDIOMAS" como nombre de idioma.

DISPONIBILIDAD:
- Si dice "sinpref", "Sin especificar", "Sin preferencia" → deja vacío "".
- Si dice "Inmediata", "Disponibilidad total", un número de días → cópialo tal cual.
SYSTEM;
    }

    private function getJsonStructure(): string
    {
        return <<<'JSON'
{
    "datos_personales": {
        "nombre_completo": "",
        "fecha_nacimiento": "",
        "email": "",
        "telefono": "",
        "residencia": "",
        "nacionalidad": "",
        "dni": "",
        "linkedin": "",
        "disponibilidad_incorporacion": "",
        "disponibilidad_entrevista": "",
        "situacion_laboral": ""
    },
    "perfil_profesional": "",
    "experiencia_laboral": [
        {
            "fecha_inicio": "",
            "fecha_fin": "",
            "empresa": "",
            "cliente": "",
            "cargo": "",
            "categoria": "",
            "funciones": "",
            "entorno_tecnologico": ""
        }
    ],
    "formacion_academica": [
        {
            "fecha": "",
            "titulo": "",
            "centro": ""
        }
    ],
    "formacion_complementaria": [
        {
            "fecha": "",
            "titulo": "",
            "centro": "",
            "horas": ""
        }
    ],
    "certificaciones": [
        {
            "nombre": "",
            "fecha": ""
        }
    ],
    "conocimientos_tecnicos": {
        "lenguajes_programacion": "",
        "sistemas_operativos": "",
        "bases_datos": "",
        "frameworks": "",
        "cloud": "",
        "otros": ""
    },
    "idiomas": [
        {
            "idioma": "",
            "nivel_general": "",
            "lectura": "",
            "escritura": "",
            "expresion_oral": ""
        }
    ],
    "soft_skills": ""
}
JSON;
    }

    // =========================================================================
    // API CALLS
    // =========================================================================

    private function callWithFile(string $filePath): string
    {
        if (!file_exists($filePath)) {
            throw new Exception("Archivo no encontrado: $filePath");
        }

        $ext           = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $base64        = base64_encode(file_get_contents($filePath));
        $jsonStructure = $this->getJsonStructure();
        $userText      = "Analiza este currículum vitae y extrae todos los datos que aparecen LITERALMENTE. Devuelve ÚNICAMENTE el siguiente JSON relleno, sin markdown:\n\n$jsonStructure";

        if ($ext === 'pdf') {
            $contentParts = [
                ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $base64]],
                ['type' => 'text', 'text' => $userText],
            ];
        } else {
            $mime = match ($ext) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png'         => 'image/png',
                'webp'        => 'image/webp',
                'gif'         => 'image/gif',
                default       => 'image/jpeg',
            };
            $contentParts = [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $base64]],
                ['type' => 'text', 'text' => $userText],
            ];
        }

        $payload = json_encode([
            'model'      => $this->model,
            'max_tokens' => 16384,
            'system'     => $this->getSystemPrompt(),
            'messages'   => [['role' => 'user', 'content' => $contentParts]],
        ]);

        return $this->doRequest($payload);
    }

    private function callWithText(string $cvText): string
    {
        $jsonStructure = $this->getJsonStructure();
        $userPrompt    = "Analiza este currículum vitae y extrae todos los datos que aparecen LITERALMENTE. Devuelve ÚNICAMENTE el siguiente JSON relleno, sin markdown:\n\n$jsonStructure\n\nTEXTO DEL CV:\n---\n$cvText\n---";

        $payload = json_encode([
            'model'      => $this->model,
            'max_tokens' => 16384,
            'system'     => $this->getSystemPrompt(),
            'messages'   => [['role' => 'user', 'content' => $userPrompt]],
        ]);

        return $this->doRequest($payload);
    }

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

        if ($error) throw new Exception("Error de conexión con Claude: $error");

        $body = json_decode($response, true);

        if ($httpCode !== 200) {
            $msg = $body['error']['message'] ?? $response;
            throw new Exception("Error de Claude (HTTP $httpCode): $msg");
        }

        if (!isset($body['content'][0]['text'])) {
            throw new Exception("Respuesta inesperada de Claude");
        }

        if (($body['stop_reason'] ?? '') === 'max_tokens') {
            throw new Exception("La respuesta de Claude se truncó (CV demasiado extenso). Intenta con un CV más corto.");
        }

        return $body['content'][0]['text'];
    }

    private function extractJson(string $response): array
    {
        $response = trim($response);
        $response = preg_replace('/^```json\s*/i', '', $response);
        $response = preg_replace('/^```\s*/i',     '', $response);
        $response = preg_replace('/\s*```$/',      '', $response);
        $response = trim($response);

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            if (preg_match('/\{[\s\S]*\}/s', $response, $match)) {
                $data = json_decode($match[0], true);
            }
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("JSON inválido de Claude: " . json_last_error_msg());
            }
        }
        return $data;
    }

    // =========================================================================
    // NORMALIZE — limpieza post-extracción independiente de la fuente
    // =========================================================================

    private function normalize(array $data): array
    {
        $dp = $data['datos_personales'] ?? [];

        // Limpiar disponibilidad: "sinpref", "Sin especificar", "s/p" → ""
        foreach (['disponibilidad_incorporacion', 'disponibilidad_entrevista'] as $field) {
            $val = $dp[$field] ?? '';
            if (preg_match('/^(sinpref|sin\s*pref|sin\s*especif|s\/p|n\/a)$/i', trim($val))) {
                $dp[$field] = '';
            }
        }

        // Limpiar situación laboral igual
        $sl = $dp['situacion_laboral'] ?? '';
        if (preg_match('/^(sinpref|sin\s*pref|sin\s*especif|s\/p|n\/a)$/i', trim($sl))) {
            $dp['situacion_laboral'] = '';
        }

        // Limpiar residencia: asegurar que sea solo ciudad aunque Claude traiga algo más
        $dp['residencia'] = $this->extractCity($dp['residencia'] ?? '');

        $data['datos_personales'] = $dp;

        // Limpiar idiomas: eliminar entradas inválidas
        $idiomas = $data['idiomas'] ?? [];
        $idiomas = array_values(array_filter($idiomas, function ($i) {
            $nombre = trim($i['idioma'] ?? '');
            return !empty($nombre) && strtoupper($nombre) !== 'IDIOMAS';
        }));

        foreach ($idiomas as &$idioma) {
            $idioma['nivel_general']  = $this->normalizeLanguageLevel($idioma['nivel_general']  ?? '');
            $idioma['lectura']        = $this->normalizeLanguageLevel($idioma['lectura']        ?? '');
            $idioma['escritura']      = $this->normalizeLanguageLevel($idioma['escritura']      ?? '');
            $idioma['expresion_oral'] = $this->normalizeLanguageLevel($idioma['expresion_oral'] ?? '');
        }
        unset($idioma);
        $data['idiomas'] = $idiomas;

        // Experiencia laboral
        $experiencias = $data['experiencia_laboral'] ?? [];
        foreach ($experiencias as &$exp) {
            $fin = trim($exp['fecha_fin'] ?? '');
            if (preg_match('/^(present|presente|current|actualmente|actualidad|hoy|today)$/i', $fin)) {
                $exp['fecha_fin'] = 'Actualidad';
            }
            $exp['funciones']    = $this->cleanFunciones($exp['funciones'] ?? '');
            $exp['fecha_inicio'] = $this->normalizeDate($exp['fecha_inicio'] ?? '');
            $exp['fecha_fin']    = $exp['fecha_fin'] === 'Actualidad'
                ? 'Actualidad'
                : $this->normalizeDate($exp['fecha_fin'] ?? '');
        }
        unset($exp);
        $data['experiencia_laboral'] = $experiencias;

        // Formación
        foreach (['formacion_academica', 'formacion_complementaria'] as $section) {
            $items = $data[$section] ?? [];
            $filtered = [];
            foreach ($items as $item) {
                $item['fecha'] = $this->normalizeDateRange($item['fecha'] ?? '');
                // Filtrar entradas claramente erróneas de InfoJobs:
                // - ESO o equivalente con fecha de inicio = fin (ej: 01/2026 - 01/2026 o solo 01/2026)
                // - Títulos de educación básica con duración cero o fecha futura reciente
                $titulo = strtolower(trim($item['titulo'] ?? ''));
                $fecha  = trim($item['fecha'] ?? '');
                $isBasicEdu = preg_match('/educación\s+secundaria|educacion\s+secundaria|eso|bachillerato\s+elemental|primaria/i', $titulo);
                $isSameDate = preg_match('/^(\d{2}\/\d{4})\s*-\s*\1$/', $fecha) || // MM/AAAA - MM/AAAA iguales
                               preg_match('/^(\d{2}\/\d{4})$/', $fecha);         // solo una fecha (sin rango)
                if ($isBasicEdu && $isSameDate) continue; // descartar
                $filtered[] = $item;
            }
            unset($item);
            $data[$section] = $filtered;
        }

        // Mover certificaciones a formacion_complementaria si esta vacia
        // (LinkedIn las pone en "Certifications" pero en la plantilla van en Formación Complementaria)
        $certs = $data['certificaciones'] ?? [];
        $formComp = $data['formacion_complementaria'] ?? [];
        if (!empty($certs) && empty($formComp)) {
            foreach ($certs as $cert) {
                $titulo = trim($cert['nombre'] ?? '');
                if (empty($titulo)) continue;
                $formComp[] = [
                    'fecha'  => $cert['fecha'] ?? '',
                    'titulo' => $titulo,
                    'centro' => '',
                    'horas'  => '',
                ];
            }
            $data['formacion_complementaria'] = $formComp;
            $data['certificaciones'] = []; // ya migradas, evitar duplicados
        }

        return $data;
    }

    /**
     * Extrae solo la ciudad/provincia de un string de dirección.
     *
     * Maneja casos como:
     *  "Rosario, Málaga"              → "Málaga"
     *  "C/ Rosario 12, Sevilla"       → "Sevilla"
     *  "Calle Jardín 5 - 29010 Málaga"→ "Málaga"
     *  "28001 Madrid"                 → "Madrid"
     *  "Madrid, España"               → "Madrid"
     *  "Madrid"                       → "Madrid"  (ya es ciudad, no tocar)
     *  "Sevilla/España"               → "Sevilla"
     */
    private function extractCity(string $residencia): string
    {
        $r = trim($residencia);
        if (empty($r)) return '';

        // Si contiene "/" (ej: "Sevilla/España") → quedarse con la primera parte
        if (strpos($r, '/') !== false) {
            $parts = explode('/', $r);
            $r = trim($parts[0]);
        }

        // Si contiene ", " puede ser "Barrio, Ciudad" o "Ciudad, País"
        // Heurística: si la última parte tras la coma es un país conocido, usar la penúltima
        $countries = ['España', 'Spain', 'France', 'Francia', 'Germany', 'Alemania',
                      'Portugal', 'Italy', 'Italia', 'UK', 'United Kingdom'];
        if (strpos($r, ',') !== false) {
            $parts = array_map('trim', explode(',', $r));
            $last  = end($parts);
            // Si el último token es un país, usar el penúltimo
            foreach ($countries as $country) {
                if (strcasecmp($last, $country) === 0) {
                    array_pop($parts);
                    $last = end($parts);
                    break;
                }
            }
            // Ahora el último token debería ser la ciudad
            // Si tiene número o es muy corto (calle) y hay más partes, descartarlo
            $city = end($parts);
            // Si parece calle (contiene número, empieza por "C/", "Calle", etc.) tomar el siguiente
            if (preg_match('/^\d|\bC\//i', $city) && count($parts) > 1) {
                $city = $parts[count($parts) - 2];
            }
            $r = $city;
        }

        // Si contiene código postal (5 dígitos) + ciudad: extraer ciudad
        if (preg_match('/\b\d{5}\s+([A-ZÁÉÍÓÚÑa-záéíóúñ][^\d,\-\n]+?)(?:\s*[-,]|$)/u', $r, $m)) {
            return trim($m[1]);
        }

        // Eliminar texto después de " - " (ej: "Málaga - España")
        if (strpos($r, ' - ') !== false) {
            $r = trim(explode(' - ', $r)[0]);
        }

        // Si todavía queda algo que parece dirección (número + calle), limpiar
        // Ej: "Rosario Málaga" → si la primera palabra parece nombre de calle, usar la última
        $words = explode(' ', $r);
        if (count($words) >= 2) {
            // Lista de palabras que indican que es un nombre de calle/barrio
            $streetWords = ['Calle', 'Avenida', 'Avda', 'Plaza', 'Paseo', 'C/', 'Av.', 'Cl.', 'Urb.', 'Urbanización'];
            foreach ($streetWords as $sw) {
                if (stripos($r, $sw) === 0) {
                    // Es una dirección completa — tomar la última palabra significativa
                    return end($words);
                }
            }
        }

        return $r;
    }

    private function normalizeDate(string $date): string
    {
        if (empty($date)) return '';

        $meses = [
            'ene' => '01', 'feb' => '02', 'mar' => '03', 'abr' => '04',
            'may' => '05', 'jun' => '06', 'jul' => '07', 'ago' => '08',
            'sep' => '09', 'oct' => '10', 'nov' => '11', 'dic' => '12',
            'jan' => '01', 'apr' => '04', 'aug' => '08',
            'enero' => '01', 'febrero' => '02', 'marzo' => '03', 'abril' => '04',
            'mayo' => '05', 'junio' => '06', 'julio' => '07', 'agosto' => '08',
            'septiembre' => '09', 'octubre' => '10', 'noviembre' => '11', 'diciembre' => '12',
            'january' => '01', 'february' => '02', 'march' => '03', 'april' => '04',
            'june' => '06', 'july' => '07', 'september' => '09', 'october' => '10',
            'november' => '11', 'december' => '12',
        ];

        if (preg_match('/^\d{2}\/\d{4}$/', $date) || preg_match('/^\d{4}$/', $date)) {
            return $date;
        }

        if (preg_match('/^([a-záéíóúñ]+)\.?\s+(\d{4})$/i', trim($date), $m)) {
            $mes = strtolower(rtrim($m[1], '.'));
            if (isset($meses[$mes])) {
                return $meses[$mes] . '/' . $m[2];
            }
        }

        if (preg_match('/^(\d{4})$/', trim($date), $m)) {
            return $m[1];
        }

        return $date;
    }

    private function normalizeDateRange(string $date): string
    {
        if (empty($date)) return '';

        if (strpos($date, ' - ') !== false) {
            [$start, $end] = explode(' - ', $date, 2);
            $s = $this->normalizeDate(trim($start));
            $e = $this->normalizeDate(trim($end));
            return $s && $e ? "$s - $e" : $date;
        }

        return $this->normalizeDate($date) ?: $date;
    }

    private function normalizeLanguageLevel(string $level): string
    {
        if (empty($level)) return '';

        $map = [
            'native'             => 'Nativo',
            'nativo'             => 'Nativo',
            'mother tongue'      => 'Nativo',
            'lengua materna'     => 'Nativo',
            'excelente'          => 'Nativo',
            'a1'                 => 'A1 - Básico',
            'a2'                 => 'A2 - Elemental',
            'b1'                 => 'B1 - Intermedio',
            'b2'                 => 'B2 - Intermedio Alto',
            'c1'                 => 'C1 - Avanzado',
            'c2'                 => 'C2 - Maestría',
            'basic'              => 'Básico',
            'básico'             => 'Básico',
            'bajo'               => 'Básico',
            'elementary'         => 'Elemental',
            'elemental'          => 'Elemental',
            'intermediate'       => 'Intermedio',
            'intermedio'         => 'Intermedio',
            'medio'              => 'Intermedio',
            'upper intermediate' => 'Intermedio Alto',
            'advanced'           => 'Avanzado',
            'avanzado'           => 'Avanzado',
            'alto'               => 'Avanzado',
            'high'               => 'Avanzado',
            'fluent'             => 'Fluido',
            'fluido'             => 'Fluido',
            'profesional'        => 'Profesional',
            'professional'       => 'Profesional',
            'negociación'        => 'Negociación',
            'negotiation'        => 'Negociación',
            'full professional'  => 'Profesional',
        ];

        $key = strtolower(trim($level));
        return $map[$key] ?? $level;
    }

    private function cleanFunciones(string $funciones): string
    {
        if (empty($funciones)) return '';

        $patrones = [
            '/He\s+cursado[^\.]*\./i',
            '/Llevo\s+años[^\.]*\./i',
            '/Soy\s+trabajador[^\.]*\./i',
            '/cumplo\s+los\s+plazos[^\.]*\./i',
            '/Me\s+considero[^\.]*\./i',
            '/Actualmente\s+estoy\s+buscando[^\.]*\./i',
            '/Estoy\s+interesado[^\.]*\./i',
            '/Busco[^\.]*oportunidad[^\.]*\./i',
        ];

        foreach ($patrones as $patron) {
            $funciones = preg_replace($patron, '', $funciones);
        }

        $funciones = preg_replace('/\s{2,}/', ' ', $funciones);
        $funciones = preg_replace('/\.\s*\./', '.', $funciones);

        return trim($funciones);
    }
}
