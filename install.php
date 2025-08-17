<?php

// Solar Imperium is licensed under GPL2, Check LICENSE.TXT for mode details //

$pos = strpos($_SERVER["SCRIPT_NAME"],"install.php_old");
if ($pos === FALSE) {
	// do nothing
} else {
	die("Installer disabled.");
}

/*
 * Remove file
 */
if (isset($_GET["REMOVE"])) {

	if (!is_writable("install.php")) die("<b>Unable to rename 'install.php' to 'install.php_old'! Please remove the file manually.</b>");
	if (!is_writable("."))  die("<b>Unable to rename 'install.php' to 'install.php_old'! Please remove the file manually.</b>");
	rename("install.php","install.php_old");
	die(header("Location: index.php"));
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    // Friendly message if someone accidentally deploys without vendor/
    die("Missing dependencies. Please deploy the 'vendor' folder or run 'composer install'.");
}
require $autoload;

// Minimal error/exception handler for the installer (no PHP5-era calls)
set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
set_exception_handler(function($e) {
    http_response_code(500);
    echo "<pre>Installer error: " . htmlspecialchars($e->getMessage()) . "\n"
       . "in " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</pre>";
});

// Devolopment debug ONLY REMOVE BEFORE RELEASE
ini_set('display_errors', '1');
error_reporting(E_ALL);



// basic PHP initialization

date_default_timezone_set('America/Chicago'); // pick your canonical tz


ob_start();	// output buffering


