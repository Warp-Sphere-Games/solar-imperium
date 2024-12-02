<?php


// Security check against XSS exploits
foreach ($_POST as $key => $value) {
    // Check for invalid characters in the key
    if (strpos($key, "<") !== false || strpos($key, ">") !== false || strpos($key, "%") !== false || strpos($key, "'") !== false || strpos($key, "\"") !== false) {
        die("Invalid information!");
    }

    $tainted = false;

    // Check for "<" in the value
    if (strpos(strtolower($value), "<") !== false) {
        $value = str_replace("<", "_", $value);
        $tainted = true;
    }

    // Check for HTML character references
    if (strpos($value, "&#") !== false) {
        $value = str_replace("&#", "_", $value);
        $tainted = true;
    }

    // Check for "%" in the value
    if (strpos($value, "%") !== false) {
        $value = str_replace("%", "_", $value);
        $tainted = true;
    }

    // Update $_POST if necessary
    if ($tainted) {
        $_POST[$key] = $value;
    }
}


// repeat for GET variables

foreach ($_GET as $key => $value) {
    // Check for invalid characters in the key
    if (strpos($key, "<") !== false || strpos($key, ">") !== false || strpos($key, "%") !== false || strpos($key, "'") !== false || strpos($key, "\"") !== false) {
        die("Invalid information!");
    }

    $tainted = false;
    
    // Check for malicious scripts in the value
    if (strpos(strtolower($value), "<script") !== false) {
        die("Invalid information");
    }

    // Check for event handlers or other potentially harmful content
    $dangerous_events = ["onload", "onmouseover", "onchange", "onclick", "ondblclick", "onabort", "ondragdrop", "onerror", "onfocus", "onkeydown", "onkeypress", "onmouseout", "onreset", "onresize", "onselect", "onsubmit", "onunload"];
    foreach ($dangerous_events as $event) {
        if (strpos(strtolower($value), $event) !== false) {
            die("Invalid information");
        }
    }

    // Handle HTML character references
    if (strpos($value, "&#") !== false) {
        $value = str_replace("&#", "_", $value);
        $tainted = true;
    }

    // Handle percent encoding
    if (strpos($value, "%") !== false) {
        $value = str_replace("%", "_", $value);
        $tainted = true;
    }

    // Update the $_GET superglobal if necessary
    if ($tainted) {
        $_GET[$key] = $value;
    }
}


?>
