<?php

class PVWallboxManager extends IPSModule
{

    // =========================================================================
    // 1. KONSTRUKTOR & INITIALISIERUNG
    // =========================================================================

    public function Create()
    {
        // Immer zuerst
        parent::Create();

        $this->RegisterCustomProfiles();
        $this->RegisterAttributeInteger('MarketPricesTimerInterval', 0);
        $this->RegisterAttributeBoolean('MarketPricesActive', false);

        // Properties aus form.json
        $this->RegisterPropertyString('WallboxIP', '0.0.0.0');
        $this->RegisterPropertyString('WallboxAPIKey', '');
        $this->RegisterPropertyInteger('RefreshInterval', 30);
        $this->RegisterPropertyBoolean('ModulAktiv', true);
        $this->RegisterPropertyBoolean('DebugLogging', false);
        $this->RegisterPropertyInteger('MinAmpere', 6);   // Minimal möglicher Ladestrom
        $this->RegisterPropertyInteger('MaxAmpere', 16);  // Maximal möglicher Ladestrom
        $this->RegisterPropertyInteger('Phasen1Schwelle', 3680); // Beispiel: 1-phasig ab < 1.400 W
        $this->RegisterPropertyInteger('Phasen3Schwelle', 4140); // Beispiel: 3-phasig ab > 3.700 W
        $this->RegisterAttributeInteger('PV2CarStartZaehler', 0);
        $this->RegisterAttributeInteger('PV2CarStopZaehler', 0);

        // Property für Hausakku SoC und Schwelle
        $this->RegisterPropertyInteger('HausakkuSOCID', 0); // VariableID für SoC des Hausakkus (Prozent)
        $this->RegisterPropertyInteger('HausakkuSOCVollSchwelle', 95); // Schwelle ab wann als „voll“ gilt (Prozent)

        // Fahrzeugdaten
        $this->RegisterPropertyInteger('CarSOCID', 0);           // Variable-ID aktueller SoC
        $this->RegisterPropertyInteger('CarTargetSOCID', 0);     // Variable-ID Ziel-SoC
        $this->RegisterPropertyFloat('CarBatteryCapacity', 0); // Standardwert für z.B. ID.3 Pure (52 kWh)

        // Hysterese-Zyklen als Properties
        $this->RegisterPropertyInteger('Phasen1Limit', 3); // z.B. 3 = nach 3x Umschalten
        $this->RegisterPropertyInteger('Phasen3Limit', 3);
        $this->RegisterPropertyInteger('MinLadeWatt', 1400);      // Schwelle zum Starten (W)
        $this->RegisterPropertyInteger('MinStopWatt', 1100);      // Schwelle zum Stoppen (W)
        $this->RegisterPropertyInteger('StartLadeHysterese', 3);  // Zyklen Start-Hysterese
        $this->RegisterPropertyInteger('StopLadeHysterese', 3);   // Zyklen Stop-Hysterese
        $this->RegisterPropertyInteger('InitialCheckInterval', 10); // 0 = deaktiviert, 5–60 Sek.

        $this->RegisterVariableBoolean('ModulAktiv_Switch', '✅ Modul aktiv', '~Switch', 900);
        $this->EnableAction('ModulAktiv_Switch');
    
        // Hysterese-Zähler (werden NICHT im WebFront angezeigt)
        $this->RegisterAttributeInteger('Phasen1Zaehler', 0);
        $this->RegisterAttributeInteger('Phasen3Zaehler', 0);
        $this->RegisterAttributeInteger('LadeStartZaehler', 0);
        $this->RegisterAttributeInteger('LadeStopZaehler', 0);
        $this->RegisterAttributeString('HausverbrauchAbzWallboxBuffer', '[]');
        $this->RegisterAttributeFloat('HausverbrauchAbzWallboxLast', 0.0);
        $this->RegisterAttributeInteger('NoPowerCounter', 0);
        $this->RegisterAttributeInteger('LastTimerStatus', -1);

        // Variablen nach API v2
        $this->RegisterVariableInteger('Status',        'Status',                                   'PVWM.CarStatus',       1);
        $this->RegisterVariableInteger('AccessStateV2', 'Wallbox Modus',                            'PVWM.AccessStateV2',   2);
        $this->RegisterVariableFloat('Leistung',        'Aktuelle Ladeleistung zum Fahrzeug (W)',   'PVWM.Watt',            3);
        IPS_SetIcon($this->GetIDForIdent('Leistung'),   'Flash');
        $this->RegisterVariableInteger('Ampere',        'Max. Ladestrom (A)',                       'PVWM.Ampere',          4);
        IPS_SetIcon($this->GetIDForIdent('Ampere'),     'Energy');

        $this->RegisterVariableBoolean('Freigabe',      'Ladefreigabe',                             'PVWM.ALW',             6);
        $this->RegisterVariableInteger('Kabelstrom',    'Kabeltyp (A)',                             'PVWM.AmpereCable',     7);
        IPS_SetIcon($this->GetIDForIdent('Kabelstrom'), 'Energy');
        $this->RegisterVariableFloat('Energie',         'Geladene Energie (Wh)',                    'PVWM.Wh',              8);
        $this->RegisterVariableInteger('Fehlercode',    'Fehlercode',                               'PVWM.ErrorCode',       9);

        // === 3. Energiequellen ===
        $this->RegisterPropertyInteger('PVErzeugungID', 0);
        $this->RegisterPropertyString('PVErzeugungEinheit', 'W');
        //$this->RegisterPropertyInteger('NetzeinspeisungID', 0);
        $this->RegisterPropertyString('NetzeinspeisungEinheit', 'W');
        $this->RegisterPropertyBoolean('InvertNetzeinspeisung', false);
        $this->RegisterPropertyInteger('HausverbrauchID', 0);
        $this->RegisterPropertyString('HausverbrauchEinheit', 'W');
        $this->RegisterPropertyBoolean('InvertHausverbrauch', false);
        $this->RegisterPropertyInteger('BatterieladungID', 0);
        $this->RegisterPropertyString('BatterieladungEinheit', 'W');
        $this->RegisterPropertyBoolean('InvertBatterieladung', false);

        $this->RegisterPropertyBoolean('UseMarketPrices', false);
        $this->RegisterPropertyString('MarketPriceProvider', 'awattar_at');
        $this->RegisterPropertyString('MarketPriceAPI', '');

        $this->RegisterVariableFloat('CurrentSpotPrice','Aktueller Börsenpreis (ct/kWh)',                   'PVWM.CentPerKWh', 30);
        $this->RegisterVariableString('MarketPrices', 'Börsenpreis-Vorschau', '', 31);

        $this->RegisterVariableString('MarketPricesPreview', '📊 Börsenpreis-Vorschau (HTML)', '~HTMLBox', 32);

        // Zielzeit für Zielzeitladung
        $this->RegisterVariableInteger('TargetTime', 'Zielzeit', '~UnixTimestampTime', 20);
        IPS_SetIcon($this->GetIDForIdent('TargetTime'), 'clock');

        // === Modul-Variablen für Visualisierung, Status, Lademodus etc. ===
        $this->RegisterVariableFloat('PV_Ueberschuss','☀️ PV-Überschuss (W)',                               'PVWM.Watt', 10);
        IPS_SetIcon($this->GetIDForIdent('PV_Ueberschuss'), 'solar-panel');

        $this->RegisterVariableInteger('PV_Ueberschuss_A', '⚡ PV-Überschuss (A)',                             'PVWM.Ampere', 12);
        IPS_SetIcon($this->GetIDForIdent('PV_Ueberschuss_A'), 'Energy');

        // Hausverbrauch (W)
        $this->RegisterVariableFloat('Hausverbrauch_W','🏠 Hausverbrauch (W)',                              'PVWM.Watt', 13);
        IPS_SetIcon($this->GetIDForIdent('Hausverbrauch_W'), 'home');

        // Hausverbrauch abzügl. Wallbox (W) – wie vorher empfohlen
        $this->RegisterVariableFloat('Hausverbrauch_abz_Wallbox','🏠 Hausverbrauch abzügl. Wallbox (W)',    'PVWM.Watt',15);
        IPS_SetIcon($this->GetIDForIdent('Hausverbrauch_abz_Wallbox'), 'home');

        // Lademodi
        $this->RegisterVariableBoolean('ManuellLaden', '🔌 Manuell: Vollladen aktiv', '~Switch', 40);
        $this->EnableAction('ManuellLaden');
        $this->RegisterVariableBoolean('PV2CarModus', '🌞 PV-Anteil laden', '~Switch', 41);
        IPS_SetIcon($this->GetIDForIdent('PV2CarModus'), 'SolarPanel');
        $this->EnableAction('PV2CarModus');
        $this->RegisterVariableBoolean('ZielzeitLaden', '⏰ Zielzeit-Ladung', '~Switch', 42);
        $this->RegisterVariableInteger('PVAnteil',    'PV-Anteil (%)',                                      'PVWM.Percent',43);
        IPS_SetIcon($this->GetIDForIdent('PVAnteil'), 'Percent');
        $this->EnableAction('PVAnteil');

        // Im Create()-Bereich, nach den anderen Variablen
        $this->RegisterVariableInteger('PhasenmodusEinstellung', '🟢 Wallbox-Phasen Soll (Einstellung)', 'PVWM.PSM', 50);
        IPS_SetIcon($this->GetIDForIdent('PhasenmodusEinstellung'), 'Lightning');

        $this->RegisterVariableInteger('Phasenmodus', '🔵 Genutzte Phasen (Fahrzeug)', 'PVWM.PhasenText', 51);
        IPS_SetIcon($this->GetIDForIdent('Phasenmodus'), 'Lightning');

        // --- Manuell: Ampere und Phasen einstellbar machen ---
        $this->RegisterVariableInteger('ManuellAmpere', '🔌 Ampere (manuell)', 'PVWM.Ampere', 44);
        $this->EnableAction('ManuellAmpere');
        if ($this->GetValue('ManuellAmpere') < $this->ReadPropertyInteger('MinAmpere')) {
            $this->SetValue('ManuellAmpere', $this->ReadPropertyInteger('MaxAmpere')); // Default = MaxAmpere
        }

        $this->RegisterVariableInteger('ManuellPhasen', '🔀 Phasen (manuell)', 'PVWM.PSM', 45);
        $this->EnableAction('ManuellPhasen');
        if (!in_array($this->GetValue('ManuellPhasen'), [1, 2])) {
            $this->SetValue('ManuellPhasen', 2); // Default = 3-phasig
        }

        $this->RegisterVariableString('StatusInfo', 'ℹ️ Status-Info', '~HTMLBox', 70);
        $this->RegisterAttributeString('LastStatusInfoHTML', '');

        $this->RegisterAttributeInteger('LetztePhasenUmschaltung', 0);

        // Timer für zyklische Abfrage (z.B. alle 30 Sek.)
        $this->RegisterTimer('PVWM_UpdateStatus', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateStatus", "pvonly");');
        $this->RegisterTimer('PVWM_UpdateMarketPrices', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateMarketPrices", "");');
        
        // Schnell-Poll-Timer für Initialcheck
        $this->RegisterTimer('PVWM_InitialCheck', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateStatus", "pvonly");');

        $this->SetTimerNachModusUndAuto();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Synchronisiere WebFront-Variable mit Property
        $aktiv = $this->ReadPropertyBoolean('ModulAktiv');
        $this->SetValue('ModulAktiv_Switch', $aktiv);

        // Timer zurücksetzen
        $this->SetTimerInterval('PVWM_UpdateStatus', 0);
        $this->SetTimerInterval('PVWM_UpdateMarketPrices', 0);
        $this->SetTimerInterval('PVWM_InitialCheck', 0);

        // Timer und Events wieder sauber initialisieren
        $this->SetTimerNachModusUndAuto();
        $this->SetMarketPriceTimerZurVollenStunde();
        $this->UpdateHausverbrauchEvent();
    }

    // =========================================================================
    // 2. PROFILE & VARIABLEN-PROFILE
    // =========================================================================

    private function RegisterCustomProfiles()
    {
        // Hilfsfunktion für Anlage/Löschen/Suffix/Icon/Assoziationen
        $create = function($name, $type, $digits, $suffix, $icon = '', $associations = null) {
            if (IPS_VariableProfileExists($name)) {
                IPS_DeleteVariableProfile($name);
            }
            IPS_CreateVariableProfile($name, $type);
            IPS_SetVariableProfileDigits($name, $digits);
            IPS_SetVariableProfileText($name, '', $suffix);
            if (!empty($icon)) {
                IPS_SetVariableProfileIcon($name, $icon);
            }
            if (is_array($associations)) {
                foreach ($associations as $idx => $a) {
                    // [Wert, Name, Icon, Farbe]
                    IPS_SetVariableProfileAssociation($name, $a[0], $a[1], $a[2] ?? '', $a[3] ?? -1);
                }
            }
        };

        // Integer-Profile (mit Assoziationen, wo nötig)
        $create('PVWM.CarStatus', VARIABLETYPE_INTEGER, 0,  '', 'Car', [
            [0, 'Unbekannt/Firmwarefehler',                 'Question',     0x888888],
            [1, 'Bereit, kein Fahrzeug',                    'Parking',      0xAAAAAA],
            [2, 'Fahrzeug lädt',                            'Lightning',    0x00FF00],
            [3, 'Fahrzeug verbunden / Bereit zum Laden',    'Car',          0x0088FF],
            [4, 'Ladung beendet, Fahrzeug noch verbunden',  'Check',        0xFFFF00],
            [5, 'Fehler',                                   'Alert',        0xFF0000]
        ]);

        $create('PVWM.ErrorCode', VARIABLETYPE_INTEGER, 0, '', 'Alert', [
            [0,  'Kein Fehler',                 '', 0x44FF44],
            [1,  'FI AC',                       '', 0xFFAA00],
            [2,  'FI DC',                       '', 0xFFAA00],
            [3,  'Phasenfehler',                '', 0xFF4444],
            [4,  'Überspannung',                '', 0xFF4444],
            [5,  'Überstrom',                   '', 0xFF4444],
            [6,  'Diodenfehler',                '', 0xFF4444],
            [7,  'PP ungültig',                 '', 0xFF4444],
            [8,  'GND ungültig',                '', 0xFF4444],
            [9,  'Schütz hängt',                '', 0xFF4444],
            [10, 'Schütz fehlt',                '', 0xFF4444],
            [11, 'FI unbekannt',                '', 0xFF4444],
            [12, 'Unbekannter Fehler',          '', 0xFF4444],
            [13, 'Übertemperatur',              '', 0xFF4444],
            [14, 'Keine Kommunikation',         '', 0xFF4444],
            [15, 'Verriegelung klemmt offen',   '', 0xFF4444],
            [16, 'Verriegelung klemmt verriegelt', '', 0xFF4444],
            [20, 'Reserviert 20',               '', 0xAAAAAA],
            [21, 'Reserviert 21',               '', 0xAAAAAA],
            [22, 'Reserviert 22',               '', 0xAAAAAA],
            [23, 'Reserviert 23',               '', 0xAAAAAA],
            [24, 'Reserviert 24',               '', 0xAAAAAA]
        ]);

        $create('PVWM.AccessStateV2', VARIABLETYPE_INTEGER, 0, '', 'Lock', [
            [0, 'Neutral (Wallbox entscheidet)', 'LockOpen', 0xAAAAAA],
            [1, 'Nicht Laden (gesperrt)',        'Lock', 0xFF4444],
            [2, 'Laden (erzwungen)',             'Power', 0x44FF44]
        ]);

        $create('PVWM.PSM', VARIABLETYPE_INTEGER, 0, '', 'Lightning', [
//            [0, 'Auto',     'Gears', 0xAAAAAA],
            [1, '1-phasig', 'Plug', 0x00ADEF],
            [2, '3-phasig', 'Plug', 0xFF9900]
        ]);

        $create('PVWM.PhasenText', VARIABLETYPE_INTEGER, 0, '', 'Lightning', [
            [0, 'Keine Ladung', '', 0xAAAAAA],
            [1, '1-phasig',     '', 0x2186eb],
            [2, '2-phasig',     '', 0x2186eb],
            [3, '3-phasig',     '', 0x2186eb]
        ]);

        $create('PVWM.ALW', VARIABLETYPE_BOOLEAN, 0, '', 'Power', [
            [false, 'Nicht freigegeben', 'Close', 0xFF4444],
            [true,  'Laden freigegeben', 'Power', 0x44FF44]
        ]);

        $create('PVWM.AmpereCable', VARIABLETYPE_INTEGER, 0, ' A', 'Energy');
        
        // Die bisherigen Profile
        $create('PVWM.Ampere',      VARIABLETYPE_INTEGER, 0, ' A',      'Energy');
        IPS_SetVariableProfileValues('PVWM.Ampere', 6, 16, 1);
        $create('PVWM.Percent',     VARIABLETYPE_INTEGER, 0, ' %',      'Percent');
        IPS_SetVariableProfileValues('PVWM.Percent', 0, 100, 1);
        $create('PVWM.Watt',        VARIABLETYPE_FLOAT,   0, ' W',      'Flash');
        $create('PVWM.W',           VARIABLETYPE_FLOAT,   0, ' W',      'Flash');
        $create('PVWM.CentPerKWh',  VARIABLETYPE_FLOAT,   3, ' ct/kWh', 'Euro');
        $create('PVWM.Wh',          VARIABLETYPE_FLOAT,   0, ' Wh',     'Lightning');

    }

    private function GetProfileText($ident)
    {
        $vid = @$this->GetIDForIdent($ident);
        if (!$vid) return '';
        $val = GetValue($vid);
        $v = IPS_GetVariable($vid);
        $profile = $v['VariableCustomProfile'] ?: $v['VariableProfile'];
        if (!$profile) return (string)$val;

        $assos = IPS_GetVariableProfile($profile)['Associations'];
        foreach ($assos as $a) {
            if ($a['Value'] == $val) return $a['Name'];
        }
        return (string)$val;
    }

    // =========================================================================
    // 3. EVENTS & REQUESTACTION
    // =========================================================================

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'ModulAktiv_Switch':
                $this->SetValue('ModulAktiv_Switch', $Value);
                IPS_SetProperty($this->InstanceID, 'ModulAktiv', $Value);
                IPS_ApplyChanges($this->InstanceID);
            if (!$Value) {
                $this->SetForceState(1);
                $this->LogTemplate('info', 'Modul deaktiviert – Wallbox auf Nicht Laden gestellt (FRC=1).');
            }
            break;

            case "UpdateStatus":
                $this->UpdateStatus($Value);
                break;

            case "UpdateMarketPrices":
                $this->AktualisiereMarktpreise();
                $this->SetTimerInterval('PVWM_UpdateMarketPrices', 3600000);
                break;

        case "ManuellLaden":
            // Nur EIN Modus darf aktiv sein!
            if ($Value) {
                $this->SetValue('ManuellLaden', true);
                $this->SetValue('PV2CarModus', false);
                // (später: weitere Modi hier deaktivieren)
                $this->LogTemplate('info', "🔌 Manuelles Vollladen aktiviert.");
            } else {
                $this->SetValue('ManuellLaden', false);
                $this->LogTemplate('info', "🔌 Manuelles Vollladen deaktiviert – zurück in PVonly-Modus.");
                // Nach Beenden: zurück auf 1-phasig, 6A, 0A
                $this->SetPhaseMode(1);
                $this->SetChargingCurrent(6);
                $this->SetValueAndLogChange('PV_Ueberschuss_A', 0, 'PV-Überschuss (A)', 'A', 'ok');
                $this->LogTemplate('ok', "Nach Deaktivierung Manuell: Wallbox auf 1-phasig/6A/0A zurückgesetzt.");
            }
            $this->SetTimerNachModusUndAuto();
            $this->UpdateStatus('manuell');
            break;

        case "PV2CarModus":
            // Nur EIN Modus darf aktiv sein!
            if ($Value) {
                $this->SetValue('PV2CarModus', true);
                $this->SetValue('ManuellLaden', false);
                $this->LogTemplate('info', "🌞 PV-Anteil laden aktiviert.");
            } else {
                $this->SetValue('PV2CarModus', false);
                $this->LogTemplate('info', "🌞 PV-Anteil laden deaktiviert – zurück in PVonly-Modus.");
                // Nach Beenden: zurück auf 1-phasig, 6A, 0A
                $this->SetPhaseMode(1);
                $this->SetChargingCurrent(6);
                $this->SetValueAndLogChange('PV_Ueberschuss_A', 0, 'PV-Überschuss (A)', 'A', 'ok');
                $this->LogTemplate('ok', "Nach Deaktivierung PV2Car: Wallbox auf 1-phasig/6A/0A zurückgesetzt.");
            }
            $this->SetTimerNachModusUndAuto();
            $this->UpdateStatus('pv2car');
            break;

        case "PVAnteil":
            // Wertebereich checken (0-100%)
            $value = max(0, min(100, intval($Value)));
            $this->SetValue('PVAnteil', $value);
            $this->LogTemplate('info', "🌞 PV-Anteil geändert: {$value}%");
            // Sofortige Wirkung, wenn PV2Car aktiv ist
            if ($this->GetValue('PV2CarModus')) {
                $this->UpdateStatus('pv2car');
            }
            break;
        
        case "ManuellAmpere":
            $amp = max($this->ReadPropertyInteger('MinAmpere'), min($this->ReadPropertyInteger('MaxAmpere'), intval($Value)));
            $this->SetValue('ManuellAmpere', $amp);
            if ($this->GetValue('ManuellLaden')) $this->UpdateStatus('manuell');
            break;

        case "ManuellPhasen":
            $ph = ($Value == 2) ? 2 : 1; // Nur 1 oder 2 zulassen
            $this->SetValue('ManuellPhasen', $ph);
            if ($this->GetValue('ManuellLaden')) $this->UpdateStatus('manuell');
            break;

        default:
            throw new Exception("Invalid Ident: $Ident");
        }
    }

    // =========================================================================
    // 4. WALLBOX-KOMMUNIKATION (API)
    // =========================================================================
    private function getStatusFromCharger()
    {
        $ip = trim($this->ReadPropertyString('WallboxIP'));

        // 1. Check: IP konfiguriert?
        if ($ip == "" || $ip == "0.0.0.0") {
            $this->LogTemplate('error', "Keine IP-Adresse für Wallbox konfiguriert.");
            //$this->SetStatus(200); // Symcon-Status: Konfiguration fehlt
            return false;
        }
        // 2. Check: IP gültig?
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->LogTemplate('error', "Ungültige IP-Adresse konfiguriert: $ip");
            $this->SetStatus(201); // Symcon-Status: Konfigurationsfehler
            return false;
        }
        // 3. Check: Erreichbar (Ping Port 80)?
        if (!$this->ping($ip, 80, 1)) {
            $this->LogTemplate('error', "Wallbox unter $ip:80 nicht erreichbar.");
            //$this->SetStatus(202); // Symcon-Status: Keine HTTP-Antwort
            return false;
        }

        // 4. HTTP-Request via cURL, V2 API bevorzugen
        $url = "http://$ip/api/status";
        $json = false;
        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            $json = curl_exec($ch);
            curl_close($ch);
        } catch (Exception $e) {
            $this->LogTemplate('error', "HTTP Fehler: " . $e->getMessage());
            //$this->SetStatus(203);
            return false;
        }