// if 'register_globals' directive is active, halt the process
if (ini_get("register_globals")==1)
{
	die("Disable register_globals PHP Directive!");
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$docroot = rtrim(realpath($_SERVER['DOCUMENT_ROOT'] ?: __DIR__), '/');

$TPL = new Smarty;
$TPL->template_dir = $docroot . '/templates/installer/';
$TPL->compile_dir  = $docroot . '/templates_c/installer/';
$TPL->use_sub_dirs = false;

if (!is_dir($TPL->compile_dir)) {
    if (!mkdir($TPL->compile_dir, 0755, true) && !is_dir($TPL->compile_dir)) {
        die("Unable to create Smarty compile directory: {$TPL->compile_dir}");
    }
}
if (!is_writable($TPL->compile_dir)) {
    die("Smarty compile directory is not writable: {$TPL->compile_dir}");
}

// optional for cooperative group write during dev
umask(002);





$current_page = 1;
if (isset($_GET["page"])) $current_page = intval($_GET["page"]);

if (file_exists("include/config.php")) $current_page = 4;

if ($current_page < 1) $current_page = 1;
if ($current_page > 4) $current_page = 4;

/**
 * Page 1
 */
if ($current_page == 1) {

	$TPL->display("page".$current_page.".html");
	die();
}

/**
 * Page 2
 */

if ($current_page == 2) {

    // Verify Session + expose CSRF for the next form
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    $TPL->assign("csrf", $_SESSION['csrf']);

    $output = "";
    $ok_count = 0;

    // Required PHP extensions
    $extensions = ["mbstring", "gd", "mysqli"];
    $loaded = get_loaded_extensions();

    foreach ($extensions as $ext) {
        if (in_array($ext, $loaded, true)) {
            $ok_count++;
            $output .= "Extension <b>{$ext}</b> :: <b style=color:blue>Found</b><br/>";
        } else {
            $output .= "Extension <b>{$ext}</b> :: <b style=color:red>Missing</b><br/>";
        }
    }

    // Build absolute paths under the current docroot (works in Docker)
    $docroot = rtrim(realpath($_SERVER['DOCUMENT_ROOT'] ?: __DIR__), '/');
    $paths = [
        "{$docroot}/images/game/empires/",
        "{$docroot}/include/game/games_config/",
        "{$docroot}/include/game/games_rules/",
        "{$docroot}/templates_c/",
        "{$docroot}/templates_c/game/",
        "{$docroot}/templates_c/system/",
    ];

    foreach ($paths as $p) {
        $p = rtrim($p, '/');

        if (is_dir($p)) {
            if (is_writable($p)) {
                $ok_count++;
                $output .= "Path <b>{$p}/</b> :: <b style=color:blue>Writable</b><br/>";
            } else {
                $output .= "Path <b>{$p}/</b> :: <b style=color:red>Not Writable!</b><br/>";
            }
            continue;
        }

        $parent = dirname($p);

        // Try to create parent if missing and its parent is writable
        if (!is_dir($parent)) {
            $grand = dirname($parent);
            if (!is_dir($grand) || !is_writable($grand)) {
                $output .= "Path <b>{$p}/</b> :: <b style=color:red>Not Found! Parent missing and not creatable.</b><br/>";
                continue;
            }
            if (!mkdir($parent, 0755, true) && !is_dir($parent)) {
                $output .= "Path <b>{$p}/</b> :: <b style=color:red>Not Found! Unable to create parent directory.</b><br/>";
                continue;
            }
        }

        // Ensure parent (now existing) is writable before mkdir
        if (!is_writable($parent)) {
            $output .= "Path <b>{$p}/</b> :: <b style=color:red>Parent exists but not writable.</b><br/>";
            continue;
        }

        if (!mkdir($p, 0755, true) && !is_dir($p)) {
            $output .= "Path <b>{$p}/</b> :: <b style=color:red>Not Found!</b><br/>";
        } else {
            $ok_count++;
            $output .= "Path <b>{$p}/</b> :: <b style=color:blue>Not Found but created</b><br/>";
        }
    }

    // Summary
    if ($ok_count != (count($paths) + count($extensions))) {
        $output .= "<br/><b style=color:red>*** Please fix these issues before continuing ***</b><br/>";
    }

    $TPL->assign("output", $output);
    $TPL->display("page{$current_page}.html");
    die();
}
/**
 * Page #3
 */
 


if ($current_page==3) {
	
	// Verify Session
	if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        die("Invalid CSRF token.");
    }
	
	if (!isset($_POST["db_driver"])) die("Invalid post data.");
	
	$db_driver = $_POST["db_driver"];
	$db_hostname = $_POST["db_hostname"];
	$db_name = $_POST["db_name"];
	$db_username = $_POST["db_username"];
	$db_password1 = $_POST["db_password1"];
	$db_password2 = $_POST["db_password2"];
	if ($db_password1 != $db_password2) die("Passwords don't matches!");
	$db_password = $db_password1;
	$output = "";
	
    // Whitelist driver and validate inputs
    $allowed_drivers = ['mysqli', 'pdo_mysql'];
    if (!in_array($db_driver, $allowed_drivers, true)) die("Unsupported database driver.");
    if (!preg_match('/^[A-Za-z0-9_]+$/', $db_name)) die("Invalid database name.");
    if (!preg_match('#^[A-Za-z0-9._-]+(:\d+)?$#', $db_hostname) && !preg_match('#^/[^\\0]+$#', $db_hostname)) {
        die("Invalid database host (allowing host[:port] or absolute socket path).");
    }


    $DB = NewADOConnection($db_driver);
    $DB->setErrorHandling(ADODB_ERROR_EXCEPTION);
    try {
        $DB->Connect($db_hostname, $db_username, $db_password, "");
    } catch (Throwable $t) {
        die("Database connect failed: " . htmlspecialchars($t->getMessage()));
    }

	if (!empty($_POST['confirm_drop']) && $_POST['confirm_drop'] === '1') {
       $DB->Execute("DROP DATABASE `{$db_name}`");
    }

	$DB->Execute("CREATE DATABASE IF NOT EXISTS `{$db_name}`");

	try {
        $DB->Connect($db_hostname, $db_username, $db_password, $db_name);
    } catch (Throwable $t) {
        die("Database select failed: " . htmlspecialchars($t->getMessage()));
    }

	$sql_data = @file_get_contents("include/sql_base.txt");
    if ($sql_data === false) die("Missing include/sql_base.txt");
    foreach (array_filter(array_map('trim', explode("/***/", $sql_data))) as $stmt) {
        if ($stmt === '') continue;
        if (!$DB->Execute($stmt)) {
            die("SQL failed: " . htmlspecialchars($DB->ErrorMsg()));
        }
    }

	$output .= "Database correctly configured. Click on continue button to complete installation.";
	
	$config_data = @file_get_contents("include/config_orig.php");
	if ($config_data === false) die("Missing include/config_orig.php");
	$config_data = str_replace("{db_hostname}",$db_hostname,$config_data);
	$config_data = str_replace("{db_name}",$db_name,$config_data);
	$config_data = str_replace("{db_username}",$db_username,$config_data);
	$config_data = str_replace("{db_password}",$db_password,$config_data);
	$config_data = str_replace("{db_driver}",$db_driver,$config_data);
	
	if (!is_writable('include')) die("The 'include' directory is not writable.");
    if (file_put_contents("include/config.php", $config_data, LOCK_EX) === false) {
        die("Failed to write include/config.php");
    }
    @chmod("include/config.php", 0640);
	
	$TPL->assign("output",$output);
	$TPL->display("page".$current_page.".html");
	die();
}

if ($current_page == 4) {
	$TPL->display("page".$current_page.".html");
	die();
}

?>
