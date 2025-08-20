<?php

// --- configuration ---
define("MRGERR_DEBUG_VERBOSE", true);   // show full HTML error page
define("MRGERR_DEBUG_SENDMAIL", false); // keep SMTP off

// Notify method: 'udp' | 'file' | 'none' | 'smtp' (legacy mail block)
define("MRGERR_NOTIFY_METHOD", "udp");

// UDP target for Windows notifications (your workstation listener)
define("MRGERR_NOTIFY_UDP_HOST", "10.8.0.22");
define("MRGERR_NOTIFY_UDP_PORT", 50001);

// Optional file log (used if MRGERR_NOTIFY_METHOD='file')
define("MRGERR_LOGFILE", __DIR__ . "/../../var/errors.log");

// PHP error reporting
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', true);
ini_set('display_startup_errors', true);
ob_start();

// if you want to use smtp under unix (kept for compatibility)
define("MRGERR_SMTP_SERVER","127.0.0.1");
define("MRGERR_SMTP_MAILFROM","email@example.com");
define("MRGERR_SMTP_MAILTO","email@example.com");

$MRGERR_ERROR_TYPES = array();
$MRGERR_ERROR_TYPES[E_ERROR] = array("E_ERROR","Fatal Error");
$MRGERR_ERROR_TYPES[E_WARNING] = array("E_WARNING","Warning");
$MRGERR_ERROR_TYPES[E_PARSE] = array("E_PARSE","Parse Error");
$MRGERR_ERROR_TYPES[E_NOTICE] = array("E_NOTICE","Notice");
$MRGERR_ERROR_TYPES[E_CORE_ERROR] = array("E_CORE_ERROR","Fatal Core Error");
$MRGERR_ERROR_TYPES[E_CORE_WARNING] = array("E_CORE_WARNING","Fatal Core Warning");
$MRGERR_ERROR_TYPES[E_COMPILE_ERROR] = array("E_COMPILE_ERROR","Fatal Compile Error");
$MRGERR_ERROR_TYPES[E_COMPILE_WARNING] = array("E_COMPILE_WARNING","Fatal Compile Warning");
$MRGERR_ERROR_TYPES[E_USER_ERROR] = array("E_ERROR","Fatal User Error");
$MRGERR_ERROR_TYPES[E_USER_WARNING] = array("E_WARNING","User Warning");
$MRGERR_ERROR_TYPES[E_USER_NOTICE] = array("E_NOTICE","User Notice");
$MRGERR_ERROR_TYPES[E_STRICT] = array("E_STRICT","Strict Code");
$MRGERR_ERROR_TYPES[E_DEPRECATED] = array("E_DEPRECATED","Deprecated Code");

// if you want to use smtp under unix
ini_set('SMTP',MRGERR_SMTP_SERVER);

function MRG_error_handler_HTML_template()
{
	$body = "
	<html>
	<head>
	<title>Oops ... {title}</title>
	</head>
	<body bgcolor=\"#aaaaaa\">
	<table align=\"center\" bgcolor=\"darkred\" width=\"90%\">
	<tr>
	<td style=\"color:yellow;font-size:13px;font-family:verdana\"><b>Oops ... {title}</b>
	</td>
	</tr>
	<tr>
	<td bgcolor=\"#cacaca\">
	<table cellspacing=\"0\" width=\"100%\" cellpadding=\"5\"><tr><td style=\"color:black;font-size:13px;font-family:verdana\">
	{body}
	</td></tr></table>
	</td>
	</tr>
	<tr>
	<td align=\"right\" style=\"color:yellow;font-size:10pt;font-family:verdana\">{platform}
	</td>
	</tr>
	</table>
	</body>
	</html>
	";
	return $body;
}

