/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 04/09/2026
 * DESCRIPTION : Sales hub — colorful charts, loader, and detail dashboards
 */
(function () {
    const cfg = window.SALES_PAGE || {};
    const symbol = cfg.currencySymbol || '$';
    const PALETTE = [
        '#3b82f6', // Electric Royal Blue
        '#ec4899', // Hot Pink / Fuchsia
        '#06b6d4', // Bright Turquoise Cyan
        '#10b981', // Vivid Emerald Green
        '#8b5cf6', // Royal Purple / Violet
        '#f59e0b', // Radiant Amber Gold
        '#f43f5e', // Vibrant Coral Rose
        '#14b8a6', // Deep Teal
        '#6366f1', // Indigo Iris
        '#d946ef', // Neon Fuchsia
        '#0ea5e9', // Sky Blue
        '#eab308'  // Goldenrod
    ];

    let currentPage = 1;
    let pageSize = 8000;
    let lastGroupCount = 0;
    let currentFrom = '';
    let currentTo = '';
    let loading = false;
    let lastPayload = null;
    let counterTimers = {};
    let loaderTimer = null;
    let loaderStarted = 0;

    let trendChart, divisionChart, stylesChart, channelChart, statusChart, soQtyChart, soMixChart;
    let currentLeftChartMode = 'auto';
    let currentRightChartMode = 'category';
    let lastChartsData = null;
    let soTableLines = [];
    let soTableParent = null;
    let soTableSort = { col: '', dir: 'asc' };

    const hub = document.getElementById('salesHub');
    const loaderNote = document.getElementById('salesLoaderNote');
    const tbody = document.getElementById('salesTableBody');
    const rangeInfo = document.getElementById('salesRangeInfo');
    const prevBtn = document.getElementById('salesPrev');
    const nextBtn = document.getElementById('salesNext');
    const pageNumbersContainer = document.getElementById('salesPageNumbers');
    const searchInput = document.getElementById('salesSearch');
    const pageSizeSelect = document.getElementById('salesPageSize');
    pageSize = 8000;
    const exportWrap = document.getElementById('salesExport');
    const exportBtn = document.getElementById('salesExportBtn');
    const exportMenu = document.getElementById('salesExportMenu');
    const drawer = document.getElementById('salesDetailDrawer');
    const backdrop = document.getElementById('salesDetailBackdrop');
    const detailTitle = document.getElementById('salesDetailTitle');
    const detailBody = document.getElementById('salesDetailBody');
    const closeBtn = document.getElementById('salesDetailClose');
    const periodBadge = document.getElementById('salesPeriodBadge');
    const liveCount = document.getElementById('salesLiveCount');
    const tableCount = document.getElementById('salesTableCount');

    // Enhanced Drawer Elements
    const drawerSoNum = document.getElementById('salesDetailSoNum');
    const drawerQtNum = document.getElementById('salesDetailQtNum');
    const drawerLineNum = document.getElementById('salesDetailLineNum');
    const drawerPlantNum = document.getElementById('salesDetailPlantNum');
    const drawerStatusPill = document.getElementById('salesDetailStatusPill');
    const drawerSub = document.getElementById('salesDetailSub');
    const drawerQuickNet = document.getElementById('drawerQuickNet');
    const drawerQuickQty = document.getElementById('drawerQuickQty');
    const drawerQuickDate = document.getElementById('drawerQuickDate');
    const drawerQuickPlant = document.getElementById('drawerQuickPlant');
    const drawerNetVal = document.getElementById('salesDetailNetVal');
    const drawerQtyVal = document.getElementById('salesDetailQtyVal');
    const drawerDateVal = document.getElementById('salesDetailDateVal');
    const subDrawer = document.getElementById('salesSubDrawer');
    const subDrawerBackBtn = document.getElementById('btnSubDrawerBack');
    const subDrawerCloseBtn = document.getElementById('subDrawerClose');
    const subDrawerCategoryBadge = document.getElementById('subDrawerCategoryBadge');
    const subDrawerLineBadge = document.getElementById('subDrawerLineBadge');
    const subDrawerStatusBadge = document.getElementById('subDrawerStatusBadge');
    const subDrawerTitle = document.getElementById('subDrawerTitle');
    const drawerNavCards = document.getElementById('salesDrawerNavCards');
    const soLineDrawer = document.getElementById('soLineDrawer');
    const soLineBack = document.getElementById('soLineBack');
    const soLineClose = document.getElementById('soLineClose');
    const soLineTitle = document.getElementById('soLineTitle');
    const soLineSub = document.getElementById('soLineSub');
    const soLineKicker = document.getElementById('soLineKicker');
    const soLineFacts = document.getElementById('soLineFacts');
    const dncPlanningVal = document.getElementById('dncPlanningVal');
    const dncBomVal = document.getElementById('dncBomVal');
    const dncMaterialVal = document.getElementById('dncMaterialVal');
    const dncProcVal = document.getElementById('dncProcVal');
    const panelPlanning = document.getElementById('panelPlanning');
    const panelBom = document.getElementById('panelBom');
    const panelMaterial = document.getElementById('panelMaterial');
    const panelProcurement = document.getElementById('panelProcurement');

    let currentDrawerRecord = null;
    let activeDrawerTab = null;
    const procCacheBySo = {};
    const bomCacheBySo = {};
    const matCacheByMat = {};

    let currentSortCol = '';
    let currentSortDir = 'asc';
    let currentRawRecords = [];
    let soDetailLines = {};
    let salesApiHubCache = {};
    let activeColFilters = {
        quotation: '',
        line_item: '',
        material: '',
        item_category: '',
        customer: '',
        customer_ref: '',
        sales_order: '',
        date: '',
        qty: '',
        net_amount: '',
        status: ''
    };

    // PROCUREMENT ELEMENTS
    const procCard      = document.getElementById('cardProcurement');
    const procModal     = document.getElementById('procurementModal');
    const procBackdrop = document.getElementById('procurementBackdrop');
    const procCloseBtn = document.getElementById('procurementModalClose');
    const procReloadBtn = document.getElementById('btnProcReload');
    const procSearchInput = document.getElementById('procSearchInput');
    const procSearchClear = document.getElementById('procSearchClear');
    const procGroupFilters = document.getElementById('procGroupFilters');
    const procFilteredCount = document.getElementById('procFilteredCount');
    const procFilterCountAll = document.getElementById('procFilterCountAll');
    const procTableBody = document.getElementById('procurementTableBody');
    const procTableView = document.getElementById('procurementTableView');
    const prDrawer = document.getElementById('procurementPrDrawer');
    const prDrawerClose = document.getElementById('prDrawerClose');
    const prDrawerTitle = document.getElementById('prDrawerTitle');
    const prDrawerSubtitle = document.getElementById('prDrawerSubtitle');
    const prDrawerBody = document.getElementById('prDrawerBody');
    const procStatComponents = document.getElementById('procStatComponents');
    const procStatReq = document.getElementById('procStatReq');
    const procStatPR = document.getElementById('procStatPR');
    const procStatPO = document.getElementById('procStatPO');
    const procStatStock = document.getElementById('procStatStock');
    const procSalesDocLabel = document.getElementById('procSalesDocLabel');
    const procHeaderMaterial = document.getElementById('procHeaderMaterial');
    const procHeaderDesc = document.getElementById('procHeaderDesc');

    // HEADER SALES ORDER SEARCH ELEMENTS
    const headerSoSearchInput = document.getElementById('headerSoSearchInput');
    const headerSoSearchClear = document.getElementById('headerSoSearchClear');
    const btnHeaderSoSearch = document.getElementById('btnHeaderSoSearch');
    const headerSoAutocomplete = document.getElementById('headerSoAutocomplete');
    const headerSoSearchWrap = document.getElementById('headerSoSearchWrap');
    const salesSearchClear = document.getElementById('salesSearchClear');
    const ordersKpiCard = document.querySelector('[data-kpi="orders"]');
    const activeFilterBanner = document.getElementById('salesActiveFilterBanner');
    const activeFilterText = document.getElementById('salesActiveFilterText');
    const activeFilterClear = document.getElementById('salesActiveFilterClear');

    /********** DASHBOARD SEARCH TERM FUNCTIONS *********   */
    function getDashboardSearchTerm() {
        return String(headerSoSearchInput?.value || searchInput?.value || '').trim();
    }

    function extractIdSearchList(term) {
        if (!term) return [];
        const cleaned = String(term).trim().replace(/(?:so|qt|quo(?:tation)?)\s*#?/gi, '');
        const tokens = cleaned.split(/[,;\s|\/]+/).map(t => t.trim()).filter(Boolean);
        if (tokens.length > 0 && tokens.every(t => /^\d{1,10}$/.test(t))) {
            return tokens;
        }
        return [];
    }

    /********** DASHBOARD SEARCH TERM FUNCTIONS *********   */
    function setDashboardSearchTerm(value) {
        const v = value == null ? '' : String(value);
        if (headerSoSearchInput && headerSoSearchInput.value !== v) headerSoSearchInput.value = v;
        if (searchInput && searchInput.value !== v) searchInput.value = v;
        const empty = !v.trim();
        if (headerSoSearchClear) headerSoSearchClear.hidden = empty;
        if (salesSearchClear) salesSearchClear.hidden = empty;
    }
    
    /********** CLEAR DASHBOARD SEARCH FUNCTION *********   */
    function clearDashboardSearch(refetch = true) {
        setDashboardSearchTerm('');
        if (headerSoAutocomplete) headerSoAutocomplete.hidden = true;
        if (activeFilterBanner) activeFilterBanner.style.display = 'none';
        if (refetch) fetchPage(1);
    }

    /********** DASHBOARD BACKDROP AND DRAWER ELEMENTS *********   */
    if (backdrop && backdrop.parentElement !== document.body) {
        document.body.appendChild(backdrop);
    }

    /********** SALES ORDER DETAIL DRAWER ELEMENTS *********   */
    if (drawer && drawer.parentElement !== document.body) {
        document.body.appendChild(drawer);
    }

    /********** PROCUREMENT BACKDROP AND MODAL ELEMENTS *********   */
    if (procBackdrop && procBackdrop.parentElement !== document.body) {
        document.body.appendChild(procBackdrop);
    }

    /********** PROCUREMENT MODAL ELEMENTS *********   */
    if (procModal && procModal.parentElement !== document.body) {
        document.body.appendChild(procModal);
    }

    /********** CHART ANIMATIONS *********   */
    const chartAnim = {
        duration: 1100,
        easing: 'easeOutQuart',
        delay(ctx) {
            if (ctx.type === 'data' && ctx.mode === 'default') {
                return ctx.dataIndex * 45;
            }
            return 0;
        },
    };

    /*
    * FUNTION OF MONEY FORMATTING
    */
    function fmtMoney(n) {
        return symbol + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    /********** NUMBER FORMATTING FUNCTION *********   */
    function fmtNum(n) {
        return Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 3 });
    }

    /*
    * FUNCTION OF NUMBER FORMATTING WITHOUT COMMA SEPARATORS
    */
    function fmtRawNum(n) {
        if (n === null || n === undefined || n === '') return '0';
        const num = parseFloat(String(n).replace(/,/g, ''));
        if (isNaN(num)) return '0';
        return (Math.round(num * 1000) / 1000).toString();
    }

    /********** ROUND NUMBER FORMATTING FUNCTION *********   */
    function fmtRoundNum(n) {
        if (n === null || n === undefined || n === '') return '0';
        const num = parseFloat(String(n).replace(/,/g, ''));
        if (isNaN(num)) return '0';
        return Math.round(num).toLocaleString();
    }

    /*
    * FUNCTION TO FORMAT SAP DATES (/Date(ms)/, YYYYMMDD, or ISO to DD Mon YYYY)
    */
    function fmtSapDate(d) {
        if (!d || d === '00000000') return '—';
        const s = String(d).trim();
        if (!s || s === 'null' || s === 'undefined') return '—';

        // 1. SAP OData /Date(1788220800000)/ or /Date(1788220800000+0000)/
        const odataMatch = s.match(/\/Date\((-?\d+)(?:[+-]\d+)?\)\//);
        if (odataMatch) {
            const ms = parseInt(odataMatch[1], 10);
            if (!isNaN(ms)) {
                const dt = new Date(ms);
                const day = String(dt.getUTCDate()).padStart(2, '0');
                const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                const mName = months[dt.getUTCMonth()] || '';
                const year = dt.getUTCFullYear();
                return `${day} ${mName} ${year}`;
            }
        }

        // 2. YYYYMMDD (e.g. 20260901)
        if (s.length === 8 && /^\d{8}$/.test(s)) {
            const year = s.substring(0, 4);
            const month = s.substring(4, 6);
            const day = s.substring(6, 8);
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const mIdx = parseInt(month, 10) - 1;
            const mName = months[mIdx] || month;
            return `${day} ${mName} ${year}`;
        }

        // 3. ISO format YYYY-MM-DD (e.g. 2026-09-01 or 2026-09-01T00:00:00)
        const isoMatch = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (isoMatch) {
            const year = isoMatch[1];
            const month = isoMatch[2];
            const day = isoMatch[3];
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const mIdx = parseInt(month, 10) - 1;
            const mName = months[mIdx] || month;
            return `${day} ${mName} ${year}`;
        }

        // 4. Timestamp string (10 or 13 digits)
        if (/^\d{10,13}$/.test(s)) {
            const num = parseInt(s, 10);
            const ms = s.length === 10 ? num * 1000 : num;
            const dt = new Date(ms);
            if (!isNaN(dt.getTime())) {
                const day = String(dt.getUTCDate()).padStart(2, '0');
                const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                const mName = months[dt.getUTCMonth()] || '';
                const year = dt.getUTCFullYear();
                return `${day} ${mName} ${year}`;
            }
        }

        return s;
    }

    /*
    * FUNTION OF STATUS
    */
    function statusClass(status) {
        const s = String(status || '').toLowerCase();
        if (s.includes('complete') || s.includes('closed') || s.includes('done')) return 'is-done';
        if (s.includes('return') || s.includes('cancel') || s.includes('block')) return 'is-warn';
        return 'is-open';
    }

    /*
    * FUNTION OF ESCAPE HTML
    */
    function escapeHtml(str) {
        const el = document.createElement('span');
        el.textContent = String(str ?? '');
        return el.innerHTML;
    }

    /*
    * FUNTION OF COLOR AT
    */
    function colorAt(i) {
        return PALETTE[i % PALETTE.length];
    }

    /*
    * FUNTION OF COLOR SET
    */
    function colorSet(n) {
        return Array.from({ length: n }, (_, i) => colorAt(i));
    }

    /*
    * FUNTION OF SHOW SALES LOADER
    */
    function emptyRowHtml(title, hint, cols) {
        const html = (typeof window.kapisEmptyHtml === 'function')
            ? window.kapisEmptyHtml(title, hint)
            : `<div class="empty-state">${title}<br>${hint || ''}</div>`;
        return `<tr><td colspan="${cols || 11}" class="empty-state">${html}</td></tr>`;
    }

    /********** SHOW SALES LOADER FUNCTION *********   */
    function showSalesLoader(active) {
        const loader = document.getElementById('rptSpinner');
        if (hub) hub.classList.toggle('is-loading', active);
        document.documentElement.classList.toggle('sales-is-loading', active);
        document.body.classList.toggle('sales-is-loading', active);
        document.body.classList.toggle('rpt-loading', active);
        if (active && window.kapisSplash && typeof window.kapisSplash.hide === 'function') {
            window.kapisSplash.hide();
        }
        const splash = document.getElementById('kapisSplash');
        if (active && splash) {
            splash.classList.add('is-done');
            splash.setAttribute('hidden', '');
        }
        if (loader) loader.hidden = !active;
    }

    /*
    * FUNTION OF ANIMATE VALUE
    */
    function animateValue(el, endValue, formatter, duration = 700) {
        if (!el) return;
        const id = el.id;
        if (counterTimers[id]) cancelAnimationFrame(counterTimers[id]);
        const startValue = Number(el.dataset.lastValue) || 0;
        el.dataset.lastValue = String(endValue);
        const startTime = performance.now();

        function tick(now) {
            const progress = Math.min((now - startTime) / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            const current = startValue + (endValue - startValue) * eased;
            el.textContent = formatter(current);
            if (progress < 1) {
                counterTimers[id] = requestAnimationFrame(tick);
            } else {
                el.textContent = formatter(endValue);
            }
        }
        counterTimers[id] = requestAnimationFrame(tick);
    }

    /*
    * FUNTION OF APPLY FILTER
    */
    function applyFilter(value) {
        const term = String(value || '').replace(/^SO\s+/i, '').trim();
        if (!term) return;
        setDashboardSearchTerm(term);
        fetchPage(1);
    }

    /*
    * FUNTION OF CLICK FILTER
    */
    function clickFilter(chart) {
        return (evt, elements) => {
            if (!elements.length) return;
            const label = chart.data.labels[elements[0].index];
            applyFilter(label);
        };
    }

    /*
    * FUNTION OF RENDER BOARD
    */
    function renderBoard(el, series, colors) {
        if (!el) return;
        const labels = series?.labels || [];
        const values = (series?.values || []).map(Number);
        const total = values.reduce((s, v) => s + v, 0);
        if (!labels.length || (labels[0] === 'No data' && !total)) {
            el.innerHTML = '<p class="empty-state">No data</p>';
            return;
        }
        el.innerHTML = labels.map((label, i) => {
            const value = values[i] || 0;
            const pct = total > 0 ? Math.round((value / total) * 100) : 0;
            const color = colors[i] || colorAt(i);
            return `
                <button type="button" data-filter="${escapeHtml(label)}">
                    <i class="sales-rank-dot" style="background:${color}"></i>
                    <div>
                        <div class="sales-rank-meta">
                            <span>${escapeHtml(label)}</span>
                            <span>${fmtMoney(value)}</span>
                        </div>
                        <div class="sales-rank-bar"><i style="width:${pct}%;background:${color}"></i></div>
                    </div>
                    <b>${pct}%</b>
                </button>
            `;
        }).join('');
        el.querySelectorAll('[data-filter]').forEach((btn) => {
            btn.addEventListener('click', () => applyFilter(btn.dataset.filter));
        });
    }

    /*
    * FUNTION OF RENDER BOARDS
    */
    function renderBoards(charts) {
        renderBoard(document.getElementById('plantBoard'), charts.plants || charts.mix, PALETTE);
        renderBoard(document.getElementById('divisionBoard'), charts.divisions, ['#db2777', '#6366f1', '#f59e0b', '#10b981']);
        renderBoard(document.getElementById('statusBoard'), charts.statuses, ['#0ea5e9', '#10b981', '#f59e0b', '#f43f5e']);
        renderBoard(document.getElementById('materialBoard'), charts.top_styles, ['#f59e0b', '#f43f5e', '#7c3aed', '#2563eb', '#10b981', '#22d3ee']);
    }

    function groupRows(title, rows) {
        return `
            <div class="sales-detail-group">
                <h4>${escapeHtml(title)}</h4>
                ${rows.map(([label, value]) => `
                    <div class="sales-detail-row">
                        <span>${escapeHtml(label)}</span>
                        <strong>${escapeHtml(value)}</strong>
                    </div>
                `).join('')}
            </div>
        `;
    }

    /* ==========================================================================
       Interactive Sales Order Offcanvas (Planning, BOM, Material, Procurement)
    ========================================================================== */

    function fillSubDrawerSoBadge() {
        const soEl = document.getElementById('subDrawerSoNum');
        const lineEl = document.getElementById('subDrawerLineNum');
        const so = currentDrawerRecord?.sales_order || '—';
        const line = currentDrawerRecord?.line_item || '—';
        if (soEl) soEl.textContent = so;
        if (lineEl) lineEl.textContent = line;
        document.querySelectorAll('#subDrawerSoBadge, #subDrawerLineBadge').forEach((pill) => {
            pill.style.animation = 'none';
            void pill.offsetWidth;
            pill.style.animation = '';
        });
    }

    function openSubDrawer(tabName) {
        if (!currentDrawerRecord || !tabName) return;
        activeDrawerTab = tabName;

        const titles = {
            planning: 'Delivery & Schedule Planning',
            bom: 'Bill of Materials (BOM)',
            material: 'Material Master Specifications',
            procurement: 'Procurement Overview'
        };
        const tags = {
            planning: 'Schedule',
            bom: 'BOM',
            material: 'Master',
            procurement: 'Live SAP'
        };

        if (subDrawerTitle) subDrawerTitle.textContent = titles[tabName] || 'Category Details';
        fillSubDrawerSoBadge();

        const panels = {
            planning: panelPlanning,
            bom: panelBom,
            material: panelMaterial,
            procurement: panelProcurement
        };

        Object.keys(panels).forEach(k => {
            const p = panels[k];
            if (p) {
                p.hidden = (k !== tabName);
                p.classList.toggle('is-active', k === tabName);
            }
        });

        if (drawerNavCards) {
            drawerNavCards.querySelectorAll('.so-sheet-tab, .drawer-nav-card, .so-module').forEach((btn) => {
                const on = btn.getAttribute('data-tab') === tabName;
                btn.classList.toggle('active', on);
                btn.setAttribute('aria-selected', on ? 'true' : 'false');
            });
        }

        if (tabName === 'planning') {
            renderPlanningPanel(currentDrawerRecord);
        } else if (tabName === 'bom') {
            loadDrawerBom(currentDrawerRecord);
        } else if (tabName === 'material') {
            loadDrawerMaterial(currentDrawerRecord, false, 'material');
        } else if (tabName === 'procurement') {
            loadDrawerProcurement(currentDrawerRecord);
        }

        if (subDrawer) {
            subDrawer.classList.add('open');
            subDrawer.setAttribute('aria-hidden', 'false');
        }
    }

    function openMaterialDetailsFor(materialCode, sourceTab) {
        const mat = String(materialCode || '').trim();
        if (!currentDrawerRecord || !mat || mat === '—') return;

        activeDrawerTab = 'material';
        if (subDrawerTitle) subDrawerTitle.textContent = 'Material Master • ' + mat;
        fillSubDrawerSoBadge();

        const panels = {
            planning: panelPlanning,
            bom: panelBom,
            material: panelMaterial,
            procurement: panelProcurement
        };
        Object.keys(panels).forEach((k) => {
            const p = panels[k];
            if (p) {
                p.hidden = k !== 'material';
                p.classList.toggle('is-active', k === 'material');
            }
        });

        loadDrawerMaterial(
            { ...currentDrawerRecord, material: mat, _materialSourceTab: sourceTab || 'bom' },
            false,
            'material'
        );

        if (subDrawer) {
            subDrawer.classList.add('open');
            subDrawer.setAttribute('aria-hidden', 'false');
        }
    }

    function closeSubDrawer() {
        if (subDrawer) {
            subDrawer.classList.remove('open');
            subDrawer.setAttribute('aria-hidden', 'true');
        }
        activeDrawerTab = null;
        if (drawerNavCards) {
            drawerNavCards.querySelectorAll('.so-sheet-tab, .drawer-nav-card, .so-module').forEach((btn) => {
                btn.classList.remove('active');
                btn.setAttribute('aria-selected', 'false');
            });
        }
    }

    /********** SWITCH DRAWER TAB FUNCTION *********   */
    function switchDrawerTab(tabName) {
        if (!tabName) {
            closeSubDrawer();
        } else {
            openSubDrawer(tabName);
        }
    }
    
    /********** DRAWER NAV CARD CLICK EVENT LISTENER *********   */
    if (drawerNavCards) {
        drawerNavCards.querySelectorAll('.drawer-nav-card, .so-sheet-tab, .so-module').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const tab = btn.getAttribute('data-tab');
                if (tab) openSubDrawer(tab);
            });
        });
    }

    /********** SO LINE DRAWER BACK BUTTON CLICK EVENT LISTENER *********   */
    soLineBack?.addEventListener('click', (e) => {
        e.stopPropagation();
        closeSoLineDrawer();
    });
    soLineClose?.addEventListener('click', (e) => {
        e.stopPropagation();
        closeSoLineDrawer();
    });

    subDrawerBackBtn?.addEventListener('click', (e) => {
        e.stopPropagation();
        closeSubDrawer();
    });

    subDrawerCloseBtn?.addEventListener('click', (e) => {
        e.stopPropagation();
        closeSoLineDrawer();
    });

    /********** RENDER PLANNING PANEL FUNCTION *********   */
    function renderPlanningPanel(record) {
        if (!panelPlanning) return;
        const isFixed = record.delivery_date_quantity_is_fixed === 'X' || record.delivery_date_quantity_is_fixed === 'true';
        const isPartial = record.partial_delivery_is_allowed === 'X' || record.partial_delivery_is_allowed === 'true';
        const isDelivRel = record.item_is_delivery_relevant === 'X' || record.item_is_delivery_relevant === 'true';

        panelPlanning.innerHTML = `
            <div class="drawer-section-card">
                <div class="drawer-sec-header">
                    <div class="drawer-sec-title-wrap">
                        <span class="material-icons-round drawer-sec-icon" style="color:#0d9488">request_quote</span>
                        <h4 class="drawer-sec-title">Quotation → Sales Order</h4>
                    </div>
                    <span class="category-tag">${escapeHtml(record.quotation_type || record.item_category || 'QT')}</span>
                </div>
                <div class="drawer-info-grid">
                    <div class="drawer-info-item">
                        <span class="info-lbl">Quotation</span>
                        <span class="info-val highlight-blue">${escapeHtml(record.quotation || '—')}</span>
                    </div>
                    <div class="drawer-info-item">
                        <span class="info-lbl">Follow-on Sales Order</span>
                        <span class="info-val">${escapeHtml(record.sales_order || '—')}</span>
                    </div>
                    <div class="drawer-info-item">
                        <span class="info-lbl">Sales Order Type</span>
                        <span class="info-val">${escapeHtml(record.sales_order_type || '—')}</span>
                    </div>
                    <div class="drawer-info-item">
                        <span class="info-lbl">Item / Material</span>
                        <span class="info-val">${escapeHtml(record.line_item || '—')} • ${escapeHtml(record.material || '—')}</span>
                    </div>
                    <div class="drawer-info-item">
                        <span class="info-lbl">Requested Delivery</span>
                        <span class="info-val">${escapeHtml(fmtSapDate(record.requested_delivery_date) || record.requested_delivery_date || '—')}</span>
                    </div>
                    <div class="drawer-info-item">
                        <span class="info-lbl">Valid From / To</span>
                        <span class="info-val">${escapeHtml(fmtSapDate(record.valid_from) || record.valid_from || '—')} → ${escapeHtml(fmtSapDate(record.valid_to) || record.valid_to || '—')}</span>
                    </div>
                </div>
            </div>
            <div class="drawer-section-card">
                <div class="drawer-sec-header">
                    <div class="drawer-sec-title-wrap">
                        <span class="material-icons-round drawer-sec-icon" style="color:#2563eb">event_available</span>
                        <h4 class="drawer-sec-title">Delivery & Schedule Planning</h4>
                    </div>
                    <span class="sales-status-pill ${statusClass(record.status)}">${escapeHtml(record.status || 'Active')}</span>
                </div>
                <div class="drawer-info-grid">
                    <div class="drawer-info-item">
                        <span class="info-lbl">Confirmed Delivery Qty</span>
                        <span class="info-val highlight-blue">${fmtNum(record.confd_deliv_qty || record.qty)} ${escapeHtml(record.unit || 'EA')}</span>
                    </div>
                    <div class="drawer-info-item">
                        <span class="info-lbl">Requested Quantity</span>
                        <span class="info-val">${fmtNum(record.requested_quantity || record.qty)} ${escapeHtml(record.requested_quantity_unit || record.unit || 'EA')}</span>
                    </div>
                    <div class="drawer-info-item">
                        <span class="info-lbl">Target Quantity</span>
                        <span class="info-val">${fmtNum(record.target_quantity || record.qty)} ${escapeHtml(record.target_quantity_unit || record.unit || 'EA')}</span>
                    </div>
                    <div class="drawer-info-item">
                        <span class="info-lbl">Base Unit</span>
                        <span class="info-val">${escapeHtml(record.base_unit || record.unit || 'EA')}</span>
                    </div>
                    <div class="drawer-info-item">
                        <span class="info-lbl">Delivery Confirmation</span>
                        <span class="info-val highlight-green">${escapeHtml(record.delivery_confirmation_status || 'Confirmed')}</span>
                    </div>
                    <div class="drawer-info-item">
                        <span class="info-lbl">SD Process Status</span>
                        <span class="info-val">${escapeHtml(record.sd_process_status || record.status || 'Active')}</span>
                    </div>
                    <div class="drawer-info-item">
                        <span class="info-lbl">Total Delivery Status</span>
                        <span class="info-val">${escapeHtml(record.total_delivery_status || record.delivery_status || 'Open')}</span>
                    </div>
                    <div class="drawer-info-item">
                        <span class="info-lbl">Delivery Block Status</span>
                        <span class="info-val">${escapeHtml(record.delivery_block_status || 'None')}</span>
                    </div>
                </div>
            </div>

            <div class="drawer-section-card">
                <div class="drawer-sec-header">
                    <div class="drawer-sec-title-wrap">
                        <span class="material-icons-round drawer-sec-icon" style="color:#0284c7">local_shipping</span>
                        <h4 class="drawer-sec-title">Logistics & Dispatch Rules</h4>
                    </div>
                    ${getPlantBadgeHtml(record.plant)}
                </div>
                <div class="drawer-info-grid">
                    <div class="drawer-info-item">
                        <span class="info-lbl">Plant</span>
                        <span class="info-val">${escapeHtml(record.plant || '—')}</span>
                    </div>
                    <div class="drawer-info-item">
                        <span class="info-lbl">Storage Location</span>
                        <span class="info-val">${escapeHtml(record.storage_location || '—')}</span>
                    </div>
                    <div class="drawer-info-item">
                        <span class="info-lbl">Shipping Point</span>
                        <span class="info-val">${escapeHtml(record.shipping_point || '—')}</span>
                    </div>
                    <div class="drawer-info-item">
                        <span class="info-lbl">Shipping Type</span>
                        <span class="info-val">${escapeHtml(record.shipping_type || '—')}</span>
                    </div>
                    <div class="drawer-info-item">
                        <span class="info-lbl">Delivery Priority</span>
                        <span class="info-val">${escapeHtml(record.delivery_priority || 'Standard')}</span>
                    </div>
                    <div class="drawer-info-item">
                        <span class="info-lbl">Route</span>
                        <span class="info-val">${escapeHtml(record.route || '—')}</span>
                    </div>
                    <div class="drawer-info-item">
                        <span class="info-lbl">Partial Delivery Allowed</span>
                        <span class="info-val">${isPartial ? 'Yes' : 'No'}</span>
                    </div>
                    <div class="drawer-info-item">
                        <span class="info-lbl">Date & Qty Fixed</span>
                        <span class="info-val">${isFixed ? 'Yes' : 'No'}</span>
                    </div>
                    <div class="drawer-info-item full-width">
                        <span class="info-lbl">Delivery Relevance</span>
                        <span class="info-val">${isDelivRel ? 'Relevant for Delivery / Shipping' : 'Standard'}</span>
                    </div>
                </div>
            </div>
        `;
    }

    /********** COPY TEXT TO CLIPBOARD FUNCTION *********   */
    function copyTextToClipboard(text, btn) {
        if (!text) return;
        const doSuccess = () => {
            if (btn) {
                const orig = btn.innerHTML;
                btn.innerHTML = '<span class="material-icons-round" style="font-size:0.95rem">check</span> Copied!';
                setTimeout(() => { btn.innerHTML = orig; }, 1800);
            }
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(doSuccess).catch(() => fallbackCopy(text, doSuccess));
        } else {
            fallbackCopy(text, doSuccess);
        }
    }

    /********** FALLBACK COPY TEXT TO CLIPBOARD FUNCTION *********   */
    function fallbackCopy(text, cb) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            if (cb) cb();
        } catch (e) { }
        document.body.removeChild(ta);
    }

    /* ==========================================================================
       BOM Panel & SAP Live Data Loader (ZC_COOISCOMP_HUB_CDS)
    ========================================================================== */

    function bomRowLineNo(row) {
        return row?.SalesOrderItem || row?.SalesDocumentItem || row?.SalesOrderItemNumber || row?.Item || '';
    }

    function filterBomByLine(rows, lineItem) {
        const list = Array.isArray(rows) ? rows : [];
        const rawLine = String(lineItem ?? '').trim();
        if (!rawLine || rawLine === '—') return list;
        const line = normLineNo(rawLine);
        const keyed = list.filter((c) => String(bomRowLineNo(c)).trim() !== '');
        if (!keyed.length) return list;
        return list.filter((c) => normLineNo(bomRowLineNo(c)) === line);
    }

    function renderBomPanel(record, isLoading = false, error = null, bomData = null, rawPayload = null) {
        if (!panelBom) return;
        const so = String(record?.sales_order || '').trim();

        if (isLoading) {
            panelBom.innerHTML = `
                <div class="drawer-section-card" style="text-align:center;padding:3rem 1rem">
                    <div class="sales-loader-ring" style="margin:0 auto 1.25rem;width:42px;height:42px;border-width:3.5px;border-top-color:#0d9488"></div>
                    <strong style="font-size:1rem;color:#0f172a;display:block;margin-bottom:0.35rem">Connecting to SAP BOM CDS API</strong>
                    <span style="font-size:0.82rem;color:#64748b">Fetching live Bill of Materials for Sales Order #${escapeHtml(so)}${record?.line_item ? ` • Line ${escapeHtml(String(record.line_item))}` : ''}…</span>
                </div>
            `;
            return;
        }

        if (error) {
            panelBom.innerHTML = `
                <div class="drawer-section-card" style="text-align:center;padding:2.5rem 1rem">
                    <span class="material-icons-round" style="font-size:2.6rem;color:#ef4444;margin-bottom:0.6rem;display:block">error_outline</span>
                    <div style="font-size:1rem;font-weight:700;color:#0f172a;margin-bottom:0.35rem">SAP BOM Notice</div>
                    <div style="color:#64748b;font-size:0.85rem;margin-bottom:1.25rem">${escapeHtml(error)}</div>
                    <button type="button" class="btn-drawer-retry-bom" style="padding:0.5rem 1.2rem;background:#0d9488;color:#fff;border:none;border-radius:0.55rem;font-weight:700;font-size:0.85rem;cursor:pointer;display:inline-flex;align-items:center;gap:0.4rem">
                        <span class="material-icons-round" style="font-size:1rem">sync</span> Retry SAP Sync
                    </button>
                </div>
            `;
            const retryBtn = panelBom.querySelector('.btn-drawer-retry-bom');
            if (retryBtn) {
                retryBtn.addEventListener('click', () => loadDrawerBom(record, true));
            }
            return;
        }

        const comps = filterBomByLine(bomData || [], record?.line_item);
        let totalPlan = 0, totalPrd = 0, totalBomQty = 0;
        comps.forEach(c => {
            totalPlan += parseFloat(c.PlanQty || 0);
            totalPrd += parseFloat(c.PrdQty || 0);
            totalBomQty += parseFloat(c.BOMQuantity || 0);
        });

        const rawJsonString = JSON.stringify(rawPayload || { data: comps }, null, 2);

        panelBom.innerHTML = `
            <!-- Colorful BOM Stat Cards -->
            <div class="bom-stat-grid">
                <div class="bom-stat-card tone-teal">
                    <div class="bom-stat-icon-wrap">
                        <span class="material-icons-round" style="font-size:1.45rem">schema</span>
                    </div>
                    <div class="bom-stat-body">
                        <span class="bom-stat-label">Total Components</span>
                        <span class="bom-stat-val" id="bomStatComponents">${comps.length}</span>
                        <span class="bom-stat-sub" id="bomStatComponentsSub">BOM Line Items</span>
                    </div>
                </div>

                <div class="bom-stat-card tone-blue">
                    <div class="bom-stat-icon-wrap">
                        <span class="material-icons-round" style="font-size:1.45rem">assignment_turned_in</span>
                    </div>
                    <div class="bom-stat-body">
                        <span class="bom-stat-label">Plan Quantity</span>
                        <span class="bom-stat-val" id="bomStatPlan">${fmtRoundNum(totalPlan)}</span>
                        <span class="bom-stat-sub" id="bomStatPlanSub">Planned Demand</span>
                    </div>
                </div>

                <div class="bom-stat-card tone-emerald">
                    <div class="bom-stat-icon-wrap">
                        <span class="material-icons-round" style="font-size:1.45rem">factory</span>
                    </div>
                    <div class="bom-stat-body">
                        <span class="bom-stat-label">Prd Quantity</span>
                        <span class="bom-stat-val" id="bomStatPrd">${fmtRoundNum(totalPrd)}</span>
                        <span class="bom-stat-sub" id="bomStatPrdSub">Confirmed Produced</span>
                    </div>
                </div>

                <div class="bom-stat-card tone-purple">
                    <div class="bom-stat-icon-wrap">
                        <span class="material-icons-round" style="font-size:1.45rem">inventory_2</span>
                    </div>
                    <div class="bom-stat-body">
                        <span class="bom-stat-label">BOM Quantity</span>
                        <span class="bom-stat-val" id="bomStatBomQty">${fmtRoundNum(totalBomQty)}</span>
                        <span class="bom-stat-sub" id="bomStatBomSub">Total Component Ratio</span>
                    </div>
                </div>
            </div>

            <!-- Table View with 20 items per page & Pagination -->
            <div id="bomTableView" class="sap-view-pane">
                <div class="drawer-section-card" style="padding:0;overflow:hidden;border:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,0.03)">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1.1rem 1.25rem;border-bottom:1px solid #f1f5f9;flex-wrap:wrap">
                        <div style="display:flex;align-items:center;gap:0.65rem;flex-wrap:wrap">
                            <span class="material-icons-round" style="color:#0d9488;font-size:1.25rem">format_list_bulleted</span>
                            <span style="font-size:0.95rem;font-weight:700;color:#0f172a">Bill of Materials Item Explosion</span>
                            <span class="so-badge-pill" id="bomTotalBadge" style="background:#ccfbf1;color:#0f766e;border-color:#99f6e4;font-weight:700;font-size:0.8rem;padding:0.25rem 0.75rem">
                                Total: ${comps.length} components
                            </span>
                        </div>
                        <div style="display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap">
                            <input type="text" class="bom-search-input" placeholder="Search Item or Material…" style="padding:0.42rem 0.85rem;border:1px solid #cbd5e1;border-radius:0.55rem;font-size:0.82rem;outline:none;min-width:220px;box-shadow:inset 0 1px 2px rgba(0,0,0,0.03)"/>
                            <div class="bom-export" id="bomExport">
                                <button type="button" class="sales-export-btn bom-export-btn" id="bomExportBtn" aria-haspopup="menu" aria-expanded="false">
                                    <span class="material-icons-round">file_download</span>
                                    Export
                                    <span class="material-icons-round chev">expand_more</span>
                                </button>
                                <div class="sales-export-menu" id="bomExportMenu" hidden>
                                    <button type="button" data-bom-export="filtered">Filtered rows (CSV)</button>
                                    <button type="button" data-bom-export="all">All components (CSV)</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="drawer-components-table-wrap" style="max-height:none;overflow-x:auto">
                        <table class="drawer-components-table bom-items-table table-bordered">
                            <thead>
                                <tr class="bom-header-row">
                                    <th class="col-sortable" data-sort="Item" title="Click to sort by Item" style="width:110px">
                                        <span>Item</span>
                                        <span class="sort-icon material-icons-round">unfold_more</span>
                                    </th>
                                    <th class="col-sortable" data-sort="Material" title="Click to sort by Material">
                                        <span>Material</span>
                                        <span class="sort-icon material-icons-round">unfold_more</span>
                                    </th>
                                    <th class="col-sortable" data-sort="PlanQty" title="Click to sort by Plan Quantity" style="text-align:right;width:140px">
                                        <span>Plan Qty</span>
                                        <span class="sort-icon material-icons-round">unfold_more</span>
                                    </th>
                                    <th class="col-sortable" data-sort="PrdQty" title="Click to sort by Prd Quantity" style="text-align:right;width:140px">
                                        <span>Prd Qty</span>
                                        <span class="sort-icon material-icons-round">unfold_more</span>
                                    </th>
                                    <th class="col-sortable" data-sort="BOMQuantity" title="Click to sort by BOM Quantity" style="text-align:right;width:150px">
                                        <span>BOM Quantity</span>
                                        <span class="sort-icon material-icons-round">unfold_more</span>
                                    </th>
                                </tr>
                                <tr class="mat-col-filter-row bom-col-filter-row">
                                    <th><input type="text" class="mat-col-filter-input bom-col-filter-input" data-filter-col="Item" placeholder="Filter item..."/></th>
                                    <th><input type="text" class="mat-col-filter-input bom-col-filter-input" data-filter-col="Material" placeholder="Filter material..."/></th>
                                    <th><input type="text" class="mat-col-filter-input bom-col-filter-input" data-filter-col="PlanQty" placeholder="Min plan..." style="text-align:right"/></th>
                                    <th><input type="text" class="mat-col-filter-input bom-col-filter-input" data-filter-col="PrdQty" placeholder="Min prd..." style="text-align:right"/></th>
                                    <th>
                                        <div style="display:flex;align-items:center;gap:0.25rem">
                                            <input type="text" class="mat-col-filter-input bom-col-filter-input" data-filter-col="BOMQuantity" placeholder="Min bom..." style="text-align:right"/>
                                            <button type="button" class="btn-clear-mat-filters" id="btnClearBomFilters" title="Clear all column filters">
                                                <span class="material-icons-round" style="font-size:13px">clear</span>
                                            </button>
                                        </div>
                                    </th>
                                </tr>
                            </thead>
                            <tbody id="bomTableBody">
                                <!-- Populated dynamically by page -->
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination Bar -->
                    <div class="bom-pagination-wrap" id="bomPaginationWrap">
                        <div class="bom-pagination-info" id="bomPaginationInfo">
                            Showing 0 to 0 of 0 components
                        </div>
                        <div class="bom-pagination-btns" id="bomPaginationBtns">
                            <!-- Page buttons -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- Raw JSON View -->
            <div id="bomRawView" class="sap-view-pane" style="display:none">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.6rem">
                    <span style="font-size:0.8rem;color:#64748b;font-weight:600">Total entities returned: ${comps.length}</span>
                    <button type="button" class="btn-copy-bom-json" style="padding:0.4rem 0.85rem;border-radius:0.5rem;border:1px solid #cbd5e1;background:#fff;cursor:pointer;font-size:0.78rem;font-weight:600;display:inline-flex;align-items:center;gap:0.35rem">
                        <span class="material-icons-round" style="font-size:0.95rem">content_copy</span> Copy Raw JSON
                    </button>
                </div>
                <pre class="sap-raw-json-view">${escapeHtml(rawJsonString)}</pre>
            </div>
        `;

        // Sync & Copy JSON button listeners
        const syncBtn = panelBom.querySelector('.btn-sync-bom-data');
        if (syncBtn) {
            syncBtn.addEventListener('click', () => loadDrawerBom(record, true));
        }

        const copyJsonBtn = panelBom.querySelector('.btn-copy-bom-json');
        if (copyJsonBtn) {
            copyJsonBtn.addEventListener('click', () => copyTextToClipboard(rawJsonString, copyJsonBtn));
        }

        // View tabs switcher
        const viewBtns = panelBom.querySelectorAll('.sap-view-toggle-btn');
        viewBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                viewBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const targetId = btn.getAttribute('data-target');
                const tv = panelBom.querySelector('#bomTableView');
                const rv = panelBom.querySelector('#bomRawView');
                if (tv) tv.style.display = (targetId === 'bomTableView') ? 'block' : 'none';
                if (rv) rv.style.display = (targetId === 'bomRawView') ? 'block' : 'none';
            });
        });

        // 20 items per page Pagination Implementation
        const PAGE_SIZE = 20;
        let currentBomPage = 1;
        let currentBomSortCol = null;
        let currentBomSortDir = 'asc';
        const activeBomColFilters = {
            Item: '',
            Material: '',
            PlanQty: '',
            PrdQty: '',
            BOMQuantity: ''
        };
        let activeBomGlobalSearch = '';
        let activeFilteredComps = comps.slice();

        const tbodyEl = panelBom.querySelector('#bomTableBody');
        const infoEl = panelBom.querySelector('#bomPaginationInfo');
        const btnsEl = panelBom.querySelector('#bomPaginationBtns');
        const totalBadgeEl = panelBom.querySelector('#bomTotalBadge');

        function sumBomRows(rows) {
            let plan = 0, prd = 0, bomQty = 0;
            (rows || []).forEach((c) => {
                plan += parseFloat(c.PlanQty || 0) || 0;
                prd += parseFloat(c.PrdQty || 0) || 0;
                bomQty += parseFloat(c.BOMQuantity || 0) || 0;
            });
            return { count: (rows || []).length, plan, prd, bomQty };
        }

        function updateBomStatCards(rows) {
            const t = sumBomRows(rows);
            const filtered = t.count !== comps.length;
            const setText = (id, value) => {
                const el = panelBom.querySelector(id);
                if (el) el.textContent = value;
            };
            setText('#bomStatComponents', String(t.count));
            setText('#bomStatPlan', fmtRoundNum(t.plan));
            setText('#bomStatPrd', fmtRoundNum(t.prd));
            setText('#bomStatBomQty', fmtRoundNum(t.bomQty));
            setText('#bomStatComponentsSub', filtered ? `${t.count} of ${comps.length} line items` : 'BOM Line Items');
            setText('#bomStatPlanSub', filtered ? 'Filtered planned demand' : 'Planned Demand');
            setText('#bomStatPrdSub', filtered ? 'Filtered produced qty' : 'Confirmed Produced');
            setText('#bomStatBomSub', filtered ? 'Filtered component ratio' : 'Total Component Ratio');
        }

        function bomRowsToCsv(rows) {
            const header = ['Item', 'Material', 'Plan Qty', 'Prd Qty', 'BOM Quantity'];
            const lines = [header.join(',')];
            (rows || []).forEach((c) => {
                lines.push([
                    c.Item ?? '',
                    c.Material ?? '',
                    c.PlanQty ?? '',
                    c.PrdQty ?? '',
                    c.BOMQuantity ?? '',
                ].map(csvEscape).join(','));
            });
            return lines.join('\r\n');
        }

        const exportWrap = panelBom.querySelector('#bomExport');
        const exportBtn = panelBom.querySelector('#bomExportBtn');
        const exportMenu = panelBom.querySelector('#bomExportMenu');
        function closeBomExportMenu() {
            if (!exportMenu) return;
            exportMenu.hidden = true;
            exportBtn?.setAttribute('aria-expanded', 'false');
        }
        exportBtn?.addEventListener('click', (e) => {
            e.stopPropagation();
            if (!exportMenu) return;
            const open = exportMenu.hidden;
            exportMenu.hidden = !open;
            exportBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                window.setTimeout(() => {
                    document.addEventListener('click', closeBomExportMenu, { once: true });
                }, 0);
            }
        });
        exportMenu?.querySelectorAll('[data-bom-export]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const mode = btn.getAttribute('data-bom-export');
                const rows = mode === 'all' ? comps : activeFilteredComps;
                const so = String(record?.sales_order || 'bom').trim();
                downloadCsv(`bom-${so}-${mode}-${Date.now()}.csv`, bomRowsToCsv(rows));
                closeBomExportMenu();
            });
        });

        function renderPage(page) {
            const totalItems = activeFilteredComps.length;
            const totalPages = Math.ceil(totalItems / PAGE_SIZE) || 1;
            currentBomPage = Math.max(1, Math.min(page, totalPages));

            const startIdx = (currentBomPage - 1) * PAGE_SIZE;
            const endIdx = Math.min(startIdx + PAGE_SIZE, totalItems);
            const pageData = activeFilteredComps.slice(startIdx, endIdx);

            if (!pageData.length) {
                tbodyEl.innerHTML = `
                    <tr>
                        <td colspan="5" style="text-align:center;padding:2.5rem 1rem;color:#64748b;font-size:0.88rem">
                            ${comps.length ? 'No components match your search or column filter criteria.' : `No BOM components found for sales order line ${escapeHtml(String(record?.line_item || '—'))}.`}
                        </td>
                    </tr>
                `;
            } else {
                tbodyEl.innerHTML = pageData.map(c => {
                    const matCode = String(c.Material || '').trim();
                    const matCell = matCode
                        ? `<button type="button" class="bom-material-link" data-material="${escapeHtml(matCode)}" title="View SAP material master for ${escapeHtml(matCode)}">${escapeHtml(matCode)}</button>`
                        : '<span style="color:#94a3b8">—</span>';
                    return `
                    <tr class="bom-data-row">
                        <td style="font-family:monospace;font-weight:600;color:#64748b">${escapeHtml(c.Item || '—')}</td>
                        <td>${matCell}</td>
                        <td style="text-align:right;font-weight:600">${fmtRoundNum(c.PlanQty)}</td>
                        <td style="text-align:right;color:#0d9488;font-weight:600">${fmtRoundNum(c.PrdQty)}</td>
                        <td style="text-align:right;color:#6366f1;font-weight:600">${fmtRoundNum(c.BOMQuantity)}</td>
                    </tr>
                `;
                }).join('');

                tbodyEl.querySelectorAll('.bom-material-link').forEach((btn) => {
                    btn.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        openMaterialDetailsFor(btn.getAttribute('data-material'), 'bom');
                    });
                });
            }

            // Update info counter
            if (infoEl) {
                if (totalItems === 0) {
                    infoEl.innerHTML = 'Showing <strong>0</strong> components';
                } else {
                    infoEl.innerHTML = `Showing <strong>${startIdx + 1}</strong> to <strong>${endIdx}</strong> of <strong>${totalItems}</strong> components (Page <strong>${currentBomPage}</strong> of <strong>${totalPages}</strong>)`;
                }
            }

            // Build pagination buttons
            if (btnsEl) {
                if (totalPages <= 1) {
                    btnsEl.innerHTML = '';
                    return;
                }

                let btnsHtml = '';

                // Prev button
                btnsHtml += `
                    <button type="button" class="bom-page-btn btn-prev" ${currentBomPage === 1 ? 'disabled' : ''} title="Previous Page">
                        <span class="material-icons-round" style="font-size:0.95rem">chevron_left</span>
                    </button>
                `;

                // Calculate pages to show with windowing
                const maxButtons = 5;
                let startPage = Math.max(1, currentBomPage - Math.floor(maxButtons / 2));
                let endPage = Math.min(totalPages, startPage + maxButtons - 1);
                if (endPage - startPage + 1 < maxButtons) {
                    startPage = Math.max(1, endPage - maxButtons + 1);
                }

                if (startPage > 1) {
                    btnsHtml += `<button type="button" class="bom-page-btn" data-page="1">1</button>`;
                    if (startPage > 2) {
                        btnsHtml += `<span class="bom-page-ellipsis">…</span>`;
                    }
                }

                for (let i = startPage; i <= endPage; i++) {
                    btnsHtml += `
                        <button type="button" class="bom-page-btn ${i === currentBomPage ? 'active' : ''}" data-page="${i}">
                            ${i}
                        </button>
                    `;
                }

                if (endPage < totalPages) {
                    if (endPage < totalPages - 1) {
                        btnsHtml += `<span class="bom-page-ellipsis">…</span>`;
                    }
                    btnsHtml += `<button type="button" class="bom-page-btn" data-page="${totalPages}">${totalPages}</button>`;
                }

                // Next button
                btnsHtml += `
                    <button type="button" class="bom-page-btn btn-next" ${currentBomPage === totalPages ? 'disabled' : ''} title="Next Page">
                        <span class="material-icons-round" style="font-size:0.95rem">chevron_right</span>
                    </button>
                `;

                btnsEl.innerHTML = btnsHtml;

                // Event listeners for page buttons
                btnsEl.querySelector('.btn-prev')?.addEventListener('click', () => renderPage(currentBomPage - 1));
                btnsEl.querySelector('.btn-next')?.addEventListener('click', () => renderPage(currentBomPage + 1));
                btnsEl.querySelectorAll('[data-page]').forEach(b => {
                    b.addEventListener('click', () => {
                        const p = parseInt(b.dataset.page, 10);
                        if (p && p !== currentBomPage) renderPage(p);
                    });
                });
            }
        }

        function applyBomFiltersAndSort() {
            // 1. Filter
            let filtered = comps.filter(c => {
                if (activeBomGlobalSearch) {
                    const mat = String(c.Material || '').toLowerCase();
                    const item = String(c.Item || '').toLowerCase();
                    if (!mat.includes(activeBomGlobalSearch) && !item.includes(activeBomGlobalSearch)) {
                        return false;
                    }
                }
                if (activeBomColFilters.Item) {
                    const it = String(c.Item || '').toLowerCase();
                    if (!it.includes(activeBomColFilters.Item.toLowerCase())) return false;
                }
                if (activeBomColFilters.Material) {
                    const m = String(c.Material || '').toLowerCase();
                    if (!m.includes(activeBomColFilters.Material.toLowerCase())) return false;
                }
                if (activeBomColFilters.PlanQty) {
                    const minVal = parseFloat(activeBomColFilters.PlanQty);
                    if (!isNaN(minVal) && (parseFloat(c.PlanQty) || 0) < minVal) return false;
                }
                if (activeBomColFilters.PrdQty) {
                    const minVal = parseFloat(activeBomColFilters.PrdQty);
                    if (!isNaN(minVal) && (parseFloat(c.PrdQty) || 0) < minVal) return false;
                }
                if (activeBomColFilters.BOMQuantity) {
                    const minVal = parseFloat(activeBomColFilters.BOMQuantity);
                    if (!isNaN(minVal) && (parseFloat(c.BOMQuantity) || 0) < minVal) return false;
                }
                return true;
            });

            // 2. Sort
            if (currentBomSortCol) {
                filtered.sort((a, b) => {
                    if (currentBomSortCol === 'PlanQty' || currentBomSortCol === 'PrdQty' || currentBomSortCol === 'BOMQuantity') {
                        const vA = parseFloat(a[currentBomSortCol]) || 0;
                        const vB = parseFloat(b[currentBomSortCol]) || 0;
                        return currentBomSortDir === 'asc' ? vA - vB : vB - vA;
                    }
                    const vA = String(a[currentBomSortCol] || '').toLowerCase();
                    const vB = String(b[currentBomSortCol] || '').toLowerCase();
                    const cmp = vA.localeCompare(vB, undefined, { numeric: true });
                    return currentBomSortDir === 'asc' ? cmp : -cmp;
                });
            }

            activeFilteredComps = filtered;
            renderPage(1);
            updateBomStatCards(activeFilteredComps);

            // Update total count badge
            if (totalBadgeEl) {
                totalBadgeEl.textContent = (activeFilteredComps.length !== comps.length)
                    ? `Total: ${activeFilteredComps.length} of ${comps.length} components`
                    : `Total: ${comps.length} components`;
            }
        }

        function updateBomSortHeaders() {
            panelBom.querySelectorAll('.bom-items-table thead th.col-sortable').forEach(th => {
                const col = th.dataset.sort;
                const icon = th.querySelector('.sort-icon');
                th.classList.remove('is-sorted-asc', 'is-sorted-desc');
                if (col === currentBomSortCol) {
                    th.classList.add(currentBomSortDir === 'asc' ? 'is-sorted-asc' : 'is-sorted-desc');
                    if (icon) icon.textContent = (currentBomSortDir === 'asc' ? 'arrow_upward' : 'arrow_downward');
                } else {
                    if (icon) icon.textContent = 'unfold_more';
                }
            });
        }

        // Click listener for sortable headers
        panelBom.querySelectorAll('.bom-items-table thead th.col-sortable').forEach(th => {
            th.addEventListener('click', (e) => {
                if (e.target.closest('input, select, button')) return;
                const col = th.dataset.sort;
                if (!col) return;
                if (currentBomSortCol === col) {
                    currentBomSortDir = (currentBomSortDir === 'asc' ? 'desc' : 'asc');
                } else {
                    currentBomSortCol = col;
                    currentBomSortDir = 'asc';
                }
                updateBomSortHeaders();
                applyBomFiltersAndSort();
            });
        });

        // Column filter input events
        panelBom.querySelectorAll('.bom-col-filter-input').forEach(inp => {
            inp.addEventListener('input', () => {
                const col = inp.dataset.filterCol;
                if (col) {
                    activeBomColFilters[col] = inp.value.trim();
                    applyBomFiltersAndSort();
                }
            });
        });

        // Clear all column filters button
        const clearBtn = panelBom.querySelector('#btnClearBomFilters');
        if (clearBtn) {
            clearBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                Object.keys(activeBomColFilters).forEach(k => activeBomColFilters[k] = '');
                panelBom.querySelectorAll('.bom-col-filter-input').forEach(i => { i.value = ''; });
                applyBomFiltersAndSort();
            });
        }

        // Global search input
        const searchInput = panelBom.querySelector('.bom-search-input');
        if (searchInput) {
            searchInput.addEventListener('input', () => {
                activeBomGlobalSearch = searchInput.value.toLowerCase().trim();
                applyBomFiltersAndSort();
            });
        }

        // Initial render
        renderPage(1);
    }

    async function loadDrawerBom(record, forceReload = false) {
        if (!record) return;
        const so = String(record.sales_order || '').trim();
        if (isBlankCode(so)) {
            renderApiMissing(panelBom, 'Sales order required', 'This quotation has no follow-on sales order, so BOM cannot be loaded.');
            if (dncBomVal) dncBomVal.textContent = 'No SO';
            return;
        }
        if (!forceReload && bomCacheBySo[so]) {
            const lineRows = filterBomByLine(bomCacheBySo[so].data, record.line_item);
            renderBomPanel(record, false, null, lineRows, bomCacheBySo[so].raw);
            if (dncBomVal) dncBomVal.textContent = `${lineRows.length} components`;
            return;
        }

        renderBomPanel(record, true);
        if (dncBomVal) dncBomVal.textContent = 'Syncing…';

        const endpoint = cfg.bomUrl || '/sales/bom';
        try {
            const res = await fetch(`${endpoint}?sales_order=${encodeURIComponent(so)}&_=${Date.now()}`);
            if (!res.ok) throw new Error(`HTTP ${res.status}: Failed to load BOM data`);
            const payload = await res.json();
            if (payload.status === 'error' || !payload.data) {
                const err = payload.error || 'No BOM components returned from SAP for SO ' + so;
                bomCacheBySo[so] = { data: [], raw: payload };
                renderBomPanel(record, false, err, [], payload);
                if (dncBomVal) dncBomVal.textContent = '0 components';
            } else {
                bomCacheBySo[so] = { data: payload.data, raw: payload };
                const lineRows = filterBomByLine(payload.data, record.line_item);
                renderBomPanel(record, false, null, lineRows, payload);
                if (dncBomVal) dncBomVal.textContent = `${lineRows.length} components`;
            }
        } catch (err) {
            renderBomPanel(record, false, err.message, [], null);
            if (dncBomVal) dncBomVal.textContent = 'SAP Error';
        }
    }

    /* ==========================================================================
       Material Panel & SAP Live Data Loader (ZI_MATERIALAPI_HUB_CDS)
    ========================================================================== */

    function renderMaterialPanel(record, isLoading = false, error = null, matData = null, rawPayload = null, mode = 'material') {
        if (!panelMaterial) return;
        const mat = String(record?.material || '').trim();
        const isDateRange = (mode === 'date_range');

        if (isLoading) {
            panelMaterial.innerHTML = `
                <div class="drawer-section-card" style="text-align:center;padding:3.5rem 1rem;background:#ffffff;border:1px solid #e2e8f0;border-radius:0.75rem">
                    <div class="sales-loader-ring" style="margin:0 auto 1.25rem;width:42px;height:42px;border-width:3.5px;border-top-color:#d97706"></div>
                    <strong style="font-size:1.05rem;color:#0f172a;display:block;margin-bottom:0.35rem">Connecting to SAP Material Master CDS API</strong>
                    <span style="font-size:0.82rem;color:#64748b">Fetching live SAP Material Master record for ${escapeHtml(mat)}…</span>
                </div>
            `;
            const modeBtns = panelMaterial.querySelectorAll('.sap-mode-btn');
            modeBtns.forEach(btn => {
                btn.addEventListener('click', () => loadDrawerMaterial(record, false, btn.dataset.mode));
            });
            return;
        }

        if (error) {
            panelMaterial.innerHTML = `
                <div class="sap-mat-modes" style="display:flex;align-items:center;gap:0.5rem;margin-bottom:1rem">
                    <button type="button" class="sap-mode-btn ${!isDateRange ? 'active' : ''}" data-mode="material" style="padding:0.42rem 0.95rem;border-radius:9999px;font-size:0.78rem;font-weight:700;cursor:pointer;border:1.5px solid #d97706;background:#d97706;color:#fff;display:inline-flex;align-items:center;gap:0.4rem">
                        <span class="material-icons-round" style="font-size:1.05rem">inventory_2</span> Order Material (${escapeHtml(mat)})
                    </button>
                    <button type="button" class="sap-mode-btn ${isDateRange ? 'active' : ''}" data-mode="date_range" style="padding:0.42rem 0.95rem;border-radius:9999px;font-size:0.78rem;font-weight:700;cursor:pointer;border:1.5px solid #cbd5e1;background:#fff;color:#475569;display:inline-flex;align-items:center;gap:0.4rem">
                        <span class="material-icons-round" style="font-size:1.05rem">date_range</span> All SAP Created (01–07 Sep 2026)
                    </button>
                </div>
                <div class="drawer-section-card" style="text-align:center;padding:2.5rem 1rem">
                    <span class="material-icons-round" style="font-size:2.6rem;color:#ef4444;margin-bottom:0.6rem;display:block">error_outline</span>
                    <div style="font-size:1rem;font-weight:700;color:#0f172a;margin-bottom:0.35rem">SAP Material API Notice</div>
                    <div style="color:#64748b;font-size:0.85rem;margin-bottom:1.25rem">${escapeHtml(error)}</div>
                    <button type="button" class="btn-drawer-retry-mat" style="padding:0.5rem 1.2rem;background:#d97706;color:#fff;border:none;border-radius:0.55rem;font-weight:700;font-size:0.85rem;cursor:pointer;display:inline-flex;align-items:center;gap:0.4rem">
                        <span class="material-icons-round" style="font-size:1rem">sync</span> Retry SAP Sync
                    </button>
                </div>
            `;
            const retryBtn = panelMaterial.querySelector('.btn-drawer-retry-mat');
            if (retryBtn) {
                retryBtn.addEventListener('click', () => loadDrawerMaterial(record, true, mode));
            }
            const modeBtns = panelMaterial.querySelectorAll('.sap-mode-btn');
            modeBtns.forEach(btn => {
                btn.addEventListener('click', () => loadDrawerMaterial(record, false, btn.dataset.mode));
            });
            return;
        }

        const items = matData || [];
        const rawJsonString = JSON.stringify(rawPayload || { data: items }, null, 2);
        const sapMatItem = (!isDateRange && items.length) ? items[0] : null;
        const sourceTab = record?._materialSourceTab || '';
        const backBtnHtml = sourceTab === 'bom'
            ? `<button type="button" class="btn-back-to-bom" style="padding:0.38rem 0.85rem;border-radius:0.5rem;border:1px solid #cbd5e1;background:#fff;cursor:pointer;font-size:0.78rem;font-weight:700;display:inline-flex;align-items:center;gap:0.3rem;color:#0f766e">
                    <span class="material-icons-round" style="font-size:1rem">arrow_back</span> Back to BOM
               </button>`
            : '';

        panelMaterial.innerHTML = `
            <!-- Mode Switchers & Actions -->
          
            <!-- Top Controls: Mode Switchers & View Toggles -->
            <div id="matDataView" class="sap-view-pane">
                ${!isDateRange ? `
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;margin-bottom:0.85rem;flex-wrap:wrap">
                        ${backBtnHtml}
                        <span style="font-size:0.78rem;color:#64748b">SAP ZI_MaterialAPI_HUB • Product eq '${escapeHtml(mat)}'</span>
                    </div>
                    ${!items.length ? `
                        <div class="drawer-section-card" style="text-align:center;padding:2rem 1rem;margin-bottom:1rem">
                            <div style="font-weight:700;color:#0f172a;margin-bottom:0.35rem">No master record for this material</div>
                            <div style="color:#64748b;font-size:0.85rem">${escapeHtml(rawPayload?.message || 'SAP returned no ZI_MaterialAPI_HUB rows for this Product.')}</div>
                        </div>
                    ` : ''}
                ` : ''}
                ${isDateRange ? `
                    <div class="drawer-section-card" style="padding:0;overflow:hidden;border:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,0.03)">
                        <!-- Table Header Point with Total Values Badge, Search & Sync -->
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1.1rem 1.25rem;border-bottom:1px solid #f1f5f9;flex-wrap:wrap">
                            <div style="display:flex;align-items:center;gap:0.65rem;flex-wrap:wrap">
                                <span class="material-icons-round" style="color:#d97706;font-size:1.25rem">list_alt</span>
                                <span style="font-size:0.95rem;font-weight:700;color:#0f172a">SAP Created Materials (01–07 Sep 2026)</span>
                                <span class="so-badge-pill" id="matTotalBadge" style="background:#fef3c7;color:#b45309;border-color:#fde68a;font-weight:700;font-size:0.8rem;padding:0.25rem 0.75rem">
                                    Total: ${items.length} records
                                </span>
                            </div>
                            <div style="display:flex;align-items:center;gap:0.6rem">
                                <input type="text" class="mat-search-input" placeholder="Search Product or Description…" style="padding:0.42rem 0.85rem;border:1px solid #cbd5e1;border-radius:0.55rem;font-size:0.82rem;outline:none;min-width:240px;box-shadow:inset 0 1px 2px rgba(0,0,0,0.03)"/>
                            </div>
                        </div>

                        <div class="drawer-components-table-wrap" style="max-height:none;overflow-x:auto">
                            <table class="drawer-components-table mat-items-table table-bordered">
                                <thead>
                                    <tr class="mat-header-row">
                                        <th class="col-sortable" data-sort="Product" title="Click to sort by Product" style="width:160px">
                                            <span>Product</span>
                                            <span class="sort-icon material-icons-round">unfold_more</span>
                                        </th>
                                        <th class="col-sortable" data-sort="ProductName" title="Click to sort by Product Name / Style">
                                            <span>Product Name / Style</span>
                                            <span class="sort-icon material-icons-round">unfold_more</span>
                                        </th>
                                        <th class="col-sortable" data-sort="ProductType" title="Click to sort by Type" style="width:90px">
                                            <span>Type</span>
                                            <span class="sort-icon material-icons-round">unfold_more</span>
                                        </th>
                                        <th class="col-sortable" data-sort="Plant" title="Click to sort by Plant" style="width:105px">
                                            <span>Plant</span>
                                            <span class="sort-icon material-icons-round">unfold_more</span>
                                        </th>
                                        <th class="col-sortable" data-sort="StorageLocation" title="Click to sort by Storage Loc" style="width:105px">
                                            <span>Storage Loc</span>
                                            <span class="sort-icon material-icons-round">unfold_more</span>
                                        </th>
                                        <th class="col-sortable" data-sort="BaseUnit" title="Click to sort by Unit" style="width:75px">
                                            <span>Unit</span>
                                            <span class="sort-icon material-icons-round">unfold_more</span>
                                        </th>
                                        <th class="col-sortable" data-sort="GrossWeight" title="Click to sort by Gross Weight" style="text-align:right;width:95px">
                                            <span>Gross Wt</span>
                                            <span class="sort-icon material-icons-round">unfold_more</span>
                                        </th>
                                        <th class="col-sortable" data-sort="CreationDate" title="Click to sort by Creation Date" style="width:140px">
                                            <span>Creation Date</span>
                                            <span class="sort-icon material-icons-round">unfold_more</span>
                                        </th>
                                    </tr>
                                    <tr class="mat-col-filter-row">
                                        <th><input type="text" class="mat-col-filter-input" data-filter-col="Product" placeholder="Filter product..."/></th>
                                        <th><input type="text" class="mat-col-filter-input" data-filter-col="ProductName" placeholder="Filter style / name..."/></th>
                                        <th>
                                            <select class="mat-col-filter-select" data-filter-col="ProductType" id="matColFilterType">
                                                <option value="">All Types</option>
                                            </select>
                                        </th>
                                        <th>
                                            <select class="mat-col-filter-select" data-filter-col="Plant" id="matColFilterPlant">
                                                <option value="">All Plants</option>
                                            </select>
                                        </th>
                                        <th><input type="text" class="mat-col-filter-input" data-filter-col="StorageLocation" placeholder="Loc..."/></th>
                                        <th>
                                            <select class="mat-col-filter-select" data-filter-col="BaseUnit" id="matColFilterUnit">
                                                <option value="">All Units</option>
                                            </select>
                                        </th>
                                        <th><input type="text" class="mat-col-filter-input" data-filter-col="GrossWeight" placeholder="Min wt..." style="text-align:right"/></th>
                                        <th>
                                            <div style="display:flex;align-items:center;gap:0.25rem">
                                                <input type="text" class="mat-col-filter-input" data-filter-col="CreationDate" placeholder="Filter date..."/>
                                                <button type="button" class="btn-clear-mat-filters" id="btnClearMatFilters" title="Clear all column filters">
                                                    <span class="material-icons-round" style="font-size:13px">clear</span>
                                                </button>
                                            </div>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody id="matTableBody">
                                    <!-- Populated dynamically by page -->
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination Bar -->
                        <div class="bom-pagination-wrap" id="matPaginationWrap">
                            <div class="bom-pagination-info" id="matPaginationInfo">
                                Showing 0 to 0 of 0 records
                            </div>
                            <div class="bom-pagination-btns" id="matPaginationBtns">
                                <!-- Page buttons -->
                            </div>
                        </div>
                    </div>
                ` : `
                    <div class="drawer-section-card">
                        <div class="drawer-sec-header">
                            <div class="drawer-sec-title-wrap">
                                <span class="material-icons-round drawer-sec-icon" style="color:#d97706">inventory_2</span>
                                <h4 class="drawer-sec-title">Material Master Specifications</h4>
                            </div>
                            <div style="display:flex;align-items:center;gap:0.5rem">
                                <span class="so-badge-pill" style="background:#fffbeb;color:#b45309;border-color:#fde68a;font-family:monospace;font-weight:700">${escapeHtml(mat)}</span>
                                <span class="so-badge-pill" style="background:#ecfdf5;color:#047857;border-color:#a7f3d0;font-weight:700"><span class="pulse-dot"></span> Live SAP</span>
                            </div>
                        </div>
                        <div class="drawer-info-grid">
                            <div class="drawer-info-item full-width">
                                <span class="info-lbl">Material Code / Product Number</span>
                                <span class="info-val" style="font-size:1.08rem;font-weight:700;color:#0f172a;font-family:monospace">${escapeHtml(sapMatItem?.Product || mat)}</span>
                            </div>
                            <div class="drawer-info-item full-width">
                                <span class="info-lbl">Description / Style Name</span>
                                <span class="info-val" style="font-weight:600;color:#0f172a">${escapeHtml(sapMatItem?.ProductName || record?.style || record?.material || '—')}</span>
                            </div>
                            <div class="drawer-info-item">
                                <span class="info-lbl">Product Type</span>
                                <span class="info-val">${escapeHtml(sapMatItem?.ProductType || record?.item_type || '—')}</span>
                            </div>
                            <div class="drawer-info-item">
                                <span class="info-lbl">Product Group</span>
                                <span class="info-val">${escapeHtml(sapMatItem?.ProductGroup || record?.material_group || '—')}</span>
                            </div>
                            <div class="drawer-info-item">
                                <span class="info-lbl">Brand</span>
                                <span class="info-val">${escapeHtml(sapMatItem?.Brand || '—')}</span>
                            </div>
                            <div class="drawer-info-item">
                                <span class="info-lbl">Product Category</span>
                                <span class="info-val">${escapeHtml(sapMatItem?.ProductCategory || '—')}</span>
                            </div>
                            <div class="drawer-info-item">
                                <span class="info-lbl">Base Unit of Measure</span>
                                <span class="info-val highlight-amber">${escapeHtml(sapMatItem?.BaseUnit || record?.base_unit || record?.unit || 'EA')}</span>
                            </div>
                            <div class="drawer-info-item">
                                <span class="info-lbl">Gross Weight</span>
                                <span class="info-val">${sapMatItem?.GrossWeight ? fmtRawNum(sapMatItem.GrossWeight) + ' ' + escapeHtml(sapMatItem.WeightUnit || 'G') : '—'}</span>
                            </div>
                            <div class="drawer-info-item">
                                <span class="info-lbl">Creation Date</span>
                                <span class="info-val">${escapeHtml(fmtSapDate(sapMatItem?.CreationDate) || sapMatItem?.CreationDate || '—')}</span>
                            </div>
                            <div class="drawer-info-item">
                                <span class="info-lbl">Last Change Date</span>
                                <span class="info-val">${escapeHtml(fmtSapDate(sapMatItem?.LastChangeDate) || sapMatItem?.LastChangeDate || '—')}</span>
                            </div>
                        </div>
                    </div>

                    <div class="drawer-section-card">
                        <div class="drawer-sec-header">
                            <div class="drawer-sec-title-wrap">
                                <span class="material-icons-round drawer-sec-icon" style="color:#d97706">domain</span>
                                <h4 class="drawer-sec-title">Plant &amp; Storage Allocations (${items.length || 1} Plant Records)</h4>
                            </div>
                            ${getPlantBadgeHtml(sapMatItem?.Plant || record?.plant)}
                        </div>
                        ${items.length > 1 ? `
                            <div class="drawer-components-table-wrap" style="overflow-x:auto;margin-top:0.75rem">
                                <table class="drawer-components-table table-bordered">
                                    <thead>
                                        <tr>
                                            <th style="width:120px">Plant</th>
                                            <th style="width:140px">Storage Loc</th>
                                            <th style="width:100px">Type</th>
                                            <th style="width:90px">Base Unit</th>
                                            <th style="width:110px;text-align:right">Gross Wt</th>
                                            <th>Batch Mgmt</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${items.map(it => `
                                            <tr>
                                                <td>${getPlantBadgeHtml(it.Plant || record?.plant)}</td>
                                                <td><span style="font-family:monospace;font-weight:700;color:#0f172a">${escapeHtml(it.StorageLocation || '—')}</span></td>
                                                <td><span class="proc-badge-tag">${escapeHtml(it.ProductType || '—')}</span></td>
                                                <td>${escapeHtml(it.BaseUnit || 'EA')}</td>
                                                <td style="text-align:right">${it.GrossWeight ? fmtRawNum(it.GrossWeight) + ' ' + escapeHtml(it.WeightUnit || 'G') : '—'}</td>
                                                <td>${it.IsBatchManagementRequired === '1' ? '<span style="color:#059669;font-weight:700">Required</span>' : '<span style="color:#94a3b8">No</span>'}</td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        ` : `
                            <div class="drawer-info-grid">
                                <div class="drawer-info-item">
                                    <span class="info-lbl">Plant</span>
                                    <span class="info-val">${escapeHtml(sapMatItem?.Plant || record?.plant || '—')}</span>
                                </div>
                                <div class="drawer-info-item">
                                    <span class="info-lbl">Storage Location</span>
                                    <span class="info-val">${escapeHtml(sapMatItem?.StorageLocation || record?.storage_location || '—')}</span>
                                </div>
                                <div class="drawer-info-item">
                                    <span class="info-lbl">Batch</span>
                                    <span class="info-val">${escapeHtml(record?.batch || '—')}</span>
                                </div>
                                <div class="drawer-info-item">
                                    <span class="info-lbl">Division</span>
                                    <span class="info-val">${escapeHtml(record?.division || '—')}${record?.division_code ? ' (' + escapeHtml(record.division_code) + ')' : ''}</span>
                                </div>
                                <div class="drawer-info-item">
                                    <span class="info-lbl">Base Unit of Measure</span>
                                    <span class="info-val highlight-amber">${escapeHtml(sapMatItem?.BaseUnit || record?.base_unit || record?.unit || 'EA')}</span>
                                </div>
                                <div class="drawer-info-item">
                                    <span class="info-lbl">Gross Weight</span>
                                    <span class="info-val">${sapMatItem?.GrossWeight ? fmtRawNum(sapMatItem.GrossWeight) + ' ' + escapeHtml(sapMatItem.WeightUnit || 'KG') : '—'}</span>
                                </div>
                            </div>
                        `}
                    </div>
                `}
            </div>

            <div id="matRawView" class="sap-view-pane" style="display:none">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.6rem">
                    <span style="font-size:0.8rem;color:#64748b;font-weight:600">Payload size: ${items.length} entities</span>
                    <button type="button" class="btn-copy-mat-json" style="padding:0.4rem 0.85rem;border-radius:0.5rem;border:1px solid #cbd5e1;background:#fff;cursor:pointer;font-size:0.78rem;font-weight:600;display:inline-flex;align-items:center;gap:0.35rem">
                        <span class="material-icons-round" style="font-size:0.95rem">content_copy</span> Copy Raw JSON
                    </button>
                </div>
                <pre class="sap-raw-json-view">${escapeHtml(rawJsonString)}</pre>
            </div>
        `;

        // Sync & Copy JSON button listeners
        const syncBtn = panelMaterial.querySelector('.btn-sync-mat-data');
        if (syncBtn) {
            syncBtn.addEventListener('click', () => loadDrawerMaterial(record, true, mode));
        }

        const backBomBtn = panelMaterial.querySelector('.btn-back-to-bom');
        if (backBomBtn) {
            backBomBtn.addEventListener('click', () => openSubDrawer('bom'));
        }

        const copyJsonBtn = panelMaterial.querySelector('.btn-copy-mat-json');
        if (copyJsonBtn) {
            copyJsonBtn.addEventListener('click', () => copyTextToClipboard(rawJsonString, copyJsonBtn));
        }

        const modeBtns = panelMaterial.querySelectorAll('.sap-mode-btn');
        modeBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                const targetMode = btn.getAttribute('data-mode');
                loadDrawerMaterial(record, false, targetMode);
            });
        });

        const viewBtns = panelMaterial.querySelectorAll('.sap-view-toggle-btn');
        viewBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                viewBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const targetId = btn.getAttribute('data-target');
                const dv = panelMaterial.querySelector('#matDataView');
                const rv = panelMaterial.querySelector('#matRawView');
                if (dv) dv.style.display = (targetId === 'matDataView') ? 'block' : 'none';
                if (rv) rv.style.display = (targetId === 'matRawView') ? 'block' : 'none';
            });
        });

        if (isDateRange) {
            // Helper to extract timestamp for date sorting
            function getSapTimestamp(d) {
                if (!d) return 0;
                const s = String(d).trim();
                const m = s.match(/\/Date\((-?\d+)(?:[+-]\d+)?\)\//);
                if (m) return parseInt(m[1], 10) || 0;
                if (s.length === 8 && /^\d{8}$/.test(s)) {
                    return new Date(s.substring(0, 4) + '-' + s.substring(4, 6) + '-' + s.substring(6, 8)).getTime() || 0;
                }
                const t = Date.parse(s);
                return isNaN(t) ? 0 : t;
            }

            // 20 items per page Pagination for Material List
            const PAGE_SIZE = 20;
            let currentMatPage = 1;
            let currentMatSortCol = null;
            let currentMatSortDir = 'asc';
            const activeMatColFilters = {
                Product: '',
                ProductName: '',
                ProductType: '',
                Plant: '',
                StorageLocation: '',
                BaseUnit: '',
                GrossWeight: '',
                CreationDate: ''
            };
            let activeGlobalSearch = '';
            let activeFilteredItems = items.slice();

            const tbodyEl = panelMaterial.querySelector('#matTableBody');
            const infoEl = panelMaterial.querySelector('#matPaginationInfo');
            const btnsEl = panelMaterial.querySelector('#matPaginationBtns');
            const totalBadgeEl = panelMaterial.querySelector('#matTotalBadge');

            // Populate Filter Dropdowns (ProductType, Plant, BaseUnit)
            function populateMatDropdown(id, prop, label) {
                const sel = panelMaterial.querySelector('#' + id);
                if (!sel) return;
                const uniqueVals = Array.from(new Set(items.map(m => String(m[prop] || '').trim()).filter(Boolean))).sort();
                sel.innerHTML = `<option value="">${label}</option>` + uniqueVals.map(v =>
                    `<option value="${escapeHtml(v)}">${escapeHtml(v)}</option>`
                ).join('');
            }
            populateMatDropdown('matColFilterType', 'ProductType', 'All Types');
            populateMatDropdown('matColFilterPlant', 'Plant', 'All Plants');
            populateMatDropdown('matColFilterUnit', 'BaseUnit', 'All Units');

            // Render material list page
            function renderMatPage(page) {
                const totalItems = activeFilteredItems.length;
                const totalPages = Math.ceil(totalItems / PAGE_SIZE) || 1;
                currentMatPage = Math.max(1, Math.min(page, totalPages));

                const startIdx = (currentMatPage - 1) * PAGE_SIZE;
                const endIdx = Math.min(startIdx + PAGE_SIZE, totalItems);
                const pageData = activeFilteredItems.slice(startIdx, endIdx);

                if (!pageData.length) {
                    tbodyEl.innerHTML = `
                        <tr>
                            <td colspan="8" style="text-align:center;padding:2.5rem 1rem;color:#64748b;font-size:0.88rem">
                                ${items.length ? 'No materials match your search or column filter criteria.' : 'No materials found matching CreationDate in SAP.'}
                            </td>
                        </tr>
                    `;
                } else {
                    tbodyEl.innerHTML = pageData.map(m => {
                        const prod = String(m.Product || '').trim();
                        const prodCell = prod
                            ? `<button type="button" class="bom-material-link" data-material="${escapeHtml(prod)}" title="View SAP material master for ${escapeHtml(prod)}">${escapeHtml(prod)}</button>`
                            : '<span style="color:#94a3b8">—</span>';
                        return `
                        <tr class="mat-data-row">
                            <td>${prodCell}</td>
                            <td>${escapeHtml(m.ProductName || m.Product_Text || '—')}</td>
                            <td><span class="proc-badge-tag">${escapeHtml(m.ProductType || '—')}</span></td>
                            <td>${getPlantBadgeHtml(m.Plant)}</td>
                            <td>${escapeHtml(m.StorageLocation || '—')}</td>
                            <td>${escapeHtml(m.BaseUnit || 'EA')}</td>
                            <td style="text-align:right">${fmtRawNum(m.GrossWeight)}</td>
                            <td style="white-space:nowrap;font-size:0.75rem;color:#64748b">${escapeHtml(fmtSapDate(m.CreationDate) || m.CreationDate || '—')}</td>
                        </tr>
                    `;
                    }).join('');

                    tbodyEl.querySelectorAll('.bom-material-link').forEach((btn) => {
                        btn.addEventListener('click', (e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            openMaterialDetailsFor(btn.getAttribute('data-material'), 'material');
                        });
                    });
                }

                // Update info counter
                if (infoEl) {
                    if (totalItems === 0) {
                        infoEl.innerHTML = 'Showing <strong>0</strong> records';
                    } else {
                        infoEl.innerHTML = `Showing <strong>${startIdx + 1}</strong> to <strong>${endIdx}</strong> of <strong>${totalItems}</strong> records (Page <strong>${currentMatPage}</strong> of <strong>${totalPages}</strong>)`;
                    }
                }

                // Build pagination buttons
                if (btnsEl) {
                    if (totalPages <= 1) {
                        btnsEl.innerHTML = '';
                        return;
                    }

                    let btnsHtml = '';

                    // Prev button
                    btnsHtml += `
                        <button type="button" class="bom-page-btn btn-prev" ${currentMatPage === 1 ? 'disabled' : ''} title="Previous Page">
                            <span class="material-icons-round" style="font-size:0.95rem">chevron_left</span>
                        </button>
                    `;

                    // Calculate pages to show with windowing
                    const maxButtons = 5;
                    let startPage = Math.max(1, currentMatPage - Math.floor(maxButtons / 2));
                    let endPage = Math.min(totalPages, startPage + maxButtons - 1);
                    if (endPage - startPage + 1 < maxButtons) {
                        startPage = Math.max(1, endPage - maxButtons + 1);
                    }

                    if (startPage > 1) {
                        btnsHtml += `<button type="button" class="bom-page-btn" data-page="1">1</button>`;
                        if (startPage > 2) {
                            btnsHtml += `<span class="bom-page-ellipsis">…</span>`;
                        }
                    }

                    for (let i = startPage; i <= endPage; i++) {
                        btnsHtml += `
                            <button type="button" class="bom-page-btn ${i === currentMatPage ? 'active' : ''}" data-page="${i}">
                                ${i}
                            </button>
                        `;
                    }

                    if (endPage < totalPages) {
                        if (endPage < totalPages - 1) {
                            btnsHtml += `<span class="bom-page-ellipsis">…</span>`;
                        }
                        btnsHtml += `<button type="button" class="bom-page-btn" data-page="${totalPages}">${totalPages}</button>`;
                    }

                    // Next button
                    btnsHtml += `
                        <button type="button" class="bom-page-btn btn-next" ${currentMatPage === totalPages ? 'disabled' : ''} title="Next Page">
                            <span class="material-icons-round" style="font-size:0.95rem">chevron_right</span>
                        </button>
                    `;

                    btnsEl.innerHTML = btnsHtml;

                    // Event listeners for page buttons
                    btnsEl.querySelector('.btn-prev')?.addEventListener('click', () => renderMatPage(currentMatPage - 1));
                    btnsEl.querySelector('.btn-next')?.addEventListener('click', () => renderMatPage(currentMatPage + 1));
                    btnsEl.querySelectorAll('[data-page]').forEach(b => {
                        b.addEventListener('click', () => {
                            const p = parseInt(b.dataset.page, 10);
                            if (p && p !== currentMatPage) renderMatPage(p);
                        });
                    });
                }
            }

            // Apply filter and sort
            function applyFiltersAndSort() {
                // 1. Filter
                let filtered = items.filter(m => {
                    // Global search
                    if (activeGlobalSearch) {
                        const prod = String(m.Product || '').toLowerCase();
                        const name = String(m.ProductName || m.Product_Text || '').toLowerCase();
                        const plant = String(m.Plant || '').toLowerCase();
                        if (!prod.includes(activeGlobalSearch) && !name.includes(activeGlobalSearch) && !plant.includes(activeGlobalSearch)) {
                            return false;
                        }
                    }
                    // Column filters
                    if (activeMatColFilters.Product) {
                        const p = String(m.Product || '').toLowerCase();
                        if (!p.includes(activeMatColFilters.Product.toLowerCase())) return false;
                    }
                    if (activeMatColFilters.ProductName) {
                        const name = String(m.ProductName || m.Product_Text || '').toLowerCase();
                        if (!name.includes(activeMatColFilters.ProductName.toLowerCase())) return false;
                    }
                    if (activeMatColFilters.ProductType) {
                        const t = String(m.ProductType || '').toLowerCase();
                        if (t !== activeMatColFilters.ProductType.toLowerCase()) return false;
                    }
                    if (activeMatColFilters.Plant) {
                        const pl = String(m.Plant || '').toUpperCase();
                        if (pl !== activeMatColFilters.Plant.toUpperCase()) return false;
                    }
                    if (activeMatColFilters.StorageLocation) {
                        const loc = String(m.StorageLocation || '').toLowerCase();
                        if (!loc.includes(activeMatColFilters.StorageLocation.toLowerCase())) return false;
                    }
                    if (activeMatColFilters.BaseUnit) {
                        const u = String(m.BaseUnit || '').toUpperCase();
                        if (u !== activeMatColFilters.BaseUnit.toUpperCase()) return false;
                    }
                    if (activeMatColFilters.GrossWeight) {
                        const minWt = parseFloat(activeMatColFilters.GrossWeight);
                        if (!isNaN(minWt) && (parseFloat(m.GrossWeight) || 0) < minWt) return false;
                    }
                    if (activeMatColFilters.CreationDate) {
                        const dtRaw = String(m.CreationDate || '').toLowerCase();
                        const dtFmt = fmtSapDate(m.CreationDate).toLowerCase();
                        const q = activeMatColFilters.CreationDate.toLowerCase();
                        if (!dtRaw.includes(q) && !dtFmt.includes(q)) return false;
                    }
                    return true;
                });

                // 2. Sort
                if (currentMatSortCol) {
                    filtered.sort((a, b) => {
                        if (currentMatSortCol === 'GrossWeight') {
                            const vA = parseFloat(a.GrossWeight) || 0;
                            const vB = parseFloat(b.GrossWeight) || 0;
                            return currentMatSortDir === 'asc' ? vA - vB : vB - vA;
                        }
                        if (currentMatSortCol === 'CreationDate') {
                            const vA = getSapTimestamp(a.CreationDate);
                            const vB = getSapTimestamp(b.CreationDate);
                            return currentMatSortDir === 'asc' ? vA - vB : vB - vA;
                        }
                        let vA, vB;
                        if (currentMatSortCol === 'ProductName') {
                            vA = String(a.ProductName || a.Product_Text || '').toLowerCase();
                            vB = String(b.ProductName || b.Product_Text || '').toLowerCase();
                        } else {
                            vA = String(a[currentMatSortCol] || '').toLowerCase();
                            vB = String(b[currentMatSortCol] || '').toLowerCase();
                        }
                        const cmp = vA.localeCompare(vB, undefined, { numeric: true });
                        return currentMatSortDir === 'asc' ? cmp : -cmp;
                    });
                }

                activeFilteredItems = filtered;
                renderMatPage(1);

                // Update total count badge
                if (totalBadgeEl) {
                    totalBadgeEl.textContent = (activeFilteredItems.length !== items.length)
                        ? `Total: ${activeFilteredItems.length} of ${items.length} records`
                        : `Total: ${items.length} records`;
                }
            }

            // Update table header sort icons
            function updateSortHeaders() {
                panelMaterial.querySelectorAll('.mat-items-table thead th.col-sortable').forEach(th => {
                    const col = th.dataset.sort;
                    const icon = th.querySelector('.sort-icon');
                    th.classList.remove('is-sorted-asc', 'is-sorted-desc');
                    if (col === currentMatSortCol) {
                        th.classList.add(currentMatSortDir === 'asc' ? 'is-sorted-asc' : 'is-sorted-desc');
                        if (icon) icon.textContent = (currentMatSortDir === 'asc' ? 'arrow_upward' : 'arrow_downward');
                    } else {
                        if (icon) icon.textContent = 'unfold_more';
                    }
                });
            }

            // Click listener for sortable headers
            panelMaterial.querySelectorAll('.mat-items-table thead th.col-sortable').forEach(th => {
                th.addEventListener('click', (e) => {
                    if (e.target.closest('input, select, button')) return;
                    const col = th.dataset.sort;
                    if (!col) return;
                    if (currentMatSortCol === col) {
                        currentMatSortDir = (currentMatSortDir === 'asc' ? 'desc' : 'asc');
                    } else {
                        currentMatSortCol = col;
                        currentMatSortDir = 'asc';
                    }
                    updateSortHeaders();
                    applyFiltersAndSort();
                });
            });

            // Column filter input events
            panelMaterial.querySelectorAll('.mat-col-filter-input').forEach(inp => {
                inp.addEventListener('input', () => {
                    const col = inp.dataset.filterCol;
                    if (col) {
                        activeMatColFilters[col] = inp.value.trim();
                        applyFiltersAndSort();
                    }
                });
            });

            // Column filter select dropdown events
            panelMaterial.querySelectorAll('.mat-col-filter-select').forEach(sel => {
                sel.addEventListener('change', () => {
                    const col = sel.dataset.filterCol;
                    if (col) {
                        activeMatColFilters[col] = sel.value.trim();
                        applyFiltersAndSort();
                    }
                });
            });

            // Clear all column filters button
            const clearBtn = panelMaterial.querySelector('#btnClearMatFilters');
            if (clearBtn) {
                clearBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    Object.keys(activeMatColFilters).forEach(k => activeMatColFilters[k] = '');
                    panelMaterial.querySelectorAll('.mat-col-filter-input').forEach(i => { i.value = ''; });
                    panelMaterial.querySelectorAll('.mat-col-filter-select').forEach(s => { s.value = ''; });
                    applyFiltersAndSort();
                });
            }

            // Global search input
            const searchInput = panelMaterial.querySelector('.mat-search-input');
            if (searchInput) {
                searchInput.addEventListener('input', () => {
                    activeGlobalSearch = searchInput.value.toLowerCase().trim();
                    applyFiltersAndSort();
                });
            }

            // Initial render
            renderMatPage(1);
        }
    }

    async function loadDrawerMaterial(record, forceReload = false, mode = 'material') {
        if (!record) return;
        let working = record;
        if (mode !== 'date_range' && isBlankCode(working.material)) {
            working = resolveWorkingRecord(record);
        }
        const mat = String(working.material || '').trim();
        if (mode !== 'date_range' && isBlankCode(mat)) {
            renderApiMissing(panelMaterial, 'Material required', 'Select a quotation item with a material code to load Material Master.');
            if (dncMaterialVal) dncMaterialVal.textContent = '—';
            return;
        }
        const cacheKey = (mode === 'date_range') ? '__date_range_sep2026__' : mat;

        if (!forceReload && matCacheByMat[cacheKey]) {
            renderMaterialPanel(working, false, null, matCacheByMat[cacheKey].data, matCacheByMat[cacheKey].raw, mode);
            if (dncMaterialVal && mode === 'date_range') {
                dncMaterialVal.textContent = `${matCacheByMat[cacheKey].data.length} materials`;
            }
            return;
        }

        renderMaterialPanel(working, true, null, null, null, mode);

        const endpoint = cfg.materialApiUrl || '/sales/material-api';
        const query = (mode === 'date_range')
            ? 'date_range=1'
            : `material=${encodeURIComponent(mat)}`;

        try {
            const res = await fetch(`${endpoint}?${query}&_=${Date.now()}`);
            if (!res.ok) throw new Error(`HTTP ${res.status}: Failed to load material data`);
            const payload = await res.json();
            if (payload.status === 'error' || !payload.data) {
                const err = payload.error || 'No material records returned from SAP';
                matCacheByMat[cacheKey] = { data: [], raw: payload };
                renderMaterialPanel(working, false, err, [], payload, mode);
            } else {
                matCacheByMat[cacheKey] = { data: payload.data, raw: payload };
                renderMaterialPanel(working, false, null, payload.data, payload, mode);
                if (dncMaterialVal && mode === 'date_range') {
                    dncMaterialVal.textContent = `${payload.data.length} materials`;
                }
            }
        } catch (err) {
            renderMaterialPanel(working, false, err.message, [], null, mode);
        }
    }

    /* ==========================================================================
       Procurement Panel & SAP Live Data Loader (ZBUSINESS_API_SRV)
    ========================================================================== */

    function renderProcurementPanel(record, isLoading = false, error = null, procData = null, rawPayload = null) {
        if (!panelProcurement) return;
        const so = String(record?.sales_order || '').trim();

        if (isLoading) {
            panelProcurement.innerHTML = `
                <div class="drawer-section-card" style="text-align:center;padding:3.5rem 1rem;background:#fff;border:1px solid #e2e8f0;border-radius:0.75rem">
                    <div class="sales-loader-ring" style="margin:0 auto 1.25rem;width:42px;height:42px;border-width:3.5px;border-top-color:#4f46e5"></div>
                    <strong style="font-size:1rem;color:#0f172a;display:block;margin-bottom:0.35rem">Connecting to SAP Procurement Dashboard</strong>
                    <span style="font-size:0.82rem;color:#64748b">Fetching live PR, PO, Stock & Demand records for Sales Doc #${escapeHtml(so)}…</span>
                </div>
            `;
            return;
        }

        if (error) {
            panelProcurement.innerHTML = `
                <div class="drawer-section-card" style="text-align:center;padding:2.5rem 1rem;background:#fff;border:1px solid #e2e8f0;border-radius:0.75rem">
                    <span class="material-icons-round" style="font-size:2.6rem;color:#ef4444;margin-bottom:0.6rem;display:block">error_outline</span>
                    <div style="font-size:1rem;font-weight:700;color:#0f172a;margin-bottom:0.35rem">SAP Procurement Notice</div>
                    <div style="color:#64748b;font-size:0.85rem;margin-bottom:1.25rem">${escapeHtml(error)}</div>
                    <button type="button" class="btn-drawer-retry-proc" style="padding:0.5rem 1.2rem;background:#4f46e5;color:#fff;border:none;border-radius:0.55rem;font-weight:700;font-size:0.85rem;cursor:pointer;display:inline-flex;align-items:center;gap:0.4rem">
                        <span class="material-icons-round" style="font-size:1rem">sync</span> Retry SAP Sync
                    </button>
                </div>
            `;
            const retryBtn = panelProcurement.querySelector('.btn-drawer-retry-proc');
            if (retryBtn) {
                retryBtn.addEventListener('click', () => loadDrawerProcurement(record, true));
            }
            return;
        }

        const comps = procData || [];
        const first = comps[0] || {};
        const parentMat = record?.material || first.Material || '—';
        const parentDesc = record?.style || record?.material_description || first.MaterialDescription || '—';

        let totalReq = 0, totalPR = 0, totalPO = 0, totalStock = 0;
        const groupCounts = {};
        comps.forEach(c => {
            totalReq += parseFloat(String(c.RequirementQty || c.RequiredQuantity || 0).replace(/\s+/g, '')) || 0;
            totalPR += parseFloat(String(c.TotalPRQty || c.TotalPRQuantity || 0).replace(/\s+/g, '')) || 0;
            totalPO += parseFloat(String(c.TotalPOQty || c.TotalPOQuantity || 0).replace(/\s+/g, '')) || 0;
            totalStock += parseFloat(String(c.StockQty || c.StockQuantity || 0).replace(/\s+/g, '')) || 0;
            const g = String(c.MaterialGroup || '').trim();
            if (g) groupCounts[g] = (groupCounts[g] || 0) + 1;
        });

        const prCoveragePct = totalReq > 0 ? Math.min(100, Math.round((totalPR / totalReq) * 100)) : (totalPR > 0 ? 100 : 0);
        const poFulfillPct = totalReq > 0 ? Math.min(100, Math.round((totalPO / totalReq) * 100)) : (totalPO > 0 ? 100 : 0);
        const uniqueGroups = Object.keys(groupCounts);

        panelProcurement.innerHTML = `
            <div class="proc-shell">
                <div class="proc-pipeline-strip">
                    <div class="proc-pipeline-flow">
                        <button type="button" class="proc-flow-node stage-req" data-stage="req">
                            <span class="proc-flow-ico"><span class="material-icons-round">inventory_2</span></span>
                            <span class="proc-flow-meta">
                                <span class="proc-flow-lbl">Demand</span>
                                <strong class="proc-flow-val">${fmtNum(totalReq)}</strong>
                            </span>
                        </button>
                        <button type="button" class="proc-flow-node stage-pr" data-stage="pr">
                            <span class="proc-flow-ico"><span class="material-icons-round">assignment</span></span>
                            <span class="proc-flow-meta">
                                <span class="proc-flow-lbl">Purchase req</span>
                                <strong class="proc-flow-val">${fmtNum(totalPR)}</strong>
                            </span>
                        </button>
                        <button type="button" class="proc-flow-node stage-po" data-stage="po">
                            <span class="proc-flow-ico"><span class="material-icons-round">local_shipping</span></span>
                            <span class="proc-flow-meta">
                                <span class="proc-flow-lbl">Purchase order</span>
                                <strong class="proc-flow-val">${fmtNum(totalPO)}</strong>
                            </span>
                        </button>
                        <button type="button" class="proc-flow-node stage-stk" data-stage="stock">
                            <span class="proc-flow-ico"><span class="material-icons-round">warehouse</span></span>
                            <span class="proc-flow-meta">
                                <span class="proc-flow-lbl">Stock</span>
                                <strong class="proc-flow-val">${fmtNum(totalStock)}</strong>
                            </span>
                        </button>
                    </div>
                </div>

                ${comps.length === 0 ? `
                    <!-- Modern Dedicated Empty State Card (No ugly empty table / scrollbar) -->
                    <div class="proc-empty-state-card">
                        <div class="proc-empty-icon-wrap">
                            <span class="material-icons-round" style="font-size:1.85rem">inventory_2</span>
                        </div>
                        <h3 class="proc-empty-title">No Procurement Demand Records Found</h3>
                        <p class="proc-empty-desc">
                            SAP ZBUSINESS_API_SRV returned 0 procurement components or purchase requisitions for Sales Doc #<strong style="color:#0f172a">${escapeHtml(so)}</strong>.
                        </p>
                        <div class="proc-empty-actions">
                            <button type="button" class="btn-proc-empty-sync" style="padding:0.48rem 1.15rem;border-radius:0.55rem;background:#4f46e5;color:#fff;border:none;font-weight:700;font-size:0.82rem;cursor:pointer;display:inline-flex;align-items:center;gap:0.4rem;box-shadow:0 2px 5px rgba(79,70,229,0.25)">
                                <span class="material-icons-round" style="font-size:1rem">sync</span> Sync from SAP
                            </button>
                            <button type="button" class="btn-proc-goto-bom" style="padding:0.48rem 1.15rem;border-radius:0.55rem;background:#ffffff;color:#334155;border:1px solid #cbd5e1;font-weight:700;font-size:0.82rem;cursor:pointer;display:inline-flex;align-items:center;gap:0.4rem">
                                <span class="material-icons-round" style="font-size:1rem;color:#d97706">schema</span> View BOM Components
                            </button>
                            <button type="button" class="btn-proc-goto-planning" style="padding:0.48rem 1.15rem;border-radius:0.55rem;background:#ffffff;color:#334155;border:1px solid #cbd5e1;font-weight:700;font-size:0.82rem;cursor:pointer;display:inline-flex;align-items:center;gap:0.4rem">
                                <span class="material-icons-round" style="font-size:1rem;color:#0284c7">calendar_today</span> View Planning Schedule
                            </button>
                        </div>
                    </div>
                ` : `
                    <!-- Clean Toolbar: Search & Dynamic Group Filter Pills -->
                    <div class="procurement-toolbar">
                        <div class="procurement-toolbar-top">
                            <div class="procurement-search-wrap">
                                <span class="material-icons-round">search</span>
                                <input type="search" class="proc-search-input" placeholder="Search material code, description, shade..." autocomplete="off">
                                <button type="button" class="proc-search-clear" style="display:none" aria-label="Clear search" title="Clear search">
                                    <span class="material-icons-round">close</span>
                                </button>
                            </div>
                            <div class="procurement-count-badge" id="procFilteredBadge">${comps.length} of ${comps.length} items</div>
                        </div>
                        <div class="procurement-group-filters">
                            <button type="button" class="proc-filter-pill active" data-group="all">All <span class="pill-cnt">${comps.length}</span></button>
                            ${uniqueGroups.map(grp => `
                                <button type="button" class="proc-filter-pill" data-group="${escapeHtml(grp.toLowerCase())}">
                                    ${escapeHtml(grp)} <span class="pill-cnt">${groupCounts[grp]}</span>
                                </button>
                            `).join('')}
                        </div>
                    </div>

                    <div class="procurement-content-body" id="procContentBody">
                        <div class="procurement-table-scroll">
                            <table class="procurement-table table-bordered">
                                <thead>
                                    <tr>
                                        <th class="col-sortable" data-sort="ComponentMaterial">
                                            <span class="th-sort-wrap">Component Material <span class="material-icons-round sort-icon">unfold_more</span></span>
                                        </th>
                                        <th class="col-sortable" data-sort="ComponentDescription">
                                            <span class="th-sort-wrap">Description <span class="material-icons-round sort-icon">unfold_more</span></span>
                                        </th>
                                        <th class="col-sortable" data-sort="MaterialGroup">
                                            <span class="th-sort-wrap">Group <span class="material-icons-round sort-icon">unfold_more</span></span>
                                        </th>
                                        <th class="col-num col-sortable" data-sort="RequirementQty">
                                            <span class="th-sort-wrap th-sort-right">Req Qty <span class="material-icons-round sort-icon">unfold_more</span></span>
                                        </th>
                                        <th class="col-num col-sortable" data-sort="TotalPRQty">
                                            <span class="th-sort-wrap th-sort-right">Total PR Qty <span class="material-icons-round sort-icon">unfold_more</span></span>
                                        </th>
                                        <th class="col-num col-sortable" data-sort="TotalPOQty">
                                            <span class="th-sort-wrap th-sort-right">Total PO Qty <span class="material-icons-round sort-icon">unfold_more</span></span>
                                        </th>
                                        <th class="col-num col-sortable" data-sort="BalancePRQty">
                                            <span class="th-sort-wrap th-sort-right">Balance PR <span class="material-icons-round sort-icon">unfold_more</span></span>
                                        </th>
                                    </tr>
                                    <tr class="proc-col-filter-row">
                                        <th><input type="text" class="proc-col-filter" data-col="ComponentMaterial" placeholder="Filter code…"></th>
                                        <th><input type="text" class="proc-col-filter" data-col="ComponentDescription" placeholder="Filter desc/shade…"></th>
                                        <th><input type="text" class="proc-col-filter" data-col="MaterialGroup" placeholder="Filter group…"></th>
                                        <th><input type="number" class="proc-col-filter" data-col="RequirementQty" placeholder="Min req…"></th>
                                        <th><input type="number" class="proc-col-filter" data-col="TotalPRQty" placeholder="Min PR…"></th>
                                        <th><input type="number" class="proc-col-filter" data-col="TotalPOQty" placeholder="Min PO…"></th>
                                        <th><input type="number" class="proc-col-filter" data-col="BalancePRQty" placeholder="Min bal…"></th>
                                    </tr>
                                </thead>
                                <tbody id="drawerProcTableBody"></tbody>
                            </table>
                        </div>
                        <div class="bom-pagination-wrap" style="display:flex;align-items:center;justify-content:space-between;padding:0.7rem 1.1rem;border-top:1px solid #e2e8f0;background:#ffffff;font-size:0.8rem;color:#64748b">
                            <div id="drawerProcPaginationInfo">Showing 1 to 20 of ${comps.length} components</div>
                            <div id="drawerProcPaginationBtns" style="display:flex;gap:0.35rem"></div>
                        </div>
                    </div>
                `}
                <div class="proc-inspect-backdrop" id="procInspectBackdrop" hidden></div>
                <aside class="proc-inspect" id="procInspect" aria-hidden="true">
                    <div class="proc-inspect-head">
                        <div>
                            <div class="proc-inspect-eyebrow" id="procInspectEyebrow">PR &amp; PO DETAILS</div>
                            <h3 id="procInspectTitle">—</h3>
                            <p id="procInspectSub"></p>
                        </div>
                        <button type="button" class="proc-inspect-close" id="procInspectClose" aria-label="Close">
                            <span class="material-icons-round">close</span>
                        </button>
                    </div>
                    <div class="proc-inspect-body" id="procInspectBody"></div>
                </aside>
            </div>
        `;

        // Interactive State
        const PAGE_SIZE = 20;
        let currentProcPage = 1;
        let currentProcSortCol = null;
        let currentProcSortDir = 'asc';
        let activeGroupFilter = 'all';
        let activeGlobalSearch = '';
        const activeColFilters = {
            ComponentMaterial: '',
            ComponentDescription: '',
            MaterialGroup: '',
            RequirementQty: '',
            TotalPRQty: '',
            TotalPOQty: '',
            BalancePRQty: '',
            StockQty: ''
        };
        let activeFilteredComps = comps.slice();

        function procQty(r) {
            const req = parseFloat(String(r.RequirementQty || r.RequiredQuantity || '0').replace(/\s+/g, '')) || 0;
            const pr = parseFloat(String(r.TotalPRQty || r.TotalPRQuantity || '0').replace(/\s+/g, '')) || 0;
            const po = parseFloat(String(r.TotalPOQty || r.TotalPOQuantity || '0').replace(/\s+/g, '')) || 0;
            const bal = parseFloat(String(r.BalancePRQty || (pr - po) || '0').replace(/\s+/g, '')) || 0;
            const stock = parseFloat(String(r.StockQty || r.StockQuantity || '0').replace(/\s+/g, '')) || 0;
            return { req, pr, po, bal, stock };
        }

        function applyFiltersAndSort() {
            let filtered = comps.filter(c => {
                // 1. Group filter pill
                if (activeGroupFilter !== 'all') {
                    const grp = String(c.MaterialGroup || '').toLowerCase();
                    if (!grp.includes(activeGroupFilter)) return false;
                }
                // 2. Global search
                if (activeGlobalSearch) {
                    const mat = String(c.ComponentMaterial || '').toLowerCase();
                    const desc = String(c.ComponentDescription || c.ComponentMaterialDesc || '').toLowerCase();
                    const grp = String(c.MaterialGroup || '').toLowerCase();
                    const shade = String(c.ShadeText1 || '').toLowerCase();
                    if (!mat.includes(activeGlobalSearch) && !desc.includes(activeGlobalSearch) && !grp.includes(activeGlobalSearch) && !shade.includes(activeGlobalSearch)) {
                        return false;
                    }
                }
                // 3. Column filters
                if (activeColFilters.ComponentMaterial) {
                    const m = String(c.ComponentMaterial || '').toLowerCase();
                    if (!m.includes(activeColFilters.ComponentMaterial.toLowerCase())) return false;
                }
                if (activeColFilters.ComponentDescription) {
                    const d = String(c.ComponentDescription || c.ComponentMaterialDesc || '').toLowerCase();
                    const s = String(c.ShadeText1 || '').toLowerCase();
                    const query = activeColFilters.ComponentDescription.toLowerCase();
                    if (!d.includes(query) && !s.includes(query)) return false;
                }
                if (activeColFilters.MaterialGroup) {
                    const g = String(c.MaterialGroup || '').toLowerCase();
                    if (!g.includes(activeColFilters.MaterialGroup.toLowerCase())) return false;
                }
                if (activeColFilters.RequirementQty) {
                    const minVal = parseFloat(activeColFilters.RequirementQty);
                    const val = parseFloat(String(c.RequirementQty || c.RequiredQuantity || 0).replace(/\s+/g, '')) || 0;
                    if (!isNaN(minVal) && val < minVal) return false;
                }
                if (activeColFilters.TotalPRQty) {
                    const minVal = parseFloat(activeColFilters.TotalPRQty);
                    const val = parseFloat(String(c.TotalPRQty || c.TotalPRQuantity || 0).replace(/\s+/g, '')) || 0;
                    if (!isNaN(minVal) && val < minVal) return false;
                }
                if (activeColFilters.TotalPOQty) {
                    const minVal = parseFloat(activeColFilters.TotalPOQty);
                    const val = parseFloat(String(c.TotalPOQty || c.TotalPOQuantity || 0).replace(/\s+/g, '')) || 0;
                    if (!isNaN(minVal) && val < minVal) return false;
                }
                if (activeColFilters.BalancePRQty) {
                    const minVal = parseFloat(activeColFilters.BalancePRQty);
                    const val = parseFloat(String(c.BalancePRQty || 0).replace(/\s+/g, '')) || 0;
                    if (!isNaN(minVal) && val < minVal) return false;
                }
                if (activeColFilters.StockQty) {
                    const minVal = parseFloat(activeColFilters.StockQty);
                    const val = parseFloat(String(c.StockQty || c.StockQuantity || 0).replace(/\s+/g, '')) || 0;
                    if (!isNaN(minVal) && val < minVal) return false;
                }
                return true;
            });

            if (currentProcSortCol) {
                filtered.sort((a, b) => {
                    const numCols = ['RequirementQty', 'TotalPRQty', 'TotalPOQty', 'BalancePRQty', 'StockQty'];
                    if (numCols.includes(currentProcSortCol)) {
                        const vA = parseFloat(String(a[currentProcSortCol] || (currentProcSortCol === 'RequirementQty' ? a.RequiredQuantity : (currentProcSortCol === 'TotalPRQty' ? a.TotalPRQuantity : (currentProcSortCol === 'TotalPOQty' ? a.TotalPOQuantity : a.StockQuantity))) || 0).replace(/\s+/g, '')) || 0;
                        const vB = parseFloat(String(b[currentProcSortCol] || (currentProcSortCol === 'RequirementQty' ? b.RequiredQuantity : (currentProcSortCol === 'TotalPRQty' ? b.TotalPRQuantity : (currentProcSortCol === 'TotalPOQty' ? b.TotalPOQuantity : b.StockQuantity))) || 0).replace(/\s+/g, '')) || 0;
                        return currentProcSortDir === 'asc' ? vA - vB : vB - vA;
                    }
                    const vA = String(a[currentProcSortCol] || a.ComponentMaterialDesc || '').toLowerCase();
                    const vB = String(b[currentProcSortCol] || b.ComponentMaterialDesc || '').toLowerCase();
                    const cmp = vA.localeCompare(vB, undefined, { numeric: true });
                    return currentProcSortDir === 'asc' ? cmp : -cmp;
                });
            }

            activeFilteredComps = filtered;
            renderPage(1);

            const countBadge = panelProcurement.querySelector('#procFilteredBadge');
            if (countBadge) {
                countBadge.textContent = `${activeFilteredComps.length} of ${comps.length} items`;
            }
        }

        function updateSortHeaders() {
            panelProcurement.querySelectorAll('.proc-pro-table thead th.col-sortable, .procurement-table thead th.col-sortable').forEach(th => {
                const col = th.dataset.sort;
                const icon = th.querySelector('.sort-icon');
                th.classList.remove('is-sorted-asc', 'is-sorted-desc');
                if (col === currentProcSortCol) {
                    th.classList.add(currentProcSortDir === 'asc' ? 'is-sorted-asc' : 'is-sorted-desc');
                    if (icon) icon.textContent = (currentProcSortDir === 'asc' ? 'arrow_upward' : 'arrow_downward');
                } else {
                    if (icon) icon.textContent = 'unfold_more';
                }
            });
        }

        function renderPage(page) {
            const totalItems = activeFilteredComps.length;
            const totalPages = Math.ceil(totalItems / PAGE_SIZE) || 1;
            currentProcPage = Math.max(1, Math.min(page, totalPages));

            const startIdx = (currentProcPage - 1) * PAGE_SIZE;
            const endIdx = Math.min(startIdx + PAGE_SIZE, totalItems);
            const pageData = activeFilteredComps.slice(startIdx, endIdx);

            const tbodyEl = panelProcurement.querySelector('#drawerProcTableBody');
            const infoEl = panelProcurement.querySelector('#drawerProcPaginationInfo');
            const btnsEl = panelProcurement.querySelector('#drawerProcPaginationBtns');

            if (!tbodyEl) return;

            if (!pageData.length) {
                tbodyEl.innerHTML = `
                    <tr class="proc-empty-row">
                        <td colspan="7">${comps.length ? 'No components match your search or filter criteria.' : 'No procurement components found in SAP for this sales doc.'}</td>
                    </tr>
                `;
            } else {
                tbodyEl.innerHTML = pageData.map((r, i) => {
                    const q = procQty(r);
                    const balColor = q.bal < 0 ? '#dc2626' : (q.bal > 0 ? '#d97706' : '#059669');
                    return `
                        <tr class="proc-row" data-page-idx="${i}">
                            <td>
                                <code class="proc-mat-code">${escapeHtml(r.ComponentMaterial || '—')}</code>
                            </td>
                            <td>
                                ${escapeHtml(r.ComponentDescription || r.ComponentMaterialDesc || '—')}
                                ${r.ShadeText1 ? `<div class="proc-item-shade">${escapeHtml(r.ShadeText1)}</div>` : ''}
                            </td>
                            <td><span class="proc-tag ${groupTagClass(r.MaterialGroup)}">${escapeHtml(r.MaterialGroup || '—')}</span></td>
                            <td class="col-num">${fmtNum(q.req)}</td>
                            <td class="col-num" style="font-weight:600;color:#0f172a">${fmtNum(q.pr)}</td>
                            <td class="col-num" style="font-weight:600;color:#059669">${fmtNum(q.po)}</td>
                            <td class="col-num" style="font-weight:700;color:${balColor}">${fmtNum(q.bal)}</td>
                        </tr>
                    `;
                }).join('');

                tbodyEl.querySelectorAll('.proc-row').forEach((row) => {
                    const idx = Number(row.getAttribute('data-page-idx'));
                    const item = pageData[idx];
                    row.addEventListener('click', () => {
                        tbodyEl.querySelectorAll('.proc-row').forEach((r) => r.classList.remove('active'));
                        row.classList.add('active');
                        openProcInspect(item);
                    });
                });
            }

            if (infoEl) {
                infoEl.textContent = totalItems
                    ? `Showing ${startIdx + 1} to ${endIdx} of ${totalItems} components`
                    : 'Showing 0 components';
            }

            if (btnsEl) {
                btnsEl.innerHTML = '';
                if (totalPages > 1) {
                    const prevBtn = document.createElement('button');
                    prevBtn.type = 'button';
                    prevBtn.className = 'bom-page-btn';
                    prevBtn.disabled = (currentProcPage === 1);
                    prevBtn.innerHTML = '<span class="material-icons-round" style="font-size:14px">chevron_left</span>';
                    prevBtn.title = 'Previous page';
                    prevBtn.addEventListener('click', () => renderPage(currentProcPage - 1));
                    btnsEl.appendChild(prevBtn);

                    const maxDisplayPages = 5;
                    let startP = Math.max(1, currentProcPage - 2);
                    let endP = Math.min(totalPages, startP + maxDisplayPages - 1);
                    if (endP - startP < maxDisplayPages - 1) {
                        startP = Math.max(1, endP - maxDisplayPages + 1);
                    }

                    for (let p = startP; p <= endP; p++) {
                        const pBtn = document.createElement('button');
                        pBtn.type = 'button';
                        pBtn.className = `bom-page-btn ${p === currentProcPage ? 'active' : ''}`;
                        pBtn.textContent = p;
                        pBtn.addEventListener('click', () => renderPage(p));
                        btnsEl.appendChild(pBtn);
                    }

                    const nextBtn = document.createElement('button');
                    nextBtn.type = 'button';
                    nextBtn.className = 'bom-page-btn';
                    nextBtn.disabled = (currentProcPage === totalPages);
                    nextBtn.innerHTML = '<span class="material-icons-round" style="font-size:14px">chevron_right</span>';
                    nextBtn.title = 'Next page';
                    nextBtn.addEventListener('click', () => renderPage(currentProcPage + 1));
                    btnsEl.appendChild(nextBtn);
                }
            }
        }

        const inspectEl = panelProcurement.querySelector('#procInspect');
        const inspectBackdrop = panelProcurement.querySelector('#procInspectBackdrop');
        const inspectTitle = panelProcurement.querySelector('#procInspectTitle');
        const inspectSub = panelProcurement.querySelector('#procInspectSub');
        const inspectBody = panelProcurement.querySelector('#procInspectBody');

        function closeDrawerProcPr() {
            inspectEl?.classList.remove('is-open');
            if (inspectEl) inspectEl.setAttribute('aria-hidden', 'true');
            if (inspectBackdrop) inspectBackdrop.hidden = true;
        }

        panelProcurement.querySelector('#procInspectClose')?.addEventListener('click', closeDrawerProcPr);
        inspectBackdrop?.addEventListener('click', closeDrawerProcPr);

        function prLinesHtml(prList) {
            return `
                <div class="proc-nested-head">
                    <span>${prList.length} Purchase Requisition Item(s)</span>
                    <span class="proc-pill proc-pill-live"><span class="pulse-dot"></span> Live SAP</span>
                </div>
                ${prList.map((pr) => {
                    const reqQty = parseFloat(String(pr.RequirementQty || '0').replace(/\s+/g, '')) || 0;
                    const prQty = parseFloat(String(pr.PRQty || '0').replace(/\s+/g, '')) || 0;
                    const poQty = parseFloat(String(pr.POQty || '0').replace(/\s+/g, '')) || 0;
                    const balQty = parseFloat(String(pr.BalancePRQty || '0').replace(/\s+/g, '')) || 0;
                    const balColor = balQty < 0 ? '#dc2626' : (balQty > 0 ? '#d97706' : '#10b981');
                    const cleanItem = pr.PRItem ? String(parseInt(pr.PRItem, 10)) : '10';
                    return `
                        <div class="pr-line-card">
                            <div class="pr-line-card-head">
                                <span class="pr-num">
                                    <span class="material-icons-round" style="font-size:16px;color:#4f46e5">receipt</span>
                                    PR ${escapeHtml(pr.PRNumber || '—')} • Item ${escapeHtml(cleanItem)}
                                </span>
                            </div>
                            <div class="pr-grid-details">
                                <div><span>Requirement Qty</span><strong style="color:#0f172a">${fmtNum(reqQty)}</strong></div>
                                <div><span>PR Quantity</span><strong style="color:#4f46e5">${fmtNum(prQty)}</strong></div>
                                <div><span>PO Quantity</span><strong style="color:#059669">${fmtNum(poQty)}</strong></div>
                                <div><span>Balance PR Qty</span><strong style="color:${balColor}">${fmtNum(balQty)}</strong></div>
                            </div>
                        </div>
                    `;
                }).join('')}
            `;
        }

        async function openProcInspect(item) {
            if (!item || !inspectEl) return;
            if (inspectTitle) inspectTitle.textContent = item.ComponentMaterial || '—';
            if (inspectSub) inspectSub.textContent = item.ComponentDescription || item.ComponentMaterialDesc || '';
            inspectBackdrop.hidden = false;
            inspectEl.classList.add('is-open');
            inspectEl.setAttribute('aria-hidden', 'false');
            inspectBody.innerHTML = `
                <div style="padding:1.25rem 0.5rem;text-align:center;color:#64748b">
                    <div class="sales-loader-ring" style="margin:0 auto 0.75rem;width:26px;height:26px;border-width:3px"></div>
                    <div style="font-weight:600;color:#0f172a">Loading PR / PO lines from SAP…</div>
                </div>
            `;

            const uri = item.ProcurementPRSet?.__deferred?.uri
                || `http://app-prod.evolvclothing.com:8000/sap/opu/odata/sap/ZBUSINESS_API_SRV/ProcurementDashboardSet(SalesDoc='${item.SalesDoc || so}',ComponentMaterial='${encodeURIComponent(item.ComponentMaterial)}')/ProcurementPRSet`;

            const endpoint = cfg.procurementPrUrl || '/sales/procurement-pr';
            try {
                const params = new URLSearchParams({
                    uri: uri,
                    sales_doc: item.SalesDoc || so,
                    component_material: item.ComponentMaterial || '',
                    _: String(Date.now()),
                });
                const res = await fetch(`${endpoint}?${params.toString()}`);
                if (!res.ok) throw new Error(`HTTP ${res.status}: Failed to load PR Set`);
                const payload = await res.json();

                if (payload.status === 'error') {
                    inspectBody.innerHTML = `
                        <div class="pr-line-card" style="text-align:center;color:#ef4444;padding:1.5rem">
                            <h4 style="margin:0">SAP Request Failed</h4>
                            <p style="margin:0.25rem 0 0;font-size:0.8rem">${escapeHtml(payload.error || 'Failed to retrieve PR details from SAP')}</p>
                        </div>
                    `;
                    return;
                }

                const prList = payload.data || [];
                if (!prList.length) {
                    inspectBody.innerHTML = `
                        <div class="pr-line-card" style="text-align:center;color:#64748b;padding:1.5rem">
                            <h4 style="margin:0;color:#0f172a">No purchase requisitions linked</h4>
                            <p style="margin:0.35rem 0 0;font-size:0.8rem">No PR lines found in SAP for this component.</p>
                        </div>
                    `;
                    return;
                }
                inspectBody.innerHTML = prLinesHtml(prList);
            } catch (err) {
                inspectBody.innerHTML = `<div class="empty-state" style="color:#ef4444;padding:1.5rem">Error: ${escapeHtml(err.message)}</div>`;
            }
        }

        async function openDrawerPRSet(item) {
            await openProcInspect(item);
        }

        // Event Listeners: Sort Headers
        panelProcurement.querySelectorAll('.proc-pro-table thead th.col-sortable, .procurement-table thead th.col-sortable').forEach(th => {
            th.addEventListener('click', (e) => {
                if (e.target.closest('input, select, button')) return;
                const col = th.dataset.sort;
                if (!col) return;
                if (currentProcSortCol === col) {
                    currentProcSortDir = (currentProcSortDir === 'asc' ? 'desc' : 'asc');
                } else {
                    currentProcSortCol = col;
                    currentProcSortDir = 'asc';
                }
                updateSortHeaders();
                applyFiltersAndSort();
            });
        });

        // Column filter input events
        panelProcurement.querySelectorAll('.proc-col-filter').forEach(inp => {
            inp.addEventListener('input', () => {
                const col = inp.dataset.col;
                if (col) {
                    activeColFilters[col] = inp.value.trim();
                    applyFiltersAndSort();
                }
            });
        });

        // Clear all column filters button
        const clearColFiltersBtn = panelProcurement.querySelector('#btnClearDrawerProcFilters');
        if (clearColFiltersBtn) {
            clearColFiltersBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                Object.keys(activeColFilters).forEach(k => activeColFilters[k] = '');
                panelProcurement.querySelectorAll('.proc-col-filter').forEach(i => { i.value = ''; });
                applyFiltersAndSort();
            });
        }

        // Group Filter Pills
        const groupPills = panelProcurement.querySelectorAll('.proc-filter-pill');
        groupPills.forEach(pill => {
            pill.addEventListener('click', () => {
                groupPills.forEach(p => p.classList.remove('active'));
                pill.classList.add('active');
                activeGroupFilter = pill.getAttribute('data-group') || 'all';
                applyFiltersAndSort();
            });
        });

        // Global Search Input & Clear
        const searchInput = panelProcurement.querySelector('.proc-search-input');
        const searchClearBtn = panelProcurement.querySelector('.proc-search-clear');
        if (searchInput) {
            searchInput.addEventListener('input', () => {
                activeGlobalSearch = searchInput.value.toLowerCase().trim();
                if (searchClearBtn) {
                    searchClearBtn.style.display = activeGlobalSearch ? 'flex' : 'none';
                }
                applyFiltersAndSort();
            });
        }
        if (searchClearBtn) {
            searchClearBtn.addEventListener('click', () => {
                if (searchInput) searchInput.value = '';
                searchClearBtn.style.display = 'none';
                activeGlobalSearch = '';
                applyFiltersAndSort();
            });
        }

        // Sync Button
        const syncBtn = panelProcurement.querySelector('.btn-proc-sync');
        if (syncBtn) {
            syncBtn.addEventListener('click', () => loadDrawerProcurement(record, true));
        }

        // Empty State Action Buttons
        const emptySyncBtn = panelProcurement.querySelector('.btn-proc-empty-sync');
        if (emptySyncBtn) {
            emptySyncBtn.addEventListener('click', () => loadDrawerProcurement(record, true));
        }
        const emptyBomBtn = panelProcurement.querySelector('.btn-proc-goto-bom');
        if (emptyBomBtn) {
            emptyBomBtn.addEventListener('click', () => openSubDrawer('bom'));
        }
        const emptyPlanningBtn = panelProcurement.querySelector('.btn-proc-goto-planning');
        if (emptyPlanningBtn) {
            emptyPlanningBtn.addEventListener('click', () => openSubDrawer('planning'));
        }

        const stageSortMap = {
            req: 'RequirementQty',
            pr: 'TotalPRQty',
            po: 'TotalPOQty',
            stock: 'StockQty'
        };

        panelProcurement.querySelectorAll('.proc-flow-node[data-stage]').forEach((card) => {
            card.addEventListener('click', () => {
                const stage = card.getAttribute('data-stage') || 'req';
                panelProcurement.querySelectorAll('.proc-flow-node[data-stage]').forEach((c) => {
                    c.classList.toggle('is-active', c === card);
                });
                if (stageSortMap[stage]) {
                    currentProcSortCol = stageSortMap[stage];
                    currentProcSortDir = 'desc';
                    updateSortHeaders();
                    applyFiltersAndSort();
                }
            });
        });

        // Initial render
        applyFiltersAndSort();
    }

    async function loadDrawerProcurement(record, forceReload = false) {
        if (!record) return;
        const so = String(record.sales_order || '').trim();
        if (isBlankCode(so)) {
            renderApiMissing(panelProcurement, 'Sales order required', 'This quotation has no follow-on sales order, so procurement cannot be loaded.');
            if (dncProcVal) dncProcVal.textContent = 'No SO';
            return;
        }
        if (!forceReload && procCacheBySo[so]) {
            renderProcurementPanel(record, false, null, procCacheBySo[so].data, procCacheBySo[so].raw);
            if (dncProcVal) dncProcVal.textContent = `${procCacheBySo[so].data.length} components`;
            return;
        }

        renderProcurementPanel(record, true);
        if (dncProcVal) dncProcVal.textContent = 'Syncing…';

        const endpoint = cfg.procurementUrl || '/sales/procurement';
        try {
            const res = await fetch(`${endpoint}?sales_doc=${encodeURIComponent(so)}&_=${Date.now()}`);
            if (!res.ok) throw new Error(`HTTP ${res.status}: Failed to load procurement data`);
            const payload = await res.json();
            if (payload.status === 'error' || !payload.data) {
                const err = payload.error || 'No procurement records returned from SAP for SO ' + so;
                procCacheBySo[so] = { data: [], raw: payload };
                renderProcurementPanel(record, false, err, [], payload);
                if (dncProcVal) dncProcVal.textContent = '0 components';
            } else {
                procCacheBySo[so] = { data: payload.data, raw: payload };
                renderProcurementPanel(record, false, null, payload.data, payload);
                if (dncProcVal) dncProcVal.textContent = `${payload.data.length} components`;
            }
        } catch (err) {
            renderProcurementPanel(record, false, err.message, [], null);
            if (dncProcVal) dncProcVal.textContent = 'SAP Error';
        }
    }

    function isBlankCode(value) {
        const s = String(value ?? '').trim();
        return s === '' || s === '—' || s === '-' || s === 'null';
    }

    function isTagCategory(record) {
        return String(record?.item_category || '').trim().toUpperCase() === 'TAG';
    }

    function quotationFamilyLines(record) {
        const q = String(record?.quotation || '').trim();
        const so = String(record?.sales_order || '').trim();
        const fromPage = (currentRawRecords || []).filter((r) => {
            if (q && String(r.quotation || '').trim() === q) return true;
            if (!isBlankCode(so) && String(r.sales_order || '').trim() === so) return true;
            return false;
        });
        const extra = [...getSoDetailLines(so), ...getSoDetailLines(q)];
        const map = new Map();
        [...fromPage, ...extra].forEach((r) => {
            const id = String(r.id || `${r.quotation}-${r.line_item}-${r.material}`);
            if (!map.has(id)) map.set(id, r);
        });
        return Array.from(map.values()).sort((a, b) => (Number(a.line_item) || 0) - (Number(b.line_item) || 0));
    }

    function resolveWorkingRecord(clicked) {
        const family = quotationFamilyLines(clicked || {});
        const items = family.filter((r) => !r.is_header);
        let working = clicked || {};
        if (clicked?.is_header || isBlankCode(clicked?.material)) {
            working = items.find(isTagCategory) || items.find((r) => !isBlankCode(r.material)) || clicked || {};
        }
        const so = !isBlankCode(clicked?.sales_order) ? clicked.sales_order : (working.sales_order || '');
        return {
            ...working,
            sales_order: so,
            quotation: clicked?.quotation || working.quotation,
            customer: clicked?.customer || working.customer,
            customer_ref: clicked?.customer_ref || working.customer_ref,
            quotation_type: clicked?.quotation_type || working.quotation_type,
            requested_delivery_date: clicked?.requested_delivery_date || working.requested_delivery_date,
            valid_from: clicked?.valid_from || working.valid_from,
            valid_to: clicked?.valid_to || working.valid_to,
            _family: family,
            _items: items,
        };
    }

    function renderApiMissing(panel, title, message) {
        if (!panel) return;
        panel.innerHTML = `
            <div class="drawer-section-card" style="text-align:center;padding:2.5rem 1rem">
                <span class="material-icons-round" style="font-size:2.4rem;color:#f59e0b;margin-bottom:0.5rem;display:block">info</span>
                <div style="font-weight:700;font-size:1rem;color:#0f172a;margin-bottom:0.35rem">${escapeHtml(title)}</div>
                <div style="color:#64748b;font-size:0.85rem">${escapeHtml(message)}</div>
            </div>
        `;
    }

    function getSoDetailLines(so) {
        const key = String(so || '').trim();
        if (!key || key === '—') return [];
        if (Array.isArray(soDetailLines[key])) return soDetailLines[key];
        const stripped = key.replace(/^0+/, '') || '0';
        if (Array.isArray(soDetailLines[stripped])) return soDetailLines[stripped];
        const padded = key.padStart(10, '0');
        if (Array.isArray(soDetailLines[padded])) return soDetailLines[padded];
        return [];
    }

    function normLineNo(value) {
        return String(value || '').replace(/^0+/, '') || '0';
    }

    function enrichFromSalesApi(item) {
        if (!item) return item;
        const lines = getSoDetailLines(item.sales_order);
        if (!lines.length) return item;
        const line = normLineNo(item.line_item);
        const mat = String(item.material || '').trim();
        let match = lines.find((l) => normLineNo(l.line_item) === line);
        if (!match && mat && mat !== '—') {
            match = lines.find((l) => String(l.material || '').trim() === mat);
        }
        if (!match) return item;
        return {
            ...item,
            ...match,
            quotation: item.quotation || match.quotation,
            sales_order: item.sales_order || match.sales_order,
            _family: item._family,
        };
    }

    function fillSoModuleCards(record) {
        const so = record?.sales_order || '';
        const unit = record?.unit || 'EA';
        const confd = Number(record?.confd_deliv_qty);
        const qty = Number(record?.qty) || 0;
        if (dncPlanningVal) {
            dncPlanningVal.textContent = `${fmtNum(Number.isFinite(confd) && confd > 0 ? confd : qty)} ${unit}`;
        }
        if (dncBomVal) {
            if (!isBlankCode(so) && bomCacheBySo[so]) {
                dncBomVal.textContent = `${filterBomByLine(bomCacheBySo[so].data, record?.line_item).length} components`;
            } else {
                dncBomVal.textContent = 'Live SAP BOM';
            }
        }
        if (dncMaterialVal) {
            dncMaterialVal.textContent = isBlankCode(record?.material) ? '—' : record.material;
        }
        if (dncProcVal) {
            if (!isBlankCode(so) && procCacheBySo[so]) {
                dncProcVal.textContent = `${procCacheBySo[so].data.length} components`;
            } else if (record?.delivery_status && record.delivery_status !== '—') {
                dncProcVal.textContent = `Delivery ${record.delivery_status}`;
            } else {
                dncProcVal.textContent = isBlankCode(so) ? 'Needs sales order' : 'Ready';
            }
        }
    }

    function closeSoLineDrawer() {
        closeSubDrawer();
        if (soLineDrawer) {
            soLineDrawer.classList.remove('open');
            soLineDrawer.setAttribute('aria-hidden', 'true');
        }
    }

    function openSoLineDrawer(item, familyRecord) {
        if (!item) return;
        currentDrawerRecord = resolveWorkingRecord(enrichFromSalesApi({
            ...item,
            sales_order: familyRecord?.sales_order || item.sales_order,
            quotation: familyRecord?.quotation || item.quotation,
        }));
        const rec = currentDrawerRecord;
        const so = rec.sales_order || '—';
        const qt = rec.quotation || '—';
        if (soLineKicker) soLineKicker.textContent = rec.material && !isBlankCode(rec.material) ? rec.material : `Line ${rec.line_item || '—'}`;
        if (soLineTitle) soLineTitle.textContent = rec.material && !isBlankCode(rec.material) ? rec.material : `Sales Order ${so}`;
        if (soLineSub) {
            soLineSub.hidden = true;
            soLineSub.textContent = `Quotation ${qt} • Plant ${rec.plant || '—'} • ${rec.item_category || '—'}`;
        }
        const soBadgeNum = document.getElementById('soLineSoNum');
        if (soBadgeNum) soBadgeNum.textContent = so;
        const lineBadgeNum = document.getElementById('soLineLineNum');
        if (lineBadgeNum) lineBadgeNum.textContent = rec.line_item || '—';
        if (soLineFacts) {
            const facts = [
                ['Line', rec.line_item || '—', 'is-blue'],
                ['Qty', `${fmtNum(rec.qty)} ${rec.unit || 'EA'}`, 'is-green'],
                ['Confirmed', `${fmtNum(rec.confd_deliv_qty || rec.qty)} ${rec.unit || 'EA'}`, 'is-cyan'],
                ['Net', fmtMoney(rec.net_amount), 'is-amber'],
                ['Cost', fmtMoney(rec.cost), 'is-rose'],
                ['Status', rec.sd_process_status && rec.sd_process_status !== '—' ? rec.sd_process_status : (rec.status || 'Active'), 'is-violet'],
                ['Plant', rec.plant || '—', 'is-slate'],
                ['Cat', rec.item_category || '—', 'is-mint'],
            ];
            soLineFacts.innerHTML = facts.map(([label, val, cls], i) => (
                `<span class="so-line-badge ${cls}" style="animation-delay:${0.04 * (i + 1)}s"><span>${escapeHtml(label)}</span><strong>${escapeHtml(String(val))}</strong></span>`
            )).join('');
        }
        fillSoModuleCards(rec);
        if (drawer) drawer.scrollTop = 0;
        if (soLineDrawer) {
            soLineDrawer.classList.add('open');
            soLineDrawer.setAttribute('aria-hidden', 'false');
        }
    }

    function getSoTableSearchTerm() {
        return String(document.getElementById('soRelatedSearch')?.value || '').trim().toLowerCase();
    }

    function soTableRowText(r) {
        return [
            r.line_item, r.item_category, r.material, r.material_group, r.plant,
            r.customer, r.quotation_type, r.sales_order_type, r.qty, r.net_amount, r.sales_order,
        ].map((v) => String(v ?? '')).join(' ').toLowerCase();
    }

    function getSoColFilters() {
        const filters = {};
        document.querySelectorAll('.so-col-filter').forEach((inp) => {
            const key = inp.getAttribute('data-so-filter');
            const val = String(inp.value || '').trim().toLowerCase();
            if (key && val) filters[key] = val;
        });
        return filters;
    }

    function soLineMatchesFilters(item, q, colFilters) {
        if (q && !soTableRowText(item).includes(q)) return false;
        const map = {
            line_item: item.line_item,
            item_category: item.item_category,
            material: item.material,
            plant: item.plant,
            customer: item.customer,
            type: item.quotation_type || item.sales_order_type,
            qty: item.qty,
            net_amount: item.net_amount,
            sales_order: item.sales_order,
        };
        return Object.entries(colFilters).every(([key, val]) => String(map[key] ?? '').toLowerCase().includes(val));
    }

    function applySoTableFilter() {
        const q = getSoTableSearchTerm();
        const colFilters = getSoColFilters();
        const body = document.getElementById('soRelatedLinesBody');
        const countEl = document.getElementById('soRelatedCount');
        if (!body) return;
        let visible = 0;
        body.querySelectorAll('.so-related-row').forEach((row) => {
            const idx = Number(row.getAttribute('data-related-idx'));
            const item = soTableLines[idx];
            const show = item ? soLineMatchesFilters(item, q, colFilters) : false;
            row.style.display = show ? '' : 'none';
            if (show) visible += 1;
        });
        if (countEl) {
            const total = soTableLines.length;
            countEl.textContent = `Showing ${visible.toLocaleString()} of ${total.toLocaleString()} items`;
        }
        updateSoFoot();
    }

    function updateSoFoot() {
        const label = document.getElementById('soFootLabel');
        const qtyEl = document.getElementById('soFootQty');
        const netEl = document.getElementById('soFootNet');
        let qty = 0;
        let net = 0;
        let visible = 0;
        document.querySelectorAll('#soRelatedLinesBody .so-related-row').forEach((row) => {
            if (row.style.display === 'none') return;
            const item = soTableLines[Number(row.getAttribute('data-related-idx'))];
            if (!item) return;
            visible += 1;
            qty += Number(item.qty) || 0;
            net += Number(item.net_amount) || 0;
        });
        if (label) label.textContent = visible ? `Total (${visible})` : 'Total';
        if (qtyEl) qtyEl.textContent = visible ? fmtNum(qty) : '';
        if (netEl) netEl.textContent = visible ? fmtMoney(net) : '';
    }

    function soSortValue(row, col) {
        if (col === 'type') return row.quotation_type || row.sales_order_type || '';
        return row[col];
    }

    function sortSoLines(lines) {
        if (!soTableSort.col) return lines;
        const col = soTableSort.col;
        const dir = soTableSort.dir === 'desc' ? -1 : 1;
        return [...lines].sort((a, b) => {
            if (col === 'qty' || col === 'net_amount') {
                return ((Number(soSortValue(a, col)) || 0) - (Number(soSortValue(b, col)) || 0)) * dir;
            }
            return String(soSortValue(a, col) || '').localeCompare(String(soSortValue(b, col) || ''), undefined, { numeric: true }) * dir;
        });
    }

    function updateSoSortHeaders() {
        document.querySelectorAll('#soRelatedTable thead th[data-sort]').forEach((th) => {
            const col = th.getAttribute('data-sort');
            th.classList.toggle('is-sorted-asc', col === soTableSort.col && soTableSort.dir === 'asc');
            th.classList.toggle('is-sorted-desc', col === soTableSort.col && soTableSort.dir === 'desc');
        });
    }

    function exportSoTable() {
        const q = getSoTableSearchTerm();
        const colFilters = getSoColFilters();
        const rows = soTableLines.filter((r) => soLineMatchesFilters(r, q, colFilters));
        const so = soTableParent?.sales_order || 'sales-order';
        downloadCsv(`sales-order-${so}-${Date.now()}.csv`, recordsToCsv(rows));
    }

    function renderRelatedOrderLines(record, options) {
        const keepTools = Boolean(options && options.keepTools);
        const body = document.getElementById('soRelatedLinesBody');
        const countEl = document.getElementById('soRelatedCount');
        const family = record?._family || quotationFamilyLines(record);
        const lines = sortSoLines(family.length ? family : []);
        soTableLines = lines;
        soTableParent = record;
        const searchEl = document.getElementById('soRelatedSearch');
        const searchClear = document.getElementById('soRelatedSearchClear');
        if (searchEl) {
            if (!keepTools) searchEl.value = '';
            if (searchClear) searchClear.style.display = searchEl.value.trim() ? '' : 'none';
            if (!searchEl.dataset.bound) {
                searchEl.dataset.bound = '1';
                searchEl.addEventListener('input', () => {
                    if (searchClear) searchClear.style.display = searchEl.value.trim() ? '' : 'none';
                    applySoTableFilter();
                });
            }
        }
        if (searchClear && !searchClear.dataset.bound) {
            searchClear.dataset.bound = '1';
            searchClear.addEventListener('click', () => {
                if (searchEl) searchEl.value = '';
                searchClear.style.display = 'none';
                searchEl?.focus();
                applySoTableFilter();
            });
        }
        const exportEl = document.getElementById('soRelatedExport');
        if (exportEl && !exportEl.dataset.bound && !exportEl.hasAttribute('data-excel-export')) {
            exportEl.dataset.bound = '1';
            exportEl.addEventListener('click', exportSoTable);
        }
        const soTableEl = document.getElementById('soRelatedTable');
        const soFilterBtn = document.getElementById('soRelatedFilterBtn');
        if (soFilterBtn && !soFilterBtn.dataset.bound) {
            soFilterBtn.dataset.bound = '1';
            soFilterBtn.addEventListener('click', () => {
                const on = soTableEl?.classList.toggle('show-col-filters');
                soFilterBtn.classList.toggle('is-on', !!on);
                soFilterBtn.classList.toggle('is-active', !!on);
                soFilterBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
        }
        if (!document.documentElement.dataset.soSortBound) {
            document.documentElement.dataset.soSortBound = '1';
            soTableEl?.querySelector('thead')?.addEventListener('click', (e) => {
                const th = e.target.closest('th[data-sort]');
                if (!th || e.target.closest('input, button')) return;
                const col = th.getAttribute('data-sort');
                if (soTableSort.col === col) soTableSort.dir = soTableSort.dir === 'asc' ? 'desc' : 'asc';
                else soTableSort = { col, dir: 'asc' };
                if (soTableParent) renderRelatedOrderLines(soTableParent, { keepTools: true });
            });
        }
        initSoColumnMenu();
        const soExpandBtn = document.getElementById('soRelatedExpandBtn');
        const soPanel = document.getElementById('soRelatedPanel');
        if (soExpandBtn && !soExpandBtn.dataset.bound) {
            soExpandBtn.dataset.bound = '1';
            soExpandBtn.addEventListener('click', () => {
                const on = soPanel?.classList.toggle('is-expanded');
                soExpandBtn.classList.toggle('is-on', !!on);
                soExpandBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
                const icon = soExpandBtn.querySelector('.material-icons-round');
                if (icon) icon.textContent = on ? 'close_fullscreen' : 'open_in_full';
            });
        }
        if (!document.documentElement.dataset.soColFilterBound) {
            document.documentElement.dataset.soColFilterBound = '1';
            document.querySelectorAll('.so-col-filter').forEach((inp) => {
                inp.addEventListener('input', applySoTableFilter);
            });
        }
        if (countEl) {
            countEl.textContent = `Showing ${lines.length.toLocaleString()} of ${lines.length.toLocaleString()} items`;
        }
        updateSoSortHeaders();
        if (!body) return lines;
        if (!lines.length) {
            body.innerHTML = '<tr><td colspan="9" class="so-related-empty">No sales order items for this quotation.</td></tr>';
            updateSoFoot();
            return lines;
        }
        body.innerHTML = lines.map((r, i) => {
            const cat = r.item_category || (r.is_header ? 'HDR' : '—');
            const desc = r.material_group && r.material_group !== '—' ? r.material_group : '';
            return `
            <tr class="so-related-row ${String(r.id) === String(record?.id) ? 'is-active' : ''}" data-orig-sno="${i + 1}" data-related-idx="${i}" tabindex="0">
                <td data-col-id="line_item"><span class="line-no">${escapeHtml(r.line_item)}</span></td>
                <td data-col-id="item_category"><span class="category-tag">${escapeHtml(cat)}</span></td>
                <td data-col-id="material" class="rpt-mat">
                    <div>${escapeHtml(r.material || '—')}</div>
                    ${desc ? `<div class="style-meta">${escapeHtml(desc)}</div>` : ''}
                </td>
                <td data-col-id="plant">${getPlantBadgeHtml(r.plant)}</td>
                <td data-col-id="customer">${escapeHtml(r.customer || '—')}</td>
                <td data-col-id="type">${escapeHtml(r.quotation_type || r.sales_order_type || '—')}</td>
                <td data-col-id="qty" class="col-num">${fmtNum(r.qty)}${r.unit ? ` ${escapeHtml(r.unit)}` : ''}</td>
                <td data-col-id="net_amount" class="col-num">${fmtMoney(r.net_amount)}</td>
                <td data-col-id="sales_order">${escapeHtml(r.sales_order || '—')}</td>
            </tr>`;
        }).join('');
        body.querySelectorAll('.so-related-row').forEach((row) => {
            const idx = Number(row.getAttribute('data-related-idx'));
            const item = lines[idx];
            if (!item) return;
            row.addEventListener('click', (e) => {
                e.stopPropagation();
                body.querySelectorAll('.so-related-row').forEach((el) => el.classList.remove('is-active'));
                row.classList.add('is-active');
                openSoLineDrawer(item, record);
            });
        });
        applySoTableFilter();
        return lines;
    }

    function initSoColumnMenu() {
        const dropdown = document.getElementById('soColumnToggleDropdown');
        const btn = document.getElementById('soColumnToggleBtn');
        const menu = document.getElementById('soColumnToggleMenu');
        if (!dropdown || !btn || !menu || btn.dataset.bound) return;
        btn.dataset.bound = '1';
        const columns = [
            { id: 'line_item', label: 'Line', locked: true },
            { id: 'item_category', label: 'Category' },
            { id: 'material', label: 'Material' },
            { id: 'plant', label: 'Plant' },
            { id: 'customer', label: 'Customer' },
            { id: 'type', label: 'Type' },
            { id: 'qty', label: 'Qty' },
            { id: 'net_amount', label: 'Net' },
            { id: 'sales_order', label: 'Sales Order' },
        ];
        const storageKey = 'sales_order_table_hidden_cols';
        let hidden = new Set();
        try { hidden = new Set(JSON.parse(localStorage.getItem(storageKey) || '[]')); } catch (e) { hidden = new Set(); }
        const style = document.createElement('style');
        style.id = 'soColHideStyle';
        document.head.appendChild(style);
        function applyHidden() {
            style.textContent = [...hidden].map((id) => `#soRelatedTable [data-col-id="${id}"]{display:none !important;}`).join('\n');
            btn.classList.toggle('is-active', hidden.size > 0);
        }
        function renderMenu() {
            menu.innerHTML = `
                <div class="rpt-colmgr-head">
                    <span class="rpt-cat-dd-header">Show columns</span>
                    <span class="rpt-colmgr-count">${columns.length - hidden.size} of ${columns.length}</span>
                </div>
                <ul class="rpt-colmgr-list">
                    ${columns.map((col) => `
                        <li class="rpt-colmgr-item${col.locked ? ' is-locked' : ''}${hidden.has(col.id) ? ' is-hidden' : ''}">
                            <label class="rpt-colmgr-label">
                                <input type="checkbox" data-col-toggle="${col.id}" ${col.locked || !hidden.has(col.id) ? 'checked' : ''} ${col.locked ? 'disabled' : ''}>
                                <span>${col.label}</span>
                            </label>
                        </li>`).join('')}
                </ul>
                <div class="rpt-cat-dd-divider"></div>
                <button type="button" class="rpt-cat-dd-item" data-col-reset>
                    <span class="material-icons-round">restart_alt</span>
                    <span class="rpt-cat-dd-item-text">Show all columns</span>
                </button>`;
        }
        function save() {
            try { localStorage.setItem(storageKey, JSON.stringify([...hidden])); } catch (e) {}
            applyHidden();
            renderMenu();
        }
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const open = dropdown.classList.toggle('is-open');
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        menu.addEventListener('change', (e) => {
            const input = e.target.closest('[data-col-toggle]');
            if (!input || input.disabled) return;
            if (input.checked) hidden.delete(input.getAttribute('data-col-toggle'));
            else hidden.add(input.getAttribute('data-col-toggle'));
            save();
        });
        menu.addEventListener('click', (e) => {
            if (!e.target.closest('[data-col-reset]')) return;
            hidden = new Set();
            save();
        });
        document.addEventListener('click', (e) => {
            if (!dropdown.contains(e.target)) {
                dropdown.classList.remove('is-open');
                btn.setAttribute('aria-expanded', 'false');
            }
        });
        applyHidden();
        renderMenu();
    }

    function renderSoDashboardCharts(lines) {
        const qtyEl = document.getElementById('soQtyChart');
        const mixEl = document.getElementById('soMixChart');
        const chartLines = (lines || []).filter((r) => !r.is_header);
        const qtyLabels = chartLines.map((r) => `${r.line_item} ${r.material || ''}`.trim().slice(0, 22));
        const orderQty = chartLines.map((r) => Number(r.qty) || 0);
        const confdQty = chartLines.map((r) => {
            const confd = Number(r.confd_deliv_qty);
            return Number.isFinite(confd) && confd > 0 ? confd : (Number(r.qty) || 0);
        });
        const mixMap = {};
        chartLines.forEach((r) => {
            const key = String(r.item_category || 'Other').trim() || 'Other';
            mixMap[key] = (mixMap[key] || 0) + (Number(r.net_amount) || 0);
        });
        const mixLabels = Object.keys(mixMap);
        const mixValues = Object.values(mixMap);
        const mixColors = ['#2563eb', '#0d9488', '#d97706', '#7c3aed', '#e11d48', '#64748b'];

        if (soQtyChart) {
            soQtyChart.destroy();
            soQtyChart = null;
        }
        if (soMixChart) {
            soMixChart.destroy();
            soMixChart = null;
        }
        if (qtyEl && typeof Chart !== 'undefined') {
            soQtyChart = new Chart(qtyEl, {
                type: 'bar',
                data: {
                    labels: qtyLabels.length ? qtyLabels : ['No items'],
                    datasets: [{
                        label: 'Order qty',
                        data: orderQty.length ? orderQty : [0],
                        backgroundColor: orderQty.map((_, i) => i % 2 ? 'rgba(20, 184, 166, 0.85)' : 'rgba(37, 99, 235, 0.85)'),
                        borderRadius: 10,
                        borderSkipped: false,
                        maxBarThickness: 18,
                    }],
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { beginAtZero: true, ticks: { color: '#94a3b8', font: { size: 10 } }, grid: { color: 'rgba(15,23,42,0.06)' } },
                        y: { ticks: { color: '#334155', font: { size: 10 } }, grid: { display: false } },
                    },
                },
            });
        }
        if (mixEl && typeof Chart !== 'undefined') {
            const netByItem = chartLines.map((r) => Number(r.net_amount) || 0);
            soMixChart = new Chart(mixEl, {
                type: 'line',
                data: {
                    labels: qtyLabels.length ? qtyLabels : ['No items'],
                    datasets: [{
                        label: 'Net value',
                        data: netByItem.length ? netByItem : [0],
                        borderColor: '#7c3aed',
                        backgroundColor: (ctx) => {
                            const chart = ctx.chart;
                            const { ctx: c, chartArea } = chart;
                            if (!chartArea) return 'rgba(124, 58, 237, 0.18)';
                            const g = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                            g.addColorStop(0, 'rgba(124, 58, 237, 0.35)');
                            g.addColorStop(1, 'rgba(124, 58, 237, 0.02)');
                            return g;
                        },
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: '#7c3aed',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 7,
                        borderWidth: 3,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { ticks: { font: { size: 10 }, color: '#64748b', maxRotation: 35 }, grid: { display: false } },
                        y: { beginAtZero: true, ticks: { color: '#94a3b8', font: { size: 10 } }, grid: { color: 'rgba(15,23,42,0.06)' } },
                    },
                },
            });
        }

        const itemsEl = document.getElementById('soDashItems');
        const qtyKpi = document.getElementById('soDashQty');
        const netKpi = document.getElementById('soDashNet');
        const qtySum = chartLines.reduce((s, r) => s + (Number(r.qty) || 0), 0);
        const netSum = chartLines.reduce((s, r) => s + (Number(r.net_amount) || 0), 0);
        if (itemsEl) itemsEl.textContent = String(chartLines.length);
        if (qtyKpi) qtyKpi.textContent = fmtNum(qtySum);
        if (netKpi) netKpi.textContent = fmtMoney(netSum);
    }

    async function loadSalesApiHubLines(so, familyRecord) {
        const key = String(so || '').replace(/^0+/, '') || String(so || '');
        if (!key || key === '—') return [];
        if (Array.isArray(salesApiHubCache[key]) && salesApiHubCache[key].length) {
            return salesApiHubCache[key];
        }
        if (!cfg.salesApiHubUrl) return [];
        try {
            const url = `${cfg.salesApiHubUrl}?sales_order=${encodeURIComponent(key)}`;
            const res = await fetch(url, { credentials: 'same-origin' });
            const json = await res.json();
            const records = Array.isArray(json.records) ? json.records : [];
            const mapped = records.map((row) => ({
                ...row,
                quotation: familyRecord?.quotation || row.quotation,
                customer: familyRecord?.customer || row.customer,
                customer_ref: familyRecord?.customer_ref || row.customer_ref,
                quotation_type: familyRecord?.quotation_type || row.quotation_type,
            }));
            salesApiHubCache[key] = mapped;
            soDetailLines[key] = mapped;
            soDetailLines[String(so)] = mapped;
            return mapped;
        } catch (_) {
            return [];
        }
    }

    function openSalesOrderOffcanvas(record, initialTab = null, specificSo = null) {
        if (!record || !drawer) return;
        currentDrawerRecord = resolveWorkingRecord(record);
        if (specificSo && !isBlankCode(specificSo)) {
            currentDrawerRecord.sales_order = specificSo;
        }
        const related = renderRelatedOrderLines(currentDrawerRecord);
        renderSoDashboardCharts(related);
        const relatedNet = related.reduce((sum, r) => sum + (Number(r.net_amount) || 0), 0);
        const relatedQty = related.reduce((sum, r) => sum + (Number(r.qty) || 0), 0);
        const so = currentDrawerRecord.sales_order || specificSo || '—';
        const qt = currentDrawerRecord.quotation || '—';

        // Update Visible Headings
        const soHeading = document.getElementById('salesDetailSoHeading');
        if (soHeading) {
            soHeading.textContent = so && so !== '—' && so !== '—' ? `#${so}` : (qt && qt !== '—' && qt !== '—' ? `(Qt #${qt})` : '—');
        }
        if (detailTitle) {
            detailTitle.innerHTML = so && so !== '—' && so !== '—'
                ? `Sales Order <span class="so-h2-highlight">#${escapeHtml(so)}</span>`
                : `Quotation <span class="so-h2-highlight">#${escapeHtml(qt)}</span>`;
        }

        if (drawerQtNum) drawerQtNum.textContent = qt;
        if (drawerSoNum) drawerSoNum.textContent = so;
        if (drawerLineNum) drawerLineNum.textContent = String(related.length);
        if (drawerPlantNum) drawerPlantNum.textContent = currentDrawerRecord.plant || '—';
        const qtTypeEl = document.getElementById('salesDetailQtType');
        if (qtTypeEl) {
            qtTypeEl.textContent = currentDrawerRecord.quotation_type || currentDrawerRecord.sales_order_type || '—';
        }
        const qtCustEl = document.getElementById('salesDetailCustomer');
        if (qtCustEl) {
            qtCustEl.textContent = currentDrawerRecord.customer_ref || currentDrawerRecord.customer || '—';
        }
        if (drawerStatusPill) {
            drawerStatusPill.hidden = true;
            drawerStatusPill.textContent = currentDrawerRecord.status || 'Active';
        }
        if (drawerSub) {
            drawerSub.hidden = true;
        }

        if (drawerNetVal) drawerNetVal.textContent = fmtMoney(relatedNet);
        if (drawerQtyVal) drawerQtyVal.textContent = `${fmtNum(relatedQty)} ${currentDrawerRecord.unit || 'EA'}`;
        if (drawerDateVal) drawerDateVal.textContent = fmtSapDate(currentDrawerRecord.date) || currentDrawerRecord.date || '—';

        // Update Drawer KPIs
        const itemsEl = document.getElementById('soDashItems');
        const qtyKpi = document.getElementById('soDashQty');
        const netKpi = document.getElementById('soDashNet');
        if (itemsEl) itemsEl.textContent = String(related.length);
        if (qtyKpi) qtyKpi.textContent = fmtNum(relatedQty);
        if (netKpi) netKpi.textContent = fmtMoney(relatedNet);

        const setOd = (id, val) => {
            const el = document.getElementById(id);
            if (el) el.textContent = val || '—';
        };
        setOd('odSalesOrder', so);
        setOd('odLine', currentDrawerRecord.line_item);
        setOd('odPlant', currentDrawerRecord.plant);
        setOd('odStatus', currentDrawerRecord.status || 'Active');
        setOd('odMaterial', currentDrawerRecord.material);
        setOd('odMaterialGroup', currentDrawerRecord.material_group);
        setOd('odQty', `${fmtNum(currentDrawerRecord.qty)} ${currentDrawerRecord.unit || 'EA'}`);
        setOd('odNet', fmtMoney(currentDrawerRecord.net_amount));
        setOd('odDate', fmtSapDate(currentDrawerRecord.date) || currentDrawerRecord.date);
        setOd('odDivision', currentDrawerRecord.division);
        setOd('odCustomer', currentDrawerRecord.customer_ref || currentDrawerRecord.customer);
        setOd('odUnit', currentDrawerRecord.unit || 'EA');

        if (drawerQuickNet) drawerQuickNet.textContent = fmtMoney(relatedNet);
        if (drawerQuickQty) drawerQuickQty.textContent = `${fmtNum(relatedQty)} ${currentDrawerRecord.unit || 'EA'}`;
        if (drawerQuickDate) drawerQuickDate.textContent = fmtSapDate(currentDrawerRecord.date) || currentDrawerRecord.date || '—';
        if (drawerQuickPlant) drawerQuickPlant.textContent = currentDrawerRecord.plant || '—';

        closeSoLineDrawer();
        drawer.classList.add('open');
        drawer.setAttribute('aria-hidden', 'false');
        if (backdrop) {
            backdrop.hidden = false;
            backdrop.style.display = 'block';
            backdrop.classList.add('open');
        }
        document.body.classList.add('sales-detail-open');

        activeDrawerTab = null;
        closeSubDrawer();

        if (so && so !== '—' && so !== '—') {
            loadSalesApiHubLines(so, currentDrawerRecord).then((apiLines) => {
                if (!apiLines.length || !drawer.classList.contains('open')) return;
                const apiRecord = { ...currentDrawerRecord, _family: apiLines };
                currentDrawerRecord = apiRecord;
                const updatedRelated = renderRelatedOrderLines(apiRecord);
                renderSoDashboardCharts(apiLines);
                const updNet = updatedRelated.reduce((sum, r) => sum + (Number(r.net_amount) || 0), 0);
                const updQty = updatedRelated.reduce((sum, r) => sum + (Number(r.qty) || 0), 0);
                if (itemsEl) itemsEl.textContent = String(updatedRelated.length);
                if (qtyKpi) qtyKpi.textContent = fmtNum(updQty);
                if (netKpi) netKpi.textContent = fmtMoney(updNet);
            });
        }
    }

    function openDetail(record) {
        openSalesOrderOffcanvas(record, null);
    }

    function closeDetail() {
        closeSoLineDrawer();
        closeSubDrawer();
        drawer?.classList.remove('open');
        drawer?.setAttribute('aria-hidden', 'true');
        if (backdrop) {
            backdrop.classList.remove('open');
            backdrop.hidden = true;
            backdrop.style.display = 'none';
        }
        document.body.classList.remove('sales-detail-open');
    }

    function updateKpis(summary) {
        if (!summary) return;
        animateValue(document.getElementById('skNet'), summary.net_sales, v => fmtMoney(v));
        animateValue(document.getElementById('skQty'), summary.total_qty || 0, v => fmtNum(v));
        const quotCount = Number(summary.quotations || (summary.lines > 0 ? 1 : 0));
        const lineCount = Number(summary.lines || 0);
        animateValue(document.getElementById('skOrders'), quotCount, v => fmtNum(v));
        const linesPill = document.getElementById('skLinesPill');
        if (linesPill) {
            linesPill.textContent = `${fmtNum(lineCount)} Item${lineCount !== 1 ? 's' : ''}`;
        }
        const skOrdersSub = document.getElementById('skOrdersSub');
        if (skOrdersSub) {
            skOrdersSub.textContent = `${fmtNum(lineCount)} Quoted Line Item${lineCount !== 1 ? 's' : ''}`;
        }
        const convRate = Number(summary.conversion_rate != null ? summary.conversion_rate : 0);
        animateValue(document.getElementById('skConversion'), convRate, v => v.toFixed(1) + '%');
        const convBar = document.getElementById('skConversionBar');
        if (convBar) {
            convBar.style.width = Math.min(100, Math.max(0, convRate)) + '%';
        }
        const convSub = document.getElementById('skConversionSub');
        if (convSub) {
            const convQ = Number(summary.converted_quotations || 0);
            const pendQ = Number(summary.pending_quotations || 0);
            convSub.textContent = `${convQ} Converted • ${pendQ} Open`;
        }
        const convPill = document.getElementById('skConversionPill');
        if (convPill) {
            convPill.textContent = convRate >= 80 ? 'High' : (convRate >= 50 ? 'Moderate' : 'Active');
        }

        animateValue(document.getElementById('skAvg'), summary.avg_line || 0, v => fmtMoney(v));
        animateValue(document.getElementById('skReturn'), summary.return_rate || 0, v => v.toFixed(1) + '%');
        animateValue(document.getElementById('skMargin'), summary.gross_margin || 0, v => v.toFixed(1) + '%');
        const procKpi = document.getElementById('skProcurement');
        if (procKpi) {
            animateValue(procKpi, summary.orders || 0, v => fmtNum(v) + ' orders');
        }
        if (liveCount) {
            liveCount.innerHTML = `<span class="material-icons-round">receipt_long</span> ${fmtNum(lineCount)} items`;
        }
        if (tableCount && summary && summary.lines !== undefined) {
            tableCount.textContent = `${fmtNum(quotCount)} Quotation${quotCount !== 1 ? 's' : ''} • ${fmtNum(lineCount)} Items`;
        }
    }

    function updateRange(data) {
        const total = Number(data?.total || 0);
        const page = Number(data?.page || 1);
        const per = Number(data?.per_page || pageSize);
        const totalPages = Number(data?.pages || 1);
        const start = total === 0 ? 0 : ((page - 1) * per) + 1;
        const end = Math.min(page * per, total);

        if (rangeInfo) {
            const shown = lastGroupCount;
            rangeInfo.textContent = `Showing ${shown.toLocaleString()} of ${shown.toLocaleString()} quotations`;
        }
        if (tableCount && lastPayload?.summary) {
            const qCount = Number(lastPayload.summary.quotations || (total > 0 ? 1 : 0));
            tableCount.textContent = `${fmtNum(qCount)} Quotation${qCount !== 1 ? 's' : ''} • ${fmtNum(total)} Items`;
        } else if (tableCount) {
            tableCount.textContent = fmtNum(total);
        }
        prevBtn && (prevBtn.disabled = page <= 1 || total === 0);
        nextBtn && (nextBtn.disabled = !data || page >= totalPages || total === 0);

        // Render Numbered Page Group Buttons
        if (pageNumbersContainer) {
            if (totalPages <= 1) {
                pageNumbersContainer.innerHTML = '';
                return;
            }
            let html = '';
            const maxVisible = 5;
            let startPage = Math.max(1, page - 2);
            let endPage = Math.min(totalPages, startPage + maxVisible - 1);
            if (endPage - startPage < maxVisible - 1) {
                startPage = Math.max(1, endPage - maxVisible + 1);
            }

            if (startPage > 1) {
                html += `<button type="button" class="sales-num-btn" data-page="1">1</button>`;
                if (startPage > 2) html += `<span class="sales-num-ellipsis">…</span>`;
            }

            for (let p = startPage; p <= endPage; p++) {
                html += `<button type="button" class="sales-num-btn ${p === page ? 'active' : ''}" data-page="${p}">${p}</button>`;
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) html += `<span class="sales-num-ellipsis">…</span>`;
                html += `<button type="button" class="sales-num-btn" data-page="${totalPages}">${totalPages}</button>`;
            }

            pageNumbersContainer.innerHTML = html;

            pageNumbersContainer.querySelectorAll('.sales-num-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const targetPage = parseInt(btn.dataset.page, 10);
                    if (targetPage && targetPage !== page) {
                        fetchPage(targetPage);
                    }
                });
            });
        }
    }

    window.kapisKpiDetails = function (key) {
        const summary = lastPayload?.summary || {};
        const charts = lastPayload?.charts || {};
        const mix = charts.mix || charts.plants || {};
        const period = currentFrom && currentTo ? `${currentFrom} → ${currentTo}` : 'Selected period';
        const common = [
            ['Period', period],
            ['Net Sales', fmtMoney(summary.net_sales)],
            ['Order Quantity', fmtNum(summary.total_qty)],
            ['Sales Orders', fmtNum(summary.orders)],
            ['Order Lines', fmtNum(summary.lines || lastPayload?.total || 0)],
            ['Top material', charts.top_styles?.labels?.[0] || '—'],
        ];
        const card = document.querySelector(`.kpi-card[data-kpi="${key}"]`);
        const title = card?.querySelector('.label')?.textContent?.trim() || 'KPI details';
        const value = card?.querySelector('.value')?.textContent?.trim() || '—';
        const note = card?.querySelector('.change')?.textContent?.trim() || '';
        const icon = card?.querySelector('.kpi-icon .material-icons-round')?.textContent?.trim() || 'insights';
        const tone = [...(card?.classList || [])].find((c) => c.startsWith('tone-'))?.replace('tone-', '') || 'sky';
        return { title, value, note, icon, tone, rows: common };
    };

    function initCharts() {
        if (typeof Chart === 'undefined') return;

        Chart.defaults.color = '#64748b';
        Chart.defaults.borderColor = '#e2e8f0';
        Chart.defaults.font.family = 'DM Sans, sans-serif';

        const btnItems = document.getElementById('btnChartModeItems');
        const btnTrend = document.getElementById('btnChartModeTrend');
        btnItems?.addEventListener('click', (e) => {
            e.preventDefault();
            currentLeftChartMode = 'items';
            if (lastChartsData) renderLeftChart(lastChartsData);
        });
        btnTrend?.addEventListener('click', (e) => {
            e.preventDefault();
            currentLeftChartMode = 'trend';
            if (lastChartsData) renderLeftChart(lastChartsData);
        });

        const btnCat = document.getElementById('btnMixModeCategory');
        const btnCust = document.getElementById('btnMixModeCustomer');
        const btnMat = document.getElementById('btnMixModeMaterial');
        btnCat?.addEventListener('click', (e) => {
            e.preventDefault();
            currentRightChartMode = 'category';
            if (lastChartsData) renderRightChart(lastChartsData);
        });
        btnCust?.addEventListener('click', (e) => {
            e.preventDefault();
            currentRightChartMode = 'customer';
            if (lastChartsData) renderRightChart(lastChartsData);
        });
        btnMat?.addEventListener('click', (e) => {
            e.preventDefault();
            currentRightChartMode = 'material';
            if (lastChartsData) renderRightChart(lastChartsData);
        });

        const trendEl = document.getElementById('salesTrendChart');
        if (trendEl) {
            trendChart = new Chart(trendEl, {
                type: 'line',
                data: {
                    labels: [], datasets: [{
                        label: 'Net Sales',
                        data: [],
                        borderColor: (ctx) => {
                            const { chart } = ctx;
                            const { ctx: c, chartArea } = chart;
                            if (!chartArea) return '#3b82f6';
                            // Vibrant horizontal multi-stop gradient line
                            const g = c.createLinearGradient(chartArea.left, 0, chartArea.right, 0);
                            g.addColorStop(0, '#06b6d4');   // Bright Turquoise Cyan
                            g.addColorStop(0.35, '#3b82f6'); // Electric Blue
                            g.addColorStop(0.7, '#8b5cf6');  // Vivid Purple
                            g.addColorStop(1, '#ec4899');   // Radiant Hot Pink
                            return g;
                        },
                        borderWidth: 3.5,
                        backgroundColor: (ctx) => {
                            const { chart } = ctx;
                            const { ctx: c, chartArea } = chart;
                            if (!chartArea) return 'rgba(59, 130, 246, 0.15)';
                            // Luminous multi-color vertical gradient fill
                            const g = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                            g.addColorStop(0, 'rgba(139, 92, 246, 0.42)');
                            g.addColorStop(0.35, 'rgba(59, 130, 246, 0.22)');
                            g.addColorStop(0.75, 'rgba(6, 182, 212, 0.08)');
                            g.addColorStop(1, 'rgba(255, 255, 255, 0.0)');
                            return g;
                        },
                        fill: true,
                        tension: 0.44,
                        pointRadius: 6,
                        pointHoverRadius: 9,
                        pointBackgroundColor: '#ffffff',
                        pointBorderColor: '#6366f1',
                        pointBorderWidth: 3,
                        pointHoverBackgroundColor: '#ec4899',
                        pointHoverBorderColor: '#ffffff',
                        pointHoverBorderWidth: 3.5,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: chartAnim,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.95)',
                            titleColor: '#ffffff',
                            bodyColor: '#e2e8f0',
                            borderColor: 'rgba(255, 255, 255, 0.15)',
                            borderWidth: 1,
                            padding: 12,
                            boxPadding: 6,
                            cornerRadius: 10,
                            callbacks: {
                                label: function (ctx) {
                                    return ` Net Sales: ${fmtMoney(ctx.parsed.y)}`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                color: '#64748b',
                                font: { size: 11, weight: 600 },
                                callback: v => symbol + Number(v).toLocaleString()
                            },
                            grid: {
                                color: 'rgba(226, 232, 240, 0.7)',
                                borderDash: [5, 5]
                            }
                        },
                        x: {
                            ticks: {
                                color: '#64748b',
                                font: { size: 11, weight: 600 },
                                maxRotation: 0,
                                autoSkip: true,
                                maxTicksLimit: 12
                            },
                            grid: { display: false }
                        },
                    },
                },
            });
        }

        const divEl = document.getElementById('salesDivisionChart');
        if (divEl) {
            divisionChart = new Chart(divEl, {
                type: 'doughnut',
                data: {
                    labels: [],
                    datasets: [{
                        data: [],
                        backgroundColor: PALETTE,
                        borderWidth: 4,
                        borderColor: '#ffffff',
                        borderRadius: 8,
                        spacing: 3,
                        hoverOffset: 12,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '72%',
                    animation: { animateRotate: true, animateScale: true, duration: 1200, easing: 'easeOutQuart' },
                    onClick: function (evt, els) { clickFilter(this)(evt, els); },
                    onHover: function (evt, elements) {
                        const centerVal = document.getElementById('plantMixCenterVal');
                        const centerLbl = document.getElementById('plantMixCenterLbl');
                        if (!centerVal || !centerLbl) return;
                        if (elements && elements.length > 0) {
                            const idx = elements[0].index;
                            const label = this.data.labels[idx];
                            const val = Number(this.data.datasets[0].data[idx] || 0);
                            const total = this.data.datasets[0].data.reduce((a, b) => a + Number(b || 0), 0);
                            const pct = total > 0 ? Math.round((val / total) * 100) : 0;
                            centerVal.textContent = `${pct}%`;
                            centerLbl.textContent = label;
                        } else {
                            centerVal.textContent = (this.data.labels || []).length + ' Plants';
                            centerLbl.textContent = 'Active Mix';
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: '#334155',
                                padding: 16,
                                boxWidth: 12,
                                boxHeight: 12,
                                font: { size: 12, weight: 700 },
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.95)',
                            titleColor: '#ffffff',
                            bodyColor: '#e2e8f0',
                            borderColor: 'rgba(255, 255, 255, 0.15)',
                            borderWidth: 1,
                            padding: 12,
                            boxPadding: 6,
                            cornerRadius: 10,
                            callbacks: {
                                label: function (ctx) {
                                    const val = ctx.parsed || 0;
                                    const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                    const pct = total > 0 ? Math.round((val / total) * 100) : 0;
                                    return ` ${ctx.label}: ${fmtMoney(val)} (${pct}%)`;
                                }
                            }
                        }
                    },
                },
            });
        }

        const stylesEl = document.getElementById('salesStylesChart');
        if (stylesEl) {
            stylesChart = new Chart(stylesEl, {
                type: 'bar',
                data: {
                    labels: [], datasets: [{
                        label: 'Net Amount',
                        data: [],
                        backgroundColor: PALETTE,
                        borderRadius: 8,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    animation: chartAnim,
                    onClick: function (evt, els) { clickFilter(this)(evt, els); },
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { beginAtZero: true, ticks: { color: '#94a3b8', callback: v => symbol + Number(v).toLocaleString() }, grid: { color: 'rgba(255,255,255,0.05)' } },
                        y: { ticks: { color: '#94a3b8' }, grid: { display: false } }
                    },
                },
            });
        }

        const channelEl = document.getElementById('salesChannelChart');
        if (channelEl) {
            channelChart = new Chart(channelEl, {
                type: 'bar',
                data: {
                    labels: [], datasets: [{
                        label: 'Net Amount',
                        data: [],
                        backgroundColor: PALETTE.slice().reverse(),
                        borderRadius: 10,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: chartAnim,
                    onClick: function (evt, els) { clickFilter(this)(evt, els); },
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, ticks: { color: '#94a3b8', callback: v => symbol + Number(v).toLocaleString() }, grid: { color: 'rgba(255,255,255,0.05)' } },
                        x: { ticks: { color: '#94a3b8' }, grid: { display: false } },
                    },
                },
            });
        }

        const statusEl = document.getElementById('salesStatusChart');
        if (statusEl) {
            statusChart = new Chart(statusEl, {
                type: 'doughnut',
                data: { labels: [], datasets: [{ data: [], backgroundColor: ['#38bdf8', '#34d399', '#fbbf24', '#f43f5e', '#a855f7'], borderWidth: 2, borderColor: '#0f172a', hoverOffset: 10 }] },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '58%',
                    onClick: function (evt, els) { clickFilter(this)(evt, els); },
                    plugins: { legend: { position: 'bottom', labels: { color: '#cbd5e1', boxWidth: 10, padding: 10 } } },
                },
            });
        }
    }

    function paintKpiSparks(values) {
        const nums = (values || []).map(Number).filter((n) => !Number.isNaN(n));
        const slice = nums.slice(-8);
        const max = Math.max(...slice, 1);

        function fillSpark(id, series) {
            const el = document.getElementById(id);
            if (!el) return;
            const bars = el.querySelectorAll('i');
            bars.forEach((bar, i) => {
                const v = series[i] != null ? series[i] : 12 + (i * 7) % 40;
                const h = Math.max(16, Math.round((Number(v) / max) * 100));
                bar.style.setProperty('--h', `${h}%`);
            });
        }

        function setDelta(id, series) {
            const el = document.getElementById(id);
            if (!el || series.length < 2) return;
            const prev = Number(series[series.length - 2]) || 0;
            const last = Number(series[series.length - 1]) || 0;
            if (prev === 0) {
                el.hidden = true;
                return;
            }
            const pct = ((last - prev) / Math.abs(prev)) * 100;
            el.hidden = false;
            el.classList.toggle('is-up', pct >= 0);
            el.classList.toggle('is-down', pct < 0);
            el.innerHTML = `<span class="material-icons-round">${pct >= 0 ? 'arrow_upward' : 'arrow_downward'}</span> ${Math.abs(pct).toFixed(0)}%`;
        }

        fillSpark('skNetSpark', slice);
        fillSpark('skQtySpark', slice.map((v, i) => v * (0.72 + ((i % 3) * 0.08))));
        fillSpark('skOrdersSpark', slice.map((v, i) => v * (0.55 + ((i % 4) * 0.1))));
        fillSpark('skConversionSpark', slice.map((v, i) => v * (0.85 + ((i % 2) * 0.12))));
        setDelta('skNetDelta', nums);
        setDelta('skQtyDelta', nums);
        setDelta('skOrdersDelta', nums);
    }

    function renderLeftChart(charts) {
        const trendEl = document.getElementById('salesTrendChart');
        if (!trendEl || !charts) return;
        const itemsData = charts.items || null;
        const trendData = charts.trend || null;

        // Auto logic: if single date/quotation or items available, show items breakdown
        const hasMultipleDates = (trendData?.labels?.length || 0) > 1;
        const activeMode = currentLeftChartMode === 'auto'
            ? (hasMultipleDates ? 'trend' : 'items')
            : currentLeftChartMode;

        const titleEl = document.getElementById('trendChartTitle');
        const subEl = document.getElementById('trendChartSubtitle');
        const iconEl = document.getElementById('trendChartIcon');
        const peakEl = document.getElementById('trendPeakStat');
        const tabItems = document.getElementById('btnChartModeItems');
        const tabTrend = document.getElementById('btnChartModeTrend');

        tabItems?.classList.toggle('active', activeMode === 'items');
        tabTrend?.classList.toggle('active', activeMode === 'trend');

        if (activeMode === 'items' && itemsData && itemsData.labels && itemsData.labels.length > 0) {
            if (titleEl) titleEl.textContent = 'Item Breakdown';
            if (subEl) subEl.textContent = 'Net value & quantity per line item';
            if (iconEl) iconEl.textContent = 'bar_chart';

            const totalItemsNet = itemsData.values.reduce((a, b) => a + Number(b || 0), 0);
            if (peakEl) peakEl.textContent = `${itemsData.labels.length} Items • ${fmtMoney(totalItemsNet)}`;

            if (trendChart) {
                trendChart.destroy();
                trendChart = null;
            }

            trendChart = new Chart(trendEl, {
                type: 'bar',
                data: {
                    labels: itemsData.labels,
                    datasets: [
                        {
                            label: 'Net Value',
                            data: itemsData.values,
                            backgroundColor: (ctx) => {
                                const colors = ['#3b82f6', '#06b6d4', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#6366f1'];
                                return colors[ctx.dataIndex % colors.length];
                            },
                            borderRadius: 8,
                            borderSkipped: false,
                            maxBarThickness: 45,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: chartAnim,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.95)',
                            titleColor: '#ffffff',
                            bodyColor: '#e2e8f0',
                            borderColor: 'rgba(255, 255, 255, 0.15)',
                            borderWidth: 1,
                            padding: 12,
                            boxPadding: 6,
                            cornerRadius: 10,
                            callbacks: {
                                label: function(ctx) {
                                    const idx = ctx.dataIndex;
                                    const val = ctx.parsed.y;
                                    const qty = itemsData.quantities?.[idx] || 0;
                                    const unit = itemsData.units?.[idx] || 'EA';
                                    const mat = itemsData.materials?.[idx] || '';
                                    const lines = [` Net Value: ${fmtMoney(val)}`, ` Quantity: ${fmtNum(qty)} ${unit}`];
                                    if (mat && mat !== '—') lines.unshift(` Material: ${mat}`);
                                    return lines;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                color: '#64748b',
                                font: { size: 11, weight: 600 },
                                callback: v => symbol + Number(v).toLocaleString()
                            },
                            grid: {
                                color: 'rgba(226, 232, 240, 0.7)',
                                borderDash: [5, 5]
                            }
                        },
                        x: {
                            ticks: {
                                color: '#64748b',
                                font: { size: 11, weight: 600 },
                                maxRotation: 25,
                                minRotation: 0,
                                autoSkip: false
                            },
                            grid: { display: false }
                        }
                    }
                }
            });

            paintKpiSparks(itemsData.values);
        } else {
            // Date Trend mode
            if (titleEl) titleEl.textContent = 'Quotation Value Trend';
            if (subEl) subEl.textContent = 'Net value aggregated by quotation date';
            if (iconEl) iconEl.textContent = 'show_chart';

            const maxVal = (trendData?.values || []).length > 0 ? Math.max(...trendData.values.map(Number)) : 0;
            if (peakEl) peakEl.textContent = `Peak: ${fmtMoney(maxVal)}`;

            if (trendChart) {
                trendChart.destroy();
                trendChart = null;
            }

            trendChart = new Chart(trendEl, {
                type: 'line',
                data: {
                    labels: trendData?.labels || [],
                    datasets: [{
                        label: 'Net Sales',
                        data: trendData?.values || [],
                        borderColor: '#3b82f6',
                        borderWidth: 3.5,
                        backgroundColor: (ctx) => {
                            const { chart } = ctx;
                            const { ctx: c, chartArea } = chart;
                            if (!chartArea) return 'rgba(59, 130, 246, 0.15)';
                            const g = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                            g.addColorStop(0, 'rgba(139, 92, 246, 0.42)');
                            g.addColorStop(0.35, 'rgba(59, 130, 246, 0.22)');
                            g.addColorStop(0.75, 'rgba(6, 182, 212, 0.08)');
                            g.addColorStop(1, 'rgba(255, 255, 255, 0.0)');
                            return g;
                        },
                        fill: true,
                        tension: 0.44,
                        pointRadius: 6,
                        pointHoverRadius: 9,
                        pointBackgroundColor: '#ffffff',
                        pointBorderColor: '#6366f1',
                        pointBorderWidth: 3,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: chartAnim,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.95)',
                            titleColor: '#ffffff',
                            bodyColor: '#e2e8f0',
                            borderColor: 'rgba(255, 255, 255, 0.15)',
                            borderWidth: 1,
                            padding: 12,
                            boxPadding: 6,
                            cornerRadius: 10,
                            callbacks: {
                                label: function (ctx) {
                                    return ` Net Sales: ${fmtMoney(ctx.parsed.y)}`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                color: '#64748b',
                                font: { size: 11, weight: 600 },
                                callback: v => symbol + Number(v).toLocaleString()
                            },
                            grid: {
                                color: 'rgba(226, 232, 240, 0.7)',
                                borderDash: [5, 5]
                            }
                        },
                        x: {
                            ticks: {
                                color: '#64748b',
                                font: { size: 11, weight: 600 },
                                maxRotation: 0,
                                autoSkip: true,
                                maxTicksLimit: 12
                            },
                            grid: { display: false }
                        }
                    }
                }
            });

            paintKpiSparks(trendData?.values || []);
        }
    }

    function renderRightChart(charts) {
        const divEl = document.getElementById('salesDivisionChart');
        if (!divEl || !charts) return;

        const titleEl = document.getElementById('salesMixTitle');
        const subEl = document.getElementById('salesMixSubtitle');
        const centerVal = document.getElementById('plantMixCenterVal');
        const centerLbl = document.getElementById('plantMixCenterLbl');
        const tabCat = document.getElementById('btnMixModeCategory');
        const tabCust = document.getElementById('btnMixModeCustomer');
        const tabMat = document.getElementById('btnMixModeMaterial');

        tabCat?.classList.toggle('active', currentRightChartMode === 'category');
        tabCust?.classList.toggle('active', currentRightChartMode === 'customer');
        tabMat?.classList.toggle('active', currentRightChartMode === 'material');

        let mixData = null;
        if (currentRightChartMode === 'material' && charts.materials && charts.materials.labels?.length > 0) {
            mixData = {
                title: 'Material Share',
                subtitle: 'Net quoted amount by material',
                labels: charts.materials.labels,
                values: charts.materials.values,
            };
        } else if (currentRightChartMode === 'customer' && charts.customers && charts.customers.labels?.length > 0) {
            mixData = {
                title: 'Customer Distribution',
                subtitle: 'Net quoted amount by customer',
                labels: charts.customers.labels,
                values: charts.customers.values,
            };
        } else {
            const catData = charts.categories && charts.categories.labels?.length > 0 ? charts.categories : (charts.mix || charts.division);
            mixData = {
                title: 'Item Category Mix',
                subtitle: 'Net quoted amount by category',
                labels: catData?.labels || ['No data'],
                values: catData?.values || [0],
            };
        }

        if (titleEl) titleEl.textContent = mixData.title;
        if (subEl) subEl.textContent = mixData.subtitle;

        const totalVal = (mixData.values || []).reduce((a, b) => a + Number(b || 0), 0);

        if (divisionChart) {
            divisionChart.destroy();
            divisionChart = null;
        }

        divisionChart = new Chart(divEl, {
            type: 'doughnut',
            data: {
                labels: mixData.labels || [],
                datasets: [{
                    data: mixData.values || [],
                    backgroundColor: (mixData.labels || []).map((_, i) => PALETTE[i % PALETTE.length]),
                    borderWidth: 4,
                    borderColor: '#ffffff',
                    borderRadius: 8,
                    spacing: 3,
                    hoverOffset: 12,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                animation: { animateRotate: true, animateScale: true, duration: 900, easing: 'easeOutQuart' },
                onClick: function (evt, els) { clickFilter(this)(evt, els); },
                onHover: function (evt, elements) {
                    if (!centerVal || !centerLbl) return;
                    if (elements && elements.length > 0) {
                        const idx = elements[0].index;
                        const label = this.data.labels[idx];
                        const val = Number(this.data.datasets[0].data[idx] || 0);
                        const total = this.data.datasets[0].data.reduce((a, b) => a + Number(b || 0), 0);
                        const pct = total > 0 ? Math.round((val / total) * 100) : 0;
                        centerVal.textContent = `${pct}%`;
                        centerLbl.textContent = label;
                    } else {
                        centerVal.textContent = fmtMoney(totalVal);
                        centerLbl.textContent = 'Total Value';
                    }
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: '#334155',
                            padding: 14,
                            boxWidth: 12,
                            boxHeight: 12,
                            font: { size: 12, weight: 700 },
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.95)',
                        titleColor: '#ffffff',
                        bodyColor: '#e2e8f0',
                        borderColor: 'rgba(255, 255, 255, 0.15)',
                        borderWidth: 1,
                        padding: 12,
                        boxPadding: 6,
                        cornerRadius: 10,
                        callbacks: {
                            label: function (ctx) {
                                const val = ctx.parsed || 0;
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = total > 0 ? Math.round((val / total) * 100) : 0;
                                return ` ${ctx.label}: ${fmtMoney(val)} (${pct}%)`;
                            }
                        }
                    }
                }
            }
        });

        if (centerVal) centerVal.textContent = fmtMoney(totalVal);
        if (centerLbl) centerLbl.textContent = 'Total Value';
    }

    function updateCharts(charts) {
        if (!charts || typeof Chart === 'undefined') return;
        lastChartsData = charts;

        renderLeftChart(charts);
        renderRightChart(charts);

        if (stylesChart && charts.top_styles) {
            stylesChart.data.labels = charts.top_styles.labels || [];
            stylesChart.data.datasets[0].data = charts.top_styles.values || [];
            stylesChart.data.datasets[0].backgroundColor = colorSet((charts.top_styles.labels || []).length);
            stylesChart.update('active');
        }

        const bar = charts.bar || charts.channels;
        if (channelChart && bar) {
            const barTitle = document.getElementById('salesBarTitle');
            const barSub = document.getElementById('salesBarSubtitle');
            if (barTitle && bar.title) barTitle.textContent = bar.title;
            if (barSub) barSub.textContent = bar.subtitle || 'Click a bar to filter';
            channelChart.data.labels = bar.labels || [];
            channelChart.data.datasets[0].data = bar.values || [];
            channelChart.data.datasets[0].backgroundColor = colorSet((bar.labels || []).length).reverse();
            channelChart.update('active');
        }

        if (statusChart && charts.statuses) {
            statusChart.data.labels = charts.statuses.labels || [];
            statusChart.data.datasets[0].data = charts.statuses.values || [];
            statusChart.update('active');
        }

        renderBoards(charts);
    }

    function getPlantBadgeHtml(plant) {
        const p = String(plant || '').trim().toUpperCase();
        if (!p || p === '—') return '<span class="plant-badge plant-none">—</span>';

        const map = {
            'PO01': 'plant-emerald',
            'P001': 'plant-emerald',
            'PO02': 'plant-indigo',
            'P002': 'plant-indigo',
            'PO03': 'plant-amber',
            'P003': 'plant-amber',
            'PO04': 'plant-rose',
            'P004': 'plant-rose',
            'PO05': 'plant-purple',
            'P005': 'plant-purple',
            'PO06': 'plant-cyan',
            'P006': 'plant-cyan',
            '1000': 'plant-blue',
            '2000': 'plant-teal',
        };

        let cls = map[p];
        if (!cls) {
            const classes = ['plant-indigo', 'plant-emerald', 'plant-amber', 'plant-rose', 'plant-purple', 'plant-cyan', 'plant-blue', 'plant-teal'];
            const sum = p.split('').reduce((acc, c) => acc + c.charCodeAt(0), 0);
            cls = classes[sum % classes.length];
        }

        return `<span class="plant-badge ${cls}"><span class="plant-dot"></span>${escapeHtml(p)}</span>`;
    }

    function isHeaderLine(record) {
        return Boolean(record?.is_header || (String(record?.line_item) === '0' && (Number(record?.qty) === 0 || !record?.material || record.material === '—')));
    }

    function groupRowsByQuotation(lines) {
        const order = [];
        const buckets = new Map();
        lines.forEach((row) => {
            const key = String(row.quotation || '').trim() || `__line_${row.id || order.length}`;
            if (!buckets.has(key)) {
                buckets.set(key, []);
                order.push(key);
            }
            buckets.get(key).push(row);
        });

        return order.map((key) => {
            const rows = buckets.get(key);
            const items = rows.filter((row) => !isHeaderLine(row));
            const base = items[0] || rows[0] || {};
            const materials = [...new Set(items.map((row) => String(row.material || '').trim()).filter((value) => value && value !== '—'))];
            const categories = [...new Set(items.map((row) => String(row.item_category || '').trim()).filter(Boolean))];
            const orders = [...new Set(rows.map((row) => String(row.sales_order || '').trim()).filter((value) => value && !isBlankCode(value)))];
            const units = [...new Set(items.map((row) => String(row.unit || '').trim()).filter(Boolean))];
            return {
                ...base,
                id: `qt-${key}`,
                is_group: true,
                quotation: base.quotation || key,
                raw_rows: rows,
                raw_items: items,
                item_count: items.length,
                material: materials[0] || '',
                material_extra: Math.max(0, materials.length - 1),
                material_group: materials.length > 1 ? `${materials.length} materials` : (base.material_group || ''),
                item_category: categories.length === 1 ? categories[0] : (categories.length > 1 ? 'Mixed' : (base.quotation_type || '')),
                qty: items.reduce((sum, row) => sum + (Number(row.qty) || 0), 0),
                unit: units.length === 1 ? units[0] : '',
                net_amount: items.reduce((sum, row) => sum + (Number(row.net_amount) || 0), 0),
                sales_orders: orders,
                sales_order: orders[0] || '',
            };
        });
    }

    function renderRows(records) {
        if (!tbody) return;
        if (!records.length) {
            tbody.innerHTML = emptyRowHtml('No data found', 'No quotation lines match this filter.');
            updateTableFoot([]);
            return;
        }

        tbody.innerHTML = records.map((r, i) => {
            const sno = i + 1;
            const isGroup = Boolean(r.is_group);
            const isHdr = !isGroup && isHeaderLine(r);
            const cat = r.item_category || (isHdr ? 'HDR' : '');
            const desc = isGroup && r.material_extra
                ? `+${r.material_extra} more`
                : (r.material_group && r.material_group !== '—' ? r.material_group : '');
            const orders = isGroup && Array.isArray(r.sales_orders) ? r.sales_orders : [];
            const hasSo = orders.length ? true : !isBlankCode(r.sales_order);
            const soClean = hasSo ? escapeHtml(orders[0] || r.sales_order) : '';
            const extraOrders = orders.slice(1);
            const subItems = (r.raw_items && r.raw_items.length > 0) ? r.raw_items : [];
            const lineBadge = isGroup
                ? `<div class="sales-item-pill-wrap">
                    <span class="line-no" title="${r.item_count} item${r.item_count === 1 ? '' : 's'}">${r.item_count} item${r.item_count === 1 ? '' : 's'}</span>
                    ${subItems.length > 1 ? `
                        <button type="button" class="sales-row-expand-btn" data-qt="${escapeHtml(r.quotation)}" title="Expand/collapse line items" aria-label="Expand line items">
                            <i class="fas fa-chevron-down"></i>
                        </button>
                    ` : ''}
                   </div>`
                : (isHdr
                    ? `<span class="line-no hdr-line" title="Quotation Header">0 (Header)</span>`
                    : `<span class="line-no">${escapeHtml(r.line_item)}</span>`);
            const matDisplay = isGroup && !r.material
                ? `<span class="style-name text-muted" style="color:#94a3b8;font-style:italic;">No material lines</span>`
                : (isHdr && (!r.material || r.material === '—')
                    ? `<span class="style-name text-muted" style="color:#94a3b8;font-style:italic;">Quotation Header</span>`
                    : `<span class="style-name">${escapeHtml(r.material || '—')}</span>`);
            const soType = !isGroup && r.sales_order_type ? `&bull; ${escapeHtml(r.sales_order_type)}` : '';

            const mainTr = `
                <tr class="sales-row row-animate ${isGroup ? 'row-quotation-group' : ''} ${isHdr ? 'row-quotation-header' : ''}" data-orig-sno="${sno}" data-id="${escapeHtml(r.id)}" data-row-idx="${i}" data-quotation="${escapeHtml(r.quotation)}" tabindex="0" role="button" title="Quotation ${escapeHtml(r.quotation)}" style="animation-delay:${Math.min(i, 12) * 0.03}s">
                    <td data-col-id="sno" class="num sno" style="text-align:center;"><span class="sales-sno">${sno}</span></td>
                    <td data-col-id="quotation">
                        <button type="button" class="po-item-tag is-link qt-id-btn sales-qt-badge" data-quotation="${escapeHtml(r.quotation)}" title="Open Quotation #${escapeHtml(r.quotation)}">
                            <i class="fas fa-file-invoice"></i>
                            <span>${escapeHtml(r.quotation)}</span>
                        </button>
                    </td>
                    <td data-col-id="line_item">${lineBadge}</td>
                    <td data-col-id="material" class="col-style">
                        ${matDisplay}
                        ${desc ? `<span class="style-meta">${escapeHtml(desc)}</span>` : ''}
                    </td>
                    <td data-col-id="item_category">${cat ? `<span class="category-tag">${escapeHtml(cat)}</span>` : '—'}</td>
                    <td data-col-id="customer">${escapeHtml(r.customer || '—')}</td>
                    <td data-col-id="customer_ref" class="col-style"><span class="style-name">${escapeHtml(r.customer_ref || '—')}</span></td>
                    <td data-col-id="date" class="col-date">${escapeHtml(fmtSapDate(r.date) || r.date || '—')}</td>
                    <td data-col-id="qty" class="col-num num">${fmtNum(r.qty)}${r.unit ? ` ${escapeHtml(r.unit)}` : ''}</td>
                    <td data-col-id="net_amount" class="col-num num revenue-cell">${fmtMoney(r.net_amount)}</td>
                    <td data-col-id="sales_order">
                        ${hasSo ? `
                            <button type="button" class="po-item-tag is-link so-action-btn" data-so="${soClean}" title="View Live Sales Order #${soClean} Details">
                                <i class="fas fa-file-invoice"></i>
                                <span>${soClean}</span>
                            </button>
                            ${extraOrders.length ? `<span class="style-meta">+${extraOrders.length} more</span>` : ''}
                        ` : `<span class="category-tag" style="background:#f1f5f9;color:#94a3b8">—</span>`}
                        ${soType ? `<span class="style-meta">${soType}</span>` : ''}
                    </td>
                </tr>
            `;

            const extTr = subItems.length > 1 ? `
                <tr class="sales-extended-row" data-extended-for="${escapeHtml(r.quotation)}" style="display:none;">
                    <td colspan="11" class="sales-extended-cell">
                        <div class="sales-extended-content">
                            <div class="sales-extended-bar">
                                <span class="sales-ext-title"><i class="fas fa-layer-group"></i> Quotation <b>${escapeHtml(r.quotation)}</b> Line Items (${subItems.length})</span>
                                <span class="sales-ext-cust"><i class="fas fa-building"></i> Customer: <b>${escapeHtml(r.customer || '—')}</b></span>
                                ${r.customer_ref ? `<span class="sales-ext-ref"><i class="fas fa-tag"></i> Ref: ${escapeHtml(r.customer_ref)}</span>` : ''}
                            </div>
                            <table class="sales-sub-table">
                                <thead>
                                    <tr>
                                        <th style="width: 55px; text-align: center;">Item #</th>
                                        <th>Material</th>
                                        <th>Material Group</th>
                                        <th>Category</th>
                                        <th class="num">Quantity</th>
                                        <th class="num">Net Value</th>
                                        <th>Sales Order</th>
                                        <th>Plant</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${subItems.map(item => `
                                        <tr>
                                            <td style="text-align: center;"><span class="line-no">${escapeHtml(item.line_item || '—')}</span></td>
                                            <td><span class="style-name"><b>${escapeHtml(item.material || '—')}</b></span></td>
                                            <td>${escapeHtml(item.material_group || '—')}</td>
                                            <td><span class="category-tag">${escapeHtml(item.item_category || '—')}</span></td>
                                            <td class="num">${fmtNum(item.qty)}${item.unit ? ` ${escapeHtml(item.unit)}` : ''}</td>
                                            <td class="num revenue-cell">${fmtMoney(item.net_amount)}</td>
                                            <td>${item.sales_order ? `<button type="button" class="po-item-tag is-link so-action-btn" data-so="${escapeHtml(item.sales_order)}" style="font-size:11px;padding:2px 6px;"><i class="fas fa-file-invoice"></i> ${escapeHtml(item.sales_order)}</button>` : '<span class="text-muted">—</span>'}</td>
                                            <td><span class="po-plant-badge">${escapeHtml(item.plant || '—')}</span></td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </td>
                </tr>
            ` : '';

            return mainTr + extTr;
        }).join('');

        // 1. Direct Sales Order button click
        tbody.querySelectorAll('.so-action-btn').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                e.preventDefault();
                const soVal = btn.getAttribute('data-so');
                const row = btn.closest('.sales-row');
                const rowIdx = Number(row?.getAttribute('data-row-idx'));
                const rec = records[rowIdx] || records.find(r => String(r.sales_order) === String(soVal)) || { sales_order: soVal };
                openSalesOrderOffcanvas(rec, null, soVal);
            });
        });

        // 2. Direct Quotation button click
        tbody.querySelectorAll('.qt-id-btn').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                e.preventDefault();
                const row = btn.closest('.sales-row');
                const rowIdx = Number(row?.getAttribute('data-row-idx'));
                const rec = records[rowIdx];
                if (rec) openSalesOrderOffcanvas(rec, null);
            });
        });

        // 3. Extended row expand/collapse toggle
        tbody.querySelectorAll('.sales-row-expand-btn').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                e.preventDefault();
                const qt = btn.getAttribute('data-qt');
                const extRow = tbody.querySelector(`tr.sales-extended-row[data-extended-for="${qt}"]`);
                if (extRow) {
                    const isHidden = extRow.style.display === 'none';
                    extRow.style.display = isHidden ? 'table-row' : 'none';
                    btn.classList.toggle('is-open', isHidden);
                    const icon = btn.querySelector('i');
                    if (icon) {
                        icon.className = isHidden ? 'fas fa-chevron-up' : 'fas fa-chevron-down';
                    }
                }
            });
        });

        // 4. Row click (any other part of row)
        updateTableFoot(records);

        tbody.querySelectorAll('.sales-row').forEach((row, i) => {
            const record = records[i];

            row.addEventListener('click', (e) => {
                if (e.target.closest('button, a')) return;
                openSalesOrderOffcanvas(record, null);
            });

            row.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    openSalesOrderOffcanvas(record, null);
                }
            });
        });
    }

    function applyFiltersAndSort() {
        let list = [...currentRawRecords];

        const queryTerm = getDashboardSearchTerm();
        const idTokens = extractIdSearchList(queryTerm);
        const isIdSearch = idTokens.length > 0 ||
            Boolean(activeColFilters.quotation || activeColFilters.sales_order);

        if (!isIdSearch && currentFrom && currentTo) {
            list = list.filter((r) => {
                const iso = String(r.date_iso || r.date || '').slice(0, 10);
                if (!/^\d{4}-\d{2}-\d{2}$/.test(iso)) return false;
                return iso >= currentFrom && iso <= currentTo;
            });
        }

        if (idTokens.length > 1) {
            const tokenSet = new Set(idTokens.map(t => t.replace(/^0+/, '')));
            list = list.filter(r => {
                const qClean = String(r.quotation || '').replace(/^0+/, '');
                const soClean = String(r.sales_order || '').replace(/^0+/, '');
                return tokenSet.has(qClean) || tokenSet.has(soClean) ||
                    (r.sales_orders && Array.isArray(r.sales_orders) && r.sales_orders.some(s => tokenSet.has(String(s).replace(/^0+/, ''))));
            });
        }

        // 1. Column-level filters
        list = list.filter(r => {
            if (activeColFilters.quotation) {
                const qn = String(r.quotation || '').toLowerCase();
                if (!qn.includes(activeColFilters.quotation.toLowerCase())) return false;
            }
            if (activeColFilters.line_item) {
                const ln = String(r.line_item || '');
                if (!ln.includes(activeColFilters.line_item)) return false;
            }
            if (activeColFilters.material) {
                const mat = String(r.material || '').toLowerCase();
                const grp = String(r.material_group || '').toLowerCase();
                const q = activeColFilters.material.toLowerCase();
                if (!mat.includes(q) && !grp.includes(q)) return false;
            }
            if (activeColFilters.item_category) {
                const cat = String(r.item_category || '').toLowerCase();
                if (!cat.includes(activeColFilters.item_category.toLowerCase())) return false;
            }
            if (activeColFilters.customer) {
                const cu = String(r.customer || '').toLowerCase();
                if (!cu.includes(activeColFilters.customer.toLowerCase())) return false;
            }
            if (activeColFilters.quotation_type) {
                const ty = String(r.quotation_type || '').toLowerCase();
                if (!ty.includes(activeColFilters.quotation_type.toLowerCase())) return false;
            }
            if (activeColFilters.customer_ref) {
                const cr = String(r.customer_ref || '').toLowerCase();
                if (!cr.includes(activeColFilters.customer_ref.toLowerCase())) return false;
            }
            if (activeColFilters.sales_order) {
                const so = String(r.sales_order || '').toLowerCase();
                if (!so.includes(activeColFilters.sales_order.toLowerCase())) return false;
            }
            if (activeColFilters.created_by) {
                const cb = String(r.created_by || '').toLowerCase();
                if (!cb.includes(activeColFilters.created_by.toLowerCase())) return false;
            }
            if (activeColFilters.currency) {
                const cur = String(r.currency || '').toLowerCase();
                if (!cur.includes(activeColFilters.currency.toLowerCase())) return false;
            }
            if (activeColFilters.date) {
                const dt = String(r.date || '');
                const fmtDt = fmtSapDate(r.date).toLowerCase();
                const q = activeColFilters.date.toLowerCase();
                if (!dt.includes(q) && !fmtDt.includes(q)) return false;
            }
            if (activeColFilters.qty) {
                const qVal = parseFloat(activeColFilters.qty);
                if (!isNaN(qVal) && Number(r.qty || 0) < qVal) return false;
            }
            if (activeColFilters.net_amount) {
                const amtVal = parseFloat(activeColFilters.net_amount);
                if (!isNaN(amtVal) && Number(r.net_amount || 0) < amtVal) return false;
            }
            if (activeColFilters.status) {
                const st = String(r.status || '').toLowerCase();
                if (!st.includes(activeColFilters.status.toLowerCase())) return false;
            }
            return true;
        });

        // 2. Sort
        if (currentSortCol) {
            list.sort((a, b) => {
                let vA = a[currentSortCol];
                let vB = b[currentSortCol];
                if (currentSortCol === 'qty' || currentSortCol === 'net_amount' || currentSortCol === 'line_item') {
                    vA = parseFloat(vA) || 0;
                    vB = parseFloat(vB) || 0;
                    return currentSortDir === 'asc' ? vA - vB : vB - vA;
                }
                vA = String(vA || '').toLowerCase();
                vB = String(vB || '').toLowerCase();
                const cmp = vA.localeCompare(vB, undefined, { numeric: true });
                return currentSortDir === 'asc' ? cmp : -cmp;
            });
        }

        const grouped = groupRowsByQuotation(list);
        lastGroupCount = grouped.length;
        renderRows(grouped);

        const activeFilterCount = Object.values(activeColFilters).filter(Boolean).length;
        const activeBadge = document.getElementById('salesActiveFilterBadge');
        if (activeBadge) {
            activeBadge.style.display = activeFilterCount > 0 ? 'inline-flex' : 'none';
            activeBadge.textContent = String(activeFilterCount);
        }

        const clearAllBtn = document.getElementById('salesClearAllFilters');
        const hasSearch = Boolean(searchInput?.value.trim());
        const hasFilters = activeFilterCount > 0 || hasSearch || Boolean(currentSortCol);
        if (clearAllBtn) {
            clearAllBtn.style.display = hasFilters ? 'inline-flex' : 'none';
        }

        if (rangeInfo) {
            rangeInfo.textContent = `Showing ${grouped.length.toLocaleString()} of ${grouped.length.toLocaleString()} quotations`;
            rangeInfo.classList.toggle('is-filtered', hasFilters);
        }
        if (tableCount) {
            const itemCount = list.filter((row) => !isHeaderLine(row)).length;
            tableCount.textContent = `${fmtNum(grouped.length)} Quotation${grouped.length === 1 ? '' : 's'} • ${fmtNum(itemCount)} Items`;
        }
    }

    function updateTableFoot(records) {
        const label = document.getElementById('salesFootLabel');
        const qtyCell = document.getElementById('salesFootQty');
        const netCell = document.getElementById('salesFootNet');
        const qty = records.reduce((sum, row) => sum + (Number(row.qty) || 0), 0);
        const net = records.reduce((sum, row) => sum + (Number(row.net_amount) || 0), 0);
        if (label) label.textContent = records.length ? `Total (${records.length})` : 'Total';
        if (qtyCell) qtyCell.textContent = records.length ? fmtNum(qty) : '';
        if (netCell) netCell.textContent = records.length ? fmtMoney(net) : '';
    }

    function updateSortHeaders() {
        document.querySelectorAll('#salesDataTable thead th.col-sortable').forEach(th => {
            const col = th.dataset.sort;
            th.classList.remove('is-sorted-asc', 'is-sorted-desc');
            if (col === currentSortCol) {
                th.classList.add(currentSortDir === 'asc' ? 'is-sorted-asc' : 'is-sorted-desc');
            }
        });
    }

    function populatePlantOptions(records) {
        const plantSelect = document.getElementById('colFilterPlant');
        if (!plantSelect) return;
        const currentVal = plantSelect.value;
        const plants = Array.from(new Set((records || []).map(r => String(r.plant || '').trim()).filter(Boolean))).sort();
        plantSelect.innerHTML = '<option value="">All Plants</option>' + plants.map(p => `<option value="${escapeHtml(p)}" ${p === currentVal ? 'selected' : ''}>${escapeHtml(p)}</option>`).join('');
    }

    function initTableSortAndFilters() {
        // Sortable headers
        document.querySelectorAll('#salesDataTable thead th.col-sortable').forEach(th => {
            th.addEventListener('click', (e) => {
                if (e.target.closest('input, select, button')) return;
                const col = th.dataset.sort;
                if (!col) return;
                if (currentSortCol === col) {
                    currentSortDir = currentSortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    currentSortCol = col;
                    currentSortDir = 'asc';
                }
                updateSortHeaders();
                applyFiltersAndSort();
            });
        });

        // Column filter text inputs
        document.querySelectorAll('.col-filter-input').forEach(input => {
            const wrap = input.closest('.rpt-col-input-wrap');
            const clearBtn = wrap?.querySelector('.rpt-col-clear');
            const syncClear = () => {
                const val = input.value.trim();
                input.classList.toggle('has-val', val !== '');
                if (clearBtn) clearBtn.style.display = val !== '' ? 'block' : 'none';
            };

            input.addEventListener('input', () => {
                syncClear();
                const col = input.dataset.filterCol;
                if (col) {
                    activeColFilters[col] = input.value.trim();
                    applyFiltersAndSort();
                }
            });
        });

        // Individual column clear buttons (×)
        document.querySelectorAll('.sales-col-filter-row .rpt-col-clear').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                e.preventDefault();
                const wrap = btn.closest('.rpt-col-input-wrap');
                const input = wrap?.querySelector('.col-filter-input');
                if (input) {
                    input.value = '';
                    input.classList.remove('has-val');
                    btn.style.display = 'none';
                    const col = input.dataset.filterCol;
                    if (col) {
                        activeColFilters[col] = '';
                        applyFiltersAndSort();
                    }
                    input.focus();
                }
            });
        });

        // Column filter select dropdowns
        document.querySelectorAll('.col-filter-select').forEach(select => {
            select.addEventListener('change', () => {
                const col = select.dataset.filterCol;
                if (col) {
                    activeColFilters[col] = select.value.trim();
                    applyFiltersAndSort();
                }
            });
        });

        // Clear all column filters button (eraser icon in S.No column)
        const btnClear = document.getElementById('btnClearColFilters');
        btnClear?.addEventListener('click', () => {
            document.querySelectorAll('.sales-col-filter-row .col-filter-input').forEach(inp => {
                inp.value = '';
                inp.classList.remove('has-val');
                const wrap = inp.closest('.rpt-col-input-wrap');
                const cb = wrap?.querySelector('.rpt-col-clear');
                if (cb) cb.style.display = 'none';
            });
            document.querySelectorAll('.col-filter-select').forEach(sel => sel.value = '');
            activeColFilters = {
                quotation: '',
                line_item: '',
                material: '',
                item_category: '',
                customer: '',
                customer_ref: '',
                sales_order: '',
                date: '',
                qty: '',
                net_amount: '',
                status: ''
            };
            applyFiltersAndSort();
        });

        // Global Clear All toolbar button
        const clearAllToolbarBtn = document.getElementById('salesClearAllFilters');
        clearAllToolbarBtn?.addEventListener('click', () => {
            if (searchInput) searchInput.value = '';
            const searchClear = document.getElementById('salesSearchClear');
            if (searchClear) searchClear.style.display = 'none';

            // Also clear header SO search & active filter banner
            const headerSearch = document.getElementById('headerSoSearchInput');
            if (headerSearch) headerSearch.value = '';
            const headerClear = document.getElementById('headerSoSearchClear');
            if (headerClear) headerClear.hidden = true;
            const filterBanner = document.getElementById('salesActiveFilterBanner');
            if (filterBanner) filterBanner.style.display = 'none';

            document.querySelectorAll('.sales-col-filter-row .col-filter-input').forEach(inp => {
                inp.value = '';
                inp.classList.remove('has-val');
                const wrap = inp.closest('.rpt-col-input-wrap');
                const cb = wrap?.querySelector('.rpt-col-clear');
                if (cb) cb.style.display = 'none';
            });
            document.querySelectorAll('.col-filter-select').forEach(sel => sel.value = '');
            activeColFilters = {
                quotation: '',
                line_item: '',
                material: '',
                item_category: '',
                customer: '',
                customer_ref: '',
                sales_order: '',
                date: '',
                qty: '',
                net_amount: '',
                status: ''
            };
            currentSortCol = '';
            currentSortDir = 'asc';
            updateSortHeaders();
            applyFiltersAndSort();
        });

        const tableEl = document.getElementById('salesDataTable');
        const shellEl = document.getElementById('salesTableShell');
        const btnFilter = document.getElementById('btnSalesColFilter');
        const btnExpand = document.getElementById('btnSalesExpandTable');
        btnFilter?.addEventListener('click', () => {
            const on = tableEl?.classList.toggle('show-col-filters');
            btnFilter.classList.toggle('is-on', !!on);
            btnFilter.classList.toggle('is-active', !!on);
            btnFilter.setAttribute('aria-pressed', on ? 'true' : 'false');
            if (on) {
                tableEl.querySelector('.col-filter-input')?.focus();
            }
        });
        const tableWrap = document.getElementById('salesTableWrap');
        const stickyBtn = document.getElementById('salesToggleStickyScroll');
        const stickyLabel = document.getElementById('salesStickyScrollLabel');
        const applySticky = (on) => {
            tableWrap?.classList.toggle('is-scroll-body', on);
            stickyBtn?.classList.toggle('is-active', on);
            if (stickyLabel) stickyLabel.textContent = on ? 'Scroll Body Only' : 'Expand Table';
        };
        applySticky(true);
        stickyBtn?.addEventListener('click', () => applySticky(!tableWrap?.classList.contains('is-scroll-body')));
        initSalesColumnMenu();
        btnExpand?.addEventListener('click', () => {
            const on = shellEl?.classList.toggle('is-expanded');
            document.body.classList.toggle('sales-table-expanded', !!on);
            btnExpand.classList.toggle('is-on', !!on);
            btnExpand.setAttribute('aria-pressed', on ? 'true' : 'false');
            const icon = btnExpand.querySelector('.material-icons-round');
            const lbl = btnExpand.querySelector('.sales-tool-btn-label');
            if (icon) icon.textContent = on ? 'close_fullscreen' : 'open_in_full';
            if (lbl) lbl.textContent = on ? 'Collapse' : 'Expand';
        });
    }

    function initSalesColumnMenu() {
        const dropdown = document.getElementById('salesColumnToggleDropdown');
        const btn = document.getElementById('salesColumnToggleBtn');
        const menu = document.getElementById('salesColumnToggleMenu');
        if (!dropdown || !btn || !menu) return;

        const columns = [
            { id: 'sno', label: 'S.No', locked: true },
            { id: 'quotation', label: 'Quotation' },
            { id: 'line_item', label: 'Item' },
            { id: 'material', label: 'Material' },
            { id: 'item_category', label: 'Category' },
            { id: 'customer', label: 'Customer' },
            { id: 'customer_ref', label: 'Customer Reference' },
            { id: 'date', label: 'Quotation Date' },
            { id: 'qty', label: 'Quantity' },
            { id: 'net_amount', label: 'Net Value' },
            { id: 'sales_order', label: 'Sales Order' },
        ];
        const storageKey = 'sales_quotation_hidden_cols';
        let hidden = new Set();
        try {
            hidden = new Set(JSON.parse(localStorage.getItem(storageKey) || '[]'));
        } catch (e) {
            hidden = new Set();
        }

        const style = document.createElement('style');
        style.id = 'salesColHideStyle';
        document.head.appendChild(style);

        function applyHidden() {
            style.textContent = [...hidden]
                .map((id) => `#salesDataTable [data-col-id="${id}"]{display:none !important;}`)
                .join('\n');
            btn.classList.toggle('is-active', hidden.size > 0);
        }

        function renderMenu() {
            const shown = columns.length - hidden.size;
            menu.innerHTML = `
                <div class="rpt-colmgr-head">
                    <span class="rpt-cat-dd-header">Show columns</span>
                    <span class="rpt-colmgr-count">${shown} of ${columns.length}</span>
                </div>
                <ul class="rpt-colmgr-list">
                    ${columns.map((col) => `
                        <li class="rpt-colmgr-item${col.locked ? ' is-locked' : ''}${hidden.has(col.id) ? ' is-hidden' : ''}">
                            <label class="rpt-colmgr-label">
                                <input type="checkbox" data-col-toggle="${col.id}" ${col.locked || !hidden.has(col.id) ? 'checked' : ''} ${col.locked ? 'disabled' : ''}>
                                <span>${col.label}</span>
                            </label>
                        </li>
                    `).join('')}
                </ul>
                <div class="rpt-cat-dd-divider"></div>
                <button type="button" class="rpt-cat-dd-item" data-col-reset>
                    <span class="material-icons-round">restart_alt</span>
                    <span class="rpt-cat-dd-item-text">Show all columns</span>
                </button>`;
        }

        function save() {
            try { localStorage.setItem(storageKey, JSON.stringify([...hidden])); } catch (e) {}
            applyHidden();
            renderMenu();
        }

        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const open = dropdown.classList.toggle('is-open');
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        menu.addEventListener('change', (e) => {
            const input = e.target.closest('[data-col-toggle]');
            if (!input || input.disabled) return;
            if (input.checked) hidden.delete(input.getAttribute('data-col-toggle'));
            else hidden.add(input.getAttribute('data-col-toggle'));
            save();
        });
        menu.addEventListener('click', (e) => {
            if (!e.target.closest('[data-col-reset]')) return;
            hidden = new Set();
            save();
        });
        document.addEventListener('click', (e) => {
            if (!dropdown.contains(e.target)) {
                dropdown.classList.remove('is-open');
                btn.setAttribute('aria-expanded', 'false');
            }
        });
        applyHidden();
        renderMenu();
    }

    function showLoadingBar(active) {
        const bar = document.getElementById('loadingBar');
        if (!bar) return;
        bar.classList.toggle('active', active);
        if (!active) bar.classList.add('done');
    }

    async function fetchPage(page = 1) {
        if (loading || !cfg.apiUrl) return;
        loading = true;
        showLoadingBar(true);
        showSalesLoader(true, { launch: !lastPayload });
        if (tbody) tbody.innerHTML = emptyRowHtml('Loading quotations…', 'Calling ZI_QuotationSalesOrder_HUB.');

        const queryTerm = getDashboardSearchTerm();
        setDashboardSearchTerm(queryTerm);

        const idTokens = extractIdSearchList(queryTerm);
        const isIdSearch = idTokens.length > 0;

        const params = new URLSearchParams({
            page: String(page),
            per_page: String(pageSize),
            search: queryTerm,
            q: queryTerm,
            from_date: isIdSearch ? '' : currentFrom,
            to_date: isIdSearch ? '' : currentTo,
            from: isIdSearch ? '' : currentFrom,
            to: isIdSearch ? '' : currentTo,
            _: String(Date.now()),
        });
        if (isIdSearch) {
            params.set('quotation', idTokens.join(','));
        }

        try {
            console.log('[Sales] Calling SAP API live:', `${cfg.apiUrl}?${params.toString()}`);
            const fetcher = window.kapisFetch || fetch;
            const res = await fetcher(`${cfg.apiUrl}?${params.toString()}`, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
                credentials: 'same-origin',
            });
            const raw = await res.text();
            let result;
            try {
                result = JSON.parse(raw);
            } catch (_) {
                const looksHtml = /^\s*</.test(raw) || /<!DOCTYPE/i.test(raw);
                throw new Error(
                    looksHtml
                        ? 'Sales API returned HTML instead of JSON. Check routing.'
                        : 'Sales API returned invalid JSON.'
                );
            }

            console.log('[Sales] Live SAP response received:', result);

            if (!res.ok || result.success === false || result.error) {
                throw new Error(result.message || result.error || `Failed to load sales data (${res.status})`);
            }

            const data = (result.data && result.data.records !== undefined) ? result.data : result;
            lastPayload = data;
            currentPage = data.page || page;
            currentRawRecords = data.records || [];
            soDetailLines = data.detail_lines || result.detail_lines || {};
            populatePlantOptions(currentRawRecords);
            applyFiltersAndSort();
            updateKpis(data.summary);
            updateCharts(data.charts || {});
            updateRange(data);
            if (!currentRawRecords.length) {
                const emptyMsg = isIdSearch || queryTerm
                    ? `No quotation or sales order lines found matching "${queryTerm}".`
                    : 'SAP returned no sales lines for this date range.';
                tbody.innerHTML = emptyRowHtml('No data found', emptyMsg);
            }

            if (activeFilterBanner) {
                if (queryTerm) {
                    activeFilterBanner.style.display = 'flex';
                    if (activeFilterText) {
                        if (idTokens.length > 0) {
                            const formatted = idTokens.map(t => `#${t}`).join(', ');
                            activeFilterText.textContent = `Quotation / Sales Order ${formatted} (${data.total || 0} line${(data.total || 0) === 1 ? '' : 's'})`;
                        } else {
                            activeFilterText.textContent = `"${queryTerm}" (${data.total || 0} result${(data.total || 0) === 1 ? '' : 's'})`;
                        }
                    }
                } else {
                    activeFilterBanner.style.display = 'none';
                }
            }
        } catch (err) {
            if (tbody) tbody.innerHTML = emptyRowHtml('No data found', err.message || 'Unable to load sales from SAP.');
            updateRange({ total: 0, page: 1, per_page: pageSize, pages: 1 });
            if (tableCount) tableCount.textContent = '0';
            if (loaderNote) loaderNote.textContent = err.message;
            if (activeFilterBanner && queryTerm) {
                activeFilterBanner.style.display = 'flex';
                if (activeFilterText) activeFilterText.textContent = `Search: "${queryTerm}" (0 results)`;
            }
        } finally {
            loading = false;
            showSalesLoader(false);
            setTimeout(() => showLoadingBar(false), 300);
        }
    }

    function csvEscape(value) {
        const text = String(value ?? '');
        if (/[",\n]/.test(text)) {
            return `"${text.replace(/"/g, '""')}"`;
        }
        return text;
    }

    function recordsToCsv(records) {
        const header = ['Quotation', 'Item', 'Material', 'Description', 'Plant', 'Date', 'Qty', 'Net Value', 'Sales Order', 'Category', 'Customer'];
        const lines = [header.join(',')];
        (records || []).forEach((r) => {
            lines.push([
                r.quotation, r.line_item, r.material, r.style, r.plant,
                r.date, r.qty, r.net_amount, r.sales_order, r.item_category, r.customer,
            ].map(csvEscape).join(','));
        });
        return lines.join('\r\n');
    }

    function downloadCsv(filename, content) {
        const blob = new Blob(['\uFEFF' + content], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        a.click();
        URL.revokeObjectURL(url);
    }

    function queryParams(extra = {}) {
        return new URLSearchParams({
            page: String(currentPage),
            per_page: String(pageSize),
            q: getDashboardSearchTerm(),
            from: currentFrom,
            to: currentTo,
            ...extra,
        });
    }

    async function exportCsv(mode) {
        if (!cfg.apiUrl) return;
        if (mode === 'page') {
            downloadCsv('kapis-order-lines-page.csv', recordsToCsv(lastPayload?.records || []));
            return;
        }
        const params = queryParams({ export: 'csv', page: '1', per_page: '8000' });
        const res = await fetch(`${cfg.apiUrl}?${params.toString()}`);
        if (!res.ok) {
            throw new Error('Export failed');
        }
        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'kapis-order-lines.csv';
        a.click();
        URL.revokeObjectURL(url);
    }

    function closeExportMenu() {
        if (!exportMenu) return;
        exportMenu.hidden = true;
        exportBtn?.setAttribute('aria-expanded', 'false');
    }

    exportBtn?.addEventListener('click', (e) => {
        e.stopPropagation();
        if (!exportMenu) return;
        const open = exportMenu.hidden;
        exportMenu.hidden = !open;
        exportBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    exportMenu?.querySelectorAll('[data-export]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            closeExportMenu();
            try {
                await exportCsv(btn.dataset.export);
            } catch (err) {
                alert(err.message || 'Export failed');
            }
        });
    });

    document.addEventListener('click', (e) => {
        if (exportWrap && !exportWrap.contains(e.target)) closeExportMenu();
    });
    prevBtn?.addEventListener('click', () => {
        if (currentPage > 1) fetchPage(currentPage - 1);
    });
    nextBtn?.addEventListener('click', () => {
        if (lastPayload && currentPage < lastPayload.pages) fetchPage(currentPage + 1);
    });

    const searchSubmitBtn = document.getElementById('btnSalesSearchSubmit');
    searchSubmitBtn?.addEventListener('click', () => {
        setDashboardSearchTerm(searchInput?.value || '');
        fetchPage(1);
    });

    searchInput?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            setDashboardSearchTerm(searchInput.value);
            fetchPage(1);
        }
    });

    searchInput?.addEventListener('input', () => {
        setDashboardSearchTerm(searchInput.value);
        if (!searchInput.value.trim()) {
            clearDashboardSearch(true);
        }
    });

    searchInput?.addEventListener('search', () => {
        if (!searchInput.value.trim()) {
            clearDashboardSearch(true);
        }
    });

    salesSearchClear?.addEventListener('click', () => {
        clearDashboardSearch(true);
        searchInput?.focus();
    });

    const refreshBtn = document.getElementById('btnSalesRefresh');
    refreshBtn?.addEventListener('click', () => {
        if (loading) return;
        refreshBtn.classList.add('is-refreshing');
        fetchPage(currentPage).finally(() => {
            setTimeout(() => refreshBtn.classList.remove('is-refreshing'), 600);
        });
    });

    pageSizeSelect?.addEventListener('change', () => {
        pageSize = Math.max(10, parseInt(pageSizeSelect.value, 10) || 10);
        fetchPage(1);
    });

    closeBtn?.addEventListener('click', closeDetail);
    document.getElementById('salesDetailCloseX')?.addEventListener('click', closeDetail);
    backdrop?.addEventListener('click', closeDetail);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            const shellEl = document.getElementById('salesTableShell');
            if (shellEl?.classList.contains('is-expanded')) {
                document.getElementById('btnSalesExpandTable')?.click();
                return;
            }
            const soPanel = document.getElementById('soRelatedPanel');
            if (soPanel?.classList.contains('is-expanded')) {
                document.getElementById('soRelatedExpandBtn')?.click();
                return;
            }
            if (prDrawer && prDrawer.classList.contains('open')) {
                closePRDrawer();
            } else if (procModal && !procModal.hidden) {
                closeProcurementModal();
            } else {
                closeDetail();
            }
        }
    });

    /* ==========================================================================
       Procurement Data Display
    ========================================================================= */
    let currentProcData = [];
    let currentProcRawPayload = null;
    let currentProcSalesDoc = '';
    let activeProcGroupFilter = 'all';

    /*
     * Function to get the tag class based on the group
     */
    function groupTagClass(grp) {
        const g = String(grp || '').toUpperCase();
        if (g.includes('FABRIC')) return 'tag-fabric';
        if (g.includes('LABEL')) return 'tag-labels';
        if (g.includes('BUTTON')) return 'tag-button';
        if (g.includes('THREAD')) return 'tag-threads';
        if (g.includes('LINING')) return 'tag-lining';
        return '';
    }

    function openProcurementModal(salesDoc = '') {
        if (!procModal) return;
        currentProcSalesDoc = String(salesDoc || currentDrawerRecord?.sales_order || '').trim();
        if (procSalesDocLabel) procSalesDocLabel.textContent = currentProcSalesDoc;
        if (procHeaderMaterial) procHeaderMaterial.textContent = '…';
        if (procHeaderDesc) procHeaderDesc.textContent = 'Connecting to SAP…';
        activeProcGroupFilter = 'all';
        if (procGroupFilters) {
            procGroupFilters.querySelectorAll('.proc-filter-pill').forEach(p => {
                p.classList.toggle('active', p.getAttribute('data-group') === 'all');
            });
        }
        if (procSearchInput) procSearchInput.value = '';
        if (procSearchClear) procSearchClear.hidden = true;

        procModal.hidden = false;
        procModal.classList.remove('is-hidden');
        procModal.style.display = 'flex';
        if (procBackdrop) {
            procBackdrop.hidden = false;
            procBackdrop.classList.remove('is-hidden');
            procBackdrop.style.display = 'block';
        }
        document.body.classList.add('sales-detail-open');
        requestAnimationFrame(() => {
            procModal.classList.add('is-open');
        });
        loadProcurementData(currentProcSalesDoc);
    }

    function closeProcurementModal() {
        procModal?.classList.remove('is-open');
        if (procBackdrop) {
            procBackdrop.classList.add('is-hidden');
            procBackdrop.style.display = 'none';
            procBackdrop.hidden = true;
        }
        document.body.classList.remove('sales-detail-open');
        closePRDrawer();
        setTimeout(() => {
            if (procModal && !procModal.classList.contains('is-open')) {
                procModal.hidden = true;
                procModal.classList.add('is-hidden');
                procModal.style.display = 'none';
            }
        }, 320);
    }

    async function loadProcurementData(salesDoc = '') {
        const cleanDoc = String(salesDoc || currentProcSalesDoc || currentDrawerRecord?.sales_order || '').trim();
        if (!cleanDoc) return;
        currentProcSalesDoc = cleanDoc;
        if (procSalesDocLabel) procSalesDocLabel.textContent = cleanDoc;
        if (procReloadBtn) procReloadBtn.classList.add('is-loading');

        if (procTableBody) {
            procTableBody.innerHTML = `
                <tr>
                    <td colspan="9" class="proc-loading-container">
                        <div class="proc-simple-loader">
                            <div class="proc-spinner-ring"></div>
                            <div class="proc-loader-title">Connecting to SAP Procurement</div>
                            <div class="proc-loader-sub">Fetching live component materials, requirements, and orders for <strong>Sales Doc #${escapeHtml(cleanDoc)}</strong>…</div>
                        </div>
                    </td>
                </tr>
            `;
        }

        const endpoint = cfg.procurementUrl || '/sales/procurement';
        try {
            console.log('[Procurement] Live SAP call for Sales Doc:', cleanDoc, `${endpoint}?sales_doc=${encodeURIComponent(cleanDoc)}`);
            const res = await fetch(`${endpoint}?sales_doc=${encodeURIComponent(cleanDoc)}&_=${Date.now()}`);
            if (!res.ok) {
                throw new Error(`HTTP ${res.status}: Failed to load procurement data`);
            }
            const payload = await res.json();
            currentProcRawPayload = payload;
            currentProcData = payload.data || [];

            if (payload.status === 'error' || !currentProcData.length) {
                if (procTableBody) {
                    procTableBody.innerHTML = `
                        <tr>
                            <td colspan="9" class="empty-state" style="padding:3.5rem 1rem;text-align:center;">
                                <span class="material-icons-round" style="font-size:2.4rem;color:${payload.status === 'error' ? '#ef4444' : '#94a3b8'};margin-bottom:0.5rem;display:block;">${payload.status === 'error' ? 'error_outline' : 'inventory_2'}</span>
                                <div style="font-weight:700;font-size:0.95rem;color:#1e293b;margin-bottom:0.25rem;">${payload.status === 'error' ? 'SAP Procurement Notice' : 'No Components Found'}</div>
                                <div style="color:#64748b;font-size:0.82rem;">${escapeHtml(payload.error || 'No procurement records returned from SAP for Sales Doc ' + cleanDoc)}</div>
                            </td>
                        </tr>
                    `;
                }
                if (procStatComponents) procStatComponents.textContent = '0';
                if (procStatReq) procStatReq.textContent = '0';
                if (procStatPR) procStatPR.textContent = '0';
                if (procStatPO) procStatPO.textContent = '0';
                if (procStatStock) procStatStock.textContent = '0';
                if (procFilterCountAll) procFilterCountAll.textContent = '0';
                if (procFilteredCount) procFilteredCount.textContent = '0 items';
                return;
            }

            if (currentProcData.length > 0) {
                const first = currentProcData[0];
                if (procHeaderMaterial) procHeaderMaterial.textContent = first.Material || '—';
                if (procHeaderDesc) procHeaderDesc.textContent = first.MaterialDescription || '—';
            }

            let totalReq = 0;
            let totalPR = 0;
            let totalPO = 0;
            let totalStock = 0;

            currentProcData.forEach((row) => {
                totalReq += parseFloat(String(row.RequirementQty || '0').replace(/\s+/g, '')) || 0;
                totalPR += parseFloat(String(row.TotalPRQty || '0').replace(/\s+/g, '')) || 0;
                totalPO += parseFloat(String(row.TotalPOQty || '0').replace(/\s+/g, '')) || 0;
                totalStock += parseFloat(String(row.StockQty || '0').replace(/\s+/g, '')) || 0;
            });

            if (procStatComponents) procStatComponents.textContent = currentProcData.length;
            if (procStatReq) procStatReq.textContent = fmtNum(totalReq);
            if (procStatPR) procStatPR.textContent = fmtNum(totalPR);
            if (procStatPO) procStatPO.textContent = fmtNum(totalPO);
            if (procStatStock) procStatStock.textContent = fmtNum(totalStock);
            if (procFilterCountAll) procFilterCountAll.textContent = currentProcData.length;

            renderProcurementTable(currentProcData);
        } catch (err) {
            if (procTableBody) {
                procTableBody.innerHTML = `<tr><td colspan="9" class="empty-state" style="color:#ef4444">Error: ${escapeHtml(err.message)}</td></tr>`;
            }
        } finally {
            if (procReloadBtn) {
                setTimeout(() => procReloadBtn.classList.remove('is-loading'), 350);
            }
        }
    }

    function renderProcurementTable(records) {
        if (!procTableBody) return;
        const query = procSearchInput?.value?.trim().toLowerCase() || '';

        const filtered = (records || []).filter((r) => {
            // Group filter
            if (activeProcGroupFilter !== 'all') {
                const grp = String(r.MaterialGroup || '').toLowerCase();
                if (!grp.includes(activeProcGroupFilter)) return false;
            }
            if (!query) return true;
            return (
                String(r.ComponentMaterial || '').toLowerCase().includes(query) ||
                String(r.ComponentDescription || '').toLowerCase().includes(query) ||
                String(r.MaterialGroup || '').toLowerCase().includes(query) ||
                String(r.ShadeText1 || '').toLowerCase().includes(query)
            );
        });

        if (procFilteredCount) {
            procFilteredCount.textContent = `${filtered.length} of ${records.length} items`;
        }

        if (!filtered.length) {
            procTableBody.innerHTML = `<tr><td colspan="9" class="empty-state">No components match "${escapeHtml(query || activeProcGroupFilter)}"</td></tr>`;
            return;
        }

        procTableBody.innerHTML = filtered.map((r, i) => {
            const req = parseFloat(String(r.RequirementQty || '0').replace(/\s+/g, '')) || 0;
            const pr = parseFloat(String(r.TotalPRQty || '0').replace(/\s+/g, '')) || 0;
            const po = parseFloat(String(r.TotalPOQty || '0').replace(/\s+/g, '')) || 0;
            const bal = parseFloat(String(r.BalancePRQty || '0').replace(/\s+/g, '')) || 0;
            const stock = parseFloat(String(r.StockQty || '0').replace(/\s+/g, '')) || 0;
            const balColor = bal < 0 ? '#dc2626' : (bal > 0 ? '#d97706' : '#10b981');

            return `
                <tr class="proc-row" data-idx="${i}" tabindex="0" role="button" title="Click to view ProcurementPRSet details">
                    <td>
                        <span class="proc-comp-badge">${escapeHtml(r.ComponentMaterial)}</span>
                    </td>
                    <td>
                        <div class="proc-desc-cell">
                            <span class="proc-desc-name">${escapeHtml(r.ComponentDescription || '—')}</span>
                            ${r.ShadeText1 ? `<span class="proc-desc-shade"><span class="material-icons-round" style="font-size:12px">palette</span> Shade: ${escapeHtml(r.ShadeText1)}</span>` : ''}
                        </div>
                    </td>
                    <td><span class="proc-tag ${groupTagClass(r.MaterialGroup)}">${escapeHtml(r.MaterialGroup || '—')}</span></td>
                    <td class="col-num">${fmtNum(req)}</td>
                    <td class="col-num" style="font-weight:600;color:#0f172a">${fmtNum(pr)}</td>
                    <td class="col-num" style="font-weight:600;color:#059669">${fmtNum(po)}</td>
                    <td class="col-num" style="font-weight:700;color:${balColor}">${fmtNum(bal)}</td>
                    <td class="col-num" style="font-weight:600;color:#0284c7">${fmtNum(stock)}</td>
                    <td style="text-align:center">
                        <button type="button" class="proc-btn-drill" data-action="pr">
                            <span>PR Set</span>
                            <span class="material-icons-round" style="font-size:13px">chevron_right</span>
                        </button>
                    </td>
                </tr>
            `;
        }).join('');

        procTableBody.querySelectorAll('.proc-row').forEach((row, i) => {
            const item = filtered[i];
            row.addEventListener('click', () => {
                procTableBody.querySelectorAll('.proc-row').forEach(r => r.classList.remove('active'));
                row.classList.add('active');
                openPRDrawer(item);
            });
        });
    }

    async function openPRDrawer(item) {
        if (!prDrawer) return;
        prDrawer.classList.add('open');
        prDrawer.setAttribute('aria-hidden', 'false');

        if (prDrawerTitle) prDrawerTitle.textContent = item.ComponentMaterial;
        if (prDrawerSubtitle) prDrawerSubtitle.textContent = item.ComponentDescription || '';

        const uri = item.ProcurementPRSet?.__deferred?.uri
            || `http://app-prod.evolvclothing.com:8000/sap/opu/odata/sap/ZBUSINESS_API_SRV/ProcurementDashboardSet(SalesDoc='${item.SalesDoc || currentProcSalesDoc}',ComponentMaterial='${encodeURIComponent(item.ComponentMaterial)}')/ProcurementPRSet`;

        if (prDrawerBody) {
            prDrawerBody.innerHTML = `
                <div style="padding:2.5rem 1rem;text-align:center;color:#64748b">
                    <div class="sales-loader-ring" style="margin:0 auto 1rem;width:28px;height:28px;border-width:3px"></div>
                    <div style="font-weight:600;color:#0f172a">Calling SAP ProcurementPRSet…</div>
                    <small style="color:#94a3b8;font-size:0.75rem;margin-top:0.25rem;display:block">Retrieving purchase requisition line items</small>
                </div>
            `;
        }

        const endpoint = cfg.procurementPrUrl || '/sales/procurement-pr';
        try {
            const params = new URLSearchParams({
                uri: uri,
                sales_doc: item.SalesDoc || currentProcSalesDoc,
                component_material: item.ComponentMaterial || '',
                _: String(Date.now()),
            });
            const res = await fetch(`${endpoint}?${params.toString()}`);
            if (!res.ok) {
                throw new Error(`HTTP ${res.status}: Failed to load PR Set`);
            }
            const payload = await res.json();

            if (payload.status === 'error') {
                prDrawerBody.innerHTML = `
                    <div class="pr-line-card" style="text-align:center;color:#ef4444;padding:2rem">
                        <span class="material-icons-round" style="font-size:36px;color:#ef4444;margin-bottom:0.5rem">error_outline</span>
                        <h4 style="margin:0">SAP Request Failed</h4>
                        <p style="margin:0.25rem 0 0.5rem;font-size:0.8rem">${escapeHtml(payload.error || 'Failed to retrieve PR details from SAP')}</p>
                    </div>
                `;
                return;
            }

            const prList = payload.data || [];

            if (!prList.length) {
                prDrawerBody.innerHTML = `
                    <div class="pr-line-card" style="text-align:center;color:#64748b;padding:2rem">
                        <span class="material-icons-round" style="font-size:36px;color:#cbd5e1;margin-bottom:0.5rem">info</span>
                        <h4 style="margin:0;color:#0f172a">No Purchase Requisitions Linked</h4>
                        <p style="margin:0.25rem 0 1rem;font-size:0.8rem">No PR lines found in SAP for this component material.</p>
                        <div style="text-align:left;background:#f8fafc;padding:0.75rem;border-radius:0.5rem;font-size:0.75rem;border:1px solid #e2e8f0">
                            <strong style="color:#0f172a;display:block;margin-bottom:0.35rem">Component Summary:</strong>
                            <div>Req Qty: <b>${escapeHtml(item.RequirementQty || '0')}</b></div>
                            <div>Total PR Qty: <b>${escapeHtml(item.TotalPRQty || '0')}</b></div>
                            <div>Total PO Qty: <b>${escapeHtml(item.TotalPOQty || '0')}</b></div>
                            <div>Balance PR: <b>${escapeHtml(item.BalancePRQty || '0')}</b></div>
                            <div>Stock Qty: <b>${escapeHtml(item.StockQty || '0')}</b></div>
                        </div>
                    </div>
                `;
                return;
            }

            prDrawerBody.innerHTML = `
                <div style="margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center">
                    <span style="font-size:0.82rem;font-weight:700;color:#334155">${prList.length} Purchase Requisition Item(s)</span>
                    <span class="proc-pill proc-pill-live"><span class="pulse-dot"></span> Live SAP</span>
                </div>
                ${prList.map((pr) => {
                const reqQty = parseFloat(String(pr.RequirementQty || '0').replace(/\s+/g, '')) || 0;
                const prQty = parseFloat(String(pr.PRQty || '0').replace(/\s+/g, '')) || 0;
                const poQty = parseFloat(String(pr.POQty || '0').replace(/\s+/g, '')) || 0;
                const balQty = parseFloat(String(pr.BalancePRQty || '0').replace(/\s+/g, '')) || 0;
                const balColor = balQty < 0 ? '#dc2626' : (balQty > 0 ? '#d97706' : '#10b981');
                const formattedDate = fmtSapDate(pr.DeliveryDate);
                const cleanItem = pr.PRItem ? String(parseInt(pr.PRItem, 10)) : '10';

                return `
                    <div class="pr-line-card">
                        <div class="pr-line-card-head">
                            <span class="pr-num">
                                <span class="material-icons-round" style="font-size:16px;color:#4f46e5">receipt</span> 
                                PR ${escapeHtml(pr.PRNumber || '—')} • Item ${escapeHtml(cleanItem)}
                            </span>
                        </div>
                        <div class="pr-grid-details">
                            <div>
                                <span>Requirement Qty</span>
                                <strong style="color:#0f172a">${fmtNum(reqQty)}</strong>
                            </div>
                            <div>
                                <span>PR Quantity</span>
                                <strong style="color:#4f46e5">${fmtNum(prQty)}</strong>
                            </div>
                            <div>
                                <span>PO Quantity</span>
                                <strong style="color:#059669">${fmtNum(poQty)}</strong>
                            </div>
                            <div>
                                <span>Balance PR Qty</span>
                                <strong style="color:${balColor}">${fmtNum(balQty)}</strong>
                            </div>
                            <div>
                                <span>Delivery Date</span>
                                <strong style="color:#0f172a">${escapeHtml(formattedDate)}</strong>
                            </div>
                            <div>
                                <span>Sales Doc</span>
                                <strong>SO #${escapeHtml(pr.SalesDoc ? String(parseInt(pr.SalesDoc, 10)) : (currentProcSalesDoc || '—'))}</strong>
                            </div>
                        </div>
                    </div>
                    `;
            }).join('')}
            `;
        } catch (err) {
            if (prDrawerBody) {
                prDrawerBody.innerHTML = `
                    <div class="pr-line-card" style="border-color:#fca5a5;background:#fef2f2;padding:1.5rem">
                        <h4 style="color:#b91c1c;margin:0 0 0.25rem">Failed to load PR Set</h4>
                        <p style="margin:0;font-size:0.8rem;color:#7f1d1d">${escapeHtml(err.message)}</p>
                    </div>
                `;
            }
        }
    }

    function closePRDrawer() {
        if (!prDrawer) return;
        prDrawer.classList.remove('open');
        prDrawer.setAttribute('aria-hidden', 'true');
        procTableBody?.querySelectorAll('.proc-row').forEach(r => r.classList.remove('active'));
    }

    // Bind Procurement UI events
    procCard?.addEventListener('click', () => {
        const defaultDoc = currentDrawerRecord?.sales_order || (lastPayload?.records && lastPayload.records[0]?.sales_order) || '';
        if (defaultDoc) openProcurementModal(defaultDoc);
    });
    procCloseBtn?.addEventListener('click', closeProcurementModal);
    procBackdrop?.addEventListener('click', closeProcurementModal);
    procReloadBtn?.addEventListener('click', () => loadProcurementData(currentProcSalesDoc));
    prDrawerClose?.addEventListener('click', closePRDrawer);

    // Group Filter Pills
    procGroupFilters?.querySelectorAll('.proc-filter-pill').forEach((pill) => {
        pill.addEventListener('click', () => {
            procGroupFilters.querySelectorAll('.proc-filter-pill').forEach(p => p.classList.remove('active'));
            pill.classList.add('active');
            activeProcGroupFilter = pill.getAttribute('data-group') || 'all';
            renderProcurementTable(currentProcData);
        });
    });

    let procSearchTimer;
    procSearchInput?.addEventListener('input', () => {
        if (procSearchClear) procSearchClear.hidden = !procSearchInput.value;
        clearTimeout(procSearchTimer);
        procSearchTimer = setTimeout(() => renderProcurementTable(currentProcData), 200);
    });

    procSearchClear?.addEventListener('click', () => {
        if (procSearchInput) procSearchInput.value = '';
        if (procSearchClear) procSearchClear.hidden = true;
        renderProcurementTable(currentProcData);
    });

    // Header Right-Side Sales Order Search logic
    function handleHeaderSoSearch() {
        const val = headerSoSearchInput?.value?.trim();
        if (!val) {
            clearDashboardSearch(true);
            headerSoSearchInput?.focus();
            return;
        }
        if (headerSoAutocomplete) headerSoAutocomplete.hidden = true;
        setDashboardSearchTerm(val);
        fetchPage(1);
    }

    btnHeaderSoSearch?.addEventListener('click', handleHeaderSoSearch);
    headerSoSearchInput?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            handleHeaderSoSearch();
        } else if (e.key === 'Escape') {
            if (headerSoAutocomplete) headerSoAutocomplete.hidden = true;
        }
    });

    headerSoSearchInput?.addEventListener('input', () => {
        setDashboardSearchTerm(headerSoSearchInput.value);
        const q = headerSoSearchInput.value.trim().toLowerCase();
        if (!q) {
            if (headerSoAutocomplete) headerSoAutocomplete.hidden = true;
            clearDashboardSearch(true);
            return;
        }

        const seen = new Set();
        const matches = [];
        if (lastPayload?.records) {
            for (const r of lastPayload.records) {
                const so = String(r.sales_order || '').trim();
                if (!so || seen.has(so)) continue;
                if (so.toLowerCase().includes(q) || String(r.material || '').toLowerCase().includes(q)) {
                    seen.add(so);
                    matches.push(r);
                    if (matches.length >= 6) break;
                }
            }
        }

        if (!headerSoAutocomplete) return;

        headerSoAutocomplete.hidden = false;
        let html = matches.map(m => `
            <div class="so-autocomplete-item" data-so="${escapeHtml(m.sales_order)}">
                <div>
                    <span class="so-match-tag">SO #${escapeHtml(m.sales_order)}</span>
                    <span class="so-match-meta">${escapeHtml(m.material || '')} • Plant ${escapeHtml(m.plant || '—')}</span>
                </div>
                <button type="button" class="so-match-action" data-proc-so="${escapeHtml(m.sales_order)}" title="View Procurement details">
                    <span>Procurement</span>
                    <span class="material-icons-round" style="font-size:12px">arrow_forward</span>
                </button>
            </div>
        `).join('');

        // Direct search option for any typed query / SO number
        html += `
            <div class="so-autocomplete-item so-autocomplete-direct" data-so="${escapeHtml(q)}">
                <div>
                    <span class="so-match-tag" style="background:#e0f2fe;color:#0369a1;border-color:#bae6fd">Search Order #${escapeHtml(q)}</span>
                    <span class="so-match-meta">Filter entire dashboard to this Sales Order</span>
                </div>
                <span class="material-icons-round" style="color:#0284c7">search</span>
            </div>
        `;

        headerSoAutocomplete.innerHTML = html;

        headerSoAutocomplete.querySelectorAll('.so-autocomplete-item').forEach(item => {
            item.addEventListener('click', (e) => {
                const procBtn = e.target.closest('.so-match-action');
                const so = item.getAttribute('data-so');
                if (procBtn) {
                    const procSo = procBtn.getAttribute('data-proc-so') || so;
                    headerSoAutocomplete.hidden = true;
                    openProcurementModal(procSo);
                    return;
                }
                setDashboardSearchTerm(so);
                headerSoAutocomplete.hidden = true;
                fetchPage(1);
            });
        });
    });

    headerSoSearchClear?.addEventListener('click', () => {
        clearDashboardSearch(true);
        headerSoSearchInput?.focus();
    });

    activeFilterClear?.addEventListener('click', () => {
        clearDashboardSearch(true);
    });

    // Clicking Card 1 (Total Sales Orders) focuses the table search
    ordersKpiCard?.addEventListener('click', () => {
        if (searchInput) {
            searchInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
            setTimeout(() => {
                searchInput.focus();
                searchInput.select();
            }, 300);
        }
    });

    document.addEventListener('click', (e) => {
        if (!e.target.closest('#headerSoSearch') && headerSoAutocomplete) {
            headerSoAutocomplete.hidden = true;
        }
    });

    // Close modal / subdrawer on Escape
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            if (subDrawer && subDrawer.classList.contains('open')) {
                closeSubDrawer();
                return;
            }
            if (drawer && drawer.classList.contains('open')) {
                closeDetail();
                return;
            }
            if (procModal && !procModal.hidden && !procModal.classList.contains('is-hidden')) {
                if (prDrawer && prDrawer.classList.contains('open')) {
                    closePRDrawer();
                } else {
                    closeProcurementModal();
                }
                return;
            }
        }
    });


    window.addEventListener('evolv:periodchange', (e) => {
        const { from, to } = e.detail || {};
        if (!from || !to) return;
        if (from === currentFrom && to === currentTo && lastPayload) return;
        currentFrom = from;
        currentTo = to;
        if (periodBadge) {
            const label = from === to ? from : `${from} → ${to}`;
            periodBadge.innerHTML = `<span class="material-icons-round">date_range</span> ${label}`;
        }
        fetchPage(1);
    });

    initCharts();
    initTableSortAndFilters();

    showSalesLoader(true);

    const pad = (n) => String(n).padStart(2, '0');
    const fmt = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    const now = new Date();
    const day = now.getDay();
    const diff = day === 0 ? -6 : 1 - day;
    const startOfWeek = new Date(now);
    startOfWeek.setDate(now.getDate() + diff);
    const endOfWeek = new Date(startOfWeek);
    endOfWeek.setDate(startOfWeek.getDate() + 6);

    const cfgFrom = (window.SALES_PAGE && window.SALES_PAGE.defaultFrom) || fmt(startOfWeek);
    const cfgTo = (window.SALES_PAGE && window.SALES_PAGE.defaultTo) || fmt(endOfWeek);
    currentFrom = cfgFrom;
    currentTo = cfgTo;
    fetchPage(1);
})();

