<?php
declare(strict_types=1);
$page = $page ?? current_page();
?>
<footer class="t8-footer">
    <p>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?> — Team 8 (RAM YUM)</p>
</footer>

<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/global-search.js')) ?>"></script>
<script src="<?= e(asset('js/context-bar.js')) ?>"></script>
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
<?php if ($page === 'visitor'): ?>
    <script src="<?= e(asset('js/visitor.js')) ?>"></script>
<?php endif; ?>
<?php if ($page === 'documents'): ?>
    <script src="<?= e(asset('js/documents.js')) ?>"></script>
<?php endif; ?>
<?php if ($page === 'retention'): ?>
    <script src="<?= e(asset('js/retention.js')) ?>"></script>
<?php endif; ?>
<?php if (in_array($page, ['contracts', 'visitor', 'legal'], true)): ?>
    <!-- Shared meatball-menu / View Details controller - see
         public/js/row-menu.js. Loaded after visitor.js on the visitor
         page so it can take over the Scheduled Visits row menu too. -->
    <script src="<?= e(asset('js/row-menu.js')) ?>"></script>
<?php endif; ?>

<?php include __DIR__ . '/ai_widget.php'; ?>
</body>
</html>
