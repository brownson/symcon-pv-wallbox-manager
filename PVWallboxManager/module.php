<?php

class PVWallboxManager extends IPSModule
{
    // Wird beim Anlegen der Instanz aufgerufen
    public function Create()
    {
        parent::Create();

        // === Modul-Variable für berechneten PV-Überschuss ===
        // Diese Variable speichert das Ergebnis: PV-Erzeugung - Hausverbrauch
        $this->RegisterVariableFloat('PV_Ueberschuss', 'PV-Überschuss (W)', '~Watt', 10);

        // === Properties zum Speichern der Quell-Variablen-IDs ===
        // ID der PV-Erzeugungs-Variable (Watt)
        $this->RegisterPropertyInteger('PVErzeugungID', 0);

        // ID der Hausverbrauchs-Variable (Watt)
        $this->RegisterPropertyInteger('HausverbrauchID', 0);
        
        // ID der Batterieladungs-Variable (Watt)
        $this->RegisterPropertyInteger('BatterieladungID', 0);

        // === Property für konfigurierbares Intervall (15–600 Sekunden) ===
        // Gibt an, wie oft die Überschuss-Berechnung durchgeführt werden soll
        $this->RegisterPropertyInteger('RefreshInterval', 60); // Standard: 60 Sekunden

        // === Timer registrieren (wird später durch ApplyChanges konfiguriert) ===
        // Führt automatisch alle X Sekunden die Berechnung durch
        $this->RegisterTimer('PVUeberschuss_Berechnen', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "BerechnePVUeberschuss", "");');

        //$this->RegisterPropertyString('WallboxTyp', 'go-e'); // 'go-e' als Standardwert
        $this->RegisterPropertyInteger('GOEChargerID', 0);
        $this->RegisterPropertyInteger('MinAmpere', 6);      // Untergrenze (z. B. 6 A)
        $this->RegisterPropertyInteger('MaxAmpere', 16);     // Obergrenze (z. B. 16 A)
        $this->RegisterPropertyInteger('Phasen', 3);         // Aktuelle Anzahl Phasen
        $this->RegisterPropertyInteger('MinLadeWatt', 1400); // Mindestüberschuss für Ladestart
        $this->RegisterPropertyInteger('MinStopWatt', -300); // Untergrenze für Stoppen der Ladung
        $this->RegisterPropertyInteger('Phasen1Schwelle', 1000);
        $this->RegisterPropertyInteger('Phasen3Schwelle', 4200);
        $this->RegisterPropertyInteger('Phasen1Limit', 3);
        $this->RegisterPropertyInteger('Phasen3Limit', 3);
        $this->RegisterPropertyBoolean('DynamischerPufferAktiv', true); // Schalter für Pufferlogik
        $this->RegisterPropertyInteger('MinAktivierungsWatt', 300);
        $this->RegisterPropertyBoolean('NurMitFahrzeug', true); // Nur laden, wenn Fahrzeug verbunden
        $this->RegisterPropertyBoolean('UseCarSOC', false);
        $this->RegisterPropertyInteger('CarSOCID', 0);
        $this->RegisterPropertyFloat('CarSOCFallback', 20);
        $this->RegisterPropertyInteger('CarTargetSOCID', 0);
        $this->RegisterPropertyFloat('CarTargetSOCFallback', 80);
        $this->RegisterAttributeInteger('Phasen1Counter', 0);
        $this->RegisterAttributeInteger('Phasen3Counter', 0);
        $this->RegisterVariableInteger('TargetTime', 'Ziel-Zeit (Uhr)', '~UnixTimestampTime', 60);
        $this->EnableAction('TargetTime');
        $this->RegisterPropertyBoolean('PVVerteilenAktiv', false);
        $this->RegisterPropertyInteger('PVAnteilAuto', 33); // z. B. 33 % fürs Auto
        $this->RegisterPropertyInteger('HausakkuSOCID', 0); // Integer-Variable für Hausakku-SoC
        $this->RegisterPropertyInteger('HausakkuSOCVollSchwelle', 95);


        
    }

    // Wird aufgerufen, wenn sich Konfigurationseinstellungen ändern
    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $interval = max(15, min(600, $this->ReadPropertyInteger('RefreshInterval')));
        $this->SetTimerInterval('PVUeberschuss_Berechnen', $interval * 1000);

