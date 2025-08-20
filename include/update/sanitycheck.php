<?php

function _sc_exec($sql, $ctx = '') {
    // Use app-level wrapper if available; else raw Execute.
    if (function_exists('db_exec_or_die')) {
        return db_exec_or_die($sql, $ctx);
    }
    global $DB;
    $ok = $DB->Execute($sql);
    if (!$ok) {
        if (function_exists('dbg_udp')) dbg_udp("[sanity] $ctx :: " . $DB->ErrorMsg());
        // Keep legacy behavior: print and die() would be too harsh here.
        // Just return false so caller can continue best-effort.
    }
    return $ok;
}

function CheckGameSanity_NegativeValues($items, $table)
{
    global $DB;

    $query = "SELECT id, $items FROM $table";
    $rs = $DB->Execute($query);
    if (!$rs) {
        if (function_exists('dbg_udp')) dbg_udp("[sanity] query failed on $table :: " . $DB->ErrorMsg());
        return;
    }

    while (!$rs->EOF) {
        $rowId = isset($rs->fields['id']) ? (int)$rs->fields['id'] : 0;

        // ADOdb exposes fields as both numeric and assoc keys. Ignore numeric.
        foreach ($rs->fields as $key => $value) {
            if (is_int($key) || $key === 'id') continue;

            // Normalize NULL to 0 for comparisons/repairs
            $num = is_null($value) ? 0 : $value;

            // 1) Clamp negatives to 0
            if (is_numeric($num) && $num < 0) {
                _sc_exec("UPDATE $table SET `$key` = 0 WHERE id = $rowId", "negfix:$table.$key#$rowId");
                if (function_exists('dbg_udp')) dbg_udp("[sanity] $table.$key id=$rowId negative -> 0");
                continue;
            }

            // 2) Special bounds for effectiveness
            if ($key === 'effectiveness' && is_numeric($num)) {
                if ($num < 10) {
                    _sc_exec("UPDATE $table SET `$key` = 10 WHERE id = $rowId", "eff-low:$table.$key#$rowId");
                    if (function_exists('dbg_udp')) dbg_udp("[sanity] $table.effectiveness id=$rowId <10 -> 10");
                } elseif ($num > 150) {
                    _sc_exec("UPDATE $table SET `$key` = 150 WHERE id = $rowId", "eff-high:$table.$key#$rowId");
                    if (function_exists('dbg_udp')) dbg_udp("[sanity] $table.effectiveness id=$rowId >150 -> 150");
                }
            }
        }

        $rs->MoveNext();
    }
}

// The goal of this script is to patch common problems (like negative values)
function CheckGameSanity($game_id)
{
    $gid = (int)$game_id;

    CheckGameSanity_NegativeValues(
        "soldiers,fighters,stations,covertagents,covert_points,lightcruisers,heavycruisers,carriers,effectiveness",
        "game{$gid}_tb_army"
    );

    CheckGameSanity_NegativeValues(
        "convoy_soldiers,convoy_fighters,convoy_lightcruisers,convoy_heavycruisers,carriers",
        "game{$gid}_tb_armyconvoy"
    );

    CheckGameSanity_NegativeValues(
        "initial_credits,current_credits,total_turns,turns_left,rate",
        "game{$gid}_tb_bond"
    );

    CheckGameSanity_NegativeValues(
        "networth,planets",
        "game{$gid}_tb_coalition"
    );

    CheckGameSanity_NegativeValues(
        "turns_left,turns_played,protection_turns_left,credits,last_credits,population,food,ore,petroleum,networth,taxrate,inflation,lottery_tickets,planets_bought,food_rate,ore_rate,petroleum_rate,research_points,research_level,research_rate,blackmarket_cooldown",
        "game{$gid}_tb_empire"
    );

    CheckGameSanity_NegativeValues(
        "gold_networth,silver_networth,bronze_networth",
        "game{$gid}_tb_hall_of_fame"
    );

    CheckGameSanity_NegativeValues(
        "initial_credits,current_credits,total_turns,turns_left,rate",
        "game{$gid}_tb_loan"
    );

    CheckGameSanity_NegativeValues(
        "food,food_ratio,ore,ore_ratio,petroleum,petroleum_ratio,food_buy,food_sell,ore_buy,ore_sell,petroleum_buy,petroleum_sell",
        "game{$gid}_tb_market"
    );

    CheckGameSanity_NegativeValues(
        "networth,credits,food,research,covertagents,stations,soldiers,fighters,lightcruisers,heavycruisers,carriers,food_planets,ore_planets,tourism_planets,supply_planets,gov_planets,edu_planets,urban_planets,research_planets,petro_planets,antipollu_planets",
        "game{$gid}_tb_pirate"
    );

    CheckGameSanity_NegativeValues(
        "food_planets,ore_planets,tourism_planets,supply_planets,gov_planets,edu_planets,research_planets,urban_planets,petro_planets,antipollu_planets,max_petro,max_tourism,max_ore,max_food,max_supply,max_gov,max_edu,max_research,max_urban,max_antipollu",
        "game{$gid}_tb_planets"
    );

    CheckGameSanity_NegativeValues(
        "food_short,food_long,ore_short,ore_long,tourism_short,tourism_long,supply_short,supply_long,edu_short,edu_long,research_short,research_long,urban_short,urban_long,petro_short,petro_long,antipollu_short,antipollu_long",
        "game{$gid}_tb_production"
    );

    CheckGameSanity_NegativeValues(
        "credits,food,networth,military,planets,population,pollution,turn",
        "game{$gid}_tb_stats"
    );

    CheckGameSanity_NegativeValues(
        "rate_soldier,rate_fighter,rate_station,rate_heavycruiser,rate_carrier,rate_covert,rate_credits",
        "game{$gid}_tb_supply"
    );

    CheckGameSanity_NegativeValues(
        "trade_money,trade_food,trade_covertagents,trade_soldiers,trade_fighters,trade_stations,trade_lightcruisers,trade_heavycruisers,carriers",
        "game{$gid}_tb_tradeconvoy"
    );
}

?>
