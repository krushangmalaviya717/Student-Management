<?php
require_once __DIR__ . '/includes/student_auth.php';
require_once __DIR__ . '/includes/functions.php';
redirect(is_student_logged_in() ? 'public/student/dashboard.php' : 'public/student/login.php');
