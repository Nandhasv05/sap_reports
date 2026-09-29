/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 28/09/2026
 * DESCRIPTION : Export the report table to a real .xlsx file in the browser (no library).
 *               Exports what the user sees: visible columns in their current order, filtered rows and the totals row.
 *               Any button with [data-excel-export] exports #rptDataTable; data-excel-title / data-excel-file set the sheet title and file name.
 */
(function () {
    const enc = new TextEncoder();

    /* ---------- minimal ZIP (stored, no compression) ---------- */
    const CRC_TABLE = (() => {
        const t = new Uint32Array(256);
        for (let n = 0; n < 256; n++) {
            let c = n;
            for (let k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1;
            t[n] = c >>> 0;
        }
        return t;
    })();

    function crc32(bytes) {
        let c = 0xFFFFFFFF;
        for (let i = 0; i < bytes.length; i++) c = CRC_TABLE[(c ^ bytes[i]) & 0xFF] ^ (c >>> 8);
        return (c ^ 0xFFFFFFFF) >>> 0;
    }

    function zip(files) {
        const parts = [];
        const central = [];
        let offset = 0;
        const now = new Date();
        const dosTime = (now.getHours() << 11) | (now.getMinutes() << 5) | Math.floor(now.getSeconds() / 2);
        const dosDate = ((now.getFullYear() - 1980) << 9) | ((now.getMonth() + 1) << 5) | now.getDate();

        files.forEach(({ name, data }) => {
            const nameBytes = enc.encode(name);
            const body = typeof data === 'string' ? enc.encode(data) : data;
            const crc = crc32(body);

            const local = new DataView(new ArrayBuffer(30));
            local.setUint32(0, 0x04034b50, true);
            local.setUint16(4, 20, true);
            local.setUint16(6, 0x0800, true);
            local.setUint16(8, 0, true);
            local.setUint16(10, dosTime, true);
            local.setUint16(12, dosDate, true);
            local.setUint32(14, crc, true);
            local.setUint32(18, body.length, true);
            local.setUint32(22, body.length, true);
            local.setUint16(26, nameBytes.length, true);
            local.setUint16(28, 0, true);
            parts.push(new Uint8Array(local.buffer), nameBytes, body);

            const cd = new DataView(new ArrayBuffer(46));
            cd.setUint32(0, 0x02014b50, true);
            cd.setUint16(4, 20, true);
            cd.setUint16(6, 20, true);
            cd.setUint16(8, 0x0800, true);
            cd.setUint16(10, 0, true);
            cd.setUint16(12, dosTime, true);
            cd.setUint16(14, dosDate, true);
            cd.setUint32(16, crc, true);
            cd.setUint32(20, body.length, true);
            cd.setUint32(24, body.length, true);
            cd.setUint16(28, nameBytes.length, true);
            cd.setUint32(42, offset, true);
            central.push(new Uint8Array(cd.buffer), nameBytes);

            offset += 30 + nameBytes.length + body.length;
        });

        const cdSize = central.reduce((s, p) => s + p.length, 0);
        const end = new DataView(new ArrayBuffer(22));
        end.setUint32(0, 0x06054b50, true);
        end.setUint16(8, files.length, true);
        end.setUint16(10, files.length, true);
        end.setUint32(12, cdSize, true);
        end.setUint32(16, offset, true);
        return new Blob([...parts, ...central, new Uint8Array(end.buffer)], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
    }

    /* ---------- XLSX parts ---------- */
    function xml(value) {
        return String(value ?? '')
            .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function colName(i) {
        let s = '';
        for (let n = i + 1; n > 0; n = Math.floor((n - 1) / 26)) s = String.fromCharCode(65 + ((n - 1) % 26)) + s;
        return s;
    }

    // Style ids (cellXfs order in styles.xml)
    const S = { text: 0, title: 1, header: 2, int: 3, dec: 4, totalText: 5, totalInt: 6, totalDec: 7, note: 8 };

    const STYLES = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.000"/></numFmts>
<fonts count="4">
<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>
<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>
<font><b/><sz val="14"/><color rgb="FF1E293B"/><name val="Calibri"/><family val="2"/></font>
<font><i/><sz val="10"/><color rgb="FF64748B"/><name val="Calibri"/><family val="2"/></font>
</fonts>
<fills count="4">
<fill><patternFill patternType="none"/></fill>
<fill><patternFill patternType="gray125"/></fill>
<fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/><bgColor indexed="64"/></patternFill></fill>
<fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/><bgColor indexed="64"/></patternFill></fill>
</fills>
<borders count="3">
<border><left/><right/><top/><bottom/><diagonal/></border>
<border><left style="thin"><color rgb="FFCBD5E1"/></left><right style="thin"><color rgb="FFCBD5E1"/></right><top style="thin"><color rgb="FFCBD5E1"/></top><bottom style="thin"><color rgb="FFCBD5E1"/></bottom><diagonal/></border>
<border><left/><right/><top style="medium"><color rgb="FF64748B"/></top><bottom style="thin"><color rgb="FFCBD5E1"/></bottom><diagonal/></border>
</borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="9">
<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>
<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>
<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>
<xf numFmtId="3" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>
<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>
<xf numFmtId="0" fontId="1" fillId="3" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>
<xf numFmtId="3" fontId="1" fillId="3" borderId="2" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>
<xf numFmtId="164" fontId="1" fillId="3" borderId="2" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>
<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>
</cellXfs>
<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>`;

    function cellXml(ref, value, style) {
        if (typeof value === 'number' && isFinite(value)) return `<c r="${ref}" s="${style}"><v>${value}</v></c>`;
        if (value === null || value === undefined || value === '') return `<c r="${ref}" s="${style}"/>`;
        return `<c r="${ref}" s="${style}" t="inlineStr"><is><t xml:space="preserve">${xml(value)}</t></is></c>`;
    }

    /*
     * sheet: { title, note, headers: [{label, numeric}], rows: [[...]], totals: [...] | null, sheetName }
     */
    function buildXlsx(sheet) {
        const cols = sheet.headers.length;
        const headerRow = 4;
        const firstData = headerRow + 1;
        const lastCol = colName(Math.max(0, cols - 1));
        const isDecimal = sheet.headers.map((h, c) => h.numeric && sheet.rows.some(r => typeof r[c] === 'number' && Math.abs(r[c] - Math.round(r[c])) > 0.0005));
        const numStyle = (c, total) => (isDecimal[c] ? (total ? S.totalDec : S.dec) : (total ? S.totalInt : S.int));

        const lines = [];
        lines.push(`<row r="1" ht="22" customHeight="1">${cellXml('A1', sheet.title, S.title)}</row>`);
        if (sheet.note) lines.push(`<row r="2">${cellXml('A2', sheet.note, S.note)}</row>`);
        lines.push(`<row r="${headerRow}" ht="30" customHeight="1">${sheet.headers.map((h, c) => cellXml(colName(c) + headerRow, h.label, S.header)).join('')}</row>`);
        sheet.rows.forEach((row, i) => {
            const r = firstData + i;
            lines.push(`<row r="${r}">${row.map((v, c) => cellXml(colName(c) + r, v, typeof v === 'number' ? numStyle(c, false) : S.text)).join('')}</row>`);
        });
        const lastData = firstData + sheet.rows.length - 1;
        if (sheet.totals) {
            const r = lastData + 1;
            lines.push(`<row r="${r}">${sheet.totals.map((v, c) => cellXml(colName(c) + r, v, typeof v === 'number' ? numStyle(c, true) : S.totalText)).join('')}</row>`);
        }

        const widths = sheet.headers.map((h, c) => {
            const longest = Math.max(String(h.label).length * 0.9, ...sheet.rows.slice(0, 500).map(r => String(r[c] ?? '').length));
            return Math.min(60, Math.max(8, Math.ceil(longest * 1.1) + 2));
        });
        const colsXml = `<cols>${widths.map((w, c) => `<col min="${c + 1}" max="${c + 1}" width="${w}" customWidth="1"/>`).join('')}</cols>`;
        const filterRef = `A${headerRow}:${lastCol}${Math.max(headerRow, lastData)}`;

        const sheetXml = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheetViews><sheetView workbookViewId="0"><pane ySplit="${headerRow}" topLeftCell="A${firstData}" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>
<sheetFormatPr defaultRowHeight="15"/>
${colsXml}
<sheetData>${lines.join('')}</sheetData>
<autoFilter ref="${filterRef}"/>
<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>
<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>
</worksheet>`;

        const sheetName = xml(String(sheet.sheetName || 'Report').replace(/[\\/?*[\]:]/g, ' ').slice(0, 31));
        const workbook = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets><sheet name="${sheetName}" sheetId="1" r:id="rId1"/></sheets>
<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">'${sheetName}'!$A$${headerRow}:$${lastCol}$${Math.max(headerRow, lastData)}</definedName></definedNames>
</workbook>`;

        return zip([
            { name: '[Content_Types].xml', data: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>` },
            { name: '_rels/.rels', data: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>` },
            { name: 'xl/workbook.xml', data: workbook },
            { name: 'xl/_rels/workbook.xml.rels', data: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>` },
            { name: 'xl/worksheets/sheet1.xml', data: sheetXml },
            { name: 'xl/styles.xml', data: STYLES },
        ]);
    }

    /* ---------- read the on-screen table ---------- */
    function isShown(el) {
        return el && getComputedStyle(el).display !== 'none';
    }

    function cellText(cell) {
        if (!cell) return '';
        const chips = cell.querySelectorAll('.so-chip');
        if (chips.length) return Array.from(chips).map(c => c.textContent.trim()).filter(Boolean).join(', ');
        const val = cell.querySelector('.qty-val, .bom-val, .foot-val');
        return (val ? val.textContent : cell.textContent).replace(/\s+/g, ' ').trim();
    }

    function toNumber(text) {
        const t = String(text).replace(/,/g, '').trim();
        if (t === '' || t === '—' || t === '-') return 0;
        return /^-?\d+(\.\d+)?$/.test(t) ? parseFloat(t) : null;
    }

    function readTable(table) {
        const headerCells = Array.from(table.querySelectorAll('thead tr.rpt-header-row > th'));
        const visible = headerCells.map((th, i) => ({ th, i })).filter(({ th }) => isShown(th));
        const headers = visible.map(({ th }) => ({ label: th.textContent.replace(/\s+/g, ' ').trim(), numeric: th.classList.contains('num') }));

        const convert = (text, numeric) => {
            if (!numeric) return text === '—' || text === '-' ? '' : text;
            const n = toNumber(text);
            return n === null ? text : n;
        };

        const rows = Array.from(table.querySelectorAll('tbody tr[data-orig-sno]'))
            .filter(tr => tr.style.display !== 'none')
            .map(tr => visible.map(({ i }, c) => convert(cellText(tr.children[i]), headers[c].numeric)));

        let totals = null;
        const foot = table.querySelector('tfoot tr');
        if (foot && foot.children.length === headerCells.length) {
            totals = visible.map(({ i }, c) => {
                const text = cellText(foot.children[i]);
                if (text === '') return '';
                return headers[c].numeric && toNumber(text) !== null ? toNumber(text) : text;
            });
        }
        return { headers, rows, totals };
    }

    function stamp(d) {
        const p = (n) => String(n).padStart(2, '0');
        return { file: `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}-${p(d.getHours())}${p(d.getMinutes())}`, text: `${p(d.getDate())}-${p(d.getMonth() + 1)}-${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}` };
    }

    window.rptExportTableToExcel = function (table, options) {
        const opts = options || {};
        const data = readTable(table);
        const now = stamp(new Date());
        const count = (document.getElementById('rptTableCount')?.textContent || '').trim();
        const blob = buildXlsx({
            title: opts.title || document.title,
            note: `Exported ${now.text}${count ? ' · ' + count : ''}`,
            sheetName: opts.sheetName || 'Report',
            ...data,
        });
        const safe = String(opts.filename || 'sap-report').replace(/[^\w.-]+/g, '-');
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `${safe}-${now.file}.xlsx`;
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
        return data.rows.length;
    };

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-excel-export]');
        if (!btn) return;
        const table = document.getElementById(btn.getAttribute('data-excel-export') || 'rptDataTable');
        if (!table) return;
        btn.classList.add('is-busy');
        setTimeout(() => {
            try {
                window.rptExportTableToExcel(table, {
                    title: btn.getAttribute('data-excel-title') || '',
                    filename: btn.getAttribute('data-excel-file') || '',
                    sheetName: btn.getAttribute('data-excel-sheet') || '',
                });
            } finally {
                btn.classList.remove('is-busy');
            }
        }, 30);
    });
})();