        // Damit das Feld übernommen wird:
        $this->ReadPropertyInteger('GOEChargerID');
        //$this->ReadPropertyString('WallboxTyp');
        $this->ReadPropertyInteger('BatterieladungID');
        $this->ReadPropertyInteger('MinAmpere');
        $this->ReadPropertyInteger('MaxAmpere');
        $this->ReadPropertyInteger('Phasen');
        $this->ReadPropertyInteger('MinLadeWatt');
        $this->ReadPropertyInteger('MinStopWatt');
        $this->ReadPropertyInteger('MinAktivierungsWatt'); // Aktivierungsschwelle sicher übernehmen
        $this->ReadPropertyBoolean('NurMitFahrzeug');
        $this->ReadPropertyInteger('HausakkuSOCID');
        $this->ReadPropertyInteger('HausakkuSOCVollSchwelle');
        $this->ReadPropertyBoolean('PVVerteilenAktiv');
        $this->ReadPropertyInteger('PVAnteilAuto');
    }

    // === Hauptfunktion: Berechnung des PV-Überschusses ===
    // Diese Methode wird durch Timer oder manuell ausgelöst
    public function BerechnePVUeberschuss()
    {
        $pv_id         = $this->ReadPropertyInteger('PVErzeugungID');
        $verbrauch_id  = $this->ReadPropertyInteger('HausverbrauchID');
        $batterie_id   = $this->ReadPropertyInteger('BatterieladungID');

        if (!@IPS_VariableExists($pv_id) || !@IPS_VariableExists($verbrauch_id) || !@IPS_VariableExists($batterie_id)) {
            IPS_LogMessage("⚠️ PVWallboxManager", "❌ Fehler: PV-, Verbrauchs- oder Batterie-ID ist ungültig!");
            return;
        }

        $pv         = GetValue($pv_id);
        $verbrauch  = GetValue($verbrauch_id);
        $batterie   = GetValue($batterie_id); // positiv = lädt, negativ = entlädt

        $goeID = $this->ReadPropertyInteger('GOEChargerID');
        $ladeleistung = 0;
        if (@IPS_InstanceExists($goeID)) {
            $ladeleistung = @GOeCharger_GetPowerToCar($goeID) * 1000; // kW → W
        }

        // === PV-Überschuss berechnen ===
        // === Float-Toleranzfilter (z. B. -1E-13 → 0.0)
        $ueberschuss = $pv - $verbrauch - $batterie + $ladeleistung;
        IPS_LogMessage("PVWallboxManager", "📊 PV={$pv} W, Haus={$verbrauch} W, Batterie={$batterie} W, Wallbox={$ladeleistung} W → Überschuss={$ueberschuss} W");
        if (abs($ueberschuss) < 0.01) {
            $ueberschuss = 0.0;
        }
        SetValue($this->GetIDForIdent('PV_Ueberschuss'), $ueberschuss);
        $minAktiv = $this->ReadPropertyInteger('MinAktivierungsWatt');
        if ($ueberschuss < $minAktiv) {
            IPS_LogMessage("PVWallboxManager", "⏸️ PV-Überschuss zu gering ({$ueberschuss} W < {$minAktiv} W) – Modul bleibt inaktiv");
            return;
        }

        if ($this->ReadPropertyBoolean('NurMitFahrzeug')) {
            $goeID = $this->ReadPropertyInteger('GOEChargerID');
            $status = @GOeCharger_GetStatus($goeID);

        if ($status === false) {
            IPS_LogMessage("PVWallboxManager", "⚠️ Statusabfrage fehlgeschlagen – GO-e Instanz nicht erreichbar?");
            return;
        }

        if (in_array($status, [2, 4])) {
            IPS_LogMessage("PVWallboxManager", "✅ Fahrzeugstatus OK (Status $status) – Ladevorgang wird fortgesetzt");
        } else {
            IPS_LogMessage("PVWallboxManager", "🚫 Kein Fahrzeug verbunden (Status $status) – Ladevorgang wird übersprungen");
            $this->SetLadeleistung(0);
            return;
        }
        }

        // 🆕 Dynamischer Pufferfaktor (optional)
        if ($this->ReadPropertyBoolean('DynamischerPufferAktiv')) {
            $puffer_faktor = 0.93;
            if ($ueberschuss < 2000) {
                $puffer_faktor = 0.80;
            } elseif ($ueberschuss < 4000) {
                $puffer_faktor = 0.85;
            } elseif ($ueberschuss < 6000) {
                $puffer_faktor = 0.90;
            }
            $puffer = round($ueberschuss * (1 - $puffer_faktor));
            $ueberschuss -= $puffer;
            IPS_LogMessage("PVWallboxManager", "🔧 Dynamischer Puffer aktiviert: -{$puffer} W → verbleibend: {$ueberschuss} W");
        }

        // === Frühzeitiger Abbruch bei zu geringem Überschuss ===
        $minLadeWatt = $this->ReadPropertyInteger('MinLadeWatt');
        if ($ueberschuss < $minLadeWatt) {
            IPS_LogMessage("⚡ PVWallboxManager", "🔌 PV-Überschuss zu gering (" . round($ueberschuss, 1) . " W < {$minLadeWatt} W) – Ladeleistung = 0 W");
            $this->SetLadeleistung(0);
            return;
        }

        // Logging mit Symbolen
        if ($ueberschuss > 100) {
            IPS_LogMessage("⚡ PVWallboxManager", "✅ PV-Überschuss: $ueberschuss W ☀️🔋");
        } elseif ($ueberschuss < -100) {
            IPS_LogMessage("⚡ PVWallboxManager", "❗ Netzbezug: $ueberschuss W 🔌❌");
        } else {
            IPS_LogMessage("⚡ PVWallboxManager", "🔍 Kein signifikanter Überschuss: $ueberschuss W");
        }
        // === Dynamische Leistungsberechnung ===
        $phasen = $this->ReadPropertyInteger('Phasen');
        $minAmp = $this->ReadPropertyInteger('MinAmpere');
        $maxAmp = $this->ReadPropertyInteger('MaxAmpere');

        // Ladeleistung in Watt → benötigte Ampere
        $ampere = ceil($ueberschuss / (230 * $phasen));
        $ampere = max($this->ReadPropertyInteger('MinAmpere'), min($this->ReadPropertyInteger('MaxAmpere'), $ampere));

        // Ergebnis: Ladeleistung in Watt
        $ladeleistung = $ampere * 230 * $phasen;

        $this->SetLadeleistung($ladeleistung);
        IPS_LogMessage("⚙️ PVWallboxManager", "Dynamische Ladeleistung: $ladeleistung W bei $ampere A / $phasen Phasen");
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'BerechnePVUeberschuss':
                $this->BerechnePVUeberschuss();
                break;

            case 'Update':
                $this->BerechneLadung();
                break;

            case 'TargetTime':
                SetValue($this->GetIDForIdent('TargetTime'), $Value);
                break;

            default:
                throw new Exception("Invalid Ident: $Ident");
        }
    }

    public function BerechneLadung()
    {
        // Beispiel: PV-Überschuss holen (optional)
        // $pvUeberschuss = $this->GetUeberschuss();

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

        // Hier später: normale PV-Überschussregelung
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

            // 🧼 Entfernt: $phasenID, GetValue($phasenID), RequestAction → ersetzt durch go-e API

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
            if ($watt <= 0 || $watt < $minStopWatt) {
                if ($aktuellerModus !== 1) {
                    GOeCharger_setMode($goeID, 1);
                    IPS_LogMessage("PVWallboxManager", "🛑 Modus auf 1 (Nicht laden) gesetzt – Ladeleistung: {$watt} W");
                } else {
                    IPS_LogMessage("PVWallboxManager", "🟡 Modus bereits 1 (Nicht laden) – keine Umschaltung notwendig");
                }
                return;
            }

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

    private function GetWallboxCapabilities(): array
    {
        $typ = $this->ReadPropertyString('WallboxTyp');

        switch ($typ) {
            case 'go-e':
                return [
                    'supportsPhaseswitch' => true,
                    'minAmp' => 6,
                    'maxAmp' => 16,
                    'setPowerWatt' => true,
                    'setChargingMode' => true
                ];

            case 'openwb':
                return [
                    'supportsPhaseswitch' => true,
                    'minAmp' => 6,
                    'maxAmp' => 32,
                    'setPowerWatt' => false,
                    'setChargingMode' => false
                ];

            default:
                return [
                    'supportsPhaseswitch' => false,
                    'minAmp' => 6,
                    'maxAmp' => 16,
                    'setPowerWatt' => false,
                    'setChargingMode' => false
                ];
        }
    }

    private function GetMinAmpere(): int
    {
        $val = $this->ReadPropertyInteger('MinAmpere');
        return ($val > 0) ? $val : $this->GetWallboxCapabilities()['minAmp'];
    }

    private function GetMaxAmpere(): int
    {
        $val = $this->ReadPropertyInteger('MaxAmpere');
        return ($val > 0) ? $val : $this->GetWallboxCapabilities()['maxAmp'];
    }

}
?>
