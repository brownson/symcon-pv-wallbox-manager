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
        $this->RegisterPropertyInteger('HausverbrauchID', 0); // Hausverbrauch in Watt
        $this->RegisterPropertyInteger('BatterieladungID', 0); // Batterie-Lade-/Entladeleistung in Watt
        $this->RegisterPropertyInteger('NetzeinspeisungID', 0); // Einspeisung/Bezug ins Netz (positiv/negativ)

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

        // Fahrzeug-Erkennung & Steuerung
        $this->RegisterPropertyBoolean('NurMitFahrzeug', true); // Ladung nur wenn Fahrzeug verbunden
        $this->RegisterPropertyBoolean('UseCarSOC', false); // Fahrzeug-SOC berücksichtigen
        $this->RegisterPropertyInteger('CarSOCID', 0); // Variable für aktuellen SOC des Fahrzeugs
        $this->RegisterPropertyFloat('CarSOCFallback', 20); // Fallback-SOC wenn keine Variable verfügbar
        $this->RegisterPropertyInteger('CarTargetSOCID', 0); // Ziel-SOC Variable
        $this->RegisterPropertyFloat('CarTargetSOCFallback', 80); // Fallback-Zielwert für SOC
        $this->RegisterPropertyFloat('CarBatteryCapacity', 52.0); // Batteriekapazität des Fahrzeugs in kWh

        // Interne Status-Zähler für Phasenumschaltung
        $this->RegisterAttributeInteger('Phasen1Counter', 0);
        $this->RegisterAttributeInteger('Phasen3Counter', 0);

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

        // Timer für regelmäßige Berechnung
        $this->RegisterTimer('PVUeberschuss_Berechnen', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateCharging", 0);');
        $this->RegisterTimer('ZyklusLadevorgangCheck', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "ZyklusLadevorgangCheck", 0);');
        
        $this->RegisterPropertyBoolean('ModulAktiv', true);
    }
    
    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $interval = $this->ReadPropertyInteger('RefreshInterval');
        $goeID    = $this->ReadPropertyInteger('GOEChargerID');
        $pvID     = $this->ReadPropertyInteger('PVErzeugungID');
        
        // Timer nur aktivieren, wenn GO-e und PV-Erzeugung konfiguriert
        if (!$this->ReadPropertyBoolean('ModulAktiv')) {
        // Deaktiviert: Alle Timer aus
        $this->SetTimerInterval('PVUeberschuss_Berechnen', 0);
        $this->SetTimerInterval('ZyklusLadevorgangCheck', 0);
        $this->SetLademodusStatus("⚠️ Modul ist deaktiviert. Keine Aktionen.");
        return;
        }
    
        // Timer nur aktivieren, wenn GO-e und PV-Erzeugung konfiguriert
        if ($goeID > 0 && $pvID > 0 && $interval > 0) {
            $this->SetTimerInterval('PVUeberschuss_Berechnen', $interval * 1000);
            $this->SetTimerInterval('ZyklusLadevorgangCheck', max($interval, 30) * 1000);
        } else {
            $this->SetTimerInterval('PVUeberschuss_Berechnen', 0);
            $this->SetTimerInterval('ZyklusLadevorgangCheck', 0);
        }
    }

    public function RequestAction($ident, $value)
    {
        // UX-Prüfung: Funktion abbrechen, wenn "Nur laden, wenn Fahrzeug verbunden" aktiv und kein Fahrzeug verbunden
        if ($this->ReadPropertyBoolean('NurMitFahrzeug') && !in_array(GOeCharger_GetStatus($this->ReadPropertyInteger('GOEChargerID')), [2,4])) {
            $this->SetLademodusStatus("⚠️ Button-Funktion nicht ausführbar: Kein Fahrzeug verbunden (oder 'Nur laden, wenn Fahrzeug verbunden' ist aktiv).");
            IPS_LogMessage("PVWallboxManager", "⚠️ Aktion abgebrochen: Kein Fahrzeug verbunden und 'Nur laden, wenn Fahrzeug verbunden' aktiv.");
            return;
        }
        
        switch ($ident) {
            case 'ManuellVollladen':
                SetValue($this->GetIDForIdent($ident), $value);
                if ($value) {
                    SetValue($this->GetIDForIdent('PV2CarModus'), false);
                    SetValue($this->GetIDForIdent('ZielzeitladungPVonly'), false);
                    $this->SetLademodusStatus('Manueller Volllademodus aktiv');
                } else {
                    $this->SetLademodusStatus('Kein Fahrzeug verbunden – Laden deaktiviert');
                }
                break;
    
            case 'PV2CarModus':
                SetValue($this->GetIDForIdent($ident), $value);
                if ($value) {
                    SetValue($this->GetIDForIdent('ManuellVollladen'), false);
                    SetValue($this->GetIDForIdent('ZielzeitladungPVonly'), false);
                    $this->SetLademodusStatus('PV2Car Modus aktiv');
                } else {
                    $this->SetLademodusStatus('Kein Fahrzeug verbunden – Laden deaktiviert');
                }
                break;
    
            case 'ZielzeitladungPVonly':
                SetValue($this->GetIDForIdent($ident), $value);
                if ($value) {
                    SetValue($this->GetIDForIdent('ManuellVollladen'), false);
                    SetValue($this->GetIDForIdent('PV2CarModus'), false);
                    $this->SetLademodusStatus('Zielzeitladung PV-optimiert aktiv');
                } else {
                    $this->SetLademodusStatus('Kein Fahrzeug verbunden – Laden deaktiviert');
                }
                break;
    
            case 'TargetTime':
                SetValue($this->GetIDForIdent($ident), $value);
                break;
        }
        // Nach jeder Aktion immer den Hauptalgorithmus aufrufen:
        $this->UpdateCharging();
    }
    
    public function UpdateCharging()
    {
        $this->SendDebug("Update", "Starte Berechnung...", 0);

        // Property-Werte nur einmal auslesen
        $goeID = $this->ReadPropertyInteger('GOEChargerID');
        $status = GOeCharger_GetStatus($goeID); // Rückgabe: 1=bereit,2=lädt,3=warte,4=beendet
        $aktuellerModus = GOeCharger_getMode($goeID); // Rückgabe: 1=bereit,2=lädt,3=warte,4=beendet

        // --- ZUERST: Fahrzeugstatus-Prüfung! ---
        if ($this->ReadPropertyBoolean('NurMitFahrzeug')) {
            if (!in_array($status, [2, 4])) { // KEIN Fahrzeug verbunden!
                // --- UX-Reset der Buttons: Alle Lademodi deaktivieren, falls Fahrzeug abgesteckt ---
                if (GetValue($this->GetIDForIdent('ManuellVollladen'))) {
                    SetValue($this->GetIDForIdent('ManuellVollladen'), false);
                }
                if (GetValue($this->GetIDForIdent('PV2CarModus'))) {
                    SetValue($this->GetIDForIdent('PV2CarModus'), false);
                }
                if (GetValue($this->GetIDForIdent('ZielzeitladungPVonly'))) {
                    SetValue($this->GetIDForIdent('ZielzeitladungPVonly'), false);
                }
                if ($aktuellerModus != 1) {
                    GOeCharger_setMode($goeID, 1);
                    IPS_LogMessage("PVWallboxManager", "Kein Fahrzeug verbunden – Modus auf 1 (Nicht laden) gestellt!");
                }
                $this->SetLadeleistung(0);
                $this->SetLademodusStatus("Kein Fahrzeug verbunden – Laden deaktiviert");
                SetValue($this->GetIDForIdent('PV_Ueberschuss'), 0.0);
                return; // *** GANZ WICHTIG: Sofort beenden! ***
            }
        }
        // --- MODUS-WEICHE (Prio: Manuell > Zielzeit > PV2Car > PV-Überschuss/Hysterese) ---
        if (GetValue($this->GetIDForIdent('ManuellVollladen'))) {
            $this->SetLadeleistung($this->GetMaxLadeleistung());
            $this->SetLademodusStatus("Manueller Volllademodus aktiv");
            return;
        }
        if (GetValue($this->GetIDForIdent('ZielzeitladungPVonly'))) {
            $this->LogikZielzeitladung();
            return;
        }
        if (GetValue($this->GetIDForIdent('PV2CarModus'))) {
            $ueberschuss = $this->BerechnePVUeberschuss();
            $anteil = $this->ReadPropertyInteger('PVAnteilAuto');
            $ladeWatt = min(max(round($ueberschuss * ($anteil / 100.0)), 0), $this->GetMaxLadeleistung());
            $this->SetLadeleistung($ladeWatt);
            $this->SetLademodusStatus("PV2Car: {$anteil}% vom Überschuss ({$ladeWatt} W)");
            return;
        }
        // --- Standard: Nur PV-Überschuss mit Start/Stop-Hysterese ---
        $this->LogikPVPureMitHysterese();
    }

    // --- Hilfsfunktion: PV-Überschuss berechnen ---
    private function BerechnePVUeberschuss(): float
    {
        $pvID   = $this->ReadPropertyInteger("PVErzeugungID");
        $hausID = $this->ReadPropertyInteger("HausverbrauchID");
        $battID = $this->ReadPropertyInteger("BatterieladungID");
        $goeID  = $this->ReadPropertyInteger("GOEChargerID");

        $pv  = GetValue($pvID);

        // Batterieladung (invertierbar):
        $batt = 0;
        if ($battID > 0 && @IPS_VariableExists($battID)) {
            $batt = GetValue($battID);
            if ($this->ReadPropertyBoolean('InvertBatterieladung')) {
                $batt *= -1;
            }
        } else {
            IPS_LogMessage("PVWallboxManager", "Hinweis: Keine Batterieladung-Variable gewählt, Wert wird als 0 angesetzt.");
        }
        
        // Hausverbrauch (invertierbar):
        $haus = 0;
        if ($hausID > 0 && @IPS_VariableExists($hausID)) {
            $haus = GetValue($hausID);
            if ($this->ReadPropertyBoolean('InvertHausverbrauch')) {
                $haus *= -1;
            }
        } else {
            IPS_LogMessage("PVWallboxManager", "Hinweis: Keine Hausverbrauch-Variable gewählt, Wert wird als 0 angesetzt.");
        }

        // Netzeinspeisung (invertierbar):
        $netzID = $this->ReadPropertyInteger('NetzeinspeisungID');
        $netz = 0;
        if ($netzID > 0 && @IPS_VariableExists($netzID)) {
            $netz = GetValue($netzID);
            if ($this->ReadPropertyBoolean('InvertNetzeinspeisung')) {
                $netz *= -1;
            }
        } else {
            IPS_LogMessage("PVWallboxManager", "Hinweis: Keine Netzeinspeisung-Variable gewählt, Wert wird als 0 angesetzt.");
        }
        
        $ladeleistung = GOeCharger_GetPowerToCar($goeID);
        $ueberschuss = $pv - $haus - $batt;

        // Optional: Dynamischer Puffer
        $puffer = 1.0;
        if ($this->ReadPropertyBoolean('DynamischerPufferAktiv')) {
            if ($ueberschuss < 2000) $puffer = 0.80;
            elseif ($ueberschuss < 4000) $puffer = 0.85;
            elseif ($ueberschuss < 6000) $puffer = 0.90;
            else $puffer = 0.93;
            $alterUeberschuss = $ueberschuss;
            $ueberschuss = $ueberschuss * $puffer;
            IPS_LogMessage(
            "PVWallboxManager",
            "🧮 Dynamischer Pufferfaktor angewendet: {$puffer} – Überschuss vorher: " . round($alterUeberschuss) . " W, jetzt: " . round($ueberschuss) . " W"
        );
        $this->SendDebug(
            "Puffer",
            "Dynamischer Puffer: {$puffer} (vorher: " . round($alterUeberschuss) . " W, jetzt: " . round($ueberschuss) . " W)",
            0
        );
        }
        // Auf Ganzzahl runden und negatives abfangen
        $ueberschuss = max(0, round($ueberschuss));
        
        // In Variable schreiben (immer als ganzzahlig und >= 0)
        SetValue($this->GetIDForIdent('PV_Ueberschuss'), $ueberschuss);

        // Rückgabewert (immer >= 0)
        return $ueberschuss;
    }

    // --- Hysterese-Logik für Standardmodus ---
    private function LogikPVPureMitHysterese()
    {
        $minStart = $this->ReadPropertyInteger('MinLadeWatt');
        $minStop  = $this->ReadPropertyInteger('MinStopWatt');
        $ueberschuss = $this->BerechnePVUeberschuss();
        $goeID = $this->ReadPropertyInteger('GOEChargerID');
        $ladeModusID = @IPS_GetObjectIDByIdent('accessStateV2', $goeID);
        $ladeModus = ($ladeModusID !== false && @IPS_VariableExists($ladeModusID)) ? GetValueInteger($ladeModusID) : 0;

        if ($ladeModus == 2) { // Lädt bereits
            if ($ueberschuss <= $minStop) {
                $this->SetLadeleistung(0);
                $this->SetLademodusStatus("PV-Überschuss unter Stop-Schwelle ({$ueberschuss} W ≤ {$minStop} W) – Wallbox gestoppt");
            } else {
                $this->SetLadeleistung($ueberschuss);
                $this->SetLademodusStatus("PV-Überschuss: Bleibt an ({$ueberschuss} W)");
            }
        } else { // Lädt NICHT
            if ($ueberschuss >= $minStart) {
                $this->SetLadeleistung($ueberschuss);
                $this->SetLademodusStatus("PV-Überschuss über Start-Schwelle ({$ueberschuss} W ≥ {$minStart} W) – Wallbox startet");
            } else {
                $this->SetLadeleistung(0);
                $this->SetLademodusStatus("PV-Überschuss zu niedrig ({$ueberschuss} W) – bleibt aus");
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
        $this->SetLademodusStatus("Zielzeitladung: Ziel-SOC erreicht – keine Ladung mehr erforderlich");
        IPS_LogMessage("PVWallboxManager", "Zielzeitladung: Ziel-SOC erreicht – keine Ladung mehr erforderlich");
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
        // Volle Leistung – Netzbezug erlaubt
        $this->SetLadeleistung($maxWatt);
        $this->SetLademodusStatus("Zielzeitladung: Maximale Leistung (Netzbezug möglich, {$fehlendeKWh} kWh fehlen)");
        IPS_LogMessage("PVWallboxManager", "Zielzeitladung: Netzbezug erlaubt, maximale Leistung {$maxWatt} W – {$fehlendeKWh} kWh fehlen");
    } else {
        // Nur PV-Überschuss – Netzbezug vermeiden
        $this->SetLadeleistung($pvUeberschuss);
        $bisWann = date('H:i', $forceTime);
        $this->SetLademodusStatus("Zielzeitladung: Nur PV-Überschuss bis $bisWann Uhr – {$fehlendeKWh} kWh fehlen ({$restStunden} h nötig)");
        IPS_LogMessage("PVWallboxManager", "Zielzeitladung: Nur PV-Überschuss – noch {$fehlendeKWh} kWh, Restzeit ca. {$restStunden} h, Umschaltung um $bisWann Uhr");
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
                    IPS_LogMessage("PVWallboxManager", "⚠️ go-e Charger Instanz nicht gefunden (ID: $goeID)");
                    return;
                }
                
                // *** Korrektur: Counterlogik nur bei > 0 W ***
                if ($watt > 0) {
                    // ...Counter für Phasenumschaltung wie gehabt...
                    // Umschalten bei Bedarf, Counter hochzählen
                } else {
                    // *** Counter zurücksetzen, keine Umschaltung ausführen ***
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
                    IPS_LogMessage("PVWallboxManager", "⏬ Zähler 1-phasig: {$counter} / {$this->ReadPropertyInteger('Phasen1Limit')}");
                    if ($counter >= $this->ReadPropertyInteger('Phasen1Limit')) {
                        if (!$aktuell1phasig) { // wirklich erst schalten, wenn noch nicht 1-phasig!
                            GOeCharger_SetSinglePhaseCharging($goeID, true);
                            IPS_LogMessage("PVWallboxManager", "🔁 Umschaltung auf 1-phasig ausgelöst");
                        }
                        $this->WriteAttributeInteger('Phasen1Counter', 0);
                    }
                } elseif ($watt > $this->ReadPropertyInteger('Phasen3Schwelle') && $aktuell1phasig) {
                    $counter = $this->ReadAttributeInteger('Phasen3Counter') + 1;
                    $this->WriteAttributeInteger('Phasen3Counter', $counter);
                    $this->WriteAttributeInteger('Phasen1Counter', 0);
                    IPS_LogMessage("PVWallboxManager", "⏫ Zähler 3-phasig: {$counter} / {$this->ReadPropertyInteger('Phasen3Limit')}");
                    if ($counter >= $this->ReadPropertyInteger('Phasen3Limit')) {
                        if ($aktuell1phasig) { // wirklich erst schalten, wenn noch nicht 3-phasig!
                            GOeCharger_SetSinglePhaseCharging($goeID, false);
                            IPS_LogMessage("PVWallboxManager", "🔁 Umschaltung auf 3-phasig ausgelöst");
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

                $minStopWatt = $this->ReadPropertyInteger('MinStopWatt');

                // === Ladeleistung nur setzen, wenn Änderung > 50 W ===
                if ($aktuelleLeistung < 0 || abs($aktuelleLeistung - $watt) > 50) {
                    GOeCharger_SetCurrentChargingWatt($goeID, $watt);
                    IPS_LogMessage("PVWallboxManager", "✅ Ladeleistung gesetzt: {$watt} W");
                } else {
                    IPS_LogMessage("PVWallboxManager", "🟡 Ladeleistung unverändert – keine Änderung notwendig");
                }
                break;

            default:
                IPS_LogMessage("PVWallboxManager", "❌ Unbekannter Wallbox-Typ '$typ' – keine Steuerung durchgeführt.");
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
            IPS_LogMessage("PVWallboxManager", $msg);
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
        IPS_LogMessage("PVWallboxManager", $msg);
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
            $this->SetLadeverlustInfo("⚠️ Ladeverluste nicht berechnet, da GO-e oder Fahrzeug-SOC-Variable fehlt!");
            return;
        }
    
        $status = GOeCharger_GetStatus($goeID); // 2/4=verbunden, 1/0=getrennt
        $aktuellerSOC = GetValue($carSOCID);
        $aktuellerWBZähler = GOeCharger_GetEnergyTotal($goeID); // in kWh
    
        if (in_array($status, [2, 4])) {
            if (!$this->ReadAttributeBoolean("ChargingActive")) {
                // Ladefenster startet
                $this->LadevorgangStart($aktuellerSOC, $aktuellerWBZähler);
            }
        } else {
            if ($this->ReadAttributeBoolean("ChargingActive")) {
                // Ladefenster endet
                $this->LadevorgangEnde($aktuellerSOC, $aktuellerWBZähler, $batteryCapacity);
            }
        }
    }

    protected function GetInvertedValue($varIdProperty, $invertProperty)
    {
        $varID = $this->ReadPropertyInteger($varIdProperty);
        if ($varID > 0 && @IPS_VariableExists($varID)) {
            $value = GetValueFloat($varID);
            if ($this->ReadPropertyBoolean($invertProperty)) {
                $value *= -1;
            }
            return $value;
        }
        return 0.0;
    }
}
