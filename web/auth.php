<?php
/**
 * Authentication helpers: login, register, session management.
 */

require_once __DIR__ . '/db.php';

session_start();

/**
 * Register a new user.
 *
 * @return array ['ok' => bool, 'error' => string|null, 'user_id' => int|null]
 */
function registerUser(string $username, string $email, string $password): array
{
    if (strlen($username) < 3 || strlen($username) > 64) {
        return ['ok' => false, 'error' => 'Username must be 3-64 characters.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid email address.'];
    }
    if (strlen($password) < 8) {
        return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
    }

    $db = getDb();
    $hash = password_hash($password, PASSWORD_BCRYPT);

    try {
        $stmt = $db->prepare(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)'
        );
        $stmt->execute([$username, $email, $hash]);
        $userId = (int)$db->lastInsertId();

        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;

        return ['ok' => true, 'error' => null, 'user_id' => $userId];
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            return ['ok' => false, 'error' => 'Username or email already taken.'];
        }
        throw $e;
    }
}

/**
 * Log in an existing user.
 *
 * @return array ['ok' => bool, 'error' => string|null]
 */
function loginUser(string $username, string $password): array
{
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT id, username, password_hash, is_active FROM users WHERE username = ? OR email = ?'
    );
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['ok' => false, 'error' => 'Invalid username or password.'];
    }
    if (!$user['is_active']) {
        return ['ok' => false, 'error' => 'Account is deactivated.'];
    }

    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];

    return ['ok' => true, 'error' => null];
}

/**
 * Log out the current user.
 */
function logoutUser(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * Get the currently logged-in user ID, or null.
 */
function currentUserId(): ?int
{
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

/**
 * Require login — redirect to index if not authenticated.
 */
function requireLogin(): int
{
    $uid = currentUserId();
    if ($uid === null) {
        header('Location: index.php?msg=login_required');
        exit;
    }
    return $uid;
}

/**
 * Generate a CSRF token and store it in the session.
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate the submitted CSRF token.
 */
function csrfValidate(): bool
{
    $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    return hash_equals(csrfToken(), $token);
}
