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

        $this->RegisterPropertyString('WallboxTyp', 'go-e'); // 'go-e' als Standardwert
        $this->RegisterPropertyInteger('GOEChargerID', 0);
        $this->RegisterPropertyInteger('MinAmpere', 6);      // Untergrenze (z. B. 6 A)
        $this->RegisterPropertyInteger('MaxAmpere', 16);     // Obergrenze (z. B. 16 A)
        $this->RegisterPropertyInteger('Phasen', 3);         // Aktuelle Anzahl Phasen
        $this->RegisterPropertyInteger('MinLadeWatt', 1400); // Mindestüberschuss für Ladestart
        $this->RegisterPropertyInteger('MinStopWatt', -300); // Untergrenze für Stoppen der Ladung
        $this->RegisterPropertyInteger('PhasenUmschaltID', 0);
        $this->RegisterPropertyInteger('Phasen1Schwelle', 1000);
        $this->RegisterPropertyInteger('Phasen3Schwelle', 4200);
        $this->RegisterPropertyInteger('Phasen1Limit', 3);
        $this->RegisterPropertyInteger('Phasen3Limit', 3);

        $this->RegisterAttributeInteger('Phasen1Counter', 0);
        $this->RegisterAttributeInteger('Phasen3Counter', 0);

    }

    // Wird aufgerufen, wenn sich Konfigurationseinstellungen ändern
    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $interval = max(15, min(600, $this->ReadPropertyInteger('RefreshInterval')));
        $this->SetTimerInterval('PVUeberschuss_Berechnen', $interval * 1000);

        // Damit das Feld übernommen wird:
        $this->ReadPropertyInteger('GOEChargerID');
        $this->ReadPropertyString('WallboxTyp');
        $this->ReadPropertyInteger('BatterieladungID');
        $this->ReadPropertyInteger('MinAmpere');
        $this->ReadPropertyInteger('MaxAmpere');
        $this->ReadPropertyInteger('Phasen');
        $this->ReadPropertyInteger('MinLadeWatt');
        $this->ReadPropertyInteger('MinStopWatt');

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

        // === PV-Überschuss berechnen ===
        // === Float-Toleranzfilter (z. B. -1E-13 → 0.0)
        $ueberschuss = $pv - $verbrauch - $batterie;
        if (abs($ueberschuss) < 0.01) {
            $ueberschuss = 0.0;
        }
        SetValue($this->GetIDForIdent('PV_Ueberschuss'), $ueberschuss);

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

    public function RequestAction($ident, $value)
    {
        if ($ident === "BerechnePVUeberschuss") {
            $this->BerechnePVUeberschuss();
        }
    }
    
    private function SetLadeleistung(int $watt)
    {
        $phasenID = $this->ReadPropertyInteger('PhasenUmschaltID');
        if ($phasenID > 0) {
            $ist3Phasig = GetValue($phasenID);

            if ($watt < $this->ReadPropertyInteger('Phasen1Schwelle') && $ist3Phasig) {
                $counter = $this->ReadAttributeInteger('Phasen1Counter') + 1;
                $this->WriteAttributeInteger('Phasen1Counter', $counter);
                $this->WriteAttributeInteger('Phasen3Counter', 0);
                if ($counter >= $this->ReadPropertyInteger('Phasen1Limit')) {
                    RequestAction($phasenID, false);
                    $this->WriteAttributeInteger('Phasen1Counter', 0);
                }
            } elseif ($watt > $this->ReadPropertyInteger('Phasen3Schwelle') && !$ist3Phasig) {
                $counter = $this->ReadAttributeInteger('Phasen3Counter') + 1;
                $this->WriteAttributeInteger('Phasen3Counter', $counter);
                $this->WriteAttributeInteger('Phasen1Counter', 0);
                if ($counter >= $this->ReadPropertyInteger('Phasen3Limit')) {
                    RequestAction($phasenID, true);
                    $this->WriteAttributeInteger('Phasen3Counter', 0);
                }
            } else {
                $this->WriteAttributeInteger('Phasen1Counter', 0);
                $this->WriteAttributeInteger('Phasen3Counter', 0);
            }
        }

        $typ = $this->ReadPropertyString('WallboxTyp');

        switch ($typ) {
            case 'go-e':
                $goeID = $this->ReadPropertyInteger('GOEChargerID');
                if (!@IPS_InstanceExists($goeID)) {
                    IPS_LogMessage("PVWallboxManager", "⚠️ go-e Charger Instanz nicht gefunden (ID: $goeID)");
                    return;
                }

                $minStopWatt = $this->ReadPropertyInteger('MinStopWatt');

                // === Aktuellen Modus & Ladeleistung auslesen ===
                $modusID = @IPS_GetObjectIDByIdent('accessStateV2', $goeID);  // DEIN IDENT
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

}
?>
