<?php
// System color theme — a Super Admin picks a primary color (and a light gradient color) in
// Configuration > Appearance. The pages hard-code the original green palette in many places, so
// instead of editing each one, the rendered HTML (and admin.css) is passed through themeApply(),
// which swaps the default palette for the configured one. No customization = no processing.

const THEME_DEFAULT_PRIMARY = '#45b84d';
const THEME_DEFAULT_SECONDARY = '#aadaad';

function themeIsHex($value)
{
    return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
}

// Adds the theme columns on first use (older databases don't have them yet).
function themeEnsureColumns()
{
    global $conn;
    foreach (['theme_primary', 'theme_secondary'] as $col) {
        $exists = $conn->query("SHOW COLUMNS FROM site_settings LIKE '$col'");
        if ($exists && $exists->num_rows === 0) {
            $conn->query("ALTER TABLE site_settings ADD COLUMN $col VARCHAR(7) NULL");
        }
    }
}

// Returns ['primary' => '#rrggbb', 'secondary' => '#rrggbb', 'custom' => bool].
function themeColors()
{
    global $conn;
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = ['primary' => THEME_DEFAULT_PRIMARY, 'secondary' => THEME_DEFAULT_SECONDARY, 'custom' => false];
    try {
        $res = $conn->query("SELECT theme_primary, theme_secondary FROM site_settings WHERE id = 1");
        $row = $res ? $res->fetch_assoc() : null;
    } catch (Throwable $ex) {
        $row = null; // columns not created yet
    }
    if ($row) {
        $p = themeIsHex($row['theme_primary'] ?? null) ? strtolower($row['theme_primary']) : THEME_DEFAULT_PRIMARY;
        $s = themeIsHex($row['theme_secondary'] ?? null) ? strtolower($row['theme_secondary']) : THEME_DEFAULT_SECONDARY;
        $cache = ['primary' => $p, 'secondary' => $s, 'custom' => ($p !== THEME_DEFAULT_PRIMARY || $s !== THEME_DEFAULT_SECONDARY)];
    }
    return $cache;
}

function themeRgb($hex)
{
    return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
}

// Mixes $hex toward $target ([r,g,b]) by fraction $t (0 = unchanged, 1 = fully target).
function themeMix($hex, array $target, $t)
{
    $rgb = themeRgb($hex);
    $out = '#';
    foreach ($rgb as $i => $c) {
        $out .= str_pad(dechex((int)round($c + ($target[$i] - $c) * $t)), 2, '0', STR_PAD_LEFT);
    }
    return $out;
}

// Default palette color => replacement, derived from the configured primary/secondary.
function themeMap(array $t)
{
    $p = $t['primary'];
    $black = [0, 0, 0];
    $white = [255, 255, 255];
    return [
        '#45b84d' => $p,
        '#4caf50' => themeMix($p, $white, 0.04),
        '#409d42' => themeMix($p, $black, 0.15),
        '#388e3c' => themeMix($p, $black, 0.23),
        '#3a7d44' => themeMix($p, $black, 0.30),
        '#2e7d32' => themeMix($p, $black, 0.32),
        '#1b5e20' => themeMix($p, $black, 0.49),
        '#a5d6a7' => themeMix($p, $white, 0.52),
        '#c8e6c9' => themeMix($p, $white, 0.70),
        '#e8f5e9' => themeMix($p, $white, 0.88),
        '#aadaad' => $t['secondary'],
    ];
}

function themeApply($text)
{
    $t = themeColors();
    if (!$t['custom'] || $text === '' || $text === null) {
        return $text;
    }
    $map = themeMap($t);
    $text = preg_replace_callback('/#(?:45b84d|4caf50|409d42|388e3c|3a7d44|2e7d32|1b5e20|a5d6a7|c8e6c9|e8f5e9|aadaad)\b/i', function ($m) use ($map) {
        return $map[strtolower($m[0])];
    }, $text);
    // Focus rings etc. use rgba() of the default primary.
    [$r, $g, $b] = themeRgb($t['primary']);
    return preg_replace('/\b69\s*,\s*184\s*,\s*77\b/', "$r, $g, $b", $text);
}

// Starts output buffering for HTML responses when a custom theme is set.
function themeStart()
{
    if (PHP_SAPI === 'cli' || !themeColors()['custom']) {
        return;
    }
    ob_start(function ($buffer) {
        foreach (headers_list() as $h) {
            if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) {
                return $buffer; // JSON, PDF, downloads, etc. are left untouched
            }
        }
        // Serve admin.css through the same color swap.
        $buffer = str_replace('/assets/css/admin.css', '/assets/css/theme-css.php?f=admin&v=' . substr(md5(json_encode(themeColors())), 0, 8), $buffer);
        return themeApply($buffer);
    });
}
