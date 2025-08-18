<?php
// Solar Imperium is licensed under GPL2, Check LICENSE.TXT for mode details //


define("LANGUAGE_DOMAIN","system");

require_once("include/init.php");

// ******************************************************************************
//  Signup callback (AJAX)
// ******************************************************************************
if (isset($_GET["SIGNUP"])) {

    if ((!isset($_POST["agree_checkbox"])) || ($_POST["agree_checkbox"] === "false")) {
        $DB->CompleteTrans();
        die(T_("You must agree with the rules!"));
    }

    // Gather & trim
    $email    = trim((string)($_POST["email"]     ?? ''));
    $realName = trim((string)($_POST["real_name"] ?? ''));
    $country  = trim((string)($_POST["country"]   ?? ''));
    $nickname = trim((string)($_POST["nickname"]  ?? ''));
    $pass1    = (string)($_POST["password1"] ?? '');
    $pass2    = (string)($_POST["password2"] ?? '');

    // Basic presence checks
    if ($email    === '') { $DB->CompleteTrans(); dieError(T_("Empty email field!")); }
    if ($realName === '') { $DB->CompleteTrans(); dieError(T_("Empty real name field!")); }
    if ($country  === '') { $DB->CompleteTrans(); dieError(T_("Empty country field!")); }
    if ($nickname === '') { $DB->CompleteTrans(); dieError(T_("Empty nickname field!")); }
    if ($pass1    === '') { $DB->CompleteTrans(); dieError(T_("Empty password(first) field!")); }
    if ($pass2    === '') { $DB->CompleteTrans(); dieError(T_("Empty password(second) field!")); }
    if ($pass1 !== $pass2){ $DB->CompleteTrans(); dieError(T_("Passwords entered does not matches!")); }

    // Uniqueness checks (use qstr or params)
    $rs = $DB->Execute("SELECT 1 FROM system_tb_players WHERE email = "   . $DB->qstr($email)   . " LIMIT 1");
    if (!$rs->EOF) { $DB->CompleteTrans(); dieError(T_("This email address is already used by someone else!")); }

    $rs = $DB->Execute("SELECT 1 FROM system_tb_players WHERE nickname = " . $DB->qstr($nickname) . " LIMIT 1");
    if (!$rs->EOF) { $DB->CompleteTrans(); dieError(T_("This nickname is already used by someone else!")); }

    // (Legacy: the old code tried to HTML-escape before storing. That’s not necessary if your output escapes properly.
    // If you want to keep the old behavior, uncomment next four lines.)
    // $nickname = str_replace(["<",">"], ["&lt;","&gt;"], $nickname);
    // $realName = str_replace(["<",">"], ["&lt;","&gt;"], $realName);
    // $email    = str_replace(["<",">"], ["&lt;","&gt;"], $email);
    // $country  = str_replace(["<",">"], ["&lt;","&gt;"], $country);

    $creation_date = time();

    // First registrant becomes admin
    $rs = $DB->Execute("SELECT COUNT(*) AS c FROM system_tb_players");
    $admin = ((int)$rs->fields["c"] === 0) ? 1 : 0;

    // INSERT with params (keeps md5 for now to avoid breaking login code)
    $sql = "INSERT INTO system_tb_players (admin, creation_date, email, nickname, real_name, country, password, active)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
    $ok = $DB->Execute($sql, [
        $admin,
        $creation_date,
        $email,
        $nickname,
        $realName,
        $country,
        md5($pass1), // TODO: migrate to password_hash() across app
    ]);
    if (!$ok) { trigger_error($DB->ErrorMsg()); }

    // Update daily stats
    $timeNow = mktime(0,0,1, (int)date("n"), (int)date("j"), (int)date("Y"));
    $stats = $DB->Execute("SELECT * FROM system_tb_stats WHERE timestamp = ?", [$timeNow]);
    if ($stats->EOF) {
        $DB->Execute("INSERT INTO system_tb_stats (timestamp, signup_count, login_count) VALUES (?, 0, 0)", [$timeNow]);
        $stats = $DB->Execute("SELECT * FROM system_tb_stats WHERE timestamp = ?", [$timeNow]);
    }
    $signup_count = (int)$stats->fields["signup_count"] + 1;
    $DB->Execute("UPDATE system_tb_stats SET signup_count = ? WHERE id = ?", [$signup_count, (int)$stats->fields["id"]]);

    $DB->CompleteTrans();
    if (isset($_GET["XML"])) {
        $TPL->display("page_register_complete.html");
    } else {
        die("register_complete");
    }
}




$DB->CompleteTrans(); 
$TPL->display("page_registernow.html");

?>
