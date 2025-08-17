<?php

// define languages available
$LANGUAGES = [];
$LANGUAGES[] = ["gettext" => "en_US", "name" => "English"];
$LANGUAGES[] = ["gettext" => "fr_CA", "name" => "French"];
$LANGUAGES[] = ["gettext" => "hu_HU", "name" => "Hungarian"];

if (session_id() === "") die("This script needs to be called after session initialization!");

// verify if language is already initialized
if (!isset($_SESSION["LANG"])) {
    $_SESSION["LANG"] = "en_US";
}

// set current language
if (isset($_POST["LANG"])) $_GET["LANG"] = $_POST["LANG"];

if (isset($_GET["LANG"])) {
    $lang = (string)$_GET["LANG"];
    $lang = str_replace([".", "/", "<", ">", "\\"], "", $lang);

    $found = false;
    foreach ($LANGUAGES as $L) {
        if ($lang === $L["gettext"]) { $found = true; break; }
    }
    if ($found === false) die("Invalid language specified: ".$lang);
    $_SESSION["LANG"] = $lang;
}

/**
 * ==== gettext shims (PHP 8 safe) ====
 * If the PHP 'gettext' extension is present, these call the native functions.
 * Otherwise they no-op so the app still runs (strings remain untranslated).
 */
if (!function_exists('T_')) {
    function T_(string $msg): string {
        return function_exists('gettext') ? gettext($msg) : $msg;
    }
}
if (!function_exists('T_setlocale')) {
    function T_setlocale(int $category, string $locale): void {
        // prefer UTF-8
        $lc = (strpos($locale, '.') === false) ? ($locale . '.UTF-8') : $locale;
        @putenv("LC_ALL=$lc");
        // set broadly for formatting as well
        if (function_exists('setlocale')) {
            @setlocale(LC_ALL, $lc);
            @setlocale($category, $lc);
        }
    }
}
if (!function_exists('T_bindtextdomain')) {
    function T_bindtextdomain(string $domain, string $dir): void {
        if (function_exists('bindtextdomain')) {
            bindtextdomain($domain, $dir);
            if (function_exists('bind_textdomain_codeset')) {
                bind_textdomain_codeset($domain, 'UTF-8');
            }
        }
    }
}
if (!function_exists('T_bind_textdomain_codeset')) {
    function T_bind_textdomain_codeset(string $domain, string $codeset): void {
        if (function_exists('bind_textdomain_codeset')) {
            bind_textdomain_codeset($domain, $codeset);
        }
    }
}
if (!function_exists('T_textdomain')) {
    function T_textdomain(string $domain): void {
        if (function_exists('textdomain')) {
            textdomain($domain);
        }
    }
}

// === REMOVE legacy thirdparty include ===
// require_once("thirdparty/gettext/gettext.inc"); // <-- gone

// gettext setup
T_setlocale(LC_MESSAGES, $_SESSION["LANG"]);
if (!defined("LANGUAGE_DOMAIN")) define("LANGUAGE_DOMAIN", "message");

if (defined("CALLED_FROM_GAME_INIT"))
    $locales_dir = realpath("./../locale");
else
    $locales_dir = realpath("./locale");

T_bindtextdomain(LANGUAGE_DOMAIN, $locales_dir);
T_bind_textdomain_codeset(LANGUAGE_DOMAIN, "UTF-8");
T_textdomain(LANGUAGE_DOMAIN);

header("Content-type: text/html; charset=UTF-8");
