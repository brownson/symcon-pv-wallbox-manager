<?php

class PVWallboxManager extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // === Geräteeinstellungen ===
        $this->RegisterPropertyInteger("goe_id", 0);
        $this->RegisterPropertyInteger("charger_modus_id", 0);
        $this->RegisterPropertyInteger("pv_id", 0);
        $this->RegisterPropertyInteger("verbrauch_id", 0);
        $this->RegisterPropertyInteger("batterie_id", 0);
        $this->RegisterPropertyInteger("wb_power_id", 0);
        $this->RegisterPropertyInteger("wb_aktiv_id", 0);
        $this->RegisterPropertyInteger("phase_var_id", 0);
        $this->RegisterPropertyInteger("modbus_id", 0);
        $this->RegisterPropertyInteger("soc_id", 0);
        $this->RegisterPropertyInteger("log_var_id", 0);
        $this->RegisterPropertyInteger("soc_car_id", 0);
        $this->RegisterPropertyInteger("soc_car_target_id", 0);
        $this->RegisterPropertyInteger("archive_id", 0);

        // === Steuerungselemente ===
        $this->RegisterVariableBoolean("ManualMax", "Manuell Maximal", "~Switch");
        $this->EnableAction("ManualMax");

        $this->RegisterVariableBoolean("PV2CarPercentMode", "PV2Car-Prozent", "~Switch");
        $this->EnableAction("PV2CarPercentMode");

        $this->RegisterVariableBoolean("TargetChargeTime", "Ladezielzeit aktiv", "~Switch");
        $this->EnableAction("TargetChargeTime");

        $this->RegisterVariableInteger("PV2CarPercent", "PV2Car %-Wert", "~Intensity.100");
        $this->EnableAction("PV2CarPercent");

        $this->RegisterVariableInteger("TargetHour", "Ziel-Stunde", "~Hour");
        $this->EnableAction("TargetHour");

        $this->RegisterVariableInteger("TargetMinute", "Ziel-Minute", "~Minute");
        $this->EnableAction("TargetMinute");

        // === Timer initialisieren ===
        $this->RegisterTimer("UpdateTimer", 0, 'PVWM_Update($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetTimerInterval("UpdateTimer", 15000); // alle 15 Sekunden
    }

    public function RequestAction($Ident, $Value)
    {
        $this->SetValue($Ident, $Value);

        // Wenn ein Modus aktiviert wird, die anderen deaktivieren
        if (in_array($Ident, ["ManualMax", "PV2CarPercentMode", "TargetChargeTime"])) {
            foreach (["ManualMax", "PV2CarPercentMode", "TargetChargeTime"] as $mode) {
                if ($mode !== $Ident) {
                    $this->SetValue($mode, false);
                }
            }
        }
    }

    public function Update()
    {
        // Die Haupt-Logik wird hier eingebaut – aktuell leer als Platzhalter
        // (Das Skript aus unserem Hauptprojekt wird hierher modular übertragen)
        IPS_LogMessage("PVWallboxManager", "Update() wird aufgerufen (noch leer)");
    }
}
