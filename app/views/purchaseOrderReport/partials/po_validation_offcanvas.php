<?php
/*
 * Shared Status & Remarks drawer (comments / activity)
 */
$pvLoginUser = trim((string) (
    $pvLoginUser
    ?? $_SESSION['username']
    ?? ($_SESSION['user_name'] ?? ($_SESSION['name'] ?? 'User'))
));
$pvLoginInitial = strtoupper(substr($pvLoginUser !== '' ? $pvLoginUser : 'U', 0, 1));
?>
<div class="pcw-action-offcanvas is-activity" id="pcwValidateModal" hidden aria-hidden="true">
    <div class="pcw-action-offcanvas-backdrop" id="pcwValidateBackdrop"></div>
    <aside class="pcw-action-offcanvas-panel pcw-activity-panel" role="dialog" aria-modal="true" aria-labelledby="pcwValidateTitle">
        <div class="pcw-activity-topbar">
            <div class="pcw-activity-topbar-title">
                <span class="material-icons-round">chat_bubble_outline</span>
                    <strong id="pcwValidateTitle">Status &amp; Remarks</strong>
                <span class="pcw-history-count" id="pcwHistoryCount">—</span>
            </div>
            <div class="pcw-activity-topbar-actions">
                <button type="button" class="rpt-tb-btn pcw-activity-hide-btn" id="pcwActivityHideDetails">Hide details</button>
                <button type="button" class="pcw-cdm-close" id="pcwValidateClose" aria-label="Close">&times;</button>
            </div>
        </div>

        <div class="pcw-action-offcanvas-body is-activity-body">
            <span id="pcwLoginUserName" hidden><?= e($pvLoginUser) ?></span>
            <span class="pcw-activity-avatar" id="pcwActivityAvatar" hidden aria-hidden="true"><?= e($pvLoginInitial) ?></span>
            <input type="hidden" id="pcwUserId" value="">
            <input type="hidden" id="pcwUserName" value="">
            <input type="hidden" id="pcwUserSearch" value="">
            <div id="pcwUserMenu" hidden></div>
            <div id="pcwValidateMeta" hidden></div>
            <div id="pcwActivityDetails" hidden></div>
            <div class="pcw-activity-compose" id="pcwActivityCompose" aria-label="Write a comment">
                <textarea id="pcwValidateRemarks" rows="1" placeholder="Write a comment… Use @ to mention someone"></textarea>
                <div class="pcw-mention-menu" id="pcwMentionMenu" hidden role="listbox" aria-label="Mention users"></div>
                <div class="pcw-activity-compose-actions" id="pcwActivityComposeActions" hidden>
                    <small class="pcw-activity-compose-hint">@mention emails them · Ctrl + Enter to save</small>
                    <button type="button" class="rpt-tb-btn" id="pcwValidateCancel">Cancel</button>
                    <button type="button" class="rpt-tb-btn rpt-tb-btn-primary" id="pcwValidateSave">Save</button>
                </div>
            </div>

            <div class="pcw-activity-feed" id="pcwHistoryBody" role="list">
                <div class="pcw-history-empty">No comments or activity yet.</div>
            </div>
        </div>
    </aside>
</div>