(function () {
    // PERIOD FILTER
    const periodFilter = document.getElementById('periodFilter');
    const periodTrigger = document.getElementById('periodTrigger');
    const periodMenu = document.getElementById('periodMenu');
    const periodLabel = document.getElementById('periodTriggerLabel');
    const customModal = document.getElementById('customDateModal');
    const headerFrom = document.getElementById('headerDateFrom');
    const headerTo = document.getElementById('headerDateTo');

    // PERIOD LABELS
    const PERIOD_LABELS = {
        today: 'Today',
        yesterday: 'Yesterday',
        this_week: 'This Week',
        prev_week: 'Previous Week',
        this_month: 'This Month',
        prev_month: 'Previous Month',
        custom: 'Custom Date'
    };

    // PAD FUNCTION
    function pad(n) { return String(n).padStart(2, '0'); }
    function fmt(d) {
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    }

    // START OF WEEK FUNCTION
    function startOfWeek(d) {
        const x = new Date(d);
        const day = x.getDay();
        const diff = day === 0 ? -6 : 1 - day;
        x.setDate(x.getDate() + diff);
        x.setHours(0, 0, 0, 0);
        return x;
    }

    // END OF WEEK FUNCTION
    function endOfWeek(d) {
        const s = startOfWeek(d);
        const e = new Date(s);
        e.setDate(s.getDate() + 6);
        return e;
    }

    // GET PERIOD RANGE FUNCTION
    function getPeriodRange(period) {
        const now = new Date();
        now.setHours(0, 0, 0, 0);
        let from, to;

        switch (period) {
            case 'today':
                from = to = new Date(now);
                break;
            case 'yesterday':
                from = to = new Date(now);
                from.setDate(from.getDate() - 1);
                to = new Date(from);
                break;
            case 'this_week':
                from = startOfWeek(now);
                to = endOfWeek(now);
                break;
            case 'prev_week': {
                const thisStart = startOfWeek(now);
                to = new Date(thisStart);
                to.setDate(to.getDate() - 1);
                from = startOfWeek(to);
                break;
            }
            case 'this_month':
                from = new Date(now.getFullYear(), now.getMonth(), 1);
                to = new Date(now.getFullYear(), now.getMonth() + 1, 0);
                break;
            case 'prev_month':
                from = new Date(now.getFullYear(), now.getMonth() - 1, 1);
                to = new Date(now.getFullYear(), now.getMonth(), 0);
                break;
            default:
                from = new Date(now.getFullYear(), 0, 1);
                to = new Date(now.getFullYear(), 11, 31);
        }
        return { from: fmt(from), to: fmt(to), period };
    }

    // EMIT PERIOD FUNCTION
    function emitPeriod(detail) {
        window.dispatchEvent(new CustomEvent('evolv:periodchange', { detail }));
        const dashFrom = document.getElementById('dateFrom');
        const dashTo = document.getElementById('dateTo');
        if (dashFrom) dashFrom.value = detail.from;
        if (dashTo) dashTo.value = detail.to;
    }

    // SELECT PERIOD FUNCTION
    function selectPeriod(period, customRange) {
        const range = customRange || getPeriodRange(period);
        if (periodLabel) periodLabel.textContent = PERIOD_LABELS[period] || 'Period';
        document.querySelectorAll('.period-option').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.period === period);
        });
        closePeriodMenu();
        emitPeriod({ ...range, period });
        document.querySelectorAll('.header-line-beam').forEach(b => {
            b.style.animation = 'none';
            void b.offsetWidth;
            b.style.animation = '';
        });
    }

    // OPEN PERIOD MENU FUNCTION
    function openPeriodMenu() {
        if (!periodMenu || !periodFilter) return;
        periodMenu.hidden = false;
        periodFilter.classList.add('open');
        periodTrigger?.setAttribute('aria-expanded', 'true');
    }

    // CLOSE PERIOD MENU FUNCTION
    function closePeriodMenu() {
        if (!periodMenu || !periodFilter) return;
        periodMenu.hidden = true;
        periodFilter.classList.remove('open');
        periodTrigger?.setAttribute('aria-expanded', 'false');
    }

    // PERIOD TRIGGER CLICK EVENT
    if (periodTrigger) {
        periodTrigger.addEventListener('click', (e) => {
            e.stopPropagation();
            if (periodMenu.hidden) openPeriodMenu();
            else closePeriodMenu();
        });
    }

    // PERIOD OPTIONS CLICK EVENT
    document.querySelectorAll('.period-option').forEach(opt => {
        opt.addEventListener('click', () => {
            const period = opt.dataset.period;
            if (period === 'custom') {
                closePeriodMenu();
                if (customModal) {
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    const sevenDaysAgo = new Date(today);
                    sevenDaysAgo.setDate(sevenDaysAgo.getDate() - 7);
                    if (headerFrom) {
                        headerFrom.value = (currentPeriod === 'custom' && currentFrom) ? currentFrom : fmt(sevenDaysAgo);
                        headerFrom.removeAttribute('min');
                        headerFrom.max = fmt(today);
                    }
                    if (headerTo) {
                        headerTo.value = (currentPeriod === 'custom' && currentTo) ? currentTo : fmt(today);
                        headerTo.removeAttribute('min');
                        headerTo.max = fmt(today);
                    }
                    const err = document.getElementById('customDateError');
                    if (err) err.hidden = true;
                    customModal.hidden = false;
                }
                return;
            }
            selectPeriod(period);
        });
    });


    document.addEventListener('click', (e) => {
        if (periodFilter && !periodFilter.contains(e.target)) closePeriodMenu();
    });

    // CUSTOM DATE CANCEL CLICK EVENT
    document.getElementById('customDateCancel')?.addEventListener('click', () => {
        if (customModal) customModal.hidden = true;
    });

    // CUSTOM DATE APPLY CLICK EVENT
    document.getElementById('customDateApply')?.addEventListener('click', () => {
        const err = document.getElementById('customDateError');
        function showErr(msg) {
            if (!err) {
                alert(msg);
                return;
            }
            err.textContent = msg;
            err.hidden = false;
        }
        if (err) err.hidden = true;
        if (!headerFrom?.value || !headerTo?.value) {
            showErr('Choose both From and To dates.');
            return;
        }
        if (headerFrom.value > headerTo.value) {
            showErr('Start date must be before end date.');
            return;
        }
        const from = new Date(headerFrom.value + 'T00:00:00');
        const to = new Date(headerTo.value + 'T00:00:00');
        const maxTo = new Date(from);
        maxTo.setMonth(maxTo.getMonth() + 6);
        if (to > maxTo) {
            showErr('Custom date range cannot exceed 6 months.');
            return;
        }
        if (customModal) customModal.hidden = true;
        selectPeriod('custom', { from: headerFrom.value, to: headerTo.value, period: 'custom' });
    });

    // CUSTOM MODAL CLICK EVENT
    customModal?.addEventListener('click', (e) => {
        if (e.target === customModal) customModal.hidden = true;
    });

    // PERIOD TRIGGER INITIALIZE
    if (periodTrigger) {
        const pageCfg = window.SALES_PAGE || {};
        const defPeriod = pageCfg.defaultPeriod || 'this_week';
        const initial = (pageCfg.defaultFrom && pageCfg.defaultTo)
            ? { from: pageCfg.defaultFrom, to: pageCfg.defaultTo, period: defPeriod }
            : getPeriodRange(defPeriod);

        if (periodLabel) {
            periodLabel.textContent = PERIOD_LABELS[defPeriod] || 'This Week';
        }
        document.querySelectorAll('.period-option').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.period === defPeriod);
        });
        emitPeriod({ ...initial, period: defPeriod });
    }
})();

