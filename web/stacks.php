<?php
/**
 * Browse completed stacked frames for the current user.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$userId = requireLogin();
$db = getDb();

// Optional filter by object
$objectFilter = $_GET['object'] ?? null;

$pageTitle = 'Stacks - CrowdSky';
include __DIR__ . '/templates/header.php';
?>

<h1>Stacked Frames</h1>

<?php
// Get distinct objects for filter
$stmt = $db->prepare(
    'SELECT DISTINCT object_name FROM stacked_frames WHERE user_id = ? AND object_name IS NOT NULL ORDER BY object_name'
);
$stmt->execute([$userId]);
$objects = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<?php if ($objects): ?>
<div style="margin-bottom:1rem">
    <strong>Filter:</strong>
    <a href="stacks.php" class="btn" style="margin-left:0.5rem">All</a>
    <?php foreach ($objects as $obj): ?>
        <a href="stacks.php?object=<?= urlencode($obj) ?>"
           class="btn<?= $objectFilter === $obj ? ' btn-primary' : '' ?>"
           style="margin-left:0.25rem">
            <?= htmlspecialchars($obj) ?>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
$sql = 'SELECT sf.*, sj.upload_session_id
        FROM stacked_frames sf
        JOIN stacking_jobs sj ON sj.id = sf.stacking_job_id
        WHERE sf.user_id = ?';
$params = [$userId];

if ($objectFilter) {
    $sql .= ' AND sf.object_name = ?';
    $params[] = $objectFilter;
}
$sql .= ' ORDER BY sf.date_obs_start DESC LIMIT 100';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$stacks = $stmt->fetchAll();
?>

<?php if (empty($stacks)): ?>
    <div class="card">
        <p>No stacked frames yet. Upload some FITS files and wait for the worker to process them.</p>
    </div>
<?php else: ?>
    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>Object</th>
                    <th>Chunk</th>
                    <th>Frames</th>
                    <th>Aligned</th>
                    <th>Exp. Time</th>
                    <th>Date</th>
                    <th>Stars</th>
                    <th>Size</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($stacks as $s): ?>
                <tr>
                    <td><?= htmlspecialchars($s['object_name'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($s['chunk_key']) ?></td>
                    <td><?= (int)$s['n_frames_input'] ?></td>
                    <td><?= (int)$s['n_frames_aligned'] ?></td>
                    <td><?= $s['total_exptime'] ? number_format($s['total_exptime'], 1) . 's' : '-' ?></td>
                    <td><?= htmlspecialchars($s['date_obs_start'] ?? '-') ?></td>
                    <td><?= $s['n_stars_detected'] !== null ? (int)$s['n_stars_detected'] : '-' ?></td>
                    <td><?= number_format($s['file_size_bytes'] / 1024 / 1024, 1) ?> MB</td>
                    <td><a href="download.php?id=<?= (int)$s['id'] ?>">Download</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/templates/footer.php'; ?>
