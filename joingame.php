<?php
// Solar Imperium is licensed under GPL2, Check LICENSE.TXT for mode details //

define("LANGUAGE_DOMAIN","system");

require_once("include/init.php");

if (!function_exists('dbg_udp')) {
    function dbg_udp($msg) {
        $h = '10.8.0.22'; $p = 50001;
        $pref = '[joingame.php] ';
        $errno = 0; $errstr = '';
        $fp = @fsockopen("udp://$h", $p, $errno, $errstr, 0.5);
        if ($fp) { @fwrite($fp, $pref . $msg); @fclose($fp); }
    }
}

function finish_and_redirect($url) {
    global $DB;
    // If you started a transaction globally, this will commit it
    // If none is active, ADOdb just returns
    @$DB->CompleteTrans();
    header("Location: $url");
    exit;
}

function db_exec_or_die($sql, $context='') {
    global $DB;
    $ok = $DB->Execute($sql);
    if (!$ok) {
        $msg = $DB->ErrorMsg();
        if (function_exists('dbg_udp')) dbg_udp("[db] $context :: $msg");
        error_log("[DB] $context :: $msg :: $sql");
        die("Database error ($context).");
    }
    return $ok;
}


dbg_udp("hit " . ($_SERVER['REQUEST_URI'] ?? ''));
dbg_udp("session=".(isset($_SESSION['player'])?'yes':'no')." admin=".($_SESSION['player']['admin']??'NA'));


if (!isset($_SESSION["player"])) {
	$DB->CompleteTrans();
	die(header("Location: welcome.php"));
}
	
if (!isset($_GET["GAME"])) {
	$DB->CompleteTrans();
	die(T_("Invalid GAME ID!"));
}

$rs = $DB->Execute("SELECT * FROM system_tb_games WHERE id=".intval($_GET["GAME"]));
if ($rs->EOF) {
	$DB->CompleteTrans();
	die(T_("Invalid GAME ID!"));
}
$game_data = $rs->fields;


