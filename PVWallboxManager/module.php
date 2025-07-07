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
        $this->RegisterPropertyString('WallboxIP', '');
        $this->RegisterPropertyInteger('RefreshInterval', 30);
        $this->RegisterPropertyBoolean('ModulAktiv', true);
        $this->RegisterPropertyBoolean('DebugLogging', false);
        $this->RegisterVariableString('Log', 'Modul-Log', '', 99);



        // Variablen nach API v2
        $this->RegisterCarStateProfile();
        $this->RegisterVariableInteger('Status',      'Fahrzeugstatus',         'GoE.CarStatus',    1);
        $this->RegisterVariableFloat('Leistung',      'Ladeleistung (W)',       '~Watt',            2);
        $this->RegisterVariableInteger('Ampere',      'Max. Ladestrom (A)',     '~Ampere',          3);
        $this->RegisterPhasesProfile();
        $this->RegisterVariableInteger('Phasen',      'Phasen aktiv',           'GoE.Phases',       4);
        $this->RegisterAlwProfile();
        $this->RegisterVariableBoolean('Freigabe',    'Ladefreigabe',           'GoE.ALW',          5);
        $this->RegisterVariableInteger('Kabelstrom',  'Kabeltyp (A)',           '~Ampere',          6);
        $this->RegisterVariableInteger('Fehlercode',  'Fehlercode',             '',                 7);
        $this->RegisterVariableInteger('Energie',     'Geladene Energie (Wh)',  '~Electricity.Wh',  8);

        // Timer für zyklische Abfrage (z.B. alle 30 Sek.)
        $this->RegisterTimer('UpdateStatus', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateStatus", "pvonly");');

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

    public function UpdateStatus(string $mode = 'pvonly')
    {
        $this->Log("Timer-Trigger: UpdateStatus (Modus: $mode)", 'debug');

        $now = date("d.m.Y H:i:s");
        $this->Log("Modul-Update gestartet: Modus = $mode, Zeit = $now", 'debug');

        // Modul aktiv?
        if (!$this->ReadPropertyBoolean('ModulAktiv')) {
            $this->Log("Modul ist inaktiv – keine Abfrage", 'warn');
            return;
        }

        $ip = $this->ReadPropertyString('WallboxIP');
        $url = "http://$ip/api/status";
        $json = @file_get_contents($url);

        if ($json === false) {
            $this->Log("Fehler: Keine Antwort von Wallbox ($url)", 'error');
            return;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            $this->Log("Fehler: Ungültiges JSON von Wallbox ($url)", 'error');
            return;
        }

        // Alle Werte defensiv auslesen
        $car         = isset($data['car'])         ? intval($data['car'])         : 0;
        $leistung    = (isset($data['nrg'][11]) && is_array($data['nrg'])) ? floatval($data['nrg'][11]) : 0.0;
        $ampere      = isset($data['amp'])         ? intval($data['amp'])         : 0;
        $phasen      = (isset($data['pha']) && is_array($data['pha']))     ? array_sum($data['pha'])    : 0;
        $energie     = isset($data['wh'])          ? intval($data['wh'])          : 0;
        $freigabe    = isset($data['alw'])         ? intval($data['alw'])         : 0;
        $kabelstrom  = isset($data['cbl'])         ? intval($data['cbl'])         : 0;
        $fehlercode  = isset($data['err'])         ? intval($data['err'])         : 0;

        // PHASEN
        $pha = $data['pha'] ?? [];
        $phasen = (is_array($pha) && count($pha) >= 6) ? array_sum(array_slice($pha, 3, 3)) : 0;
        SetValue($this->GetIDForIdent('Phasen'), $phasen);

        switch ($mode) {
        case 'manuell':
            $this->SetValueAndLogChange('Status',      $car,        'Fahrzeugstatus');
            $this->SetValueAndLogChange('Leistung',    $leistung,   'Ladeleistung');
            $this->SetValueAndLogChange('Ampere',      $ampere,     'Maximaler Ladestrom');
            $this->SetValueAndLogChange('Phasen',      $phasen,     'Phasen aktiv');
            $this->SetValueAndLogChange('Energie',     $energie,    'Geladene Energie');
            $this->SetValueAndLogChange('Freigabe',    (bool)$freigabe, 'Ladefreigabe');
            $this->SetValueAndLogChange('Kabelstrom',  $kabelstrom, 'Kabeltyp');
            $this->SetValueAndLogChange('Fehlercode',  $fehlercode, 'Fehlercode', '', 'warn');
            break;

        case 'pv2car':
        case 'zielzeit':
        case 'strompreis':
            // Diese Modi setzen im Grundsatz die gleichen Basiswerte, können aber später noch erweitert werden
            $this->SetValueAndLogChange('Status',      $car,        'Fahrzeugstatus');
            $this->SetValueAndLogChange('Leistung',    $leistung,   'Ladeleistung');
            $this->SetValueAndLogChange('Ampere',      $ampere,     'Maximaler Ladestrom');
            $this->SetValueAndLogChange('Phasen',      $phasen,     'Phasen aktiv');
            $this->SetValueAndLogChange('Energie',     $energie,    'Geladene Energie');
            $this->SetValueAndLogChange('Freigabe',    (bool)$freigabe, 'Ladefreigabe');
            $this->SetValueAndLogChange('Kabelstrom',  $kabelstrom, 'Kabeltyp');
            $this->SetValueAndLogChange('Fehlercode',  $fehlercode, 'Fehlercode', '', 'warn');
            break;

        case 'pvonly':
        default:
            // Standard: Nur PV-Modus, Werte für PV-Überschuss-Laden
            $this->SetValueAndLogChange('Status',      $car,        'Fahrzeugstatus');
            $this->SetValueAndLogChange('Leistung',    $leistung,   'Ladeleistung');
            $this->SetValueAndLogChange('Ampere',      $ampere,     'Maximaler Ladestrom');
            $this->SetValueAndLogChange('Phasen',      $phasen,     'Phasen aktiv');
            $this->SetValueAndLogChange('Energie',     $energie,    'Geladene Energie');
            $this->SetValueAndLogChange('Freigabe',    (bool)$freigabe, 'Ladefreigabe');
            $this->SetValueAndLogChange('Kabelstrom',  $kabelstrom, 'Kabeltyp');
            $this->SetValueAndLogChange('Fehlercode',  $fehlercode, 'Fehlercode', '', 'warn');
            break;
        }
    }

    // =========================================================================
    // 9. HILFSFUNKTIONEN & GETTER/SETTER
    // =========================================================================

    private function RegisterCarStateProfile()
        {
            $profile = 'GoE.CarStatus';
            if (!IPS_VariableProfileExists($profile)) {
                IPS_CreateVariableProfile($profile, 1); // 1 = Integer
                IPS_SetVariableProfileValues($profile, 1, 4, 1);
                IPS_SetVariableProfileAssociation($profile, 0, 'Unbekannt/Firmwarefehler', '', 0x888888);
                IPS_SetVariableProfileAssociation($profile, 1, 'Ladestation bereit, kein Fahrzeug', '', 0xAAAAAA);
                IPS_SetVariableProfileAssociation($profile, 2, 'Fahrzeug lädt', '', 0x00FF00);
                IPS_SetVariableProfileAssociation($profile, 3, 'Warte auf Fahrzeug', '', 0x0088FF);
                IPS_SetVariableProfileAssociation($profile, 4, 'Ladung beendet, Fahrzeug noch verbunden', '', 0xFFFF00);
                IPS_SetVariableProfileAssociation($profile, 5, 'Fehler', '', 0xFF0000);
            }
    }

    private function RegisterPhasesProfile()
    {
        $profile = 'GoE.Phases';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1); // 1 = Integer
            IPS_SetVariableProfileAssociation($profile, 0, 'Keine', '', 0x888888);
            IPS_SetVariableProfileAssociation($profile, 1, '1-phasig', '', 0x00ADEF);
            IPS_SetVariableProfileAssociation($profile, 2, '2-phasig', '', 0x009900);
            IPS_SetVariableProfileAssociation($profile, 3, '3-phasig', '', 0xFF9900);
        }
    }

    private function RegisterAlwProfile()
    {
        $profile = 'GoE.ALW';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 0); // 0 = Boolean
            IPS_SetVariableProfileAssociation($profile, false, 'Nicht freigegeben', '', 0xFF4444);
            IPS_SetVariableProfileAssociation($profile, true,  'Laden freigegeben', '', 0x44FF44);
        }
    }

    // =========================================================================
    // 8. LOGGING / STATUSMELDUNGEN / DEBUG
    // =========================================================================

    private function Log($msg, $level = 'info')
    {
        // Icons je nach Level
        $icons = [
            'info'  => '✅',
            'warn'  => '⚠️',
            'error' => '❌',
            'debug' => '🐞'
        ];
        $icon = $icons[$level] ?? '';

        // Debug aus, wenn nicht aktiviert
        if ($level === 'debug' && !$this->ReadPropertyBoolean('DebugLogging')) {
            return;
        }

        // Format: [LEVEL] Icon Nachricht
        $prefix = '[PVWM] ';
        $levelStr = strtoupper($level);
        $logLine = "[$levelStr] $icon $prefix$msg";

        // Symcon-Systemlog
        IPS_LogMessage("PVWallboxManager", $logLine);

        // WebFront-Log (Variable "Log")
        $logVarID = @$this->GetIDForIdent('Log');
        if ($logVarID) {
            $old = GetValueString($logVarID);
            $new = date("d.m.Y H:i:s") . " | $logLine\n" . $old;
            SetValueString($logVarID, mb_substr($new, 0, 6000)); // max 6000 Zeichen
        }
    }

    private function SetValueAndLogChange($ident, $newValue, $caption = '', $unit = '', $level = 'info')
    {
        $varID = @$this->GetIDForIdent($ident);
        if ($varID === false) {
            $this->Log("Variable mit Ident '$ident' nicht gefunden!", 'warn');
            return;
        }
        $oldValue = GetValue($varID);

        // Wenn identisch, nichts tun
        if ($oldValue === $newValue) {
            return;
        }

        // Werte ggf. als Klartext formatieren
        $formatValue = function($value) use ($ident, $varID) {
            // Profile-Auswertung (z. B. für Status, Phasen, Freigabe)
            $profile = IPS_GetVariable($varID)['VariableCustomProfile'] ?: IPS_GetVariable($varID)['VariableProfile'];
            if ($profile == 'GoE.CarStatus') {
                $map = [
                    0 => 'Unbekannt/Firmwarefehler',
                    1 => 'Bereit, kein Fahrzeug',
                    2 => 'Fahrzeug lädt',
                    3 => 'Warte auf Fahrzeug',
                    4 => 'Ladung beendet',
                    5 => 'Fehler'
                ];
                return $map[intval($value)] ?? $value;
            }
            if ($profile == 'GoE.Phases') {
                $map = [0 => 'Keine', 1 => '1-phasig', 2 => '2-phasig', 3 => '3-phasig'];
                return $map[intval($value)] ?? $value;
            }
            if ($profile == 'GoE.ALW') {
                return ($value ? 'Ladefreigabe: aktiv' : 'Ladefreigabe: aus');
            }
            if ($profile == '~Ampere') {
                return number_format($value, 0, ',', '.') . ' A';
            }
            if ($profile == '~Watt') {
                return number_format($value, 0, ',', '.') . ' W';
            }
            if ($profile == '~Electricity.Wh') {
                return number_format($value, 0, ',', '.') . ' Wh';
            }
            // Standard: einfach Zahl/Bool
            if (is_bool($value)) {
                return $value ? 'ja' : 'nein';
            }
            if (is_numeric($value)) {
                return number_format($value, 0, ',', '.');
            }
            return strval($value);
        };

        // Meldung zusammensetzen
        $oldText = $formatValue($oldValue);
        $newText = $formatValue($newValue);
        if ($caption) {
            $msg = "$caption geändert: $oldText → $newText";
        } else {
            $msg = "Wert geändert: $oldText → $newText";
        }
        $this->Log($msg, $level);

        SetValue($varID, $newValue);
    }



}
