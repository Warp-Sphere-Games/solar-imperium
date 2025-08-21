<?php
// Solar Imperium is licensed under GPL2, Check LICENSE.TXT for mode details //

class Supply
{
    var $DB;
    var $TEMPLATE;
    var $data;
    var $data_footprint;
    var $game_id;

    // PHP 8 constructor that preserves legacy signature
    function __construct($DB = null, $TEMPLATE = null) { $this->Supply($DB, $TEMPLATE); }

    // Legacy ctor (kept for older call sites)
    function Supply($DB = null, $TEMPLATE = null)
    {
        if (!$DB && isset($GLOBALS['DB']))  { $DB  = $GLOBALS['DB']; }
        if (!$TEMPLATE && isset($GLOBALS['TPL'])) { $TEMPLATE = $GLOBALS['TPL']; }

        $this->DB       = $DB;
        $this->TEMPLATE = $TEMPLATE;
        $this->game_id  = isset($_SESSION['game']) ? (int)$_SESSION['game'] : 0;
    }

    ///////////////////////////////////////////////////////////////////////
    // Load supply row for an empire
    ///////////////////////////////////////////////////////////////////////
    function load($empire_id)
    {
        $empire_id = (int)$empire_id;
        $rs = $this->DB->Execute("SELECT * FROM game{$this->game_id}_tb_supply WHERE empire='{$empire_id}'");
        if (!$rs) { trigger_error($this->DB->ErrorMsg()); return false; }
        if ($rs->EOF) return false;

        $this->data = $rs->fields;
        $this->data_footprint = md5(serialize($this->data));
        return true;
    }

    ///////////////////////////////////////////////////////////////////////
    // Save if changed
    ///////////////////////////////////////////////////////////////////////
    function save()
    {
        if (!$this->data) return;
        if (md5(serialize($this->data)) === $this->data_footprint) return;

        $pairs = [];
        foreach ($this->data as $key => $value) {
            if (is_int($key) || $key === 'id' || $key === 'empire') continue;
            $pairs[] = (is_numeric($value) && $key !== 'logo')
                ? "$key=".(0+$value)
                : "$key='".addslashes($value)."'";
        }
        if (!$pairs) return;

        $empire_id = (int)$this->data['empire'];
        $sql = "UPDATE game{$this->game_id}_tb_supply SET ".implode(',', $pairs)." WHERE empire='{$empire_id}'";
        if (!$this->DB->Execute($sql)) trigger_error($this->DB->ErrorMsg());
    }
}
