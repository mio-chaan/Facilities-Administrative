<?php
/**
 * templates/header.php
 *
 * Expects (optionally) from the including scope:
 *   $pageTitle - string, defaults to APP_NAME
 *   $page      - route key string, defaults to current_page()
 */
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
