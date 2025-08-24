<?php
// Solar Imperium is licensed under GPL2, Check LICENSE.TXT for mode details //

class EventCreator
{
    /** @var object|\ADOConnection */
    var $DB;
    var $type = 0;
    var $from = -1;
    var $to   = -1;
    var $params = [];
    var $seen = 0;
    var $sticky = 0;
    var $height = 160;
    var $game_id = 0;

    // PHP 8 constructor (keeps backward compatibility if called as EventCreator($DB))
    function __construct($DB = null)
    {
        // Prefer provided handle, fall back to global
        if ($DB) {
            $this->DB = $DB;
        } elseif (isset($GLOBALS['DB'])) {
            $this->DB = $GLOBALS['DB'];
        } else {
            throw new \RuntimeException("EventCreator: DB handle not provided and \$DB global is missing");
        }

        // Game id from session
        $this->game_id = isset($_SESSION['game']) ? (int)$_SESSION['game'] : 0;

        // Defaults
        $this->sticky = 0;
        $this->seen   = 0;
        $this->height = 160;
    }

    // Legacy PHP 5-style constructor shim
    function EventCreator($DB = null) { $this->__construct($DB); }

    private function paramsString(): string
    {
        // Always use the same encoding for both SELECT and INSERT
        return addslashes(serialize($this->params));
    }

    private function table(string $suffix): string
    {
        return "game{$this->game_id}_{$suffix}";
    }

    function broadcast()
    {
        // All active empires are recipients
        $recipients = $this->DB->Execute("SELECT id FROM ".$this->table('tb_empire')." WHERE active=1");
        if (!$recipients) {
            trigger_error($this->DB->ErrorMsg());
            return;
        }

        // Skip duplicates (compare against serialized params, same as insert)
        $params_str = $this->paramsString();
        $dup = $this->DB->Execute(
            "SELECT COUNT(*) AS c FROM ".$this->table('tb_event').
            " WHERE event_type='".(int)$this->type."' AND event_from='".(int)$this->from."' AND params='".$params_str."'"
        );
        if (!$dup) trigger_error($this->DB->ErrorMsg());
        if ($dup && (int)$dup->fields['c'] !== 0) return;

        while (!$recipients->EOF) {
            $to = (int)$recipients->fields['id'];
            $q  = "INSERT INTO ".$this->table('tb_event').
                  " (event_type,event_from,event_to,params,seen,sticky,date,height) VALUES (".
                  (int)$this->type.",".
                  (int)$this->from.",".
                  $to.",".
                  "'".$params_str."',".
                  (int)$this->seen.",".
                  (int)$this->sticky.",".
                  time().",".
                  (int)$this->height.
                  ")";
            if (!$this->DB->Execute($q)) trigger_error($this->DB->ErrorMsg());
            $recipients->MoveNext();
        }

        $this->gc();
    }

    function send()
    {
        // Skip duplicates
        $params_str = $this->paramsString();
        $dup = $this->DB->Execute(
            "SELECT COUNT(*) AS c FROM ".$this->table('tb_event').
            " WHERE event_type='".(int)$this->type."' AND event_from='".(int)$this->from."' AND params='".$params_str."'"
        );
        if (!$dup) trigger_error($this->DB->ErrorMsg());
        if ($dup && (int)$dup->fields['c'] !== 0) return;

        // If AI turn flag is set, suppress user-facing events
        if (!empty($GLOBALS['GAME']['ai_turn'])) return;

        $q = "INSERT INTO ".$this->table('tb_event').
             " (event_type,event_from,event_to,params,seen,sticky,date,height) VALUES (".
             (int)$this->type.",".
             (int)$this->from.",".
             (int)$this->to.",".
             "'".$params_str."',".
             (int)$this->seen.",".
             (int)$this->sticky.",".
             time().",".
             (int)$this->height.
             ")";
        if (!$this->DB->Execute($q)) trigger_error($this->DB->ErrorMsg());

        $this->gc();
    }

    private function gc(): void
    {
        $timeout_unseen = time() - (int)CONF_UNSEEN_EVENT_TIMEOUT;
        $timeout_seen   = time() - (int)CONF_SEEN_EVENT_TIMEOUT;

        if (!$this->DB->Execute("DELETE FROM ".$this->table('tb_event')." WHERE date < {$timeout_unseen} AND seen=0"))
            trigger_error($this->DB->ErrorMsg());
        if (!$this->DB->Execute("DELETE FROM ".$this->table('tb_event')." WHERE date < {$timeout_seen} AND seen=1"))
            trigger_error($this->DB->ErrorMsg());
    }
}
