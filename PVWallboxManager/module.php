<?php

class PVWallboxManager extends IPSModule
{
public function Create()
{
    parent::Create();

    // Visualisierung berechneter Werte
    $this->RegisterVariableFloat('PV_Ueberschuss', 'PV-Überschuss (W)', '~Watt', 10);
    $this->RegisterVariableFloat('PV_Ueberschuss', '☀️ PV-Überschuss (W)', '~Watt', 10);
    $this->RegisterVariableFloat('Hausverbrauch', '🏠 Hausverbrauch (W)', '~Watt', 11);
    $this->RegisterVariableFloat('WallboxLeistung', '🔌 Wallbox-Leistung (W)', '~Watt', 12);

    // Energiequellen (Variablen-IDs für Berechnung)
    $this->RegisterPropertyInteger('PVErzeugungID', 0);
    $this->RegisterPropertyString("PVErzeugungEinheit", "W");
    $this->RegisterPropertyInteger('HausverbrauchID', 0);
    $this->RegisterPropertyBoolean("InvertHausverbrauch", false);
    $this->RegisterPropertyString("HausverbrauchEinheit", "W");
    $this->RegisterPropertyInteger('BatterieladungID', 0);
    $this->RegisterPropertyBoolean("InvertBatterieladung", false);
    $this->RegisterPropertyString("BatterieladungEinheit", "W");
    $this->RegisterPropertyInteger('NetzeinspeisungID', 0);
    $this->RegisterPropertyBoolean("InvertNetzeinspeisung", false);
    $this->RegisterPropertyString("NetzeinspeisungEinheit", "W");

    // Wallbox-Einstellungen
    $this->RegisterPropertyInteger('GOEChargerID', 0);
    $this->RegisterPropertyInteger('MinAmpere', 6);
    $this->RegisterPropertyInteger('MaxAmpere', 16);
    $this->RegisterPropertyInteger('Phasen', 3);

    // Lade-Logik & Schwellenwerte
    $this->RegisterPropertyInteger('MinLadeWatt', 1400);
    $this->RegisterPropertyInteger('MinStopWatt', -300);
    $this->RegisterPropertyInteger('Phasen1Schwelle', 1000);
    $this->RegisterPropertyInteger('Phasen3Schwelle', 4200);
    $this->RegisterPropertyInteger('Phasen1Limit', 3);
    $this->RegisterPropertyInteger('Phasen3Limit', 3);
    $this->RegisterPropertyBoolean('DynamischerPufferAktiv', true);

    // Fahrzeug-Erkennung & Ziel-SOC
    $this->RegisterPropertyBoolean('NurMitFahrzeug', true);
    $this->RegisterPropertyBoolean('AllowBatteryDischarge', true);
    $this->RegisterPropertyBoolean('UseCarSOC', false);
    $this->RegisterPropertyInteger('CarSOCID', 0);
    $this->RegisterPropertyFloat('CarSOCFallback', 20);
    $this->RegisterPropertyInteger('CarTargetSOCID', 0);
    $this->RegisterPropertyFloat('CarTargetSOCFallback', 80);
    $this->RegisterPropertyInteger('MaxAutoWatt', 11000);
    $this->RegisterPropertyFloat('CarBatteryCapacity', 52.0);
    $this->RegisterPropertyBoolean('AlwaysUseTargetSOC', false);

    // Status-Zähler für Phasenumschaltung
    $this->RegisterAttributeInteger('Phasen1Counter', 0);
    $this->RegisterAttributeInteger('Phasen3Counter', 0);
    $this->RegisterAttributeBoolean('RunLogFlag', true);

    // Hysterese
    $this->RegisterPropertyInteger('StartHysterese', 0);
    $this->RegisterPropertyInteger('StopHysterese', 0);
    $this->RegisterAttributeInteger('StartHystereseCounter', 0);
    $this->RegisterAttributeInteger('StopHystereseCounter', 0);

    // PV2Car Verteilung: NUR WebFront-Variablen (KEINE Properties!)
    $this->RegisterVariableBoolean('PVVerteilenAktiv', '🚗 PV-Überschuss aufteilen (PV2Car)', '', 45);
    $this->EnableAction('PVVerteilenAktiv');
    if (!@IPS_VariableProfileExists('PVAnteilAuto.Prozent')) {
        IPS_CreateVariableProfile('PVAnteilAuto.Prozent', 2); // 2 = Float
        IPS_SetVariableProfileText('PVAnteilAuto.Prozent', '', '%');
        IPS_SetVariableProfileValues('PVAnteilAuto.Prozent', 10, 100, 5);
    }
    $this->RegisterVariableFloat('PVAnteilAuto', 'Anteil fürs Fahrzeug (%)', 'PVAnteilAuto.Prozent', 46);
    $this->EnableAction('PVAnteilAuto');
    if (GetValue($this->GetIDForIdent('PVAnteilAuto')) == 0) {
        SetValue($this->GetIDForIdent('PVAnteilAuto'), 50);
    }
    // Hausakku-SoC als Property, falls noch nicht vorhanden:
    $this->RegisterPropertyInteger('HausakkuSOCID', 0);
    $this->RegisterPropertyInteger('HausakkuSOCVollSchwelle', 95);

    // Visualisierung & WebFront
    $this->RegisterVariableBoolean('ManuellVollladen', '🔌 Manuell: Vollladen aktiv', '', 20);
    $this->EnableAction('ManuellVollladen');
    $this->RegisterVariableBoolean('ZielzeitladungModus', '⏱️ Zielzeitladung', '', 40);
    $this->EnableAction('ZielzeitladungModus');
    $this->RegisterVariableBoolean('AllowBatteryDischargeStatus', 'PV-Batterieentladung zulassen', '', 98);
    $this->RegisterVariableString('FahrzeugStatusText', 'Fahrzeug Status', '', 70);
    $this->RegisterVariableString('LademodusStatus', 'Aktueller Lademodus', '', 80);
    $this->RegisterVariableString('WallboxStatusText', 'Wallbox Status', '~HTMLBox', 90);
    $this->RegisterVariableInteger('TargetTime', 'Ziel-Zeit (Uhr)', '~UnixTimestampTime', 60);
    $this->EnableAction('TargetTime');

    // Zeit & Preis-Parameter
    $this->RegisterPropertyInteger('RefreshInterval', 60);
    $this->RegisterVariableString('MarketPrices', '🔢 Strompreis-Forecast', '', 21);
    $this->RegisterVariableString('MarketPricesText', 'Preisvorschau', '', 22);
    $this->RegisterPropertyBoolean('UseMarketPrices', false);
    $this->RegisterPropertyString('MarketPriceProvider', 'awattar_at');
    $this->RegisterPropertyString('MarketPriceAPI', '');
    $this->RegisterPropertyInteger('MarketPriceInterval', 30);

    // Timer
    $this->RegisterTimer('PVUeberschuss_Berechnen', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateCharging", 0);');
    $this->RegisterTimer('MarketPrice_Update', 0, 'PVWM_UpdateMarketPrices($_IPS[\'TARGET\']);');

    $this->RegisterPropertyBoolean('ModulAktiv', true);
    $this->RegisterPropertyBoolean('DebugLogging', false);
    $this->RegisterAttributeBoolean('RunLock', false);

}

    // =====================================================================================================

public function ApplyChanges()
{
    parent::ApplyChanges();
    $this->Log("ApplyChanges(): Konfiguration wird angewendet.", 'debug');

    // --- Zielzeit-Profil (Stundenanzeige im WebFront) ---
    $profil = 'TimeTarget_Seconds';
    if (!IPS_VariableProfileExists($profil)) {
        IPS_CreateVariableProfile($profil, 1); // 1 = Integer
    }

    // Schrittweite 1 Stunde (3600 Sekunden) → Anzeige als 00:00, 01:00, ..., 23:00
    IPS_SetVariableProfileText($profil, '', '');
    IPS_SetVariableProfileValues($profil, 0, 86399, 3600); // von 0 bis 23:59 Uhr, Schritt: 1 Stunde

    // Assoziationen: jede volle Stunde mit Uhrzeit anzeigen
    if (IPS_VariableProfileExists($profil)) {
        // 0 bis 86399 in der Schrittweite (z.B. 3600 für Stunden)
        for ($v = 0; $v <= 86399; $v += 3600) {
            @IPS_SetVariableProfileAssociation($profil, $v, '', '', -1);
        }
        for ($h = 0; $h < 24; $h++) {
            $seconds = $h * 3600;
            $label = sprintf('%02d:00', $h);
            IPS_SetVariableProfileAssociation($profil, $seconds, $label, '', -1);
        }
        $this->RegisterVariableInteger('TargetTime', 'Zielzeit', $profil, 40);
    }

    // --- PV2Car-Modus: Variablen und Slider im WebFront ---
    $this->RegisterVariableBoolean('PVVerteilenAktiv', '☀️ PV-Überschuss aufteilen (PV2Car)', '', 45);

    // Prozentwert als Slider
    $sliderProfil = 'PVAnteilAuto.Prozent';
    if (!@IPS_VariableProfileExists($sliderProfil)) {
        IPS_CreateVariableProfile($sliderProfil, 2); // 2 = Float
        IPS_SetVariableProfileText($sliderProfil, '', ' %');
        IPS_SetVariableProfileValues($sliderProfil, 10, 100, 5); // 10%-100%, Schrittweite 5%
    }
    $this->RegisterVariableFloat('PVAnteilAuto', 'Anteil fürs Fahrzeug (%)', $sliderProfil, 46);
    if (GetValue($this->GetIDForIdent('PVAnteilAuto')) == 0) {
        SetValue($this->GetIDForIdent('PVAnteilAuto'), 50);
    }

    // --- Grundlegende Parameter lesen ---
    $interval = $this->ReadPropertyInteger('RefreshInterval');
    $goeID    = $this->ReadPropertyInteger('GOEChargerID');
    $pvID     = $this->ReadPropertyInteger('PVErzeugungID');

    // --- Timer für Strompreis-Aktualisierung ---
    if ($this->ReadPropertyBoolean('UseMarketPrices')) {
        $intervalMarket = $this->ReadPropertyInteger('MarketPriceInterval');
        if ($intervalMarket > 0) {
            $this->SetTimerInterval('MarketPrice_Update', $intervalMarket * 60000);
            $this->Log("Timer MarketPrice_Update aktiviert: Intervall = {$intervalMarket} Minuten", 'info');
            $this->UpdateMarketPrices();
        } else {
            $this->SetTimerInterval('MarketPrice_Update', 0);
            $this->Log("Timer MarketPrice_Update deaktiviert (Intervall = 0)", 'info');
        }
    } else {
        $this->SetTimerInterval('MarketPrice_Update', 0);
        $this->Log("Timer MarketPrice_Update deaktiviert (UseMarketPrices = false)", 'info');
    }

    // --- Modul deaktiviert: Alles stoppen ---
    if (!$this->ReadPropertyBoolean('ModulAktiv')) {
        if ($goeID > 0 && @IPS_InstanceExists($goeID)) {
            GOeCharger_setMode($goeID, 1);
            GOeCharger_SetCurrentChargingWatt($goeID, 0);
        }

        // Nur noch die tatsächlich verwendeten Modus-Variablen
        foreach (['ManuellVollladen', 'ZielzeitladungModus', 'PVVerteilenAktiv'] as $mod) {
            if (@$this->GetIDForIdent($mod) && GetValue($this->GetIDForIdent($mod))) {
                SetValue($this->GetIDForIdent($mod), false);
            }
        }

        if (@$this->GetIDForIdent('PV_Ueberschuss')) {
            SetValue($this->GetIDForIdent('PV_Ueberschuss'), 0.0);
        }

        $this->SetLademodusStatus("🛑 Modul deaktiviert – alle Vorgänge gestoppt.");
        $this->SetFahrzeugStatus("🛑 Modul deaktiviert.");
        $this->SetTimerInterval('PVUeberschuss_Berechnen', 0);
        $this->RemoveStatusEvent();

        $this->Log("ApplyChanges(): Modul deaktiviert, Vorgänge gestoppt.", 'info');
        return;
    }

    // --- Modul aktiv: Status-Event & Timer setzen ---
    if ($goeID > 0) {
        $this->CreateStatusEvent($goeID);
    }

    if ($goeID > 0 && $pvID > 0 && $interval > 0) {
        $this->SetTimerInterval('PVUeberschuss_Berechnen', $interval * 1000);
        $this->Log("Timer aktiviert: PVUeberschuss_Berechnen alle {$interval} Sekunden", 'info');
        $this->Log("ApplyChanges(): Initialer Berechnungsdurchlauf wird gestartet.", 'info');
        $this->UpdateCharging();
    } else {
        $this->SetTimerInterval('PVUeberschuss_Berechnen', 0);
        $this->RemoveStatusEvent();
        $this->Log("ApplyChanges(): Timer deaktiviert – GO-e, PV oder Intervall nicht konfiguriert.", 'warn');
    }

    // --- Visualisierung: Batterie-Entladung ---
    $this->SetValue('AllowBatteryDischargeStatus', $this->ReadPropertyBoolean('AllowBatteryDischarge'));

    $this->Log("ApplyChanges(): Konfiguration abgeschlossen.", 'debug');
}


// =====================================================================================================

public function RequestAction($ident, $value)
{
    // Schönes Logging für Zeit-Variable
    if ($ident === "TargetTime") {
        $stunden = floor($value / 3600);
        $minuten = floor(($value % 3600) / 60);
        $zeitString = sprintf("%02d:%02d", $stunden, $minuten);
        $this->Log("RequestAction(): Aufruf mit Ident=TargetTime, Value={$value} ({$zeitString})", 'debug');
    } else {
        $this->Log("RequestAction(): Aufruf mit Ident={$ident}, Value=" . json_encode($value), 'debug');
    }

    // --- Schalter & Buttons behandeln ---
    switch ($ident) {
        case "ManuellVollladen":
            $this->SetValue("ManuellVollladen", $value);
            if ($value) {
                SetValue($this->GetIDForIdent('ZielzeitladungModus'), false);
                SetValue($this->GetIDForIdent('PVVerteilenAktiv'), false);
                $this->UpdateCharging();
            } else {
                // Vollladen deaktiviert → sofort stoppen!
                $goeID = $this->ReadPropertyInteger('GOEChargerID');
                $this->SetLadeleistung(0);
                if (function_exists('GOeCharger_SetMode')) {
                    GOeCharger_SetMode($goeID, 1); // Standby/Bereit
                }
                $this->SetLademodusStatus("🔴 Manuelles Vollladen gestoppt (per Button)!");
                $this->Log("Manuelles Vollladen wurde deaktiviert: Ladung sofort gestoppt.", 'info');
                return; // Keine weitere Logik
            }
            break;

        case 'PVVerteilenAktiv':
            $this->SetValue('PVVerteilenAktiv', $value);
            if ($value) {
                SetValue($this->GetIDForIdent('ManuellVollladen'), false);
                SetValue($this->GetIDForIdent('ZielzeitladungModus'), false);
            }
            break;

        case 'PVAnteilAuto':
            // Slider für Anteil Fahrzeug
            $this->SetValue('PVAnteilAuto', $value);
            break;

        case 'ZielzeitladungModus':
            $this->SetValue('ZielzeitladungModus', $value);
            if ($value) {
                SetValue($this->GetIDForIdent('ManuellVollladen'), false);
                SetValue($this->GetIDForIdent('PVVerteilenAktiv'), false);
            }
            break;

        case 'TargetTime':
            // Begrenzung auf gültigen Wertebereich: 0–86399 (23:59:59)
            $value = max(0, min($value, 86399));
            $this->SetValue('TargetTime', $value);
            break;

        default:
            parent::RequestAction($ident, $value);
            break;
    }

    // --- Prüfen, ob alle Lademodi deaktiviert ---
    $manuell   = GetValue($this->GetIDForIdent('ManuellVollladen'));
    $verteilen = GetValue($this->GetIDForIdent('PVVerteilenAktiv'));
    $ziel      = GetValue($this->GetIDForIdent('ZielzeitladungModus'));

    if (!$manuell && !$verteilen && !$ziel) {
        $this->Log("RequestAction(): Alle Lademodi deaktiviert – Standardmodus wird aktiviert.", 'info');
    }

    // --- Hauptlogik immer zum Schluss ausführen ---
    $this->UpdateCharging();
}


// =====================================================================================================

public function UpdateCharging()
{
    // --- Schutz vor Parallelaufrufen ---
    if ($this->ReadAttributeBoolean('RunLock')) {
        $this->Log("UpdateCharging(): Läuft bereits – Aufruf abgebrochen.", 'warn');
        return;
    }
    $this->WriteAttributeBoolean('RunLock', true);
    $this->Log("UpdateCharging(): Berechnung startet.", 'debug');

    try {
        // --- Hausverbrauch berechnen ---
        $hausverbrauch = $this->BerechneHausverbrauch();
        if ($hausverbrauch === false) {
            $this->Log("UpdateCharging(): Hausverbrauch konnte nicht berechnet werden – Abbruch.", 'error');
            return;
        }

        $goeID = $this->ReadPropertyInteger('GOEChargerID');
        $status = GOeCharger_GetStatus($goeID);  // 1=bereit, 2=lädt, 3=warte, 4=beendet

        // --- Kein Fahrzeug verbunden ---
        if ($this->ReadPropertyBoolean('NurMitFahrzeug') && $status == 1) {
            foreach (['ManuellVollladen', 'PVVerteilenAktiv', 'ZielzeitladungModus'] as $mod) {
                if (GetValue($this->GetIDForIdent($mod))) {
                    SetValue($this->GetIDForIdent($mod), false);
                }
            }
            $this->SetLadeleistung(0);
            $this->SetFahrzeugStatus("⚠️ Kein Fahrzeug verbunden – bitte anschließen.");
            SetValue($this->GetIDForIdent('PV_Ueberschuss'), 0.0);
            $this->SetLademodusStatusByReason('no_vehicle');
            $this->UpdateWallboxStatusText();
            $this->Log("UpdateCharging(): Kein Fahrzeug verbunden – Berechnung abgebrochen.", 'warn');
            return;
        }

        // Wallbox-Leistung abfragen
        $powerToCarTotal = GetValue($this->GetIDForIdent('powerToCarTotal'));
        
        $ladeleistung = GOeCharger_GetPowerToCar($goeID);

        // Sicherstellen, dass der Wert korrekt abgerufen wurde
        $this->Log("Aktuelle Wallbox-Leistung (powerToCarTotal): {$ladeleistung} W", 'debug');
        
        // --- PV-Überschuss berechnen ---
        $pvUeberschussStandard = $this->BerechnePVUeberschuss($hausverbrauch);
        SetValue($this->GetIDForIdent('PV_Ueberschuss'), $pvUeberschussStandard);
        $this->Log("UpdateCharging(): Standard-PV-Überschuss = {$pvUeberschussStandard} W", 'debug');

        $minLadeWatt = $this->ReadPropertyInteger('MinLadeWatt');

        // --- Fahrzeug verbunden, Ladefreigabe prüfen ---
        if ($this->ReadPropertyBoolean('NurMitFahrzeug') && in_array($status, [3, 4])) {

            $ladefreigabe = (
                GetValue($this->GetIDForIdent('ManuellVollladen')) ||
                GetValue($this->GetIDForIdent('ZielzeitladungModus')) ||
                GetValue($this->GetIDForIdent('PVVerteilenAktiv')) ||
                $pvUeberschussStandard >= $minLadeWatt
            );

            if (!$ladefreigabe) {
                GOeCharger_SetMode($goeID, 1);
                $this->SetFahrzeugStatus("🚗 Fahrzeug verbunden, keine Ladefreigabe (wartet auf PV oder Modus).");
                $this->SetLademodusStatusByReason('no_ladefreigabe');
                $this->UpdateWallboxStatusText();
                $this->Log("UpdateCharging(): Keine Ladefreigabe – Wallbox auf 'Bereit'", 'info');
                return;
            } else {
                GOeCharger_SetMode($goeID, 2);
                $this->Log("UpdateCharging(): Ladefreigabe erkannt – Wallbox auf 'Laden'", 'info');
            }
        }

        // --- Fahrzeugstatus anzeigen ---
        if ($this->ReadPropertyBoolean('NurMitFahrzeug')) {
            if ($status == 3) {
                $this->SetFahrzeugStatus("🚗 Fahrzeug angeschlossen, wartet auf Freigabe.");
            }
            if ($status == 4) {
                $this->SetFahrzeugStatus("🅿️ Fahrzeug verbunden, Ladung beendet.");
            }
        }

        // --- Ziel-SOC berücksichtigen ---
        if ($this->ReadPropertyBoolean('AlwaysUseTargetSOC')) {
            $socID = $this->ReadPropertyInteger('CarSOCID');
            $soc = (IPS_VariableExists($socID) && $socID > 0) ? GetValue($socID) : $this->ReadPropertyFloat('CarSOCFallback');
            $targetSOCID = $this->ReadPropertyInteger('CarTargetSOCID');
            $targetSOC = (IPS_VariableExists($targetSOCID) && $targetSOCID > 0) ? GetValue($targetSOCID) : $this->ReadPropertyFloat('CarTargetSOCFallback');
            $capacity = $this->ReadPropertyFloat('CarBatteryCapacity');

            $fehlendeProzent = max(0, $targetSOC - $soc);
            $fehlendeKWh = $capacity * $fehlendeProzent / 100.0;

            $this->Log("UpdateCharging(): SOC-Prüfung: Ist={$soc}% | Ziel={$targetSOC}% | Fehlend=" . round($fehlendeProzent, 2) . "% | Fehlende kWh=" . round($fehlendeKWh, 2) . " kWh", 'info');

            if ($soc >= $targetSOC) {
                $this->SetLadeleistung(0);
                $this->SetLademodusStatus("✅ Ziel-SOC erreicht ({$soc}% ≥ {$targetSOC}%) – keine weitere Ladung.");
                $this->UpdateWallboxStatusText();
                $this->Log("UpdateCharging(): Ziel-SOC erreicht – Ladung gestoppt.", 'info');
                return;
            }
        }

        // --- Modus-Weiche ---
        if (GetValue($this->GetIDForIdent('ManuellVollladen'))) {
            $this->LogikManuellVollladen();
            return;
        } elseif (GetValue($this->GetIDForIdent('ZielzeitladungModus'))) {
            $this->LogikZielzeitladung($hausverbrauch);
            return;
        } elseif (GetValue($this->GetIDForIdent('PVVerteilenAktiv'))) {
            $this->LogikPV2CarModus();
            return;
        } else {
            $this->LogikPVPureMitHysterese('standard', $hausverbrauch);
            return;
        }

        // --- Statusanzeige aktualisieren ---
        $ladeleistung = ($goeID > 0) ? GOeCharger_GetPowerToCar($goeID) : 0;
        $batt = $this->GetNormWert('BatterieladungID', 'BatterieladungEinheit', 'InvertBatterieladung', "Batterieladung");
        $hausakkuSOCID = $this->ReadPropertyInteger('HausakkuSOCID');
        $hausakkuSOC = ($hausakkuSOCID > 0 && @IPS_VariableExists($hausakkuSOCID)) ? GetValue($hausakkuSOCID) : 100;
        $hausakkuSOCVoll = $this->ReadPropertyInteger('HausakkuSOCVollSchwelle');
        $socID = $this->ReadPropertyInteger('CarSOCID');
        $soc = (IPS_VariableExists($socID) && $socID > 0) ? GetValue($socID) : 0;
        $targetSOCID = $this->ReadPropertyInteger('CarTargetSOCID');
        $targetSOC = (IPS_VariableExists($targetSOCID) && $targetSOCID > 0) ? GetValue($targetSOCID) : 0;
        $wartenAufTarif = false;

        $this->UpdateLademodusStatusAuto(
            $status,
            $ladeleistung,
            $pvUeberschussStandard,
            $batt,
            $hausakkuSOC,
            $hausakkuSOCVoll,
            $soc,
            $targetSOC,
            $wartenAufTarif
        );

        $this->UpdateWallboxStatusText();
        $this->UpdateFahrzeugStatusText();

    } catch (Throwable $e) {
        $this->Log("UpdateCharging(): Fehler – " . $e->getMessage(), 'error');
    } finally {
        $this->WriteAttributeBoolean('RunLock', false);
    }
}


// =====================================================================================================

public function ResetLock()
{
    $this->WriteAttributeBoolean('RunLock', false);
    $this->Log("ResetLock(): RunLock wurde manuell zurückgesetzt.", 'info');
}
    
// =====================================================================================================

public function UpdateMarketPrices()
{
    $provider = $this->ReadPropertyString('MarketPriceProvider');
    $url = $this->ReadPropertyString('MarketPriceAPI');

    // Standard-URLs basierend auf Provider setzen, falls leer
    if (empty($url)) {
        if ($provider === 'awattar_at') {
            $url = 'https://api.awattar.at/v1/marketdata';
        } elseif ($provider === 'awattar_de') {
            $url = 'https://api.awattar.de/v1/marketdata';
        } else {
            $this->Log("UpdateMarketPrices(): Kein gültiger Provider/URL angegeben!", 'error');
            return;
        }
    }

    $this->Log("UpdateMarketPrices(): Abruf von {$url}", 'debug');

    // Abruf mit Timeout (stream_context_create)
    $context = stream_context_create(['http' => ['timeout' => 10]]);
    $json = @file_get_contents($url, false, $context);

    if ($json === false) {
        $this->Log("UpdateMarketPrices(): Strompreisdaten konnten nicht geladen werden von {$url}!", 'error');
        return;
    }

    $data = json_decode($json, true);

    if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
        $this->Log("UpdateMarketPrices(): Fehler beim Parsen der Strompreisdaten!", 'error');
        return;
    }

    // Preise aufbereiten (nur nächste 36h)
    $preise = [];
    foreach ($data['data'] as $item) {
        // Datenstruktur prüfen
        if (isset($item['start_timestamp'], $item['end_timestamp'], $item['marketprice'])) {
            $preise[] = [
                'start' => intval($item['start_timestamp'] / 1000),
                'end'   => intval($item['end_timestamp'] / 1000),
                'price' => floatval($item['marketprice'] / 10.0)
            ];
        }
    }

    // Maximal 36h nach vorne schauen
    $jetzt = time();
    $preise36 = array_filter($preise, function($slot) use ($jetzt) {
        return $slot['end'] > $jetzt && $slot['start'] < ($jetzt + 36 * 3600);
    });

    // JSON für andere Funktionen speichern (z.B. Zielzeitladung)
    $jsonShort = json_encode(array_values($preise36));
    $varID = $this->GetIDForIdent('MarketPrices');
    if ($varID > 0) {
        SetValue($varID, $jsonShort);
    }

    // Vorschautext für WebFront (z.B. die nächsten 6 Preise)
    $vorschau = "";
    $count = 0;
    foreach ($preise36 as $p) {
        if ($count++ >= 6) break;
        $uhrzeit = date('d.m. H:i', $p['start']);
        $vorschau .= "{$uhrzeit}: " . number_format($p['price'], 2, ',', '.') . " ct/kWh\n";
    }
    $varIDText = $this->GetIDForIdent('MarketPricesText');
    if ($varIDText > 0) {
        SetValue($varIDText, $vorschau);
    }

    $this->Log("UpdateMarketPrices(): Strompreisdaten erfolgreich aktualisiert ({$count} Slots, Provider: {$provider})", 'info');
}

// =====================================================================================================

private function BerechnePVUeberschuss(float $haus, string $modus = 'standard'): float
{
    $goeID = $this->ReadPropertyInteger("GOEChargerID");

    // PV-Erzeugung auslesen (immer auf Watt normiert)
    $pv = 0;
    $pvID = $this->ReadPropertyInteger('PVErzeugungID');
    if ($pvID > 0 && @IPS_VariableExists($pvID)) {
        $pv = GetValue($pvID);
        if ($this->ReadPropertyString('PVErzeugungEinheit') === 'kW') {
            $pv *= 1000;
        }
    }

    $batt = $this->GetNormWert('BatterieladungID', 'BatterieladungEinheit', 'InvertBatterieladung', "Batterieladung");
    $netz = $this->GetNormWert('NetzeinspeisungID', 'NetzeinspeisungEinheit', 'InvertNetzeinspeisung', "Netzeinspeisung");

    $ladeleistung = ($goeID > 0) ? GOeCharger_GetPowerToCar($goeID) : 0;

    // === Modus-Weiche ===
    $logModus = "";
    $ueberschuss = 0;
    $abgezogen = 0;
    $pufferText = "Dynamischer Puffer ist deaktiviert. Kein Abzug.";

    if ($modus === 'pv2car') {
        $ueberschuss = $pv - $haus;
        $logModus = "PV2Car (Auto bekommt Anteil vom Überschuss, Rest Batterie)";

        // Prozent aus WebFront-Variable
        $prozentVarID = @$this->GetIDForIdent('PVAnteilAuto');
        $prozent = ($prozentVarID > 0) ? GetValue($prozentVarID) : 50; // Fallback 50%

        $anteilWatt = intval($ueberschuss * $prozent / 100);
        $minWatt = $this->ReadPropertyInteger('MinLadeWatt');
        $ladeSoll = 0;
        if ($anteilWatt > 0 && $anteilWatt >= $minWatt) {
            $ladeSoll = $anteilWatt;
        }

        $this->Log("PV2Car-Modus: Nutzer-Anteil = {$prozent}% → Ladeleistung für das Auto = {$anteilWatt} W (PV-Überschuss gesamt: {$ueberschuss} W, gesetzt: {$ladeSoll} W)", 'info');
        // Die tatsächliche Steuerung erfolgt jetzt in LogikPV2CarModus().
        // return $ladeSoll wäre auch möglich, wenn du explizit nur den Auto-Anteil brauchst.

    } else {
        $ueberschuss = $pv - $haus - max(0, $batt);
        $logModus = "Standard (Batterie hat Vorrang)";

        if ($this->ReadPropertyBoolean('DynamischerPufferAktiv')) {
            $pufferProzent = 1.0;
            if ($ueberschuss < 2000) $pufferProzent = 0.80;
            elseif ($ueberschuss < 4000) $pufferProzent = 0.85;
            elseif ($ueberschuss < 6000) $pufferProzent = 0.90;
            else $pufferProzent = 0.93;

            $alterUeberschuss = $ueberschuss;
            $ueberschuss *= $pufferProzent;
            $abgezogen = round($alterUeberschuss - $ueberschuss);
            $prozent = round((1 - $pufferProzent) * 100);

            $pufferText = "Dynamischer Puffer: Abzug {$abgezogen} W ({$prozent}%), Faktor: {$pufferProzent}";
        }
    }

    $ueberschuss = max(0, round($ueberschuss));

    $this->Log($pufferText, 'info');
    $this->Log("[{$logModus}] PV: {$pv} W | Haus: {$haus} W | Batterie: {$batt} W | Dyn.Puffer: {$abgezogen} W | → Überschuss: {$ueberschuss} W", 'info');

    if ($modus === 'standard') {
        $this->SetLogValue('PV_Ueberschuss', $ueberschuss);
    }

    return $ueberschuss;
}

// =====================================================================================================

private function LogikPVPureMitHysterese($modus = 'standard', $hausverbrauch = null)
{
    $this->Log("LogikPVPureMitHysterese() gestartet – Modus: {$modus}", 'debug');

    $modusTexte = [
        'pv2car'   => "PV2Car",
        'manuell'  => "Manueller Volllademodus",
        'zielzeit' => "Zielzeit-Laden",
        'standard' => "PV-Überschuss"
    ];
    $modusText = $modusTexte[$modus] ?? "Unbekannt";

    $minStart = $this->ReadPropertyInteger('MinLadeWatt');
    $minStop  = $this->ReadPropertyInteger('MinStopWatt');
    $goeID    = $this->ReadPropertyInteger('GOEChargerID');

    $ladeModusID = @IPS_GetObjectIDByIdent('accessStateV2', $goeID);
    $ladeModus   = ($ladeModusID !== false && @IPS_VariableExists($ladeModusID)) ? GetValueInteger($ladeModusID) : 0;

    // Überschuss berechnen
    if ($modus === 'manuell') {
        $ueberschuss = $this->GetMaxLadeleistung();
        $this->Log("Manueller Volllademodus aktiv – setze Ladeleistung auf {$ueberschuss} W", 'info');
    } else {
        if ($hausverbrauch === null) {
            $hausverbrauch = $this->BerechneHausverbrauch();
        }
        $ueberschuss = $this->BerechnePVUeberschuss($hausverbrauch, $modus);
    }

    // PV-Batterie-Prio im Standardmodus
    if ($modus === 'standard') {
        $hausakkuSOCID   = $this->ReadPropertyInteger('HausakkuSOCID');
        $hausakkuSOCVoll = $this->ReadPropertyInteger('HausakkuSOCVollSchwelle');
        $batt            = $this->GetNormWert('BatterieladungID', 'BatterieladungEinheit', 'InvertBatterieladung', "Batterieladung");
        $hausakkuSOC     = ($hausakkuSOCID > 0 && @IPS_VariableExists($hausakkuSOCID)) ? GetValue($hausakkuSOCID) : 100;

        if ($batt > 0 && $hausakkuSOC < $hausakkuSOCVoll) {
            $ueberschuss = 0;
            $this->SetLadeleistung(0);
            if (@IPS_InstanceExists($goeID)) {
                GOeCharger_setMode($goeID, 1);
                $this->Log("🔋 Hausakku lädt ({$batt} W), SoC: {$hausakkuSOC}% < {$hausakkuSOCVoll}% – Wallbox bleibt aus!", 'info');
            }
            $this->SetLademodusStatus("🔋 Hausakku lädt – Wallbox bleibt aus!");
        }
    }

    $startCounter = $this->ReadAttributeInteger('StartHystereseCounter');
    $stopCounter  = $this->ReadAttributeInteger('StopHystereseCounter');

    $this->Log("Hysterese-Check – Modus={$ladeModus}, Überschuss={$ueberschuss} W, Start-Schwelle={$minStart} W, Stop-Schwelle={$minStop} W", 'info');

    if ($ladeModus == 2) {
        // Wallbox lädt – Stop-Hysterese
        if ($ueberschuss <= $minStop) {
            $stopCounter++;
            $this->WriteAttributeInteger('StopHystereseCounter', $stopCounter);
            $this->Log("🛑 Stop-Hysterese: {$stopCounter} von " . ($this->ReadPropertyInteger('StopHysterese') + 1), 'debug');

            if ($stopCounter > $this->ReadPropertyInteger('StopHysterese')) {
                $this->SetLadeleistung(0);
                if (@IPS_InstanceExists($goeID)) {
                    GOeCharger_setMode($goeID, 1);
                    $this->Log("🔌 Wallbox-Modus auf 'Bereit' gestellt (1)", 'info');
                }
                $msg = "{$modusText}: Unter Stop-Schwelle ({$ueberschuss} W ≤ {$minStop} W) – Wallbox gestoppt";
                $this->Log($msg, 'info');
                $this->SetLademodusStatus($msg);
                $this->WriteAttributeInteger('StopHystereseCounter', 0);
                $this->WriteAttributeInteger('StartHystereseCounter', 0);
            }
        } else {
            if ($stopCounter > 0) $this->WriteAttributeInteger('StopHystereseCounter', 0);

            $this->SetLadeleistung($ueberschuss);
            if ($ueberschuss > 0 && @IPS_InstanceExists($goeID)) {
                GOeCharger_setMode($goeID, 2);
            }
            $msg = "{$modusText}: Bleibt an ({$ueberschuss} W)";
            $this->Log($msg, 'info');
            $this->SetLademodusStatus($msg);
        }
    } else {
        // Wallbox lädt nicht – Start-Hysterese
        if ($ueberschuss >= $minStart) {
            $startCounter++;
            $this->WriteAttributeInteger('StartHystereseCounter', $startCounter);
            $this->Log("🟢 Start-Hysterese: {$startCounter} von " . ($this->ReadPropertyInteger('StartHysterese') + 1), 'debug');

            if ($startCounter > $this->ReadPropertyInteger('StartHysterese')) {
                $this->SetLadeleistung($ueberschuss);
                if ($ueberschuss > 0 && @IPS_InstanceExists($goeID)) {
                    GOeCharger_setMode($goeID, 2);
                }
                $msg = "{$modusText}: Über Start-Schwelle ({$ueberschuss} W ≥ {$minStart} W) – Wallbox startet";
                $this->Log($msg, 'info');
                $this->SetLademodusStatus($msg);
                $this->WriteAttributeInteger('StartHystereseCounter', 0);
                $this->WriteAttributeInteger('StopHystereseCounter', 0);
            }
        } else {
            if ($startCounter > 0) $this->WriteAttributeInteger('StartHystereseCounter', 0);

            $this->SetLadeleistung(0);
            if (@IPS_InstanceExists($goeID)) {
                GOeCharger_setMode($goeID, 1);
            }
            $msg = "{$modusText}: Zu niedrig ({$ueberschuss} W) – bleibt aus";
            $this->Log($msg, 'info');
            $this->SetLademodusStatus($msg);
        }
    }

    if (!in_array($ladeModus, [1, 2])) {
        $this->Log("Unbekannter Wallbox-Modus: {$ladeModus}", 'warn');
    }
}

// =====================================================================================================

private function LogikZielzeitladung($hausverbrauch = null)
{
    // Annahme: TargetTime ist die Anzahl Sekunden seit Mitternacht (0 - 86399)
    $targetOffset = GetValue($this->GetIDForIdent('TargetTime'));
    $targetOffset = max(0, min($targetOffset, 86399)); // Begrenzen

    // Aktueller Tag, lokale Zeitzone
    $today = new DateTime('today', new DateTimeZone('Europe/Vienna'));
    $targetTime = clone $today;
    $targetTime->modify("+$targetOffset seconds");

    // Falls Zielzeit schon vorbei ist, auf morgen legen
    $nowDateTime = new DateTime('now', new DateTimeZone('Europe/Vienna'));
    if ($targetTime < $nowDateTime) {
        $targetTime->modify('+1 day');
    }

    $this->Log("Zielzeitladung: Zielzeit lokal = " . $targetTime->format('d.m.Y H:i:s'), 'debug');
    $this->Log("Zielzeitladung: Uhrzeit eingestellt auf " . $targetTime->format('H:i'), 'debug');

    // Ladebedarf berechnen
    $soc = $this->ReadPropertyFloat('CarSOCFallback');
    $targetSOC = $this->ReadPropertyFloat('CarTargetSOCFallback');
    $socID = $this->ReadPropertyInteger('CarSOCID');
    $targetSOCID = $this->ReadPropertyInteger('CarTargetSOCID');
    if ($socID > 0 && @IPS_VariableExists($socID)) $soc = GetValue($socID);
    if ($targetSOCID > 0 && @IPS_VariableExists($targetSOCID)) $targetSOC = GetValue($targetSOCID);

    $capacity = $this->ReadPropertyFloat('CarBatteryCapacity');
    $fehlendeProzent = max(0, $targetSOC - $soc);
    $fehlendeKWh = $capacity * $fehlendeProzent / 100.0;
    $maxWatt = $this->GetMaxLadeleistung();
    $ladezeitStunden = ceil($fehlendeKWh / ($maxWatt / 1000));

    $this->Log("Zielzeitladung: SOC={$soc}%, Ziel={$targetSOC}%, Fehlend={$fehlendeProzent}% → ca. {$fehlendeKWh} kWh → Ladezeit ca. {$ladezeitStunden} h", 'info');

    // Preisdaten prüfen
    $preise = json_decode(GetValue($this->GetIDForIdent('MarketPrices')), true);
    if (!is_array($preise) || count($preise) < 1) {
        $this->Log("Zielzeitladung: Keine gültigen Strompreisdaten gefunden!", 'warn');
        $this->SetLadeleistung(0);
        $this->SetLademodusStatus("Keine Strompreisdaten – kein Laden möglich!");
        return;
    }

    // Relevante Slots bis Zielzeit filtern
    $now = time();
    $slots = array_values(array_filter($preise, fn($slot) => $slot['end'] > $now && $slot['start'] < $targetTime->getTimestamp()));

    // DEBUGBLOCK
    $this->Log("Debug: LadezeitStunden = {$ladezeitStunden}, gefundene Preisslots = " . count($slots), 'debug');
    if (count($preise)) {
        $lastSlotEnd = end($preise)['end'];
        $this->Log("Letzter Preisslot endet am " . date('d.m. H:i', $lastSlotEnd), 'debug');
    }
    foreach ($slots as $s) {
        $this->Log("Slot: " . date('d.m. H:i', $s['start']) . " - " . date('H:i', $s['end']) . " Preis: " . $s['price'], 'debug');
    }
    // DEBUGBLOCK Ende

    if (count($slots) < $ladezeitStunden) {
        $this->Log("Zielzeitladung: Nicht genug Preisslots im Zeitraum – Abbruch", 'warn');
        $this->SetLadeleistung(0);
        $this->SetLademodusStatus("Zu wenig Preisslots – kein Laden möglich!");
        return;
    }

    // Günstigste zusammenhängende Slots finden
    $minSum = null;
    $minIndex = 0;
    for ($i = 0; $i <= count($slots) - $ladezeitStunden; $i++) {
        $sum = array_sum(array_column(array_slice($slots, $i, $ladezeitStunden), 'price'));
        if ($minSum === null || $sum < $minSum) {
            $minSum = $sum;
            $minIndex = $i;
        }
    }
    $ladeSlots = array_slice($slots, $minIndex, $ladezeitStunden);

    // Ladeplan loggen
    $ladeplan = implode(" | ", array_map(function ($s) {
        return date('H:i', $s['start']) . "-" . date('H:i', $s['end']) . ": " . number_format($s['price'], 2, ',', '.') . " ct";
    }, $ladeSlots));
    $this->Log("Zielzeit-Ladeplan (günstigste {$ladezeitStunden} Stunden): {$ladeplan}", 'info');

    // Prüfen, ob aktuell im geplanten Slot
    $ladeJetzt = array_filter($ladeSlots, fn($s) => $now >= $s['start'] && $now < $s['end']);

    if ($ladeJetzt) {
        $preis = number_format($ladeSlots[0]['price'], 2, ',', '.');
        $msg = "Zielzeitladung: Im Preisslot ({$preis} ct/kWh) – volle Leistung {$maxWatt} W";
        $this->SetLadeleistung($maxWatt);
        $this->SetLademodusStatus($msg);
        $this->Log($msg, 'info');
    } else {
        // HIER: Hysterese für den PV-Überschuss als Fallback (statt direkter Überschussladung)
        $this->Log("Zielzeitladung: Kein Preisslot aktiv – PV-Überschuss mit Hysterese als Fallback.", 'info');
        $this->LogikPVPureMitHysterese('zielzeit', $hausverbrauch);
        // Logging etc. wird zentral in der Hysterese-Funktion behandelt.
    }
}
    
// =====================================================================================================

private function LogikManuellVollladen()
{
    $goeID = $this->ReadPropertyInteger('GOEChargerID');

    // Instanzprüfung
    if ($goeID <= 0 || !@IPS_InstanceExists($goeID)) {
        $this->Log("Manuell Vollladen: GO-e Instanz nicht gefunden (ID={$goeID})!", 'error');
        $this->SetLademodusStatus("❌ Fehler: GO-e Instanz nicht gefunden!");
        return;
    }

    // 3-phasig schalten (falls Funktion vorhanden)
    if (function_exists('GOeCharger_SetSinglePhaseCharging')) {
        GOeCharger_SetSinglePhaseCharging($goeID, false); // false = 3-phasig
        $this->Log("Manuell Vollladen: 3-phasiges Laden aktiviert.", 'info');
    } else {
        $this->Log("GOeCharger_SetSinglePhaseCharging nicht verfügbar!", 'warn');
    }

    // Maximale Ladeleistung setzen
    $maxWatt = $this->GetMaxLadeleistung();
    $this->SetLadeleistung($maxWatt);

    // Immer-Laden-Modus setzen
    if (function_exists('GOeCharger_SetMode')) {
        GOeCharger_SetMode($goeID, 2); // 2 = Laden
        $this->Log("Manuell Vollladen: Wallbox-Modus auf 'Laden' gesetzt.", 'debug');
    } else {
        $this->Log("GOeCharger_SetMode nicht verfügbar!", 'warn');
    }

    $this->SetLademodusStatus("🔌 Manueller Volllademodus aktiv: 3-phasig, {$maxWatt} W");
    $this->Log("UpdateCharging(): Manueller Volllademodus aktiv: 3-phasig, {$maxWatt} W", 'info');
}

// =====================================================================================================

private function LogikPV2CarModus()
{
    // 1. Modus wirklich aktiv? (Variable PVVerteilenAktiv)
    if (!GetValue($this->GetIDForIdent('PVVerteilenAktiv'))) {
        $this->Log("PV2Car: Modus nicht aktiviert (PVVerteilenAktiv = false)", 'info');
        return;
    }

    // 2. SoC-Variable vom Auto vorhanden?
    $carSOCID = $this->ReadPropertyInteger('CarSOCID');
    if (!$carSOCID || !IPS_VariableExists($carSOCID)) {
        $this->Log("PV2Car: Kein SoC-Wert vom Auto – Modus gestoppt.", 'warn');
        $this->SetLadeleistung(0);
        $this->SetLademodusStatus("PV2Car-Modus nicht möglich – kein SoC vom Auto vorhanden!");
        return;
    }

    // 3. Ziel-SoC vom Auto vorhanden?
    $carTargetSOCID = $this->ReadPropertyInteger('CarTargetSOCID');
    $carTargetSOC = ($carTargetSOCID > 0 && IPS_VariableExists($carTargetSOCID)) ? GetValue($carTargetSOCID) : 100;
    $carSOC = GetValue($carSOCID);

    // 4. Hausakku-Variable vorhanden?
    $hausakkuSOCID = $this->ReadPropertyInteger('HausakkuSOCID');
    if (!$hausakkuSOCID || !IPS_VariableExists($hausakkuSOCID)) {
        $this->Log("PV2Car: Keine PV-Batterie definiert – Modus nicht verfügbar!", 'warn');
        $this->SetLadeleistung(0);
        $this->SetLademodusStatus("PV2Car-Modus nicht möglich – keine PV-Batterie.");
        return;
    }
    $hausakkuSOC = GetValue($hausakkuSOCID);
    $hausakkuSOCVollSchwelle = $this->ReadPropertyInteger('HausakkuSOCVollSchwelle');

    // 5. PV-Überschuss berechnen (PV-Erzeugung - Hausverbrauch - Wallbox)
    $hausverbrauch = GetValue($this->ReadPropertyInteger('HausverbrauchID')); // Hausverbrauch holen
    $pvUeberschuss = $this->BerechnePVUeberschuss($hausverbrauch, 'pv2car'); // Berechnung des Überschusses im PV2Car-Modus

    // 6. Dynamischen Puffer zentral berücksichtigen
    if ($this->ReadPropertyBoolean('DynamischerPufferAktiv')) {
        $pufferWatt = $this->BerechneDynamischenPuffer($pvUeberschuss);
        $pvUeberschuss -= $pufferWatt;
        $this->Log("PV2Car: Dynamischer Puffer abgezogen: {$pufferWatt} W → Restüberschuss: {$pvUeberschuss} W", 'debug');
    }

    // 7. Anteil für Auto bestimmen (Variable!)
    $anteil = GetValue($this->GetIDForIdent('PVAnteilAuto')) / 100.0;
    $anteilProzent = $anteil * 100;
    $this->Log("PV2Car: Anteil für Auto: {$anteil} (d.h. {$anteilProzent}% des Überschusses)", 'debug');

    // 8. Hysterese Counter laden (zentral!)
    $startCounter = $this->ReadAttributeInteger('StartHystereseCounter');
    $stopCounter  = $this->ReadAttributeInteger('StopHystereseCounter');
    $minStart = $this->ReadPropertyInteger('MinLadeWatt');
    $minStop  = $this->ReadPropertyInteger('MinStopWatt');
    $goeID = $this->ReadPropertyInteger('GOEChargerID');

    // 9. Umschaltlogik: SoC/Schwellen prüfen
    $akkuVoll = ($hausakkuSOC >= $hausakkuSOCVollSchwelle);
    $autoVoll = ($carSOC >= $carTargetSOC);

    // 10. Berechne Ziel-Ladeleistung
    $ladeleistung = 0;
    $msg = "";
    if ($akkuVoll && !$autoVoll) {
        $ladeleistung = $pvUeberschuss;
        $msg = "PV2Car: Hausakku voll – 100% PV-Überschuss ins Auto ({$ladeleistung} W)";
    } elseif ($autoVoll && !$akkuVoll) {
        $ladeleistung = 0;
        $msg = "PV2Car: Auto voll – 100% PV-Überschuss in Hausakku.";
    } elseif ($akkuVoll && $autoVoll) {
        $ladeleistung = 0;
        $msg = "PV2Car: Auto und Hausakku voll – Überschuss wird eingespeist.";
    } else {
        $ladeleistung = round($pvUeberschuss * $anteil);
        $msg = "PV2Car: Anteil fürs Auto: {$ladeleistung} W ({$anteilProzent}% von {$pvUeberschuss} W, inkl. Puffer)";
    }

    // Log für die berechnete Ladeleistung hinzufügen
    $this->Log("PV2Car: Berechneter Überschuss: {$pvUeberschuss} W, Ladeleistung für das Auto: {$ladeleistung} W", 'debug'); // Log hier hinzufügen

    // 11. Hysterese-Umschaltung wie Standardmodus (zentraler Counter!)
    $ladeModusID = @IPS_GetObjectIDByIdent('accessStateV2', $goeID);
    $ladeModus = ($ladeModusID !== false && @IPS_VariableExists($ladeModusID)) ? GetValueInteger($ladeModusID) : 0;

    $this->Log("Hysterese-Check (PV2Car): Modus={$ladeModus}, Ladeleistung={$ladeleistung} W, Start-Schwelle={$minStart} W, Stop-Schwelle={$minStop} W", 'info');

    if ($ladeModus == 2) { // Wallbox lädt
        if ($ladeleistung <= $minStop) {
            $stopCounter++;
            $this->WriteAttributeInteger('StopHystereseCounter', $stopCounter);
            $this->Log("🛑 PV2Car Stop-Hysterese: {$stopCounter} von " . ($this->ReadPropertyInteger('StopHysterese') + 1), 'debug');
            if ($stopCounter > $this->ReadPropertyInteger('StopHysterese')) {
                $this->SetLadeleistung(0);
                if (@IPS_InstanceExists($goeID)) {
                    GOeCharger_setMode($goeID, 1);
                }
                $msg .= " – Unter Stop-Schwelle ({$ladeleistung} W ≤ {$minStop} W) – Wallbox gestoppt";
                $this->WriteAttributeInteger('StopHystereseCounter', 0);
                $this->WriteAttributeInteger('StartHystereseCounter', 0);
            }
        } else {
            if ($stopCounter > 0) $this->WriteAttributeInteger('StopHystereseCounter', 0);
            $this->SetLadeleistung($ladeleistung);
            if ($ladeleistung > 0 && @IPS_InstanceExists($goeID)) {
                GOeCharger_setMode($goeID, 2);
            }
            $msg .= " – Bleibt an ({$ladeleistung} W)";
        }
    } else { // Wallbox lädt NICHT
        if ($ladeleistung >= $minStart) {
            $startCounter++;
            $this->WriteAttributeInteger('StartHystereseCounter', $startCounter);
            $this->Log("🟢 PV2Car Start-Hysterese: {$startCounter} von " . ($this->ReadPropertyInteger('StartHysterese') + 1), 'debug');
            if ($startCounter > $this->ReadPropertyInteger('StartHysterese')) {
                $this->SetLadeleistung($ladeleistung);
                if ($ladeleistung > 0 && @IPS_InstanceExists($goeID)) {
                    GOeCharger_setMode($goeID, 2);
                }
                $msg .= " – Über Start-Schwelle ({$ladeleistung} W ≥ {$minStart} W) – Wallbox startet";
                $this->WriteAttributeInteger('StartHystereseCounter', 0);
                $this->WriteAttributeInteger('StopHystereseCounter', 0);
            }
        } else {
            if ($startCounter > 0) $this->WriteAttributeInteger('StartHystereseCounter', 0);
            $this->SetLadeleistung(0);
            if (@IPS_InstanceExists($goeID)) {
                GOeCharger_setMode($goeID, 1);
            }
            $msg .= " – Zu niedrig ({$ladeleistung} W) – bleibt aus";
        }
    }

    $this->SetLademodusStatus($msg);
    $this->Log($msg, 'info');
}

// =====================================================================================================

private function GetMaxLadeleistung(): int
{
    $hardLimit = $this->ReadPropertyInteger('MaxAutoWatt');
    if ($hardLimit > 0) {
        $this->Log("GetMaxLadeleistung(): Nutze konfiguriertes Limit {$hardLimit} W", 'debug');
        return (int)$hardLimit;
    }

    // Plausibilitätsgrenzen
    $phasen = (int)$this->ReadPropertyInteger('Phasen');
    $maxAmp = (int)$this->ReadPropertyInteger('MaxAmpere');

    // Phasen auf 1-3 begrenzen
    if ($phasen < 1) $phasen = 1;
    if ($phasen > 3) $phasen = 3;

    // Ampere auf 6-16 (oder 6-32) begrenzen
    if ($maxAmp < 6) $maxAmp = 6;
    if ($maxAmp > 16) $maxAmp = 16; // auf 32 erhöhen, falls du willst

    $maxWatt = $phasen * 230 * $maxAmp;

    $this->Log("GetMaxLadeleistung(): Berechnet {$phasen} Phasen x {$maxAmp} A = {$maxWatt} W (nach Plausibilitätsprüfung)", 'debug');
    return (int)$maxWatt;
}

// =====================================================================================================
    
private function SetLadeleistung(int $watt)
{
    $goeID = $this->ReadPropertyInteger('GOEChargerID');
    if (!@IPS_InstanceExists($goeID)) {
        $this->Log("⚠️ go-e Charger Instanz nicht gefunden (ID: $goeID)", 'warn');
        return;
    }

    // Negative oder nicht-integer Werte vermeiden
    $watt = max(0, intval(round($watt)));

    // Obergrenze Fahrzeuglimit
    $maxAutoWatt = $this->ReadPropertyInteger('MaxAutoWatt');
    if ($maxAutoWatt > 0 && $watt > $maxAutoWatt) {
        $this->Log("⚠️ Ladeleistung auf Fahrzeuglimit reduziert ({$watt} W → {$maxAutoWatt} W)", 'debug');
        $watt = $maxAutoWatt;
    }

    // Mindestladeleistung berücksichtigen (nur wenn > 0)
    $minWatt = $this->ReadPropertyInteger('MinLadeWatt');
    if ($watt > 0 && $watt < $minWatt) {
        $this->Log("⚠️ Angeforderte Ladeleistung zu niedrig ({$watt} W), setze auf Mindestwert {$minWatt} W", 'debug');
        $watt = $minWatt;
    }

    // Phasenumschaltung nur bei > 0 W prüfen
    if ($watt > 0) {
        $phaseVarID = @IPS_GetObjectIDByIdent('SinglePhaseCharging', $goeID);
        $aktuell1phasig = ($phaseVarID !== false && @IPS_VariableExists($phaseVarID)) ? GetValueBoolean($phaseVarID) : false;

        // Auf 1-phasig schalten
        if ($watt < $this->ReadPropertyInteger('Phasen1Schwelle') && !$aktuell1phasig) {
            $counter = $this->ReadAttributeInteger('Phasen1Counter') + 1;
            $this->WriteAttributeInteger('Phasen1Counter', $counter);
            $this->WriteAttributeInteger('Phasen3Counter', 0);
            $this->Log("⏬ 1-phasig Zähler: {$counter}/{$this->ReadPropertyInteger('Phasen1Limit')}", 'debug');

            if ($counter >= $this->ReadPropertyInteger('Phasen1Limit')) {
                GOeCharger_SetSinglePhaseCharging($goeID, true);
                $this->Log("🔁 Umschaltung auf 1-phasig ausgelöst", 'info');
                $this->WriteAttributeInteger('Phasen1Counter', 0);
            }
        }
        // Auf 3-phasig schalten
        elseif ($watt > $this->ReadPropertyInteger('Phasen3Schwelle') && $aktuell1phasig) {
            $counter = $this->ReadAttributeInteger('Phasen3Counter') + 1;
            $this->WriteAttributeInteger('Phasen3Counter', $counter);
            $this->WriteAttributeInteger('Phasen1Counter', 0);
            $this->Log("⏫ 3-phasig Zähler: {$counter}/{$this->ReadPropertyInteger('Phasen3Limit')}", 'debug');

            if ($counter >= $this->ReadPropertyInteger('Phasen3Limit')) {
                GOeCharger_SetSinglePhaseCharging($goeID, false);
                $this->Log("🔁 Umschaltung auf 3-phasig ausgelöst", 'info');
                $this->WriteAttributeInteger('Phasen3Counter', 0);
            }
        } else {
            // Zähler zurücksetzen, wenn Schwellen nicht erreicht
            $this->WriteAttributeInteger('Phasen1Counter', 0);
            $this->WriteAttributeInteger('Phasen3Counter', 0);
        }
    } else {
        // Bei 0 W: Beide Zähler zurücksetzen
        $this->WriteAttributeInteger('Phasen1Counter', 0);
        $this->WriteAttributeInteger('Phasen3Counter', 0);
    }

    // Aktuelle Werte abfragen
    $modusID = @IPS_GetObjectIDByIdent('accessStateV2', $goeID);
    $wattID = @IPS_GetObjectIDByIdent('Watt', $goeID);
    $aktuellerModus = ($modusID !== false && @IPS_VariableExists($modusID)) ? GetValueInteger($modusID) : -1;
    $aktuelleLeistung = ($wattID !== false && @IPS_VariableExists($wattID)) ? GetValueFloat($wattID) : -1;

    // Ladeleistung nur setzen wenn deutlich geändert
    if ($aktuelleLeistung < 0 || abs($aktuelleLeistung - $watt) > 50) {
        GOeCharger_SetCurrentChargingWatt($goeID, $watt);
        $this->Log("✅ Ladeleistung gesetzt: {$watt} W", 'info');

        // Modus setzen (2 = Laden, 1 = Bereitschaft)
        if ($watt > 0 && $aktuellerModus != 2) {
            GOeCharger_setMode($goeID, 2);
            $this->Log("⚡ Modus auf 'Laden' gestellt (2)", 'debug');
        }
        if ($watt == 0 && $aktuellerModus != 1) {
            GOeCharger_setMode($goeID, 1);
            $this->Log("🔌 Modus auf 'Bereit' gestellt (1)", 'debug');
        }

        // Hinweis, falls Ladung nicht automatisch startet
        $status = GOeCharger_GetStatus($goeID);
        if ($watt > 0 && $aktuellerModus == 1 && in_array($status, [3, 4])) {
            $msg = "⚠️ Ladeleistung gesetzt, aber Ladung startet nicht automatisch.<br>Bitte Fahrzeug neu anstecken.";
            $this->SetLademodusStatus($msg);
            $this->Log($msg, 'warn');
        }
    } else {
        $this->Log("🟡 Ladeleistung unverändert – keine Änderung notwendig", 'debug');
    }
}

// =====================================================================================================

private function SetFahrzeugStatus(string $text, bool $log = false)
{
    $this->SetLogValue('FahrzeugStatusText', $text);

    if ($log) {
        $this->Log("🚗 FahrzeugStatus: {$text}", 'info');
    }
}

// =====================================================================================================

private function SetLademodusStatus(string $text, bool $log = false)
{
    $this->SetLogValue('LademodusStatus', $text);

    if ($log) {
        $this->Log("⚡ LademodusStatus: {$text}", 'info');
    }
}

// =====================================================================================================

private function GetNormWert(string $idProp, string $einheitProp, string $invertProp, string $name = ""): float
{
    $wert = 0;
    $vid = $this->ReadPropertyInteger($idProp);

    if ($vid > 0 && @IPS_VariableExists($vid)) {
        $wert = GetValue($vid);

        if ($this->ReadPropertyBoolean($invertProp)) {
            $wert *= -1;
        }

        if ($this->ReadPropertyString($einheitProp) === "kW") {
            $wert *= 1000;
        }
    } elseif ($name !== "") {
        $this->Log("Hinweis: Keine {$name}-Variable gewählt, Wert wird als 0 angesetzt.", 'debug');
    }

    return $wert;
}

// =====================================================================================================

private function UpdateWallboxStatusText()
{
    $goeID = $this->ReadPropertyInteger('GOEChargerID');

    if ($goeID === 0) {
        $text = '<span style="color:gray;">Keine GO-e Instanz gewählt</span>';
    } else {
        $status = GOeCharger_GetStatus($goeID);

        switch ($status) {
            case 1:
                $text = '<span style="color: gray;">Ladestation bereit, kein Fahrzeug</span>';
                break;
            case 2:
                $text = '<span style="color: green; font-weight:bold;">Fahrzeug lädt</span>';
                break;
            case 3:
                $text = '<span style="color: orange;">Fahrzeug angeschlossen, wartet auf Ladefreigabe</span>';
                break;
            case 4:
                $text = '<span style="color: blue;">Ladung beendet, Fahrzeug verbunden</span>';
                break;
            default:
                $text = '<span style="color: red;">Unbekannter Status</span>';
                $this->Log("Unbekannter Status vom GO-e Charger: {$status}", 'warn');
                break;
        }
    }

    $this->SetLogValue('WallboxStatusText', $text);
}

// =====================================================================================================

private function UpdateFahrzeugStatusText()
{
    $goeID = $this->ReadPropertyInteger('GOEChargerID');

    if ($goeID === 0 || !@IPS_InstanceExists($goeID)) {
        $this->SetFahrzeugStatus('⚠️ Keine GO-e Instanz gewählt.');
        $this->Log("UpdateFahrzeugStatusText: Keine gültige GO-e Instanz gewählt.", 'warn');
        return;
    }

    $status = GOeCharger_GetStatus($goeID);
    // Modus-Logik mit Default auf Standard
    if (GetValue($this->GetIDForIdent('ManuellVollladen'))) {
        $modus = 'Manueller Volllademodus';
    } elseif (GetValue($this->GetIDForIdent('PV2CarModus'))) {
        $modus = 'PV2Car';
    } elseif (GetValue($this->GetIDForIdent('ZielzeitladungModus'))) {
        $modus = 'Zielzeitladung';
    } else {
        $modus = 'PV-Überschuss (Standard)';
    }

    $statusText = "";
    switch ($status) {
        case 2:
            $statusText = "⚡️ Fahrzeug lädt – Modus: $modus";
            break;
        case 3:
            $statusText = "🚗 Fahrzeug angeschlossen, wartet auf Freigabe (Modus: $modus)";
            break;
        case 4:
            $statusText = "🔋 Modus aktiv: $modus – aber Ladung beendet.";
            break;
        case 1:
        default:
            $statusText = "⚠️ Kein Fahrzeug verbunden.";
            break;
    }

    $this->SetFahrzeugStatus($statusText);
    $this->Log("UpdateFahrzeugStatusText: GO-e Status={$status}, Modus='{$modus}', Statustext='$statusText'", 'debug');
}

// =====================================================================================================

private function BerechneHausverbrauch()
{
    $hausverbrauchID      = $this->ReadPropertyInteger('HausverbrauchID');
    $hausverbrauchEinheit = $this->ReadPropertyString('HausverbrauchEinheit');
    $invertHausverbrauch  = $this->ReadPropertyBoolean('InvertHausverbrauch');
    $goeID                = $this->ReadPropertyInteger('GOEChargerID');

    if ($hausverbrauchID == 0 || !@IPS_VariableExists($hausverbrauchID)) {
        $this->Log("Hausverbrauch konnte nicht berechnet werden – keine gültige Variable konfiguriert!", 'warn');
        return 0;
    }

    $gesamtverbrauch = GetValue($hausverbrauchID);

    // Einheit umrechnen
    if ($hausverbrauchEinheit === 'kW') {
        $gesamtverbrauch *= 1000;
    }

    // Invertieren falls gewünscht
    if ($invertHausverbrauch) {
        $gesamtverbrauch *= -1;
    }

    // Wallbox-Leistung abrufen
    $wallboxLeistung = 0;
    if ($goeID > 0 && @IPS_InstanceExists($goeID)) {
        $wallboxLeistung = @GOeCharger_GetPowerToCar($goeID);
        if ($wallboxLeistung === false) $wallboxLeistung = 0;
    }

    $hausverbrauch = $gesamtverbrauch - $wallboxLeistung;
    if ($hausverbrauch < 0) $hausverbrauch = 0;

    $this->SendDebug('Hausverbrauch', "Gesamt: {$gesamtverbrauch} W - Wallbox: {$wallboxLeistung} W = {$hausverbrauch} W", 0);
    $this->Log("Berechneter Hausverbrauch: {$hausverbrauch} W (inkl. Wallboxabzug)", 'debug');

    if (@$this->GetIDForIdent('Hausverbrauch') > 0) {
        SetValue($this->GetIDForIdent('Hausverbrauch'), $hausverbrauch);
    }

    return $hausverbrauch;
}
    
// =====================================================================================================

private function BerechneDynamischenPuffer(float $pvUeberschuss): float
{
    // Hier kannst du deine Logik für den dynamischen Puffer anpassen
    // Beispiel für eine einfache Anpassung des Puffers basierend auf dem Überschuss:
    
    $pufferWatt = 0;

    // Wenn der Überschuss unter einem bestimmten Wert liegt, setze den Puffer auf einen niedrigen Wert
    if ($pvUeberschuss < 2000) {
        $pufferWatt = $pvUeberschuss * 0.20; // 20% des Überschusses für den Puffer
    } elseif ($pvUeberschuss < 4000) {
        $pufferWatt = $pvUeberschuss * 0.30; // 30% des Überschusses für den Puffer
    } elseif ($pvUeberschuss < 6000) {
        $pufferWatt = $pvUeberschuss * 0.50; // 50% des Überschusses für den Puffer
    } else {
        $pufferWatt = $pvUeberschuss * 0.60; // 60% des Überschusses für den Puffer
    }

    // Optional: Loggen des berechneten Puffers für Debugging-Zwecke
    $this->Log("Berechneter dynamischer Puffer: {$pufferWatt} W", 'debug');

    return $pufferWatt;
}

// =====================================================================================================


private function Log(string $message, string $level)
{
    $prefix = "PVWM";
    $normalized = strtolower(trim($level));

    // Sicherstellen, dass keine leeren Nachrichten geloggt werden
    if (trim($message) === '') return;

    // Überprüfung der Debug-Option
    $debugAktiv = false;
    if (method_exists($this, 'ReadPropertyBoolean')) {
        try {
            $debugAktiv = $this->ReadPropertyBoolean('DebugLogging');
        } catch (Throwable $e) {
            $debugAktiv = false;
        }
    }

    // Loggen je nach Log-Level
    switch ($normalized) {
        case 'debug':
            if ($debugAktiv) {
                IPS_LogMessage("{$prefix} [DEBUG]", $message);
                $this->SendDebug("DEBUG", $message, 0);
            }
            break;
        case 'info':
            IPS_LogMessage("{$prefix} [INFO]", $message);
            break;
        case 'warn':
        case 'warning':
            IPS_LogMessage("{$prefix} [WARN]", $message);
            break;
        case 'error':
            IPS_LogMessage("{$prefix} [ERROR]", $message);
            break;
        default:
            // Default-Fall für unbekannte Log-Level: Info
            IPS_LogMessage("{$prefix} [INFO]", $message);
            break;
    }
}

// =====================================================================================================
    
private function SetLogValue($ident, $value)
{
    $varID = $this->GetIDForIdent($ident);

    if ($varID !== false && @IPS_VariableExists($varID)) {
        $alt = GetValue($varID);

        // Verhindern, dass derselbe Wert mehrfach gesetzt wird
        if (trim((string)$alt) !== trim((string)$value)) {
            SetValue($varID, $value);

            // Loggen des neuen Wertes mit einer Kurzversion bei langen Strings
            $short = is_string($value) ? mb_strimwidth($value, 0, 100, "...") : $value;
            $this->Log("[$ident] geändert: $short", 'debug');
        }
    } else {
        $this->Log("SetLogValue: Variable '$ident' existiert nicht!", 'warn');
    }
}
    
// =====================================================================================================

//Legt ein Ereignis an, das bei Status-Änderung der Wallbox (Status > 1) sofort UpdateCharging() auslöst.
private function CreateStatusEvent($goeID)
{
    if ($goeID <= 0 || !@IPS_InstanceExists($goeID)) {
        $this->Log("CreateStatusEvent: Ungültige oder fehlende GO-e Instanz ($goeID) – Vorgang abgebrochen.", 'warn');
        return;
    }

    $statusIdent = 'status'; 
    $statusVarID = @IPS_GetObjectIDByIdent($statusIdent, $goeID);

    if ($statusVarID === false) {
        $this->Log("CreateStatusEvent: Keine Status-Variable ($statusIdent) in GO-e Instanz ($goeID) gefunden – Sofort-Trigger nicht angelegt!", 'warn');
        return;
    }

    $eventIdent = 'Trigger_UpdateCharging_OnStatusChange';
    $eventID = @IPS_GetObjectIDByIdent($eventIdent, $this->InstanceID);

    // Prüfen, ob das Ereignis bereits existiert
    if ($eventID === false) {
        // Neues Ereignis erstellen
        $eventID = IPS_CreateEvent(0); // 0 = Trigger bei Wertänderung
        IPS_SetParent($eventID, $this->InstanceID);
        IPS_SetIdent($eventID, $eventIdent);
        IPS_SetName($eventID, "Trigger: UpdateCharging bei Fahrzeugstatus > 1");
        IPS_SetEventTrigger($eventID, 1, $statusVarID); // Wertänderung triggern
        IPS_SetEventActive($eventID, true);

        // Code für das Event, das die Funktion UpdateCharging bei Statusänderung aufruft
        $code = 'if ($_IPS["VALUE"] > 1) { IPS_RequestAction(' . $this->InstanceID . ', "UpdateCharging", true); }';
        IPS_SetEventScript($eventID, $code);

        $this->Log("Ereignis zum sofortigen Update bei Statuswechsel wurde neu erstellt. (Event-ID: {$eventID})", 'info');
    } else {
        // Existierendes Ereignis anpassen
        if (@IPS_GetEvent($eventID)['TriggerVariableID'] != $statusVarID) {
            IPS_SetEventTrigger($eventID, 1, $statusVarID);
            $this->Log("Trigger-Variable im Ereignis aktualisiert. (Event-ID: {$eventID})", 'debug');
        }
        IPS_SetEventActive($eventID, true);
        $this->Log("Ereignis zum sofortigen Update geprüft und reaktiviert. (Event-ID: {$eventID})", 'debug');
    }
}

// =====================================================================================================

// Löscht das Ereignis für Statuswechsel, falls vorhanden.
private function RemoveStatusEvent()
{
    $eventIdent = 'Trigger_UpdateCharging_OnStatusChange';
    $eventID = @IPS_GetObjectIDByIdent($eventIdent, $this->InstanceID);

    if ($eventID !== false && @IPS_EventExists($eventID)) {
        // Löschen des Ereignisses
        IPS_DeleteEvent($eventID);
        $this->Log("Ereignis zum sofortigen Update bei Statuswechsel (Event-ID: {$eventID}) wurde entfernt.", 'debug');
    } else {
        $this->Log("RemoveStatusEvent: Kein bestehendes Ereignis (Event-ID: {$eventID}) gefunden – nichts zu tun.", 'debug');
    }
}
    
// =====================================================================================================

private function SetLademodusStatusByReason($grund = '')
{
    $grund = trim(strtolower($grund));

    switch ($grund) {
        case 'no_vehicle':
            $text = '🅿️ Kein Fahrzeug verbunden';
            break;
        case 'pv_too_low':
            $text = '🌥️ Kein PV-Überschuss – wartet auf Sonne';
            break;
        case 'waiting_tariff':
            $text = '⏳ Wartet auf günstigen Stromtarif';
            break;
        case 'battery_charging':
            $text = '🔋 Hausakku lädt – Wallbox pausiert';
            break;
        case 'soc_reached':
            $text = '✅ Ziel-SOC erreicht – keine weitere Ladung';
            break;
        case 'manual_pause':
            $text = '⏸️ Manuell pausiert';
            break;
        case 'active':
            $text = '⚡️ Ladung aktiv';
            break;
        case 'pv_surplus':
            $text = '🌞 PV-Überschuss: Ladung läuft';
            break;
        case 'no_ladefreigabe': // Beispiel für einen schöneren Ausdruck
            $text = '⏸️ Ladefreigabe fehlt – Wallbox auf „Bereit“';
            break;
        default:
            $text = '⏸️ Keine Ladung aktiv';
            $this->Log("SetLademodusStatusByReason: Unbekannter Grund '{$grund}' – Standardstatus gesetzt.", 'warn');
    }

    $this->SetLogValue('LademodusStatus', $text);
}

// =====================================================================================================

// Umbau: Nur noch zentrale Statussteuerung über UpdateLademodusStatusAuto
private function UpdateLademodusStatusAuto($status, $ladeleistung, $pvUeberschuss, $batt, $hausakkuSOC, $hausakkuSOCVoll, $soc, $targetSOC, $wartenAufTarif = false)
{
    $ladeleistung = floatval($ladeleistung);
    $pvUeberschuss = floatval($pvUeberschuss);
    $batt = floatval($batt);
    $hausakkuSOC = floatval($hausakkuSOC);
    $hausakkuSOCVoll = floatval($hausakkuSOCVoll);
    $soc = floatval($soc);
    $targetSOC = floatval($targetSOC);

    $neuerText = '';

    if ($status == 1) {
        $neuerText = '🅿️ Kein Fahrzeug verbunden';
    } elseif ($soc >= $targetSOC && $targetSOC > 0) {
        $neuerText = '✅ Ziel-SOC erreicht – keine weitere Ladung';
    } elseif ($wartenAufTarif) {
        $neuerText = '⏳ Wartet auf günstigen Stromtarif';
    } elseif ($batt > 0 && $hausakkuSOC < $hausakkuSOCVoll) {
        $neuerText = '🔋 Hausakku lädt – Wallbox pausiert';
    } elseif ($ladeleistung > 0) {
        $neuerText = '⚡️ Ladung aktiv';
    } elseif ($pvUeberschuss <= 0) {
        $neuerText = '🌥️ Kein PV-Überschuss – wartet auf Sonne';
    } else {
        $neuerText = '⏸️ Keine Ladung aktiv';
    }

    $this->SetLogValue('LademodusStatus', $neuerText);

    // Logging für detaillierte Statusanalyse
    $this->Log("UpdateLademodusStatusAuto: Status={$status}, Ladeleistung={$ladeleistung} W, PV-Überschuss={$pvUeberschuss} W, Batterie={$batt} W, HausakkuSOC={$hausakkuSOC}%, ZielSOC={$targetSOC}%, TarifWarten=" . ($wartenAufTarif ? 'Ja' : 'Nein') . " → Text='{$neuerText}'", 'debug');
}
    
// =====================================================================================================

}
