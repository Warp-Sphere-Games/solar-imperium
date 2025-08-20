<?php
// Solar Imperium is licensed under GPL2, Check LICENSE.TXT for mode details //

class Session {
    var $DB;
    var $game_id;

    //////////////////////////////////////////////////////////////////////
    // Constructor (PHP 5+/8+)
    //////////////////////////////////////////////////////////////////////
    public function __construct($DB) {
        $this->init($DB);
    }

    // Back-compat if something *explicitly* calls Session($DB)
    public function Session($DB) {
        $this->init($DB);
    }

    private function init($DB) {
        $this->DB = $DB;
        $this->game_id = isset($_SESSION["game"]) ? (int)$_SESSION["game"] : 0;

        if ($this->game_id <= 0) {
            // Nothing to do if no game context yet.
            return;
        }

        if (isset($_SESSION["empire_id"])) {
            $empireId = (int)$_SESSION["empire_id"];

            $rs = $this->DB->Execute("SELECT * FROM game{$this->game_id}_tb_session WHERE empire={$empireId}");
            if (!$rs) trigger_error($this->DB->ErrorMsg());
            if ($rs->EOF) {
                if (!$this->DB->Execute("INSERT INTO game{$this->game_id}_tb_session (empire,lastdate) VALUES ({$empireId}," . time() . ")")) {
                    trigger_error($this->DB->ErrorMsg());
                }
            }

            // update session date
            if (!$this->DB->Execute("UPDATE game{$this->game_id}_tb_session SET lastdate=" . time() . " WHERE empire={$empireId}")) {
                trigger_error($this->DB->ErrorMsg());
            }
        }

        // delete old sessions
        $date_timeout = time() - CONF_SESSION_TIMEOUT;
        if (!$this->DB->Execute("DELETE FROM game{$this->game_id}_tb_session WHERE lastdate < {$date_timeout}")) {
            trigger_error($this->DB->ErrorMsg());
        }
    }

    //////////////////////////////////////////////////////////////////////
    // Logout of the system
    //////////////////////////////////////////////////////////////////////
    public function logout() {
        if ($this->game_id > 0 && isset($_SESSION["empire_id"])) {
            $empireId = (int)$_SESSION["empire_id"];
            if (!$this->DB->Execute("DELETE FROM game{$this->game_id}_tb_session WHERE empire={$empireId}")) {
                trigger_error($this->DB->ErrorMsg());
            }
        }
        session_destroy();
        $_SESSION = null;
    }

    //////////////////////////////////////////////////////////////////////
    // Login of the system
    //////////////////////////////////////////////////////////////////////
    public function login($username, $password) {
        global $CONF_PREMIUM_MEMBERS;

        if ($this->getOnlinePLayers() >= CONF_MAX_SESSIONS) {
            if (!in_array($username, $CONF_PREMIUM_MEMBERS, true)) {
                die(T_("Too much players connected, cannot login!"));
            }
        }

        $empire = $this->DB->Execute(
            "SELECT id FROM game{$this->game_id}_tb_empire
             WHERE email='" . addslashes($username) . "' AND password='" . md5($password) . "' AND active > 0"
        );

        if (!$empire) trigger_error($this->DB->ErrorMsg());
        if ($empire->EOF) return false;

        $_SESSION["empire_id"] = (int)$empire->fields["id"];
        $_SESSION["email"] = $username;

        if (!$this->DB->Execute(
            "INSERT INTO game{$this->game_id}_tb_session (empire,lastdate) VALUES (" . (int)$_SESSION["empire_id"] . "," . time() . ")"
        )) {
            trigger_error($this->DB->ErrorMsg());
        }

        return true;
    }

    //////////////////////////////////////////////////////////////////////
    // Empire is active?
    //////////////////////////////////////////////////////////////////////
    public function isActive() {
        if ($this->game_id <= 0 || !isset($_SESSION["empire_id"])) return 0;

        $empireId = (int)$_SESSION["empire_id"];
        $empire = $this->DB->Execute("SELECT * FROM game{$this->game_id}_tb_empire WHERE id={$empireId}");
        if (!$empire) trigger_error($this->DB->ErrorMsg());
        return (int)$empire->fields["active"];
    }

    //////////////////////////////////////////////////////////////////////
    // how much online players?
    //////////////////////////////////////////////////////////////////////
    public function getOnlinePLayers() {
        if ($this->game_id <= 0) return 0;
        $count = $this->DB->Execute("SELECT COUNT(*) FROM game{$this->game_id}_tb_session");
        if (!$count) trigger_error($this->DB->ErrorMsg());
        return (int)$count->fields[0];
    }
}