function MRG_error_handler_list_HTML($title, $listing)
{
    $html = "<table width=\"100%\"><tr><td colspan=\"2\" style=\"font-size:13px;font-family:verdana;color:darkred\"><b>".$title."</b></td></tr>\r\n";

    if (!is_array($listing)) {
        $listing = (array)$listing;
    }

    $count = 0;
    foreach ($listing as $key => $value) {
        $bgcolor = ($count++ % 2 === 1) ? "#dedede" : "#efefef";

        // Render non-scalars safely
        if (is_object($value)) {
            $value = '[object '.get_class($value).']';
        } elseif (is_array($value)) {
            $value = htmlspecialchars(var_export($value, true));
        } elseif (is_resource($value)) {
            $value = '[resource]';
        } else {
            $value = htmlspecialchars((string)$value);
        }

        $k = htmlspecialchars((string)$key);
        $html .= "<tr><td bgcolor=\"{$bgcolor}\"><b>{$k}</b></td><td width=\"100%\" bgcolor=\"{$bgcolor}\">{$value}</td></tr>\r\n";
    }

    $html .= "</table>\r\n<br/>";
    return $html;
}

function MRG_error_handler($errno, $errstr, $errfile, $errline)
{
    // Honor @-operator and ignore deprecations
    if (!(error_reporting() & $errno)) return false; // silenced with @
    if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) return false;

    // If you want fewer popups, also ignore notices/warnings:
    // if (in_array($errno, [E_NOTICE, E_USER_NOTICE, E_WARNING, E_USER_WARNING], true)) return false;

    global $MRGERR_ERROR_TYPES;
    ob_clean();

    $html = MRG_error_handler_HTML_template();
    $title = (isset($MRGERR_ERROR_TYPES[$errno]) ? $MRGERR_ERROR_TYPES[$errno][1] : 'Error') . " : " . $errstr;
    $html = str_replace("{title}", $title, $html);
    $html = str_replace("{platform}", "PHP " . PHP_VERSION . " (" . PHP_OS . ")", $html);

    $body  = "";
    $body .= "<br/><div align=\"center\">$errstr in <b>$errfile:$errline</b></div><br/>\n";
    $body_short = $body;

	// code
	$body .= "<b style=\"font-size:13px;font-family:verdana;color:darkred\">Error line in $errfile: </b>";
	$body .= "<table width=\"100%\" style=\"font-size:10pt;font-family:verdana\">";

	$line = $errline - 4;
	$code = file($errfile);
	for ($i=$line;$i<($errline+3);$i++)
	{
		$bgcolor = ($i%2==0?"#dedede":"#efefef");
		if (($i+1) == $errline) $bgcolor = "#FF9999";

		if (isset($code[$i]))
		{
			if (($i+1) == $errline)
			{
				$body .=  "<tr><td bgcolor=\"".$bgcolor."\" style=\"color:#666666;font-size:13px;font-family:verdana\"><i>".str_pad($i,6,0,STR_PAD_LEFT)."</i></td>
				<td width=\"100%\" bgcolor=\"".$bgcolor."\" style=\"color:darkred;font-size:13px;font-family:verdana\"><b>".str_replace("\t","&nbsp;&nbsp;&nbsp;&nbsp;",$code[$i])."</b></td></tr>";
			} else {
				$body .=  "<tr><td bgcolor=\"".$bgcolor."\" style=\"color:#666666;font-size:13px;font-family:verdana\"><i>".str_pad($i,6,0,STR_PAD_LEFT)."</i></td>
				<td width=\"100%\" bgcolor=\"".$bgcolor."\" style=\"color:black;font-size:13px;font-family:verdana\">".str_replace("\t","&nbsp;&nbsp;&nbsp;&nbsp;",$code[$i])."</td></tr>";
			}
		}

	}

	$body .= "</table>";
	$body .= "<br/>";
	$body .= "<b style=\"font-size:13px;font-family:verdana;color:darkred\">Execution backtrace: </b>";

	// backtrace
	$bt = debug_backtrace();  // keep args for parity
	$html_bt = "<table width=\"100%\" style=\"font-size:10pt;font-family:verdana\">";
	$count = 0;

	foreach ($bt as $frame) {
		if ($count === 0) { $count++; continue; } // skip the handler frame itself
		$bgcolor = ($count++ % 2 === 0) ? "#dedede" : "#efefef";

		$file = isset($frame['file']) ? $frame['file'] : '[internal]';
		$line = isset($frame['line']) ? $frame['line'] : 0;
		$func = isset($frame['function']) ? $frame['function'] : '[unknown]';

		// Format args briefly
		$args = '';
		if (isset($frame['args'])) {
			$safeArgs = [];
			foreach ((array)$frame['args'] as $a) {
				if (is_scalar($a) || $a === null) {
					$safeArgs[] = var_export($a, true);
				} elseif (is_array($a)) {
					$safeArgs[] = '[array]';
				} elseif (is_object($a)) {
					$safeArgs[] = '[object '.get_class($a).']';
				} elseif (is_resource($a)) {
					$safeArgs[] = '[resource]';
				} else {
					$safeArgs[] = '[unknown]';
				}
			}
			$args = htmlspecialchars(implode(', ', $safeArgs));
		}

		$fileline = htmlspecialchars("{$file}:{$line}");
		$func = htmlspecialchars($func);

		$html_bt .= "<tr><td bgcolor=\"{$bgcolor}\" style=\"color:#666666;font-size:13px;font-family:verdana\"><i>{$fileline}</i></td>
					 <td width=\"100%\" bgcolor=\"{$bgcolor}\" style=\"color:black;font-size:13px;font-family:verdana\">{$func}(<b>{$args}</b>)</td></tr>";
	}
	$html_bt .= "</table>";
	$body .= $html_bt;
	$body .= "<br/>";

	if (isset($_SESSION) && (count($_SESSION)!=0))
	{
		// session variables
		$body .=  MRG_error_handler_list_HTML("Session Variables:",$_SESSION);
	}

	if (isset($_POST) && (count($_POST)!=0))
	{
		// posted variables
		$body .=  MRG_error_handler_list_HTML("POST Variables:",$_POST);
	}

	if (isset($_GET) && (count($_GET)!=0))
	{
		// get variables
		$body .=  MRG_error_handler_list_HTML("GET Variables:",$_GET);
	}

	// $_SERVER contents
	if (isset($_SERVER) && (count($_SERVER)!=0))
	{
		// server variables
		$body .=  MRG_error_handler_list_HTML("SERVER Variables:",$_SERVER);
	}

	// DEFINED variables
	$const = get_defined_vars();
	unset($const["html"]);
	unset($const["body"]);
	unset($const["body_short"]);

	if (isset($const) && (count($const)!=0))
	{
		// server variables
		$body .=  MRG_error_handler_list_HTML("USER Defined Variables:",$const);
	}

	// functions in scope
	$funct = get_defined_functions();
	$funct = @$funct["user"];

	if (isset($funct) && (count($funct)!=0))
	{
		// server variables
		$body .=  MRG_error_handler_list_HTML("USER Defined Functions:",$funct);
	}

	// Sending email
	if (MRGERR_DEBUG_SENDMAIL==true)
	{

		// To send HTML mail, the Content-type header must be set
		$headers  = "MIME-Version: 1.0\r\n";
		$headers .= "Content-type: text/html; charset=iso-8859-1\r\n";

		// Mail it
	   $fd = fsockopen (MRGERR_SMTP_SERVER, 25);
	   $failed = false;

 	   if ($fd) {

			fputs($fd, "HELO foreigner\n");
	  	 	$res=fgets($fd,256);
	  	 	if(substr($res,0,3) == "220")	$res=fgets($fd,256);
  			if(substr($res,0,3) != "250") { print $res; $failed = true; }

	   	fputs($fd, "MAIL FROM: <".MRGERR_SMTP_MAILFROM.">\n");
	   	$res=fgets($fd,256);
	  	if(substr($res,0,3) != "250") $failed = true;

	   	fputs($fd, "RCPT TO: <".MRGERR_SMTP_MAILTO.">\n");
	   	$res=fgets($fd,256);
	 	   if(substr($res,0,3) != "250") $failed = true;

	  	fputs($fd, "DATA\n");
	  	$res=fgets($fd,256);
	  	if(substr($res,0,3) != "354") $failed = true;

		   fputs($fd, "To: ".MRGERR_SMTP_MAILTO."\nFrom: ".MRGERR_SMTP_MAILFROM."\nSubject: ".$errstr." in ".$errfile.":".$errline."\n$headers\n\n".$body."\n.\n");
  			$res=fgets($fd,256);
  			if(substr($res,0,3) != "250") $failed = true;

			fputs($fd,"QUIT\n");
			$res=fgets($fd,256);
  			if(substr($res,0,3) != "221") $failed = true;

			if ($failed == false)
			{
				$body_short .= "<div align=\"center\" style=\"font-size:14pt;font-family:verdana;color:blue;\"><b>A complete report was sent to the code maintainer</b></div><br/>";
				$body .= "<div align=\"center\" style=\"font-size:14pt;font-family:verdana;color:blue;\"><b>A complete report was sent to the code maintainer</b></div><br/>";
			}

		}

		/////////
	}

	if (MRGERR_DEBUG_VERBOSE==true) {
		$html = str_replace("{body}",$body,$html);
	} else {
		$html = str_replace("{body}",$body_short,$html);
	}

    // --- Send a concise notification via chosen channel ---
    $subject = $title;

	// Before finishing, send concise notification via UDP/file
    $url = !empty($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    $short = basename($errfile) . ':' . $errline . ($url ? " [$url]" : '');
    MRG_notify($title, $short);

    if (MRGERR_DEBUG_VERBOSE==true) {
        $html = str_replace("{body}",$body,$html);
    } else {
        $html = str_replace("{body}",$body_short,$html);
    }
    die($html);
}

