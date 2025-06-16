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

        // === Property für konfigurierbares Intervall (15–600 Sekunden) ===
        // Gibt an, wie oft die Überschuss-Berechnung durchgeführt werden soll
        $this->RegisterPropertyInteger('RefreshInterval', 60); // Standard: 60 Sekunden

        // === Timer registrieren (wird später durch ApplyChanges konfiguriert) ===
        // Führt automatisch alle X Sekunden die Berechnung durch
        $this->RegisterTimer('PVUeberschuss_Berechnen', 0, 'PVWallboxManager_BerechnePVUeberschuss($_IPS[\'TARGET\']);');
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
    }

    // === Hauptfunktion: Berechnung des PV-Überschusses ===
    // Diese Methode wird durch Timer oder manuell ausgelöst
    public function BerechnePVUeberschuss()
    {
        // Lese konfigurierte Variablen-IDs
        $pv_id        = $this->ReadPropertyInteger('PVErzeugungID');
        $verbrauch_id = $this->ReadPropertyInteger('HausverbrauchID');

        // Prüfe, ob beide Variablen existieren
        if (!@IPS_VariableExists($pv_id) || !@IPS_VariableExists($verbrauch_id)) {
            IPS_LogMessage("PVWallboxManager", "Fehler: PVErzeugungID oder HausverbrauchID ist ungültig!");
            return;
        }

        // Werte abrufen
        $pv         = GetValue($pv_id);
        $verbrauch  = GetValue($verbrauch_id);

        // PV-Überschuss berechnen (positiv = Überschuss, negativ = Netzbezug)
        $ueberschuss = $pv - $verbrauch;

        // Ergebnis in Modul-Variable speichern
        SetValue($this->GetIDForIdent('PV_Ueberschuss'), $ueberschuss);

        // Log-Ausgabe zur Kontrolle
        IPS_LogMessage("PVWallboxManager", "Aktueller PV-Überschuss: $ueberschuss W");
    }
}
?>
