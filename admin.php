<?php
// Solar Imperium is licensed under GPL2, Check LICENSE.TXT for mode details //

define("LANGUAGE_DOMAIN","system");
require_once ("include/init.php");

// --- DEBUG: minimal UDP notifier (bypasses error handler entirely) ---
if (!function_exists('dbg_udp')) {
    function dbg_udp($msg) {
        $h = '10.8.0.22'; $p = 50001;
        $pref = '[admin.php] ';
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

// --- Helpers for SELECTs (place near db_exec_or_die) -------------------------
if (!function_exists('db_select_or_die')) {
    function db_select_or_die($sql, $context = '') {
        global $DB;
        $rs = $DB->Execute($sql);
        if (!$rs) {
            $msg = $DB->ErrorMsg();
            if (function_exists('dbg_udp')) dbg_udp("[db] $context :: $msg");
            error_log("[DB] $context :: $msg :: $sql");
            die("Database error ($context).");
        }
        return $rs;
    }
}

// check if the player is logged
if (!isset ($_SESSION["player"])) {
	$DB->CompleteTrans(); 
	finish_and_redirect('admin.php');   
}

// check if the player is admin
if ($_SESSION["player"]["admin"] != 1) {
	$DB->CompleteTrans(); 
	die(T_("You need to be an admin!"));
}


// *********************************************************************************
// Delete empire callback
// *********************************************************************************
if (isset($_GET["DELETE_EMPIRE"])) {
    // do nothing for now
}


// *********************************************************************************
// Post message callback
// *********************************************************************************
if (isset($_GET["POSTMSG"])) {
	$message = $_POST["message"];
	$message = str_replace("<","(",$message);
	$message = str_replace(">",")",$message);

	if ($message != "") {
		$players = $DB->Execute("SELECT * FROM system_tb_players");
		while(!$players->EOF) {
			$DB->Execute("INSERT INTO system_tb_messages (player_id,date,message) VALUES(".$players->fields["id"].",".time().",'".utf8_encode(addslashes($message))."')");
			$players->MoveNext();
		}
	}
	$DB->CompleteTrans(); 

	finish_and_redirect('admin.php');   
}

// *********************************************************************************
// Update game description callback
// *********************************************************************************
if (isset($_GET["UPDATE_DESCRIPTION"])) {
	$game_id = intval($_GET["UPDATE_DESCRIPTION"]);
	$description = "";
	if (isset($_POST["description"]))
		$description = (addslashes($_POST["description"]));

	$DB->Execute("UPDATE system_tb_games SET description='$description' WHERE id='$game_id'");
	$DB->CompleteTrans(); 
	finish_and_redirect('admin.php');   
}

// *********************************************************************************
// Reset rules callback
// *********************************************************************************
if (isset ($_GET["RESETRULES"])) {

	$game_id = intval($_GET["RESETRULES"]);

	copy("include/game/rules_orig.php","include/game/games_rules/" . $game_id . ".php");
	$DB->CompleteTrans(); 
	finish_and_redirect('admin.php');   
}

// *********************************************************************************
// Update rules callback
// *********************************************************************************
if (isset ($_GET["UPDATERULES"])) {


	$fd = fopen("include/game/games_rules/".intval($_GET["UPDATERULES"]).".php","w");
	$filedata = stripslashes($_POST["filedata"]);
	$filedata = str_replace("_?php","<?php",$filedata);
    
	if (strpos($filedata,"<?php") === false) {
		die(T_("Invalid rules sent."));
	} else {
		$filedata = substr($filedata,strpos($filedata,"<?php"));

		if (strpos($filedata,"?>") === false) {
			die(T_("Invalid rules sent."));
		} else {
			$filedata = substr($filedata,0,strpos($filedata,"?>")+2);
		}
	}


	fwrite($fd,stripslashes($filedata));
	fclose($fd);
	$DB->CompleteTrans(); 
	finish_and_redirect('admin.php');   

}


// *********************************************************************************
// Edit rules callback
// *********************************************************************************
if (isset ($_GET["EDITRULES"])) {

	$game_id = intval($_GET["EDITRULES"]);
	
	$filename = "include/game/games_rules/".$game_id.".php";
	$fd = fopen($filename,"r");
    $fsize = filesize($filename);
    $filedata = "";
    if ($fsize != 0)
    	$filedata = fread($fd,$fsize);
	fclose($fd);

	$TPL->assign("game_id",$game_id);
	$TPL->assign("file_content",$filedata);

	$TPL->display("page_admin_editrules.html");
	$DB->CompleteTrans(); 
	die();

}

// *********************************************************************************
// Add AI Callback
// *********************************************************************************
if (isset($_GET['ADDAI'])) {
    // ── INPUT & BASIC LOGGING ────────────────────────────────────────────────
    $game_id  = (int)($_GET['ADDAI'] ?? 0);
    $ai_level = (int)($_POST['ai_level'] ?? 1);
    // keep AI level reasonable; original UI offers 1/5/10
    if ($ai_level < 1)  $ai_level = 1;
    if ($ai_level > 10) $ai_level = 10;

    dbg_udp("ADDAI hit; game_id={$game_id}");
    dbg_udp("ai_level={$ai_level}");

    // ── PRECHECKS ────────────────────────────────────────────────────────────
    $rs        = db_select_or_die("SELECT * FROM system_tb_games WHERE id='{$game_id}'", 'fetch-game');
    if ($rs->EOF) { $DB->CompleteTrans(); die(T_("Game not found.")); }
    $game_data = $rs->fields;

    dbg_udp("game {$game_id} max_players={$game_data['max_players']}");

    $rs = db_select_or_die("SELECT COUNT(*) AS n FROM game{$game_id}_tb_empire", 'count-empires');
    dbg_udp("current empires=".$rs->fields['n']);
    if ((int)$rs->fields['n'] >= (int)$game_data['max_players']) {
        $DB->CompleteTrans();
        die(T_("Cannot add computer component, max players reached!"));
    }

    $rs = db_select_or_die("SELECT * FROM game{$game_id}_tb_coordinator", 'check-coordinator');
    dbg_udp("coordinator rows: ".($rs->EOF ? 0 : 1));
    if ($rs->EOF) {
        $DB->CompleteTrans();
        die(T_("Game not resetted yet!"));
    }

    // ── NAME GENERATION (UNIQUE) ─────────────────────────────────────────────
    $empire_rand1 = [
        'Corporate','Master','Space','Solar','Ralos','Blorgz','Heavy',
        'Hyperdrive','USR','Rogue','Death','Star','Galactic','Cyber',
        'Peaceful','Power','Kickass','Elite','Evil'
    ];
    $empire_rand2 = [
        'Muirepmi','Imperium','Corporation','Associates','Industries','Robotics',
        'Nation','Squadron','Destroyers','Hyperion','Factories','Dominion'
    ];
    $emperor_rand1 = [
        'Nafarious','Necro','Infamous','Illarious','Mad','Bloody','Imperial','Goth',
        'Chief','Undead','Star','Evil','Good','Nasty','Marvellous','Lord','Solar'
    ];
    $emperor_rand2 = [
        'Leader','Warrrior','Vampire','PlanetBuster','Monster','JailKeeper','Jack',
        'Cleon I','Bevatron','Lord','Gurney','Jackal','Destroyer','Master','Chief'
    ];

    $empire_name  = '';
    $emperor_name = '';
    for ($tries = 0; $tries < 1000; $tries++) {
        $empire_name  = $empire_rand1[array_rand($empire_rand1)]   . ' ' . $empire_rand2[array_rand($empire_rand2)];
        $emperor_name = $emperor_rand1[array_rand($emperor_rand1)] . ' ' . $emperor_rand2[array_rand($emperor_rand2)];

        $safe_empire  = addslashes($empire_name);
        $safe_emperor = addslashes($emperor_name);

        $rs = db_select_or_die(
            "SELECT id FROM game{$game_id}_tb_empire WHERE emperor='{$safe_emperor}' OR name='{$safe_empire}'",
            'check-unique-ai-names'
        );
        if ($rs->EOF) break; // unique
    }
    if ($tries >= 1000) { die(T_("Fatal error while creating new empire!")); }

    // ── SPAWN POSITION (avoid 50px radius collision) ────────────────────────
    $x = 0; $y = 0;
    do {
        $x = -1000 + (rand(0, 40) * 50);
        $y = -1000 + (rand(0, 40) * 50);
        $rs = db_select_or_die(
            "SELECT id FROM game{$game_id}_tb_empire
             WHERE (x BETWEEN ".($x-50)." AND ".($x+50).")
               AND (y BETWEEN ".($y-50)." AND ".($y+50).")
               AND active < 2",
            'spawn-collision-check'
        );
    } while (!$rs->EOF);

    // ── LOGO / BIO / FLAGS ──────────────────────────────────────────────────
    // Pull a random ASCII logo from config (global $default_logo array)
    if (!isset($default_logo) || !is_array($default_logo) || empty($default_logo)) {
        // Fallback if missing
        $logo_pick = '';
    } else {
        $logo_pick = $default_logo[array_rand($default_logo)];
    }
    $premium = 0;
    $gender  = (rand(0,1) === 0 ? 'M' : 'F');
    $autobio = T_("Resistance is futile!");

    // ── INSERT AI EMPIRE (STRICT MODE SAFE) ─────────────────────────────────
    dbg_udp("inserting AI empire");

    $now = time();
    $sql = "
        INSERT INTO game{$game_id}_tb_empire
        (
            player_id, ai_level,
            emperor, name, gender, logo, biography,
            active, date, last_turn_date,
            turns_left, protection_turns_left,
            credits, last_credits, population, food,
            x, y, premium,
            food_rate, ore_rate, petroleum_rate,
            attacked_by
        ) VALUES (
            -1, {$ai_level},
            '".addslashes($emperor_name)."',
            '".addslashes($empire_name)."',
            '".addslashes($gender)."',
            '".addslashes($logo_pick)."',
            '".addslashes($autobio)."',
            1, {$now}, {$now},
            ".((int)$game_data['turns_per_day'] * 4).",
            ".(int)$game_data['protection_turns'].",
            ".(int)CONF_START_CREDITS.",
            ".(int)CONF_START_CREDITS.",
            ".(int)CONF_START_POPULATION.",
            ".(int)CONF_START_FOOD.",
            {$x}, {$y}, {$premium},
            ".(int)CONF_DEFAULT_AUTOSELL_RATE.",
            ".(int)CONF_DEFAULT_AUTOSELL_RATE.",
            ".(int)CONF_DEFAULT_AUTOSELL_RATE.",
            0
        );
    ";
    db_exec_or_die($sql, 'insert-ai-empire');

    $id = (int)$DB->Insert_ID();
    dbg_udp("AI empire inserted id={$id}");

    // ── INSERT RELATED ROWS (production / supply / planets / army) ──────────
    db_exec_or_die(
        "INSERT INTO game{$game_id}_tb_production (empire) VALUES ({$id})",
        'insert-ai-production'
    );

    db_exec_or_die(
        "INSERT INTO game{$game_id}_tb_supply
         (empire, rate_soldier, rate_fighter, rate_station, rate_heavycruiser, rate_carrier, rate_covert, rate_credits)
         VALUES ({$id}, 15, 15, 15, 15, 10, 20, 10)",
        'insert-ai-supply'
    );

    db_exec_or_die("
        INSERT INTO game{$game_id}_tb_planets
        (
            empire, food_planets, ore_planets, tourism_planets, supply_planets,
            gov_planets, edu_planets, research_planets, urban_planets, petro_planets,
            antipollu_planets
        ) VALUES (
            {$id},
            ".((int)CONF_START_FOOD_PLANETS      * $ai_level).",
            ".((int)CONF_START_ORE_PLANETS       * $ai_level).",
            ".((int)CONF_START_TOURISM_PLANETS   * $ai_level).",
            ".((int)CONF_START_SUPPLY_PLANETS    * $ai_level).",
            ".((int)CONF_START_GOV_PLANETS       * $ai_level).",
            ".((int)CONF_START_EDU_PLANETS       * $ai_level).",
            ".((int)CONF_START_RESEARCH_PLANETS  * $ai_level).",
            ".((int)CONF_START_URBAN_PLANETS     * $ai_level).",
            ".((int)CONF_START_PETRO_PLANETS     * $ai_level).",
            ".((int)CONF_START_ANTIPOLLU_PLANETS * $ai_level)."
        );
    ", 'insert-ai-planets');

    db_exec_or_die("
        INSERT INTO game{$game_id}_tb_army (empire, soldiers, fighters, stations)
        VALUES ({$id},
            ".((int)CONF_START_SOLDIERS  * $ai_level).",
            ".((int)CONF_START_FIGHTERS  * $ai_level).",
            ".((int)CONF_START_STATIONS  * $ai_level)."
        );
    ", 'insert-ai-army');

    // ── BROADCAST NEW EMPIRE EVENT ──────────────────────────────────────────
    $evt_type   = (int)CONF_EVENT_NEWEMPIRE;
    $evt_from   = $id;
    $evt_params = addslashes(serialize([
        'empire_emperor' => $emperor_name,
        'empire_name'    => $empire_name,
        'gender'         => $gender,
    ]));
    $evt_seen   = 0;
    $evt_sticky = 0;
    $evt_h      = 160;

    $recipients = db_select_or_die(
        "SELECT id FROM game{$game_id}_tb_empire WHERE active=1",
        'fetch-empire-recipients'
    );
    while (!$recipients->EOF) {
        $to = (int)$recipients->fields['id'];
        db_exec_or_die(
            "INSERT INTO game{$game_id}_tb_event
             (event_type, event_from, event_to, params, seen, sticky, date, height)
             VALUES ({$evt_type}, {$evt_from}, {$to}, '{$evt_params}', {$evt_seen}, {$evt_sticky}, {$now}, {$evt_h})",
            'insert-event-new-empire'
        );
        $recipients->MoveNext();
    }

    // ── EVENT GC ─────────────────────────────────────────────────────────────
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

    // ── DONE ─────────────────────────────────────────────────────────────────
    dbg_udp("ADDAI done; redirecting");
    $DB->CompleteTrans();
    finish_and_redirect('admin.php');
}

// *********************************************************************************
// Delete game callback
// *********************************************************************************
if (isset ($_GET["DELETEGAME"])) {

	$game_id = intval($_GET["DELETEGAME"]);

	$DB->Execute("DELETE FROM system_tb_games WHERE id='$game_id'");
	if (!$DB) trigger_error($DB->ErrorMsg());

	$rs2 = $DB->Execute("SHOW TABLES LIKE 'game".$game_id."_%'");
	if (!$rs2) trigger_error($DB->ErrorMsg());

	while(!$rs2->EOF)
	{
		$DB->Execute("DROP TABLE ".$rs2->fields[0]);
		$rs2->MoveNext();
	}


	if (file_exists("include/game/games_config/" . $game_id . ".php")) unlink("include/game/games_config/" . $game_id . ".php");
	if (file_exists("include/game/games_rules/" . $game_id . ".php")) unlink("include/game/games_rules/" . $game_id . ".php");
	$DB->CompleteTrans(); 
	finish_and_redirect('admin.php');   
}

// *********************************************************************************
// Reset game callback
// *********************************************************************************
if (isset ($_GET["RESETGAME"])) {

	$game_id = intval($_GET["RESETGAME"]);

	$players = $DB->Execute("SELECT id FROM game".$game_id."_tb_empire");
	while (!$players->EOF) {

		$DB->Execute("DELETE FROM game".$game_id."_tb_army WHERE  empire='" . $players->fields["id"]."'");
		if (!$DB) trigger_error($DB->ErrorMsg());
		$DB->Execute("DELETE FROM game".$game_id."_tb_planets WHERE empire='" . $players->fields["id"]."'");
		if (!$DB) trigger_error($DB->ErrorMsg());
		$DB->Execute("DELETE FROM game".$game_id."_tb_production WHERE empire='" . $players->fields["id"]."'");
		if (!$DB) trigger_error($DB->ErrorMsg());
		$DB->Execute("DELETE FROM game".$game_id."_tb_empire WHERE id='" . $players->fields["id"]."'");
		if (!$DB) trigger_error($DB->ErrorMsg());
		$DB->Execute("DELETE FROM game".$game_id."_tb_supply WHERE empire='" . $players->fields["id"]."'");
		if (!$DB) trigger_error($DB->ErrorMsg());

		$players->MoveNext();
	}

	$DB->Execute("DELETE FROM game".$game_id."_tb_bond");
	if (!$DB) trigger_error($DB->ErrorMsg());
	$DB->Execute("DELETE FROM game".$game_id."_tb_coalition");
	if (!$DB) trigger_error($DB->ErrorMsg());
	$DB->Execute("DELETE FROM game".$game_id."_tb_event");
	if (!$DB) trigger_error($DB->ErrorMsg());
	$DB->Execute("DELETE FROM game".$game_id."_tb_loan");
	if (!$DB) trigger_error($DB->ErrorMsg());
	$DB->Execute("DELETE FROM game".$game_id."_tb_trace");
	if (!$DB) trigger_error($DB->ErrorMsg());
	$DB->Execute("DELETE FROM game".$game_id."_tb_member");
	if (!$DB) trigger_error($DB->ErrorMsg());
	$DB->Execute("DELETE FROM game".$game_id."_tb_research_done");
	if (!$DB) trigger_error($DB->ErrorMsg());
	$DB->Execute("DELETE FROM game".$game_id."_tb_session");
	if (!$DB) trigger_error($DB->ErrorMsg());
	$DB->Execute("DELETE FROM game".$game_id."_tb_invasion");
	if (!$DB) trigger_error($DB->ErrorMsg());
	$DB->Execute("DELETE FROM game".$game_id."_tb_shoutbox");
	if (!$DB) trigger_error($DB->ErrorMsg());
	$DB->Execute("DELETE FROM game".$game_id."_tb_stats");
	if (!$DB) trigger_error($DB->ErrorMsg());
	$DB->Execute("DELETE FROM game".$game_id."_tb_treaty");
	if (!$DB) trigger_error($DB->ErrorMsg());
	$DB->Execute("DELETE FROM game".$game_id."_tb_market");
	if (!$DB) trigger_error($DB->ErrorMsg());
	$DB->Execute("DELETE FROM game".$game_id."_tb_tradeconvoy");
	if (!$DB) trigger_error($DB->ErrorMsg());
	$DB->Execute("DELETE FROM game".$game_id."_tb_coordinator");
	if (!$DB) trigger_error($DB->ErrorMsg());
	$DB->Execute("INSERT INTO game".$game_id."_tb_market (last_update) VALUES(0)");
	if (!$DB) trigger_error($DB->ErrorMsg());
		
	$query = "INSERT INTO game".$game_id."_tb_coordinator (last_master) VALUES('".T_("Nobody")."')";
	$DB->Execute($query);
	if (!$DB) trigger_error($DB->ErrorMsg());
	
	$query = "UPDATE game".$game_id."_tb_coordinator SET
		date=" . time() . ",game_status=0,
		lottery_cash=0,
		lottery_date=0
	";

		
		
	$DB->Execute($query);
	if (!$DB) trigger_error($DB->ErrorMsg());

	// remove empire logos
	if (!file_exists("images/game/empires/".$game_id."/"))
			mkdir("images/game/empires/".$game_id."/");
			
	
	$dir = opendir("images/game/empires/".$game_id."/");
	while ($file = readdir($dir)) {
		if ($file[0] == ".")
			continue;
		if ($file == "new.jpg")
			continue;
		unlink("images/game/empires/".$game_id."/" . $file);
	}
	closedir($dir);

	// reset pirates
	$rs = $DB->Execute("SELECT * FROM game".$game_id."_tb_pirate");
	if (!$rs) trigger_error($DB->ErrorMsg());

	while (!$rs->EOF) {
		$pirate = $rs->fields;
		$pirate["credits"] = rand(0, CONF_PIRATE_BASEVALUE);
		$pirate["food"] = rand(0, CONF_PIRATE_BASEVALUE);
		$pirate["research"] = rand(0, CONF_PIRATE_BASEVALUE);
		$pirate["covertagents"] = rand(0, CONF_PIRATE_BASEVALUE);
		$pirate["stations"] = rand(0, CONF_PIRATE_BASEVALUE);
		$pirate["soldiers"] = rand(0, CONF_PIRATE_BASEVALUE);
		$pirate["fighters"] = rand(0, CONF_PIRATE_BASEVALUE);
		$pirate["lightcruisers"] = rand(0, CONF_PIRATE_BASEVALUE);
		$pirate["heavycruisers"] = rand(0, CONF_PIRATE_BASEVALUE);
		$pirate["carriers"] = rand(0, CONF_PIRATE_BASEVALUE);
		$pirate["food_planets"] = rand(0, CONF_PIRATE_BASEVALUE);
		$pirate["ore_planets"] = rand(0, CONF_PIRATE_BASEVALUE);
		$pirate["tourism_planets"] = rand(0, CONF_PIRATE_BASEVALUE);
		$pirate["supply_planets"] = rand(0, CONF_PIRATE_BASEVALUE);
		$pirate["gov_planets"] = rand(0, CONF_PIRATE_BASEVALUE);
		$pirate["edu_planets"] = rand(0, CONF_PIRATE_BASEVALUE);
		$pirate["urban_planets"] = rand(0, CONF_PIRATE_BASEVALUE);
		$pirate["research_planets"] = rand(0, CONF_PIRATE_BASEVALUE);
		$pirate["petro_planets"] = rand(0, CONF_PIRATE_BASEVALUE);
		$pirate["antipollu_planets"] = rand(0, CONF_PIRATE_BASEVALUE);

		$networth = 0;
		$networth += ($pirate["credits"] * CONF_NETWORTH_CREDITS);

		$planets = 0;
		$planets += $pirate["food_planets"];
		$planets += $pirate["ore_planets"];
		$planets += $pirate["tourism_planets"];
		$planets += $pirate["supply_planets"];
		$planets += $pirate["gov_planets"];
		$planets += $pirate["edu_planets"];
		$planets += $pirate["research_planets"];
		$planets += $pirate["urban_planets"];
		$planets += $pirate["petro_planets"];
		$planets += $pirate["antipollu_planets"];
		$networth += ($planets * CONF_NETWORTH_PLANETS);

		$army = 0;
		$army += $pirate["soldiers"] * CONF_NETWORTH_MILITARY_SOLDIER;
		$army += $pirate["fighters"] * CONF_NETWORTH_MILITARY_FIGHTER;
		$army += $pirate["stations"] * CONF_NETWORTH_MILITARY_STATION;
		$army += $pirate["lightcruisers"] * CONF_NETWORTH_MILITARY_LIGHTCRUISER;
		$army += $pirate["heavycruisers"] * CONF_NETWORTH_MILITARY_HEAVYCRUISER;
		$army += $pirate["carriers"] * CONF_NETWORTH_MILITARY_CARRIER;
		$army += $pirate["covertagents"] * CONF_NETWORTH_MILITARY_COVERT;
		$networth += $army;

		$pirate["networth"] = $networth;

		$DB->Execute("UPDATE game".$game_id."_tb_pirate SET soldiers=" . $pirate["soldiers"] . ",
														fighters=" . $pirate["fighters"] . ",
														lightcruisers=" . $pirate["lightcruisers"] . ",
														heavycruisers=" . $pirate["heavycruisers"] . ",
														stations=" . $pirate["stations"] . ",
														carriers=" . $pirate["carriers"] . ",
														covertagents=" . $pirate["covertagents"] . ",
														food=" . $pirate["food"] . ",
														credits=" . $pirate["credits"] . ",
														research=" . $pirate["research"] . ",
														food_planets=" . $pirate["food_planets"] . ",
														ore_planets=" . $pirate["ore_planets"] . ",
														tourism_planets=" . $pirate["tourism_planets"] . ",
														supply_planets=" . $pirate["supply_planets"] . ",
														gov_planets=" . $pirate["gov_planets"] . ",
														edu_planets=" . $pirate["edu_planets"] . ",
														research_planets=" . $pirate["research_planets"] . ",
														urban_planets=" . $pirate["urban_planets"] . ",
														petro_planets=" . $pirate["petro_planets"] . ",
														antipollu_planets=" . $pirate["antipollu_planets"] . ",
														networth=" . addslashes($pirate["networth"]) . "
													 	WHERE id=" . $pirate["id"]);

		if (!$DB) trigger_error($DB->ErrorMsg());
	
		$rs->MoveNext();
	}

	$DB->CompleteTrans();
	header("Location: admin.php?RESETDONE");
	die();
	
}
// *********************************************************************************
// Add game callback
// *********************************************************************************
if (isset ($_GET["ADDGAME"])) {

	if ($_POST["game_name"] == "") { $DB->CompleteTrans(); die(T_("Game name empty!")); }
	if ($_POST["lifetime"] == "") { $DB->CompleteTrans(); die(T_("Lifetime empty!")); }
	if ($_POST["turns_per_day"] == "") { $DB->CompleteTrans(); die(T_("Turns per day empty!")); }
	if ($_POST["protection_turns"] == "") { $DB->CompleteTrans(); die(T_("Protection turns empty!")); }
	if ($_POST["maxplayers"] == "") { $DB->CompleteTrans(); die(T_("Max players empty!")); }
	if ($_POST["players_slot"] == "") { $DB->CompleteTrans(); die(T_("Players slot empty!")); }


	$premium_only = 0;
	if (isset ($_POST["premium_only"]))
		$premium_only = 1;

	if (!$DB->Execute("INSERT INTO system_tb_games (name,victory_condition,premium_only,lifetime,turns_per_day,protection_turns,max_players,players_slot) " .
	"VALUES('" . addslashes($_POST["game_name"]) . "','" . addslashes($_POST["victory_condition"]) . "',$premium_only," . addslashes($_POST["lifetime"]) . "," . addslashes($_POST["turns_per_day"]) . "," . addslashes($_POST["protection_turns"]) . "," . addslashes($_POST["maxplayers"]) . "," . addslashes($_POST["players_slot"]) . ")")) {
		trigger_error($DB->ErrorMsg());
	}

	$game_id = $DB->Insert_ID();


	$fd = fopen("include/game/config_orig.php", "r");
	$filedata = fread($fd, filesize("include/game/config_orig.php"));
	fclose($fd);

	$filedata = str_replace("{game_name}", addslashes($_POST["game_name"]), $filedata);
	$filedata = str_replace("{game_lifetime}", addslashes(($_POST["lifetime"] * 60 * 60 * 24)), $filedata);
	$filedata = str_replace("{game_bool_research}", ($_POST["victory_condition"] == "research" ? "true" : "false"), $filedata);
	$filedata = str_replace("{game_bool_premiumonly}", ($premium_only == 1 ? "true" : "false"), $filedata);
	$filedata = str_replace("{game_maxplayers}", addslashes($_POST["maxplayers"]), $filedata);
	$filedata = str_replace("{game_turnsperday}", addslashes($_POST["turns_per_day"]), $filedata);
	$filedata = str_replace("{game_maxslots}", addslashes($_POST["players_slot"]), $filedata);
	$filedata = str_replace("{game_protection_turns}", addslashes($_POST["protection_turns"]), $filedata);
	$filedata = str_replace("{game_id}", $game_id, $filedata);


	$fd = fopen("include/game/games_config/" . $game_id . ".php", "w");
	fwrite($fd, $filedata);
	fclose($fd);

	copy("include/game/rules_orig.php","include/game/games_rules/".$game_id.".php");

	$fd = fopen("include/sql_insert.txt", "r");
	$sql_data = fread($fd, filesize("include/sql_insert.txt"));
	$sql_data = str_replace("{game_id}",$game_id,$sql_data);
	fclose($fd);

	$sql_data = explode("/***/", $sql_data);
	
	for ($i = 0; $i < count($sql_data); $i++) {
		$query = $sql_data[$i];
		$query = str_replace("{prefix}",$game_id,$query);
		if (!$DB->Execute($query)) {
			$error_str = $DB->ErrorMsg()." = $query";
			if (!$DB->Execute("DELETE FROM system_tb_games WHERE id=".$game_id)) trigger_error($DB->ErrorMsg());
			if (file_exists("include/game/games_config/" . $game_id . ".php")) unlink("include/game/games_config/" . $game_id . ".php");
			if (file_exists("include/game/games_rules/" . $game_id . ".php")) unlink("include/game/games_rules/" . $game_id . ".php");
			$DB->Execute("DROP TABLE game".$game_id."_tb_*");
			trigger_error($error_str);
		}
	}

	$DB->CompleteTrans();
	finish_and_redirect('admin.php');   
}



// *********************************************************************************
// Display page
// *********************************************************************************


// populate games
$games = array ();

$rs = $DB->Execute("SELECT * FROM system_tb_games");
while (!$rs->EOF) {

	$game = $rs->fields;
	$rs2 = $DB->Execute("SELECT COUNT(*) FROM game".$game["id"]."_tb_empire WHERE active=1");

	if ($rs2 == NULL) {
		// bogus database entry
		$DB->Execute("DELETE FROM system_tb_games WHERE id=".$rs->fields["id"]);
		$rs->MoveNext();
		continue;
	}


	$game["empires_count"] = $rs2->fields[0];
	
	$rs3 = $DB->Execute("SELECT date FROM game".$game["id"]."_tb_coordinator");

	if ($rs3->EOF)
		$game["time_elapsed"] = T_("Need a reset!");
	else {
		$elapsed = (time() - $rs3->fields["date"]);
		$game["time_elapsed"] = (floor($elapsed / (60 * 60 * 24)) + 1) . " ".T_("days");
	}

  	$game["description"] = stripslashes($game["description"]);

	$empires = array();
	$rs4 = $DB->Execute("SELECT * FROM game".$game["id"]."_tb_empire ORDER BY networth DESC");
	$rank = 1;
	while(!$rs4->EOF) {
		$empire = $rs4->fields;
		$empire["rank"] = $rank++;
		$empire["lifespan"] = formatTime(time() - $empire["date"]);
		$empires[] = $empire;
		$rs4->MoveNext();
	}

	$game["empires"] = $empires;
	$games[] = $game;
	$rs->MoveNext();
}

$TPL->assign("games", $games);

$DB->CompleteTrans();

$TPL->display("page_admin.html");

?>
