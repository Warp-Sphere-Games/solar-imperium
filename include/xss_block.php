<?php
// PHP 8+ compatible input sanitizer (keeps legacy behavior)
// NOTE: Prefer validating inputs and escaping on output in new code.

declare(strict_types=1);

/**
 * Recursively sanitize an input array in-place.
 * - Dies if a key contains forbidden characters (legacy behavior).
 * - For POST: replaces "<" with "_" in values; for both: replaces "&#" and "%" with "_".
 * - For GET: blocks common script/event patterns (legacy behavior).
 */
function si_sanitize_input_array(array &$arr, bool $isGet = false): void
{
    // Original key checks: die if key contains any of these
    $forbiddenInKeys = ['<', '>', '%', "'", '"'];

    foreach ($arr as $key => &$value) {
        // key validation
        foreach ($forbiddenInKeys as $ch) {
            if (strpos($key, $ch) !== false) {
                die("Invalid information!");
            }
        }

        // recurse for nested arrays
        if (is_array($value)) {
            si_sanitize_input_array($value, $isGet);
            continue;
        }

        // normalize to string
        $val = (string)$value;
        $tainted = false;

        if ($isGet) {
            $lower = strtolower($val);
            // legacy GET checks
            $badNeedles = [
                '<script','onload','onmouseover','onchange','onclick','ondblclick','onabort','ondragdrop',
                'onerror','onfocus','onkeydown','onkeypress','onmouseout','onreset','onresize','onselect',
                'onsubmit','onunload'
            ];
            foreach ($badNeedles as $needle) {
                if (strpos($lower, $needle) !== false) {
                    die("Invalid information");
                }
            }
        }

        // legacy replacements
        if (strpos($val, '&#') !== false) { $val = str_replace('&#', '_', $val); $tainted = true; }
        if (strpos($val, '%')  !== false) { $val = str_replace('%',  '_', $val); $tainted = true; }

        if (!$isGet) { // POST-only legacy behavior: replace "<"
            if (stripos($val, '<') !== false) { $val = str_ireplace('<', '_', $val); $tainted = true; }
        }

        if ($tainted) {
            $value = $val; // write back
        }
    }
    unset($value);
}

// Apply to superglobals
si_sanitize_input_array($_POST, false);
si_sanitize_input_array($_GET,  true);
