<div class="t8-modal-backdrop" id="t8PartyModal" hidden>
    <div class="t8-modal" role="dialog" aria-modal="true" aria-labelledby="t8PartyModalTitle">
        <div class="t8-modal-header">
            <div><h2 id="t8PartyModalTitle">Add New Party</h2><p class="t8-help-text">Create a reusable party record.</p></div>
            <button type="button" class="t8-modal-close" data-close-party-modal aria-label="Close">&times;</button>
        </div>
        <form method="post" action="<?= e(page_url('party_registry', ['action' => 'create'])) ?>" class="t8-modal-body" id="t8PartyModalForm">
            <?= t8_csrf_field() ?>
            <div class="t8-field"><span class="t8-label">Party Type *</span><div class="t8-tile-row">
                <label class="t8-tile"><input type="radio" name="type" value="organization" checked><strong>Organization</strong><span>Company, supplier, agency, or partner</span></label>
                <label class="t8-tile"><input type="radio" name="type" value="individual"><strong>Individual</strong><span>Person acting as a party</span></label>
            </div></div>
            <div class="t8-form-grid">
                <div class="t8-field t8-form-span-2"><label class="t8-label" for="party_name">Legal / Full Name *</label><input class="t8-input" id="party_name" type="text" name="name" required maxlength="200"></div>
                <div class="t8-field"><label class="t8-label" for="trade_name">Trade / Business Name</label><input class="t8-input" id="trade_name" type="text" name="trade_name" maxlength="200"></div>
                <div class="t8-field"><label class="t8-label" for="registration_number">Registration Number</label><input class="t8-input" id="registration_number" type="text" name="registration_number" maxlength="100"></div>
                <div class="t8-field"><label class="t8-label" for="tin">TIN (Numeric Only)</label><input class="t8-input" id="tin" type="text" name="tin" maxlength="50" inputmode="numeric" pattern="[0-9]*" title="Please enter numbers only"></div>
                <div class="t8-field"><label class="t8-label" for="primary_contact">Primary Contact *</label><input class="t8-input" id="primary_contact" type="text" name="primary_contact" required maxlength="150"></div>
                <div class="t8-field"><label class="t8-label" for="party_email">Email</label><input class="t8-input" id="party_email" type="email" name="contact_email" maxlength="150"></div>
                <div class="t8-field t8-form-span-2"><label class="t8-label" for="party_phone">Phone Number (PH) *</label><div style="display:flex; align-items:center; gap:0;">
                    <span style="background:#f0f0f0; border:1px solid #ccc; border-right:none; padding:8px 12px; border-radius:4px 0 0 4px; font-weight:bold; color:#555;">+63</span>
                    <input class="t8-input" id="party_phone" type="tel" name="contact_phone" required maxlength="10" minlength="10" inputmode="numeric" pattern="[0-9]{10}" title="Please enter exactly 10 digits" placeholder="9171234567" style="border-radius:0 4px 4px 0; flex:1;">
                </div><span class="t8-help-text">Enter 10 digits (e.g., 9171234567).</span></div>
                <div class="t8-field t8-form-span-2"><label class="t8-label" for="party_address">Address *</label><textarea class="t8-input" id="party_address" name="address" rows="2" required></textarea></div>
                <div class="t8-field"><label class="t8-label" for="signatory_name">Authorized Signatory</label><input class="t8-input" id="signatory_name" type="text" name="authorized_signatory_name" maxlength="150"></div>
                <div class="t8-field"><label class="t8-label" for="signatory_position">Signatory Position</label><input class="t8-input" id="signatory_position" type="text" name="authorized_signatory_position" maxlength="150"></div>
            </div>
            <div class="t8-modal-footer"><button type="button" class="t8-btn t8-btn-outline" data-close-party-modal>Cancel</button><button class="t8-btn t8-btn-accent" type="submit">Save Party</button></div>
        </form>
    </div>
</div>
