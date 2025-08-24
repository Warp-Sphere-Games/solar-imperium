<?php
// Solar Imperium is licensed under GPL2, Check LICENSE.TXT for mode details //

class Planets
{
    /** @var \ADOConnection|null */
    var $DB;
    /** @var mixed */
    var $TEMPLATE;
    /** @var array */
    var $data = [];
    /** @var string */
    var $data_footprint = '';
    /** @var int */
    var $game_id = 0;

    // Modern constructor with optional injection; falls back to globals
    function __construct($DB = null, $TEMPLATE = null)
    {
        if ($DB === null) {
            global $DB;
            $this->DB = isset($DB) ? $DB : null;
        } else {
            $this->DB = $DB;
        }

        if ($TEMPLATE === null) {
            global $TPL;
            $this->TEMPLATE = isset($TPL) ? $TPL : null;
        } else {
            $this->TEMPLATE = $TEMPLATE;
        }

        $this->game_id = isset($_SESSION['game']) ? (int)$_SESSION['game'] : 0;
    }

    // Back-compat PHP4-style constructor
    function Planets($DB = null, $TEMPLATE = null) { $this->__construct($DB, $TEMPLATE); }

    ///////////////////////////////////////////////////////////////////////
    function load($empire_id)
    {
        if (!$this->DB) {
            trigger_error('Planets::load called without a valid DB handle', E_USER_ERROR);
            return false;
        }

        $empire_id = (int)$empire_id;
        $sql = "SELECT * FROM game{$this->game_id}_tb_planets WHERE empire='{$empire_id}'";
        $rs = $this->DB->Execute($sql);
        if (!$rs) {
            trigger_error($this->DB->ErrorMsg() . " :: {$sql}", E_USER_ERROR);
            return false;
        }
        if ($rs->EOF) return false;

        $this->data = $rs->fields ?? [];
        $this->data_footprint = md5(serialize($this->data));
        return true;
    }

    ///////////////////////////////////////////////////////////////////////
    function save()
    {
        if (!$this->DB) {
            trigger_error('Planets::save called without a valid DB handle', E_USER_ERROR);
            return;
        }
        if (md5(serialize($this->data)) === $this->data_footprint) return;

        $sets = [];
        foreach ($this->data as $key => $value) {
            if ($key === 'id' || $key === 'empire' || is_int($key)) continue;

            if (is_numeric($value) && $key !== 'logo') {
                $num = (float)$value;
                if ($num < 0) $num = 0;
                $sets[] = $key . '=' . (strpos((string)$num, '.') === false ? (int)$num : $num);
            } else {
                $sets[] = $key . "='" . addslashes((string)$value) . "'";
            }
        }

        if (empty($sets)) return;

        $empireId = isset($this->data['empire']) ? (int)$this->data['empire'] : 0;
        $sql = "UPDATE game{$this->game_id}_tb_planets SET " . implode(',', $sets) . " WHERE empire='{$empireId}'";
        if (!$this->DB->Execute($sql)) {
            trigger_error($this->DB->ErrorMsg() . " :: {$sql}", E_USER_ERROR);
        }
    }

    ///////////////////////////////////////////////////////////////////////
    function getCount()
    {
        if (empty($this->data)) return 0;
        $keys = [
            'food_planets','ore_planets','tourism_planets','supply_planets',
            'gov_planets','edu_planets','research_planets','urban_planets',
            'petro_planets','antipollu_planets'
        ];
        $count = 0;
        foreach ($keys as $k) {
            $count += isset($this->data[$k]) ? (int)$this->data[$k] : 0;
        }
        return $count;
    }
}
