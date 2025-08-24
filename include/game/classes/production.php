<?php
// Solar Imperium is licensed under GPL2, Check LICENSE.TXT for mode details //

class Production
{
    var $DB, $TEMPLATE, $data, $data_footprint, $game_id;

    // ✅ PHP 8 constructor that calls the legacy one
    function __construct($DB = null, $TEMPLATE = null) { $this->Production($DB, $TEMPLATE); }

    // legacy ctor kept for older code paths
    function Production($DB = null, $TEMPLATE = null)
    {
        if (!$DB && isset($GLOBALS['DB']))  { $DB  = $GLOBALS['DB']; }
        if (!$TEMPLATE && isset($GLOBALS['TPL'])) { $TEMPLATE = $GLOBALS['TPL']; }

        $this->DB       = $DB;
        $this->TEMPLATE = $TEMPLATE;
        $this->game_id  = isset($_SESSION['game']) ? (int)$_SESSION['game'] : 0;
    }

    function load($empire_id)
    {
        $empire_id = (int)$empire_id;
        $rs = $this->DB->Execute("SELECT * FROM game{$this->game_id}_tb_production WHERE empire='{$empire_id}'");
        if (!$rs) { trigger_error($this->DB->ErrorMsg()); return false; }
        if ($rs->EOF) return false;

        $this->data = $rs->fields;
        $this->data_footprint = md5(serialize($this->data));
        return true;
    }

    function save()
    {
        if (md5(serialize($this->data)) === $this->data_footprint) return;

        $pairs = [];
        foreach ($this->data as $key => $value) {
            if (is_int($key) || $key === 'id' || $key === 'empire') continue;
            $pairs[] = (is_numeric($value) && $key !== 'logo') ? "$key=".(0+$value) : "$key='".addslashes($value)."'";
        }
        if (!$pairs) return;

        $empire_id = (int)$this->data['empire'];
        $sql = "UPDATE game{$this->game_id}_tb_production SET ".implode(',', $pairs)." WHERE empire='{$empire_id}'";
        if (!$this->DB->Execute($sql)) trigger_error($this->DB->ErrorMsg());
    }
}
?>