function MRG_notify($subject, $text) {
    $method = MRGERR_NOTIFY_METHOD;

    if ($method === 'udp') {
        $host = MRGERR_NOTIFY_UDP_HOST;
        $port = (int)MRGERR_NOTIFY_UDP_PORT;
        $payload = '[' . date('Y-m-d H:i:s') . "] $subject - $text";
        $errno = 0; $errstr = '';
        $fp = @fsockopen("udp://{$host}", $port, $errno, $errstr, 1.0);
        if ($fp) { @fwrite($fp, $payload); @fclose($fp); }
        return;
    }

    if ($method === 'file') {
        $line = '[' . date('Y-m-d H:i:s') . "] $subject - $text\n";
        @error_log($line, 3, MRGERR_LOGFILE);
        return;
    }

}

register_shutdown_function(function () {
    $e = error_get_last();
    if (!$e) return;

    // Fatal-ish errors that bypass set_error_handler
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($e['type'], $fatalTypes, true)) return;

    // Build a concise subject/text and send via UDP/file
    $typeLabel = 'Fatal Error';
    // Optional: map types to your $MRGERR_ERROR_TYPES names
    if (isset($GLOBALS['MRGERR_ERROR_TYPES'][$e['type']][1])) {
        $typeLabel = $GLOBALS['MRGERR_ERROR_TYPES'][$e['type']][1];
    }
    $subject = $typeLabel . ' : ' . $e['message'];
    $url = !empty($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    $short = basename($e['file']) . ':' . $e['line'] . ($url ? " [$url]" : '');
    if (function_exists('MRG_notify')) {
        MRG_notify($subject, $short);
    }

    // Optional minimal output so the browser doesn’t just white-screen
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo "<h3 style='font-family: sans-serif'>Server Error</h3>";
});

set_error_handler("MRG_error_handler");

?>
