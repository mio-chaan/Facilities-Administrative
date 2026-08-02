<?php
declare(strict_types=1);
$pageTitle = $pageTitle ?? APP_NAME;
$page      = $page ?? current_page();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer">

    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/components.css')) ?>">
    <?php if ($page === 'dashboard'): ?>
        <link rel="stylesheet" href="<?= e(asset('css/dashboard.css')) ?>">
        <!-- REDESIGN: Chart.js powers the Monthly Reservation Trend card
             (see modules/dashboard/index.php + public/js/dashboard.js).
             Loaded here, before body content, same pattern as Font
             Awesome above; only pulled in on the dashboard page.
             NOTE: no integrity= hash here on purpose - a stale/incorrect
             SRI hash makes the browser silently block the whole script
             (the exact bug that broke this include the first time
             around). If you want SRI, generate the hash yourself for
             this exact file with:
               curl -s https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.5.0/chart.umd.min.js | openssl dgst -sha512 -binary | openssl base64 -A
             and paste it back in as integrity="sha512-...". -->
        <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.5.0/chart.umd.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <?php endif; ?>
    <?php if ($page === 'reservation'): ?>
        <link rel="stylesheet" href="<?= e(asset('css/reservation.css')) ?>">
    <?php endif; ?>
</head>
<body>
<?php $flashes = t8_flash_get(); ?>
<?php if (!empty($flashes)): ?>
    <div class="t8-flash-stack">
        <?php foreach ($flashes as $flash): ?>
            <div class="t8-alert t8-alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
