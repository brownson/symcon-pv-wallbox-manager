<?php
class PVWallboxManager extends IPSModule
{

    // =========================================================================
    // 1. INITIALISIERUNG
    // =========================================================================

    public function Create()
    {
        // Immer zuerst
        parent::Create();

        // Properties aus form.json
        $this->RegisterPropertyString('WallboxIP', '0.0.0.0');
        $this->RegisterPropertyInteger('PollingInterval', 30);

        // Variablen nach API v2
        $this->RegisterVariableInteger('Status',      'Fahrzeugstatus', '', 1);
        $this->RegisterVariableFloat('Leistung',      'Ladeleistung (W)', '~Watt', 2);
        $this->RegisterVariableInteger('Ampere',      'Max. Ladestrom (A)', '', 3);
        $this->RegisterVariableInteger('Phasen',      'Phasen aktiv', '', 4);
        $this->RegisterVariableInteger('Freigabe',    'Ladefreigabe', '', 5);
        $this->RegisterVariableInteger('Kabelstrom',  'Kabeltyp (A)', '', 6);
        $this->RegisterVariableInteger('Fehlercode',  'Fehlercode', '', 7);
        $this->RegisterVariableInteger('Energie',     'Geladene Energie (Wh)', '~Electricity.Wh', 8);

        // Timer für zyklische Abfrage (z.B. alle 30 Sek.)
        //$this->RegisterTimer('UpdateStatus', 30 * 1000, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateStatus", 0);');
        $this->RegisterTimer('UpdateStatus', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateStatus", 0);');


    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $interval = $this->ReadPropertyInteger('RefreshInterval');
        $this->SetTimerInterval('UpdateStatus', $interval * 1000);
    }

    // =========================================================================
    // 2. REQUESTACTION / TIMER / EVENTS
    // =========================================================================

    public function RequestAction($Ident, $Value)
    {
        if ($Ident == "UpdateStatus") {
            $this->UpdateStatus();
            return;
        }
        // ggf. weitere cases
        throw new Exception("Invalid Ident: $Ident");
    }

    // =========================================================================
    // 3. ZENTRALE STEUERLOGIK
    // =========================================================================

    public function UpdateStatus()
    {
        $ip = $this->ReadPropertyString('WallboxIP');
        $url = "http://$ip/api/status";
        $json = @file_get_contents($url);

        if ($json === false) {
            IPS_LogMessage("PVWallboxManager", "Fehler: Keine Antwort von Wallbox ($url)");
            return;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            IPS_LogMessage("PVWallboxManager", "Fehler: Ungültiges JSON von Wallbox ($url)");
            return;
        }

        // Variablen nach API-V2 befüllen
        SetValue($this->GetIDForIdent('Status'),      intval($data['car'] ?? 0));
        SetValue($this->GetIDForIdent('Leistung'),    floatval($data['nrg'][11] ?? 0.0));
        SetValue($this->GetIDForIdent('Ampere'),      intval($data['amp'] ?? 0));
        SetValue($this->GetIDForIdent('Phasen'),      array_sum($data['pha'] ?? [0,0,0]));
        SetValue($this->GetIDForIdent('Freigabe'),    intval($data['alw'] ?? 0));
        SetValue($this->GetIDForIdent('Kabelstrom'),  intval($data['cbl'] ?? 0));
        SetValue($this->GetIDForIdent('Fehlercode'),  intval($data['err'] ?? 0));
        SetValue($this->GetIDForIdent('Energie'),     intval($data['wh'] ?? 0));
    }
}

}
