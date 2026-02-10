<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'CrowdSky') ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header class="site-header">
    <nav>
        <a href="index.php" class="logo">CrowdSky</a>
        <div class="nav-links">
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="upload.php">Upload</a>
                <a href="status.php">Jobs</a>
                <a href="stacks.php">Stacks</a>
                <span class="user-info"><?= htmlspecialchars($_SESSION['username'] ?? '') ?></span>
                <a href="index.php?action=logout">Logout</a>
            <?php else: ?>
                <a href="index.php">Login</a>
            <?php endif; ?>
        </div>
    </nav>
</header>
<main>
