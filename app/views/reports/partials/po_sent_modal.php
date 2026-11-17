<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 13/11/2026
 * DESCRIPTION : Sent PO modal — pick user (auto email) + remarks + send mail
 */
?>
<div class="pcw-sent-shell" id="pcwSentModal" hidden aria-hidden="true">
    <div class="pcw-sent-backdrop" id="pcwSentBackdrop"></div>
    <aside class="pcw-sent-panel" role="dialog" aria-modal="true" aria-labelledby="pcwSentTitle">
        <header class="pcw-sent-head">
            <div class="pcw-sent-head-left">
                <span class="pcw-sent-ico material-icons-round" aria-hidden="true">send</span>
                <div>
                    <strong id="pcwSentTitle">Send PO</strong>
                    <p id="pcwSentSub">Select user, set status, add note, and send mail</p>
                </div>
            </div>
            <button type="button" class="pcw-sent-close" id="pcwSentClose" aria-label="Close">&times;</button>
        </header>

        <div class="pcw-sent-body">
            <div class="pcw-sent-po-chip" id="pcwSentPoLabel">PO —</div>

            <label class="pcw-sent-field" for="pcwSentUserSearch">
                <span>Username</span>
                <div class="pcw-sent-combo" id="pcwSentUserCombo">
                    <input type="hidden" id="pcwSentUser" value="">
                    <input type="hidden" id="pcwSentUsername" value="">
                    <div class="pcw-sent-combo-control">
                        <span class="material-icons-round pcw-sent-combo-ico" aria-hidden="true">person_search</span>
                        <input type="search" id="pcwSentUserSearch" class="pcw-sent-input pcw-sent-combo-input"
                               placeholder="Search and select user…"
                               autocomplete="off" spellcheck="false"
                               role="combobox" aria-autocomplete="list"
                               aria-controls="pcwSentUserList" aria-expanded="false"
                               aria-label="Search and select username">
                        <button type="button" class="pcw-sent-combo-clear" id="pcwSentUserClear" hidden aria-label="Clear selected user">&times;</button>
                        <span class="material-icons-round pcw-sent-combo-chevron" aria-hidden="true">expand_more</span>
                    </div>
                    <ul class="pcw-sent-combo-list" id="pcwSentUserList" role="listbox" hidden></ul>
                </div>
                <small>Type to search, then select a username from the list.</small>
            </label>

            <label class="pcw-sent-field">
                <span>Email</span>
                <input type="email" id="pcwSentEmail" class="pcw-sent-input" placeholder="name@company.com" readonly>
                <small>Filled automatically from the selected username.</small>
            </label>

            <label class="pcw-sent-field">
                <span>Status</span>
                <input type="text" id="pcwSentStatus" class="pcw-sent-input" aria-label="Status" placeholder="Type status…" maxlength="80" autocomplete="off">
            </label>

            <label class="pcw-sent-field">
                <span>Note</span>
                <textarea id="pcwSentRemarks" class="pcw-sent-textarea" rows="4" placeholder="Write a note for this send…"></textarea>
            </label>

            <p class="pcw-sent-error" id="pcwSentError" role="alert" hidden></p>
        </div>

        <footer class="pcw-sent-foot">
            <button type="button" class="rpt-tb-btn" id="pcwSentCancel">Cancel</button>
            <button type="button" class="rpt-tb-btn rpt-tb-btn-primary" id="pcwSentSubmit">
                <span class="pcw-sent-submit-spin" aria-hidden="true"></span>
                <span class="material-icons-round pcw-sent-submit-ico">mail</span>
                <span class="pcw-sent-submit-label">Send mail</span>
            </button>
        </footer>

        <input type="hidden" id="pcwSentPo" value="">
    </aside>
</div>
