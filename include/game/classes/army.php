<?php
// Solar Imperium is licensed under GPL2, Check LICENSE.TXT for more details //

class Army
{
    /** @var \ADOConnection|null */
    var $DB;
    var $TEMPLATE;
    var $data = [];
    var $data_footprint = '';
    var $game_id = 0;

    ///////////////////////////////////////////////////////////////////////
    // Constructor
    ///////////////////////////////////////////////////////////////////////
    function __construct($DB = null, $TEMPLATE = null)
    {
        // Fallback to global DB if not injected
        if ($DB === null) {
            global $DB;
            $this->DB = $DB;
        } else {
            $this->DB = $DB;
        }

        $this->TEMPLATE = $TEMPLATE;

        // guard game id
        $gid = isset($_SESSION['game']) ? (int)$_SESSION['game'] : 0;
        $this->game_id = ($gid > 0) ? $gid : 0;
    }

    // Back-compat for old-style construction Army($DB, $TEMPLATE)
    function Army($DB = null, $TEMPLATE = null) { $this->__construct($DB, $TEMPLATE); }

    ///////////////////////////////////////////////////////////////////////
    // Load army row for an empire
    ///////////////////////////////////////////////////////////////////////
    function load($empire_id)
    {
        if (!$this->DB) { trigger_error('Army::$DB is not initialized', E_USER_ERROR); return false; }
        if ($this->game_id <= 0) { trigger_error('Army::$game_id is not set', E_USER_ERROR); return false; }

        $eid = (int)$empire_id;
        $sql = "SELECT * FROM game{$this->game_id}_tb_army WHERE empire='{$eid}'";
        $rs  = $this->DB->Execute($sql);
        if (!$rs) trigger_error($this->DB->ErrorMsg(), E_USER_ERROR);
        if ($rs->EOF) return false;

        $this->data = $rs->fields;

        // Clamp effectiveness to sane bounds
        if (isset($this->data['effectiveness'])) {
            if ($this->data['effectiveness'] < 10)  $this->data['effectiveness'] = 10;
            if ($this->data['effectiveness'] > 150) $this->data['effectiveness'] = 150;
        }

        $this->data_footprint = md5(serialize($this->data));
        return true;
    }

    ///////////////////////////////////////////////////////////////////////
    // Save if changed
    ///////////////////////////////////////////////////////////////////////
    function save()
    {
        if (!$this->DB) { trigger_error('Army::$DB is not initialized', E_USER_ERROR); return; }
        if ($this->game_id <= 0) { trigger_error('Army::$game_id is not set', E_USER_ERROR); return; }
        if (!$this->data || md5(serialize($this->data)) === $this->data_footprint) return;

        $sets = [];
        foreach ($this->data as $key => $value) {
            if ($key === 'id' || $key === 'empire' || is_int($key)) continue;

            if (is_numeric($value) && $value < 0) $value = 0;

            if (is_numeric($value) && $key !== 'logo') {
                $sets[] = "$key=" . (0 + $value);
            } else {
                $sets[] = "$key='" . addslashes((string)$value) . "'";
            }
        }

        if (!empty($sets)) {
            $eid = (int)$this->data['empire'];
            $sql = "UPDATE game{$this->game_id}_tb_army SET " . implode(',', $sets) . " WHERE empire='{$eid}'";
            if (!$this->DB->Execute($sql)) trigger_error($this->DB->ErrorMsg(), E_USER_ERROR);
        }

        $this->data_footprint = md5(serialize($this->data));
    }
}
?>
