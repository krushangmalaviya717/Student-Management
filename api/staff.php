<?php
/**
 * REST API: /api/staff.php
 */
require_once __DIR__ . '/ApiAuth.php';
require_once __DIR__ . '/../includes/services/StaffService.php';

$user = ApiAuth::requireAuth();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
        if ($id) {
            $staff = StaffService::getStaffById($id);
            if (!$staff) {
                ApiResponse::error('Staff not found', 404);
            }
            ApiResponse::json($staff, 200, 'Staff details fetched');
        } elseif (isset($_GET['kpis'])) {
            $kpis = StaffService::getKPIs();
            ApiResponse::json($kpis, 200, 'Staff KPIs fetched');
        } else {
            $status = $_GET['status'] ?? null;
            $batchId = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : null;
            $search = $_GET['search'] ?? null;
            $staffList = StaffService::getAllStaff($status, $batchId, $search);
            ApiResponse::json([
                'count' => count($staffList),
                'staff' => $staffList
            ], 200, 'Staff list fetched');
        }
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $action = $input['action'] ?? '';

        if ($action === 'create') {
            $newId = StaffService::createStaff($input, (int)$user['id']);
            ApiResponse::json(['id' => $newId], 201, 'Staff member created successfully');
        } elseif ($action === 'update') {
            $id = (int)($input['id'] ?? 0);
            StaffService::updateStaff($id, $input, (int)$user['id']);
            ApiResponse::json(null, 200, 'Staff member updated successfully');
        } elseif ($action === 'toggle_status') {
            $id = (int)($input['id'] ?? 0);
            StaffService::toggleStatus($id, (int)$user['id']);
            ApiResponse::json(null, 200, 'Staff status updated');
        } elseif ($action === 'delete') {
            $id = (int)($input['id'] ?? 0);
            StaffService::deleteStaff($id, (int)$user['id']);
            ApiResponse::json(null, 200, 'Staff member deleted');
        } else {
            ApiResponse::error('Invalid or missing action', 400);
        }
    } else {
        ApiResponse::error('Method not allowed', 405);
    }
} catch (Throwable $e) {
    ApiResponse::error($e->getMessage(), 400);
}
