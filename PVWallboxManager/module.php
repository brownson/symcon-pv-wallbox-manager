<?php

class PVWallboxManager extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->Log("Initialisiere Modulvariablen und Properties", 'debug');

        // Visualisierung berechneter Werte
        $this->RegisterVariableFloat('PV_Ueberschuss', 'PV-Überschuss (W)', '~Watt', 10);

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

        // PV2Car Verteilung
        $this->RegisterPropertyBoolean('PVVerteilenAktiv', false);
        $this->RegisterPropertyInteger('PVAnteilAuto', 33);
        $this->RegisterPropertyInteger('HausakkuSOCID', 0);
        $this->RegisterPropertyInteger('HausakkuSOCVollSchwelle', 95);

        // Visualisierung & WebFront
        $this->RegisterVariableBoolean('ManuellVollladen', '🔌 Manuell: Vollladen aktiv', '', 20);
        $this->EnableAction('ManuellVollladen');
        $this->RegisterVariableBoolean('PV2CarModus', '☀️ PV-Anteil fürs Auto aktiv', '', 30);
        $this->EnableAction('PV2CarModus');
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

        $this->Log("Initialisierung abgeschlossen", 'debug');
    }

// =====================================================================================================

public function ApplyChanges()
{
    parent::ApplyChanges();
    $this->Log("ApplyChanges(): Konfiguration wird angewendet.", 'debug');

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

        foreach (['ManuellVollladen', 'PV2CarModus', 'ZielzeitladungModus'] as $mod) {
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
    $this->Log("RequestAction(): Aufruf mit Ident={$ident}, Value=" . json_encode($value), 'debug');

    // --- Schalter & Buttons behandeln ---
    switch ($ident) {
        case 'ManuellVollladen':
            SetValue($this->GetIDForIdent($ident), $value);
            if ($value) {
                SetValue($this->GetIDForIdent('PV2CarModus'), false);
                SetValue($this->GetIDForIdent('ZielzeitladungModus'), false);
            }
            break;

        case 'PV2CarModus':
            SetValue($this->GetIDForIdent($ident), $value);
            if ($value) {
                SetValue($this->GetIDForIdent('ManuellVollladen'), false);
                SetValue($this->GetIDForIdent('ZielzeitladungModus'), false);
            }
            break;

        case 'ZielzeitladungModus':
            SetValue($this->GetIDForIdent($ident), $value);
            if ($value) {
                SetValue($this->GetIDForIdent('ManuellVollladen'), false);
                SetValue($this->GetIDForIdent('PV2CarModus'), false);
            }
            break;

        case 'TargetTime':
            SetValue($this->GetIDForIdent($ident), $value);
            break;

        default:
            parent::RequestAction($ident, $value);
            break;
    }

    // --- Prüfen, ob alle Lademodi deaktiviert ---
    $manuell = GetValue($this->GetIDForIdent('ManuellVollladen'));
    $pv2car  = GetValue($this->GetIDForIdent('PV2CarModus'));
    $ziel    = GetValue($this->GetIDForIdent('ZielzeitladungModus'));

    if (!$manuell && !$pv2car && !$ziel) {
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
            foreach (['ManuellVollladen', 'PV2CarModus', 'ZielzeitladungModus'] as $mod) {
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
                GetValue($this->GetIDForIdent('PV2CarModus')) ||
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
            $this->SetLadeleistung($this->GetMaxLadeleistung());
            $this->SetLademodusStatus("🔌 Manueller Volllademodus aktiv.");
            $this->Log("UpdateCharging(): Modus = Manueller Volllademodus.", 'info');
        } elseif (GetValue($this->GetIDForIdent('ZielzeitladungModus'))) {
            $this->Log("UpdateCharging(): Modus = Zielzeitladung.", 'info');
            $this->LogikZielzeitladung($hausverbrauch);
        } elseif (GetValue($this->GetIDForIdent('PV2CarModus'))) {
            $this->Log("UpdateCharging(): Modus = PV2Car.", 'info');
            $this->LogikPVPureMitHysterese('pv2car', $hausverbrauch);
        } else {
            $this->Log("UpdateCharging(): Modus = PV-Überschuss (Standard).", 'info');
            $this->LogikPVPureMitHysterese('standard', $hausverbrauch);
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

    // Standard-URLs basierend auf Provider setzen
    if ($provider === 'awattar_at') {
        $url = 'https://api.awattar.at/v1/marketdata';
    } elseif ($provider === 'awattar_de') {
        $url = 'https://api.awattar.de/v1/marketdata';
    }

    $this->Log("UpdateMarketPrices(): Abruf von {$url}", 'debug');

    $context = stream_context_create(['http' => ['timeout' => 5]]);
    $json = @file_get_contents($url, false, $context);

    if ($json === false) {
        $this->Log("UpdateMarketPrices(): Strompreisdaten konnten nicht geladen werden von {$url}!", 'error');
        return;
    }

    $data = json_decode($json, true);

    if (!is_array($data) || !isset($data['data'])) {
        $this->Log("UpdateMarketPrices(): Fehler beim Parsen der Strompreisdaten!", 'error');
        return;
    }

    // Preise aufbereiten (nur nächste 36h)
    $preise = [];
    foreach ($data['data'] as $item) {
        $preise[] = [
            'start' => intval($item['start_timestamp'] / 1000),
            'end'   => intval($item['end_timestamp'] / 1000),
            'price' => floatval($item['marketprice'] / 10.0)
        ];
    }

    $preise36 = array_filter($preise, function($slot) {
        return $slot['end'] > time() && $slot['start'] < (time() + 36 * 3600);
    });

    $jsonShort = json_encode(array_values($preise36));
    $this->SetLogValue('MarketPrices', $jsonShort);

    // Vorschautext für WebFront
    $vorschau = "";
    $count = 0;
    foreach ($preise36 as $p) {
        if ($count++ >= 6) break;
        $uhrzeit = date('d.m. H:i', $p['start']);
        $vorschau .= "{$uhrzeit}: " . number_format($p['price'], 2, ',', '.') . " ct/kWh\n";
    }

    $varID = $this->GetIDForIdent('MarketPricesText');
    if ($varID > 0) {
        SetValue($varID, $vorschau);
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

        $prozent = $this->ReadPropertyInteger('PVAnteilAuto');
        $anteilWatt = intval($ueberschuss * $prozent / 100);
        $minWatt = $this->ReadPropertyInteger('MinLadeWatt');

        $ladeSoll = 0;
        if ($anteilWatt > 0 && $anteilWatt >= $minWatt) {
            $ladeSoll = $anteilWatt;
        }

        $this->Log("PV2Car-Modus: Nutzer-Anteil = {$prozent}% → Ladeleistung für das Auto = {$anteilWatt} W (PV-Überschuss gesamt: {$ueberschuss} W, gesetzt: {$ladeSoll} W)", 'info');

        if ($goeID > 0) {
            GOeCharger_SetCurrentChargingWatt($goeID, $ladeSoll);
            $this->Log("Ladeleistung an Wallbox übergeben: {$ladeSoll} W (PV2Car-Modus)", 'debug');
        }
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
        'pv2car'  => "PV2Car",
        'manuell' => "Manueller Volllademodus",
        'zielzeit'=> "Zielzeit-Laden",
        'standard'=> "PV-Überschuss"
    ];
    $modusText = $modusTexte[$modus] ?? "Unbekannt";

    $minStart = $this->ReadPropertyInteger('MinLadeWatt');
    $minStop  = $this->ReadPropertyInteger('MinStopWatt');
    $goeID = $this->ReadPropertyInteger('GOEChargerID');

    $ladeModusID = @IPS_GetObjectIDByIdent('accessStateV2', $goeID);
    $ladeModus = ($ladeModusID !== false && @IPS_VariableExists($ladeModusID)) ? GetValueInteger($ladeModusID) : 0;

    // Überschuss berechnen
    $ueberschuss = 0;

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
    date_default_timezone_set('Europe/Vienna');

    // Zielzeit bestimmen (lokaler Timestamp)
    $offset = GetValue($this->GetIDForIdent('TargetTime'));
    $midnight = (new DateTime('today', new DateTimeZone('Europe/Vienna')))->getTimestamp();
    $tzOffset = (new DateTime('now', new DateTimeZone('Europe/Vienna')))->getOffset();
    $targetTime = $midnight + $offset + $tzOffset;

    if ($targetTime < time()) {
        $targetTime += 86400; // Morgen
    }
    $this->Log("Zielzeitladung: Zielzeit lokal = " . date('d.m.Y H:i:s', $targetTime), 'debug');

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
    $slots = array_values(array_filter($preise, fn($slot) => $slot['end'] > $now && $slot['start'] < $targetTime));

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
        // PV-Überschuss prüfen
        if ($hausverbrauch === null) $hausverbrauch = $this->BerechneHausverbrauch();
        $pvUeberschuss = $this->BerechnePVUeberschuss($hausverbrauch, 'standard');

        if ($pvUeberschuss > 0) {
            $msg = "Zielzeitladung: Kein Preisslot – PV-Überschuss laden ({$pvUeberschuss} W)";
            $this->SetLadeleistung($pvUeberschuss);
        } else {
            $msg = "Zielzeitladung: Kein Preisslot, kein PV-Überschuss – wartet";
            $this->SetLadeleistung(0);
        }
        $this->SetLademodusStatus($msg);
        $this->Log($msg, 'info');
    }
}
    
// =====================================================================================================
    
private function GetMaxLadeleistung(): int
{
    $hardLimit = $this->ReadPropertyInteger('MaxAutoWatt');
    if ($hardLimit > 0) {
        $this->Log("GetMaxLadeleistung(): Nutze konfiguriertes Limit {$hardLimit} W", 'debug');
        return $hardLimit;
    }

    $phasen = $this->ReadPropertyInteger('Phasen');
    $maxAmp = $this->ReadPropertyInteger('MaxAmpere');
    $maxWatt = $phasen * 230 * $maxAmp;

    $this->Log("GetMaxLadeleistung(): Berechnet {$phasen} Phasen x {$maxAmp} A = {$maxWatt} W", 'debug');
    return $maxWatt;
}

// =====================================================================================================
    
private function SetLadeleistung(int $watt)
{
    $goeID = $this->ReadPropertyInteger('GOEChargerID');
    if (!@IPS_InstanceExists($goeID)) {
        $this->Log("⚠️ go-e Charger Instanz nicht gefunden (ID: $goeID)", 'warn');
        return;
    }

    // Obergrenze Fahrzeuglimit
    $maxAutoWatt = $this->ReadPropertyInteger('MaxAutoWatt');
    if ($maxAutoWatt > 0 && $watt > $maxAutoWatt) {
        $this->Log("⚠️ Ladeleistung auf Fahrzeuglimit reduziert ({$watt} W → {$maxAutoWatt} W)", 'debug');
        $watt = $maxAutoWatt;
    }

    // Mindestladeleistung berücksichtigen
    $minWatt = $this->ReadPropertyInteger('MinLadeWatt');
    if ($watt > 0 && $watt < $minWatt) {
        $this->Log("⚠️ Angeforderte Ladeleistung zu niedrig ({$watt} W), setze auf Mindestwert {$minWatt} W", 'debug');
        $watt = $minWatt;
    }

    // Phasenumschaltung nur bei > 0 W prüfen
    if ($watt > 0) {
        $phaseVarID = @IPS_GetObjectIDByIdent('SinglePhaseCharging', $goeID);
        $aktuell1phasig = ($phaseVarID !== false && @IPS_VariableExists($phaseVarID)) ? GetValueBoolean($phaseVarID) : false;

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
        } elseif ($watt > $this->ReadPropertyInteger('Phasen3Schwelle') && $aktuell1phasig) {
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
            $this->WriteAttributeInteger('Phasen1Counter', 0);
            $this->WriteAttributeInteger('Phasen3Counter', 0);
        }
    } else {
        $this->WriteAttributeInteger('Phasen1Counter', 0);
        $this->WriteAttributeInteger('Phasen3Counter', 0);
    }

    // Aktuelle Leistung abfragen
    $modusID = @IPS_GetObjectIDByIdent('accessStateV2', $goeID);
    $wattID = @IPS_GetObjectIDByIdent('Watt', $goeID);
    $aktuellerModus = ($modusID !== false && @IPS_VariableExists($modusID)) ? GetValueInteger($modusID) : -1;
    $aktuelleLeistung = ($wattID !== false && @IPS_VariableExists($wattID)) ? GetValueFloat($wattID) : -1;

    if ($aktuelleLeistung < 0 || abs($aktuelleLeistung - $watt) > 50) {
        GOeCharger_SetCurrentChargingWatt($goeID, $watt);
        $this->Log("✅ Ladeleistung gesetzt: {$watt} W", 'info');

        if ($watt > 0 && $aktuellerModus != 2) {
            GOeCharger_setMode($goeID, 2);
            $this->Log("⚡ Modus auf 'Laden' gestellt (2)", 'debug');
        }
        if ($watt == 0 && $aktuellerModus != 1) {
            GOeCharger_setMode($goeID, 1);
            $this->Log("🔌 Modus auf 'Bereit' gestellt (1)", 'debug');
        }

        // Nur wenn Leistung geändert wurde, auch Status schreiben
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

private function SetLademodusStatus(string $text)
{
    $this->SetLogValue('LademodusStatus', $text);
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
    $modus = 'Kein Modus aktiv';

    if (GetValue($this->GetIDForIdent('ManuellVollladen'))) {
        $modus = 'Manueller Volllademodus';
    } elseif (GetValue($this->GetIDForIdent('PV2CarModus'))) {
        $modus = 'PV2Car';
    } elseif (GetValue($this->GetIDForIdent('ZielzeitladungModus'))) {
        $modus = 'Zielzeitladung';
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
            if ($modus !== 'Kein Modus aktiv')
                $statusText = "🔋 Modus aktiv: $modus – aber Ladung beendet.";
            else
                $statusText = "🅿️ Fahrzeug verbunden, Ladung beendet. Moduswechsel möglich.";
            break;
        case 1:
        default:
            $statusText = "⚠️ Kein Fahrzeug verbunden.";
            break;
    }

    $this->SetFahrzeugStatus($statusText);

    // *** Logging ***
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

    if (@$this->GetIDForIdent('Hausverbrauch') > 0) {
        SetValue($this->GetIDForIdent('Hausverbrauch'), $hausverbrauch);
    }

    return $hausverbrauch;
}
    
// =====================================================================================================

private function Log(string $message, string $level)
{
    // Unterstützte Level: debug, info, warn, warning, error
    $prefix = "PVWM";
    $normalized = strtolower(trim($level));

    // Nur nicht-leere Nachrichten loggen
    if (trim($message) === '') return;

    switch ($normalized) {
        case 'debug':
            if ($this->ReadPropertyBoolean('DebugLogging')) {
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

        if (trim((string)$alt) !== trim((string)$value)) {
            SetValue($varID, $value);

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

    if ($eventID === false) {
        $eventID = IPS_CreateEvent(0); // Bei Wertänderung
        IPS_SetParent($eventID, $this->InstanceID);
        IPS_SetIdent($eventID, $eventIdent);
        IPS_SetName($eventID, "Trigger: UpdateCharging bei Fahrzeugstatus > 1");
        IPS_SetEventTrigger($eventID, 1, $statusVarID);
        IPS_SetEventActive($eventID, true);

        $code = 'if ($_IPS["VALUE"] > 1) { IPS_RequestAction(' . $this->InstanceID . ', "UpdateCharging", true); }';
        IPS_SetEventScript($eventID, $code);

        $this->Log("Ereignis zum sofortigen Update bei Statuswechsel wurde neu erstellt.", 'info');
    } else {
        if (@IPS_GetEvent($eventID)['TriggerVariableID'] != $statusVarID) {
            IPS_SetEventTrigger($eventID, 1, $statusVarID);
            $this->Log("Trigger-Variable im Ereignis aktualisiert.", 'debug');
        }
        IPS_SetEventActive($eventID, true);
        $this->Log("Ereignis zum sofortigen Update geprüft und reaktiviert.", 'debug');
    }
}

// =====================================================================================================

    // Löscht das Ereignis für Statuswechsel, falls vorhanden.
private function RemoveStatusEvent()
{
    $eventIdent = 'Trigger_UpdateCharging_OnStatusChange';
    $eventID = @IPS_GetObjectIDByIdent($eventIdent, $this->InstanceID);

    if ($eventID !== false && @IPS_EventExists($eventID)) {
        IPS_DeleteEvent($eventID);
        $this->Log("Ereignis zum sofortigen Update bei Statuswechsel wurde entfernt.", 'debug');
    } else {
        $this->Log("RemoveStatusEvent: Kein bestehendes Ereignis gefunden – nichts zu tun.", 'debug');
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
        default:
            $text = '⏸️ Keine Ladung aktiv';
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

    $this->Log("UpdateLademodusStatusAuto: Status={$status}, Ladeleistung={$ladeleistung} W, PV-Überschuss={$pvUeberschuss} W, Batterie={$batt} W, HausakkuSOC={$hausakkuSOC}%, ZielSOC={$targetSOC}%, TarifWarten=" . ($wartenAufTarif ? 'Ja' : 'Nein') . " → Text='{$neuerText}'", 'debug');
}
    
// =====================================================================================================

}
