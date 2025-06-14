<?php

class PVWallboxManager extends IPSModule {

    public function Create() {
        // Diese Methode wird beim Hinzufügen des Moduls aufgerufen.
        parent::Create();
    }

    public function ApplyChanges() {
        // Diese Methode wird aufgerufen, wenn sich Eigenschaften ändern.
        parent::ApplyChanges();
    }

    public function StartLadesteuerung() {
        // Hier könnte das Hauptscript integriert werden.
        IPS_LogMessage("PVWallboxManager", "Ladesteuerung gestartet.");
    }
}
?>
