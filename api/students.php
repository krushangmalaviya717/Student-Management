<?php
/**
 * REST API: /api/students.php
 */
require_once __DIR__ . '/ApiAuth.php';
require_once __DIR__ . '/../includes/services/StudentService.php';

$user = ApiAuth::requireAuth();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
        if ($id) {
            $student = StudentService::getStudentById($id);
            if (!$student) {
                ApiResponse::error('Student not found', 404);
            }
            ApiResponse::json($student, 200, 'Student details fetched');
        } else {
            $filters = [
                'q' => $_GET['q'] ?? '',
                'status' => $_GET['status'] ?? 'active_students',
                'batch_id' => $_GET['batch_id'] ?? '',
                'limit' => (int)($_GET['limit'] ?? 100),
            ];
            $students = StudentService::getStudents($filters);
            ApiResponse::json([
                'count' => count($students),
                'students' => $students
            ], 200, 'Students fetched');
        }
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $action = $input['action'] ?? '';

        if ($action === 'mark_completed') {
            $id = (int)($input['student_id'] ?? 0);
            StudentService::markCourseCompleted($id, (int)$user['id']);
            ApiResponse::json(null, 200, 'Student marked as course completed');
        } elseif ($action === 'reopen_course') {
            $id = (int)($input['student_id'] ?? 0);
            StudentService::reopenCourse($id, (int)$user['id']);
            ApiResponse::json(null, 200, 'Student course reopened to active');
        } else {
            ApiResponse::error('Invalid or missing action', 400);
        }
    } else {
        ApiResponse::error('Method not allowed', 405);
    }
} catch (Throwable $e) {
    ApiResponse::error($e->getMessage(), 400);
}
