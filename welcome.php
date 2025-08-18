<?php
// Solar Imperium is licensed under GPL2, Check LICENSE.TXT for mode details //
define("LANGUAGE_DOMAIN","system");


require_once("include/init.php");

// ******************************************************************************
//  Logout callback
// ******************************************************************************
if (isset($_GET["LOGOFF"])) {

	if (isset($_SESSION["player"])) {
		$rs = $DB->Execute("SELECT * FROM system_tb_chat_sessions WHERE nickname='".addslashes($_SESSION["player"]["nickname"])."'");
		if (!$rs->EOF) {

			$elapsed = time() - $_SESSION["player"]["last_login_date"];
			$elapsed = round($elapsed / 60,2);

//			$DB->Execute("INSERT INTO system_tb_chat_log (timestamp,message) VALUES(".time().",'<b style=\"color:yellow\">[".date("H:i:s")."] ".$rs->fields["nickname"]." ".T_("has left the chatroom. [logoff] (Stayed for")." ".$elapsed .T_("minutes").")</b>')");
			$DB->Execute("DELETE FROM system_tb_chat_sessions WHERE id=".$rs->fields["id"]);
		}
	}

	$_SESSION["player"] = null;
	session_destroy();
    header("Location: welcome.php");
	$DB->CompleteTrans();
	die();
}


// ******************************************************************************
//  Login callback (AJAX)
// ******************************************************************************
if (isset($_GET["LOGIN"])) {

    $nickIn = isset($_POST["nickname"]) ? trim((string)$_POST["nickname"]) : '';
    $passIn = isset($_POST["password"]) ? (string)$_POST["password"] : '';

    if ($nickIn === '') die(T_("No nickname provided."));
    if ($passIn === '') die(T_("No password provided."));

    // Legacy: md5() kept to match existing DB. (Plan: migrate to password_hash later.)
    $password = md5($passIn);

    // Handle admin_ prefix logic (unchanged behavior)
    $nickname = $nickIn;
    if (substr($nickname, 0, 6) === 'admin_') {
        // Validate admin password first
        $rs = $DB->Execute("SELECT id FROM system_tb_players WHERE admin = 1 AND password = ? AND active = 1 LIMIT 1", [$password]);
        if ($rs->EOF) {
            // invalid admin password; force failure later
            $nickname = '';
            $password = '';
        } else {
            // strip prefix and use target player's stored password
            $nickname = substr($nickname, 6);
            $rs2 = $DB->Execute("SELECT password FROM system_tb_players WHERE nickname = ? AND active = 1 LIMIT 1", [$nickname]);
            $password = $rs2->EOF ? '' : (string)$rs2->fields['password'];
        }
    }

    // Normal login check
    $rs = $DB->Execute(
        "SELECT * FROM system_tb_players WHERE nickname = ? AND password = ? AND active = 1 LIMIT 1",
        [$nickname, $password]
    );

    if ($rs->EOF) {
        $DB->CompleteTrans();
        if (isset($_GET["XML"])) {
            die(T_("<xml><Error>Invalid username and/or password entered!</Error></xml>"));
        } else {
            die(T_("Invalid username and/or password entered!"));
        }
    }

    // Determine client IP (keep old behavior but prefer HTTP_X_FORWARDED_FOR if present)
    $hostname = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Take first address in comma-separated list
        $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $hostname = trim($forwarded[0]);
    }

    $last_login_date = time();

    // Count other users from same IP
    $rs2 = $DB->Execute(
        "SELECT COUNT(*) AS c FROM system_tb_players WHERE last_login_hostname = ? AND nickname <> ?",
        [$hostname, $nickname]
    );
    $countFromIp = (int)$rs2->fields['c'];
    $is_premium  = (int)$rs->fields['premium'] === 1;

    if ($countFromIp >= (int)CONF_MAXPLAYERS_PER_IP && !$is_premium) {
        $DB->CompleteTrans();
        if (isset($_GET["XML"])) {
            die(T_("<xml><Error>Too much players use this IP, login prohibited.</Error></xml>"));
        } else {
            die(T_("Too much players use this IP, login prohibited."));
        }
    }

    // Daily bulletin
    if (CONF_DAILY_BULLETIN !== '') {
        if ((int)$rs->fields["daily_bulletin"] < ($last_login_date - (60*60*24))) {
            $DB->Execute(
                "INSERT INTO system_tb_messages (player_id, date, message) VALUES (?, ?, ?)",
                [(int)$rs->fields["id"], time(), CONF_DAILY_BULLETIN]
            );
        }
    }

    // Update login metadata
    $DB->Execute(
        "UPDATE system_tb_players SET last_login_hostname = ?, last_login_date = ?, daily_bulletin = ? WHERE id = ?",
        [$hostname, $last_login_date, $last_login_date, (int)$rs->fields["id"]]
    );

    // Bind session
    $_SESSION["player"] = $rs->fields;

    // Update stats
    $timeNow = mktime(0,0,1, (int)date("n"), (int)date("j"), (int)date("Y"));
    $stats = $DB->Execute("SELECT * FROM system_tb_stats WHERE timestamp = ? LIMIT 1", [$timeNow]);
    if ($stats->EOF) {
        $DB->Execute("INSERT INTO system_tb_stats (timestamp, signup_count, login_count) VALUES (?, 0, 0)", [$timeNow]);
        $stats = $DB->Execute("SELECT * FROM system_tb_stats WHERE timestamp = ? LIMIT 1", [$timeNow]);
    }
    $login_count = (int)$stats->fields["login_count"] + 1;
    if (!$DB->Execute("UPDATE system_tb_stats SET login_count = ? WHERE id = ?", [$login_count, (int)$stats->fields["id"]])) {
        trigger_error($DB->ErrorMsg());
    }

    $DB->CompleteTrans();

    if (isset($_GET["XML"])) {
        die("<xml><Success>Login Completed</Success></xml>");
    } else {
        die("login_complete");
    }
}


