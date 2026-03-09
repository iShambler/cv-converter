<?php
/**
 * CV Converter - Database connection & CV storage
 */
class Database
{
    private static ?PDO $pdo = null;

    public static function getConnection(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES    => false,
                ]
            );
        }
        return self::$pdo;
    }

    /**
     * Create database and table if they don't exist
     */
    public static function init(): void
    {
        // First connect without database to create it if needed
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE `' . DB_NAME . '`');

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `cvs` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `nombre` VARCHAR(255) NOT NULL,
                `template` VARCHAR(100) NOT NULL,
                `archivo` VARCHAR(255) NOT NULL,
                `datos_json` LONGTEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Reset singleton so next call uses the proper DB
        self::$pdo = null;
    }

    /**
     * Save a generated CV
     */
    public static function saveCv(string $nombre, string $template, string $archivo, ?array $datosJson): int
    {
        $pdo = self::getConnection();
        $stmt = $pdo->prepare('INSERT INTO cvs (nombre, template, archivo, datos_json) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            $nombre,
            $template,
            $archivo,
            $datosJson ? json_encode($datosJson, JSON_UNESCAPED_UNICODE) : null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * List CVs with optional name and template filter
     */
    public static function listCvs(string $search = '', int $limit = 50, int $offset = 0, string $template = ''): array
    {
        $pdo = self::getConnection();

        $conditions = [];
        $params = [];
        if ($search !== '') {
            $conditions[] = 'nombre LIKE ?';
            $params[] = '%' . $search . '%';
        }
        if ($template !== '') {
            $conditions[] = 'template = ?';
            $params[] = $template;
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $stmt = $pdo->prepare("SELECT * FROM cvs $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM cvs $where");
        $stmtCount->execute($params);
        $total = (int) $stmtCount->fetchColumn();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Count CVs grouped by template
     */
    public static function countByTemplate(): array
    {
        $pdo = self::getConnection();
        $stmt = $pdo->query('SELECT template, COUNT(*) as total FROM cvs GROUP BY template ORDER BY template');
        $rows = $stmt->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[$row['template']] = (int) $row['total'];
        }
        return $result;
    }

    /**
     * Delete a CV by ID
     */
    public static function deleteCv(int $id): bool
    {
        $pdo = self::getConnection();

        // Get file path first to delete the file
        $stmt = $pdo->prepare('SELECT archivo FROM cvs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) return false;

        // Delete file if it exists
        $filePath = OUTPUT_PATH . $row['archivo'];
        if (file_exists($filePath)) {
            @unlink($filePath);
        }

        $stmt = $pdo->prepare('DELETE FROM cvs WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get a single CV by ID
     */
    public static function getCv(int $id): ?array
    {
        $pdo = self::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM cvs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
