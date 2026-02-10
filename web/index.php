<?php
/**
 * Landing page: login/register form or redirect to upload.
 */

require_once __DIR__ . '/auth.php';

// Handle logout
if (($_GET['action'] ?? '') === 'logout') {
    logoutUser();
    header('Location: index.php');
    exit;
}

// Handle login POST
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValidate()) {
        $error = 'Invalid form submission.';
    } else {
        $formAction = $_POST['form_action'] ?? '';

        if ($formAction === 'register') {
            $result = registerUser(
                trim($_POST['username'] ?? ''),
                trim($_POST['email'] ?? ''),
                $_POST['password'] ?? ''
            );
            if ($result['ok']) {
                header('Location: upload.php');
                exit;
            }
            $error = $result['error'];
        } elseif ($formAction === 'login') {
            $result = loginUser(
                trim($_POST['username'] ?? ''),
                $_POST['password'] ?? ''
            );
            if ($result['ok']) {
                header('Location: upload.php');
                exit;
            }
            $error = $result['error'];
        }
    }
}

// Already logged in? Go to upload
if (currentUserId() !== null) {
    header('Location: upload.php');
    exit;
}

$pageTitle = 'CrowdSky - Cloud Stacking for Seestar';
include __DIR__ . '/templates/header.php';
?>

<div class="auth-container">
    <h1>Welcome to CrowdSky</h1>
    <p style="color:var(--text-muted);margin-bottom:1.5rem">
        Upload your Seestar raw frames. We stack them for free at 15-minute cadence.
    </p>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if (($_GET['msg'] ?? '') === 'login_required'): ?>
        <div class="alert alert-warning">Please log in to continue.</div>
    <?php endif; ?>

    <div class="card">
        <h2>Login</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="form_action" value="login">
            <div class="form-group">
                <label for="login-user">Username or email</label>
                <input type="text" id="login-user" name="username" required>
            </div>
            <div class="form-group">
                <label for="login-pass">Password</label>
                <input type="password" id="login-pass" name="password" required>
            </div>
            <button type="submit" class="btn btn-primary">Login</button>
        </form>
    </div>

    <div class="card">
        <h2>Register</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="form_action" value="register">
            <div class="form-group">
                <label for="reg-user">Username</label>
                <input type="text" id="reg-user" name="username" required minlength="3" maxlength="64">
            </div>
            <div class="form-group">
                <label for="reg-email">Email</label>
                <input type="email" id="reg-email" name="email" required>
            </div>
            <div class="form-group">
                <label for="reg-pass">Password (min 8 chars)</label>
                <input type="password" id="reg-pass" name="password" required minlength="8">
            </div>
            <button type="submit" class="btn btn-primary">Register</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/templates/footer.php'; ?>