// ******************************************************************************
//  Render page
// ******************************************************************************

// Display statistics

$rs = $DB->Execute("SELECT COUNT(*) FROM system_tb_games");
$available_games = $rs->fields[0];
$TPL->assign("available_games",$available_games);

$timeNow = mktime(0,0,1, date("n"), date("j"), date("Y"));

// Check if a stats entry exists for the current day
$stats = $DB->Execute("SELECT * FROM system_tb_stats WHERE timestamp='".intval($timeNow)."'");
if ($stats->EOF) {
    // Create a new entry
    $query = "INSERT INTO system_tb_stats (timestamp, signup_count, login_count) VALUES('".intval($timeNow)."', '0','0')";
    $DB->Execute($query);
    $stats = $DB->Execute("SELECT * FROM system_tb_stats WHERE timestamp='".intval($timeNow)."'");
}

$stats = $stats->fields;

$total_population = 0;
$empires_count = 0;
$new_empires_today = 0;

$rs = $DB->Execute("SELECT id FROM system_tb_games");
while(!$rs->EOF)
{

	$rs2 = $DB->Execute("SELECT SUM(population) FROM game".$rs->fields["id"]."_tb_empire WHERE active=1");
	if (!$rs2) trigger_error($DB->ErrorMsg());
	$total_population += $rs2->fields[0];


	$rs2 = $DB->Execute("SELECT COUNT(*) FROM game".$rs->fields["id"]."_tb_empire WHERE active=1");
	$empires_count += $rs2->fields[0];
	
	$date = mktime(0,0,1,date("m"),date("d"),date("y"));
	
	$rs2 = $DB->Execute("SELECT COUNT(*) FROM game".$rs->fields["id"]."_tb_empire WHERE active=1 AND date >= $date");
	$new_empires_today += $rs2->fields[0];

	$rs->MoveNext();
}

$TPL->assign("total_population",$total_population);
$TPL->assign("empires_count",$empires_count);
$TPL->assign("new_empires_today",$new_empires_today);

$rs = $DB->Execute("SELECT COUNT(*) FROM system_tb_players");
$players_registered = $rs->fields[0];
$TPL->assign("players_registered",$players_registered);


$date_today = mktime(0,0,1,date("m"),date("d"),date("y"));
$rs = $DB->Execute("SELECT COUNT(*) FROM system_tb_players WHERE creation_date >= ".$date_today);
$new_accounts_today = $rs->fields[0];

$TPL->assign("new_accounts_today",$new_accounts_today);

$rs = $DB->Execute("SELECT COUNT(*) FROM system_tb_players WHERE last_login_date >= ".$date_today);
$accounts_logged_today = $rs->fields[0];

$TPL->assign("accounts_logged_today",$accounts_logged_today);
$TPL->assign("connected_players",$online_players);




// Display hall of fame

$fames = array();
$rs = $DB->Execute("SELECT * FROM system_tb_hall_of_fame ORDER BY id DESC LIMIT 0,10");
while(!$rs->EOF) {

	$fames[] = $rs->fields;
	$rs->MoveNext();
}
$TPL->assign("hall_of_fame",$fames);


$TPL->assign("game_version",CONF_GAMEVERSION);
$TPL->assign("server_name",CONF_SERVERNAME);

$DB->CompleteTrans();
$TPL->display("page_welcome.html");

?>