        if ($json === false || strlen($json) < 2) {
            $this->LogTemplate('error', "Fehler: Keine Antwort von Wallbox ($url)");
            //$this->SetStatus(203);
            return false;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            $this->LogTemplate('error', "Fehler: Ungültiges JSON von Wallbox ($url)");
            //$this->SetStatus(204);
            return false;
        }

        //$this->SetStatus(102); // Alles OK (optional)
        return $data;
    }

    private function ping($host, $port = 80, $timeout = 1)
    {
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($fp) {
            fclose($fp);
            return true;
        }
        return false;
    }

    // =========================================================================
    // 5. HAUPT-STEUERLOGIK
    // =========================================================================
    public function UpdateStatus(string $mode = 'pvonly')
    {
        // 0) Start-Log
        $this->LogTemplate('debug', "UpdateStatus getriggert (Modus: $mode, Zeit: " . date("H:i:s") . ")");

        // 1) Status von der Wallbox holen
        $data = $this->getStatusFromCharger();
        if ($data === false) {
            $this->ResetWallboxVisualisierungKeinFahrzeug();
            $this->LogTemplate('debug', "Wallbox nicht erreichbar – Visualisierungswerte zurückgesetzt.");
            $this->UpdateStatusAnzeige();
            return;
        }

        // 2) Phasenzahl ermitteln (für spätere Modus-Methoden)
        $psm    = isset($data['psm']) ? intval($data['psm']) : 0;
        $phasen = 1;
        if (isset($data['nrg'][4], $data['nrg'][5], $data['nrg'][6])) {
            $schluss = 1.5;
            $cnt = 0;
            foreach ([$data['nrg'][4], $data['nrg'][5], $data['nrg'][6]] as $strom) {
                if (abs(floatval($strom)) > $schluss) {
                    $cnt++;
                }
            }
            $phasen = max(1, $cnt);
        }

        // 3) Hausverbrauch für WebFront aktualisieren (ohne Überschuss)
        $energyRaw = $this->gatherEnergyData();
        $this->SetValueAndLogChange('Hausverbrauch_W',           $energyRaw['haus'],                           'Hausverbrauch (W)');
        $this->SetValueAndLogChange(
            'Hausverbrauch_abz_Wallbox',
            max(0, $energyRaw['haus'] - $energyRaw['wallbox']),
            'Hausverbrauch abzgl. Wallbox (W)'
        );

        // 4) Wallbox-Status-Variablen auslesen & schreiben
        $car        = isset($data['car'])  ? intval($data['car'])  : 0;
        $leistung   = $data['nrg'][11]     ?? 0.0;
        $ampereWB   = $data['amp']         ?? 0;
        $energie    = $data['wh']          ?? 0;
        $freigabe   = (bool)($data['alw']   ?? false);
        $kabelstrom = $data['cbl']         ?? 0;
        $fehlercode = $data['err']         ?? 0;

        $frcRaw     = $data['frc']             ?? null;
        $stateRaw   = $data['accessStateV2']   ?? null;
        $accessStateV2 = ($frcRaw === 2 || $stateRaw === 2) ? 2 : 1;

        $this->SetValueAndLogChange('PhasenmodusEinstellung', $psm,     'Phasenmodus (Einstellung)', '', 'debug');
        $this->SetValueAndLogChange('Phasenmodus',           $phasen, 'Genutzte Phasen',            '', 'debug');
        $this->SetValueAndLogChange('Status',                $car,        'Status');
        $this->SetValueAndLogChange('AccessStateV2',         $accessStateV2, 'Wallbox Modus');
        $this->SetValueAndLogChange('Leistung',              $leistung,   'Aktuelle Ladeleistung zum Fahrzeug', 'W');
        $this->SetValueAndLogChange('Ampere',                $ampereWB,   'Maximaler Ladestrom',             'A');
        $this->SetValueAndLogChange('Energie',               $energie,    'Geladene Energie',                'Wh');
        $this->SetValueAndLogChange('Freigabe',              $freigabe,   'Ladefreigabe');
        $this->SetValueAndLogChange('Kabelstrom',            $kabelstrom,'Kabeltyp');
        $this->SetValueAndLogChange('Fehlercode',            $fehlercode,'Fehlercode','', 'warn');

        // optional: SOC-Logging wenn gerade geladen
        if ($accessStateV2 === 2) {
            // … SOC-Logging wie bisher …
        }

        // 5) Automatisches Ladeende prüfen
        $this->PruefeLadeendeAutomatisch();

        // 6) Modi-Steuerung: hier wird jeweils in der Modus-Methode die Überschuss-Berechnung gemacht
        if ($car > 1 && $this->FahrzeugVerbunden($data)) {
            if ($this->GetValue('ManuellLaden')) {
                $this->ModusManuellVollladen($data);
            }
            elseif ($this->GetValue('PV2CarModus')) {
                $this->ModusPV2CarLaden($data);
            }
            else {
                $this->ModusPVonlyLaden($data, $phasen, $mode);
            }
        }
        else {
            $this->ResetLademodiWennKeinFahrzeug();
            $this->SetTimerNachModusUndAuto();
        }

        // 7) WebFront-Anzeige aktualisieren
        $this->UpdateStatusAnzeige();
    }

    private function ModusPVonlyLaden(array $data, int $anzPhasenAlt, string $mode = 'pvonly')
    {
        if (!$this->FahrzeugVerbunden($data)) {
            $this->ResetLademodiWennKeinFahrzeug();
            return;
        }

        // 1) Energie-Daten holen
        $energy = $this->gatherEnergyData();

        // 2) Hausverbrauch filtern
        $energy = $this->applyFilters($energy);

        // 3) Überschuss & Ampere berechnen
        $surplus = $this->calculateSurplus($energy, $anzPhasenAlt, true);
        $pvUeberschuss = $surplus['ueberschuss_w'];
        $ampere        = $surplus['ueberschuss_a'];

        // 4) Phasenmodus nur bei Änderung setzen
        $this->PruefeUndSetzePhasenmodus($pvUeberschuss);

        // 5) Ladefreigabe gemäß Hysterese berechnen
        $desiredFRC = $this->BerechneLadefreigabeMitHysterese($pvUeberschuss);

        // 6) Ladefreigabe setzen
        $anzPhasenNeu = max(1, $this->GetValue('Phasenmodus'));
        $this->SteuerungLadefreigabe(
            $pvUeberschuss,
            $mode,
            $ampere,
            $anzPhasenNeu,
            $desiredFRC
        );

            // 7) Ladefreigabe setzen
            $anzPhasenNeu = max(1, $this->GetValue('Phasenmodus'));
            $this->SteuerungLadefreigabe(
                $pvUeberschuss,
                $mode,
                $ampere,
                $anzPhasenNeu,
                $sollFRC
            );
        }

        private function ModusManuellVollladen(array $data)
        {
            if (!$this->FahrzeugVerbunden($data)) {
                // Kein Fahrzeug → Lademodi zurücksetzen und beenden
                $this->ResetLademodiWennKeinFahrzeug();
                return;
            }

            // 1. Benutzervorgaben einlesen und validieren
            $anzPhasenGewuenscht = $this->GetValue('ManuellPhasen') == 2 ? 2 : 1;
            $ampereGewuenscht    = intval($this->GetValue('ManuellAmpere'));
            $minAmp = $this->ReadPropertyInteger('MinAmpere');
            $maxAmp = $this->ReadPropertyInteger('MaxAmpere');
            $ampereGewuenscht = max($minAmp, min($maxAmp, $ampereGewuenscht));

            // 2. Phasenmodus nur setzen, wenn er sich ändert
            $aktPhasen = $this->GetValue('Phasenmodus');
            if ($aktPhasen !== $anzPhasenGewuenscht) {
                $this->SetPhaseMode($anzPhasenGewuenscht);
                $this->LogTemplate('debug', "Manuell: Phasenmodus gewechselt {$aktPhasen} → {$anzPhasenGewuenscht}");
            }

            // 3. Tatsächliche Phasenzahl auslesen
            $anzPhasenIst = max(1, $this->GetValue('Phasenmodus'));

            // 4. Für Visualisierung Überschuss berechnen
            $energy   = $this->gatherEnergyData();
            $energy   = $this->applyFilters($energy);
            // false → kein doppeltes Debug-Log hier, Logging übernimmt calculateSurplus wenn gewünscht
            $surplus  = $this->calculateSurplus($energy, $anzPhasenIst, false);

            $ueberschuss_w = $surplus['ueberschuss_w'];
            $ueberschuss_a = $surplus['ueberschuss_a'];

            $this->SetValue('PV_Ueberschuss',   $ueberschuss_w);
            $this->SetValue('PV_Ueberschuss_A', $ueberschuss_a);

            // 5. Genutzte Phasen (Fahrzeug) nur loggen, wenn sich der Wert ändert
            $this->SetValueAndLogChange('Phasenmodus', $anzPhasenIst, 'Genutzte Phasen (Fahrzeug)', '', 'debug');

            // 6. Laden erzwingen + Ampere setzen – nur wenn sich etwas ändert
            $this->SteuerungLadefreigabe(0, 'manuell', $ampereGewuenscht, $anzPhasenIst);

            // 7. Abschließendes Logging mit den aufbereiteten Werten
            $this->LogTemplate(
                'ok',
                sprintf(
                    "🔌 Manuelles Vollladen aktiv (%d-phasig, %d A). PV=%dW, Haus=%dW, Wallbox=%dW, Batterie=%dW, Überschuss=%dW / %dA",
                    $anzPhasenIst,
                    $ampereGewuenscht,
                    $energy['pv'],
                    $energy['hausFiltered'],
                    $energy['wallbox'],
                    $energy['batt'],
                    $ueberschuss_w,
                    $ueberschuss_a
                )
            );

            // 8. Timer ggf. anpassen
            $this->SetTimerNachModusUndAuto();
        }

    private function ModusPV2CarLaden(array $data)
    {
        if (!$this->FahrzeugVerbunden($data)) {
            $this->ResetLademodiWennKeinFahrzeug();
            return;
        }

        // 1. PV-Anteil holen
        $anteil      = max(0, min(100, intval($this->GetValue('PVAnteil'))));
        // 2. aktuelle Phasenanzahl
        $oldPhasen   = max(1, $this->GetValue('Phasenmodus'));
        // 3. Basisdaten holen & filtern
        $energy      = $this->applyFilters($this->gatherEnergyData());
        // 4. Roh-Überschuss und Watt-Anteil berechnen
        $pv2car      = $this->calculatePV2Car($energy, $anteil);
        $rohUeb      = $pv2car['roh_ueber'];
        $anteilWatt  = $pv2car['anteil_watt'];

        // 5. PHASEN-HYSTERESE:
        $this->PruefeUndSetzePhasenmodus($rohUeb);
        $newPhasen = max(1, $this->GetValue('Phasenmodus'));
        if ($newPhasen !== $oldPhasen) {
            $this->SetValueAndLogChange('Phasenmodus', $newPhasen, 'Genutzte Phasen', '', 'debug');
            // mit neuer Phasenanzahl neu berechnen:
            $energy     = $this->applyFilters($this->gatherEnergyData());
            $pv2car     = $this->calculatePV2Car($energy, $anteil);
            $rohUeb     = $pv2car['roh_ueber'];
            $anteilWatt = $pv2car['anteil_watt'];
        }

        // 6. Ampere-Berechnung
        $minAmp   = $this->ReadPropertyInteger('MinAmpere');
        $maxAmp   = $this->ReadPropertyInteger('MaxAmpere');
        $ampere   = $newPhasen
                ? (int)ceil($anteilWatt / (230 * $newPhasen))
                : 0;
        $ampere   = max($minAmp, min($maxAmp, $ampere));

        // 7. Visualisierung
        $this->SetValueAndLogChange('PV_Ueberschuss',   $rohUeb,   'PV-Überschuss',   'W', 'debug');
        $this->SetValueAndLogChange('PV_Ueberschuss_A', $ampere,   'Überschuss Ampere','A','debug');

        // 8. Freigabe-Hysterese prüfen
        $desiredFRC = $this->BerechneLadefreigabeMitHysterese($anteilWatt);

        // 9. Steuerung
        $this->SteuerungLadefreigabe(
            $rohUeb,
            'pv2car',
            $ampere,
            $newPhasen,
            $desiredFRC
        );
        }

    // =========================================================================
    // 6. WALLBOX STEUERN (SET-FUNKTIONEN)
    // =========================================================================

    private function simpleCurlGet($url)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);

        $result = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'result'   => $result,
            'httpcode' => $httpcode,
            'error'    => $error
        ];
    }

    public function SetChargingCurrent(int $ampere)
    {
        $minAmp = $this->ReadPropertyInteger('MinAmpere');
        $maxAmp = $this->ReadPropertyInteger('MaxAmpere');
        // Wertebereich prüfen
        if ($ampere < $minAmp || $ampere > $maxAmp) {
            $this->LogTemplate('warn', "SetChargingCurrent: Ungültiger Wert ($ampere A). Erlaubt: {$minAmp}-{$maxAmp} A!");
            return false;
        }
        $ip = $this->ReadPropertyString('WallboxIP');
        $url = "http://$ip/api/set?amp=" . intval($ampere);

        $this->LogTemplate('info', "SetChargingCurrent: Sende Ladestrom $ampere A an $url");

        $response = $this->simpleCurlGet($url);

        if ($response['result'] === false || $response['httpcode'] != 200) {
            $this->LogTemplate(
                'error',
                "SetChargingCurrent: Fehler beim Setzen auf $ampere A! " .
                "HTTP-Code: {$response['httpcode']}, cURL-Fehler: {$response['error']}"
            );
            return false;
        } else {
            $this->LogTemplate('ok', "SetChargingCurrent: Ladestrom auf $ampere A gesetzt. (HTTP {$response['httpcode']})");
            //$this->UpdateStatus();
            return true;
        }
    }

    public function SetPhaseMode(int $mode)
    {
        // Wertebereich prüfen: 0 = Auto, 1 = 1-phasig, 2 = 3-phasig
        if ($mode < 0 || $mode > 2) {
            $this->LogTemplate('warn', "SetPhaseMode: Ungültiger Wert ($mode). Erlaubt: 0=Auto, 1=1-phasig, 2=3-phasig!");
            return false;
        }

        $ip = $this->ReadPropertyString('WallboxIP');
        $url = "http://$ip/api/set?psm=" . intval($mode);

        $modes = [0 => "Auto", 1 => "1-phasig", 2 => "3-phasig"];
        $modeText = $modes[$mode] ?? $mode;

        $this->LogTemplate('info', "SetPhaseMode: Sende Phasenmodus '$modeText' ($mode) an $url");

        $response = $this->simpleCurlGet($url);

        if ($response['result'] === false || $response['httpcode'] != 200) {
            $this->LogTemplate(
                'error',
                "SetPhaseMode: Fehler beim Setzen auf '$modeText' ($mode)! HTTP-Code: {$response['httpcode']}, cURL-Fehler: {$response['error']}"
            );
            return false;
        } else {
            $this->LogTemplate('ok', "SetPhaseMode: Phasenmodus auf '$modeText' ($mode) gesetzt. (HTTP {$response['httpcode']})");
            // Direkt Status aktualisieren
            //$this->UpdateStatus();
            return true;
        }
    }

    public function SetForceState(int $state)
    {
        // 1) Wertebereich prüfen
        if ($state < 0 || $state > 2) {
            $this->LogTemplate('warn', "SetForceState: Ungültiger Wert ($state). Erlaubt: 0=Neutral, 1=OFF, 2=ON!");
            return false;
        }

        // 2) Request vorbereiten
        $ip       = $this->ReadPropertyString('WallboxIP');
        $url      = "http://{$ip}/api/set?frc=" . intval($state);
        $modes    = [
            0 => "Neutral (Wallbox entscheidet)",
            1 => "Nicht Laden (gesperrt)",
            2 => "Laden (erzwungen)"
        ];
        $modeText = $modes[$state] ?? $state;

        // 3) Debug-Log vor dem Senden
        $this->LogTemplate('debug', "SetForceState: HTTP GET {$url}");

        // 4) Curl-Aufruf
        $response = $this->simpleCurlGet($url);

        // 5) Fehlerfall
        if ($response['result'] === false || $response['httpcode'] != 200) {
            $this->LogTemplate(
                'error',
                "SetForceState-Fehler: {$modeText} ({$state}), HTTP {$response['httpcode']}, cURL: {$response['error']}"
            );
            return false;
        }

        // 6) Erfolg loggen und lokalen Status sofort setzen
        $this->LogTemplate('ok', "SetForceState: {$modeText} ({$state}) gesetzt. (HTTP {$response['httpcode']})");
        $varID = $this->GetIDForIdent('AccessStateV2');
        if ($varID) {
            SetValue($varID, $state);
        }

        return true;
    }

    public function SetChargingEnabled(bool $enabled)
    {
        $ip = $this->ReadPropertyString('WallboxIP');
        $apiKey = $this->ReadPropertyString('WallboxAPIKey');
        $alwValue = $enabled ? 1 : 0;
        $statusText = $enabled ? "Laden erlaubt" : "Laden gesperrt";

        // Offizieller Weg: mit oder ohne API-Key
        if ($apiKey != '') {
            $url = "http://$ip/api/set?dwo=0&alw=$alwValue&key=" . urlencode($apiKey);
            $this->LogTemplate('info', "SetChargingEnabled: Sende Ladefreigabe '$statusText' ($alwValue) mit API-Key an $url");
        } else {
            $url = "http://$ip/api/set?dwo=0&alw=$alwValue";
            $this->LogTemplate('info', "SetChargingEnabled: Sende Ladefreigabe '$statusText' ($alwValue) an $url");
        }

        $response = $this->simpleCurlGet($url);

        if ($response['result'] === false || $response['httpcode'] != 200) {
            $this->LogTemplate(
                'error',
                "SetChargingEnabled: Fehler beim Setzen der Ladefreigabe ($alwValue)! HTTP-Code: {$response['httpcode']}, cURL-Fehler: {$response['error']}"
            );
            return false;
        } else {
            $this->LogTemplate('ok', "SetChargingEnabled: Ladefreigabe wurde auf '$statusText' ($alwValue) gesetzt. (HTTP {$response['httpcode']})");
            //$this->UpdateStatus();
            return true;
        }
    }

    private function PruefeUndSetzePhasenmodus($pvUeberschuss = null, $forceThreePhase = false)
    {
        $umschaltCooldown = 30; // Cooldown in Sekunden
        $letzteUmschaltung = @$this->ReadAttributeInteger('LetztePhasenUmschaltung');
        if (!is_int($letzteUmschaltung) || $letzteUmschaltung <= 0) {
            $letzteUmschaltung = 0;
        }
        $now = time();

        // Sofort auf 3-phasig schalten, wenn erzwungen
        if ($forceThreePhase) {
            $aktModus = $this->GetValue('Phasenmodus');
            if ($aktModus != 2) {
                $this->SetValueAndLogChange('Phasenmodus', 2, 'Phasenumschaltung', '', 'ok');
                $ok = $this->SetPhaseMode(2); // 2 = 3-phasig
                if ($ok) {
                    $this->LogTemplate('ok', "Manueller Modus: 3-phasig *erzwingend* geschaltet!");
                } else {
                    $this->LogTemplate('error', 'Manueller Modus: Umschalten auf 3-phasig fehlgeschlagen!');
                }
                // Zähler & Zeit zurücksetzen
                $this->WriteAttributeInteger('Phasen3Zaehler', 0);
                $this->WriteAttributeInteger('Phasen1Zaehler', 0);
                $this->WriteAttributeInteger('LetztePhasenUmschaltung', $now);
            }
            return;
        }

        // Umschaltpause beachten
        if (($now - $letzteUmschaltung) < $umschaltCooldown) {
            $rest = $umschaltCooldown - ($now - $letzteUmschaltung);
            $this->LogTemplate('debug', "Phasenumschaltung: Cooldown aktiv, noch $rest Sekunden warten.");
            return;
        }

        // Normale Phasenumschalt-Logik
        $schwelle1 = $this->ReadPropertyInteger('Phasen1Schwelle');
        $schwelle3 = $this->ReadPropertyInteger('Phasen3Schwelle');
        $limit1    = $this->ReadPropertyInteger('Phasen1Limit');
        $limit3    = $this->ReadPropertyInteger('Phasen3Limit');
        $aktModus  = $this->GetValue('Phasenmodus');

        // === Auf 3-phasig umschalten ===
        if ($aktModus == 1 && $pvUeberschuss >= $schwelle3) {
            $zaehler = $this->ReadAttributeInteger('Phasen3Zaehler') + 1;
            $this->WriteAttributeInteger('Phasen3Zaehler', $zaehler);
            $this->WriteAttributeInteger('Phasen1Zaehler', 0);

            $this->LogTemplate('debug', "Phasen-Hysterese: $zaehler/$limit3 Zyklen > Schwelle3");
            if ($zaehler >= $limit3) {
                $this->SetValueAndLogChange('Phasenmodus', 2, 'Phasenumschaltung', '', 'ok');
                $ok = $this->SetPhaseMode(2); // 2 = 3-phasig
                if (!$ok) $this->LogTemplate('error', 'PruefeUndSetzePhasenmodus: Umschalten auf 3-phasig fehlgeschlagen!');
                $this->WriteAttributeInteger('Phasen3Zaehler', 0);
                $this->WriteAttributeInteger('Phasen1Zaehler', 0);
                $this->WriteAttributeInteger('LetztePhasenUmschaltung', $now);
            }
            return;
        }

        // === Auf 1-phasig umschalten ===
        if ($aktModus == 2 && $pvUeberschuss <= $schwelle1) {
            $zaehler = $this->ReadAttributeInteger('Phasen1Zaehler') + 1;
            $this->WriteAttributeInteger('Phasen1Zaehler', $zaehler);
            $this->WriteAttributeInteger('Phasen3Zaehler', 0);

            $this->LogTemplate('debug', "Phasen-Hysterese: $zaehler/$limit1 Zyklen < Schwelle1");
            if ($zaehler >= $limit1) {
                $this->SetValueAndLogChange('Phasenmodus', 1, 'Phasenumschaltung', '', 'warn');
                $ok = $this->SetPhaseMode(1); // 1 = 1-phasig
                if (!$ok) $this->LogTemplate('error', 'PruefeUndSetzePhasenmodus: Umschalten auf 1-phasig fehlgeschlagen!');
                $this->WriteAttributeInteger('Phasen3Zaehler', 0);
                $this->WriteAttributeInteger('Phasen1Zaehler', 0);
                $this->WriteAttributeInteger('LetztePhasenUmschaltung', $now);
            }
            return;
        }
    }

    private function SteuerungLadefreigabe($pvUeberschuss, $modus = 'pvonly', $ampere = 0, $anzPhasen = 1, $overrideFRC = null)
    {
        $minUeberschuss = $this->ReadPropertyInteger('MinLadeWatt'); // z.B. 1400 W

        // === overrideFRC prüfen: wenn gesetzt, immer diesen Wert nutzen ===
        if ($overrideFRC !== null) {
            $sollFRC = $overrideFRC;
        }
        else {
            // Default-Logik: Manueller Modus oder PVonly mit genug Überschuss → Laden (2), sonst Sperren (1)
            $sollFRC = ($modus === 'manuell' || ($modus === 'pvonly' && $pvUeberschuss >= $minUeberschuss))
                ? 2
                : 1;
        }

        // Nur wenn nötig an Wallbox senden!
        $aktFRC = $this->GetValue('AccessStateV2');
        if ($aktFRC !== $sollFRC) {
            $this->LogTemplate('debug', "SetForceState: sende FRC={$sollFRC} (Modus={$modus})");
            $this->SetForceState($sollFRC);
            IPS_Sleep(1000);
        }

        // Nur Ladestrom setzen, wenn Freigabe aktiv und Ampere gültig
        if ($sollFRC === 2 && $ampere > 0) {
            $currentAmp = $this->GetValue('Ampere');
            if ($currentAmp != $ampere) {
                $this->LogTemplate('debug', "SetChargingCurrent: sende {$ampere}A");
                $this->SetChargingCurrent($ampere);
            }
        }
    }

    // =========================================================================
    // HILFSFUNKTIONEN & WERTLOGGING
    // =========================================================================

    private function SetValueAndLogChange($ident, $newValue, $caption = '', $unit = '', $level = 'info')
    {
        $varID = @$this->GetIDForIdent($ident);
        if ($varID === false || $varID === 0) {
            $this->LogTemplate('warn', "Variable mit Ident '$ident' nicht gefunden!");
            return;
        }

        // Versuche, aktuellen Wert robust zu lesen
        try {
            $oldValue = GetValue($varID);
        } catch (Exception $e) {
            $oldValue = null;
        }

        if (is_string($oldValue) || is_string($newValue)) {
            if ((string)$oldValue === (string)$newValue) return;
        } else {
            if (round(floatval($oldValue), 2) == round(floatval($newValue), 2)) return;
        }

        // Werte ggf. als Klartext formatieren
        $formatValue = function($value) use ($varID) {
            $varInfo = @IPS_GetVariable($varID);
            if (!$varInfo) return strval($value);
            $profile = $varInfo['VariableCustomProfile'] ?: $varInfo['VariableProfile'];

            switch ($profile) {
                case 'PVWM.CarStatus':
                    $map = [
                        0 => 'Unbekannt/Firmwarefehler',
                        1 => 'Bereit, kein Fahrzeug',
                        2 => 'Fahrzeug lädt',
                        3 => 'Fahrzeug verbunden / Bereit zum Laden',
                        4 => 'Ladung beendet, Fahrzeug noch verbunden',
                        5 => 'Fehler'
                    ];
                    return $map[intval($value)] ?? $value;

                case 'PVWM.PSM':
                    $map = [0 => 'Auto', 1 => '1-phasig', 2 => '3-phasig'];
                    return $map[intval($value)] ?? $value;

                case 'PVWM.ALW':
                    return ($value ? 'Laden freigegeben' : 'Nicht freigegeben');

                case 'PVWM.AccessStateV2':
                    $map = [
                        0 => 'Neutral (Wallbox entscheidet)',
                        1 => 'Nicht Laden (gesperrt)',
                        2 => 'Laden (erzwungen)'
                    ];
                    return $map[intval($value)] ?? $value;

                case 'PVWM.ErrorCode':
                    $map = [
                        0 => 'Kein Fehler', 1 => 'FI AC', 2 => 'FI DC', 3 => 'Phasenfehler', 4 => 'Überspannung',
                        5 => 'Überstrom', 6 => 'Diodenfehler', 7 => 'PP ungültig', 8 => 'GND ungültig', 9 => 'Schütz hängt',
                        10 => 'Schütz fehlt', 11 => 'FI unbekannt', 12 => 'Unbekannter Fehler', 13 => 'Übertemperatur',
                        14 => 'Keine Kommunikation', 15 => 'Verriegelung klemmt offen', 16 => 'Verriegelung klemmt verriegelt',
                        20 => 'Reserviert 20', 21 => 'Reserviert 21', 22 => 'Reserviert 22', 23 => 'Reserviert 23', 24 => 'Reserviert 24'
                    ];
                    return $map[intval($value)] ?? $value;

                case 'PVWM.Ampere':
                case 'PVWM.AmpereCable':
                    return number_format($value, 0, ',', '.') . ' A';

                case 'PVWM.Watt':
                case 'PVWM.W':
                    return number_format($value, 0, ',', '.') . ' W';

                case 'PVWM.Wh':
                    return number_format($value, 0, ',', '.') . ' Wh';

                case 'PVWM.Percent':
                    return number_format($value, 0, ',', '.') . ' %';

                case 'PVWM.CentPerKWh':
                    return number_format($value, 3, ',', '.') . ' ct/kWh';

                default:
                    if (is_bool($value)) return $value ? 'ja' : 'nein';
                    if (is_numeric($value)) return number_format($value, 0, ',', '.');
                    return strval($value);
            }
        };

        // Meldung zusammensetzen
        $oldText = $formatValue($oldValue);
        $newText = $formatValue($newValue);
        if ($caption) {
            $msg = "$caption geändert: $oldText → $newText";
        } else {
            $msg = "Wert geändert: $oldText → $newText";
        }

        $this->LogTemplate($level, $msg);
        SetValue($varID, $newValue);
    }

    private function ProfileValueText($profile, $value)
    {
        switch ($profile) {
            case 'PVWM.AccessStateV2':
                return $this->GetFrcText($value);
            case 'PVWM.PSM':
                $map = [0 => 'Auto', 1 => '1-phasig', 2 => '3-phasig'];
                return $map[intval($value)] ?? $value;
            // ... weitere Profile nach Bedarf ...
            default:
                return $value;
        }
    }

    private function GetFrcText($frc)
    {
        switch (intval($frc)) {
            case 0: return 'Neutral (Wallbox entscheidet)';
            case 1: return 'Nicht Laden (gesperrt)';
            case 2: return 'Laden (erzwungen)';
            default: return 'Unbekannt (' . $frc . ')';
        }
    }

    private function GetInitialCheckInterval() {
        $val = intval($this->ReadPropertyInteger('InitialCheckInterval'));
        if ($val < 5 || $val > 60) $val = 5;
        return $val;
    }

    private function ResetWallboxVisualisierungKeinFahrzeug()
    {
        $this->SetValue('Leistung', 0);                  // Ladeleistung zum Fahrzeug
        $this->SetValue('PV_Ueberschuss', 0);            // PV-Überschuss (W)
        $this->SetValue('PV_Ueberschuss_A', 0);          // PV-Überschuss (A) – Jetzt 0A!
//

        // Hausverbrauch trotzdem live anzeigen (wie oben beschrieben)
        $hvID = $this->ReadPropertyInteger('HausverbrauchID');
        $hvEinheit = $this->ReadPropertyString('HausverbrauchEinheit');
        $invertHV = $this->ReadPropertyBoolean('InvertHausverbrauch');
        $hausverbrauch = ($hvID > 0) ? @GetValueFloat($hvID) : 0;
        if ($hvEinheit == "kW") $hausverbrauch *= 1000;
        if ($invertHV) $hausverbrauch *= -1;
        $hausverbrauch = round($hausverbrauch);
//        $this->SetValue('Hausverbrauch_W', $hausverbrauch);

        $this->SetValue('Freigabe', false);   // explizit auf false setzen!
        $this->SetValue('AccessStateV2', 1);  // explizit auf 1 = gesperrt!
        $this->SetValue('Status', 1);         // Status für „kein Fahrzeug“
        $this->SetTimerNachModusUndAuto();
    }

    private function FahrzeugVerbunden($data)
    {
        // Robust, egal ob false oder Array kommt
        $car = (is_array($data) && isset($data['car'])) ? intval($data['car']) : 0;
        if ($car > 1) return true;

        // --- NUR EINMAL zentral alles erledigen ---
        if ($this->GetValue('AccessStateV2') != 1) {
            $this->SetForceState(1);
            $this->LogTemplate('info', "Kein Fahrzeug verbunden – Wallbox bleibt gesperrt.");
        }
        $this->SetTimerNachModusUndAuto($car);
        $this->ResetWallboxVisualisierungKeinFahrzeug();
        return false;
    }

    // Hilfsfunktion: Setzt Timer richtig je nach Status und Modus
    private function SetTimerNachModusUndAuto()
    {
        // --- 0. Vorbereitungen (Self-Healing) ---
        if (!@is_int($this->ReadAttributeInteger('MarketPricesTimerInterval'))) {
            $this->WriteAttributeInteger('MarketPricesTimerInterval', 0);
        }
        if (!@is_bool($this->ReadAttributeBoolean('MarketPricesActive'))) {
            $this->WriteAttributeBoolean('MarketPricesActive', false);
        }

        // --- 1. Status-Wechsel erkennen und loggen ---
        $car        = @$this->GetValue('Status');                  // aktueller Status 0/1/2...
        $lastStatus = $this->ReadAttributeInteger('LastTimerStatus');
        if ($car !== $lastStatus) {
            $this->LogTemplate('debug', "SetTimerNachModusUndAuto: Status={$car}");
            $this->WriteAttributeInteger('LastTimerStatus', $car);
        }

        // --- 2. Timer abschalten ---
        $this->SetTimerInterval('PVWM_UpdateStatus', 0);
        $this->SetTimerInterval('PVWM_InitialCheck', 0);

        // --- 3. Modul deaktiviert? Dann alles aus ---
        if (!$this->ReadPropertyBoolean('ModulAktiv')) {
            $this->SetTimerInterval('PVWM_UpdateMarketPrices', 0);
            return;
        }

        // --- 4. Je nach Fahrzeugstatus einen Haupttimer setzen ---
        $mainInterval    = intval($this->ReadPropertyInteger('RefreshInterval'));
        $initialInterval = $this->GetInitialCheckInterval();

        if ($car === false || $car <= 1) {
            // bis Fahrzeug erkannt: Schnell-Poll
            if ($initialInterval > 0) {
                $this->SetTimerInterval('PVWM_InitialCheck', $initialInterval * 1000);
            }
        } else {
            // Fahrzeug verbunden: regulärer Poll-Intervall
            $this->SetTimerInterval('PVWM_UpdateStatus', $mainInterval * 1000);
        }
    }

    private function ResetLademodiWennKeinFahrzeug()
    {
        if ($this->GetValue('Status') <= 1) {
            $modi = ['ManuellLaden', 'PV2CarModus', 'ZielzeitLaden'];
            foreach ($modi as $modus) {
                if ($this->GetValue($modus)) {
                    $this->SetValue($modus, false);
                    $this->LogTemplate(
                        'debug',
                        "Modus '$modus' wurde deaktiviert, weil kein Fahrzeug verbunden ist."
                    );
                }
            }
        }
    }

    private function SetMarketPriceTimerZurVollenStunde()
    {
        // Deaktivieren, wenn Option aus
        if (!$this->ReadPropertyBoolean('UseMarketPrices')) {
            $this->SetTimerInterval('PVWM_UpdateMarketPrices', 0);
            $this->WriteAttributeBoolean('MarketPricesActive', false);
            return;
        }

        // Jetzt sofort einmal abrufen!
        $this->AktualisiereMarktpreise();

        // Sekunden bis zur nächsten vollen Stunde berechnen
        $now = time();
        $sekBisNaechsteStunde = (60 - date('i', $now)) * 60 - date('s', $now);
        if ($sekBisNaechsteStunde <= 0) $sekBisNaechsteStunde = 3600; // Absicherung

        // Timer EINMALIG auf nächsten Stundentakt setzen
        $this->SetTimerInterval('PVWM_UpdateMarketPrices', $sekBisNaechsteStunde * 1000);
        $this->WriteAttributeBoolean('MarketPricesActive', true);
    }

    private function UpdateHausverbrauchEvent()
    {
        $eventIdent = "UpdateHausverbrauchW";
        $eventID = @$this->GetIDForIdent($eventIdent);
        $hvID = $this->ReadPropertyInteger('HausverbrauchID');
        $myVarID = $this->GetIDForIdent('Hausverbrauch_W');
        $einheit = $this->ReadPropertyString('HausverbrauchEinheit');

        // Vorheriges Ereignis löschen, falls ID gewechselt wurde oder Property leer
        if ($eventID && ($hvID <= 0 || @IPS_GetEvent($eventID)['TriggerVariableID'] != $hvID)) {
            IPS_DeleteEvent($eventID);
            $eventID = 0;
        }
        if ($hvID > 0 && IPS_VariableExists($hvID)) {
            // Neues Ereignis anlegen, falls noch nicht vorhanden
            if (!$eventID) {
                $eventID = IPS_CreateEvent(0); // 0 = Trigger
                IPS_SetIdent($eventID, $eventIdent);
                IPS_SetParent($eventID, $this->InstanceID);
                IPS_SetEventTrigger($eventID, 0, $hvID); // 0 = bei Änderung
                IPS_SetEventActive($eventID, true);
                IPS_SetName($eventID, "Aktualisiere Hausverbrauch_W");
            }
            // Ereignis-Skript: Einheit berücksichtigen
            $script = <<<'EOD'
    $wert = GetValue($_IPS['VARIABLE']);
    $einheit = IPS_GetProperty($_IPS['INSTANCE'], 'HausverbrauchEinheit');
    if ($einheit == 'kW') $wert *= 1000;
    SetValue($_IPS['TARGET'], round($wert));
    EOD;
            // Dynamisch Instanz und Zielvariable einsetzen
            $script = str_replace(['$_IPS[\'INSTANCE\']', '$_IPS[\'TARGET\']'], [$this->InstanceID, $myVarID], $script);

            IPS_SetEventScript($eventID, $script);
        }

        // === Event 2: Hausverbrauch_abz_Wallbox aktualisieren ===
        $eventIdent2 = "UpdateHausverbrauchAbzWallbox";
        $eventID2 = @$this->GetIDForIdent($eventIdent2);
        $myVarID2 = $this->GetIDForIdent('Hausverbrauch_abz_Wallbox');
        $srcVarID = $this->GetIDForIdent('Hausverbrauch_W');

        // Vorheriges Folge-Ereignis löschen, falls nicht korrekt verknüpft
        if ($eventID2 && (@IPS_GetEvent($eventID2)['TriggerVariableID'] != $srcVarID)) {
            IPS_DeleteEvent($eventID2);
            $eventID2 = 0;
        }
        if ($srcVarID > 0 && IPS_VariableExists($srcVarID)) {
            if (!$eventID2) {
                $eventID2 = IPS_CreateEvent(0); // Trigger
                IPS_SetIdent($eventID2, $eventIdent2);
                IPS_SetParent($eventID2, $this->InstanceID);
                IPS_SetEventTrigger($eventID2, 0, $srcVarID);
                IPS_SetEventActive($eventID2, true);
                IPS_SetName($eventID2, "Aktualisiere Hausverbrauch_abz_Wallbox");
            }
            // Ereignis-Skript für den Abzug
            $script2 = <<<'EOD'
    $hv = GetValue($_IPS['VARIABLE']);
    $wb = GetValue(IPS_GetObjectIDByIdent('Leistung', $_IPS['INSTANCE']));
    SetValue(IPS_GetObjectIDByIdent('Hausverbrauch_abz_Wallbox', $_IPS['INSTANCE']), round($hv - $wb));
    EOD;
            $script2 = str_replace(['$_IPS[\'INSTANCE\']'], [$this->InstanceID], $script2);
            IPS_SetEventScript($eventID2, $script2);
        }
    }

    private function PruefeLadeendeAutomatisch()
    {
        // 1) Lese SOC-Properties
        $socID       = $this->ReadPropertyInteger('CarSOCID');
        $socTargetID = $this->ReadPropertyInteger('CarTargetSOCID');

        $socAktuell = ($socID > 0 && IPS_VariableExists($socID))
            ? GetValue($socID)
            : null;
        $socZiel = ($socTargetID > 0 && IPS_VariableExists($socTargetID))
            ? GetValue($socTargetID)
            : null;

        // 2) Lade-Freigabe aktuell?
        $aktFreigabe = ($this->GetValue('AccessStateV2') == 2);

        // 3) Wenn SOC-Properties gültig sind, nutze Ziel-SOC-Logik
        if ($socAktuell !== null && $socZiel !== null && $aktFreigabe) {
            if ($socAktuell >= $socZiel) {
                $this->LogTemplate(
                    'ok',
                    "Ziel-SoC erreicht (Aktuell: {$socAktuell}%, Ziel: {$socZiel}%) – beende Ladung."
                );
                $this->SetForceState(1);
                $this->ResetModiNachLadeende();
                return;
            }
            // Wenn SOC-Logik greift, überspringe No-Power
            return;
        }

        // 4) Fallback: No-Power-Counter, wenn keine SOC-Properties gesetzt
        if ($aktFreigabe) {
            $ladeleistung = $this->GetValue('Leistung');
            if ($ladeleistung < 100) {
                $cnt = $this->ReadAttributeInteger('NoPowerCounter') + 1;
                $this->WriteAttributeInteger('NoPowerCounter', $cnt);
                if ($cnt >= 6) {
                    $this->LogTemplate(
                        'ok',
                        "Keine Ladeleistung mehr – beende Ladung nach {$cnt} Versuchen."
                    );
                    $this->SetForceState(1);
                    $this->ResetModiNachLadeende();
                    $this->WriteAttributeInteger('NoPowerCounter', 0);
                }
            } else {
                // Leistung wieder vorhanden → Counter zurücksetzen
                $this->WriteAttributeInteger('NoPowerCounter', 0);
            }
        }
    }

    private function ResetModiNachLadeende()
    {
        // Hier kannst du nach Ladeende die Lademodi zurücksetzen (optional)
        $modi = ['ManuellLaden', 'PV2CarModus', 'ZielzeitLaden'];
        $manualDeactivated = false;
        foreach ($modi as $modus) {
            if ($this->GetValue($modus)) {
                $this->SetValue($modus, false);
                $this->LogTemplate('debug', "Modus '$modus' wurde deaktiviert, da Ladeende erreicht.");
                if ($modus === 'ManuellLaden') {
                    $manualDeactivated = true;
                }
            }
        }
        // Nach Deaktivierung von ManuellLaden → auf 1-phasig, 6A, 0A (nur einmalig!)
        if ($manualDeactivated) {
            $this->SetPhaseMode(1); // 1-phasig
            $this->SetChargingCurrent(6); // 6A
            $this->SetValueAndLogChange('PV_Ueberschuss_A', 0, 'PV-Überschuss (A)', 'A', 'ok');
            $this->LogTemplate('ok', "Nach Ladeende: Zurück auf 1-phasig/6A/0A für PVonly.");
        }
    }

    private function UpdateStatusAnzeige()
    {
        // 1) SoC-Werte holen
        $socID       = $this->ReadPropertyInteger('CarSOCID');
        $targetID    = $this->ReadPropertyInteger('CarTargetSOCID');
        $socAktuell  = ($socID > 0 && @IPS_VariableExists($socID))       ? GetValue($socID)       . '%' : 'n/a';
        $socZiel     = ($targetID > 0 && @IPS_VariableExists($targetID)) ? GetValue($targetID)    . '%' : 'n/a';

        // 2) No-Power-Counter (Versuche ohne Leistung)
        $noPowerCounter = $this->ReadAttributeInteger('NoPowerCounter');

        // 3) Initial-Check-Status & Intervall
        $status       = $this->GetValue('Status');
        $inInitial    = ($status === false || $status <= 1);
        $initialTxt   = $inInitial ? 'Aktiv' : 'Inaktiv';
        $initialIntvl = $this->ReadPropertyInteger('InitialCheckInterval');

        // 4) Lademodus-Text
        $modus = '☀️ PVonly (nur PV-Überschuss)';
        if ($this->GetValue('ManuellLaden')) {
            $phasenIst = $this->GetValue('Phasenmodus');   
            $ampere    = $this->GetValue('ManuellAmpere');
            $modus     = "🔌 Manuell: Vollladen ({$phasenIst}-phasig, {$ampere} A)";
        } elseif ($this->GetValue('PV2CarModus')) {
            $prozent = $this->GetValue('PVAnteil');
            $modus = "🌞 PV-Anteil laden ({$prozent} %)";
        } elseif ($this->GetValue('ZielzeitLaden')) {
            $modus = '⏰ Zielzeitladung';
        }

        // Text aus Profil lesen!
        $psmSollTxt   = $this->GetProfileText('PhasenmodusEinstellung'); // z.B. "1-phasig"
        $psmIstTxt    = $this->GetProfileText('Phasenmodus');            // z.B. "1-phasig", "2-phasig", "3-phasig"
        $statusTxt    = $this->GetProfileText('Status');                 // z.B. "Fahrzeug lädt"
        $frcTxt       = $this->GetProfileText('AccessStateV2');          // z.B. "Laden (erzwungen)"

        $html = '<div style="font-size:15px; line-height:1.7em;">';
        $html .= "<b>Initial-Check:</b> {$initialTxt} (Intervall: {$initialIntvl} s)<br>";
        $html .= "<b>Lademodus:</b> $modus<br>";
        $html .= "<b>SOC Auto (Ist / Ziel):</b> {$socAktuell} / {$socZiel}<br>";
///        $html .= "<b>No-Power-Counter:</b> {$noPowerCounter}×<hr>";
        $html .= "<b>Phasen Wallbox-Einstellung:</b> $psmSollTxt<br>";
        $html .= "<b>Genutzte Phasen (Fahrzeug):</b> $psmIstTxt<br>";
        $html .= "<b>Status:</b> $statusTxt<br>";
        $html .= "<b>Wallbox Modus:</b> $frcTxt";
        $html .= '</div>';

        // Nur aktualisieren, wenn sich der Text geändert hat
        $lastHtml = $this->ReadAttributeString('LastStatusInfoHTML');
        if ($lastHtml !== $html) {
            SetValue($this->GetIDForIdent('StatusInfo'), $html);
            $this->WriteAttributeString('LastStatusInfoHTML', $html);
            $this->LogTemplate('debug', "Status-Info HTMLBox aktualisiert.");
        } else {
            $this->LogTemplate('debug', "Status-Info HTMLBox unverändert, kein Update.");
        }
    }
        
    // =========================================================================
    // 8. LOGGING / DEBUG / STATUSMELDUNGEN
    // =========================================================================

    private function LogTemplate($type, $short, $detail = '')
        {
            $emojis = [
                'info'  => 'ℹ️',
                'warn'  => '⚠️',
                'error' => '❌',
                'ok'    => '✅',
                'debug' => '🐞'
            ];
            $icon = isset($emojis[$type]) ? $emojis[$type] : 'ℹ️';
            $msg = $icon . ' ' . $short;
            if ($detail !== '') {
                $msg .= ' | ' . $detail;
            }
            if ($type === 'debug' && !$this->ReadPropertyBoolean('DebugLogging')) {
                return;
            }
            IPS_LogMessage('[PVWM]', $msg);
        }

    // =========================================================================
    // 9. BERECHNUNGEN
    // =========================================================================
    /**
     * 1) Gesamtdaten aus den Quellen holen (PV, Hausverbrauch, Batterie, Wallbox)
     */
    private function gatherEnergyData(): array
    {
        // PV
        $pvID  = $this->ReadPropertyInteger('PVErzeugungID');
        $pv    = $pvID > 0 ? GetValueFloat($pvID) : 0;
        if ($this->ReadPropertyString('PVErzeugungEinheit') === 'kW') {
            $pv *= 1000;
        }

        // Wallbox-Leistung
        $wb = round($this->GetValue('Leistung'));

        // Hausverbrauch
        $hvID = $this->ReadPropertyInteger('HausverbrauchID');
        $hv   = $hvID > 0 ? GetValueFloat($hvID) : 0;
        if ($this->ReadPropertyString('HausverbrauchEinheit') === 'kW') {
            $hv *= 1000;
        }
        if ($this->ReadPropertyBoolean('InvertHausverbrauch')) {
            $hv = -$hv;
        }

        // Batterie­ladung
        $batID = $this->ReadPropertyInteger('BatterieladungID');
        $bat   = $batID > 0 ? GetValueFloat($batID) : 0;
        if ($this->ReadPropertyString('BatterieladungEinheit') === 'kW') {
            $bat *= 1000;
        }
        if ($this->ReadPropertyBoolean('InvertBatterieladung')) {
            $bat = -$bat;
        }

        return [
            'pv'      => round($pv),
            'wallbox' => $wb,
            'haus'    => round($hv),
            'batt'    => round($bat),
        ];
    }

    /**
     * 2) Glättung und Spike-Filter auf den bereinigten Hausverbrauch anwenden
     */
    private function applyFilters(array $data): array
    {
        // Raw-Hausverbrauch ohne Wallbox
        $raw = max(0, $data['haus'] - $data['wallbox']);

        // Puffer­array (letzte 3 Werte)
        $buf = json_decode($this->ReadAttributeString('HausverbrauchAbzWallboxBuffer'), true) ?: [];
        $buf[] = $raw;
        if (count($buf) > 3) {
            array_shift($buf);
        }
        $mean = array_sum($buf) / count($buf);

        // Spike-Schwelle
        $threshold = 1.5 * $this->ReadPropertyInteger('MaxAmpere') * 230;
        $last      = floatval($this->ReadAttributeFloat('HausverbrauchAbzWallboxLast'));

        if ($last > 0 && abs($raw - $last) > $threshold) {
            $filtered = $last;
            $this->LogTemplate('warn', "Spike erkannt: {$raw}W → bleibe bei {$last}W");
        } else {
            $filtered = $mean;
            $this->WriteAttributeString('HausverbrauchAbzWallboxBuffer', json_encode($buf));
            $this->WriteAttributeFloat('HausverbrauchAbzWallboxLast', $mean);
        }

        $data['hausFiltered'] = round($filtered);
        return $data;
    }

    /**
     * 2.5) Anteilsberechnung für PV2Car-Modus
     *
     * @param array $data         Ergebnis von applyFilters(): ['pv','wallbox','hausFiltered','batt']
     * @param int   $anteilProzent Prozentwert 0–100
     * @return array              ['roh_ueber','anteil_watt']
     */
    private function calculatePV2Car(array $data, int $anteilProzent): array
    {
        // Roh-Überschuss = PV minus gefiltertem Hausverbrauch
        $rohUeberschuss = max(0, $data['pv'] - $data['hausFiltered']);

        // Watt-Anteil für’s Auto
        $anteilWatt = intval(round($rohUeberschuss * $anteilProzent / 100));

        return [
            'roh_ueber'   => $rohUeberschuss,
            'anteil_watt' => $anteilWatt,
        ];
    }

    /**
     * 3) Überschuss und Ampere berechnen (inkl. Threshold und Hysterese-Logging)
     */
    private function calculateSurplus(array $data, int $anzPhasen, bool $log = true): array
    {
        // Nur Batterie­ladung (positiv) berücksichtigen
        $batLoad = max(0, $data['batt']);

        // Gesamtverbrauch
        $cons = $data['hausFiltered'] + $batLoad;

        // Roh-Überschuss
        $rawSurplus = max(0, $data['pv'] - $cons);
        if (abs($rawSurplus) < 1) {
            $rawSurplus = 0;
        }

        // Cutoff-Threshold
        $cutoff = 250;
        $amp    = 0;
        if ($rawSurplus >= $cutoff) {
            $amp = ceil($rawSurplus / (230 * $anzPhasen));
            $amp = max(
                $this->ReadPropertyInteger('MinAmpere'),
                min($this->ReadPropertyInteger('MaxAmpere'), $amp)
            );
        } elseif ($log) {
            $this->LogTemplate('debug', "PV-Überschuss <{$cutoff}W ({$rawSurplus}W) → setze auf 0");
        }

        // Logging & Visualisierung
        if ($log) {
            $this->LogTemplate(
                'debug',
                sprintf(
                    "Berechnung: PV=%dW, Haus=%dW, Batt=%dW → Überschuss=%dW, Ampere=%dA, Phasen=%d",
                    $data['pv'],
                    $data['hausFiltered'],
                    $batLoad,
                    $rawSurplus,
                    $amp,
                    $anzPhasen
                )
            );
            $this->SetValueAndLogChange('PV_Ueberschuss', $rawSurplus,   'PV-Überschuss',   'W', 'debug');
            $this->SetValueAndLogChange('PV_Ueberschuss_A', $amp,        'PV-Überschuss (A)','A', 'debug');
        } else {
            $this->SetValue('PV_Ueberschuss',   $rawSurplus);
            $this->SetValue('PV_Ueberschuss_A', $amp);
        }

        return [
            'ueberschuss_w' => $rawSurplus,
            'ueberschuss_a' => $amp,
        ];
    }

    private function BerechneLadefreigabeMitHysterese(int $pvUeberschuss): int
    {
        $minLadeWatt  = $this->ReadPropertyInteger('MinLadeWatt');
        $minStopWatt  = $this->ReadPropertyInteger('MinStopWatt');
        $startHys     = $this->ReadPropertyInteger('StartLadeHysterese');
        $stopHys      = $this->ReadPropertyInteger('StopLadeHysterese');
        $startZ       = $this->ReadAttributeInteger('LadeStartZaehler');
        $stopZ        = $this->ReadAttributeInteger('LadeStopZaehler');

        $aktFRC       = $this->GetValue('AccessStateV2') === 2 ? 2 : 1;
        $desiredFRC   = $aktFRC;

        if ($pvUeberschuss >= $minLadeWatt) {
            $startZ++;
            $this->WriteAttributeInteger('LadeStartZaehler', $startZ);
            $this->WriteAttributeInteger('LadeStopZaehler', 0);
            $this->LogTemplate('info', "Start-Hysterese: {$startZ}/{$startHys} Zyklen ≥ {$minLadeWatt} W");
            if ($startZ >= $startHys) {
                $this->LogTemplate('ok', "Start-Hysterese erreicht → Freigabe an.");
                $desiredFRC = 2;
                $this->WriteAttributeInteger('LadeStartZaehler', 0);
            }
        } else {
            $this->WriteAttributeInteger('LadeStartZaehler', 0);
        }

        if ($aktFRC === 2 && $pvUeberschuss <= $minStopWatt) {
            $stopZ++;
            $this->WriteAttributeInteger('LadeStopZaehler', $stopZ);
            $this->WriteAttributeInteger('LadeStartZaehler', 0);
            $this->LogTemplate('info', "Stop-Hysterese: {$stopZ}/{$stopHys} Zyklen ≤ {$minStopWatt} W");
            if ($stopZ >= $stopHys) {
                $this->LogTemplate('warn', "Stop-Hysterese erreicht → Freigabe aus.");
                $desiredFRC = 1;
                $this->WriteAttributeInteger('LadeStopZaehler', 0);
            }
        } elseif ($aktFRC === 2) {
            $this->WriteAttributeInteger('LadeStopZaehler', 0);
        }

        return $desiredFRC;
    }

