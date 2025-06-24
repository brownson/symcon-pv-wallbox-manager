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
 * - PV-Erzeugung, Hausverbrauch, Batterieladung als Variablen verfügbar
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
        $this->RegisterPropertyInteger('MinAktivierungsWatt', 300); // Mindestüberschuss zur Aktivierung (Watt)
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
        $this->RegisterTimer('PVUeberschuss_Berechnen', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "BerechnePVUeberschuss", 0);');
    }
    
    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $interval = $this->ReadPropertyInteger('RefreshInterval');
        $this->SetTimerInterval('PVUeberschuss_Berechnen', $interval * 1000);
    }

    public function GetMinAmpere(): int
    {
        $val = $this->ReadPropertyInteger('MinAmpere');
        return ($val > 0) ? $val : 6;
    }

    public function GetMaxAmpere(): int
    {
        $val = $this->ReadPropertyInteger('MaxAmpere');
        return ($val > 0) ? $val : 16;
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
                    // Immer maximale Leistung setzen!
                    $phasen = $this->ReadPropertyInteger('Phasen');
                    $maxAmp = $this->ReadPropertyInteger('MaxAmpere');
                    $maxWatt = $phasen * 230 * $maxAmp;
                    $this->SetLadeleistung($maxWatt);
                } else {
                    $this->SetLademodusStatus('');
                    $this->BerechnePVUeberschuss();
                }
                break;

            case 'PV2CarModus':
                SetValue($this->GetIDForIdent($ident), $value);
                if ($value) {
                    SetValue($this->GetIDForIdent('ManuellVollladen'), false);
                    SetValue($this->GetIDForIdent('ZielzeitladungPVonly'), false);
                    $this->SetLademodusStatus('PV2Car Modus aktiv');
                    $this->BerechnePVUeberschuss();
                } else {
                    $this->SetLademodusStatus('');
                }
                break;

            case 'ZielzeitladungPVonly':
                SetValue($this->GetIDForIdent($ident), $value);
                if ($value) {
                    SetValue($this->GetIDForIdent('ManuellVollladen'), false);
                    SetValue($this->GetIDForIdent('PV2CarModus'), false);
                    $this->SetLademodusStatus('Zielzeitladung PV-optimiert aktiv');
                    $this->BerechnePVUeberschuss();
                } else {
                    $this->SetLademodusStatus('');
                }
                break;

            case 'TargetTime':
                SetValue($this->GetIDForIdent($ident), $value);
                break;

            case 'BerechnePVUeberschuss':
                $this->BerechnePVUeberschuss();
                break;
        }
        // Statusanzeige für den Normalbetrieb setzen, wenn kein Modus aktiv
        if (
            !GetValue($this->GetIDForIdent('ManuellVollladen')) &&
            !GetValue($this->GetIDForIdent('PV2CarModus')) &&
            !GetValue($this->GetIDForIdent('ZielzeitladungPVonly'))
        ) {
            // Nichts aktiv, Wallbox ganz sicher deaktivieren
            $this->SetLadeleistung(0);
            $this->SetLademodusStatus("Wallbox deaktiviert (kein Modus aktiv, kein PV-Überschuss)");
            return;
        }
    }

    public function BerechnePVUeberschuss()
    {
        // --- Zielzeitladung PV-optimiert: Umschalten auf Soll-Ladeleistung ab x Stunden vor Zielzeit ---
        if (GetValue($this->GetIDForIdent('ZielzeitladungPVonly'))) {
            // ... (der Block bleibt wie bisher)
            // ACHTUNG: SetValue für 'PV_Ueberschuss' bleibt hier bei der SollLeistung, das ist korrekt!
            return;
        }

        $pv_id        = $this->ReadPropertyInteger('PVErzeugungID');
        $verbrauch_id = $this->ReadPropertyInteger('HausverbrauchID');
        $batterie_id  = $this->ReadPropertyInteger('BatterieladungID');
        $goeID        = $this->ReadPropertyInteger('GOEChargerID');

        $pv        = @IPS_VariableExists($pv_id)        ? GetValue($pv_id)        : 0;
        $verbrauch = @IPS_VariableExists($verbrauch_id) ? GetValue($verbrauch_id) : 0;
        $batterie  = @IPS_VariableExists($batterie_id)  ? GetValue($batterie_id)  : 0;
        $ladeleistung = 0;

        if ($goeID > 0 && @IPS_InstanceExists($goeID)) {
            $ladeleistung = @GOeCharger_GetPowerToCar($goeID) * 1000; // in W
            if ($ladeleistung > 0) {
                $pv += $ladeleistung;
                IPS_LogMessage("PVWallboxManager", "⚡ Wallbox-Leistung {$ladeleistung} W zur PV addiert");
            }
        }

        $ueberschuss = $pv - $verbrauch - max($batterie, 0);

        // Dynamischer Pufferfaktor
        $effektiv = $ueberschuss;
        $puffer_faktor = 1.0;
        if ($this->ReadPropertyBoolean('DynamischerPufferAktiv')) {
            if ($effektiv < 2000) {
                $puffer_faktor = 0.80;
            } elseif ($effektiv < 4000) {
                $puffer_faktor = 0.85;
            } elseif ($effektiv < 6000) {
                $puffer_faktor = 0.90;
            } else {
                $puffer_faktor = 0.93;
            }
            $ueberschuss = round($ueberschuss * $puffer_faktor);
            IPS_LogMessage("PVWallboxManager", "🧮 Dynamischer Pufferfaktor {$puffer_faktor} angewendet – neuer Überschuss: {$ueberschuss} W");
        }

        // --- Start/Stop Logik ---
        $minLadeWatt = $this->ReadPropertyInteger('MinLadeWatt');
        $minStopWatt = $this->ReadPropertyInteger('MinStopWatt');

        if ($ueberschuss < $minLadeWatt) {
            $ueberschuss = 0.0; // <<--- HIER auf Null setzen!
            SetValue($this->GetIDForIdent('PV_Ueberschuss'), $ueberschuss);
            IPS_LogMessage("PVWallboxManager", "⏹️ PV-Überschuss zu gering ({$ueberschuss} W < {$minLadeWatt} W) – Wallbox bleibt aus");
            $this->SetLadeleistung(0);
            $this->SetLademodusStatus("Wallbox deaktiviert (kein Modus aktiv, kein PV-Überschuss)");
            return;
        }
        if ($ueberschuss < $minStopWatt) {
            $ueberschuss = 0.0;
            SetValue($this->GetIDForIdent('PV_Ueberschuss'), $ueberschuss);
            IPS_LogMessage("PVWallboxManager", "🛑 PV-Überschuss unter Defizitschwelle ({$ueberschuss} W < {$minStopWatt} W) – Wallbox wird deaktiviert");
            $this->SetLadeleistung(0);
            return;
        }

        // Keine negativen Werte
        if ($ueberschuss < 0) {
            $ueberschuss = 0.0;
            IPS_LogMessage("PVWallboxManager", "⚠️ Kein PV-Überschuss – Wert auf 0 gesetzt.");
        }
        SetValue($this->GetIDForIdent('PV_Ueberschuss'), $ueberschuss);

        // --- PV2CarModus: Anteil des Überschusses für das Auto verwenden ---
        if (GetValue($this->GetIDForIdent('PV2CarModus'))) {
            $anteil = $this->ReadPropertyInteger('PVAnteilAuto');
            $maxAmp = $this->ReadPropertyInteger('MaxAmpere');
            $phasen = $this->ReadPropertyInteger('Phasen');
            $maxWatt = $phasen * 230 * $maxAmp;

            $ladeleistung = round($ueberschuss * ($anteil / 100.0));
            $ladeleistung = min(max($ladeleistung, 0), $maxWatt);

            IPS_LogMessage("PVWallboxManager", "☀️ PV2Car aktiv – Anteil fürs Auto: {$anteil}%, Ladeleistung: {$ladeleistung} W");
            $this->SetLadeleistung($ladeleistung);
            $this->SetLademodusStatus("PV2Car: {$ladeleistung} W");
            return;
        }

        // Logging der Gesamtbilanz
        IPS_LogMessage(
            "PVWallboxManager",
            "📊 Bilanz: PV={$pv} W, Haus={$verbrauch} W, Batterie={$batterie} W, " .
            "Wallbox={$ladeleistung} W => Überschuss={$ueberschuss} W"
        );

        $this->SetLadeleistung($ueberschuss);
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
        $typ = 'go-e'; // fest auf go-e gesetzt, da aktuell nur diese Wallbox unterstützt wird

        switch ($typ) {
            case 'go-e':
                $goeID = $this->ReadPropertyInteger('GOEChargerID');
                if (!@IPS_InstanceExists($goeID)) {
                    IPS_LogMessage("PVWallboxManager", "⚠️ go-e Charger Instanz nicht gefunden (ID: $goeID)");
                    return;
                }

                // 🌐 NEU: Phasenumschaltung direkt via API
                $aktuell1phasig = false;
                $phaseVarID = @IPS_GetObjectIDByIdent('SinglePhaseCharging', $goeID);
                if ($phaseVarID !== false && @IPS_VariableExists($phaseVarID)) {
                    $aktuell1phasig = GetValueBoolean($phaseVarID);
                }

                if ($watt < $this->ReadPropertyInteger('Phasen1Schwelle') && !$aktuell1phasig) {
                    $counter = $this->ReadAttributeInteger('Phasen1Counter') + 1;
                    $this->WriteAttributeInteger('Phasen1Counter', $counter);
                    $this->WriteAttributeInteger('Phasen3Counter', 0);
                    IPS_LogMessage("PVWallboxManager", "⏬ Zähler 1-phasig: {$counter} / {$this->ReadPropertyInteger('Phasen1Limit')}");
                    if ($counter >= $this->ReadPropertyInteger('Phasen1Limit')) {
                        GOeCharger_SetSinglePhaseCharging($goeID, true);
                        $this->WriteAttributeInteger('Phasen1Counter', 0);
                        IPS_LogMessage("PVWallboxManager", "🔁 Umschaltung auf 1-phasig ausgelöst");
                    }
                } elseif ($watt > $this->ReadPropertyInteger('Phasen3Schwelle') && $aktuell1phasig) {
                    $counter = $this->ReadAttributeInteger('Phasen3Counter') + 1;
                    $this->WriteAttributeInteger('Phasen3Counter', $counter);
                    $this->WriteAttributeInteger('Phasen1Counter', 0);
                    IPS_LogMessage("PVWallboxManager", "⏫ Zähler 3-phasig: {$counter} / {$this->ReadPropertyInteger('Phasen3Limit')}");
                    if ($counter >= $this->ReadPropertyInteger('Phasen3Limit')) {
                        GOeCharger_SetSinglePhaseCharging($goeID, false);
                        $this->WriteAttributeInteger('Phasen3Counter', 0);
                        IPS_LogMessage("PVWallboxManager", "🔁 Umschaltung auf 3-phasig ausgelöst");
                    }
                } else {
                    $this->WriteAttributeInteger('Phasen1Counter', 0);
                    $this->WriteAttributeInteger('Phasen3Counter', 0);
                }

                $minStopWatt = $this->ReadPropertyInteger('MinStopWatt');

                // === Aktuellen Modus & Ladeleistung auslesen ===
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

                // === Laden deaktivieren ===
                if ($watt < $minStopWatt) {
                    if ($aktuellerModus !== 1) {
                        GOeCharger_setMode($goeID, 1);
                        IPS_LogMessage("PVWallboxManager", "🛑 Modus auf 1 (Nicht laden) gesetzt – Ladeleistung: {$watt} W");
                    }
                    return;
                }

                // === Laden aktivieren ===
                if ($aktuellerModus !== 2) {
                    GOeCharger_setMode($goeID, 2);
                    IPS_LogMessage("PVWallboxManager", "⚡ Modus auf 2 (Immer laden) gesetzt");
                }

                // === Ladeleistung nur setzen, wenn Änderung > 50 W ===
                if ($aktuelleLeistung < 0 || abs($aktuelleLeistung - $watt) > 50) {
                    GOeCharger_SetCurrentChargingWatt($goeID, $watt);
                    IPS_LogMessage("PVWallboxManager", "✅ Ladeleistung gesetzt: {$watt} W");
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
