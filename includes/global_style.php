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
<?php } ?>