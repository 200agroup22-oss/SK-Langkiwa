<?php
// Adds "Print" and "Excel" buttons above every admin list table (applicants, beneficiaries, scholars, ...).
// Included from adminsidebar.php, so no page needs its own copy. The export covers every page of the table
// with the filters / search / sort currently applied, leaves out the Action column, and skips tables inside modals.
if (!defined('SK_TABLE_EXPORT_LOADED')) {
    define('SK_TABLE_EXPORT_LOADED', true);
?>
    <?php
    $__tePrinter = trim(($adminMe['first_name'] ?? '') . ' ' . ($adminMe['last_name'] ?? ''));
    $__tePosition = '';
    if (!empty($adminMe['user_id'])) {
        $__st = $conn->prepare("SELECT position_title FROM users WHERE user_id = ?");
        $__st->bind_param('i', $adminMe['user_id']);
        $__st->execute();
        $__tePosition = (string)($__st->get_result()->fetch_assoc()['position_title'] ?? '');
        $__st->close();
    }
    $__teInfo = [
        'site' => siteName(),
        'logo' => siteLogoUrl(),
        'name' => $__tePrinter !== '' ? $__tePrinter : 'Administrator',
        'position' => $__tePosition !== '' ? $__tePosition : 'System Administrator',
    ];
    ?>
    <style>
        .tbl-export-bar {
            display: flex;
            justify-content: flex-end;
            gap: 6px;
            margin-bottom: 6px;
        }
    </style>
    <script>
        (function() {
            var INFO = <?php echo json_encode($__teInfo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
            // Pages whose tables are settings / charts rather than lists.
            if (/AdminConfiguration|AdminProgramManagement/i.test(location.pathname)) return;
            var ONLY_LOGS = /AdminReports/i.test(location.pathname);

            function clean(t) {
                return (t || '').replace(/\s+/g, ' ').trim();
            }

            function esc(t) {
                return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }

            function listTables(doc) {
                return Array.prototype.filter.call(doc.querySelectorAll('.table-card table'), function(t) {
                    if (t.closest('.modal')) return false;
                    return ONLY_LOGS ? !!t.closest('#activityLogsTab, #auditLogsTab') : true;
                });
            }

            // Reads one table into {head: [...], rows: [[...]]}, leaving out the Action column and "no records" rows.
            function readTable(table) {
                var headCells = Array.prototype.slice.call(table.querySelectorAll('thead th'));
                var skip = [];
                var head = [];
                headCells.forEach(function(th, i) {
                    var txt = clean(th.textContent);
                    if (/^actions?$/i.test(txt) || txt === '') {
                        skip.push(i);
                    } else {
                        head.push(txt.toUpperCase());
                    }
                });
                var rows = [];
                table.querySelectorAll('tbody tr').forEach(function(tr) {
                    var tds = tr.querySelectorAll('td');
                    if (tds.length === 1 && tds[0].colSpan > 1) return;
                    if (tr.style.display === 'none' || tr.hidden) return;
                    var row = [];
                    tds.forEach(function(td, i) {
                        if (skip.indexOf(i) !== -1) return;
                        var sel = td.querySelector('select');
                        row.push(sel && sel.selectedOptions[0] ? clean(sel.selectedOptions[0].textContent) : clean(td.textContent));
                    });
                    if (row.length) rows.push(row);
                });
                return {
                    head: head,
                    rows: rows
                };
            }

            function pageCount(card) {
                var max = 1;
                var scope = card.parentNode;
                scope.querySelectorAll('.pagination a.page-link').forEach(function(a) {
                    var n = parseInt(clean(a.textContent), 10);
                    if (!isNaN(n) && n > max) max = n;
                });
                return max;
            }

            // Gathers the table across all pages (page 1..N of the current filters). The page links themselves tell us the
            // name of the page parameter (page, logpage, ...), so every list works the same way.
            async function collect(index, card) {
                var data = readTable(listTables(document)[index]);
                var links = Array.prototype.filter.call(card.parentNode.querySelectorAll('.pagination a.page-link'), function(a) {
                    return /^\d+$/.test(clean(a.textContent)) && a.getAttribute('href') && a.getAttribute('href') !== '#';
                });
                var pages = pageCount(card);
                if (pages <= 1 || !links.length) return data;
                var first = links[0];
                var key = 'page';
                new URL(first.href, location.href).searchParams.forEach(function(v, k) {
                    if (v === clean(first.textContent)) key = k;
                });
                var activeItem = card.parentNode.querySelector('.pagination .page-item.active');
                var current = activeItem ? parseInt(clean(activeItem.textContent), 10) : 1;
                var rows = [];
                for (var p = 1; p <= pages; p++) {
                    if (p === current) {
                        rows = rows.concat(data.rows);
                        continue;
                    }
                    var u = new URL(first.href, location.href);
                    u.searchParams.set(key, p);
                    var res = await fetch(u.pathname + u.search, {
                        credentials: 'same-origin'
                    });
                    var doc = new DOMParser().parseFromString(await res.text(), 'text/html');
                    var t = listTables(doc)[index];
                    if (t) rows = rows.concat(readTable(t).rows);
                }
                return {
                    head: data.head,
                    rows: rows
                };
            }
            // Heading of the page (first heading outside modals / menus).
            function pageHeading() {
                var hs = document.querySelectorAll('h1, h2, h3, h4');
                for (var i = 0; i < hs.length; i++) {
                    if (!hs[i].closest('.modal, nav, .sidebar, #sidebar, header, .topbar, .table-card')) {
                        var t = clean(hs[i].textContent);
                        if (t) return t;
                    }
                }
                return clean(document.title);
            }

            function titleText(card) {
                // A heading right above this table (e.g. a dashboard section) wins over the page heading.
                var own = card.parentNode.querySelector(':scope > h5, :scope > h6, :scope > .section-title');
                var base = own && own.compareDocumentPosition(card) & Node.DOCUMENT_POSITION_FOLLOWING ? clean(own.textContent) : pageHeading();
                var pane = card.closest('.tab-pane-custom');
                var lab = pane && pane.querySelector('.section-label');
                if (lab) return clean(lab.textContent.split('(')[0]);
                var tab = document.querySelector('.nav-tabs .nav-link.active');
                return tab ? base + ' - ' + clean(tab.textContent.replace(/\d+\s*$/, '')) : base;
            }

            // "A.Y. 2026-2027 | 1st Semester" style details taken from the term badges shown on the page.
            function detailsText() {
                var parts = [];
                document.querySelectorAll('.term-badge').forEach(function(b) {
                    var t = clean(b.textContent);
                    if (t) parts.push(t);
                });
                var sel = document.querySelector('select[name="term"]');
                if (!parts.length && sel && sel.selectedOptions[0]) parts.push('Term: ' + clean(sel.selectedOptions[0].textContent));
                return parts.join('  |  ');
            }

            // Same look as the PDF reports: green banner, centered green title, green table header, signature block.
            function printHtml(title, data, extraDetails) {
                var logo = new URL(INFO.logo, location.href).href;
                var head = data.head.map(function(h) {
                    return '<th>' + esc(h) + '</th>';
                }).join('');
                var body = data.rows.map(function(r) {
                    return '<tr>' + r.map(function(c) {
                        return '<td>' + esc(c) + '</td>';
                    }).join('') + '</tr>';
                }).join('');
                if (!data.rows.length) body = '<tr><td colspan="' + data.head.length + '" style="text-align:center;color:#777;">No records.</td></tr>';
                var details = extraDetails || detailsText();
                var today = new Date().toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });
                return '<!doctype html><html><head><meta charset="utf-8"><title>' + esc(title) + '</title><style>' +
                    '@page{size:landscape;margin:0}' +
                    '*{box-sizing:border-box}body{margin:0;font-family:Arial,Helvetica,sans-serif;color:#222;-webkit-print-color-adjust:exact;print-color-adjust:exact}' +
                    '.banner{background:#44b94f;color:#fff;padding:14px 30px;display:flex;align-items:center;gap:18px}' +
                    '.banner img{width:70px;height:70px;object-fit:cover;background:#fff}' +
                    '.banner h1{margin:0;font-size:24px}.banner p{margin:4px 0 0;font-size:13px}' +
                    '.page{padding:0 30px 26px}' +
                    'h2{text-align:center;color:#2e7d32;font-size:22px;letter-spacing:.3px;margin:22px 0 14px;padding-bottom:12px;border-bottom:2px solid #44b94f;text-transform:uppercase}' +
                    'table.data{width:100%;border-collapse:collapse;font-size:12px}table.outer{width:100%;border-collapse:collapse}table.outer>thead>tr>td,table.outer>tbody>tr>td,table.outer>tfoot>tr>td{padding:0;border:0}table.outer>thead{display:table-header-group}table.outer>tfoot{display:table-footer-group}.spacer{height:12mm}' +
                    'table.data th{background:#a8dcaa;color:#111;text-align:left;border:1px solid #222;padding:9px 8px}' +
                    'table.data td{border:1px solid #222;padding:8px;vertical-align:middle}table.data tbody tr:nth-child(even) td{background:#eff8f0}table.data tr{page-break-inside:avoid}table.data thead{display:table-header-group}' +
                    '.total{text-align:right;font-weight:bold;font-size:13px;margin:12px 0 0}' +
                    '.signwrap{margin-top:30px;page-break-inside:avoid;display:flex;justify-content:space-between}.signwrap .by{font-size:13px;margin-bottom:38px}.sign.blank{border-top:1px solid #222;height:0;padding:0}.sign{width:260px;margin:0;text-align:center;border-top:1px solid #222;padding-top:4px}' +
                    '.sign b{display:block;font-size:13px;text-transform:uppercase}.sign span{display:block;color:#666;font-size:12px}.sign i{display:block;color:#666;font-size:11px;margin-top:3px}' +
                    '.foot{text-align:center;color:#888;font-size:11px;margin-top:28px;font-style:italic}' +
                    '</style></head><body>' +
                    '<table class="outer"><thead><tr><td><div class="banner"><img src="' + esc(logo) + '" alt=""><div><h1>' + esc(INFO.site) + '</h1>' + (details ? '<p>' + esc(details) + '</p>' : '') + '</div></div><div class="page" style="padding-bottom:0"><h2>' + esc(title) + '</h2></div></td></tr></thead><tbody><tr><td>' +
                    '<div class="page" style="padding-top:0">' +
                    '<table class="data"><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table>' +
                    '<p class="total">Total Records: ' + data.rows.length + '</p>' +
                    '<div class="signwrap"><div><div class="by">Prepared by:</div><div class="sign"><b>' + esc(INFO.name) + '</b><span>' + esc(INFO.position) + '</span><i>Generated on ' + esc(today) + '</i></div></div><div><div class="by">Checked by:</div><div class="sign blank"></div></div></div>' +
                    '<div class="foot">Generated ' + esc(stamp()) + '</div></div></td></tr></tbody><tfoot><tr><td><div class="spacer"></div></td></tr></tfoot></table></body></html>';
            }

            function tableHtml(data, forExcel) {
                var th = data.head.map(function(h) {
                    return '<th style="background:#198754;color:#fff;border:1px solid #999;padding:6px 8px;text-align:left;">' + esc(h) + '</th>';
                }).join('');
                var body = data.rows.map(function(r) {
                    return '<tr>' + r.map(function(c) {
                        return '<td style="border:1px solid #999;padding:5px 8px;">' + esc(c) + '</td>';
                    }).join('') + '</tr>';
                }).join('');
                return '<table style="border-collapse:collapse;font-size:12px;width:100%;"><thead><tr>' + th + '</tr></thead><tbody>' + body + '</tbody></table>';
            }

            function stamp() {
                return new Date().toLocaleString('en-PH', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                    hour: 'numeric',
                    minute: '2-digit'
                });
            }

            async function run(kind, index, card, btn) {
                var original = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = 'Preparing...';
                try {
                    var data = await collect(index, card);
                    var title = titleText(card);
                    if (kind === 'excel') {
                        var html = '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>' +
                            '<h3>' + esc(title) + '</h3><p>Generated ' + esc(stamp()) + ' - ' + data.rows.length + ' record(s)</p>' +
                            tableHtml(data, true) + '</body></html>';
                        var blob = new Blob(['﻿' + html], {
                            type: 'application/vnd.ms-excel;charset=utf-8'
                        });
                        var a = document.createElement('a');
                        a.href = URL.createObjectURL(blob);
                        a.download = title.replace(/[^\w\- ]+/g, '').trim().replace(/\s+/g, '_') + '_' + new Date().toISOString().slice(0, 10) + '.xls';
                        document.body.appendChild(a);
                        a.click();
                        a.remove();
                    } else {
                        var w = window.open('', '_blank');
                        if (!w) {
                            alert('Please allow pop-ups to print.');
                            return;
                        }
                        w.document.write(printHtml(title, data));
                        w.document.close();
                        w.focus();
                        // wait for the logo before opening the print dialog
                        var fire = function() {
                            w.print();
                        };
                        var img = w.document.querySelector('.banner img');
                        if (img && !img.complete) {
                            img.onload = img.onerror = function() {
                                setTimeout(fire, 100);
                            };
                        } else {
                            setTimeout(fire, 300);
                        }
                    }
                } catch (e) {
                    alert('Sorry, the table could not be prepared. Please try again.');
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = original;
                }
            }

            // Used by the Print button inside pop-up windows (e.g. activity attendance).
            window.skPrintTable = function(table, title, details) {
                var data = readTable(table);
                var w = window.open('', '_blank');
                if (!w) {
                    alert('Please allow pop-ups to print.');
                    return;
                }
                w.document.write(printHtml(title, data, details));
                w.document.close();
                w.focus();
                var img = w.document.querySelector('.banner img');
                var fire = function() {
                    w.print();
                };
                if (img && !img.complete) {
                    img.onload = img.onerror = function() {
                        setTimeout(fire, 100);
                    };
                } else {
                    setTimeout(fire, 300);
                }
            };

            // Dashboard: prints the headline numbers and the charts (as pictures) with the report layout.
            function printDashboard(onlyCard) {
                var logo = new URL(INFO.logo, location.href).href;
                var stats = [];
                if (!onlyCard) document.querySelectorAll('.stat-card').forEach(function(c) {
                    var l = c.querySelector('.label'),
                        v = c.querySelector('.value');
                    if (l && v) stats.push([clean(l.textContent), clean(v.textContent)]);
                });
                if (!onlyCard) document.querySelectorAll('.status-card').forEach(function(c) {
                    var l = c.querySelector('.s-label'),
                        v = c.querySelector('.s-value');
                    if (l && v) stats.push([clean(l.textContent), clean(v.textContent)]);
                });
                var rowsHtml = stats.map(function(r) {
                    return '<tr><td>' + esc(r[0]) + '</td><td style="width:140px;text-align:right;font-weight:bold;">' + esc(r[1]) + '</td></tr>';
                }).join('');
                var charts = '';
                var chartTitle = '';
                document.querySelectorAll('.chart-card').forEach(function(card) {
                    if (onlyCard && card !== onlyCard) return;
                    var cv = card.querySelector('canvas'),
                        t = card.querySelector('.chart-title');
                    if (!cv) return;
                    if (onlyCard && t) chartTitle = clean(t.textContent);
                    charts += '<div class="chart"><h3>' + esc(t ? clean(t.textContent) : '') + '</h3><img src="' + cv.toDataURL('image/png') + '"></div>';
                });
                var today = new Date().toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });
                var html = '<!doctype html><html><head><meta charset="utf-8"><title>Dashboard</title><style>' +
                    '@page{size:landscape;margin:0}*{box-sizing:border-box}body{margin:0;font-family:Arial,Helvetica,sans-serif;color:#222;-webkit-print-color-adjust:exact;print-color-adjust:exact}' +
                    '.banner{background:#44b94f;color:#fff;padding:14px 30px;display:flex;align-items:center;gap:18px}.banner img{width:70px;height:70px;object-fit:cover;background:#fff}.banner h1{margin:0;font-size:24px}' +
                    '.page{padding:0 30px 20px}h2{text-align:center;color:#2e7d32;font-size:22px;margin:20px 0 14px;padding-bottom:12px;border-bottom:2px solid #44b94f;text-transform:uppercase}' +
                    'table{width:100%;border-collapse:collapse;font-size:13px}th{background:#a8dcaa;text-align:left;border:1px solid #222;padding:8px}td{border:1px solid #222;padding:7px 8px}tbody tr:nth-child(even) td{background:#eff8f0}' +
                    '.charts{display:flex;gap:20px;margin-top:18px}.chart{flex:1;max-width:100%;border:1px solid #ccc;padding:10px;page-break-inside:avoid;text-align:center}.chart h3{margin:0 0 8px;font-size:13px;text-align:left;text-transform:uppercase}.chart img{max-width:100%;max-height:300px}' +
                    '.signwrap{margin-top:30px;page-break-inside:avoid;display:flex;justify-content:space-between}.by{font-size:13px;margin-bottom:38px}.sign{width:260px;text-align:center;border-top:1px solid #222;padding-top:4px}.sign b{display:block;font-size:13px;text-transform:uppercase}.sign span{display:block;color:#666;font-size:12px}.sign i{display:block;color:#666;font-size:11px;margin-top:3px}.sign.blank{height:0;padding:0}' +
                    '</style></head><body><div class="banner"><img src="' + esc(logo) + '" alt=""><div><h1>' + esc(INFO.site) + '</h1></div></div>' +
                    '<div class="page"><h2>' + esc(onlyCard ? chartTitle : 'Dashboard Overview') + '</h2>' + (onlyCard ? '' : '<table><thead><tr><th>Summary</th><th style="text-align:right;">Total</th></tr></thead><tbody>' + rowsHtml + '</tbody></table>') +
                    '<div class="charts">' + charts + '</div>' +
                    '<div class="signwrap"><div><div class="by">Prepared by:</div><div class="sign"><b>' + esc(INFO.name) + '</b><span>' + esc(INFO.position) + '</span><i>Generated on ' + esc(today) + '</i></div></div><div><div class="by">Checked by:</div><div class="sign blank"></div></div></div>' +
                    '</div></body></html>';
                var w = window.open('', '_blank');
                if (!w) {
                    alert('Please allow pop-ups to print.');
                    return;
                }
                w.document.write(html);
                w.document.close();
                w.focus();
                var img = w.document.querySelector('.banner img');
                var fire = function() {
                    w.print();
                };
                if (img && !img.complete) {
                    img.onload = img.onerror = function() {
                        setTimeout(fire, 100);
                    };
                } else {
                    setTimeout(fire, 400);
                }
            }

            document.addEventListener('DOMContentLoaded', function() {
                if (/AdminDashboard/i.test(location.pathname)) {
                    var h = document.querySelector('.main-content h4');
                    if (h) {
                        var sub = h.nextElementSibling;
                        var holder = document.createElement('div');
                        holder.className = 'd-flex justify-content-between align-items-start flex-wrap gap-2';
                        var left = document.createElement('div');
                        h.parentNode.insertBefore(holder, h);
                        holder.appendChild(left);
                        left.appendChild(h);
                        if (sub && sub.tagName === 'P') left.appendChild(sub);
                        var pb = document.createElement('button');
                        pb.type = 'button';
                        pb.className = 'btn btn-sm btn-success';
                        pb.innerHTML = '<i class="bi bi-printer me-1"></i> Print';
                        pb.addEventListener('click', function() {
                            printDashboard(null);
                        });
                        holder.appendChild(pb);
                    }
                    // a small printer button on every graph prints just that graph
                    document.querySelectorAll('.chart-card').forEach(function(card) {
                        var title = card.querySelector('.chart-title');
                        if (!title || !card.querySelector('canvas')) return;
                        title.classList.add('d-flex', 'align-items-center', 'justify-content-between', 'gap-2');
                        var b = document.createElement('button');
                        b.type = 'button';
                        b.title = 'Print this graph';
                        b.className = 'btn btn-sm btn-outline-success py-0 px-2';
                        b.innerHTML = '<i class="bi bi-printer"></i>';
                        b.addEventListener('click', function() {
                            printDashboard(card);
                        });
                        title.appendChild(b);
                    });
                }
                listTables(document).forEach(function(table, index) {
                    var card = table.closest('.table-card');
                    if (!card || card.previousElementSibling && card.previousElementSibling.classList.contains('tbl-export-bar')) return;
                    if (table.querySelectorAll('thead th').length < 3) return;
                    var bar = document.createElement('div');
                    bar.className = 'tbl-export-bar';
                    bar.innerHTML = '<button type="button" class="btn btn-sm btn-success" data-k="print"><i class="bi bi-printer me-1"></i> Print</button>';
                    bar.addEventListener('click', function(e) {
                        var b = e.target.closest('button[data-k]');
                        if (b) run(b.getAttribute('data-k'), index, card, b);
                    });

                    // Beside the Search box when the page has one (top right), otherwise right above the table.
                    var sb = card.parentNode.querySelector('.search-box');
                    if (sb && sb.parentNode && !sb.closest('.modal') && !sb.dataset.printed) {
                        sb.dataset.printed = '1';
                        var wrap = document.createElement('div');
                        wrap.className = 'd-flex align-items-center gap-2 flex-wrap';
                        sb.parentNode.insertBefore(wrap, sb);
                        wrap.appendChild(bar);
                        wrap.appendChild(sb);
                        bar.style.marginBottom = '0';
                    } else {
                        card.parentNode.insertBefore(bar, card);
                    }
                });
            });
        })();
    </script>
<?php } ?>