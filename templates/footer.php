<?php
/**
 * templates/footer.php
 * See templates/header.php for the $page scope-coupling note (Medium,
 * code review) - same contract applies here.
 *
 * REDESIGN: visual only - same JS include logic as before.
 *
 * Expects (optionally) from the including scope:
 *   $page - route key string, defaults to current_page()
 */
declare(strict_types=1);
$page = $page ?? current_page();
?>
<footer class="t8-footer">
    <p>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?> — Team 8 (RAM YUM)</p>
</footer>

<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php if ($page === 'reservation' || $page === 'contracts'): ?>
    <script src="<?= e(asset('js/validation.js')) ?>"></script>
<?php endif; ?>
<?php if ($page === 'reservation'): ?>
    <script src="<?= e(asset('js/reservation.js')) ?>"></script>
<?php endif; ?>

<?php include __DIR__ . '/ai_widget.php'; ?>
</body>
</html>
