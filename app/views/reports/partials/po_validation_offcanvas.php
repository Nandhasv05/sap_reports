<?php
/*
 * Shared 75% Assign & Validate + View History offcanvas
 */
$pvLoginUser = trim((string) (
    $pvLoginUser
    ?? $_SESSION['username']
    ?? ($_SESSION['user_name'] ?? ($_SESSION['name'] ?? 'User'))
));
$pvKicker = (string) ($pvKicker ?? 'PO Costing Validation');
?>
<div class="pcw-action-offcanvas is-validate" id="pcwValidateModal" hidden aria-hidden="true">
    <div class="pcw-action-offcanvas-backdrop" id="pcwValidateBackdrop"></div>
    <aside class="pcw-action-offcanvas-panel" role="dialog" aria-modal="true" aria-labelledby="pcwValidateTitle">
        <div class="pcw-action-offcanvas-head">
            <div class="pcw-action-offcanvas-head-left">
                <span class="pcw-action-offcanvas-icon material-icons-round">fact_check</span>
                <div>
                    <span class="pcw-action-offcanvas-kicker"><?= e($pvKicker) ?></span>
                    <strong id="pcwValidateTitle">Assign &amp; Validate PO</strong>
                    <p id="pcwValidateDesc">Record one validation decision for this purchase order and assign the next-level user.</p>
                </div>
            </div>
            <button type="button" class="pcw-cdm-close" id="pcwValidateClose" aria-label="Close">&times;</button>
        </div>
        <div class="pcw-action-offcanvas-body">
            <div class="pcw-validate-meta" id="pcwValidateMeta"></div>
            <div class="pcw-validate-layout">
                <section class="pcw-validate-card">
                    <header class="pcw-validate-card-head">
                        <span class="material-icons-round">badge</span>
                        <div>
                            <strong>People</strong>
                            <small>Who validates now and who reviews next</small>
                        </div>
                    </header>
                    <div class="pcw-validate-grid">
                        <div class="pcw-validate-field">
                            <span>Logged-in User</span>
                            <div class="pcw-login-user" id="pcwLoginUserChip">
                                <span class="material-icons-round">account_circle</span>
                                <strong id="pcwLoginUserName"><?= e($pvLoginUser) ?></strong>
                            </div>
                        </div>
                        <div class="pcw-validate-field">
                            <span>Assign Next Level User</span>
                            <div class="pcw-user-assign is-modal" id="pcwUserAssign">
                                <span class="material-icons-round">person_search</span>
                                <input type="search" id="pcwUserSearch" class="pcw-user-search" placeholder="Search username…" autocomplete="off" spellcheck="false">
                                <input type="hidden" id="pcwUserId" value="">
                                <input type="hidden" id="pcwUserName" value="">
                                <div class="pcw-user-menu" id="pcwUserMenu" hidden role="listbox"></div>
                            </div>
                        </div>
                    </div>
                </section>
                <section class="pcw-validate-card">
                    <header class="pcw-validate-card-head">
                        <span class="material-icons-round">rule</span>
                        <div>
                            <strong>Decision</strong>
                            <small>Choose Validate PO or Not Validate</small>
                        </div>
                    </header>
                    <div class="pcw-validate-field">
                        <span>Status</span>
                        <div class="pcw-validate-status-seg" role="radiogroup" aria-label="Validation status">
                            <label class="is-active">
                                <input type="radio" name="pcwValidateStatus" value="VALIDATE_PO" checked>
                                <span class="material-icons-round">verified</span>
                                <em>Validate PO</em>
                            </label>
                            <label>
                                <input type="radio" name="pcwValidateStatus" value="NOT_VALIDATE">
                                <span class="material-icons-round">cancel</span>
                                <em>Not Validate</em>
                            </label>
                        </div>
                    </div>
                </section>
                <section class="pcw-validate-card is-remarks">
                    <header class="pcw-validate-card-head">
                        <span class="material-icons-round">notes</span>
                        <div>
                            <strong>Remarks</strong>
                            <small>Optional note saved with this PO validation</small>
                        </div>
                    </header>
                    <div class="pcw-validate-field">
                        <textarea id="pcwValidateRemarks" rows="8" placeholder="Enter remarks for this validation decision…"></textarea>
                    </div>
                </section>
            </div>
        </div>
        <div class="pcw-action-offcanvas-foot">
            <button type="button" class="rpt-tb-btn" id="pcwValidateCancel">Cancel</button>
            <button type="button" class="rpt-tb-btn rpt-tb-btn-primary" id="pcwValidateSave">
                <span class="material-icons-round">save</span>
                Save Validation
            </button>
        </div>
    </aside>
</div>

<div class="pcw-action-offcanvas is-history" id="pcwHistoryModal" hidden aria-hidden="true">
    <div class="pcw-action-offcanvas-backdrop" id="pcwHistoryBackdrop"></div>
    <aside class="pcw-action-offcanvas-panel" role="dialog" aria-modal="true" aria-labelledby="pcwHistoryTitle">
        <div class="pcw-action-offcanvas-head">
            <div class="pcw-action-offcanvas-head-left">
                <span class="pcw-action-offcanvas-icon material-icons-round">history</span>
                <div>
                    <span class="pcw-action-offcanvas-kicker"><?= e($pvKicker) ?></span>
                    <strong id="pcwHistoryTitle">Validation History</strong>
                    <p id="pcwHistoryDesc">One entry per PO validation — newest first.</p>
                </div>
            </div>
            <div class="pcw-action-offcanvas-head-actions">
                <span class="pcw-history-count" id="pcwHistoryCount">—</span>
                <button type="button" class="pcw-cdm-close" id="pcwHistoryClose" aria-label="Close">&times;</button>
            </div>
        </div>
        <div class="pcw-action-offcanvas-body is-history-body">
            <div class="pcw-history-list" id="pcwHistoryBody" role="list">
                <div class="pcw-history-empty">No history yet.</div>
            </div>
        </div>
        <div class="pcw-action-offcanvas-foot">
            <button type="button" class="rpt-tb-btn rpt-tb-btn-primary" id="pcwHistoryDone">Close</button>
        </div>
    </aside>
</div>
