<?php
/**
 * modules/documents/hr/dashboard.php
 * New landing view for the Document Management page (action=dashboard,
 * also the default when no ?action is given). Everything here is
 * read-only display logic - all mutations live in the other hr/*.php
 * controllers and the original upload actions still in index.php.
 */

declare(strict_types=1);

$hrStats = t8_hr_dashboard_stats($pdo);
$recentDocuments = t8_hr_recent_documents($pdo, $isAdmin, $currentUserId, 8);
?>
<div class="t8-docs-dashboard">

    <div class="t8-docs-hero t8-card">
        <div class="t8-docs-hero-text">
            <h1>Document Management</h1>
            <p class="t8-help-text">Upload and version traditional files, or generate HR documents — incident reports, notices to explain, memorandums, warning letters, and certificates — straight from templates.</p>
        </div>
        <div class="t8-docs-hero-actions">
            <div class="t8-docs-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="t8DocsSearch" placeholder="Search recent documents…" autocomplete="off">
            </div>
            <div class="t8-docs-hero-buttons">
                <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents', ['action' => 'create'])) ?>">
                    <i class="fa-solid fa-upload"></i> Upload Existing Document
                </a>
                <a class="t8-btn t8-btn-accent" href="<?= e(page_url('documents', ['action' => 'generate'])) ?>">
                    <i class="fa-solid fa-wand-magic-sparkles"></i> Generate HR Document
                </a>
            </div>
        </div>
    </div>

    <div class="t8-docs-grid-2">
        <div class="t8-card">
            <div class="t8-card-header"><h2 class="t8-card-title">Quick Actions</h2></div>
            <div class="t8-docs-quick-actions">
                <a class="t8-docs-quick-action" href="<?= e(page_url('documents', ['action' => 'create'])) ?>">
                    <i class="fa-solid fa-upload"></i><span>Upload Existing File</span>
                </a>
                <a class="t8-docs-quick-action" href="<?= e(page_url('documents', ['action' => 'incident_report_new'])) ?>">
                    <i class="fa-solid fa-triangle-exclamation"></i><span>Incident Report</span>
                </a>
                <a class="t8-docs-quick-action" href="<?= e(page_url('documents', ['action' => 'nte_new'])) ?>">
                    <i class="fa-solid fa-file-circle-question"></i><span>Notice To Explain</span>
                </a>
                <a class="t8-docs-quick-action" href="<?= e(page_url('documents', ['action' => 'memorandum_new'])) ?>">
                    <i class="fa-solid fa-file-lines"></i><span>Memorandum</span>
                </a>
                <a class="t8-docs-quick-action" href="<?= e(page_url('documents', ['action' => 'memorandum_new', 'kind' => 'warning_letter'])) ?>">
                    <i class="fa-solid fa-circle-exclamation"></i><span>Warning Letter</span>
                </a>
                <a class="t8-docs-quick-action" href="<?= e(page_url('documents', ['action' => 'certificate_new'])) ?>">
                    <i class="fa-solid fa-certificate"></i><span>Certificate</span>
                </a>
            </div>
        </div>

        <div class="t8-card">
            <div class="t8-card-header"><h2 class="t8-card-title">Recent Documents</h2></div>
            <div id="t8RecentDocsWrap">
                <?php if ($recentDocuments === []): ?>
                    <div class="t8-empty">No documents yet. Upload a file or generate your first HR document.</div>
                <?php else: ?>
                    <ul class="t8-docs-recent-list">
                        <?php foreach ($recentDocuments as $doc): ?>
                            <li class="t8-docs-recent-item" data-search="<?= e(strtolower((string) $doc['label'])) ?>">
                                <div class="t8-docs-recent-icon"><i class="fa-solid fa-file"></i></div>
                                <div class="t8-docs-recent-body">
                                    <a href="<?= e(page_url('documents', ['action' => $doc['url_action'], 'id' => $doc['id']])) ?>"><?= e((string) $doc['label']) ?></a>
                                    <span class="t8-table-subtext"><?= e(t8_hr_doc_type_label((string) $doc['doc_type'])) ?> · <?= e(format_date((string) $doc['ts'], 'M d, Y g:i A')) ?></span>
                                </div>
                                <span class="t8-badge <?= e(t8_hr_status_badge((string) $doc['status'])) ?>"><?= e(ucfirst((string) $doc['status'])) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="t8-docs-grid-2">
        <div class="t8-card">
            <div class="t8-card-header"><h2 class="t8-card-title">Document Statistics</h2></div>
            <div class="t8-docs-stat-list">
                <div class="t8-docs-stat"><span>Total Documents</span><strong><?= e((string) $hrStats['total_documents']) ?></strong></div>
                <div class="t8-docs-stat"><span>Generated Documents</span><strong><?= e((string) $hrStats['generated_documents']) ?></strong></div>
                <div class="t8-docs-stat"><span>Archived</span><strong><?= e((string) $hrStats['archived']) ?></strong></div>
                <div class="t8-docs-stat"><span>Templates</span><strong><?= e((string) $hrStats['templates']) ?></strong></div>
            </div>
        </div>

        <div class="t8-card">
            <div class="t8-card-header"><h2 class="t8-card-title">Pending Actions</h2></div>
            <div class="t8-docs-stat-list">
                <div class="t8-docs-stat"><span>Pending Incident Reports</span><strong><?= e((string) $hrStats['pending_incidents']) ?></strong></div>
                <div class="t8-docs-stat"><span>Pending NTE</span><strong><?= e((string) $hrStats['pending_nte']) ?></strong></div>
                <div class="t8-docs-stat"><span>Pending Explanations</span><strong><?= e((string) $hrStats['pending_explanations']) ?></strong></div>
                <div class="t8-docs-stat"><span>Pending Approval</span><strong><?= e((string) $hrStats['pending_approval']) ?></strong></div>
            </div>
        </div>
    </div>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-2);">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents', ['action' => 'browse'])) ?>">
            <i class="fa-solid fa-folder-open"></i> Browse All Uploaded Documents
        </a>
    </div>
</div>
