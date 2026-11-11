<?php
// Small rules that apply to every page of the system (included with the admin sidebar and the applicant / scholar
// navigation bar, so no page needs its own copy).
if (!defined('SK_GLOBAL_STYLE_LOADED')) {
    define('SK_GLOBAL_STYLE_LOADED', true);
?>
    <style>
        /* Every table heading is shown in capitals. */
        table thead th,
        .table thead th,
        table>thead>tr>th {
            text-transform: uppercase;
        }
    </style>
    <script>
        // A time can't be set earlier than right now when its date is today. Time boxes are paired with the date box in
        // the same form by name (release_time/release_date, activity_time/activity_date, event_time/event_date). A time
        // that was already saved (edit windows) is left alone until it is changed.
        (function() {
            var PAIRS = {
                release_time: 'release_date',
                activity_time: 'activity_date',
                event_time: 'event_date'
            };

            function pad(n) {
                return String(n).padStart(2, '0');
            }

            function today() {
                var d = new Date();
                return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
            }

            function nowTime() {
                var d = new Date();
                return pad(d.getHours()) + ':' + pad(d.getMinutes());
            }

            function setup(timeEl) {
                var form = timeEl.closest('form') || document;
                var dateEl = form.querySelector('input[type="date"][name="' + PAIRS[timeEl.name] + '"]');
                if (!dateEl) return;
                var originalTime = timeEl.value,
                    originalDate = dateEl.value;

                function apply(showMessage) {
                    var untouched = timeEl.value === originalTime && dateEl.value === originalDate;
                    var isToday = dateEl.value === today();
                    if (isToday && !untouched) {
                        timeEl.min = nowTime();
                    } else {
                        timeEl.removeAttribute('min');
                    }
                    timeEl.setCustomValidity('');
                    if (isToday && !untouched && timeEl.value && timeEl.value < nowTime()) {
                        timeEl.setCustomValidity('That time has already passed. Pick a time from now onward.');
                        if (showMessage) {
                            timeEl.value = '';
                            timeEl.reportValidity();
                            timeEl.setCustomValidity('');
                        }
                    }
                }
                dateEl.addEventListener('change', function() {
                    apply(true);
                });
                timeEl.addEventListener('focus', function() {
                    apply(false);
                });
                timeEl.addEventListener('change', function() {
                    apply(true);
                });
                if (timeEl.form) timeEl.form.addEventListener('submit', function() {
                    apply(false);
                }, true);
            }
            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('input[type="time"]').forEach(function(t) {
                    if (PAIRS[t.name]) setup(t);
                });
            });
        })();
    </script>
<?php } ?>