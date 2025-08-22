<?php
// Solar Imperium is licensed under GPL2, Check LICENSE.TXT for mode details //

class Diplomacy
{
    var $DB;
    var $data;            // array of treaty rows
    var $data_footprint;  // per-row md5 footprints
    var $game_id;

    // PHP 8 constructor that preserves legacy signature
    function __construct($DB = null) { $this->Diplomacy($DB); }

    // Legacy ctor (kept for older call sites)
    function Diplomacy($DB = null)
    {
        if (!$DB && isset($GLOBALS['DB'])) { $DB = $GLOBALS['DB']; }
        $this->DB  = $DB;
        $this->data = array();
        $this->data_footprint = array();
        $this->game_id = isset($_SESSION['game']) ? (int)$_SESSION['game'] : 0;
    }

    ///////////////////////////////////////////////////////////////////////////
    // Load all treaties involving an empire
    ///////////////////////////////////////////////////////////////////////////
    function load($empire_id)
    {
        $empire_id = (int)$empire_id;
        $this->data = array();
        $this->data_footprint = array();

        $sql = "SELECT * FROM game{$this->game_id}_tb_treaty
                WHERE empire_from='{$empire_id}' OR empire_to='{$empire_id}'";
        $rs = $this->DB->Execute($sql);
        if (!$rs) { trigger_error($this->DB->ErrorMsg()); return false; }

        while (!$rs->EOF) {
            // Identify the counterpart empire
            $other = ($rs->fields['empire_from'] == $empire_id)
                ? (int)$rs->fields['empire_to']
                : (int)$rs->fields['empire_from'];

            // If the other empire no longer exists/active, purge this treaty
            $chk = $this->DB->Execute("SELECT id FROM game{$this->game_id}_tb_empire
                                       WHERE active='1' AND id='{$other}'");
            if (!$chk) { trigger_error($this->DB->ErrorMsg()); return false; }

            if ($chk->EOF) {
                $tid = (int)$rs->fields['id'];
                if (!$this->DB->Execute("DELETE FROM game{$this->game_id}_tb_treaty WHERE id='{$tid}'")) {
                    trigger_error($this->DB->ErrorMsg());
                    return false;
                }
            } else {
                $row = $rs->fields;
                $this->data[] = $row;
                $this->data_footprint[] = md5(serialize($row));
            }

            $rs->MoveNext();
        }

        return true;
    }

    ///////////////////////////////////////////////////////////////////////////
    // Save modified treaties
    ///////////////////////////////////////////////////////////////////////////
    function save()
    {
        $n = count($this->data);
        for ($i = 0; $i < $n; $i++) {
            $row = $this->data[$i];
            $fp  = md5(serialize($row));
            if ($fp === $this->data_footprint[$i]) continue;

            $id = (int)$row['id'];
            $emp_from = (int)$row['empire_from'];
            $emp_to   = (int)$row['empire_to'];
            $type     = addslashes($row['type']);
            $date     = (int)$row['date'];
            $status   = addslashes($row['status']);

            $q = "UPDATE game{$this->game_id}_tb_treaty SET
                    empire_from='{$emp_from}',
                    empire_to='{$emp_to}',
                    type='{$type}',
                    date='{$date}',
                    status='{$status}'
                  WHERE id='{$id}'";
            if (!$this->DB->Execute($q)) { trigger_error($this->DB->ErrorMsg()); return false; }

            $this->data_footprint[$i] = $fp;
        }
        return true;
    }

    ///////////////////////////////////////////////////////////////////////////
    // Get the treaty type/status for any treaty involving $empire_id
    ///////////////////////////////////////////////////////////////////////////
    function treatyFrom($empire_id)
    {
        $empire_id = (int)$empire_id;
        for ($i = 0; $i < count($this->data); $i++) {
            $row = $this->data[$i];
            // If either side matches, return its type/status
            if ($row['empire_from'] == $empire_id || $row['empire_to'] == $empire_id) {
                return array($row['type'], $row['status']);
            }
        }
        return null;
    }

    ///////////////////////////////////////////////////////////////////////////
    // Create/send a treaty
    ///////////////////////////////////////////////////////////////////////////
    function sendTreaty($treaty, $empire_data, $target_data)
    {
        $emp_from = (int)$empire_data['id'];
        $emp_to   = (int)$target_data['id'];
        $type     = addslashes($treaty);
        $now      = time();
        $status   = CONF_TREATY_ACCEPT_PENDING;

        $q = "INSERT INTO game{$this->game_id}_tb_treaty (empire_from,empire_to,type,date,status)
              VALUES ('{$emp_from}','{$emp_to}','{$type}','{$now}','{$status}')";
        if (!$this->DB->Execute($q)) { trigger_error($this->DB->ErrorMsg()); return false; }

        $evt = new EventCreator($this->DB);
        $evt->from  = $emp_from;
        $evt->to    = $emp_to;
        $evt->type  = CONF_EVENT_PENDINGTREATY;
        $evt->params = array(
            "empire_id"      => $empire_data["id"],
            "empire_name"    => $empire_data["name"],
            "empire_emperor" => $empire_data["emperor"],
            "gender"         => $empire_data["gender"],
            "treaty"         => $treaty
        );
        $evt->sticky = true;
        $evt->send();

        $this->load($emp_from);
        return true;
    }

    ///////////////////////////////////////////////////////////////////////////
    // Get one treaty by id from loaded cache
    ///////////////////////////////////////////////////////////////////////////
    function getTreaty($treaty_id)
    {
        $treaty_id = (int)$treaty_id;
        for ($i = 0; $i < count($this->data); $i++) {
            if ((int)$this->data[$i]['id'] === $treaty_id) return $this->data[$i];
        }
        return null;
    }

    ///////////////////////////////////////////////////////////////////////////
    // Mark treaty as break-pending and notify target
    ///////////////////////////////////////////////////////////////////////////
    function breakTreaty($treaty_id, $empire_data, $target_id)
    {
        $treaty_id = (int)$treaty_id;
        $target_id = (int)$target_id;

        $q = "UPDATE game{$this->game_id}_tb_treaty
              SET status='".CONF_TREATY_BREAK_PENDING."'
              WHERE id='{$treaty_id}'";
        if (!$this->DB->Execute($q)) { trigger_error($this->DB->ErrorMsg()); return false; }

        $evt = new EventCreator($this->DB);
        $evt->from  = (int)$empire_data['id'];
        $evt->to    = $target_id;
        $evt->type  = CONF_EVENT_BREAKTREATY;
        $evt->params = array(
            "empire_id"      => $empire_data["id"],
            "empire_name"    => $empire_data["name"],
            "empire_emperor" => $empire_data["emperor"],
            "gender"         => $empire_data["gender"]
        );
        $evt->send();
        return true;
    }

    ///////////////////////////////////////////////////////////////////////////
    // Delete a treaty
    ///////////////////////////////////////////////////////////////////////////
    function deleteTreaty($treaty_id)
    {
        $treaty_id = (int)$treaty_id;
        $q = "DELETE FROM game{$this->game_id}_tb_treaty WHERE id='{$treaty_id}'";
        if (!$this->DB->Execute($q)) { trigger_error($this->DB->ErrorMsg()); return false; }
        return true;
    }
}
