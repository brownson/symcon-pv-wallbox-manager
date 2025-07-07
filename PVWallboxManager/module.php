<?php

class PVWallboxManager extends IPSModule
{
    public function Create()
    {
        // Immer zuerst
        parent::Create();

        // Properties (Konfigurationsfelder im Instanz-Dialog)
        $this->RegisterPropertyString('WallboxIP', '192.168.98.5'); // Standard-IP, anpassbar im WebFront

        // Variablen anlegen
        $this->RegisterVariableInteger('Status', 'Fahrzeugstatus', '', 1);    // car: 1=bereit, 2=lädt, ...
        $this->RegisterVariableFloat('Leistung', 'Ladeleistung (W)', '~Watt', 2);

        // Timer für zyklische Abfrage (z.B. alle 30 Sek.)
        $this->RegisterTimer('UpdateStatus', 30 * 1000, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateStatus", 0);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Nach Konfig-Änderung: Timer ggf. neu setzen
        $this->SetTimerInterval('UpdateStatus', 30 * 1000);
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident == "UpdateStatus") {
            $this->UpdateStatus();
            return;
        }
        // ggf. weitere cases
        throw new Exception("Invalid Ident: $Ident");
    }

    // Hauptfunktion: Statusdaten holen und Variablen setzen
    public function UpdateStatus()
    {
        $ip = $this->ReadPropertyString('WallboxIP');
        $url = "http://$ip/status";
        $json = @file_get_contents($url);

        if ($json === false) {
            IPS_LogMessage("GoEChargerSimple", "Fehler: Keine Antwort von Wallbox ($url)");
            return;
        }
        $data = json_decode($json, true);

        if (!is_array($data)) {
            IPS_LogMessage("GoEChargerSimple", "Fehler: Ungültiges JSON von Wallbox ($url)");
            return;
        }

        // Werte setzen
        $status = isset($data['car']) ? intval($data['car']) : 0;
        $leistung = isset($data['nrg'][11]) ? floatval($data['nrg'][11]) : 0.0;

        SetValue($this->GetIDForIdent('Status'), $status);
        SetValue($this->GetIDForIdent('Leistung'), $leistung);
    }

}
