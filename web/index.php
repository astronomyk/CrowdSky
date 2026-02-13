<?php
/**
 * Public splash/landing page with CSS starfield background.
 */

require_once __DIR__ . '/auth.php';

// Handle logout (keep backward compat)
if (($_GET['action'] ?? '') === 'logout') {
    logoutUser();
    header('Location: index.php');
    exit;
}

// Already logged in? Go to upload
if (currentUserId() !== null) {
    header('Location: upload.php');
    exit;
}

$pageTitle = 'CrowdSky - Cloud Stacking for Seestar';
$isSplash = true;
include __DIR__ . '/templates/header.php';
?>

<div class="splash-hero">
    <div class="starfield" aria-hidden="true">
        <div class="stars stars-sm"></div>
        <div class="stars stars-md"></div>
        <div class="stars stars-lg"></div>
    </div>
    <div class="hero-content">
        <h1 class="hero-title">CrowdSky</h1>
        <p class="hero-tagline">Cloud stacking for Seestar telescopes</p>
        <div class="hero-description">
            <p>
                CrowdSky is a free cloud storage and automated stacking service for
                ZWO Seestar S50 users. Upload your raw sub-exposures and we stack them
                into deep images at 15-minute cadence &mdash; no software, no hassle.
            </p>
            <p>
                By pooling observations from citizen astronomers, CrowdSky builds a
                time-domain archive for variable stars, transient events, and
                long-baseline photometry research.
            </p>
        </div>
        <a href="login.php" class="btn btn-primary btn-lg">Get Started</a>
    </div>
</div>

<div class="splash-steps">
    <div class="step-card">
        <div class="step-icon">&#x1F4E4;</div>
        <h3>Upload</h3>
        <p>Drag & drop your raw .fit files. We parse FITS headers automatically and group by pointing and time.</p>
    </div>
    <div class="step-card">
        <div class="step-icon">&#x1F4DA;</div>
        <h3>Stack</h3>
        <p>Our worker aligns and stacks your sub-exposures using proven algorithms. Track progress in real time.</p>
    </div>
    <div class="step-card">
        <div class="step-icon">&#x1F30C;</div>
        <h3>Archive</h3>
        <p>Browse your stacked frames on an all-sky map, filter by time, and download publication-ready FITS files.</p>
    </div>
</div>

<?php include __DIR__ . '/templates/footer.php'; ?>