//=========================================================================
    // 10. EXTERNE SCHNITTSTELLEN & FORECAST
    // =========================================================================
    private function AktualisiereMarktpreise()
    {
        $this->LogTemplate('debug', "AktualisiereMarktpreise wurde aufgerufen."); // Start-Log

        if (!$this->ReadPropertyBoolean('UseMarketPrices')) {
            $this->LogTemplate('info', "Börsenpreis-Update übersprungen (deaktiviert).");
            return;
        }

        // Provider/URL wählen
        $provider = $this->ReadPropertyString('MarketPriceProvider');
        $apiUrl = '';
        if ($provider == 'awattar_at') {
            $apiUrl = 'https://api.awattar.at/v1/marketdata';
        } elseif ($provider == 'awattar_de') {
            $apiUrl = 'https://api.awattar.de/v1/marketdata';
        } elseif ($provider == 'custom') {
            $apiUrl = $this->ReadPropertyString('MarketPriceAPI');
        }
        if ($apiUrl == '') {
            $this->LogTemplate('error', "Keine gültige API-URL für Strompreis-Provider!");
            return;
        }

        // Daten abrufen (mit cURL und Timeout)
        $response = $this->simpleCurlGet($apiUrl);
        if ($response['result'] === false || $response['httpcode'] != 200) {
            $this->LogTemplate(
                'error',
                "Abruf der Börsenpreise fehlgeschlagen! HTTP-Code: {$response['httpcode']}, cURL-Fehler: {$response['error']} (URL: $apiUrl)"
            );
            return;
        }
        $json = $response['result'];
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['data'])) {
            $this->LogTemplate('error', "Fehlerhafte Antwort der API (keine 'data').");
            return;
        }

        // --- Preise aufbereiten (nächste 36h) ---
        $preise = array_map(function($item) {
            return [
                'timestamp' => intval($item['start_timestamp'] / 1000),
                'price'     => floatval($item['marketprice'] / 10.0)
            ];
        }, $data['data']);

        // Aktuellen Preis setzen (erster Datensatz)
        $aktuellerPreis = $preise[0]['price'];
        $this->SetValueAndLogChange('CurrentSpotPrice', $aktuellerPreis);

        // Forecast als JSON speichern
        $this->SetValueAndLogChange('MarketPrices', json_encode($preise));
        $this->LogTemplate('debug', "MarketPrices wurde gesetzt: " . substr(json_encode($preise), 0, 100) . "..."); // Nur die ersten Zeichen fürs Log

        // HTML-Vorschau speichern
        $this->SetValue('MarketPricesPreview', $this->FormatMarketPricesPreviewHTML(24));

        // Nur eine Logmeldung am Ende!
        $this->LogTemplate('ok', "Börsenpreise aktualisiert: Aktuell {$aktuellerPreis} ct/kWh – " . count($preise) . " Preispunkte gespeichert.");
    }

    private function FormatMarketPricesPreviewHTML($max = 24)
    {
        $preiseRaw = @$this->GetValue('MarketPrices');
        $preise    = json_decode($preiseRaw, true);
        if (!is_array($preise) || count($preise) === 0) {
            return '<span style="color:#888;">Keine Preisdaten verfügbar.</span>';
        }

        $now = time();
        // 1) nur zukünftige oder aktuelle Zeitpunkte behalten
        $future = array_filter($preise, function($p) use ($now) {
            return $p['timestamp'] >= $now;
        });
        // 2) auf die ersten $max Einträge kürzen
        $slice = array_slice(array_values($future), 0, $max);

        // === ab hier unverändert weiterverarbeiten $slice statt $preise ===
        $allePreise = array_column($slice, 'price');
        $min        = min($allePreise);
        $maxPrice   = max($allePreise);

        // CSS – KEINE Farbvorgabe für Text!
        $html = <<<EOT
    <style>
    .pvwm-row {
        display: flex; align-items: center;
        margin: 7px 0 0 0;
    }
    .pvwm-hour {
        width: 28px; min-width: 28px;
        font-weight: 600;
        font-size: 1.07em;
        text-align: right;
        padding-right: 8px;
    }
    .pvwm-bar-wrap {
        flex: 1;
        display: flex; align-items: center;
    }
    .pvwm-bar {
        display: flex; align-items: center; justify-content: left;
        height: 22px;
        border-radius: 7px;
        font-weight: 700;
        font-size: 1.10em;
        box-shadow: 0 1px 2.5px #0002;
        padding-left: 18px;
        letter-spacing: 0.02em;
        min-width: 62px;
        background: #eee;
        transition: width 0.35s;
    }
    </style>
    <div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;max-width:540px;">
    <b style="font-size:1.07em;">Börsenpreis-Vorschau:</b>
    EOT;

        foreach ($preise as $i => $dat) {
            $time = date('H', $dat['timestamp']);
            $price = number_format($dat['price'], 3, ',', '.');
            $percent = ($dat['price'] - $min) / max(0.001, ($maxPrice - $min));

            // Farbverlauf: Grün → Gelb → Orange
            if ($percent <= 0.5) {
                // #38b000 (grün) bis #ffcc00 (gelb)
                $t = $percent / 0.5;
                $r = intval(56 + (255-56) * $t);
                $g = intval(176 + (204-176) * $t);
                $b = 0;
            } else {
                // #ffcc00 (gelb) bis #ff6a00 (orange)
                $t = ($percent-0.5)/0.5;
                $r = 255;
                $g = intval(204 - (204-106) * $t);
                $b = 0;
            }
            $color = sprintf("#%02x%02x%02x", $r, $g, $b);

            // Balkenbreite von 38% bis 100%
            $barWidth = 38 + intval($percent * 62);

            $html .= "<div class='pvwm-row'>
                <span class='pvwm-hour'>$time</span>
                <span class='pvwm-bar-wrap'>
                    <span class='pvwm-bar' style='background:$color; width:{$barWidth}%;'>{$price} ct</span>
                </span>
            </div>";
        }
        $html .= '</div>';
        return $html;
    }
}
