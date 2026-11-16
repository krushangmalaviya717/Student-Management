<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/student_auth.php';

student_logout();
redirect('public/student/login.php');
exit;
