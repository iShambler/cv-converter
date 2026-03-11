<?php
/**
 * CV Converter - Extractor / Router de archivos CV
 * 
 * Para PDFs e imágenes: devuelve la ruta del archivo para enviar directo a GPT-4o
 * Para DOCX/DOC/TXT: extrae texto
 */

class TextExtractor
{
    /**
     * Procesa un archivo CV:
     * - PDF/imágenes → devuelve __FILE__:ruta (se envía directo a OpenAI)
     * - DOCX/DOC/TXT → extrae y devuelve texto plano
     */
    public static function extract(string $filePath): string
    {
        if (!file_exists($filePath)) {
            throw new Exception("Archivo no encontrado: $filePath");
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        switch ($ext) {
            // Archivos que se envían DIRECTAMENTE a GPT-4o (sin extraer texto)
            case 'pdf':
            case 'jpg':
            case 'jpeg':
            case 'png':
            case 'webp':
                return '__FILE__:' . $filePath;

            // Archivos de los que extraemos texto
            case 'docx':
                return self::extractFromDocx($filePath);
            case 'doc':
                return self::extractFromDoc($filePath);
            case 'txt':
                return file_get_contents($filePath);
            case 'rtf':
                return self::extractFromRtf($filePath);
            default:
                throw new Exception("Formato no soportado: $ext");
        }
    }

    /**
     * Extrae texto de un DOCX
     */
    private static function extractFromDocx(string $filePath): string
    {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new Exception("No se pudo abrir el archivo DOCX");
        }

        $text = '';
        $content = $zip->getFromName('word/document.xml');
        if ($content) {
            $parts = preg_split('/(<\/w:p>)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
            foreach ($parts as $part) {
                if ($part === '</w:p>') {
                    $text .= "\n";
                } else {
                    preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', $part, $m);
                    if (!empty($m[1])) {
                        $text .= implode('', $m[1]);
                    }
                }
            }
        }

        $zip->close();
        return trim($text);
    }

    /**
     * Extrae texto de DOC
     */
    private static function extractFromDoc(string $filePath): string
    {
        // Intentar herramientas del sistema
        $safeFile = escapeshellarg($filePath);
        $output = self::tryCommand("antiword $safeFile");
        if ($output) return $output;
        $output = self::tryCommand("catdoc $safeFile");
        if ($output) return $output;

        // Extracción bruta de strings UTF-16LE
        $content = file_get_contents($filePath);
        $text = '';
        $len = strlen($content);
        $buffer = '';
        for ($i = 0; $i < $len - 1; $i += 2) {
            $char = ord($content[$i]);
            $high = ord($content[$i + 1]);
            if ($high === 0 && $char >= 32 && $char < 127) {
                $buffer .= chr($char);
            } elseif ($high === 0 && ($char === 10 || $char === 13)) {
                if (strlen($buffer) > 2) $text .= $buffer . "\n";
                $buffer = '';
            } else {
                if (strlen($buffer) > 2) $text .= $buffer . " ";
                $buffer = '';
            }
        }
        if (strlen($buffer) > 2) $text .= $buffer;

        if (strlen(trim($text)) > 50) return trim($text);

        // Si no se puede extraer texto, enviar como archivo
        return '__FILE__:' . $filePath;
    }

    /**
     * Extrae texto de RTF
     */
    private static function extractFromRtf(string $filePath): string
    {
        $content = file_get_contents($filePath);
        $content = preg_replace('/\{\\\\[^{}]*\}/', '', $content);
        $content = preg_replace('/\\\\[a-z]+[-]?[0-9]*[ ]?/', '', $content);
        $content = str_replace(['{', '}', '\\'], '', $content);
        return trim($content);
    }

    private static function tryCommand(string $cmd): string|false
    {
        $output = [];
        $returnCode = -1;
        @exec($cmd . ' 2>NUL', $output, $returnCode);
        if ($returnCode === 0 && !empty($output)) {
            $text = implode("\n", $output);
            if (strlen(trim($text)) > 10) return trim($text);
        }
        return false;
    }
}
