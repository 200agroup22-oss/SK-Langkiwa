<?php
// Adds "Print" and "Excel" buttons above every admin list table (applicants, beneficiaries, scholars, ...).
// Included from adminsidebar.php, so no page needs its own copy. The export covers every page of the table
// with the filters / search / sort currently applied, leaves out the Action column, and skips tables inside modals.
if (!defined('SK_TABLE_EXPORT_LOADED')) {
    define('SK_TABLE_EXPORT_LOADED', true);
?>
    <style>
        .tbl-export-bar {
            display: flex;
            justify-content: flex-end;
            gap: 6px;
            margin-bottom: 6px;
        }

        .tbl-export-bar .btn {
            font-size: 12px;
            padding: 3px 10px;
        }
    </style>
    <script>
        (function() {
            // Pages whose tables are settings / charts rather than lists.
            if (/AdminConfiguration|AdminReports|AdminProgramManagement/i.test(location.pathname)) return;

            function clean(t) {
                return (t || '').replace(/\s+/g, ' ').trim();
            }

            function esc(t) {
                return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }

            function listTables(doc) {
                return Array.prototype.filter.call(doc.querySelectorAll('.table-card table'), function(t) {
                    return !t.closest('.modal');
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
                    var row = [];
                    tds.forEach(function(td, i) {
                        if (skip.indexOf(i) === -1) row.push(clean(td.textContent));
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

            // Gathers the table across all pages (page 1..N of the current filters).
            async function collect(index, card) {
                var data = readTable(listTables(document)[index]);
                var pages = pageCount(card);
                if (pages <= 1) return data;
                var current = parseInt(new URLSearchParams(location.search).get('page') || '1', 10);
                var rows = [];
                for (var p = 1; p <= pages; p++) {
                    if (p === current) {
                        rows = rows.concat(data.rows);
                        continue;
                    }
                    var params = new URLSearchParams(location.search);
                    params.set('page', p);
                    var res = await fetch(location.pathname + '?' + params.toString(), {
                        credentials: 'same-origin'
                    });
                    var doc = new DOMParser().parseFromString(await res.text(), 'text/html');
                    var t = listTables(doc)[index];
                    if (t) rows = rows.concat(readTable(t).rows);
                }
                // keep page order: pages before current were pushed in loop order, current slotted in place
                return {
                    head: data.head,
                    rows: rows
                };
            }

            function titleText() {
                var tab = document.querySelector('.nav-tabs .nav-link.active');
                var t = clean(document.title);
                return tab ? t + ' - ' + clean(tab.textContent.replace(/\d+\s*$/, '')) : t;
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
                    var title = titleText();
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
                        w.document.write('<!doctype html><html><head><meta charset="utf-8"><title>' + esc(title) + '</title>' +
                            '<style>@page{size:landscape;margin:12mm}body{font-family:Arial,sans-serif;color:#111}h2{margin:0 0 2px}p{margin:0 0 10px;font-size:12px;color:#555}th,td{vertical-align:top}</style></head><body>' +
                            '<h2>' + esc(title) + '</h2><p>Printed ' + esc(stamp()) + ' - ' + data.rows.length + ' record(s)</p>' +
                            tableHtml(data, false) + '</body></html>');
                        w.document.close();
                        w.focus();
                        setTimeout(function() {
                            w.print();
                        }, 300);
                    }
                } catch (e) {
                    alert('Sorry, the table could not be prepared. Please try again.');
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = original;
                }
            }

            document.addEventListener('DOMContentLoaded', function() {
                listTables(document).forEach(function(table, index) {
                    var card = table.closest('.table-card');
                    if (!card || card.previousElementSibling && card.previousElementSibling.classList.contains('tbl-export-bar')) return;
                    if (table.querySelectorAll('thead th').length < 3) return;
                    var bar = document.createElement('div');
                    bar.className = 'tbl-export-bar';
                    bar.innerHTML = '<button type="button" class="btn btn-outline-secondary" data-k="print"><i class="bi bi-printer me-1"></i>Print</button>' +
                        '<button type="button" class="btn btn-outline-success" data-k="excel"><i class="bi bi-file-earmark-excel me-1"></i>Excel</button>';
                    bar.addEventListener('click', function(e) {
                        var b = e.target.closest('button[data-k]');
                        if (b) run(b.getAttribute('data-k'), index, card, b);
                    });
                    card.parentNode.insertBefore(bar, card);
                });
            });
        })();
    </script>
<?php } ?>