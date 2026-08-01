<?php
declare(strict_types=1);
$page = $page ?? current_page();
?>
<footer class="t8-footer">
    <p>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?> — Team 8 (RAM YUM)</p>
</footer>

<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php if ($page === 'dashboard'): ?>
    <script src="<?= e(asset('js/dashboard.js')) ?>"></script>
<?php endif; ?>
<?php if ($page === 'reservation' || $page === 'contracts'): ?>
    <script src="<?= e(asset('js/validation.js')) ?>"></script>
<?php endif; ?>
<?php if ($page === 'reservation'): ?>
    <script src="<?= e(asset('js/reservation.js')) ?>"></script>
<?php endif; ?>
<?php if ($page === 'facilities'): ?>
    <script src="<?= e(asset('js/facilities.js')) ?>"></script>
<?php endif; ?>

<?php include __DIR__ . '/ai_widget.php'; ?>
</body>
</html>
