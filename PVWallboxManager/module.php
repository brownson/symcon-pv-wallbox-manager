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

        // Hysterese-Zyklen als Properties
        $this->RegisterPropertyInteger('Phasen1Limit', 3); // z.B. 3 = nach 3x Umschalten
        $this->RegisterPropertyInteger('Phasen3Limit', 3);
        $this->RegisterPropertyInteger('MinLadeWatt', 1400);      // Schwelle zum Starten (W)
        $this->RegisterPropertyInteger('MinStopWatt', 1100);      // Schwelle zum Stoppen (W)
        $this->RegisterPropertyInteger('StartLadeHysterese', 3);  // Zyklen Start-Hysterese
        $this->RegisterPropertyInteger('StopLadeHysterese', 3);   // Zyklen Stop-Hysterese
        $this->RegisterPropertyInteger('InitialCheckInterval', 10); // 0 = deaktiviert, 5–60 Sek.
    
        // Hysterese-Zähler (werden NICHT im WebFront angezeigt)
        $this->RegisterAttributeInteger('Phasen1Zaehler', 0);
        $this->RegisterAttributeInteger('Phasen3Zaehler', 0);
        $this->RegisterAttributeBoolean('NachPhasenwechsel', false);
        $this->RegisterAttributeInteger('LadeStartZaehler', 0);
        $this->RegisterAttributeInteger('LadeStopZaehler', 0);
        $this->RegisterAttributeString('HausverbrauchAbzWallboxBuffer', '[]');
        $this->RegisterAttributeFloat('HausverbrauchAbzWallboxLast', 0.0);


        // Variablen nach API v2
        $this->RegisterVariableInteger('Status',        'Status',                                   'PVWM.CarStatus',       1);
        $this->RegisterVariableInteger('AccessStateV2', 'Wallbox Modus',                            'PVWM.AccessStateV2',   2);
        $this->RegisterVariableFloat('Leistung',        'Aktuelle Ladeleistung zum Fahrzeug (W)',   'PVWM.Watt',            3);
        IPS_SetIcon($this->GetIDForIdent('Leistung'),   'Flash');
        $this->RegisterVariableInteger('Ampere',        'Max. Ladestrom (A)',                       'PVWM.Ampere',          4);
        IPS_SetIcon($this->GetIDForIdent('Ampere'),     'Energy');

        $this->RegisterVariableInteger('Phasenmodus',   'Phasenmodus',                              'PVWM.PSM',             5);
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
        $this->RegisterPropertyInteger('MarketPriceInterval', 30); // Minuten

        $this->RegisterVariableFloat('CurrentSpotPrice','Aktueller Börsenpreis (ct/kWh)',                   'PVWM.CentPerKWh', 30);
        $this->RegisterVariableString('MarketPrices', 'Börsenpreis-Vorschau', '', 31);

        $this->RegisterVariableString('MarketPricesPreview', '📊 Börsenpreis-Vorschau (HTML)', '~HTMLBox', 32);

        // Zielzeit für Zielzeitladung
        $this->RegisterVariableInteger('TargetTime', 'Zielzeit', '~UnixTimestampTime', 20);
        IPS_SetIcon($this->GetIDForIdent('TargetTime'), 'clock');

        // === Modul-Variablen für Visualisierung, Status, Lademodus etc. ===
        $this->RegisterVariableFloat('PV_Ueberschuss','☀️ PV-Überschuss (W)',                               'PVWM.Watt', 10);
        IPS_SetIcon($this->GetIDForIdent('PV_Ueberschuss'), 'solar-panel');

        $this->RegisterVariableInteger('PV_Ueberschuss_A', 'PV-Überschuss (A)',                             'PVWM.Ampere', 11);
        IPS_SetIcon($this->GetIDForIdent('PV_Ueberschuss_A'), 'Energy');

        // Hausverbrauch (W)
        $this->RegisterVariableFloat('Hausverbrauch_W','🏠 Hausverbrauch (W)',                              'PVWM.Watt', 12);
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
        $this->RegisterVariableInteger('PhasenmodusEinstellung', 'Phasenmodus (Einstellung)', 'PVWM.PSM', 50);
        IPS_SetIcon($this->GetIDForIdent('PhasenmodusEinstellung'), 'Lightning');
        $this->RegisterVariableInteger('Phasenmodus', 'Genutzte Phasen', '', 51);
        IPS_SetIcon($this->GetIDForIdent('Phasenmodus'), 'Lightning');

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
        $this->SetTimerNachModusUndAuto();
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
            [3, 'Warte auf Fahrzeug',                       'Car',          0x0088FF],
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
            [0, 'Auto',     'Gears', 0xAAAAAA],
            [1, '1-phasig', 'Plug', 0x00ADEF],
            [2, '3-phasig', 'Plug', 0xFF9900]
        ]);

        $create('PVWM.ALW', VARIABLETYPE_BOOLEAN, 0, '', 'Power', [
            [false, 'Nicht freigegeben', 'Close', 0xFF4444],
            [true,  'Laden freigegeben', 'Power', 0x44FF44]
        ]);

        $create('PVWM.AmpereCable', VARIABLETYPE_INTEGER, 0, ' A', 'Energy');

        // Die bisherigen Profile
        $create('PVWM.Ampere',      VARIABLETYPE_INTEGER, 0, ' A',      'Energy');
        $create('PVWM.Percent',     VARIABLETYPE_INTEGER, 0, ' %',      'Percent');
        IPS_SetVariableProfileValues('PVWM.Percent', 0, 100, 1);
        $create('PVWM.Watt',        VARIABLETYPE_FLOAT,   0, ' W',      'Flash');
        $create('PVWM.W',           VARIABLETYPE_FLOAT,   0, ' W',      'Flash');
        $create('PVWM.CentPerKWh',  VARIABLETYPE_FLOAT,   3, ' ct/kWh', 'Euro');
        $create('PVWM.Wh',          VARIABLETYPE_FLOAT,   0, ' Wh',     'Lightning');

    }

    // =========================================================================
    // 3. EVENTS & REQUESTACTION
    // =========================================================================

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case "UpdateStatus":
                $this->UpdateStatus($Value);
                break;
            case "UpdateMarketPrices":
                $this->AktualisiereMarktpreise();
                break;

        case "ManuellLaden":
            // Nur EIN Modus darf aktiv sein!
            if ($Value) {
                $this->SetValue('ManuellLaden', true);
                $this->SetValue('PV2CarModus', false);
                $this->LogTemplate('info', "🔌 Manuelles Vollladen aktiviert.");
            } else {
                $this->SetValue('ManuellLaden', false);
                $this->LogTemplate('info', "🔌 Manuelles Vollladen deaktiviert – zurück in PVonly-Modus.");

                // Zentralisierte Rücksetzung!
                $this->ResetWallboxToMinimal();
            }
            $this->SetTimerNachModusUndAuto();
            $this->UpdateStatus('pvonly');
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
        // Nach Phasenwechsel: Immer explizit Ladebefehl setzen!
        if ($this->ReadAttributeBoolean('NachPhasenwechsel')) {
            $this->LogTemplate('debug', "Nach Phasenwechsel: Ladebefehl wird jetzt explizit gesetzt (UpdateStatus).");
            $this->WriteAttributeBoolean('NachPhasenwechsel', false);

            // Neuen Phasenmodus bestimmen (aus aktuellen Daten)
            $anzPhasenNeu = 1;
            $dataPhasen = $this->getStatusFromCharger();
            if (is_array($dataPhasen) && isset($dataPhasen['nrg'][4], $dataPhasen['nrg'][5], $dataPhasen['nrg'][6])) {
                $phasenSchwelle = 1.5;
                $phasenAmpere = [
                    abs(floatval($dataPhasen['nrg'][4])),
                    abs(floatval($dataPhasen['nrg'][5])),
                    abs(floatval($dataPhasen['nrg'][6]))
                ];
                $anzPhasenNeu = 0;
                foreach ($phasenAmpere as $a) {
                    if ($a > $phasenSchwelle) $anzPhasenNeu++;
                }
                if ($anzPhasenNeu === 0) $anzPhasenNeu = 1;
            }

            // Ladeberechnung für neue Phasenanzahl durchführen
            $berechnung = $this->BerechnePVUeberschuss($anzPhasenNeu);
            $ampere = $berechnung['ueberschuss_a'];
            $minAmp = $this->ReadPropertyInteger('MinAmpere');
            $maxAmp = $this->ReadPropertyInteger('MaxAmpere');
            $ampere = max($minAmp, min($maxAmp, $ampere));
            
            $this->SetForceStateAndAmpereIfChanged(2, $ampere, true);
            $this->LogTemplate('debug', "Nach Phasenwechsel: Ladebefehl explizit gesendet (ForceSet: ja, Ampere: {$ampere}).");
        }

            // Ladefreigabe prüfen und setzen
            if ($this->GetValue('AccessStateV2') == 2 && $ampere >= $minAmp) {
                $changed = $this->SetForceStateAndAmpereIfChanged(2, $ampere, true);
                if ($changed) {
                    $this->LogTemplate('debug', "Nach Phasenwechsel wurde Ladebefehl ({$ampere}A) erneut gesetzt (FORCE).");
                } else {
                    $this->LogTemplate('debug', "Nach Phasenwechsel: Ladebefehl war schon korrekt ({$ampere}A).");
                }
            }

        // Hausverbrauch immer aktuell setzen – auch ohne Fahrzeug!
        $hvID = $this->ReadPropertyInteger('HausverbrauchID');
        $hvEinheit = $this->ReadPropertyString('HausverbrauchEinheit');
        $invertHV = $this->ReadPropertyBoolean('InvertHausverbrauch');
        $hausverbrauch = ($hvID > 0) ? @GetValueFloat($hvID) : 0;
        if ($hvEinheit == "kW") $hausverbrauch *= 1000;
        if ($invertHV) $hausverbrauch *= -1;
        $hausverbrauch = round($hausverbrauch);
        $this->SetValue('Hausverbrauch_W', $hausverbrauch);

        $this->LogTemplate('debug', "UpdateStatus getriggert (Modus: $mode, Zeit: " . date("H:i:s") . ")");

        $data = $this->getStatusFromCharger();

        // Wallbox nicht erreichbar: Visualisierung zurücksetzen und abbrechen
        if ($data === false) {
            $this->ResetWallboxVisualisierungKeinFahrzeug();
            $this->LogTemplate('debug', "Wallbox nicht erreichbar – Visualisierungswerte zurückgesetzt.");
            return;
        }

        if (!$this->FahrzeugVerbunden($data)) {
            $this->ResetLademodiWennKeinFahrzeug();
            return;
        }

        // 1. Phasenanzahl initial ermitteln (aus Wallbox, sonst Default 1)
        $anzPhasenAlt = 1;
        if ($data !== false && isset($data['nrg'][4], $data['nrg'][5], $data['nrg'][6])) {
            $phasenSchwelle = 1.5;
            $phasenAmpere = [
                abs(floatval($data['nrg'][4])),
                abs(floatval($data['nrg'][5])),
                abs(floatval($data['nrg'][6]))
            ];
            $anzPhasenAlt = 0;
            foreach ($phasenAmpere as $a) {
                if ($a > $phasenSchwelle) $anzPhasenAlt++;
            }
            if ($anzPhasenAlt === 0) $anzPhasenAlt = 1;
        }

        // Statuswerte holen für Visualisierung/Fallback
        $berechnung = null;
        $pvUeberschuss = null;
        $ampere = null;
        if ($this->GetValue('PV2CarModus')) {
            $werte = $this->BerechnePVUeberschussKomplett($anzPhasenAlt); // nur für Log
        } else {
            $berechnung = $this->BerechnePVUeberschuss($anzPhasenAlt);
            $pvUeberschuss = $berechnung['ueberschuss_w'];
            $ampere        = $berechnung['ueberschuss_a'];
        }

        // --- KEINE Wallbox-Daten – Visualisierung updaten & return ---
        // $car muss trotzdem immer gesetzt werden!
        $car = 0;
        if (is_array($data) && isset($data['car'])) {
            $car = intval($data['car']);
        }

        if ($data === false) {
            // Visualisierung zurücksetzen – immer robust!
            $this->ResetWallboxVisualisierungKeinFahrzeug();
            $this->LogTemplate('debug', "Wallbox nicht erreichbar – Visualisierungswerte zurückgesetzt.");
            return;
        }

        // Defensive Extraktion
        $leistung   = (isset($data['nrg'][11]) && is_array($data['nrg'])) ? floatval($data['nrg'][11]) : 0.0;
        $ampereWB   = isset($data['amp']) ? intval($data['amp']) : 0;
        $energie    = isset($data['wh']) ? intval($data['wh']) : 0;
        $freigabe   = isset($data['alw']) ? (bool)$data['alw'] : false;
        $kabelstrom = isset($data['cbl']) ? intval($data['cbl']) : 0;
        $fehlercode = isset($data['err']) ? intval($data['err']) : 0;
        $psm        = isset($data['psm']) ? intval($data['psm']) : 0;
        $accessStateV2 = 0;
        if (isset($data['frc'])) {
            $accessStateV2 = intval($data['frc']);
        } elseif (isset($data['accessStateV2'])) {
            $accessStateV2 = intval($data['accessStateV2']);
        }

        $hausverbrauchAbzWallbox = $hausverbrauch - $leistung;
        $this->SetValue('Hausverbrauch_abz_Wallbox', $hausverbrauchAbzWallbox);

        $this->SetValueAndLogChange('PhasenmodusEinstellung', $psm, 'Phasenmodus (Einstellung)', '', 'debug');
        $this->SetValueAndLogChange('Phasenmodus', $anzPhasenAlt, 'Genutzte Phasen', '', 'debug');
        $this->SetValueAndLogChange('Status',        $car,        'Status');
        $this->SetValueAndLogChange('AccessStateV2', $accessStateV2, 'Wallbox Modus');
        $this->SetValueAndLogChange('Leistung',      $leistung,   'Aktuelle Ladeleistung zum Fahrzeug', 'W');
        $this->SetValueAndLogChange('Ampere',        $ampereWB,   'Maximaler Ladestrom', 'A');
        $this->SetValueAndLogChange('Energie',       $energie,    'Geladene Energie', 'Wh');
        $this->SetValueAndLogChange('Freigabe',      $freigabe,   'Ladefreigabe');
        $this->SetValueAndLogChange('Kabelstrom',    $kabelstrom, 'Kabeltyp');
        $this->SetValueAndLogChange('Fehlercode',    $fehlercode, 'Fehlercode', '', 'warn');

        // 1. Manueller Modus
        if ($this->GetValue('ManuellLaden')) {
            $this->ModusManuellVollladen($data);
            return;
        }

        // 2. PV2Car-Modus (PV-Anteil laden)
        if ($this->GetValue('PV2CarModus')) {
            $this->ModusPV2CarLaden($data);
            return;
        }
        // 3. PVonly-Modus: komplett ausgelagert
        $this->ModusPVonlyLaden($data, $anzPhasenAlt, $mode);
    }

    private function ModusPVonlyLaden($data, $anzPhasenAlt, $mode = 'pvonly')
    {
        $minAmp = intval($this->ReadPropertyInteger('MinAmpere'));
        $maxAmp = intval($this->ReadPropertyInteger('MaxAmpere'));
        
            if ($this->ReadAttributeBoolean('NachPhasenwechsel')) {
                $this->LogTemplate('debug', "Nach Phasenwechsel: Ladebefehl wird jetzt explizit gesetzt (PVonlyLaden).");
                $this->WriteAttributeBoolean('NachPhasenwechsel', false);

                $anzPhasenNeu = max(1, $this->GetValue('Phasenmodus'));
                $berechnung = $this->BerechnePVUeberschuss($anzPhasenNeu);
                $ampere = isset($berechnung['ueberschuss_a']) ? intval($berechnung['ueberschuss_a']) : $minAmp;
                $ampere = max($minAmp, min($maxAmp, $ampere));
                
                $this->SetForceStateAndAmpereIfChanged(2, $ampere, true);
                $this->LogTemplate('debug', "Nach Phasenwechsel: Ladebefehl explizit gesendet (ForceSet: ja, Ampere: {$ampere}).");
            } else {
                // $ampere muss auch im "normalen" Zweig definiert sein!
                $anzPhasenNeu = max(1, $this->GetValue('Phasenmodus'));
                $berechnung = $this->BerechnePVUeberschuss($anzPhasenNeu);
                $ampere = isset($berechnung['ueberschuss_a']) ? intval($berechnung['ueberschuss_a']) : $minAmp;
                $ampere = max($minAmp, min($maxAmp, $ampere));
            }

            if ($this->GetValue('AccessStateV2') == 2 && $ampere >= $minAmp) {
                $changed = $this->SetForceStateAndAmpereIfChanged(2, $ampere);
                if ($changed) {
                    $this->LogTemplate('debug', "Ladebefehl nach Logik (".$ampere."A) gesetzt.");
                } else {
                    $this->LogTemplate('debug', "Ladebefehl war schon korrekt (".$ampere."A).");
                }
            }

        if (!$this->FahrzeugVerbunden($data)) {
            $this->ResetLademodiWennKeinFahrzeug();
            return;
        }

        // --- Überschuss neu berechnen (nach eventueller Phasenumschaltung) ---
        $anzPhasenNeu = max(1, $this->GetValue('Phasenmodus'));
        $berechnung = $this->BerechnePVUeberschuss($anzPhasenNeu);
        $pvUeberschuss = $berechnung['ueberschuss_w'];
        $ampere        = $berechnung['ueberschuss_a'];

        // Visualisierungswerte setzen
        $this->SetValue('PV_Ueberschuss', $pvUeberschuss);

        // Nach aller Logik (z.B. direkt vor Funktionsende/return)
        $accessStateV2 = $this->GetValue('AccessStateV2');
        $minAmp = $this->ReadPropertyInteger('MinAmpere');

        if ($accessStateV2 == 2 && $pvUeberschuss > 0) {
            // Wallbox lädt, dann tatsächlichen Wert anzeigen
            $ampereIst = $data['amp'] ?? $ampere;
            $this->SetValue('PV_Ueberschuss_A', $ampereIst);
        } else {
            // Gesperrt oder kein Überschuss, Minimalwert
            $this->SetValue('PV_Ueberschuss_A', $minAmp);
        }

        // Debug-Log – robust, mit Hinweis wenn Werte fehlen
        if (is_array($berechnung)) {
            $requiredFields = ['pv', 'haus', 'wallbox', 'batterie', 'phasenmodus', 'ueberschuss_w', 'ueberschuss_a'];
            $missing = array_diff($requiredFields, array_keys($berechnung));

            if (empty($missing)) {
                $this->LogTemplate(
                    'debug',
                    "PV-Überschuss: PV={$berechnung['pv']} W, Haus={$berechnung['haus']} W, Wallbox={$berechnung['wallbox']} W, Batterie={$berechnung['batterie']} W, Phasenmodus={$berechnung['phasenmodus']} → Überschuss={$berechnung['ueberschuss_w']} W / {$berechnung['ueberschuss_a']} A"
                );
            } else {
                $this->LogTemplate(
                    'warn',
                    'BerechnePVUeberschuss: Fehlende Felder im Array: ' . implode(', ', $missing)
                );
            }
        }

        // --- Phasenumschaltung prüfen (immer im PVonly-Modus) ---
        $this->PruefeUndSetzePhasenmodus($pvUeberschuss);

        // --- Hysterese für Ladefreigabe/Stop ---
        $minLadeWatt    = $this->ReadPropertyInteger('MinLadeWatt');
        $minStopWatt    = $this->ReadPropertyInteger('MinStopWatt');
        $startHysterese = $this->ReadPropertyInteger('StartLadeHysterese');
        $stopHysterese  = $this->ReadPropertyInteger('StopLadeHysterese');
        $startZaehler   = $this->ReadAttributeInteger('LadeStartZaehler');
        $stopZaehler    = $this->ReadAttributeInteger('LadeStopZaehler');
        $accessStateV2  = $this->GetValue('AccessStateV2');
        $aktFreigabe    = ($accessStateV2 == 2);

        // Start-Hysterese
        if ($pvUeberschuss >= $minLadeWatt) {
            $startZaehler++;
            $this->WriteAttributeInteger('LadeStartZaehler', $startZaehler);
            $this->WriteAttributeInteger('LadeStopZaehler', 0);

            if ($startZaehler >= $startHysterese && !$aktFreigabe) {
                $this->LogTemplate('ok', "Ladefreigabe: Start-Hysterese erreicht ($startZaehler x >= $minLadeWatt W). Ladefreigabe aktivieren.");
                $this->SetForceState(2);
            }
        } else {
            $this->WriteAttributeInteger('LadeStartZaehler', 0);
        }

        // Stop-Hysterese
        if ($pvUeberschuss <= $minStopWatt) {
            $stopZaehler++;
            $this->WriteAttributeInteger('LadeStopZaehler', $stopZaehler);
            $this->WriteAttributeInteger('LadeStartZaehler', 0);

            if ($stopZaehler >= $stopHysterese && $aktFreigabe) {
                $this->LogTemplate('warn', "... Stop-Hysterese erreicht ...");
                if ($this->GetValue('AccessStateV2') != 1) {
                    $this->SetForceState(1);
                }
                // Jetzt sauber zurücksetzen UND RETURN!
                $this->ResetWallboxToMinimal();
                return; // <-- Ohne return läuft sonst die Logik weiter!
            }

        $ladebefehlGesendet = false;
        $ladefreigabeGeaendert = false;

        // Wenn einer der beiden Befehle gesendet wurde:
        if ($ladebefehlGesendet || $ladefreigabeGeaendert) {
            $this->LogTemplate('debug', "Warte 3 Sekunden auf stabilen Hausverbrauch nach Wallbox-Befehl...");
            IPS_Sleep(3000); // 3 Sekunden warten
            $berechnung = $this->BerechnePVUeberschuss($anzPhasenNeu);
            $pvUeberschuss = $berechnung['ueberschuss_w'];
            $ampere        = $berechnung['ueberschuss_a'];
            $this->SetValue('PV_Ueberschuss', $pvUeberschuss);
            $this->SetValue('PV_Ueberschuss_A', $ampere);
        }

        // Ladefreigabe steuern
        $this->SteuerungLadefreigabe($pvUeberschuss, $mode, $ampere, $anzPhasenNeu);
        }
    }

    private function ModusManuellVollladen($data)
    {
        // Nach Phasenwechsel: Immer explizit MaxAmp erneut setzen!
        if ($this->ReadAttributeBoolean('NachPhasenwechsel')) {
            $this->LogTemplate('debug', "Nach Phasenwechsel: Ladebefehl wird jetzt explizit gesetzt (ManuellVollladen).");
            $this->WriteAttributeBoolean('NachPhasenwechsel', false);

            // MaxAmp immer forciert an die Wallbox schicken:
            $maxAmp = $this->ReadPropertyInteger('MaxAmpere');
            $this->SetForceStateAndAmpereIfChanged(2, $maxAmp, true); // true = erzwingen
            $this->LogTemplate('debug', "Nach Phasenwechsel: Ladebefehl explizit gesendet (ForceSet: ja, Ampere: {$maxAmp}).");
        }

        if (!$this->FahrzeugVerbunden($data)) {
            $this->ResetLademodiWennKeinFahrzeug();
            return;
        }

        $phasenmodusSoll = 2; // 3-phasig
        $maxAmp = $this->ReadPropertyInteger('MaxAmpere');
        $phasenmodusChanged = false;

        // Umschalten auf 3-phasig falls nötig
        if ($this->GetValue('PhasenmodusEinstellung') != $phasenmodusSoll) {
            $this->SetPhaseMode($phasenmodusSoll);
            $phasenmodusChanged = true;
        }

        // Immer MaxAmp setzen (so oder so, nach jeder Aktivierung)
        $ampChanged = $this->SetForceStateAndAmpereIfChanged(2, $maxAmp);

        // Nach Umschaltung warten – dann reale Phasen holen
        if ($phasenmodusChanged || $ampChanged) {
            $this->LogTemplate('debug', "Warte 3 Sekunden auf stabile Phasenumschaltung...");
            IPS_Sleep(3000);
        }

        // Hole NEU Wallbox-Daten
        $dataNeu = $this->getStatusFromCharger();
        $anzPhasen = 1;
        if (isset($dataNeu['nrg'][4], $dataNeu['nrg'][5], $dataNeu['nrg'][6])) {
            $anzPhasen = 0;
            foreach ([$dataNeu['nrg'][4], $dataNeu['nrg'][5], $dataNeu['nrg'][6]] as $a) {
                if (abs(floatval($a)) > 1.5) $anzPhasen++;
            }
            if ($anzPhasen == 0) $anzPhasen = 1;
        }

        // Neue Visualisierungswerte mit Fallback, falls etwas fehlt
        $werte = $this->BerechnePVUeberschussKomplett($anzPhasen);
        $this->SetValue('PV_Ueberschuss',   $werte['ueberschuss_w'] ?? 0);

        // EINZIGES Setzen, passend zum Zustand:
        $accessStateV2 = $this->GetValue('AccessStateV2');
        $minAmp = $this->ReadPropertyInteger('MinAmpere');
        if ($accessStateV2 == 2) {
            $ampereIst = $dataNeu['amp'] ?? $maxAmp;
            $this->SetValue('PV_Ueberschuss_A', $ampereIst);
        } else {
            $this->SetValue('PV_Ueberschuss_A', $minAmp);
        }

        $this->SetValueAndLogChange('Phasenmodus', $anzPhasen, 'Genutzte Phasen', '', 'debug');

        // Realer Ladestrom zur Anzeige (kann abweichen, falls Wallbox/Auto limitiert)
        $ampereIst = $dataNeu['amp'] ?? $maxAmp;

        $this->LogTemplate(
            'ok',
            sprintf(
                "🔌 Manuelles Vollladen aktiv (Phasen: %d, %d A, max. Leistung auf Fahrzeug). PV=%d W, HausOhneWB=%d W, Wallbox=%d W, Batterie=%d W, Überschuss=%d W / %d A",
                $anzPhasen,
                $ampereIst,
                $werte['pv'] ?? 0,
                $werte['haus'] ?? 0,
                $werte['wallbox'] ?? 0,
                $werte['batterie'] ?? 0,
                $werte['ueberschuss_w'] ?? 0,
                $ampereIst
            )
        );

        $this->SetTimerNachModusUndAuto();
    }

    private function ModusPV2CarLaden($data)
    {
        if ($this->ReadAttributeBoolean('NachPhasenwechsel')) {
            $anzPhasen = max(1, $this->GetValue('Phasenmodus'));
            $werte = $this->BerechnePVUeberschussKomplett($anzPhasen);
            $anteil = $this->GetValue('PVAnteil');
            $anteil = max(0, min(100, intval($anteil)));
            $pv2car = $this->BerechnePV2CarLadeleistung($werte, $anteil);
            $anteilWatt = $pv2car['anteil_watt'];
            $ampere = ceil($anteilWatt / (230 * $anzPhasen));
            $ampere = max($this->ReadPropertyInteger('MinAmpere'), min($this->ReadPropertyInteger('MaxAmpere'), $ampere));

            $this->LogTemplate('debug', "Nach Phasenwechsel: Ladebefehl explizit gesendet (ForceSet: ja, Ampere: {$ampere}).");
            $this->SetForceStateAndAmpereIfChanged(2, $ampere, true);
            $this->WriteAttributeBoolean('NachPhasenwechsel', false);
        }

        if (!$this->FahrzeugVerbunden($data)) {
            $this->ResetLademodiWennKeinFahrzeug();
            return;
        }

        $anteil = $this->GetValue('PVAnteil');
        $anteil = max(0, min(100, intval($anteil)));

        $anzPhasenAlt = max(1, $this->GetValue('Phasenmodus'));
        $werte = $this->BerechnePVUeberschussKomplett($anzPhasenAlt);

        $pv2car = $this->BerechnePV2CarLadeleistung($werte, $anteil);
        $pvUeberschussPV2Car = $pv2car['roh_ueber'];
        $anteilWatt = $pv2car['anteil_watt'];

        // Phasenumschaltung prüfen (immer auf aktuellem Überschuss!)
        $this->PruefeUndSetzePhasenmodus($pvUeberschussPV2Car);

        // Phasenanzahl ggf. nach Umschaltung neu holen und neu berechnen!
        $anzPhasen = max(1, $this->GetValue('Phasenmodus'));
        if ($anzPhasen !== $anzPhasenAlt) {
            $werte = $this->BerechnePVUeberschussKomplett($anzPhasen);
            $pv2car = $this->BerechnePV2CarLadeleistung($werte, $anteil);
            $pvUeberschussPV2Car = $pv2car['roh_ueber'];
            $anteilWatt = $pv2car['anteil_watt'];
        }

        $ampere = ceil($anteilWatt / (230 * $anzPhasen));
        $ampere = max($this->ReadPropertyInteger('MinAmpere'), min($this->ReadPropertyInteger('MaxAmpere'), $ampere));

        // JETZT explizit den Ladebefehl bei NachPhasenwechsel senden:
        if ($this->ReadAttributeBoolean('NachPhasenwechsel')) {
            $this->LogTemplate('debug', "Nach Phasenwechsel: Ladebefehl mit explizitem Ampere-Befehl (PV2CarLaden): {$ampere}A.");
            $this->SetForceStateAndAmpereIfChanged(2, $ampere, true); // true = immer senden
            $this->WriteAttributeBoolean('NachPhasenwechsel', false);
        }

        $this->SetValue('PV_Ueberschuss', $pvUeberschussPV2Car);

        // Am Ende (nach allen Befehlen):
        $accessStateV2 = $this->GetValue('AccessStateV2');
        $minAmp = $this->ReadPropertyInteger('MinAmpere');
        if ($accessStateV2 == 2 && $anteilWatt >= $minLadeWatt) {
            // Ladung aktiv: tatsächlichen Strom anzeigen
            $ampereIst = $data['amp'] ?? $ampere;
            $this->SetValue('PV_Ueberschuss_A', $ampereIst);
        } else {
            $this->SetValue('PV_Ueberschuss_A', $minAmp);
        }

        $this->SetValueAndLogChange('Phasenmodus', $anzPhasen, 'Genutzte Phasen', '', 'debug');

        // Hausakku-SOC prüfen (falls aktiv)
        $socID = $this->ReadPropertyInteger('HausakkuSOCID');
        $socSchwelle = $this->ReadPropertyInteger('HausakkuSOCVollSchwelle');
        $soc = ($socID > 0) ? @GetValue($socID) : false;
        $socText = ($soc !== false) ? $soc . "%" : "(nicht gesetzt)";
        if ($soc !== false && $soc >= $socSchwelle) {
            $anteil = 100;
            $pv2car = $this->BerechnePV2CarLadeleistung($werte, $anteil);
            $pvUeberschussPV2Car = $pv2car['roh_ueber'];
            $anteilWatt = $pv2car['anteil_watt'];
            $this->LogTemplate('ok', "Hausakku voll (SoC=$socText, Schwelle=$socSchwelle%). 100% PV-Überschuss wird geladen.");
        }

        // Hysterese
        $minLadeWatt    = $this->ReadPropertyInteger('MinLadeWatt');
        $minStopWatt    = $this->ReadPropertyInteger('MinStopWatt');
        $startHysterese = $this->ReadPropertyInteger('StartLadeHysterese');
        $stopHysterese  = $this->ReadPropertyInteger('StopLadeHysterese');
        $startZaehler   = $this->ReadAttributeInteger('PV2CarStartZaehler');
        $stopZaehler    = $this->ReadAttributeInteger('PV2CarStopZaehler');
        $aktFreigabe    = ($this->GetValue('AccessStateV2') == 2);

        // --- Start-Hysterese
        if ($anteilWatt >= $minLadeWatt) {
            $startZaehler++;
            $this->WriteAttributeInteger('PV2CarStartZaehler', $startZaehler);
            $this->WriteAttributeInteger('PV2CarStopZaehler', 0);

            if ($startZaehler >= $startHysterese && !$aktFreigabe) {
                $this->LogTemplate('ok', "PV2Car: Start-Hysterese erreicht ({$startZaehler} x >= {$minLadeWatt} W). Ladefreigabe aktivieren.");
                $changed = $this->SetForceState(2); // <-- Rückgabewert speichern
                if ($changed) {
                    IPS_Sleep(3000); // Nur wenn wirklich geändert!
                }
                $aktFreigabe = true;
            }
        } else {
            $this->WriteAttributeInteger('PV2CarStartZaehler', 0);
        }

        // --- Stop-Hysterese
        if ($anteilWatt <= $minStopWatt) {
            $stopZaehler++;
            $this->WriteAttributeInteger('PV2CarStopZaehler', $stopZaehler);
            $this->WriteAttributeInteger('PV2CarStartZaehler', 0);

            if ($stopZaehler >= $stopHysterese && $aktFreigabe) {
                $this->LogTemplate('warn', "PV2Car: Stop-Hysterese erreicht ({$stopZaehler} x <= {$minStopWatt} W). Ladefreigabe deaktivieren.");
                $this->ResetWallboxToMinimal();  // <-- Ergänzung für sauberen Minimalzustand
                return;
            }
        } else {
            $this->WriteAttributeInteger('PV2CarStopZaehler', 0);
        }

        // Laden (nur wenn erlaubt)
        if ($anteilWatt >= $minLadeWatt && $this->GetValue('AccessStateV2') == 2) {
            $changed = $this->SetForceStateAndAmpereIfChanged(2, $ampere);
            if ($changed) {
                IPS_Sleep(3000); // Nur wenn wirklich geändert!
            }
            $this->LogTemplate(
                'ok',
                "PV2Car: PV={$pv2car['pv']} W, HausOhneWB={$pv2car['haus']} W, Wallbox={$pv2car['wallbox']} W, Überschuss={$pvUeberschussPV2Car} W, Anteil={$anteil}% ({$anteilWatt} W), {$ampere} A"
            );
        } else {
            $this->LogTemplate(
                'debug',
                "PV2Car: Anteil {$anteilWatt} W < MinLadeWatt ({$minLadeWatt} W) oder Freigabe nicht aktiv – keine Änderung."
            );
        }
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
            $this->LogTemplate('warn', "SetChargingCurrent: Ungültiger Wert ($ampere A). Erlaubt: $minAmp–$maxAmp A!");
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
        // Wertebereich prüfen: 0 = Neutral, 1 = Nicht Laden, 2 = Laden
        if ($state < 0 || $state > 2) {
            $this->LogTemplate('warn', "SetForceState: Ungültiger Wert ($state). Erlaubt: 0=Neutral, 1=OFF, 2=ON!");
            return false;
        }

        // Prüfe aktuellen Wert aus Variable, um unnötige Requests zu vermeiden
        $currentState = $this->GetValue('AccessStateV2');
        if ($currentState === $state) {
            $this->LogTemplate('debug', "SetForceState: State bereits auf $state, keine Änderung notwendig.");
            return false;
        }

        $ip = $this->ReadPropertyString('WallboxIP');
        $url = "http://$ip/api/set?frc=" . intval($state);

        $modes = [
            0 => "Neutral (Wallbox entscheidet)",
            1 => "Nicht Laden (gesperrt)",
            2 => "Laden (erzwungen)"
        ];
        $modeText = $modes[$state] ?? $state;

        $this->LogTemplate('info', "SetForceState: Sende Wallbox-Modus '$modeText' ($state) an $url");

        $response = $this->simpleCurlGet($url);

        if ($response['result'] === false || $response['httpcode'] != 200) {
            $this->LogTemplate(
                'error',
                "SetForceState: Fehler beim Setzen auf '$modeText' ($state)! HTTP-Code: {$response['httpcode']}, cURL-Fehler: {$response['error']}"
            );
            return false;
        } else {
            $this->LogTemplate('ok', "SetForceState: Wallbox-Modus auf '$modeText' ($state) gesetzt. (HTTP {$response['httpcode']})");
            // Nach erfolgreichem Request: Aktualisiere Visualisierung
            // $this->UpdateStatus();
            return true;
        }
    }

    public function SetChargingEnabled(bool $enabled)
    {
        $ip = $this->ReadPropertyString('WallboxIP');
        $apiKey = $this->ReadPropertyString('WallboxAPIKey');
        $alwValue = $enabled ? 1 : 0;

        $statusText = $enabled ? "Laden erlaubt" : "Laden gesperrt";

        if ($apiKey != '') {
            // Offizieller Weg: mit API-Key
            $url = "http://$ip/api/set?dwo=0&alw=$alwValue&key=" . urlencode($apiKey);
            $this->LogTemplate('info', "SetChargingEnabled: Sende (API-Key) Ladefreigabe '$statusText' ($alwValue) an $url");
        } else {
            // Inoffizieller Weg: MQTT-Shortcut
            $url = "http://$ip/mqtt?payload=alw=$alwValue";
            $this->LogTemplate('info', "SetChargingEnabled: Sende (MQTT) Ladefreigabe '$statusText' ($alwValue) an $url");
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

    public function StopCharging()
    {
        $ip = $this->ReadPropertyString('WallboxIP');
        $url = "http://$ip/api/set?stp=1";

        $this->LogTemplate('info', "StopCharging: Sende Stopp-Befehl an $url");

        $response = $this->simpleCurlGet($url);

        if ($response['result'] === false || $response['httpcode'] != 200) {
            $this->LogTemplate(
                'error',
                "StopCharging: Fehler beim Stoppen des Ladevorgangs! HTTP-Code: {$response['httpcode']}, cURL-Fehler: {$response['error']}"
            );
            return false;
        } else {
            $this->LogTemplate('ok', "StopCharging: Ladevorgang wurde gestoppt. (HTTP {$response['httpcode']})");
            // Direkt Status aktualisieren
            //$this->UpdateStatus();
            return true;
        }
    }

    private function PruefeUndSetzePhasenmodus($pvUeberschuss)
    {
        $schwelle1 = $this->ReadPropertyInteger('Phasen1Schwelle');
        $schwelle3 = $this->ReadPropertyInteger('Phasen3Schwelle');
        $limit1    = $this->ReadPropertyInteger('Phasen1Limit');
        $limit3    = $this->ReadPropertyInteger('Phasen3Limit');
        $aktModus  = $this->GetValue('Phasenmodus');

        // ========== 3-phasig prüfen ==========
        if ($pvUeberschuss >= $schwelle3 && $aktModus != 2) {
            $zaehler3 = $this->ReadAttributeInteger('Phasen3Zaehler') + 1;
            $this->WriteAttributeInteger('Phasen3Zaehler', $zaehler3);
            $this->WriteAttributeInteger('Phasen1Zaehler', 0); // Nur den anderen Zähler zurücksetzen
            $this->LogTemplate('debug', "Phasen-Hysterese: $zaehler3/$limit3 Zyklen > Schwelle3");
            if ($zaehler3 >= $limit3) {
                $this->SetValueAndLogChange('Phasenmodus', 2, 'Phasenumschaltung', '', 'ok');
                $ok = $this->SetPhaseMode(2); // Wallbox: 2 = 3-phasig
                if ($ok) {
                    $this->LogTemplate('debug', "Umschalten auf 3-phasig: Warte 3 Sekunden, lese echten Phasenmodus aus Wallbox...");
                    IPS_Sleep(3000);
                    $data = $this->getStatusFromCharger();
                    $phasenIst = 1;
                    if (isset($data['nrg'][4], $data['nrg'][5], $data['nrg'][6])) {
                        $phasenIst = 0;
                        foreach ([$data['nrg'][4], $data['nrg'][5], $data['nrg'][6]] as $a) {
                            if (abs(floatval($a)) > 1.5) $phasenIst++;
                        }
                        if ($phasenIst == 0) $phasenIst = 1;
                    }
                    $this->SetValueAndLogChange('Phasenmodus', $phasenIst, 'Phasenmodus (nach Umschaltung)', '', 'ok');
                    $this->WriteAttributeInteger('Phasen3Zaehler', 0);
                    $this->WriteAttributeBoolean('NachPhasenwechsel', true); // <--- NEU
                    $this->LogTemplate('debug', "Nach Phasenumschaltung: Status wird neu berechnet, Ladebefehl im nächsten Zyklus!");
                    $this->UpdateStatus();
                    return;
                }
            }
            return;
        }

        // ========== 1-phasig prüfen ==========
        if ($pvUeberschuss <= $schwelle1 && $aktModus != 1) {
            $zaehler1 = $this->ReadAttributeInteger('Phasen1Zaehler') + 1;
            $this->WriteAttributeInteger('Phasen1Zaehler', $zaehler1);
            $this->WriteAttributeInteger('Phasen3Zaehler', 0);
            $this->LogTemplate('debug', "Phasen-Hysterese: $zaehler1/$limit1 Zyklen < Schwelle1");
            if ($zaehler1 >= $limit1) {
                $this->SetValueAndLogChange('Phasenmodus', 1, 'Phasenumschaltung', '', 'warn');
                $ok = $this->SetPhaseMode(1); // Wallbox: 1 = 1-phasig
                if ($ok) {
                    $this->LogTemplate('debug', "Umschalten auf 1-phasig: Warte 3 Sekunden, lese echten Phasenmodus aus Wallbox...");
                    IPS_Sleep(3000); // Zeit zum Umschalten!
                    $data = $this->getStatusFromCharger();
                    $phasenIst = 1;
                    if (isset($data['nrg'][4], $data['nrg'][5], $data['nrg'][6])) {
                        $phasenIst = 0;
                        foreach ([$data['nrg'][4], $data['nrg'][5], $data['nrg'][6]] as $a) {
                            if (abs(floatval($a)) > 1.5) $phasenIst++;
                        }
                        if ($phasenIst == 0) $phasenIst = 1;
                    }
                    $this->SetValueAndLogChange('Phasenmodus', $phasenIst, 'Phasenmodus (nach Umschaltung)', '', 'warn');
                    $this->WriteAttributeInteger('Phasen1Zaehler', 0);
                    $this->WriteAttributeBoolean('NachPhasenwechsel', true); // <--- NEU
                    $this->LogTemplate('debug', "Nach Phasenumschaltung: Status wird neu berechnet, Ladebefehl im nächsten Zyklus!");
                    $this->UpdateStatus();
                    return;
                }
            }
            return;
        }

        // === Auf 1-phasig umschalten, wenn Überschuss oft genug unterschritten ===
        if ($pvUeberschuss <= $schwelle1 && $aktModus != 1) {
            $zaehler = $this->ReadAttributeInteger('Phasen1Zaehler') + 1;
            $this->WriteAttributeInteger('Phasen1Zaehler', $zaehler);
            $this->WriteAttributeInteger('Phasen3Zaehler', 0);

            $this->LogTemplate('debug', "Phasen-Hysterese: $zaehler/$limit1 Zyklen < Schwelle1");
            if ($zaehler >= $limit1) {
                $this->SetValueAndLogChange('Phasenmodus', 1, 'Phasenumschaltung', '', 'warn');
                $ok = $this->SetPhaseMode(1); // Wallbox: 1 = 1-phasig
                if ($ok) {
                    $this->LogTemplate('debug', "Umschalten auf 1-phasig: Warte 3 Sekunden, lese echten Phasenmodus aus Wallbox...");
                    IPS_Sleep(3000); // Zeit zum Umschalten!
                    $data = $this->getStatusFromCharger();
                    $phasenIst = 1;
                    if (isset($data['nrg'][4], $data['nrg'][5], $data['nrg'][6])) {
                        $phasenIst = 0;
                        foreach ([$data['nrg'][4], $data['nrg'][5], $data['nrg'][6]] as $a) {
                            if (abs(floatval($a)) > 1.5) $phasenIst++;
                        }
                        if ($phasenIst == 0) $phasenIst = 1;
                    }
                    $this->SetValueAndLogChange('Phasenmodus', $phasenIst, 'Phasenmodus (nach Umschaltung)', '', 'warn');
                    // NEU:
                    $this->LogTemplate('debug', "Nach Phasenumschaltung: Status wird neu berechnet, Ladebefehl erst im nächsten Zyklus!");
                    $this->WriteAttributeInteger('Phasen1Zaehler', 0); // Zähler zurücksetzen!
                    $this->UpdateStatus();
                    return;
                } else {
                    $this->LogTemplate('error', 'PruefeUndSetzePhasenmodus: Umschalten auf 1-phasig fehlgeschlagen!');
                    $this->WriteAttributeInteger('Phasen1Zaehler', 0); // Zähler trotzdem zurücksetzen
                    return;
                }
            }
        }

        // Kein Umschaltgrund: Zähler zurücksetzen
        $this->WriteAttributeInteger('Phasen3Zaehler', 0);
        $this->WriteAttributeInteger('Phasen1Zaehler', 0);
    }

    private function SteuerungLadefreigabe($pvUeberschuss, $modus = 'pvonly', $ampere = 0, $anzPhasen = 1)
    {
        $minUeberschuss = $this->ReadPropertyInteger('MinLadeWatt'); // z.B. 1400 W

        // Default: Immer FRC=1 → Kein Laden, Wallbox gesperrt (wartet auf Überschuss)
        $sollFRC = 1;

        // PV-Modus: nur Laden bei Überschuss
        if ($modus === 'pvonly' && $pvUeberschuss >= $minUeberschuss) {
            $sollFRC = 2; // Laden erzwingen
        }

        // Manueller Modus: Immer laden, unabhängig vom Überschuss
        if ($modus === 'manuell') {
            $sollFRC = 2;
        }

        // Nur wenn nötig an Wallbox senden!
        $aktFRC = $this->GetValue('AccessStateV2');
        if ($aktFRC !== $sollFRC) {
            $ok = $this->SetForceState($sollFRC);
            if ($ok) {
                $this->LogTemplate('ok', "Ladefreigabe auf FRC=$sollFRC gestellt (Modus: $modus, Überschuss: {$pvUeberschuss}W)");
                IPS_Sleep(1000); // Kleines Delay, damit die Wallbox reagieren kann
            } else {
                $this->LogTemplate('warn', "Ladefreigabe setzen auf FRC=$sollFRC **fehlgeschlagen**!");
            }
        }

        // Nur Ladestrom setzen, wenn Freigabe aktiv und Ampere gültig
        if ($sollFRC == 2 && $ampere > 0) {
            // Zusatz: Prüfen, ob sich der gewünschte Ladestrom von aktuellem unterscheidet
            $currentAmp = $this->GetValue('Ampere');
            if ($currentAmp != $ampere) {
                $ok = $this->SetChargingCurrent($ampere);
                if ($ok) {
                    $this->LogTemplate('ok', "Ladestrom auf $ampere A gesetzt (tatsächliche Phasen: $anzPhasen).");
                } else {
                    $this->LogTemplate('warn', "Setzen des Ladestroms auf $ampere A **fehlgeschlagen**!");
                }
            } else {
                $this->LogTemplate('debug', "Ladestrom bereits auf $ampere A (Phasen: $anzPhasen), keine Änderung nötig.");
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
                        3 => 'Warte auf Fahrzeug',
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

    private function SetForceStateAndAmpereIfChanged(int $forceState, int $ampere, bool $force = false)
    {
        $changed = false;
        $currentForceState = $this->GetValue('AccessStateV2');
        $currentAmpere = $this->GetValue('Ampere');

        // Immer loggen, was gewünscht ist!
        $this->LogTemplate('debug', "SetForceStateAndAmpereIfChanged: Soll=$forceState, Ampere=$ampere, Ist=$currentForceState/$currentAmpere, Force=".($force?'JA':'nein'));

        // Zuerst den Modus (FRC)
        if ($force || $currentForceState !== $forceState) {
            $set = $this->SetForceState($forceState);
            if ($set) {
                $this->LogTemplate('debug', "ForceState geändert: $currentForceState → $forceState");
                $changed = true;
            } else {
                $this->LogTemplate('debug', "ForceState beibehalten ($currentForceState) – kein Setzen nötig");
            }
        }

        // Dann Ampere setzen (sofern abweichend oder force)
        if ($force || $currentAmpere !== $ampere) {
            $set = $this->SetChargingCurrent($ampere);
            if ($set) {
                $this->LogTemplate('debug', "Ampere geändert: $currentAmpere → $ampere");
                $changed = true;
            } else {
                $this->LogTemplate('debug', "Ampere beibehalten ($currentAmpere) – kein Setzen nötig");
            }
        }

        return $changed;
    }

    private function ResetWallboxVisualisierungKeinFahrzeug()
    {
        $this->SetValue('Leistung', 0);                  // Ladeleistung zum Fahrzeug
        $this->SetValue('PV_Ueberschuss', 0);            // PV-Überschuss (W)
        $this->SetValue('PV_Ueberschuss_A', 0);          // PV-Überschuss (A) – Jetzt 0A!
//        $this->SetValue('Hausverbrauch_abz_Wallbox', 0); // Hausverbrauch abz. Wallbox

        // Hausverbrauch trotzdem live anzeigen (wie oben beschrieben)
        $hvID = $this->ReadPropertyInteger('HausverbrauchID');
        $hvEinheit = $this->ReadPropertyString('HausverbrauchEinheit');
        $invertHV = $this->ReadPropertyBoolean('InvertHausverbrauch');
        $hausverbrauch = ($hvID > 0) ? @GetValueFloat($hvID) : 0;
        if ($hvEinheit == "kW") $hausverbrauch *= 1000;
        if ($invertHV) $hausverbrauch *= -1;
        $hausverbrauch = round($hausverbrauch);
        $this->SetValue('Hausverbrauch_W', $hausverbrauch);

        $this->SetValue('Hausverbrauch_abz_Wallbox', $hausverbrauch);

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
        // Timer- und Statusattribute initialisieren (Self-Healing nach Update/Neuinstallation)
        if (!@is_int($this->ReadAttributeInteger('MarketPricesTimerInterval'))) {
            $this->WriteAttributeInteger('MarketPricesTimerInterval', 0);
        }
        if (!@is_bool($this->ReadAttributeBoolean('MarketPricesActive'))) {
            $this->WriteAttributeBoolean('MarketPricesActive', false);
        }

        $aktiv = $this->ReadPropertyBoolean('ModulAktiv');
        $car = @$this->GetValue('Status');
        $mainInterval = intval($this->ReadPropertyInteger('RefreshInterval'));
        $initialInterval = $this->GetInitialCheckInterval();

        $this->LogTemplate('debug', "SetTimerNachModusUndAuto: Status=" . print_r($car, true));

        // Immer zuerst NUR Status-Timer AUS (nicht MarketPrices!)
        $this->SetTimerInterval('PVWM_UpdateStatus', 0);
        $this->SetTimerInterval('PVWM_InitialCheck', 0);

        if (!$aktiv) {
            $this->LogTemplate('debug', "Modul nicht aktiv, alle Timer gestoppt.");
            // Strompreis-Timer auch aus:
            $this->SetTimerInterval('PVWM_UpdateMarketPrices', 0);
            return;
        }

        // EINEN Haupttimer setzen – je nach Fahrzeugstatus
        if ($car === false || $car <= 1) { // 0=unbekannt, 1=kein Fahrzeug
            if ($initialInterval > 0) {
                $this->SetTimerInterval('PVWM_InitialCheck', $initialInterval * 1000);
                $this->LogTemplate('debug', "InitialCheck-Timer gestartet (alle $initialInterval Sekunden, bis Fahrzeug erkannt)");
            } else {
                $this->LogTemplate('debug', "InitialCheck-Intervall ist 0 – kein Schnellpoll.");
            }
            $this->SetTimerInterval('PVWM_UpdateStatus', 0);
        } else {
            $this->SetTimerInterval('PVWM_UpdateStatus', $mainInterval * 1000);
            $this->LogTemplate('debug', "PVWM_UpdateStatus-Timer gestartet (alle $mainInterval Sekunden)");
            $this->SetTimerInterval('PVWM_InitialCheck', 0);
        }

        $marketInterval = max(5, $this->ReadPropertyInteger('MarketPriceInterval'));
        $newInterval = $marketInterval * 60 * 1000;
        $active = $this->ReadPropertyBoolean('UseMarketPrices');

        $lastInterval = $this->ReadAttributeInteger('MarketPricesTimerInterval');
        $lastActive   = $this->ReadAttributeBoolean('MarketPricesActive');

        // Nach Modulupdate (Timer = aus), immer wiederherstellen!
        if ($active) {
            if ($lastInterval != $newInterval || !$lastActive || $this->GetTimerInterval('PVWM_UpdateMarketPrices') == 0) {
                $this->SetTimerInterval('PVWM_UpdateMarketPrices', $newInterval);
                $this->WriteAttributeInteger('MarketPricesTimerInterval', $newInterval);
                $this->WriteAttributeBoolean('MarketPricesActive', true);
                $this->LogTemplate('debug', "PVWM_UpdateMarketPrices-Timer (gesetzt/aktiv) $newInterval ms");
            }
        } else {
            if ($lastActive || $this->GetTimerInterval('PVWM_UpdateMarketPrices') != 0) {
                $this->SetTimerInterval('PVWM_UpdateMarketPrices', 0);
                $this->WriteAttributeBoolean('MarketPricesActive', false);
                $this->LogTemplate('debug', "PVWM_UpdateMarketPrices-Timer gestoppt");
            }
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

    private function ResetWallboxToMinimal() 
    {
        $this->SetPhaseMode(1); // 1-phasig
        IPS_Sleep(1000); // für sichere Umschaltung
        $this->SetChargingCurrent($this->ReadPropertyInteger('MinAmpere')); // meist 6A
        $this->LogTemplate('ok', "Wallbox zurückgesetzt: 1-phasig, {$this->ReadPropertyInteger('MinAmpere')}A gesetzt.");
    }

    private function AnalysiereGoENrgArray($nrg)
    {
        // Indizes je nach go-e Firmware
        $I_L1 = isset($nrg[4]) ? floatval($nrg[4]) : 0.0;
        $I_L2 = isset($nrg[5]) ? floatval($nrg[5]) : 0.0;
        $I_L3 = isset($nrg[6]) ? floatval($nrg[6]) : 0.0;

        $P_L1 = isset($nrg[8])  ? floatval($nrg[8])  : 0.0;
        $P_L2 = isset($nrg[9])  ? floatval($nrg[9])  : 0.0;
        $P_L3 = isset($nrg[10]) ? floatval($nrg[10]) : 0.0;
        $P_total = isset($nrg[11]) ? floatval($nrg[11]) : ($P_L1 + $P_L2 + $P_L3);

        // Welche Phasen sind aktiv? Schwelle > 1A
        $aktivePhasen = [];
        if ($I_L1 > 1.0) $aktivePhasen[] = 1;
        if ($I_L2 > 1.0) $aktivePhasen[] = 2;
        if ($I_L3 > 1.0) $aktivePhasen[] = 3;
        $phasen = count($aktivePhasen);

        return [
            'phasen'         => $phasen,
            'aktive_phasen'  => $aktivePhasen,
            'leistung'       => $P_total, // Gesamtleistung in Watt
            'strom_je_phase' => [$I_L1, $I_L2, $I_L3]
        ];
    }

    // Wartet nach einem Ladebefehl (z.B. Setzen von Ampere oder Phasen), damit der Hausverbrauch nach dem Umschalten sauber aktualisiert werden kann.
    private function WarteVorHausverbrauchUpdate($ladebefehlErfolgt)
    {
        if ($ladebefehlErfolgt) {
            $this->LogTemplate('debug', "Ladestrom/Phasen geändert – warte 3 Sekunden für Hausverbrauchs-Update.");
            IPS_Sleep(3000);
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
    private function BerechnePVUeberschuss($anzPhasen = 1)
    {
        // PV-Erzeugung holen
        $pvID = $this->ReadPropertyInteger('PVErzeugungID');
        $pvEinheit = $this->ReadPropertyString('PVErzeugungEinheit');
        $pv = ($pvID > 0) ? GetValueFloat($pvID) : 0;
        if ($pvEinheit == "kW") $pv *= 1000;

        // Wallbox-Leistung (direkt am Auto, nur für Visualisierung)
        $ladeleistung = round($this->GetValue('Leistung'));

        // Kurzes Delay für stabilere Werte (optional)
        IPS_Sleep(500);

        // Hausverbrauch holen (inkl. Wallbox-Leistung)
        $hvID = $this->ReadPropertyInteger('HausverbrauchID');
        $hvEinheit = $this->ReadPropertyString('HausverbrauchEinheit');
        $invertHV = $this->ReadPropertyBoolean('InvertHausverbrauch');
        $hausverbrauch = ($hvID > 0) ? GetValueFloat($hvID) : 0;
        if ($hvEinheit == "kW") $hausverbrauch *= 1000;
        if ($invertHV) $hausverbrauch *= -1;
        $hausverbrauch = round($hausverbrauch);

        // Hausverbrauch ABZÜGLICH Wallbox-Leistung (für Visualisierung)
        $hausverbrauchAbzWallbox = round($hausverbrauch - $ladeleistung);

        // --- Glättung & Spike-Filter ---
        $buffer = json_decode($this->ReadAttributeString('HausverbrauchAbzWallboxBuffer'), true);
        if (!is_array($buffer)) $buffer = [];
        $buffer[] = $hausverbrauchAbzWallbox;
        if (count($buffer) > 3) array_shift($buffer);

        $mittelwert = array_sum($buffer) / count($buffer);
        $spikeSchwelle = 1.5 * $this->ReadPropertyInteger('MaxAmpere') * 230;
        $letzterWert = floatval($this->ReadAttributeFloat('HausverbrauchAbzWallboxLast'));

        if ($letzterWert > 0 && abs($hausverbrauchAbzWallbox - $letzterWert) > $spikeSchwelle) {
            $hausverbrauchAbzWallboxGlaettet = $letzterWert;
            $this->LogTemplate('warn', "Spike erkannt: $hausverbrauchAbzWallbox W (letzter Wert: $letzterWert W, Schwelle: $spikeSchwelle W) – Wert wird NICHT gespeichert!");
        } else {
            $hausverbrauchAbzWallboxGlaettet = $mittelwert;
            $this->WriteAttributeString('HausverbrauchAbzWallboxBuffer', json_encode($buffer));
            $this->WriteAttributeFloat('HausverbrauchAbzWallboxLast', $hausverbrauchAbzWallboxGlaettet);
        }

        // Für Anzeige/Log den geglätteten/spikegefilterten Wert nehmen:
        $this->SetValueAndLogChange('Hausverbrauch_abz_Wallbox', $hausverbrauchAbzWallboxGlaettet, 'Hausverbrauch abz. Wallbox', 'W', 'debug');

        // Batterie-Ladung (positiv = lädt, negativ = entlädt)
        $batID = $this->ReadPropertyInteger('BatterieladungID');
        $batEinheit = $this->ReadPropertyString('BatterieladungEinheit');
        $invertBat = $this->ReadPropertyBoolean('InvertBatterieladung');
        $batterieladung = ($batID > 0) ? GetValueFloat($batID) : 0;
        if ($batEinheit == "kW") $batterieladung *= 1000;
        if ($invertBat) $batterieladung *= -1;

        // Batterie-Entladung NICHT als Überschuss behandeln!
        if ($batterieladung < 0) $batterieladung = 0; // Nur Laden zählt

        // Verbrauch gesamt (Batterie positiv = lädt, negativ = entlädt)
        $verbrauchGesamt = $hausverbrauchAbzWallbox + $batterieladung;

        // --- PV-Überschuss berechnen (Standardformel: PV – Hausverbrauch – Batterie) ---
        $pvUeberschuss = max(0, $pv - $verbrauchGesamt);
        if (abs($pvUeberschuss) < 1) $pvUeberschuss = 0.0;

        // --- Ladestrom (Ampere) berechnen ---
        $minAmp = $this->ReadPropertyInteger('MinAmpere');
        $maxAmp = $this->ReadPropertyInteger('MaxAmpere');
        $ampere = ceil($pvUeberschuss / (230 * $anzPhasen));
        $ampere = max($minAmp, min($maxAmp, $ampere));

        // Visualisierung
        $this->SetValueAndLogChange('PV_Ueberschuss', $pvUeberschuss, 'PV-Überschuss', 'W', 'debug');
        $this->SetValueAndLogChange('Hausverbrauch_W', $hausverbrauch, 'Hausverbrauch', 'W', 'debug');
        $this->SetValueAndLogChange('Hausverbrauch_abz_Wallbox', $hausverbrauchAbzWallbox, 'Hausverbrauch abz. Wallbox', 'W', 'debug');
//        $this->SetValueAndLogChange('PV_Ueberschuss_A', $ampere, 'PV-Überschuss (A)', 'A', 'debug');

        // Logging
        $this->LogTemplate(
            'debug',
            "PV-Überschuss: PV=$pv W, Haus=$hausverbrauchAbzWallbox W, Wallbox=$ladeleistung W, Batterie=$batterieladung W, Phasenmodus=$anzPhasen → Überschuss=$pvUeberschuss W / $ampere A"
        );

        // Rückgabe für die Steuerlogik
        return [
            'pv'             => $pv,
            'haus'           => $hausverbrauchAbzWallbox,   // oder ggf. $hausverbrauch, wenn OHNE Wallbox geliefert wird!
            'wallbox'        => $ladeleistung,
            'batterie'       => $batterieladung,
            'ueberschuss_w'  => $pvUeberschuss,
            'ueberschuss_a'  => $ampere,
            'phasenmodus'    => $anzPhasen
        ];
    }

    // Zentrale PV-Überschussberechnung: IMMER korrekt (inkl. Haus, Batterie, Wallbox)
    private function BerechnePVUeberschussKomplett($anzPhasen = 1)
    {
        // --- PV-Leistung ---
        $pvID = $this->ReadPropertyInteger('PVErzeugungID');
        $pv = ($pvID > 0) ? GetValueFloat($pvID) : 0;
        if ($this->ReadPropertyString('PVErzeugungEinheit') == "kW") $pv *= 1000;

        // --- Wallbox-Leistung (was am Auto ankommt!) ---
        $ladeleistung = round($this->GetValue('Leistung'));

        // --- Hausverbrauch (inkl. Wallbox) ---
        $hvID = $this->ReadPropertyInteger('HausverbrauchID');
        $hausverbrauch = ($hvID > 0) ? GetValueFloat($hvID) : 0;
        if ($this->ReadPropertyString('HausverbrauchEinheit') == "kW") $hausverbrauch *= 1000;

        // --- Hausverbrauch OHNE Wallbox (für PV2Car-Aufteilung & Visualisierung) ---
        $hausverbrauchOhneWB = $hausverbrauch - $ladeleistung;

        // --- Batterieladung (Vorzeichen prüfen!) ---
        $batID = $this->ReadPropertyInteger('BatterieladungID');
        $batterieladung = ($batID > 0) ? GetValueFloat($batID) : 0;
        if ($this->ReadPropertyString('BatterieladungEinheit') == "kW") $batterieladung *= 1000;
        if ($this->ReadPropertyBoolean('InvertBatterieladung')) $batterieladung *= -1;

        // Rückgabe für alle folgenden Berechnungen:
        return [
            'pv'         => $pv,                   // PV-Leistung in W
            'wallbox'    => $ladeleistung,         // aktuelle Ladeleistung Auto in W
            'haus'       => $hausverbrauch,        // Hausverbrauch GESAMT (inkl. Wallbox) in W
            'hausOhneWB' => $hausverbrauchOhneWB,  // Hausverbrauch OHNE Wallbox in W
            'batterie'   => $batterieladung        // Batterie-Leistung (Vorzeichen wie in Variable)
        ];
    }

    private function BerechnePV2CarLadeleistung($werte, $anteil)
    {
        // PV2Car: PV - Hausverbrauch OHNE Wallbox
        $rohUeberschuss = max(0, $werte['pv'] - $werte['hausOhneWB']);
        $anteilWatt = intval(round($rohUeberschuss * $anteil / 100));

        return [
            'roh_ueber'   => $rohUeberschuss,        // max. möglicher Überschuss für Verteilung (W)
            'anteil_watt' => $anteilWatt,            // davon der Anteil für das Auto (W)
            'pv'          => $werte['pv'],           // PV-Leistung (W)
            'haus'        => $werte['hausOhneWB'],   // Hausverbrauch ohne Wallbox (W)
            'wallbox'     => $werte['wallbox'],      // aktuelle Ladeleistung (W)
            'batterie'    => $werte['batterie'],     // Batterie-Leistung (W)
        ];
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
        $preise = [];
        $now = time();
        $maxTimestamp = $now + 36 * 3600; // bis max 36h in die Zukunft

        foreach ($data['data'] as $item) {
            if (isset($item['start_timestamp'], $item['marketprice'])) {
                $start = intval($item['start_timestamp'] / 1000);
                // if ($start < $now) continue; // optional je nach Use-Case
                if ($start > $maxTimestamp) break;
                $preise[] = [
                    'timestamp' => $start,
                    'price' => floatval($item['marketprice'] / 10.0)
                ];
                // Kein Einzel-Log hier!
            }
        }

        $this->LogTemplate('debug', "Preise-Array nach Verarbeitung: " . count($preise)); // Kurz-Log der Array-Größe

        if (count($preise) === 0) {
            $this->LogTemplate('warn', "Keine gültigen Preisdaten gefunden!");
            return;
        }

        // Aktuellen Preis setzen (erster Datensatz)
        $aktuellerPreis = $preise[0]['price'];
        $this->SetValueAndLogChange('CurrentSpotPrice', $aktuellerPreis);

        // Forecast als JSON speichern
        $this->SetValueAndLogChange('MarketPrices', json_encode($preise));
        $this->LogTemplate('debug', "MarketPrices wurde gesetzt: " . substr(json_encode($preise), 0, 100) . "..."); // Nur die ersten Zeichen fürs Log

        // HTML-Vorschau speichern
        $this->SetValue('MarketPricesPreview', $this->FormatMarketPricesPreviewHTML(12));

        // Nur eine Logmeldung am Ende!
        $this->LogTemplate('ok', "Börsenpreise aktualisiert: Aktuell {$aktuellerPreis} ct/kWh – " . count($preise) . " Preispunkte gespeichert.");
    }

    private function FormatMarketPricesPreviewHTML($max = 12)
    {
        $preiseRaw = @$this->GetValue('MarketPrices');
        if (!$preiseRaw) {
            return '<span style="color:#888;">Keine Preisdaten verfügbar.</span>';
        }
        $preise = json_decode($preiseRaw, true);
        if (!is_array($preise) || count($preise) === 0) {
            return '<span style="color:#888;">Keine Preisdaten verfügbar.</span>';
        }

        $preise = array_slice($preise, 0, $max);
        $allePreise = array_column($preise, 'price');
        $min = min($allePreise);
        $maxPrice = max($allePreise);

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
