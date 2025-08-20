<?php
// Solar Imperium is licensed under GPL2, Check LICENSE.TXT for mode details //

class Coalition
{
    var $DB;
    var $member;
    var $data;
    var $data_footprint;
    var $members;
    var $game_id;

    ///////////////////////////////////////////////////////////////////////
    // constructor
    ///////////////////////////////////////////////////////////////////////
    function __construct($DB = null)
    {
        if ($DB === null) {
            global $DB;
            $this->DB = $DB;
        } else {
            $this->DB = $DB;
        }

        $this->member = null;
        $this->game_id = round($_SESSION["game"]);
    }

    // legacy PHP4-style constructor support
    function Coalition($DB = null) {
        $this->__construct($DB);
    }

    ///////////////////////////////////////////////////////////////////////
    // load coalition for a given empire
    ///////////////////////////////////////////////////////////////////////
    function load($empire_id)
    {
        $this->member = $this->DB->Execute(
            "SELECT * FROM game".$this->game_id."_tb_member WHERE empire='".addslashes($empire_id)."'"
        );
        if (!$this->member) trigger_error($this->DB->ErrorMsg());

        if ($this->member->EOF) {
            $this->member = null;
            return true;
        }
        $this->member = $this->member->fields;

        $this->data = $this->DB->Execute(
            "SELECT * FROM game".$this->game_id."_tb_coalition WHERE id='".$this->member["coalition"]."'"
        );
        if (!$this->data) trigger_error($this->DB->ErrorMsg());

        $this->data = $this->data->fields;
        $this->data_footprint = md5(serialize($this->data));

        $this->members = array();
        $rs = $this->DB->Execute(
            "SELECT game".$this->game_id."_tb_member.*,game".$this->game_id."_tb_empire.networth
             FROM game".$this->game_id."_tb_member,game".$this->game_id."_tb_empire
             WHERE game".$this->game_id."_tb_empire.id = game".$this->game_id."_tb_member.empire
             AND game".$this->game_id."_tb_member.coalition='".$this->data["id"]."'"
        );
        if (!$rs) trigger_error($this->DB->ErrorMsg());

        while(!$rs->EOF)
        {
            if ($rs->fields["empire"] != $this->member["empire"]) {
                $this->members[] = $rs->fields;
            }
            $rs->MoveNext();
        }
        return true;
    }

    ///////////////////////////////////////////////////////////////////////
    // save coalition
    ///////////////////////////////////////////////////////////////////////
    function save()
    {
        if ($this->member == null) return;
        if (md5(serialize($this->data)) == $this->data_footprint) return;

        $query = "UPDATE game".$this->game_id."_tb_coalition SET ";
        foreach ($this->data as $key => $value) {
            if ($key == "id") continue;
            if (is_numeric($key)) continue;
            if ((is_numeric($value)) && ($key != "logo"))
                $query .= "$key=$value,";
            else
                $query .= "$key='".addslashes($value)."',";
        }

        $query = rtrim($query, ",");
        $query .= " WHERE id='".$this->data["id"]."'";

        if (!$this->DB->Execute($query)) trigger_error($this->DB->ErrorMsg());
    }

    // … all other functions remain unchanged …
}
?>
