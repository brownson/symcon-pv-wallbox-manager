<?php

class PVWallboxManager extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Visualisierung berechneter Werte
        $this->RegisterVariableFloat('PV_Ueberschuss', 'PV-Überschuss (W)', '~Watt', 10); // Aktuell berechneter PV-Überschuss in Watt

        // Energiequellen (Variablen-IDs für Berechnung)
        $this->RegisterPropertyInteger('PVErzeugungID', 0); // PV-Erzeugung in Watt
        $this->RegisterPropertyString("PVErzeugungEinheit", "W");
        
        $this->RegisterPropertyInteger('HausverbrauchID', 0); // Hausverbrauch in Watt
        $this->RegisterPropertyBoolean("InvertHausverbrauch", false);
        $this->RegisterPropertyString("HausverbrauchEinheit", "W");
        
        $this->RegisterPropertyInteger('BatterieladungID', 0); // Batterie-Lade-/Entladeleistung in Watt
        $this->RegisterPropertyBoolean("InvertBatterieladung", false);
        $this->RegisterPropertyString("BatterieladungEinheit", "W");
        
        $this->RegisterPropertyInteger('NetzeinspeisungID', 0); // Einspeisung/Bezug ins Netz (positiv/negativ)
        $this->RegisterPropertyBoolean("InvertNetzeinspeisung", false);
        $this->RegisterPropertyString("NetzeinspeisungEinheit", "W");

        // Wallbox-Einstellungen
        $this->RegisterPropertyInteger('GOEChargerID', 0); // Instanz-ID des GO-e Chargers
        $this->RegisterPropertyInteger('MinAmpere', 6); // Minimale Ladeleistung (Ampere)
        $this->RegisterPropertyInteger('MaxAmpere', 16); // Maximale Ladeleistung (Ampere)
        $this->RegisterPropertyInteger('Phasen', 3); // Anzahl aktiv verwendeter Ladephasen (1 oder 3)

        // Lade-Logik & Schwellenwerte
        $this->RegisterPropertyInteger('MinLadeWatt', 1400); // Mindest-PV-Überschuss zum Starten (Watt)
        $this->RegisterPropertyInteger('MinStopWatt', -300); // Schwelle zum Stoppen bei Defizit (Watt)
        $this->RegisterPropertyInteger('Phasen1Schwelle', 1000); // Schwelle zum Umschalten auf 1-phasig (Watt)
        $this->RegisterPropertyInteger('Phasen3Schwelle', 4200); // Schwelle zum Umschalten auf 3-phasig (Watt)
        $this->RegisterPropertyInteger('Phasen1Limit', 3); // Messzyklen unterhalb Schwelle vor Umschalten auf 1-phasig
        $this->RegisterPropertyInteger('Phasen3Limit', 3); // Messzyklen oberhalb Schwelle vor Umschalten auf 3-phasig
        $this->RegisterPropertyBoolean('DynamischerPufferAktiv', true); // Dynamischer Sicherheitsabzug aktiv

        // Fahrzeug-Erkennung & Ziel-SOC
        $this->RegisterPropertyBoolean('NurMitFahrzeug', true); // Ladung nur wenn Fahrzeug verbunden
        $this->RegisterPropertyBoolean('UseCarSOC', false); // Fahrzeug-SOC berücksichtigen
        $this->RegisterPropertyInteger('CarSOCID', 0); // Variable für aktuellen SOC des Fahrzeugs
        $this->RegisterPropertyFloat('CarSOCFallback', 20); // Fallback-SOC wenn keine Variable verfügbar
        $this->RegisterPropertyInteger('CarTargetSOCID', 0); // Ziel-SOC Variable
        $this->RegisterPropertyFloat('CarTargetSOCFallback', 80); // Fallback-Zielwert für SOC
        $this->RegisterPropertyFloat('CarBatteryCapacity', 52.0); // Batteriekapazität des Fahrzeugs in kWh
        $this->RegisterPropertyBoolean('AlwaysUseTargetSOC', false); // Ziel-SOC immer berücksichtigen (auch bei PV-Überschussladung)

        // Interne Status-Zähler für Phasenumschaltung
        $this->RegisterAttributeInteger('Phasen1Counter', 0);
        $this->RegisterAttributeInteger('Phasen3Counter', 0);

        $this->RegisterAttributeBoolean('RunLogFlag', true);

        // Erweiterte Logik: PV-Verteilung Auto/Haus
        $this->RegisterPropertyBoolean('PVVerteilenAktiv', false); // PV-Leistung anteilig zum Auto leiten
        $this->RegisterPropertyInteger('PVAnteilAuto', 33); // Anteil für das Auto in Prozent
        $this->RegisterPropertyInteger('HausakkuSOCID', 0); // SOC-Variable des Hausakkus
        $this->RegisterPropertyInteger('HausakkuSOCVollSchwelle', 95); // Schwelle ab wann Akku voll gilt

        // Visualisierung & WebFront-Buttons
        $this->RegisterVariableBoolean('ManuellVollladen', '🔌 Manuell: Vollladen aktiv', '', 95);
        $this->EnableAction('ManuellVollladen');

        $this->RegisterVariableBoolean('PV2CarModus', '☀️ PV-Anteil fürs Auto aktiv', '', 96);
        $this->EnableAction('PV2CarModus');

        $this->RegisterVariableBoolean('ZielzeitladungPVonly', '⏱️ Zielzeitladung PV-optimiert', '', 97);
        $this->EnableAction('ZielzeitladungPVonly');

        $this->RegisterVariableString('LademodusStatus', 'Aktueller Lademodus', '', 98);
        $this->RegisterVariableString('WallboxStatusText', 'Wallbox Status', '~HTMLBox', 99);


        $this->RegisterVariableInteger('TargetTime', 'Ziel-Zeit (Uhr)', '~UnixTimestampTime', 60);
        $this->EnableAction('TargetTime');

        // Zykluszeiten & Ladeplanung
        $this->RegisterPropertyInteger('RefreshInterval', 60); // Intervall für die Überschuss-Berechnung (Sekunden)
        $this->RegisterPropertyInteger('TargetChargePreTime', 4); // Stunden vor Zielzeit aktiv laden

        //Für die Berechnung der Ladeverluste
        $this->RegisterAttributeBoolean("ChargingActive", false);
        $this->RegisterAttributeFloat("ChargeSOCStart", 0);
        $this->RegisterAttributeFloat("ChargeEnergyStart", 0);
        $this->RegisterAttributeInteger("ChargeStartTime", 0);

        // Strompreis-Ladung (ab Version 0.9)
        $this->RegisterPropertyInteger("CurrentPriceID", 0);      // Aktueller Preis (ct/kWh, Float)
        $this->RegisterPropertyInteger("ForecastPriceID", 0);     // 24h-Prognose (ct/kWh, String)
        $this->RegisterPropertyFloat("MinPrice", 0.000);       // Mindestpreis (ct/kWh)
        $this->RegisterPropertyFloat("MaxPrice", 30.000);      // Höchstpreis (ct/kWh)

        // Timer für regelmäßige Berechnung
        $this->RegisterTimer('PVUeberschuss_Berechnen', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateCharging", 0);');
        $this->RegisterTimer('ZyklusLadevorgangCheck', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "ZyklusLadevorgangCheck", 0);');
        
        $this->RegisterPropertyBoolean('ModulAktiv', true);
        $this->RegisterPropertyBoolean('DebugLogging', false);

    }
    
    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->Log('Instanz-Config: ' . json_encode(IPS_GetConfiguration($this->InstanceID)), 'debug');

        $interval = $this->ReadPropertyInteger('RefreshInterval');
        $goeID    = $this->ReadPropertyInteger('GOEChargerID');
        $pvID     = $this->ReadPropertyInteger('PVErzeugungID');

        // Timer nur aktivieren, wenn GO-e und PV-Erzeugung konfiguriert
        if (!$this->ReadPropertyBoolean('ModulAktiv')) {
            // Deaktiviert: Alle Timer aus
            $this->SetTimerInterval('PVUeberschuss_Berechnen', 0);
            $this->SetTimerInterval('ZyklusLadevorgangCheck', 0);
            $this->SetLademodusStatus("⚠️ Modul ist deaktiviert. Keine Aktionen.");
            $this->Log('Modul ist deaktiviert – alle Timer gestoppt.', 'info');
            return;
        }

        // Timer nur aktivieren, wenn GO-e und PV-Erzeugung konfiguriert
        if ($goeID > 0 && $pvID > 0 && $interval > 0) {
            $this->SetTimerInterval('PVUeberschuss_Berechnen', $interval * 1000);
            $this->SetTimerInterval('ZyklusLadevorgangCheck', max($interval, 30) * 1000);
            $this->Log("Timer aktiviert: Intervall PVUeberschuss_Berechnen={$interval}s, ZyklusLadevorgangCheck=" . max($interval, 30) . "s", 'info');
        } else {
            $this->SetTimerInterval('PVUeberschuss_Berechnen', 0);
            $this->SetTimerInterval('ZyklusLadevorgangCheck', 0);
            $this->Log('Timer deaktiviert – GO-e Instanz oder PV-Erzeugung oder Intervall nicht konfiguriert.', 'warn');
        }
    }

    public function RequestAction($ident, $value)
    {
        // NUR Variablen und Modus-Flags setzen! KEINE Statusmeldungen!
        switch ($ident) {
            case 'ManuellVollladen':
                SetValue($this->GetIDForIdent($ident), $value);
                if ($value) {
                    SetValue($this->GetIDForIdent('PV2CarModus'), false);
                    SetValue($this->GetIDForIdent('ZielzeitladungPVonly'), false);
                    SetValue($this->GetIDForIdent('StrompreisModus'), false);
                }
                break;
            case 'PV2CarModus':
                SetValue($this->GetIDForIdent($ident), $value);
                if ($value) {
                    SetValue($this->GetIDForIdent('ManuellVollladen'), false);
                    SetValue($this->GetIDForIdent('ZielzeitladungPVonly'), false);
                    SetValue($this->GetIDForIdent('StrompreisModus'), false);
                }
                break;
            case 'ZielzeitladungPVonly':
                SetValue($this->GetIDForIdent($ident), $value);
                if ($value) {
                    SetValue($this->GetIDForIdent('ManuellVollladen'), false);
                    SetValue($this->GetIDForIdent('PV2CarModus'), false);
                    SetValue($this->GetIDForIdent('StrompreisModus'), false);
                }
                break;
            case 'StrompreisModus':
                SetValue($this->GetIDForIdent($ident), $value);
                if ($value) {
                    SetValue($this->GetIDForIdent('ManuellVollladen'), false);
                    SetValue($this->GetIDForIdent('PV2CarModus'), false);
                    SetValue($this->GetIDForIdent('ZielzeitladungPVonly'), false);
                }
                break;
            case 'TargetTime':
                SetValue($this->GetIDForIdent($ident), $value);
                break;
            default:
                parent::RequestAction($ident, $value);
                break;
        }
        // Hauptlogik am Ende immer aufrufen!
        $this->UpdateCharging();
    }

    public function UpdateCharging()
    {
        $this->WriteAttributeBoolean('RunLogFlag', true); // Start eines neuen Durchlaufs
        $this->Log('debug', "Starte Berechnung (UpdateCharging)");
    
        $goeID = $this->ReadPropertyInteger('GOEChargerID');
        $status = GOeCharger_GetStatus($goeID); // 1=bereit, 2=lädt, 3=warte, 4=beendet
    
        // Immer: PV-Überschuss (inkl. Batterieabzug) berechnen und anzeigen
        $pvUeberschussStandard = $this->BerechnePVUeberschuss();
        SetValue($this->GetIDForIdent('PV_Ueberschuss'), $pvUeberschussStandard);
        $this->Log("Standard-PV-Überschuss berechnet: {$pvUeberschussStandard} W", 'debug');
    
        // === Fahrzeugstatus-Logik ===
        if ($this->ReadPropertyBoolean('NurMitFahrzeug') && $status == 1) {
            // Wenn kein Fahrzeug verbunden, alle Modi deaktivieren
            foreach (['ManuellVollladen','PV2CarModus','ZielzeitladungPVonly','StrompreisModus'] as $mod) {
                if (GetValue($this->GetIDForIdent($mod))) {
                    SetValue($this->GetIDForIdent($mod), false);
                }
            }
            // Wallbox auf "Bereit" setzen
            if (GOeCharger_getMode($goeID) != 1) {
                GOeCharger_setMode($goeID, 1);
            }
            $this->SetLademodusStatus("⚠️ Kein Fahrzeug verbunden – bitte erst Fahrzeug anschließen.");
            SetValue($this->GetIDForIdent('PV_Ueberschuss'), 0.0);
            $this->Log("Kein Fahrzeug verbunden – Abbruch der Berechnung", 'warn');
            $this->UpdateWallboxStatusText();
            return;
        }
        // Status-Logik für weitere Fahrzeugstatus
        if ($this->ReadPropertyBoolean('NurMitFahrzeug')) {
            if ($status == 3) {
                $this->SetLademodusStatus("🚗 Fahrzeug angeschlossen, wartet auf Freigabe (z.B. Tür öffnen oder am Fahrzeug 'Laden' aktivieren)");
                $this->Log("Fahrzeug angeschlossen, wartet auf Freigabe", 'debug');
            }
            if ($status == 4) {
                $this->SetLademodusStatus("🅿️ Fahrzeug verbunden, Ladung beendet. Moduswechsel möglich.");
                $this->Log("Fahrzeug verbunden, Ladung beendet", 'debug');
            }
        }
    
        // === Ziel-SOC immer berücksichtigen, wenn Option aktiv ===
        if ($this->ReadPropertyBoolean('AlwaysUseTargetSOC')) {
            $socID = $this->ReadPropertyInteger('CarSOCID');
            $soc = (IPS_VariableExists($socID) && $socID > 0) ? GetValue($socID) : $this->ReadPropertyFloat('CarSOCFallback');
            $targetSOCID = $this->ReadPropertyInteger('CarTargetSOCID');
            $targetSOC = (IPS_VariableExists($targetSOCID) && $targetSOCID > 0) ? GetValue($targetSOCID) : $this->ReadPropertyFloat('CarTargetSOCFallback');
    
            $this->Log("SOC-Prüfung (AlwaysUseTargetSOC): Ist={$soc}%, Ziel={$targetSOC}% (Option aktiv)", 'info');
            if ($soc >= $targetSOC) {
                $this->SetLadeleistung(0);
                $this->SetLademodusStatus("Ziel-SOC erreicht ({$soc}% ≥ {$targetSOC}%) – keine weitere Ladung.");
                $this->Log("Ziel-SOC erreicht ({$soc}% ≥ {$targetSOC}%) – keine weitere Ladung.", 'info');
                $this->UpdateWallboxStatusText();
                return;
            }
        }
    
        // === Modus-Weiche: NUR eine Logik pro Durchlauf! ===
        // Priorität: Manuell > Zielzeit > PV2Car > Standard
        if (GetValue($this->GetIDForIdent('ManuellVollladen'))) {
            $this->SetLadeleistung($this->GetMaxLadeleistung());
            $this->SetLademodusStatus("Manueller Volllademodus aktiv");
            $this->Log("Modus: Manueller Volllademodus", 'info');
        } elseif (GetValue($this->GetIDForIdent('ZielzeitladungPVonly'))) {
            $this->Log("Modus: Zielzeitladung PV-optimiert", 'info');
            $this->LogikZielzeitladung();
        } elseif (GetValue($this->GetIDForIdent('PV2CarModus'))) {
            $this->Log("Modus: PV2Car aktiv", 'info');
            // --- PV2Car Code, wie gehabt ---
            $pv = 0;
            $pvID = $this->ReadPropertyInteger('PVErzeugungID');
            if ($pvID > 0 && @IPS_VariableExists($pvID)) {
                $pv = GetValue($pvID);
                if ($this->ReadPropertyString('PVErzeugungEinheit') == 'kW') {
                    $pv *= 1000;
                }
            }
            $haus = $this->GetNormWert('HausverbrauchID', 'HausverbrauchEinheit', 'InvertHausverbrauch', "Hausverbrauch");
            $pvUeberschussDirekt = max(0, $pv - $haus);
    
            // Hausakku SoC prüfen ...
            $hausakkuSocID = $this->ReadPropertyInteger('HausakkuSOCID');
            $hausakkuSocVoll = $this->ReadPropertyInteger('HausakkuSOCVollSchwelle');
            $hausakkuSoc = 0;
            if ($hausakkuSocID > 0 && @IPS_VariableExists($hausakkuSocID)) {
                $hausakkuSoc = GetValue($hausakkuSocID);
            }
            $anteil = $this->ReadPropertyInteger('PVAnteilAuto');
            $autoProzent = $anteil;
            $restProzent = 100 - $anteil;
            if ($hausakkuSoc >= $hausakkuSocVoll) {
                $autoProzent = 100;
                $restProzent = 0;
            }
            $ladeWatt = min(max(round($pvUeberschussDirekt * ($autoProzent / 100.0)), 0), $this->GetMaxLadeleistung());
            $info = "PV2Car: {$autoProzent}% vom Überschuss ({$ladeWatt} W)";
            if ($autoProzent == 100) {
                $info .= " (Hausakku voll, 100 % ins Auto)";
            } else {
                $info .= " ({$restProzent}% zur Batterie)";
            }
            $this->SetLadeleistung($ladeWatt);
            $this->SetLademodusStatus($info);
            $this->Log("PV2Car: Anteil Auto: {$autoProzent}% | Ladeleistung: {$ladeWatt} W | Rest zur Batterie: {$restProzent}%", 'debug');
        } else {
            // === Standard: Nur PV-Überschuss/Hysterese ===
            $this->Log("Modus: PV-Überschuss (Standard)", 'info');
            $this->LogikPVPureMitHysterese();
        }
    
        // Optional: WallboxStatusText für WebFront aktualisieren (nur einmal pro Zyklus)
        $this->UpdateWallboxStatusText();
    }

    // --- Hilfsfunktion: PV-Überschuss berechnen ---
    // Modus kann 'standard' (bisher wie gehabt) oder 'pv2car' (neuer PV2Car-Modus) sein
    private function BerechnePVUeberschuss(string $modus = 'standard'): float
    {
        $goeID  = $this->ReadPropertyInteger("GOEChargerID");
    
        // Werte auslesen, immer auf Watt normiert
        $pv    = 0;
        $pvID  = $this->ReadPropertyInteger('PVErzeugungID');
        if ($pvID > 0 && @IPS_VariableExists($pvID)) {
            $pv = GetValue($pvID);
            if ($this->ReadPropertyString('PVErzeugungEinheit') == 'kW') {
                $pv *= 1000;
            }
        }
        
        $haus  = $this->GetNormWert('HausverbrauchID', 'HausverbrauchEinheit', 'InvertHausverbrauch', "Hausverbrauch");
        $batt  = $this->GetNormWert('BatterieladungID', 'BatterieladungEinheit', 'InvertBatterieladung', "Batterieladung");
        $netz  = $this->GetNormWert('NetzeinspeisungID', 'NetzeinspeisungEinheit', 'InvertNetzeinspeisung', "Netzeinspeisung");
    
        // Ladeleistung (optional für Debugging)
        $ladeleistung = ($goeID > 0) ? GOeCharger_GetPowerToCar($goeID) : 0;
    
        // --- Unterscheidung nach Modus ---
        if ($modus == 'pv2car') {
            // Anteil direkt ans Auto (Rest für Batterie)
            $ueberschuss = $pv - $haus;
            $logModus = "PV2Car (Auto bekommt Anteil vom Überschuss, Rest Batterie)";
        } else {
            // Standard: Batterie bekommt Vorrang
            $ueberschuss = $pv - $haus - max(0, $batt);
            $logModus = "Standard (Batterie hat Vorrang)";
        }
    
        // Dynamischer Puffer
        $puffer = 1.0;
        if ($this->ReadPropertyBoolean('DynamischerPufferAktiv')) {
            if ($ueberschuss < 2000)      $puffer = 0.80;
            elseif ($ueberschuss < 4000)  $puffer = 0.85;
            elseif ($ueberschuss < 6000)  $puffer = 0.90;
            else                          $puffer = 0.93;
            $alterUeberschuss = $ueberschuss;
            $ueberschuss *= $puffer;
            $this->Log(
                "🧮 Dynamischer Pufferfaktor angewendet: {$puffer} – Überschuss vorher: " . round($alterUeberschuss) . " W, jetzt: " . round($ueberschuss) . " W",
                'debug'
            );
        }
        
        // Auf Ganzzahl runden und negatives abfangen
        $ueberschuss = max(0, round($ueberschuss));
    
        // --- Zentrales Logging ---
        $logMsg = "[{$logModus}] PV-Überschuss = PV: {$pv} W - Haus: {$haus} W";
        if ($modus != 'pv2car') {
            $logMsg .= " - Batterie: {$batt} W";
        }
        if ($this->ReadPropertyBoolean('DynamischerPufferAktiv')) {
            $logMsg .= " [Pufferfaktor: {$puffer}]";
            $logMsg .= " → nach Puffer: " . round($ueberschuss) . " W";
        } else {
            $logMsg .= " → Ergebnis: " . round($ueberschuss) . " W";
        }
    
        // Immer 1x loggen (zentrales Level: info)
        $this->Log($logMsg, 'info');
        //$this->Log("PV-Berechnung", $logMsg, 0);
    
        // In Variable schreiben (nur im Standardmodus als Visualisierung)
        if ($modus == 'standard') {
            SetValue($this->GetIDForIdent('PV_Ueberschuss'), $ueberschuss);
        }
    
        return $ueberschuss;
    }


    // --- Hysterese-Logik für Standardmodus ---
    private function LogikPVPureMitHysterese()
    {
        $minStart = $this->ReadPropertyInteger('MinLadeWatt');
        $minStop  = $this->ReadPropertyInteger('MinStopWatt');
        $ueberschuss = $this->BerechnePVUeberschuss('standard');
        $goeID = $this->ReadPropertyInteger('GOEChargerID');
        $ladeModusID = @IPS_GetObjectIDByIdent('accessStateV2', $goeID);
        $ladeModus = ($ladeModusID !== false && @IPS_VariableExists($ladeModusID)) ? GetValueInteger($ladeModusID) : 0;
    
        $this->Log(
            "Hysterese: Modus={$ladeModus}, Überschuss={$ueberschuss} W, MinStart={$minStart} W, MinStop={$minStop} W",
            'debug'
        );
    
        if ($ladeModus == 2) { // Lädt bereits
            if ($ueberschuss <= $minStop) {
                $this->SetLadeleistung(0);
                $msg = "PV-Überschuss unter Stop-Schwelle ({$ueberschuss} W ≤ {$minStop} W) – Wallbox gestoppt";
                $this->Log($msg, 'info');
                $this->SetLademodusStatus($msg);
            } else {
                $this->SetLadeleistung($ueberschuss);
                $msg = "PV-Überschuss: Bleibt an ({$ueberschuss} W)";
                $this->Log($msg, 'info');
                $this->SetLademodusStatus($msg);
            }
        } else { // Lädt NICHT
            if ($ueberschuss >= $minStart) {
                $this->SetLadeleistung($ueberschuss);
                $msg = "PV-Überschuss über Start-Schwelle ({$ueberschuss} W ≥ {$minStart} W) – Wallbox startet";
                $this->Log($msg, 'info');
                $this->SetLademodusStatus($msg);
            } else {
                $this->SetLadeleistung(0);
                $msg = "PV-Überschuss zu niedrig ({$ueberschuss} W) – bleibt aus";
                $this->Log($msg, 'info');
                $this->SetLademodusStatus($msg);
            }
        }
    }

    // --- Zielzeitladung-Logik: ---
    private function LogikZielzeitladung()
    {
        // Zielzeit holen & ggf. auf nächsten Tag anpassen
        $targetTimeVarID = $this->GetIDForIdent('TargetTime');
        $targetTime = GetValue($targetTimeVarID);
        $now = time();
        if ($targetTime < $now) $targetTime += 86400;
    
        // SOC & Ziel-SOC holen
        $socID = $this->ReadPropertyInteger('CarSOCID');
        $soc = (IPS_VariableExists($socID) && $socID > 0) ? GetValue($socID) : $this->ReadPropertyFloat('CarSOCFallback');
        $targetSOCID = $this->ReadPropertyInteger('CarTargetSOCID');
        $targetSOC = (IPS_VariableExists($targetSOCID) && $targetSOCID > 0) ? GetValue($targetSOCID) : $this->ReadPropertyFloat('CarTargetSOCFallback');
        $capacity = $this->ReadPropertyFloat('CarBatteryCapacity'); // z.B. 52.0 kWh
    
        // Restenergie und Zeit
        $fehlendeProzent = max(0, $targetSOC - $soc);
        $fehlendeKWh = $capacity * $fehlendeProzent / 100.0;
    
        // Ziel erreicht?
        if ($fehlendeProzent <= 0) {
            $this->SetLadeleistung(0);
            $msg = "Zielzeitladung: Ziel-SOC erreicht – keine Ladung mehr erforderlich";
            $this->Log($msg, 'info');
            $this->SetLademodusStatus($msg);
            return;
        }
    
        // ==== Forecast auslesen (falls vorhanden) ====
        $forecastVarID = $this->ReadPropertyInteger("ForecastPriceID");
        $forecast = [];
        if ($forecastVarID > 0 && @IPS_VariableExists($forecastVarID)) {
            $forecastString = GetValue($forecastVarID);
            $forecast = json_decode($forecastString, true); // Wenn es JSON ist!
            if (!is_array($forecast)) {
                $forecast = array_map('floatval', explode(';', $forecastString));
            }
        }
    
        $maxWatt = $this->GetMaxLadeleistung();
        $ladezeitStd = $fehlendeKWh / ($maxWatt / 1000.0); // kWh / (kW) = h
    
        if (!is_array($forecast) || count($forecast) < 1) {
            $this->Log("Forecast: Keine gültigen Prognosedaten gefunden – Standard-Zielzeit-Logik wird verwendet.", 'warn');
        }
    
        if (is_array($forecast) && count($forecast) >= 1) {
            $nowHour = intval(date('G', $now));
            $stundenslots = [];
            for ($i = 0; $i < count($forecast); $i++) {
                $slotTime = $now + $i * 3600;
                if ($slotTime > $targetTime) continue;
                $stundenslots[] = [
                    "index" => $i,
                    "price" => floatval($forecast[$i]),
                    "time" => $slotTime,
                ];
            }
            // Günstigste n-Stunden-Fenster finden
            usort($stundenslots, function($a, $b) { return $a["price"] <=> $b["price"]; });
    
            $ladeStunden = ceil($ladezeitStd);
            $ladezeiten = array_slice($stundenslots, 0, $ladeStunden);
    
            // Logging Ladefenster (debug)
            $ladeFensterTxt = implode(", ", array_map(function($slot) {
                return date('H', $slot["time"]) . "h: " . round($slot["price"], 2) . "ct";
            }, $ladezeiten));
            $this->Log("Forecast: Ladefenster gewählt: {$ladeFensterTxt}", 'debug');
    
            $aktuelleStunde = intval(date('G', $now));
            $ladeJetzt = false;
            $aktuellerSlotPrice = null;
            foreach ($ladezeiten as $slot) {
                if (intval(date('G', $slot["time"])) == $aktuelleStunde) {
                    $ladeJetzt = true;
                    $aktuellerSlotPrice = $slot["price"];
                    break;
                }
            }
    
            if ($ladeJetzt) {
                $this->SetLadeleistung($maxWatt);
                $msg = "Forecast: Lade in günstigster Stunde (" . round($aktuellerSlotPrice, 2) . " ct/kWh), Rest: " . round($fehlendeKWh, 2) . " kWh";
                $this->Log($msg, 'info');
                $this->SetLademodusStatus($msg);
            } else {
                // Nicht laden, außer PV-Überschuss ist vorhanden!
                $pvUeberschuss = $this->BerechnePVUeberschuss();
                if ($pvUeberschuss > 0) {
                    $msg = "Forecast: Lade nur mit PV-Überschuss, Rest: " . round($fehlendeKWh, 2) . " kWh";
                    $this->SetLadeleistung($pvUeberschuss);
                    $this->Log($msg, 'info');
                    $this->SetLademodusStatus($msg);
                } else {
                    $msg = "Forecast: Warte auf günstigen Tarif oder PV, Rest: " . round($fehlendeKWh, 2) . " kWh";
                    $this->SetLadeleistung(0);
                    $this->Log($msg, 'info');
                    $this->SetLademodusStatus($msg);
                }
            }
            return;
        }
    
        // Ladeleistung bestimmen (PV-only bis x Stunden vor Zielzeit, dann volle Leistung)
        $maxWatt = $this->GetMaxLadeleistung();
        $minWatt = $this->ReadPropertyInteger('MinLadeWatt');
        $pvUeberschuss = $this->BerechnePVUeberschuss();
        $ladewatt = max($pvUeberschuss, $minWatt);
    
        // Reststunden berechnen
        $ladeleistung_kW = $ladewatt / 1000.0;
        $restStunden = ($ladeleistung_kW > 0) ? round($fehlendeKWh / $ladeleistung_kW, 2) : 99;
    
        // Umschaltzeit berechnen
        $stundenVorher = $this->ReadPropertyInteger('TargetChargePreTime');
        $forceTime = $targetTime - ($stundenVorher * 3600);
    
        if ($now >= $forceTime) {
            $msg = "Zielzeitladung: Maximale Leistung (Netzbezug möglich, {$fehlendeKWh} kWh fehlen)";
            $this->SetLadeleistung($maxWatt);
            $this->Log("Zielzeitladung: Netzbezug erlaubt, maximale Leistung {$maxWatt} W – {$fehlendeKWh} kWh fehlen", 'info');
            $this->SetLademodusStatus($msg);
        } else {
            $bisWann = date('H:i', $forceTime);
            $msg = "Zielzeitladung: Nur PV-Überschuss bis $bisWann Uhr – {$fehlendeKWh} kWh fehlen ({$restStunden} h nötig)";
            $this->SetLadeleistung($pvUeberschuss);
            $this->Log("Zielzeitladung: Nur PV-Überschuss – noch {$fehlendeKWh} kWh, Restzeit ca. {$restStunden} h, Umschaltung um $bisWann Uhr", 'info');
            $this->SetLademodusStatus($msg);
        }
    }
    
        private function GetMaxLadeleistung(): int
        {
            $phasen = $this->ReadPropertyInteger('Phasen');
            $maxAmp = $this->ReadPropertyInteger('MaxAmpere');
            return $phasen * 230 * $maxAmp;
        }
        
        private function SetLadeleistung(int $watt)
        {
            $typ = 'go-e';
        
            switch ($typ) {
                case 'go-e':
                    $goeID = $this->ReadPropertyInteger('GOEChargerID');
                    if (!@IPS_InstanceExists($goeID)) {
                        $this->Log('warn', "⚠️ go-e Charger Instanz nicht gefunden (ID: $goeID)");
                        return;
                    }
                    
                    // *** Korrektur: Counterlogik nur bei > 0 W ***
                    if ($watt > 0) {
                        // ...Counter für Phasenumschaltung wie gehabt...
                    } else {
                        // Counter zurücksetzen, keine Umschaltung ausführen
                        $this->WriteAttributeInteger('Phasen1Counter', 0);
                        $this->WriteAttributeInteger('Phasen3Counter', 0);
                    }
        
                    // Phasenumschaltung prüfen
                    $phaseVarID = @IPS_GetObjectIDByIdent('SinglePhaseCharging', $goeID);
                    $aktuell1phasig = false;
                    if ($phaseVarID !== false && @IPS_VariableExists($phaseVarID)) {
                        $aktuell1phasig = GetValueBoolean($phaseVarID);
                    }
        
                    // Hysterese für Umschaltung
                    if ($watt < $this->ReadPropertyInteger('Phasen1Schwelle') && !$aktuell1phasig) {
                        $counter = $this->ReadAttributeInteger('Phasen1Counter') + 1;
                        $this->WriteAttributeInteger('Phasen1Counter', $counter);
                        $this->WriteAttributeInteger('Phasen3Counter', 0);
                        $this->Log('info', "⏬ Zähler 1-phasig: {$counter} / {$this->ReadPropertyInteger('Phasen1Limit')}");
                        if ($counter >= $this->ReadPropertyInteger('Phasen1Limit')) {
                            if (!$aktuell1phasig) {
                                GOeCharger_SetSinglePhaseCharging($goeID, true);
                                $this->Log('info', "🔁 Umschaltung auf 1-phasig ausgelöst");
                            }
                            $this->WriteAttributeInteger('Phasen1Counter', 0);
                        }
                    } elseif ($watt > $this->ReadPropertyInteger('Phasen3Schwelle') && $aktuell1phasig) {
                        $counter = $this->ReadAttributeInteger('Phasen3Counter') + 1;
                        $this->WriteAttributeInteger('Phasen3Counter', $counter);
                        $this->WriteAttributeInteger('Phasen1Counter', 0);
                        $this->Log('info', "⏫ Zähler 3-phasig: {$counter} / {$this->ReadPropertyInteger('Phasen3Limit')}");
                        if ($counter >= $this->ReadPropertyInteger('Phasen3Limit')) {
                            if ($aktuell1phasig) {
                                GOeCharger_SetSinglePhaseCharging($goeID, false);
                                $this->Log('info', "🔁 Umschaltung auf 3-phasig ausgelöst");
                            }
                            $this->WriteAttributeInteger('Phasen3Counter', 0);
                        }
                    } else {
                        $this->WriteAttributeInteger('Phasen1Counter', 0);
                        $this->WriteAttributeInteger('Phasen3Counter', 0);
                    }
        
                    // Modus & Ladeleistung nur setzen, wenn nötig
                    $modusID = @IPS_GetObjectIDByIdent('accessStateV2', $goeID);
                    $wattID  = @IPS_GetObjectIDByIdent('Watt', $goeID);
        
                    $aktuellerModus = -1;
                    if ($modusID !== false && @IPS_VariableExists($modusID)) {
                        $aktuellerModus = GetValueInteger($modusID);
                    }
        
                    $aktuelleLeistung = -1;
                    if ($wattID !== false && @IPS_VariableExists($wattID)) {
                        $aktuelleLeistung = GetValueFloat($wattID);
                    }
        
                    // === Ladeleistung nur setzen, wenn Änderung > 50 W ===
                    if ($aktuelleLeistung < 0 || abs($aktuelleLeistung - $watt) > 50) {
                        GOeCharger_SetCurrentChargingWatt($goeID, $watt);
                        $this->Log('info', "✅ Ladeleistung gesetzt: {$watt} W");
        
                        // Nach Setzen der Leistung Modus sicherheitshalber aktivieren:
                        if ($watt > 0 && $aktuellerModus != 2) {
                            GOeCharger_setMode($goeID, 2); // 2 = Laden erzwingen
                            $this->Log('info', "⚡ Modus auf 'Laden' gestellt (2)");
                        }
                        if ($watt == 0 && $aktuellerModus != 1) {
                            GOeCharger_setMode($goeID, 1); // 1 = Bereit
                            $this->Log('info', "🔌 Modus auf 'Bereit' gestellt (1)");
                        }
                    } else {
                        $this->Log('debug', "🟡 Ladeleistung unverändert – keine Änderung notwendig");
                    }
                    // Prüfe: Leistung > 0, Modus ist "bereit" (1), Fahrzeug verbunden (Status 3 oder 4)
                    $status = GOeCharger_GetStatus($goeID); // 1=bereit, 2=lädt, 3=warte, 4=beendet
                    if ($watt > 0 && $aktuellerModus == 1 && in_array($status, [3, 4])) {
                        $msg = "⚠️ Ladeleistung gesetzt, aber die Ladung startet nicht automatisch.<br>
                                Bitte Fahrzeug einmal ab- und wieder anstecken, um die Ladung zu aktivieren!";
                        $this->SetLademodusStatus($msg);
                        $this->Log('warn', $msg);
                    }
                    break;
                default:
                    $this->Log('error', "❌ Unbekannter Wallbox-Typ '$typ' – keine Steuerung durchgeführt.");
                    break;
            }
        }


    private function SetLademodusStatus(string $text)
    {
        $varID = $this->GetIDForIdent('LademodusStatus');
        if ($varID !== false && @IPS_VariableExists($varID)) {
            SetValue($varID, $text);
        }
    }

    // --- Ladeverluste automatisch berechnen, wenn alle Werte vorhanden ---
    private function BerechneLadeverluste($socStart, $socEnde, $batteryCapacity, $wbEnergy)
    {
        $errors = [];
        if ($batteryCapacity <= 0) $errors[] = "Batteriekapazität";
        if ($socStart < 0 || $socEnde < 0) $errors[] = "SOC-Start/Ende";
        if ($wbEnergy <= 0) $errors[] = "Wallbox-Energie";
    
        if (count($errors) > 0) {
            $msg = "⚠️ Ladeverluste nicht berechnet: Fehlende/falsche Werte: " . implode(", ", $errors);
            $this->Log('warn', $msg);
            $this->SetLadeverlustInfo($msg);
            return;
        }
    
        $gespeichert = (($socEnde - $socStart) / 100) * $batteryCapacity;
        $verlustAbsolut = $wbEnergy - $gespeichert;
        $verlustProzent = $wbEnergy > 0 ? ($verlustAbsolut / $wbEnergy) * 100 : 0;
    
        // Profile prüfen/erstellen und Variablen registrieren
        $profil_kwh = "~Electricity";
        if (!IPS_VariableProfileExists($profil_kwh)) {
            IPS_CreateVariableProfile($profil_kwh, 2);
            IPS_SetVariableProfileDigits($profil_kwh, 2);
            IPS_SetVariableProfileText($profil_kwh, "", " kWh");
        }
        $profil_percent = "~Intensity.100";
        if (!IPS_VariableProfileExists($profil_percent)) {
            IPS_CreateVariableProfile($profil_percent, 2);
            IPS_SetVariableProfileDigits($profil_percent, 1);
            IPS_SetVariableProfileText($profil_percent, "", " %");
            IPS_SetVariableProfileValues($profil_percent, 0, 100, 1);
        }
        $this->RegisterVariableFloat('Ladeverlust_Absolut', 'Ladeverlust absolut (kWh)', $profil_kwh, 100);
        $this->RegisterVariableFloat('Ladeverlust_Prozent', 'Ladeverlust (%)', $profil_percent, 110);
    
        // Logging aktivieren (einmalig)
        $archiveID = @IPS_GetInstanceIDByName('Archiv', 0);
        if ($archiveID === false) $archiveID = 1;
        @AC_SetLoggingStatus($archiveID, $this->GetIDForIdent('Ladeverlust_Absolut'), true);
        @AC_SetLoggingStatus($archiveID, $this->GetIDForIdent('Ladeverlust_Prozent'), true);
    
        SetValue($this->GetIDForIdent('Ladeverlust_Absolut'), round($verlustAbsolut, 2));
        SetValue($this->GetIDForIdent('Ladeverlust_Prozent'), round($verlustProzent, 1));
    
        $msg = "Ladeverluste berechnet: absolut=" . round($verlustAbsolut, 2) . " kWh, prozentual=" . round($verlustProzent, 1) . " %";
        $this->Log('info', $msg);
        $this->SetLadeverlustInfo($msg);
    }

    private function SetLadeverlustInfo($msg)
    {
        $this->RegisterVariableString('Ladeverlust_Info', 'Ladeverlust Status', '', 120);
        SetValue($this->GetIDForIdent('Ladeverlust_Info'), $msg);
    }
    
    // Ladevorgang-Start
    private function LadevorgangStart($aktuellerSOC, $aktuellerWBZähler)
    {
        $this->WriteAttributeBoolean("ChargingActive", true);
        $this->WriteAttributeFloat("ChargeSOCStart", $aktuellerSOC);
        $this->WriteAttributeFloat("ChargeEnergyStart", $aktuellerWBZähler);
        $this->WriteAttributeInteger("ChargeStartTime", time());
    }
    
    // Ladevorgang-Ende
    private function LadevorgangEnde($aktuellerSOC, $aktuellerWBZähler, $batteryCapacity)
    {
        $socStart = $this->ReadAttributeFloat("ChargeSOCStart");
        $socEnde  = $aktuellerSOC;
        $energyStart = $this->ReadAttributeFloat("ChargeEnergyStart");
        $energyEnd   = $aktuellerWBZähler;
        $wbEnergy = $energyEnd - $energyStart;
    
        $this->Log('info', "LadevorgangEnde: SOC von $socStart auf $socEnde, Energie von $energyStart auf $energyEnd, WB-Energie $wbEnergy kWh");
        $this->BerechneLadeverluste($socStart, $socEnde, $batteryCapacity, $wbEnergy);
    
        // Reset Status
        $this->WriteAttributeBoolean("ChargingActive", false);
    }

    public function ZyklusLadevorgangCheck()
    {
        $goeID = $this->ReadPropertyInteger("GOEChargerID");
        $carSOCID = $this->ReadPropertyInteger("CarSOCID");
        $batteryCapacity = $this->ReadPropertyFloat("CarBatteryCapacity");
    
        // Robustheit: Fehlende Variablen abfangen!
        if ($goeID == 0 || $carSOCID == 0 || !@IPS_VariableExists($carSOCID)) {
            $this->Log('warn', "Ladeverluste nicht berechnet, da GO-e oder Fahrzeug-SOC-Variable fehlt!");
            $this->SetLadeverlustInfo("⚠️ Ladeverluste nicht berechnet, da GO-e oder Fahrzeug-SOC-Variable fehlt!");
            return;
        }
    
        $status = GOeCharger_GetStatus($goeID); // 2/4=verbunden, 1/0=getrennt
        $aktuellerSOC = GetValue($carSOCID);
        $aktuellerWBZähler = GOeCharger_GetEnergyTotal($goeID); // in kWh
    
        if (in_array($status, [2, 4])) {
            if (!$this->ReadAttributeBoolean("ChargingActive")) {
                // Ladefenster startet
                $this->Log('info', "Ladevorgang gestartet: SOC={$aktuellerSOC}, WB-Zähler={$aktuellerWBZähler} kWh");
                $this->LadevorgangStart($aktuellerSOC, $aktuellerWBZähler);
            }
        } else {
            if ($this->ReadAttributeBoolean("ChargingActive")) {
                // Ladefenster endet
                $this->Log('info', "Ladevorgang beendet: SOC={$aktuellerSOC}, WB-Zähler={$aktuellerWBZähler} kWh");
                $this->LadevorgangEnde($aktuellerSOC, $aktuellerWBZähler, $batteryCapacity);
            }
        }
    }

    private function GetNormWert(string $idProp, string $einheitProp, string $invertProp, string $name = ""): float
    {
        $wert = 0;
        $vid = $this->ReadPropertyInteger($idProp);
        if ($vid > 0 && @IPS_VariableExists($vid)) {
            $wert = GetValue($vid);
            if ($this->ReadPropertyBoolean($invertProp)) {
                $wert *= -1;
            }
            if ($this->ReadPropertyString($einheitProp) == "kW") {
                $wert *= 1000;
            }
        } else {
            if ($name != "") {
                $this->Log('debug', "Hinweis: Keine $name-Variable gewählt, Wert wird als 0 angesetzt.");
            }
        }
        return $wert;
    }

    private function UpdateWallboxStatusText()
    {
        $goeID = $this->ReadPropertyInteger('GOEChargerID');
        if ($goeID == 0) {
            $text = '<span style="color:gray;">Keine GO-e Instanz gewählt</span>';
            SetValue($this->GetIDForIdent('WallboxStatusText'), $text);
            return;
        }
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
                $this->Log('warn', "Unbekannter Status vom GO-e Charger: $status");
        }
        SetValue($this->GetIDForIdent('WallboxStatusText'), $text);
    }


    private function Log(string $level, string $message)
    {
        // Unterstützte Level: debug, info, warn, warning, error
        $prefix = "PVWM";
        $normalized = strtolower(trim($level));

        // Unerwünschte/zu kurze Nachrichten unterdrücken
        if (in_array(strtolower(trim($message)), ['warn', 'debug', 'info', ''])) {
            return;
        }

        switch ($normalized) {
            case 'debug':
                if ($this->ReadPropertyBoolean('DebugLogging')) {
                    IPS_LogMessage("{$prefix} [DEBUG]", $message);
                    $this->SendDebug("DEBUG", $message, 0);
                }
                break;
            case 'warn':
            case 'warning':
                IPS_LogMessage("{$prefix} [WARN]", $message);
                break;
            case 'error':
                IPS_LogMessage("{$prefix} [ERROR]", $message);
                break;
            case 'info':
            case '':
            case null:
                IPS_LogMessage("{$prefix}", $message);
                break;
            default:
                IPS_LogMessage("{$prefix}", $message);
                break;
        }
    }
}
