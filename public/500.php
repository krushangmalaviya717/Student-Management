<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Server Error - Devangi Sewing Classes</title>
    <style>
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #F8FAFC;
            color: #0F172A;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }
        .error-card {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 10px;
            padding: 36px 32px;
            max-width: 460px;
            width: 100%;
            text-align: center;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
        }
        .icon {
            margin-bottom: 14px;
            color: #D97706;
        }
        h1 {
            font-size: 19px;
            color: #0F172A;
            margin: 0 0 10px;
            font-weight: 700;
        }
        p {
            font-size: 13.5px;
            color: #64748B;
            line-height: 1.6;
            margin: 0 0 22px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 36px;
            background: #4F46E5;
            color: #FFFFFF;
            padding: 0 20px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            font-size: 13.5px;
            transition: background 0.15s ease;
        }
        .btn:hover {
            background: #4338CA;
        }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="icon">
            <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
        </div>
        <h1>Something went wrong</h1>
        <p>An unexpected server error occurred. Please try again in a few moments or contact your system administrator.</p>
        <?php
        $baseUrl = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
        if (isset($_SESSION['user'])) {
            $homeHref = $baseUrl . '/public/dashboard.php';
            $homeLabel = 'Go to Dashboard';
        } elseif (isset($_SESSION['student'])) {
            $homeHref = $baseUrl . '/public/student/dashboard.php';
            $homeLabel = 'Go to Dashboard';
        } else {
            $homeHref = $baseUrl . '/public/student/login.php';
            $homeLabel = 'Go to Login';
        }
        ?>
        <a href="<?= htmlspecialchars($homeHref, ENT_QUOTES, 'UTF-8') ?>" class="btn"><?= $homeLabel ?></a>
    </div>
</body>
</html>