// **************************************************************
// Join now callback
// **************************************************************
if (isset($_GET['JOINNOW']) && isset($_POST['empire_name'])) {
    dbg_udp("JOINNOW param seen");

    $game_id = (int)($_GET['GAME'] ?? 0);
    dbg_udp("GAME id=".$game_id);

    // Sanitize inputs (light touch to keep original behavior)
    $empire_name  = addslashes((string)$_POST['empire_name']);
    $emperor_name = addslashes((string)$_POST['emperor_name']);
    $gender       = addslashes((string)$_POST['gender']);
    $autobio      = addslashes((string)$_POST['autobiography']);

    // Common redirect helper
    $redir_base = "joingame.php?GAME={$game_id}&WARNING=";

    // Basic validations
    if ($empire_name === '') {
        $DB->CompleteTrans();
        finish_and_redirect($redir_base . urlencode(T_("Invalid empire name!")));
    }
    if ($emperor_name === '') {
        $DB->CompleteTrans();
        finish_and_redirect($redir_base . urlencode(T_("Invalid emperor/emperess name!")));
    }
    if ($gender === '') {
        $DB->CompleteTrans();
        finish_and_redirect($redir_base . urlencode(T_("Invalid gender!")));
    }
    if ($autobio === '') {
        $autobio = T_("--- No biography defined ---");
    }

    // Game must be reset (coordinator row must exist)
    $rs = $DB->Execute("SELECT * FROM game{$game_id}_tb_coordinator");
    if ($rs === false || $rs->EOF) {
        $DB->CompleteTrans();
        finish_and_redirect($redir_base . urlencode(T_("Game not resetted yet!")));
    }

    // Unique emperor name
    $rs = $DB->Execute("SELECT 1 FROM game{$game_id}_tb_empire WHERE emperor='{$emperor_name}'");
    if ($rs === false) {
        db_exec_or_die("--noop--", "select emperor uniqueness"); // will die — context only
    }
    if (!$rs->EOF) {
        $DB->CompleteTrans();
        finish_and_redirect($redir_base . urlencode(T_("Emperor/emperess name already in use!")));
    }

    // Unique empire name
    $rs = $DB->Execute("SELECT 1 FROM game{$game_id}_tb_empire WHERE name='{$empire_name}'");
    if ($rs === false) {
        db_exec_or_die("--noop--", "select empire name uniqueness"); // will die — context only
    }
    if (!$rs->EOF) {
        $DB->CompleteTrans();
        finish_and_redirect($redir_base . urlencode(T_("Empire name already in use!")));
    }

    // Premium-only gate
    if (!empty($game_data['premium_only'])) {
        if (empty($_SESSION['player']['premium'])) {
            $DB->CompleteTrans();
            finish_and_redirect($redir_base . urlencode(T_("You need to be a premium member to join this game!")));
        }
    }

    // Capacity check
    dbg_udp("checking capacity");
    $rs = $DB->Execute("SELECT COUNT(*) AS c FROM game{$game_id}_tb_empire WHERE active < 2");
    if ($rs === false) {
        db_exec_or_die("--noop--", "count active empires"); // will die — context only
    }
    if ((int)$rs->fields['c'] >= (int)$game_data['max_players']) {
        $DB->CompleteTrans();
        finish_and_redirect($redir_base . urlencode(T_("Too much players, this game is full!")));
    }

    // Find a free starmap position
    $x = 0; $y = 0;
    do {
        $x = -1000 + (rand(0, 40) * 50);
        $y = -1000 + (rand(0, 40) * 50);
        $rs = $DB->Execute(
            "SELECT 1 FROM game{$game_id}_tb_empire
             WHERE (x BETWEEN ".($x-50)." AND ".($x+50).")
               AND (y BETWEEN ".($y-50)." AND ".($y+50).")
               AND active < 2"
        );
        if ($rs === false) {
            db_exec_or_die("--noop--", "starmap collision check"); // will die — context only
        }
        dbg_udp("selected from system_tb_games and game{$game_id}_tb_coordinator");
    } while (!$rs->EOF);

    // Pick a default logo (don’t overwrite the array itself)
    $logo_value = '';
    if (!empty($default_logo) && is_array($default_logo)) {
        $logo_value = $default_logo[array_rand($default_logo)];
    }

    $premium = (int)!empty($_SESSION['player']['premium']);
    $now     = time();

    // Insert empire
    dbg_udp("inserting human empire");
	$ai_level = 0; // humans are level 0
    $query = "
        INSERT INTO game{$game_id}_tb_empire (
			player_id, ai_level,
			emperor, name, gender, logo, biography, active,
			date, last_turn_date, turns_left, protection_turns_left,
			credits, last_credits, population, food,
			x, y, premium, food_rate, ore_rate, petroleum_rate,
			attacked_by
		) VALUES (
			".(int)$_SESSION['player']['id'].", {$ai_level},
			'{$emperor_name}', '{$empire_name}', '{$gender}', '{$logo_value}', '{$autobio}', 1,
			{$now}, {$now},
			".(int)$game_data['turns_per_day'].", ".(int)$game_data['protection_turns'].",
			".(int)CONF_START_CREDITS.", ".(int)CONF_START_CREDITS.", ".(int)CONF_START_POPULATION.", ".(int)CONF_START_FOOD.",
			{$x}, {$y}, {$premium},
			".(int)CONF_DEFAULT_AUTOSELL_RATE.", ".(int)CONF_DEFAULT_AUTOSELL_RATE.", ".(int)CONF_DEFAULT_AUTOSELL_RATE.",
			0
		);
    ";
    db_exec_or_die($query, 'insert-human-empire');

    $id = (int)$DB->Insert_ID();
    dbg_udp("human empire id=".$id);
    if ($id <= 0) {
        if (function_exists('dbg_udp')) dbg_udp("[db] unexpected insert_id=0 after human insert");
        $DB->CompleteTrans();
        finish_and_redirect($redir_base . urlencode(T_("Internal error creating empire.")));
    }

    // Production row
    db_exec_or_die("INSERT INTO game{$game_id}_tb_production (empire) VALUES ({$id})",
                   'insert-production');

    // Supply row (original code only set rate_soldier)
    db_exec_or_die("INSERT INTO game{$game_id}_tb_supply (empire, rate_soldier) VALUES ({$id}, 100)",
                   'insert-supply');

    // Planets row
    $q_planets = "
        INSERT INTO game{$game_id}_tb_planets (
            empire, food_planets, ore_planets, tourism_planets, supply_planets,
            gov_planets, edu_planets, research_planets, urban_planets,
            petro_planets, antipollu_planets
        ) VALUES (
            {$id},
            ".(int)CONF_START_FOOD_PLANETS.",
            ".(int)CONF_START_ORE_PLANETS.",
            ".(int)CONF_START_TOURISM_PLANETS.",
            ".(int)CONF_START_SUPPLY_PLANETS.",
            ".(int)CONF_START_GOV_PLANETS.",
            ".(int)CONF_START_EDU_PLANETS.",
            ".(int)CONF_START_RESEARCH_PLANETS.",
            ".(int)CONF_START_URBAN_PLANETS.",
            ".(int)CONF_START_PETRO_PLANETS.",
            ".(int)CONF_START_ANTIPOLLU_PLANETS."
        );
    ";
    db_exec_or_die($q_planets, 'insert-planets');

    // Army row
    $q_army = "
        INSERT INTO game{$game_id}_tb_army (empire, soldiers, fighters, stations)
        VALUES ({$id}, ".(int)CONF_START_SOLDIERS.", ".(int)CONF_START_FIGHTERS.", ".(int)CONF_START_STATIONS.");
    ";
    db_exec_or_die($q_army, 'insert-army');

    // Notify other empires about the new empire
    $evt_type   = (int)CONF_EVENT_NEWEMPIRE;
    $evt_from   = $id;
    $evt_params = addslashes(serialize([
        'empire_emperor' => stripslashes($emperor_name),
        'empire_name'    => stripslashes($empire_name),
        'gender'         => stripslashes($gender),
    ]));
    $evt_sticky = 0;
    $evt_seen   = 0;
    $evt_height = 160;

    $recipients = $DB->Execute("SELECT id FROM game{$game_id}_tb_empire WHERE active=1");
    if ($recipients === false) {
        db_exec_or_die("--noop--", "select recipients"); // will die — context only
    }
    while (!$recipients->EOF) {
        $to = (int)$recipients->fields['id'];
        $q_evt = "
            INSERT INTO game{$game_id}_tb_event
                (event_type, event_from, event_to, params, seen, sticky, date, height)
            VALUES
                ({$evt_type}, {$evt_from}, {$to}, '{$evt_params}', {$evt_seen}, {$evt_sticky}, {$now}, {$evt_height})
        ";
        db_exec_or_die($q_evt, "insert-event to={$to}");
        $recipients->MoveNext();
    }

    // Garbage collect events
    $timeout_unseen = $now - (int)CONF_UNSEEN_EVENT_TIMEOUT;
    $timeout_seen   = $now - (int)CONF_SEEN_EVENT_TIMEOUT;

    db_exec_or_die(
        "DELETE FROM game{$game_id}_tb_event WHERE date < {$timeout_unseen} AND seen=0",
        'gc-events-unseen'
    );
    db_exec_or_die(
        "DELETE FROM game{$game_id}_tb_event WHERE date < {$timeout_seen} AND seen=1",
        'gc-events-seen'
    );

    dbg_udp("join complete; redirecting");
    $DB->CompleteTrans();
    finish_and_redirect("gamesbrowser.php?SUCCESS");
}


// ***************************************************
// Display page
// ***************************************************

$TPL->assign("game_id",$_GET["GAME"]);

$DB->CompleteTrans();
$TPL->display("page_joingame.html");

?>
