<?php
/**
 * CV Converter - API para gestionar CVs guardados
 *
 * GET    ?action=list&template=X&search=...&page=1  → listar CVs
 * GET    ?action=counts                             → totales por plantilla
 * POST   ?action=save                               → guardar CV
 * POST   ?action=delete&id=X                        → eliminar CV
 * GET    ?action=init                               → inicializar BD
 */

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../lib/Database.php';

    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    switch ($action) {

        case 'init':
            Database::init();
            echo json_encode(['success' => true, 'message' => 'Base de datos inicializada correctamente']);
            break;

        case 'counts':
            $counts = Database::countByTemplate();
            echo json_encode(['success' => true, 'counts' => $counts]);
            break;

        case 'list':
            $search   = trim($_GET['search'] ?? '');
            $template = trim($_GET['template'] ?? '');
            $page     = max(1, (int)($_GET['page'] ?? 1));
            $limit    = min(500, max(1, (int)($_GET['limit'] ?? 5)));
            $offset   = ($page - 1) * $limit;

            $result = Database::listCvs($search, $limit, $offset, $template);
            echo json_encode([
                'success' => true,
                'data'    => $result['rows'],
                'total'   => $result['total'],
                'page'    => $page,
                'pages'   => max(1, ceil($result['total'] / $limit)),
            ]);
            break;

        case 'save':
            if ($method !== 'POST') throw new Exception('Método no permitido');

            $nombre  = trim($_POST['nombre'] ?? '');
            $template = trim($_POST['template'] ?? '');
            $archivo  = trim($_POST['archivo'] ?? '');
            $datosJson = isset($_POST['datos_json']) ? json_decode($_POST['datos_json'], true) : null;

            if ($nombre === '' || $template === '' || $archivo === '') {
                throw new Exception('Faltan campos obligatorios: nombre, template, archivo');
            }

            $id = Database::saveCv($nombre, $template, $archivo, $datosJson);
            echo json_encode(['success' => true, 'id' => $id, 'message' => 'CV guardado correctamente']);
            break;

        case 'delete':
            if ($method !== 'POST' && $method !== 'DELETE') {
                throw new Exception('Método no permitido');
            }

            $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('ID no válido');

            $deleted = Database::deleteCv($id);
            if (!$deleted) throw new Exception('CV no encontrado');

            echo json_encode(['success' => true, 'message' => 'CV eliminado correctamente']);
            break;

        default:
            throw new Exception('Acción no válida');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
}
