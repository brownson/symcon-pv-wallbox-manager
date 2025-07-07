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
        $this->RegisterPropertyInteger('RefreshInterval', 30);
        $this->RegisterPropertyBoolean('ModulAktiv', true);

        // Variablen nach API v2
        $this->RegisterCarStateProfile();
        $this->RegisterVariableInteger('Status',      'Fahrzeugstatus',     'GoE.CarState',     1);
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
        $aktiv = $this->ReadPropertyBoolean('ModulAktiv');
        $interval = $this->ReadPropertyInteger('RefreshInterval');

        if ($aktiv) {
        $this->SetTimerInterval('UpdateStatus', $interval * 1000);
        } else {
            $this->SetTimerInterval('UpdateStatus', 0); // Timer AUS
            // Optional: Variablen auf 0/null setzen oder einen Status loggen
        }
    }

    // =========================================================================
    // 2. REQUESTACTION / TIMER / EVENTS
    // =========================================================================

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === "UpdateStatus") {
            // Default: PV only
            $this->UpdateStatus('pvonly');
            return;
        }
        throw new Exception("Invalid Ident: $Ident");
    }

    // =========================================================================
    // 3. ZENTRALE STEUERLOGIK
    // =========================================================================

    public function UpdateStatus($mode = 'pvonly')
    {
        if (!$this->ReadPropertyBoolean('ModulAktiv')) {
            IPS_LogMessage("PVWallboxManager", "Modul ist inaktiv – keine Abfrage");
            return;
        }

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

        switch ($mode) {
            case 'manuell':
                // Alles, was für manuelles Laden gebraucht wird
                SetValue($this->GetIDForIdent('Status'),      intval($data['car'] ?? 0));
                SetValue($this->GetIDForIdent('Leistung'),    floatval($data['nrg'][11] ?? 0.0));
                SetValue($this->GetIDForIdent('Ampere'),      intval($data['amp'] ?? 0));
                SetValue($this->GetIDForIdent('Phasen'),      array_sum($data['pha'] ?? [0,0,0]));
                SetValue($this->GetIDForIdent('Energie'),     intval($data['wh'] ?? 0));
                SetValue($this->GetIDForIdent('Freigabe'),    intval($data['alw'] ?? 0));
                SetValue($this->GetIDForIdent('Kabelstrom'),  intval($data['cbl'] ?? 0));
                SetValue($this->GetIDForIdent('Fehlercode'),  intval($data['err'] ?? 0));
                break;

            case 'pv2car':
                // Alles, was für PV2Car benötigt wird
                SetValue($this->GetIDForIdent('Status'),      intval($data['car'] ?? 0));
                SetValue($this->GetIDForIdent('Leistung'),    floatval($data['nrg'][11] ?? 0.0));
                SetValue($this->GetIDForIdent('Ampere'),      intval($data['amp'] ?? 0));
                SetValue($this->GetIDForIdent('Phasen'),      array_sum($data['pha'] ?? [0,0,0]));
                SetValue($this->GetIDForIdent('Energie'),     intval($data['wh'] ?? 0));
                // weitere Werte können leicht ergänzt werden
                break;

            case 'zielzeit':
                // Alles, was für die Zielzeitladung benötigt wird
                SetValue($this->GetIDForIdent('Status'),      intval($data['car'] ?? 0));
                SetValue($this->GetIDForIdent('Leistung'),    floatval($data['nrg'][11] ?? 0.0));
                SetValue($this->GetIDForIdent('Ampere'),      intval($data['amp'] ?? 0));
                SetValue($this->GetIDForIdent('Phasen'),      array_sum($data['pha'] ?? [0,0,0]));
                SetValue($this->GetIDForIdent('Energie'),     intval($data['wh'] ?? 0));
                break;

            case 'strompreis':
                // Alles, was für Strompreis-basiertes Laden benötigt wird
                SetValue($this->GetIDForIdent('Status'),      intval($data['car'] ?? 0));
                SetValue($this->GetIDForIdent('Leistung'),    floatval($data['nrg'][11] ?? 0.0));
                SetValue($this->GetIDForIdent('Ampere'),      intval($data['amp'] ?? 0));
                SetValue($this->GetIDForIdent('Phasen'),      array_sum($data['pha'] ?? [0,0,0]));
                SetValue($this->GetIDForIdent('Energie'),     intval($data['wh'] ?? 0));
                // aktueller Strompreis kommt extern rein (eigene Variable/Logik)
                break;

            case 'pvonly':
            default:
                // Standard: Nur PV-Modus, Werte für PV-Überschuss-Laden
                SetValue($this->GetIDForIdent('Status'),      intval($data['car'] ?? 0));
                SetValue($this->GetIDForIdent('Leistung'),    floatval($data['nrg'][11] ?? 0.0));
                SetValue($this->GetIDForIdent('Ampere'),      intval($data['amp'] ?? 0));
                SetValue($this->GetIDForIdent('Phasen'),      array_sum($data['pha'] ?? [0,0,0]));
                SetValue($this->GetIDForIdent('Energie'),     intval($data['wh'] ?? 0));
                break;
        }
    }

    // =========================================================================
    // 9. HILFSFUNKTIONEN & GETTER/SETTER
    // =========================================================================

    private function RegisterCarStateProfile()
    {
        $profile = 'GoE.CarState';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1); // 1 = Integer
            IPS_SetVariableProfileValues($profile, 0, 5, 1);
            IPS_SetVariableProfileAssociation($profile, 0, 'Unbekannt/Fehler', '', 0x888888);
            IPS_SetVariableProfileAssociation($profile, 1, 'Ladestation bereit, kein Fahrzeug', '', 0xAAAAAA);
            IPS_SetVariableProfileAssociation($profile, 2, 'Fahrzeug lädt', '', 0x00FF00);
            IPS_SetVariableProfileAssociation($profile, 3, 'Warte auf Fahrzeug', '', 0x0088FF);
            IPS_SetVariableProfileAssociation($profile, 4, 'Ladung beendet, Fahrzeug noch verbunden', '', 0xFFFF00);
            IPS_SetVariableProfileAssociation($profile, 5, 'Error', '', 0xFF0000);
        }
    }

}
