<?php
/**
 * REST API: /api/batches.php
 */
require_once __DIR__ . '/ApiAuth.php';
require_once __DIR__ . '/../includes/services/BatchService.php';

$user = ApiAuth::requireAuth();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
        if ($id) {
            $batch = BatchService::getBatchById($id);
            if (!$batch) {
                ApiResponse::error('Batch not found', 404);
            }
            $batch['active_student_count'] = BatchService::getActiveStudentCount($id);
            $batch['waiting_student_count'] = BatchService::getWaitingStudentCount($id);
            ApiResponse::json($batch, 200, 'Batch details fetched');
        } elseif (isset($_GET['stats'])) {
            $stats = BatchService::getBatchStats();
            ApiResponse::json($stats, 200, 'Batch statistics fetched');
        } else {
            $activeOnly = isset($_GET['active_only']) && (bool)$_GET['active_only'];
            $batches = BatchService::getAllBatches($activeOnly);
            foreach ($batches as &$b) {
                $b['active_students'] = BatchService::getActiveStudentCount((int)$b['id']);
            }
            unset($b);
            ApiResponse::json([
                'count' => count($batches),
                'batches' => $batches
            ], 200, 'Batches fetched');
        }
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $action = $input['action'] ?? '';

        if ($action === 'create') {
            $newId = BatchService::createBatch($input, (int)$user['id']);
            ApiResponse::json(['id' => $newId], 201, 'Batch created successfully');
        } elseif ($action === 'toggle_status') {
            $id = (int)($input['id'] ?? 0);
            BatchService::toggleStatus($id, (int)$user['id']);
            ApiResponse::json(null, 200, 'Batch status updated');
        } elseif ($action === 'delete') {
            $id = (int)($input['id'] ?? 0);
            $res = BatchService::deleteBatch($id, (int)$user['id']);
            ApiResponse::json($res, 200, $res['message']);
        } else {
            ApiResponse::error('Invalid or missing action', 400);
        }
    } else {
        ApiResponse::error('Method not allowed', 405);
    }
} catch (Throwable $e) {
    ApiResponse::error($e->getMessage(), 400);
}
