<?php
/**
 * REST API: /api/holidays.php
 */
require_once __DIR__ . '/ApiAuth.php';
require_once __DIR__ . '/../includes/services/HolidayService.php';

$user = ApiAuth::requireAuth();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $filters = ['batch_id' => $_GET['batch_id'] ?? ''];
        $holidays = HolidayService::getHolidays($filters);
        ApiResponse::json([
            'count' => count($holidays),
            'holidays' => $holidays
        ], 200, 'Holidays fetched');
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $action = $input['action'] ?? 'single';
        $batchTarget = $input['batch_id'] ?? 'all';
        $reason = $input['reason'] ?? 'Holiday';

        if ($action === 'single') {
            $date = $input['holiday_date'] ?? '';
            if (empty($date)) {
                ApiResponse::error('holiday_date is required', 400);
            }
            $res = HolidayService::declareSingleHoliday($batchTarget, $date, $reason, (int)$user['id']);
            ApiResponse::json($res, 201, 'Single holiday processed');
        } elseif ($action === 'range') {
            $startDate = $input['start_date'] ?? '';
            $endDate = $input['end_date'] ?? '';
            $skipSundays = !empty($input['skip_sundays']);
            if (empty($startDate) || empty($endDate)) {
                ApiResponse::error('start_date and end_date are required', 400);
            }
            $res = HolidayService::declareHolidayRange($batchTarget, $startDate, $endDate, $reason, $skipSundays, (int)$user['id']);
            ApiResponse::json($res, 201, 'Holiday range processed');
        } elseif ($action === 'delete') {
            $id = (int)($input['id'] ?? 0);
            $deleted = HolidayService::deleteHoliday($id, (int)$user['id']);
            ApiResponse::json($deleted, 200, 'Holiday deleted and expiry dates rolled back');
        } else {
            ApiResponse::error('Invalid action', 400);
        }
    } else {
        ApiResponse::error('Method not allowed', 405);
    }
} catch (Throwable $e) {
    ApiResponse::error($e->getMessage(), 400);
}
