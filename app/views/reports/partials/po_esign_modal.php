<?php
/*
 * PASS PO e-signature — full-screen PDF workspace + signature tools
 */
$esignUser = trim((string) (
    $esignUser
    ?? $_SESSION['username']
    ?? ($_SESSION['user_name'] ?? ($_SESSION['name'] ?? 'User'))
));
?>
<div class="pcw-esign-shell" id="pcwEsignModal" hidden aria-hidden="true">
    <aside class="pcw-esign-panel" role="dialog" aria-modal="true" aria-labelledby="pcwEsignTitle">
        <header class="pcw-esign-head">
            <div class="pcw-esign-head-top">
                <div class="pcw-esign-head-left">
                    <span class="pcw-esign-badge" aria-hidden="true">
                        <span class="material-icons-round">draw</span>
                    </span>
                    <div class="pcw-esign-head-titles">
                        <strong id="pcwEsignTitle">PO E-Signature</strong>
                        <p id="pcwEsignSub">Sign and save the purchase order PDF</p>
                    </div>
                </div>
                <div class="pcw-esign-head-right">
                    <span class="pcw-esign-po" id="pcwEsignPoLabel">PO —</span>
                    <span class="pcw-esign-user-chip" title="Signed in as">
                        <span class="material-icons-round">account_circle</span>
                        <?= e($esignUser) ?>
                    </span>
                    <button type="button" class="pcw-esign-close" id="pcwEsignClose" aria-label="Close">
                        <span class="material-icons-round">close</span>
                    </button>
                </div>
            </div>
            <div class="pcw-esign-log" id="pcwEsignLogDetails" aria-live="polite">
                <div class="pcw-esign-log-head">
                    <span class="material-icons-round">history</span>
                    <span>Log Details</span>
                </div>
                <dl class="pcw-esign-log-meta">
                    <div class="pcw-esign-log-item" title="Signed / action by">
                        <dt>Person</dt>
                        <dd id="pcwEsignLogUser">—</dd>
                    </div>
                    <div class="pcw-esign-log-item" title="Action time">
                        <dt>Time</dt>
                        <dd id="pcwEsignLogTime">—</dd>
                    </div>
                    <div class="pcw-esign-log-item" title="Last action">
                        <dt>Action</dt>
                        <dd id="pcwEsignLogAction">—</dd>
                    </div>
                    <div class="pcw-esign-log-item is-status" title="Status">
                        <dt>Status</dt>
                        <dd id="pcwEsignLogStatus">—</dd>
                    </div>
                </dl>
            </div>
        </header>

        <div class="pcw-esign-body">
            <section class="pcw-esign-side">
                <div class="pcw-esign-card">
                    <header class="pcw-esign-side-head">
                        <span class="material-icons-round">draw</span>
                        <div>
                            <strong>Signature</strong>
                            <p>Saved or draw, then place on the PDF</p>
                        </div>
                    </header>

                    <div class="pcw-esign-tabs" id="pcwEsignTabs" role="tablist">
                        <button type="button" class="is-active" data-esign-tab="upload" id="pcwEsignTabSaved">
                            Saved
                        </button>
                        <button type="button" data-esign-tab="draw" id="pcwEsignTabDraw">
                            Draw
                        </button>
                    </div>

                    <div class="pcw-esign-saved" id="pcwEsignSavedPane">
                        <div class="pcw-esign-sig-box" id="pcwEsignSavedBox">
                            <span class="pcw-esign-sig-drag material-icons-round" aria-hidden="true">drag_indicator</span>
                            <img id="pcwEsignSavedImg" alt="Saved signature" hidden>
                            <span id="pcwEsignSavedEmpty">No signature yet — upload or draw one</span>
                            <label class="pcw-esign-sig-pen" for="pcwEsignFile" title="Upload signature image">
                                <span class="material-icons-round">draw</span>
                            </label>
                        </div>
                        <div class="pcw-esign-sig-actions">
                            <button type="button" class="pcw-esign-btn is-secondary" id="pcwEsignUseSaved" title="Add signature on PDF (click again for another place)" disabled>
                                <span class="material-icons-round">back_hand</span>
                                Place on PDF
                            </button>
                            <div class="pcw-esign-sig-tools" role="group" aria-label="Signature tools">
                                <button type="button" class="pcw-esign-btn is-ghost is-icon" id="pcwEsignClearStamps" title="Clear places" aria-label="Clear places" disabled>
                                    <span class="material-icons-round">delete_sweep</span>
                                </button>
                                <button type="button" class="pcw-esign-btn is-ghost is-icon" id="pcwEsignCropBtn" title="Crop" aria-label="Crop" disabled>
                                    <span class="material-icons-round">crop</span>
                                </button>
                                <button type="button" class="pcw-esign-btn is-ghost is-icon" id="pcwEsignChangeSig" title="Replace" aria-label="Replace" hidden>
                                    <span class="material-icons-round">sync</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="pcw-esign-crop" id="pcwEsignCropPane" hidden>
                        <header class="pcw-esign-crop-head">
                            <strong>Crop signature</strong>
                            <span>Drag on the image to select ink only</span>
                        </header>
                        <div class="pcw-esign-crop-stage" id="pcwEsignCropStage">
                            <canvas id="pcwEsignCropCanvas" aria-label="Crop signature"></canvas>
                            <div class="pcw-esign-crop-sel" id="pcwEsignCropSel"></div>
                        </div>
                        <div class="pcw-esign-crop-actions">
                            <button type="button" class="pcw-esign-btn is-ghost" id="pcwEsignCropCancel">Cancel</button>
                            <button type="button" class="pcw-esign-btn is-primary" id="pcwEsignCropApply">
                                <span class="material-icons-round">check</span>
                                Apply crop
                            </button>
                        </div>
                    </div>

                    <div class="pcw-esign-upload" id="pcwEsignUploadPane" hidden>
                        <label class="pcw-esign-upload-btn" for="pcwEsignFile">
                            <span class="material-icons-round">cloud_upload</span>
                            <strong>Choose signature image</strong>
                            <em>PNG or JPG</em>
                        </label>
                        <input type="file" id="pcwEsignFile" accept="image/png,image/jpeg,image/jpg" hidden>
                    </div>

                    <div class="pcw-esign-draw" id="pcwEsignDrawPane" hidden>
                        <canvas id="pcwEsignCanvas" width="320" height="120" aria-label="Draw signature"></canvas>
                        <div class="pcw-esign-draw-actions">
                            <button type="button" class="pcw-esign-btn is-ghost" id="pcwEsignClear">Clear</button>
                            <button type="button" class="pcw-esign-btn is-primary" id="pcwEsignKeepDraw">Use this</button>
                        </div>
                    </div>

                    <div class="pcw-esign-assign">
                        <label class="pcw-esign-field">
                            <span>Assign to User</span>
                            <div class="pcw-esign-user-wrap">
                                <select id="pcwEsignAssignUser" class="pcw-esign-select">
                                    <option value="">— Select user —</option>
                                </select>
                            </div>
                        </label>
                        <label class="pcw-esign-field">
                            <span>Email</span>
                            <input type="email" id="pcwEsignAssignEmail" class="pcw-esign-input" placeholder="name@company.com" readonly>
                            <small>This email is filled automatically from the selected user.</small>
                        </label>
                        <label class="pcw-esign-field">
                            <span>Remarks</span>
                            <textarea id="pcwEsignRemarks" class="pcw-esign-textarea" rows="3" placeholder="Add a remark for this PO."></textarea>
                        </label>
                    </div>
                </div>

                <div class="pcw-esign-side-foot">
                    <button type="button" class="pcw-esign-btn is-approve" id="pcwEsignApprove" hidden title="Call POApprovalSet only (Value=X)">
                        <span class="material-icons-round">task_alt</span>
                        Approved
                    </button>
                    <button type="button" class="pcw-esign-btn is-save" id="pcwEsignSave">
                        <span class="material-icons-round">verified</span>
                        Save signed PO
                    </button>
                    <small id="pcwEsignApproveHint" hidden><b>Approved</b> = SAP Approve API · <b>Save signed PO</b> = save PDF file only (not SAP)</small>
                </div>
            </section>

            <section class="pcw-esign-preview" aria-label="PO PDF">
                <div class="pcw-esign-stage" id="pcwEsignStage">
                    <div class="pcw-esign-pdf-surface" id="pcwEsignPdfSurface">
                        <div class="pcw-esign-pdf-pages" id="pcwEsignPdfPages"></div>
                        <iframe id="pcwEsignFrame" class="pcw-esign-pdf-fallback" title="PO PDF" hidden></iframe>
                        <!-- Template only — live stamps are cloned onto PDF pages -->
                        <div class="pcw-esign-stamp" id="pcwEsignStamp" hidden aria-hidden="true">
                            <img id="pcwEsignStampImg" alt="" draggable="false">
                        </div>
                    </div>
                    <div class="pcw-esign-pdf-loading" id="pcwEsignPdfLoading" hidden>Loading PDF…</div>
                </div>
            </section>
        </div>
        <button type="button" class="pcw-esign-fab-close" id="pcwEsignFabClose" aria-label="Close">
            <span class="material-icons-round">close</span>
        </button>
        <button type="button" class="pcw-esign-tools-toggle" id="pcwEsignToolsToggle" aria-expanded="true" title="Show signature tools">
            <span class="material-icons-round">draw</span>
            <span>Tools</span>
        </button>

        <span id="pcwEsignLoginUser" hidden><?= e($esignUser) ?></span>
        <input type="hidden" id="pcwEsignRequestId" value="">
        <input type="hidden" id="pcwEsignPo" value="">
        <input type="hidden" id="pcwEsignSigData" value="">
        <input type="hidden" id="pcwEsignRawSig" value="">
        <input type="hidden" id="pcwEsignFromDb" value="0">
    </aside>
</div>
