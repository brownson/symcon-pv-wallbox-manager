<?php

class PVWallboxManager extends IPSModule
{

    // =========================================================================
    // 1. Initialisierung
    // =========================================================================

    public function Create()
    {
        // Immer zuerst
        parent::Create();

        // Properties aus form.json
        $this->RegisterPropertyString('WallboxIP', '0.0.0.0');
        $this->RegisterPropertyString('WallboxAPIKey', '');
        $this->RegisterPropertyInteger('RefreshInterval', 30);
        $this->RegisterPropertyBoolean('ModulAktiv', true);
        $this->RegisterPropertyBoolean('DebugLogging', false);
        $this->RegisterVariableString('Log', 'Modul-Log', '', 99);

        // Variablen nach API v2
        $this->RegisterCarStateProfile();
        $this->RegisterVariableInteger('Status',      'Status',                                 'GoE.CarStatus',     1);
        $this->RegisterAccessStateV2Profile();
        $this->RegisterVariableInteger('AccessStateV2', 'Wallbox Modus',                        'GoE.AccessStateV2', 2);
        $this->RegisterVariableFloat('Leistung',      'Aktuelle Ladeleistung zum Fahrzeug (W)', '~Watt',             3);
        $this->RegisterVariableInteger('Ampere',      'Max. Ladestrom (A)',                     '~Ampere',           4);
        $this->RegisterPSMProfile();
        $this->RegisterVariableInteger('Phasenmodus', 'Phasenmodus',                            'GoE.PSM',           5);
        $this->RegisterAlwProfile();
        $this->RegisterVariableBoolean('Freigabe',    'Ladefreigabe',                           'GoE.ALW',           6);
        $this->RegisterVariableInteger('Kabelstrom',  'Kabeltyp (A)',                           '~Ampere',           7);
        $this->RegisterVariableInteger('Energie',     'Geladene Energie (Wh)',                  '~Electricity.Wh',   8);
        $this->RegisterErrorCodeProfile();
        $this->RegisterVariableInteger('Fehlercode',  'Fehlercode',                             'GoE.ErrorCode',     9);

        // === 3. Energiequellen ===
        $this->RegisterPropertyInteger('PVErzeugungID', 0);
        $this->RegisterPropertyString('PVErzeugungEinheit', 'W');
        $this->RegisterPropertyInteger('NetzeinspeisungID', 0);
        $this->RegisterPropertyString('NetzeinspeisungEinheit', 'W');
        $this->RegisterPropertyBoolean('InvertNetzeinspeisung', false);
        $this->RegisterPropertyInteger('HausverbrauchID', 0);
        $this->RegisterPropertyString('HausverbrauchEinheit', 'W');
        $this->RegisterPropertyBoolean('InvertHausverbrauch', false);
        $this->RegisterPropertyInteger('BatterieladungID', 0);
        $this->RegisterPropertyString('BatterieladungEinheit', 'W');
        $this->RegisterPropertyBoolean('InvertBatterieladung', false);

        // Profil für Börsenpreis anlegen (ohne ~, robust)
        $profile = 'ElectricityPrice';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 2); // Float
            IPS_SetVariableProfileDigits($profile, 3);
            IPS_SetVariableProfileSuffix($profile, ' ct/kWh');
            IPS_SetVariableProfileIcon($profile, 'Euro');
        }

        // Strompreis-Börse / Forecast
        $this->RegisterPropertyBoolean('UseMarketPrices', false);
        $this->RegisterPropertyString('MarketPriceProvider', 'awattar_at');
        $this->RegisterPropertyString('MarketPriceAPI', '');
        $this->RegisterPropertyInteger('MarketPriceInterval', 30);
        $this->RegisterVariableFloat('CurrentSpotPrice', 'Aktueller Börsenpreis (ct/kWh)', $profile, 30);
        $this->RegisterVariableString('MarketPrices', 'Börsenpreis-Vorschau', '', 31);

        // Zielzeit für Zielzeitladung
        $this->RegisterVariableInteger('TargetTime', 'Zielzeit', '~UnixTimestampTime', 20);
        IPS_SetIcon($this->GetIDForIdent('TargetTime'), 'clock');

        // === Modul-Variablen für Visualisierung, Status, Lademodus etc. ===
        $this->RegisterVariableFloat('PV_Ueberschuss', '☀️ PV-Überschuss (W)', '~Watt', 10);
        IPS_SetIcon($this->GetIDForIdent('PV_Ueberschuss'), 'solar-panel');

        // Hausverbrauch (W)
        $this->RegisterVariableFloat('Hausverbrauch_W', '🏠 Hausverbrauch (W)', '~Watt', 12);
        IPS_SetIcon($this->GetIDForIdent('Hausverbrauch_W'), 'home');

        // Hausverbrauch abzügl. Wallbox (W) – wie vorher empfohlen
        $this->RegisterVariableFloat('Hausverbrauch_abz_Wallbox', '🏠 Hausverbrauch abzügl. Wallbox (W)', '~Watt', 15);
        IPS_SetIcon($this->GetIDForIdent('Hausverbrauch_abz_Wallbox'), 'home');

        // Lademodi
        $this->RegisterVariableBoolean('ManuellLaden', '🔌 Manuell: Vollladen aktiv', '~Switch', 40);
        $this->RegisterVariableBoolean('PV2CarModus', '🌞 PV2Car-Modus', '~Switch', 41);
        $this->RegisterVariableBoolean('ZielzeitLaden', '⏰ Zielzeit-Ladung', '~Switch', 42);
        $this->RegisterVariableInteger('PVAnteil', 'PV-Anteil (%)', '', 43);

        // Timer für zyklische Abfrage (z.B. alle 30 Sek.)
        $this->RegisterTimer('PVWM_UpdateStatus', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateStatus", "pvonly");');
        $this->RegisterTimer('PVWM_UpdateMarketPrices', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateMarketPrices", "");');

    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $interval = $this->ReadPropertyInteger('RefreshInterval'); 
        $this->LogTemplate('debug', "Timer-Intervall: $interval Sekunden");

        $aktiv = $this->ReadPropertyBoolean('ModulAktiv');
        if ($aktiv) {
            $this->SetTimerInterval('PVWM_UpdateStatus', $interval * 1000);
        } else {
            $this->SetTimerInterval('PVWM_UpdateStatus', 0); // Timer AUS
        }
        // Timer-Intervalle setzen:
        $interval = $this->ReadPropertyInteger('RefreshInterval');
        $this->SetTimerInterval('PVWM_UpdateStatus', $interval * 1000);

        $intervalMarket = $this->ReadPropertyInteger('MarketPriceInterval');
        $useMarketPrices = $this->ReadPropertyBoolean('UseMarketPrices');
        $this->SetTimerInterval(
            'PVWM_UpdateMarketPrices',
            ($useMarketPrices && $intervalMarket > 0) ? $intervalMarket * 60 * 1000 : 0
        );
    }

    // =========================================================================
    // 2. Events & RequestAction
    // =========================================================================

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === "UpdateStatus") {
            $this->UpdateStatus($Value); // $Value ist dann z.B. 'pvonly'
            return;
        }
        if ($Ident === "UpdateMarketPrices") {
            $this->UpdateMarketPrices();
            return;
        }
        throw new Exception("Invalid Ident: $Ident");
    }

    // =========================================================================
    // 3. Wallbox-Kommunikation (API-Funktionen)
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
            //$this->SetStatus(201); // Symcon-Status: Konfigurationsfehler
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
    // 4. Zentrale Steuerlogik
    // =========================================================================

    public function UpdateStatus(string $mode = 'pvonly')
    {
        $this->LogTemplate('debug', "UpdateStatus getriggert (Modus: $mode, Zeit: " . date("H:i:s") . ")");

        $data = $this->getStatusFromCharger();
        if ($data === false) {
            // Fehler wurde schon geloggt
            return;
        }

        // Defensive Daten-Extraktion
        $car = isset($data['car']) ? intval($data['car']) : 0;
        $leistung = (isset($data['nrg'][11]) && is_array($data['nrg'])) ? floatval($data['nrg'][11]) : 0.0;
        $ampere = isset($data['amp']) ? intval($data['amp']) : 0;
        $energie = isset($data['wh']) ? intval($data['wh']) : 0;
        $freigabe = isset($data['alw']) ? (bool)$data['alw'] : false;
        $kabelstrom = isset($data['cbl']) ? intval($data['cbl']) : 0;
        $fehlercode = isset($data['err']) ? intval($data['err']) : 0;
        $psm = isset($data['psm']) ? intval($data['psm']) : 0;
        $pha = $data['pha'] ?? [];
        $accessStateV2 = isset($data['accessStateV2']) ? intval($data['accessStateV2']) : 0;

        // Jetzt Werte NUR bei Änderung schreiben und loggen:
        $this->SetValueAndLogChange('Status',      $car,         'Status');
        $this->SetValueAndLogChange('AccessStateV2', $accessStateV2, 'Wallbox Modus');
        $this->SetValueAndLogChange('Leistung',    $leistung,    'Aktuelle Ladeleistung zum Fahrzeug', 'W');
        $this->SetValueAndLogChange('Ampere',      $ampere,      'Maximaler Ladestrom', 'A');
        $this->SetValueAndLogChange('Phasenmodus', $psm,         'Phasenmodus');
        $this->SetValueAndLogChange('Energie',     $energie,     'Geladene Energie', 'Wh');
        $this->SetValueAndLogChange('Freigabe',    $freigabe,    'Ladefreigabe');
        $this->SetValueAndLogChange('Kabelstrom',  $kabelstrom,  'Kabeltyp');
        $this->SetValueAndLogChange('Fehlercode',  $fehlercode,  'Fehlercode', '', 'warn');

        $pvUeberschuss = $this->BerechnePVUeberschuss();
    }

    // =========================================================================
    // 5. Set-Funktionen (Wallbox steuern)
    // =========================================================================

    public function SetChargingCurrent(int $ampere)
    {
        // Wertebereich prüfen – die meisten go-e erlauben nur 6–16A (bei manchen z.B. bis 32A)
        if ($ampere < 6 || $ampere > 16) {
            $this->LogTemplate('warn', "SetChargingCurrent: Ungültiger Wert ($ampere A). Erlaubt: 6–16A!");
            return false;
        }

        $ip = $this->ReadPropertyString('WallboxIP');
        $url = "http://$ip/api/set?amp=" . intval($ampere);

        $this->LogTemplate('info', "SetChargingCurrent: Sende Ladestrom $ampere A an $url");

        $result = @file_get_contents($url);

        if ($result === false) {
            $this->LogTemplate('error', "SetChargingCurrent: Fehler beim Setzen auf $ampere A!");
            return false;
        } else {
            $this->LogTemplate('ok', "SetChargingCurrent: Ladestrom auf $ampere A gesetzt.");
            // Direkt Status aktualisieren, damit das WebFront aktuell ist
            //IPS_Sleep(1000);
            $this->UpdateStatus();
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

        $result = @file_get_contents($url);

        if ($result === false) {
            $this->LogTemplate('error', "SetPhaseMode: Fehler beim Setzen auf '$modeText' ($mode)!");
            return false;
        } else {
            $this->LogTemplate('ok', "SetPhaseMode: Phasenmodus auf '$modeText' ($mode) gesetzt.");
            // Direkt Status aktualisieren
            $this->UpdateStatus();
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

        $ip = $this->ReadPropertyString('WallboxIP');
        $url = "http://$ip/api/set?frc=" . intval($state);

        $modes = [
            0 => "Neutral (Wallbox entscheidet)",
            1 => "Nicht Laden (gesperrt)",
            2 => "Laden (erzwungen)"
        ];
        $modeText = $modes[$state] ?? $state;

        $this->LogTemplate('info', "SetForceState: Sende Wallbox-Modus '$modeText' ($state) an $url");

        $result = @file_get_contents($url);

        if ($result === false) {
            $this->LogTemplate('error', "SetForceState: Fehler beim Setzen auf '$modeText' ($state)!");
            return false;
        } else {
            $this->LogTemplate('ok', "SetForceState: Wallbox-Modus auf '$modeText' ($state) gesetzt.");
            // Direkt Status aktualisieren
            $this->UpdateStatus();
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

        $result = @file_get_contents($url);

        if ($result === false) {
            $this->LogTemplate('error', "SetChargingEnabled: Fehler beim Setzen der Ladefreigabe ($alwValue)!");
            return false;
        } else {
            $this->LogTemplate('ok', "SetChargingEnabled: Ladefreigabe wurde auf '$statusText' ($alwValue) gesetzt.");
            $this->UpdateStatus();
            return true;
        }
    }
    
    public function StopCharging()
    {
        $ip = $this->ReadPropertyString('WallboxIP');
        $url = "http://$ip/api/set?stp=1";

        $this->LogTemplate('info', "StopCharging: Sende Stopp-Befehl an $url");

        $result = @file_get_contents($url);

        if ($result === false) {
            $this->LogTemplate('error', "StopCharging: Fehler beim Stoppen des Ladevorgangs!");
            return false;
        } else {
            $this->LogTemplate('ok', "StopCharging: Ladevorgang wurde gestoppt.");
            // Direkt Status aktualisieren
            $this->UpdateStatus();
            return true;
        }
    }

    // =========================================================================
    // 6. Hilfsfunktionen
    // =========================================================================

    private function SetValueAndLogChange($ident, $newValue, $caption = '', $unit = '', $level = 'info')
    {
        $varID = @$this->GetIDForIdent($ident);
        if ($varID === false) {
            $this->LogTemplate('warn', "Variable mit Ident '$ident' nicht gefunden!");
            return;
        }
        $oldValue = GetValue($varID);

        // Wenn identisch, nichts tun
        if ($oldValue === $newValue) {
            return;
        }

        // Werte ggf. als Klartext formatieren
        $formatValue = function($value) use ($ident, $varID) { 
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
        // *** NEU: Phasenmodus (psm) ***
        if ($profile == 'GoE.PSM') {
            $map = [0 => 'Auto', 1 => '1-phasig', 2 => '3-phasig'];
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
        if ($profile == 'GoE.AccessStateV2') {
        $map = [
            0 => 'Neutral (Wallbox entscheidet)',
            1 => 'Nicht Laden (gesperrt)',
            2 => 'Laden (erzwungen)'
        ];
        return $map[intval($value)] ?? $value;
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

        $this->LogTemplate($level, $msg);

        SetValue($varID, $newValue);
    }

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

    private function RegisterPSMProfile()
    {
        $profile = 'GoE.PSM';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1); // Integer
            IPS_SetVariableProfileAssociation($profile, 0, 'Auto', '', 0xAAAAAA);
            IPS_SetVariableProfileAssociation($profile, 1, '1-phasig', '', 0x00ADEF);
            IPS_SetVariableProfileAssociation($profile, 2, '3-phasig', '', 0xFF9900);
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

    private function RegisterAccessStateV2Profile()
    {
        $profile = 'GoE.AccessStateV2';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1); // Integer
            IPS_SetVariableProfileAssociation($profile, 0, 'Neutral (Wallbox entscheidet)', '', 0xAAAAAA);
            IPS_SetVariableProfileAssociation($profile, 1, 'Nicht Laden (gesperrt)', '', 0xFF4444);
            IPS_SetVariableProfileAssociation($profile, 2, 'Laden (erzwungen)', '', 0x44FF44);
        }
    }

    private function RegisterErrorCodeProfile()
    {
        $profile = 'GoE.ErrorCode';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1); // Integer
            IPS_SetVariableProfileAssociation($profile, 0,  'Kein Fehler',           '', 0x44FF44);
            IPS_SetVariableProfileAssociation($profile, 1,  'FI AC',                '', 0xFFAA00);
            IPS_SetVariableProfileAssociation($profile, 2,  'FI DC',                '', 0xFFAA00);
            IPS_SetVariableProfileAssociation($profile, 3,  'Phasenfehler',         '', 0xFF4444);
            IPS_SetVariableProfileAssociation($profile, 4,  'Überspannung',         '', 0xFF4444);
            IPS_SetVariableProfileAssociation($profile, 5,  'Überstrom',            '', 0xFF4444);
            IPS_SetVariableProfileAssociation($profile, 6,  'Diodenfehler',         '', 0xFF4444);
            IPS_SetVariableProfileAssociation($profile, 7,  'PP ungültig',          '', 0xFF4444);
            IPS_SetVariableProfileAssociation($profile, 8,  'GND ungültig',         '', 0xFF4444);
            IPS_SetVariableProfileAssociation($profile, 9,  'Schütz hängt',         '', 0xFF4444);
            IPS_SetVariableProfileAssociation($profile, 10, 'Schütz fehlt',         '', 0xFF4444);
            IPS_SetVariableProfileAssociation($profile, 11, 'FI unbekannt',         '', 0xFF4444);
            IPS_SetVariableProfileAssociation($profile, 12, 'Unbekannter Fehler',   '', 0xFF4444);
            IPS_SetVariableProfileAssociation($profile, 13, 'Übertemperatur',       '', 0xFF4444);
            IPS_SetVariableProfileAssociation($profile, 14, 'Keine Kommunikation',  '', 0xFF4444);
            IPS_SetVariableProfileAssociation($profile, 15, 'Verriegelung klemmt offen', '', 0xFF4444);
            IPS_SetVariableProfileAssociation($profile, 16, 'Verriegelung klemmt verriegelt', '', 0xFF4444);
            IPS_SetVariableProfileAssociation($profile, 20, 'Reserviert 20',        '', 0xAAAAAA);
            IPS_SetVariableProfileAssociation($profile, 21, 'Reserviert 21',        '', 0xAAAAAA);
            IPS_SetVariableProfileAssociation($profile, 22, 'Reserviert 22',        '', 0xAAAAAA);
            IPS_SetVariableProfileAssociation($profile, 23, 'Reserviert 23',        '', 0xAAAAAA);
            IPS_SetVariableProfileAssociation($profile, 24, 'Reserviert 24',        '', 0xAAAAAA);
        }
    }

    // =========================================================================
    // 7. LOGGING / STATUSMELDUNGEN / DEBUG
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
    // 8. Berechnungen
    // =========================================================================

    private function BerechnePVUeberschuss()
    {
        // PV-Erzeugung holen
        $pvID = $this->ReadPropertyInteger('PVErzeugungID');
        $pvEinheit = $this->ReadPropertyString('PVErzeugungEinheit');
        $pv = ($pvID > 0) ? GetValueFloat($pvID) : 0;
        if ($pvEinheit == "kW") $pv *= 1000;

        // Hausverbrauch holen (inkl. Wallbox-Leistung)
        $hvID = $this->ReadPropertyInteger('HausverbrauchID');
        $hvEinheit = $this->ReadPropertyString('HausverbrauchEinheit');
        $invertHV = $this->ReadPropertyBoolean('InvertHausverbrauch');
        $hausverbrauch = ($hvID > 0) ? GetValueFloat($hvID) : 0;
        if ($hvEinheit == "kW") $hausverbrauch *= 1000;
        if ($invertHV) $hausverbrauch *= -1;

        // Wallbox-Leistung (direkt an Auto, nur für Visualisierung)
        $ladeleistung = $this->GetValue('Leistung'); // oder $this->GetLadeleistungAuto()
        // Hausverbrauch abzügl. Wallbox (nur Visualisierung)
        $hausverbrauchAbzWallbox = $hausverbrauch - $ladeleistung;

        // Batterie-Ladung: Nur positiv (lädt)
        $batID = $this->ReadPropertyInteger('BatterieladungID');
        $batEinheit = $this->ReadPropertyString('BatterieladungEinheit');
        $invertBat = $this->ReadPropertyBoolean('InvertBatterieladung');
        $batterieladung = ($batID > 0) ? GetValueFloat($batID) : 0;
        if ($batEinheit == "kW") $batterieladung *= 1000;
        if ($invertBat) $batterieladung *= -1;

        // Verbrauch gesamt = Hausverbrauch (inkl. Wallbox) + nur wenn Batterie lädt (batterieladung > 0)
        $verbrauchGesamt = $hausverbrauch;
        if ($batterieladung > 0) {
            $verbrauchGesamt += $batterieladung;
        }

        // --- PV-Überschuss berechnen ---
        $pvUeberschuss = max(0, $pv - $verbrauchGesamt);

        // Variablen setzen und loggen
        $this->SetValueAndLogChange('PV_Ueberschuss', $pvUeberschuss, 'PV-Überschuss', ' W', 'debug');
        $this->SetValueAndLogChange('Hausverbrauch_W', $hausverbrauch, 'Hausverbrauch', ' W', 'debug');
        $this->SetValueAndLogChange('Hausverbrauch_abz_Wallbox', $hausverbrauchAbzWallbox, 'Hausverbrauch abz. Wallbox', ' W', 'debug');

        // Logging (kompakt)
        $this->LogTemplate(
            'debug',
            "PV-Überschuss berechnet: PV=$pv W, Haus=$hausverbrauch W, Wallbox=$ladeleistung W, Batterie=$batterieladung W → Überschuss=$pvUeberschuss W"
        );

        return $pvUeberschuss;
    }


    // =========================================================================
    // 9. (Optional) Erweiterungen & Auslagerungen
    // =========================================================================

    private function LadeAwattarForecast()
    {
        $provider = $this->ReadPropertyString('MarketPriceProvider');
        $url = '';
        if ($provider == 'awattar_at') {
            $url = 'https://api.awattar.at/v1/marketdata';
        } elseif ($provider == 'awattar_de') {
            $url = 'https://api.awattar.de/v1/marketdata';
        } elseif ($provider == 'custom') {
            $url = trim($this->ReadPropertyString('MarketPriceAPI'));
            if ($url == '') {
                $this->LogTemplate('warn', "Kein Custom-API-URL konfiguriert!");
                return false;
            }
        } else {
            $url = 'https://api.awattar.at/v1/marketdata';
        }

        $json = @file_get_contents($url);
        if ($json === false) {
            $this->LogTemplate('error', "API nicht erreichbar: $url");
            return false;
        }
        $data = json_decode($json, true);
        if (!isset($data['data']) || !is_array($data['data'])) {
            $this->LogTemplate('error', "Ungültige Antwort von Strompreis-API");
            return false;
        }

        $forecast = [];
        foreach ($data['data'] as $item) {
            $timestamp = intval($item['start_timestamp'] / 1000);
            $price_eur_per_mwh = floatval($item['marketprice']);
            $price_ct_per_kwh = $price_eur_per_mwh / 10.0;
            $forecast[] = [
                'timestamp' => $timestamp,
                'price'     => round($price_ct_per_kwh, 3)
            ];
        }
        return $forecast;
    }

    public function UpdateMarketPrices()
    {
        if (!$this->ReadPropertyBoolean('UseMarketPrices')) {
            $this->LogTemplate('info', "Strompreis-Update deaktiviert (UseMarketPrices=false)");
            return;
        }

        $forecast = $this->LadeAwattarForecast();
        if ($forecast === false) {
            $this->SetValueAndLogChange('CurrentSpotPrice', 0, 'Aktueller Börsenpreis', ' ct/kWh', 'warn');
            $this->SetValue('MarketPrices', '');
            return;
        }

        // Aktueller Preis (für die laufende Stunde)
        $now = time();
        $currentPrice = null;
        foreach ($forecast as $item) {
            if ($now >= $item['timestamp'] && $now < ($item['timestamp'] + 3600)) {
                $currentPrice = $item['price'];
                break;
            }
        }
        if ($currentPrice === null && count($forecast) > 0) {
            // Fallback: nächster Preis
            $currentPrice = $forecast[0]['price'];
        }

        $this->SetValueAndLogChange('CurrentSpotPrice', $currentPrice, 'Aktueller Börsenpreis', ' ct/kWh', 'debug');
        $this->SetValue('MarketPrices', json_encode($forecast));
        $this->LogTemplate('ok', "Strompreis-Update: Aktuell = $currentPrice ct/kWh, Forecast gespeichert ({$forecast[0]['timestamp']}ff)");
    }

}
