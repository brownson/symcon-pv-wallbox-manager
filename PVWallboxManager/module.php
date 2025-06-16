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
        $this->RegisterPropertyInteger('MinAmpere', 6);      // Untergrenze (z. B. 6 A)
        $this->RegisterPropertyInteger('MaxAmpere', 16);     // Obergrenze (z. B. 16 A)
        $this->RegisterPropertyInteger('Phasen', 3);         // Aktuelle Anzahl Phasen
    }

    // Wird aufgerufen, wenn sich Konfigurationseinstellungen ändern
    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Lese das eingestellte Intervall aus (in Sekunden)
        $interval = $this->ReadPropertyInteger('RefreshInterval');

        // Sicherheitsgrenze: mindestens 15 Sekunden, maximal 600 Sekunden
        $interval = max(15, min(600, $interval));

        // Setze den Timer neu (in Millisekunden!)
        $this->SetTimerInterval('PVUeberschuss_Berechnen', $interval * 1000);

        // Damit das Feld übernommen wird:
        $this->ReadPropertyInteger('BatterieladungID');
        $this->ReadPropertyString('WallboxTyp');
        $this->ReadPropertyInteger('MinAmpere');
        $this->ReadPropertyInteger('MaxAmpere');
        $this->ReadPropertyInteger('Phasen');
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

        $ueberschuss = $pv - $verbrauch - $batterie;

        SetValue($this->GetIDForIdent('PV_Ueberschuss'), $ueberschuss);

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
        $ampere = max($minAmp, min($maxAmp, $ampere)); // auf gültigen Bereich begrenzen

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
        $typ = $this->ReadPropertyString('WallboxTyp');

        switch ($typ) {
            case 'go-e':
                $goeID = $this->ReadPropertyInteger('GOEChargerID');
                if (!@IPS_InstanceExists($goeID)) {
                    IPS_LogMessage("PVWallboxManager", "⚠️ go-e Charger Instanz nicht vorhanden (ID: $goeID)");
                    return;
                }

                // Ladeleistung setzen
                GOeCharger_SetCurrentChargingWatt($goeID, $watt);
                IPS_LogMessage("PVWallboxManager", "✅ Ladeleistung (go-e) gesetzt: {$watt} W");
                break;

            default:
                IPS_LogMessage("PVWallboxManager", "❌ Wallbox-Typ '$typ' nicht unterstützt – keine Steuerung durchgeführt.");
                break;
        }
    }
}
?>
