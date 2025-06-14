<?php

class PVWallboxManager extends IPSModule {
    public function Create() {
        parent::Create();
        // Konfig-Properties
        $this->RegisterPropertyInteger("goe_id", 58186);
        // ... weitere Properties
        // Modus-Buttons
        $this->RegisterVariableBoolean("ManualMode", "Manuell laden", "~Switch");
        // Profil-Aktion für Zielzeit
        $this->RegisterVariableInteger("TargetHour", "Zielzeit Stunde", "~Hour");
        $this->RegisterVariableInteger("TargetMinute", "Zielzeit Minute", "~Minute");
        $this->EnableAction("ManualMode");
        // Timer alle x Sekunden starten
        $this->RegisterTimer("UpdateTimer", 0, 'PVWM_Update($_IPS["TARGET"]);');
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        $this->SetTimerInterval("UpdateTimer", 10000);
    }

    public function RequestAction($Ident, $value) {
        switch ($Ident) {
            case "ManualMode":
                $this->SetValue($Ident, $value);
                break;
            // ...
        }
    }

    public function Update() {
        // Logik-Aufruf hier, step-by-step aus Hauptscript
    }
}