(function () {
    const kpiModal = document.getElementById('kpiModal');
    const kpiModalCard = kpiModal?.querySelector('.kpi-modal-card');
    const kpiModalTitle = document.getElementById('kpiModalTitle');
    const kpiModalValue = document.getElementById('kpiModalValue');
    const kpiModalNote = document.getElementById('kpiModalNote');
    const kpiModalLabel = document.getElementById('kpiModalLabel');
    const kpiModalBody = document.getElementById('kpiModalBody');
    const kpiModalIconName = document.getElementById('kpiModalIconName');
    const kpiModalIcon = document.getElementById('kpiModalIcon');

    function cardTone(card) {
        const cls = [...(card?.classList || [])].find((c) => c.startsWith('tone-'));
        return cls ? cls.replace('tone-', '') : 'sky';
    }

    function detailsFromCard(card) {
        const related = [...document.querySelectorAll('.kpi-card.proj-card')].map((el) => ([
            el.querySelector('.label')?.textContent?.trim() || 'Metric',
            el.querySelector('.value')?.textContent?.trim() || '—',
        ]));
        return {
            title: card.querySelector('.label')?.textContent?.trim() || 'KPI details',
            value: card.querySelector('.value')?.textContent?.trim() || '—',
            note: card.querySelector('.change')?.textContent?.trim() || '',
            icon: card.querySelector('.kpi-icon .material-icons-round')?.textContent?.trim() || 'insights',
            tone: cardTone(card),
            rows: related,
        };
    }

    function openKpiModal(opts) {
        if (!kpiModal) return;
        const tone = opts.tone || 'sky';
        kpiModal.dataset.tone = tone;
        if (kpiModalCard) {
            kpiModalCard.style.setProperty('--tone', getComputedStyle(document.documentElement).getPropertyValue('--primary') || '#1b5e4b');
        }
        const toneMap = {
            sky: '#0369a1',
            mint: '#047857',
            amber: '#b45309',
            violet: '#6d28d9',
            rose: '#be123c',
            teal: '#0f766e',
        };
        const color = toneMap[tone] || '#1b5e4b';
        if (kpiModalCard) kpiModalCard.style.setProperty('--tone', color);
        if (kpiModalIcon) kpiModalIcon.style.background = color;
        if (kpiModalTitle) kpiModalTitle.textContent = opts.title || 'KPI details';
        if (kpiModalLabel) kpiModalLabel.textContent = opts.title || 'Value';
        if (kpiModalValue) kpiModalValue.textContent = opts.value || '—';
        if (kpiModalNote) kpiModalNote.textContent = opts.note || '';
        if (kpiModalIconName) kpiModalIconName.textContent = opts.icon || 'insights';
        if (kpiModalBody) {
            const rows = Array.isArray(opts.rows) ? opts.rows : [];
            kpiModalBody.innerHTML = rows.map((row, i) => `
                <div class="kpi-modal-stat" style="animation-delay:${0.05 * i}s">
                    <span>${String(row[0] ?? '')}</span>
                    <strong>${String(row[1] ?? '—')}</strong>
                </div>
            `).join('');
        }
        kpiModal.hidden = false;
        document.body.classList.add('kpi-modal-open');
    }

    function closeKpiModal() {
        if (!kpiModal) return;
        kpiModal.hidden = true;
        document.body.classList.remove('kpi-modal-open');
    }

    window.kapisKpiModal = { open: openKpiModal, close: closeKpiModal };

    document.querySelectorAll('.kpi-card.proj-card').forEach((card) => {
        function show() {
            const key = card.dataset.kpi || '';
            const extra = typeof window.kapisKpiDetails === 'function' ? window.kapisKpiDetails(key, card) : null;
            openKpiModal(extra || detailsFromCard(card));
        }
        card.addEventListener('click', show);
        card.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                show();
            }
        });
    });

    document.getElementById('kpiModalClose')?.addEventListener('click', closeKpiModal);
    kpiModal?.addEventListener('click', (e) => {
        if (e.target === kpiModal) closeKpiModal();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeKpiModal();
    });
})();
