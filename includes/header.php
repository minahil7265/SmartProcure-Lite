<?php
// /var/www/smartprocure/includes/header.php
//
// Common page top: <head>, sidebar navigation, top bar and breadcrumbs.
// Before including it, a page may set:
//   $pageTitle   - text for <title> and the page heading
//   $breadcrumbs - [['label' => 'Requests', 'url' => url('/requests/index.php')], ['label' => 'New']]
//   $pageActions - HTML for buttons shown beside the heading (optional)
//   $authLayout  - true for login/signup pages (no sidebar)

$pageTitle   = $pageTitle ?? '';
$breadcrumbs = $breadcrumbs ?? [];
$pageActions = $pageActions ?? '';
$authLayout  = $authLayout ?? false;
$appName     = config('app.name');

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ? $pageTitle . ' - ' . $appName : $appName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('/assets/css/style.css')) ?>">
</head>
<body class="<?= $authLayout ? 'auth-body' : 'app-body' ?>" data-currency="<?= e(config('app.currency')) ?>">

<?php if ($authLayout): ?>
<main class="auth-shell">
    <div class="auth-brand">
        <div class="brand-mark">SP</div>
        <div>
            <p class="brand-name"><?= e($appName) ?></p>
            <p class="brand-tagline"><?= e(config('app.tagline')) ?></p>
        </div>
    </div>
    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>" role="alert"><?= e($flash['message']) ?></div>
    <?php endif; ?>
<?php else: ?>
<div class="layout">
    <aside class="sidebar" id="sidebar">
        <a class="sidebar-brand" href="<?= e(url('/dashboard.php')) ?>">
            <span class="brand-mark">SP</span>
            <span class="brand-name"><?= e($appName) ?></span>
        </a>

        <?php if (isLoggedIn()): ?>
        <nav class="sidebar-nav" aria-label="Main navigation">
            <?php foreach (navigationLinks() as $link): ?>
                <?php if (isset($link['section'])): ?>
                    <p class="nav-section"><?= e($link['section']) ?></p>
                <?php else: ?>
                    <?php $active = $currentPath === url($link['path']); ?>
                    <a class="nav-link<?= $active ? ' active' : '' ?>" href="<?= e(url($link['path'])) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
                        <span class="nav-icon" aria-hidden="true"><?= e($link['icon']) ?></span>
                        <span><?= e($link['label']) ?></span>
                        <?php if (!empty($link['count'])): ?>
                            <span class="nav-count"><?= (int) $link['count'] ?></span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
    </aside>

    <div class="main">
        <header class="topbar">
            <button class="menu-toggle" type="button" data-toggle-sidebar aria-label="Open menu">☰</button>

            <nav class="breadcrumbs" aria-label="Breadcrumb">
                <ol>
                    <li><a href="<?= e(url('/dashboard.php')) ?>">Home</a></li>
                    <?php foreach ($breadcrumbs as $index => $crumb): ?>
                        <?php if (!empty($crumb['url']) && $index < count($breadcrumbs) - 1): ?>
                            <li><a href="<?= e($crumb['url']) ?>"><?= e($crumb['label']) ?></a></li>
                        <?php else: ?>
                            <li aria-current="page"><?= e($crumb['label']) ?></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ol>
            </nav>

            <?php if (isLoggedIn()): ?>
            <div class="user-box">
                <div class="user-meta">
                    <span class="user-name"><?= e($_SESSION['name'] ?? '') ?></span>
                    <?php $subtitle = $_SESSION['vendor_name'] ?? $_SESSION['department_name'] ?? ''; ?>
                    <?php if ($subtitle !== ''): ?><span class="user-role"><?= e($subtitle) ?></span><?php endif; ?>
                </div>
                <form method="post" action="<?= e(url('/logout.php')) ?>">
                    <?= csrfField() ?>
                    <button type="submit" class="btn btn-ghost btn-sm">Log out</button>
                </form>
            </div>
            <?php endif; ?>
        </header>

        <main class="content">
            <?php if ($pageTitle !== ''): ?>
            <div class="page-head">
                <h1><?= e($pageTitle) ?></h1>
                <?php if ($pageActions !== ''): ?>
                    <div class="page-actions"><?= $pageActions ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?>" role="alert"><?= e($flash['message']) ?></div>
            <?php endif; ?>
<?php endif; ?>
