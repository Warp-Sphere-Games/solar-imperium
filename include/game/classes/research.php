<?php
// Solar Imperium is licensed under GPL2, Check LICENSE.TXT for mode details //

class Research
{
    var $DB;
    var $TEMPLATE;
    var $tech_data;
    var $tech_done;
    var $game_id;

    // PHP 8 constructor (keeps legacy signature)
    function __construct($DB = null, $TEMPLATE = null) { $this->Research($DB, $TEMPLATE); }

    // Legacy ctor
    function Research($DB = null, $TEMPLATE = null)
    {
        if (!$DB && isset($GLOBALS['DB'])) { $DB = $GLOBALS['DB']; }
        $this->DB       = $DB;
        $this->TEMPLATE = $TEMPLATE;
        $this->game_id  = isset($_SESSION['game']) ? (int)$_SESSION['game'] : 0;

        $this->tech_data = array();
        $this->tech_done = array();
    }

    ///////////////////////////////////////////////////////////////////////
    // Load tech catalog + what this empire has completed
    ///////////////////////////////////////////////////////////////////////
    function load($empire_id)
    {
        $empire_id = (int)$empire_id;
        $this->tech_data = array();
        $this->tech_done = array();

        $rs = $this->DB->Execute("SELECT * FROM game{$this->game_id}_tb_research_tech");
        if (!$rs) { trigger_error($this->DB->ErrorMsg()); return false; }
        while (!$rs->EOF) {
            $this->tech_data[] = $rs->fields;
            $rs->MoveNext();
        }

        $rs = $this->DB->Execute("SELECT tech_id FROM game{$this->game_id}_tb_research_done WHERE empire_id='{$empire_id}'");
        if (!$rs) { trigger_error($this->DB->ErrorMsg()); return false; }
        while (!$rs->EOF) {
            $this->tech_done[] = (int)$rs->fields['tech_id'];
            $rs->MoveNext();
        }

        return true;
    }

    ///////////////////////////////////////////////////////////////////////
    // Return all techs at a given level
    ///////////////////////////////////////////////////////////////////////
    function getLevel($level)
    {
        $level = (int)$level;
        $techs = array();
        for ($i = 0; $i < count($this->tech_data); $i++) {
            if ((int)$this->tech_data[$i]['level'] === $level) {
                $techs[] = $this->tech_data[$i];
            }
        }
        return $techs;
    }

    ///////////////////////////////////////////////////////////////////////
    // Lookup tech by id
    ///////////////////////////////////////////////////////////////////////
    function getTechFromId($tech_id)
    {
        $tech_id = (int)$tech_id;
        for ($i = 0; $i < count($this->tech_data); $i++) {
            if ((int)$this->tech_data[$i]['id'] === $tech_id) {
                return $this->tech_data[$i];
            }
        }
        return null;
    }

    ///////////////////////////////////////////////////////////////////////
    // Growth points from planets and production
    ///////////////////////////////////////////////////////////////////////
    function getGrowthPoints($planets, $production)
    {
        $planets    = (int)$planets;
        $production = (int)$production;
        $points = (int) floor(($planets / 100) * $production);
        $points *= (int) CONF_RESEARCH_POINTS_PER_PLANET;
        return $points;
    }
}
