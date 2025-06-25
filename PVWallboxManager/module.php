<?php

/**
 * PVWallboxManager – Intelligente PV-Überschussladung für den GO-eCharger
 *
 * Dieses Modul steuert dynamisch die Ladeleistung einer GO-e Wallbox basierend auf PV-Überschuss,
 * Hausverbrauch und optionalen Fahrzeugdaten. Unterstützt werden Phasenumschaltung, Ladezeitplanung
 * sowie flexible Lademodi (PV2Car, Zielzeitladung, manuelles Vollladen).
 *
 * Voraussetzungen:
 * - IP-Symcon 8.x oder höher
 * - GO-eCharger V3 oder V4 mit lokal erreichbarer Instanz und aktivierter API
 * - PV-Erzeugung, Hausverbrauch, Batterieladung als Variablen verfügbar.
 */

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

        // Timer für regelmäßige Berechnung
        $this->RegisterTimer('PVUeberschuss_Berechnen', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateCharging", 0);');


    }
    
    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $interval = $this->ReadPropertyInteger('RefreshInterval');
        $this->SetTimerInterval('PVUeberschuss_Berechnen', $interval * 1000);
    }

    public function UpdateCharging()
    {
        $this->SendDebug("Update", "Starte Berechnung...", 0);

        // Property-Werte nur einmal auslesen
        $goeID = $this->ReadPropertyInteger('GOEChargerID');
        $status = GOeCharger_GetStatus($goeID); // Rückgabe: 1=bereit,2=lädt,3=warte,4=beendet
        $aktuellerModus = GOeCharger_getMode($goeID); // Rückgabe: 1=bereit,2=lädt,3=warte,4=beendet

        // Fahrzeugprüfung (nur wenn Property gesetzt)
        if ($this->ReadPropertyBoolean('NurMitFahrzeug')) {
            if (!in_array($status, [2, 4])) {
                $this->SendDebug("Fahrzeugstatus", "Kein Fahrzeug verbunden (Status: {$status}), setze Modus 1 und beende Skript.", 0);
                if ($aktuellerModus != 1) {
                    GOeCharger_setMode($goeID, 1);
                }
                $this->SetLadeleistung(0); // Immer auf 0
                $this->SetLademodusStatus("Kein Fahrzeug verbunden – Laden deaktiviert");
                SetValue($this->GetIDForIdent('PV_Ueberschuss'), 0.0);
                return;
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
        $pvID = $this->ReadPropertyInteger("PVErzeugungID");
        $hausID = $this->ReadPropertyInteger("HausverbrauchID");
        $battID = $this->ReadPropertyInteger("BatterieladungID");
        $goeID  = $this->ReadPropertyInteger("GOEChargerID");
        $pv  = GetValue($pvID);
        $haus = GetValue($hausID);
        $batt = GetValue($battID);
        $ladeleistung = GOeCharger_GetPowerToCar($goeID);
        $ueberschuss = $pv - $haus - $batt + $ladeleistung;

        // Optional: Dynamischer Puffer
        $puffer = 1.0;
        if ($this->ReadPropertyBoolean('DynamischerPufferAktiv')) {
            if ($ueberschuss < 2000) $puffer = 0.80;
            elseif ($ueberschuss < 4000) $puffer = 0.85;
            elseif ($ueberschuss < 6000) $puffer = 0.90;
            else $puffer = 0.93;
            $ueberschuss = round($ueberschuss * $puffer);
        }
        SetValue($this->GetIDForIdent('PV_Ueberschuss'), $ueberschuss);
        return max(0, $ueberschuss);
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

    // --- Zielzeitladung-Logik: Dummy ---
    private function LogikZielzeitladung()
    {
        // Deine Zielzeit-Logik hier einbauen oder nach Bedarf erweitern!
        $this->SetLademodusStatus("[Zielzeitladung-Logik folgt noch]");
        $this->SetLadeleistung(0); // Dummy: Setzt Ladeleistung auf 0
    } 

    public function RequestAction($ident, $value)
    {
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

    private function GetMaxLadeleistung(): int
    {
        $phasen = $this->ReadPropertyInteger('Phasen');
        $maxAmp = $this->ReadPropertyInteger('MaxAmpere');
        return $phasen * 230 * $maxAmp;
    }
    
    public function BerechneLadung()
    {
        // === Auto getrennt → manuellen Volllademodus zurücksetzen ===
        $goeID = $this->ReadPropertyInteger('GOEChargerID');
        if (@IPS_InstanceExists($goeID)) {
            $statusVarID = @IPS_GetObjectIDByIdent('carStatus', $goeID);
            if ($statusVarID !== false && @IPS_VariableExists($statusVarID)) {
                $status = GetValueInteger($statusVarID);
                if (!in_array($status, [2, 4])) {
                    if (GetValue($this->GetIDForIdent('ManuellVollladen'))) {
                        SetValue($this->GetIDForIdent('ManuellVollladen'), false);
                        IPS_LogMessage("PVWallboxManager", "🔌 Fahrzeug getrennt – manueller Volllademodus deaktiviert");
                    }
                    if (GetValue($this->GetIDForIdent('PV2CarModus')) || GetValue($this->GetIDForIdent('ZielzeitladungPVonly'))) {
                        SetValue($this->GetIDForIdent('PV2CarModus'), false);
                        SetValue($this->GetIDForIdent('ZielzeitladungPVonly'), false);
                        IPS_LogMessage("PVWallboxManager", "🚗 Fahrzeug getrennt – PV2Car- und Zielzeitladung deaktiviert");
                    }
                }
            }
        }
        // Prüfen ob manueller Modus aktiv ist
        if (GetValue($this->GetIDForIdent('ManuellVollladen'))) {
            IPS_LogMessage("PVWallboxManager", "🚨 Manueller Lademodus aktiv – maximale Ladeleistung wird erzwungen");
            $phasen = $this->ReadPropertyInteger('Phasen');
            $maxAmp = $this->ReadPropertyInteger('MaxAmpere');
            $maxWatt = $phasen * 230 * $maxAmp;
            $this->SetLadeleistung($maxWatt);
            return;
        }
        // Beispiel: PV-Überschuss holen (optional)
        if ($this->ReadPropertyBoolean('UseCarSOC')) {
            // Aktuellen Fahrzeug-SOC holen (Variable oder Fallback)
            $carSOCID = $this->ReadPropertyInteger('CarSOCID');
            if (IPS_VariableExists($carSOCID) && $carSOCID > 0) {
                $carSOC = GetValue($carSOCID);
            } else {
                $this->SendDebug('Info', 'UseCarSOC aktiv, aber kein gültiger Fahrzeug-SOC verfügbar. Abbruch.', 0);
                return;
            }

            // Ziel-SOC holen (Variable oder Fallback)
            $carTargetSOCID = $this->ReadPropertyInteger('CarTargetSOCID');
            if (IPS_VariableExists($carTargetSOCID) && $carTargetSOCID > 0) {
                $targetSOC = GetValue($carTargetSOCID);
            } else {
                $targetSOC = $this->ReadPropertyFloat('CarTargetSOCFallback');
            }

            // Debug-Ausgabe
            $this->SendDebug('Fahrzeug-SOC', $carSOC, 1);
            $this->SendDebug('Ziel-SOC', $targetSOC, 1);

        // Vergleich: Ist Ziel erreicht?
            if ($carSOC >= $targetSOC) {
                $this->SendDebug('Ladeentscheidung', 'Ziel-SOC erreicht – kein Laden erforderlich', 0);
                return;
            }

        // Hier später: Ladeplanung basierend auf SOC
            $this->SendDebug('Ladeentscheidung', 'Laden erforderlich – SOC unter Zielwert', 0);
        } else {
            $this->SendDebug('Info', 'Fahrzeugdaten werden ignoriert – reine PV-Überschussladung aktiv.', 0);
        }
        // Hier kann nun die Ladeleistungsberechnung / Wallbox-Steuerung folgen
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

                // === Laden deaktivieren ===
                //if ($watt < $minStopWatt) {
                //    if ($aktuellerModus !== 1) {
                //        GOeCharger_setMode($goeID, 1);
                //        IPS_LogMessage("PVWallboxManager", "🛑 Modus auf 1 (Nicht laden) gesetzt – Ladeleistung: {$watt} W");
                //    } else {
                //        IPS_LogMessage("PVWallboxManager", "🟡 Modus bereits 1 (Nicht laden) – keine Umschaltung notwendig");
                //    }
                //    return;
                //}

                // === Laden aktivieren ===
                if ($aktuellerModus !== 2) {
                    GOeCharger_setMode($goeID, 2);
                    IPS_LogMessage("PVWallboxManager", "⚡ Modus auf 2 (Immer laden) gesetzt");
                } else {
                    IPS_LogMessage("PVWallboxManager", "🟡 Modus bereits 2 (Immer laden) – keine Umschaltung notwendig");
                }

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
}
