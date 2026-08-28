<?php
/**
 * modules/documents/hr/generate.php
 * "Generate HR Document" — replaces the main content with a template
 * picker (per task spec: this must NOT be a new sidebar page). Choosing
 * a template + clicking Continue navigates to that template's own
 * hr/*.php controller via ?action=..._new.
 */

declare(strict_types=1);
?>
<div class="t8-card-header" style="margin-bottom: var(--t8-space-4);">
    <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents')) ?>">
        <i class="fa-solid fa-arrow-left"></i> Back to Document Management
    </a>
</div>

<div class="t8-card">
    <div class="t8-card-header">
        <h2 class="t8-card-title">Choose Template</h2>
    </div>
    <p class="t8-help-text">Pick a document type, then continue to fill in the details.</p>

    <div class="t8-template-grid">
        <label class="t8-template-option" data-target="<?= e(page_url('documents', ['action' => 'incident_report_new'])) ?>">
            <input type="radio" name="t8_template" value="incident_report">
            <div class="t8-template-box">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span>Incident Report</span>
            </div>
        </label>

        <label class="t8-template-option" data-target="<?= e(page_url('documents', ['action' => 'nte_new'])) ?>">
            <input type="radio" name="t8_template" value="nte">
            <div class="t8-template-box">
                <i class="fa-solid fa-file-circle-question"></i>
                <span>Notice To Explain</span>
            </div>
        </label>

        <label class="t8-template-option" data-target="<?= e(page_url('documents', ['action' => 'memorandum_new'])) ?>">
            <input type="radio" name="t8_template" value="memorandum">
            <div class="t8-template-box">
                <i class="fa-solid fa-file-lines"></i>
                <span>Memorandum</span>
            </div>
        </label>

        <label class="t8-template-option" data-target="<?= e(page_url('documents', ['action' => 'memorandum_new', 'kind' => 'warning_letter'])) ?>">
            <input type="radio" name="t8_template" value="warning_letter">
            <div class="t8-template-box">
                <i class="fa-solid fa-circle-exclamation"></i>
                <span>Warning Letter</span>
            </div>
        </label>

        <label class="t8-template-option" data-target="<?= e(page_url('documents', ['action' => 'certificate_new'])) ?>">
            <input type="radio" name="t8_template" value="certificate">
            <div class="t8-template-box">
                <i class="fa-solid fa-certificate"></i>
                <span>Certificate</span>
            </div>
        </label>
    </div>

    <div style="margin-top: var(--t8-space-4); display:flex; gap:8px; flex-wrap:wrap;">
        <button class="t8-btn t8-btn-accent" id="t8TemplateContinue" type="button" disabled>
            <i class="fa-solid fa-arrow-right"></i> Continue
        </button>
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents')) ?>">Cancel</a>
    </div>
</div>
