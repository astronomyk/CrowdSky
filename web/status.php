<?php
/**
 * Status page: show upload sessions and stacking job statuses.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$userId = requireLogin();
$db = getDb();

// If a specific session is requested, show its jobs
$sessionToken = $_GET['session'] ?? null;

$pageTitle = 'Job Status - CrowdSky';
include __DIR__ . '/templates/header.php';
?>

<h1>Job Status</h1>

<?php if ($sessionToken): ?>
    <?php
    $stmt = $db->prepare(
        'SELECT * FROM upload_sessions WHERE session_token = ? AND user_id = ?'
    );
    $stmt->execute([$sessionToken, $userId]);
    $session = $stmt->fetch();
    ?>
    <?php if ($session): ?>
        <div class="card">
            <h2>Upload Session</h2>
            <p>
                <strong>Object:</strong> <?= htmlspecialchars($session['object_name'] ?? 'Unknown') ?> &nbsp;
                <strong>Files:</strong> <?= (int)$session['file_count'] ?> &nbsp;
                <strong>Size:</strong> <?= number_format($session['total_size_mb'], 1) ?> MB &nbsp;
                <strong>Status:</strong>
                <span class="badge badge-<?= htmlspecialchars($session['status']) ?>">
                    <?= htmlspecialchars($session['status']) ?>
                </span>
            </p>
        </div>

        <?php
        $stmt = $db->prepare(
            'SELECT * FROM stacking_jobs WHERE upload_session_id = ? ORDER BY chunk_key'
        );
        $stmt->execute([$session['id']]);
        $jobs = $stmt->fetchAll();
        ?>

        <?php if ($jobs): ?>
        <div class="card">
            <h2>Stacking Jobs</h2>
            <table>
                <thead>
                    <tr>
                        <th>Job ID</th>
                        <th>Chunk Key</th>
                        <th>Object</th>
                        <th>Frames</th>
                        <th>Status</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($jobs as $job): ?>
                    <tr>
                        <td><?= (int)$job['id'] ?></td>
                        <td><?= htmlspecialchars($job['chunk_key']) ?></td>
                        <td><?= htmlspecialchars($job['object_name'] ?? '-') ?></td>
                        <td><?= (int)$job['frame_count'] ?></td>
                        <td>
                            <span class="badge badge-<?= htmlspecialchars($job['status']) ?>">
                                <?= htmlspecialchars($job['status']) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($job['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="alert alert-error">Session not found.</div>
    <?php endif; ?>

<?php else: ?>
    <?php
    // Show all sessions for this user
    $stmt = $db->prepare(
        'SELECT * FROM upload_sessions WHERE user_id = ? ORDER BY created_at DESC LIMIT 50'
    );
    $stmt->execute([$userId]);
    $sessions = $stmt->fetchAll();
    ?>

    <?php if (empty($sessions)): ?>
        <div class="card">
            <p>No uploads yet. <a href="upload.php">Upload your first FITS files.</a></p>
        </div>
    <?php else: ?>
        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Object</th>
                        <th>Files</th>
                        <th>Size</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($sessions as $s): ?>
                    <tr>
                        <td><?= htmlspecialchars($s['created_at']) ?></td>
                        <td><?= htmlspecialchars($s['object_name'] ?? '-') ?></td>
                        <td><?= (int)$s['file_count'] ?></td>
                        <td><?= number_format($s['total_size_mb'], 1) ?> MB</td>
                        <td>
                            <span class="badge badge-<?= htmlspecialchars($s['status']) ?>">
                                <?= htmlspecialchars($s['status']) ?>
                            </span>
                        </td>
                        <td><a href="status.php?session=<?= urlencode($s['session_token']) ?>">Details</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/templates/footer.php'; ?>
