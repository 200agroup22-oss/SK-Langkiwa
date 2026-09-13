<?php
if ($_SERVER['REQUEST_URI'] === '/' || $_SERVER['REQUEST_URI'] === '') {
    require __DIR__ . '/login.php';
    return true;
}
return false;
