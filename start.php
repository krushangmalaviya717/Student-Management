<?php
// Auto DB creation and server starter script
require_once __DIR__ . '/config/config.php';

echo "\n=======================================================\n";
echo "   Devangi Sewing Classes - Student Management System  \n";
echo "=======================================================\n\n";

// Step 1: Ensure Database Exists & Initialize Schema
try {
    $dsnNoDb = 'mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET;
    $pdoInit = new PDO($dsnNoDb, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $pdoInit->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = db();

    $tables = $pdo->query("SHOW TABLES LIKE 'users'")->fetchAll();
    if (count($tables) === 0) {
        $sql = file_get_contents(__DIR__ . '/database/schema.sql');
        $sql = preg_replace('/^--.*$/m', '', $sql);
        $statements = array_filter(array_map('trim', explode(";\n", $sql)));
        foreach ($statements as $statement) {
            $statement = trim($statement, "; \t\n\r");
            if ($statement === '') continue;
            $pdo->exec($statement);
        }

        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare(
            "INSERT INTO users (role_id, name, email, password_hash, status)
             VALUES ((SELECT id FROM roles WHERE name = 'super_admin'), 'Super Admin', 'admin@example.com', ?, 'active')"
        );
        $stmt->execute([$hash]);
    }
} catch (Throwable $e) {
    // Ignore db notices
}

$localIp = getHostByName(getHostName());

echo "-------------------------------------------------------\n";
echo "🚀 LOCAL SERVER RUNNING:\n";
echo "   💻 On this PC: http://localhost:8000\n";
if ($localIp && $localIp !== '127.0.0.1') {
    echo "   📱 On Mobile (Same Wi-Fi): http://{$localIp}:8000\n";
}
echo "-------------------------------------------------------\n\n";

echo "🔑 ADMIN LOGIN DETAILS:\n";
echo "   Email:    admin@example.com\n";
echo "   Password: admin123\n\n";

echo "🔗 1. ADMIN PANEL (એડમિન સાઈડ):\n";
echo "   💻 PC:     http://localhost:8000/public/login.php\n";
if ($localIp && $localIp !== '127.0.0.1') {
    echo "   📱 Mobile: http://{$localIp}:8000/public/login.php\n";
}
echo "\n";

echo "🔗 2. STUDENT REGISTRATION (સ્ટુડન્ટ સાઈડ):\n";
echo "   💻 PC:     http://localhost:8000/public/student_register.php\n";
if ($localIp && $localIp !== '127.0.0.1') {
    echo "   📱 Mobile: http://{$localIp}:8000/public/student_register.php\n";
}
echo "\n";

echo "=======================================================\n";
echo "Press Ctrl+C in CMD to stop the server.\n";
echo "=======================================================\n\n";

// Start PHP Server on all interfaces (0.0.0.0) so devices on the same Wi-Fi can connect
passthru('C:\\xampp\\php\\php.exe -S 0.0.0.0:8000');
