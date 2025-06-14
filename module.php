<?php

class PVWallboxManager extends IPSModule
{
    public function Create()
    {
        // Standard
        parent::Create();

        // === Gerätespezifische Properties (Variablen-IDs zuweisen) ===
        $this->RegisterPropertyInteger('PVErzeugungID', 0);
        $this->RegisterPropertyInteger('HausverbrauchID', 0);
        $this->RegisterPropertyInteger('BatterieladungID', 0);
        $this->RegisterPropertyInteger('WallboxLadeleistungID', 0);
        $this->RegisterPropertyInteger('WallboxAktivID', 0);
        $this->RegisterPropertyInteger('ModbusRegisterID', 0);
        $this->RegisterPropertyInteger('SOC_HausspeicherID', 0);
        $this->RegisterPropertyInteger('SOC_AutoID', 0);

        // === Modus/Button-Variablen ===
        $this->RegisterPropertyInteger('ManuellerModusID', 0);
        $this->RegisterPropertyInteger('PV2CarModusID', 0);
        $this->RegisterPropertyInteger('PV2CarPercentID', 0);
        $this->RegisterPropertyInteger('ZielzeitladungID', 0);
        $this->RegisterPropertyInteger('SOC_ZielwertID', 0);
        $this->RegisterPropertyInteger('Zielzeit_Stunde_ID', 0);
        $this->RegisterPropertyInteger('Zielzeit_Minute_ID', 0);

        // === Ladeparameter/Settings ===
        $this->RegisterPropertyFloat('MinStartWatt', 1400);
        $this->RegisterPropertyFloat('MinStopWatt', 300);
        $this->RegisterPropertyInteger('PhasenSwitchWatt3', 4200);
        $this->RegisterPropertyInteger('PhasenSwitchWatt1', 1000);
        $this->RegisterPropertyFloat('SOC_Limit', 10);

        // === Logging/Status-Variablen werden automatisch erzeugt
        $this->RegisterVariableFloat('PV_Berechnet', 'PV berechnet', '~Watt', 1);
        $this->RegisterVariableFloat('PV_Effektiv', 'PV Überschuss effektiv', '~Watt', 2);
        $this->RegisterVariableFloat('Geplante_Ladeleistung', 'Geplante Ladeleistung', '~Watt', 3);
        $this->RegisterVariableString('Wallbox_Log', 'Wallbox Log', '', 10);

        // === Zielzeit-Variablen (schön editierbar im WebFront)
        $this->RegisterVariableInteger('Zielzeit_Stunde', 'Ziel-Zeit (Stunde)', '~Hour', 20);
        $this->RegisterVariableInteger('Zielzeit_Minute', 'Ziel-Zeit (Minute)', '~Minute', 21);

        // === Timer für zyklische Ausführung (1x pro Minute als Standard)
        $this->RegisterTimer('ZyklischCheck', 60 * 1000, 'PVWALLBOX_RunLogic($InstanceID);');
    }
    
    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Zielzeit-Variablen (Profile anlegen, falls nicht vorhanden)
        if (!IPS_VariableProfileExists('~Hour')) {
            IPS_CreateVariableProfile('~Hour', 1);
            IPS_SetVariableProfileDigits('~Hour', 0);
            IPS_SetVariableProfileValues('~Hour', 0, 23, 1);
        }
        if (!IPS_VariableProfileExists('~Minute')) {
            IPS_CreateVariableProfile('~Minute', 1);
            IPS_SetVariableProfileDigits('~Minute', 0);
            IPS_SetVariableProfileValues('~Minute', 0, 59, 1);
        }

        // Logging-Variable: Mehrzeilig im WebFront
        $logID = $this->GetIDForIdent('Wallbox_Log');
        IPS_SetInfo($logID, 'Wird für Wallbox-Ereignisse genutzt');

        // Status/Meldung initialisieren
        if (GetValue($logID) == '') {
            SetValue($logID, date("d.m.Y, H:i:s") . " | Modul geladen\n");
        }

        // Timer ggf. wieder aktivieren
        $this->SetTimerInterval('ZyklischCheck', 60 * 1000); // alle 60 Sekunden
    }

    // === Logging-Funktion für das Modul ===
    protected function LogWB($msg)
    {
        $logID = $this->GetIDForIdent('Wallbox_Log');
        $old = GetValue($logID);
        $new = date("d.m.Y, H:i:s") . " | PVWallbox | $msg";
        // Maximal 50 Einträge behalten
        $entries = array_merge([$new], explode("\n", $old));
        SetValue($logID, implode("\n", array_slice($entries, 0, 50)));
    }

    // === Hilfsfunktion: Wert einer Property oder Variable abrufen ===
    protected function ReadValue($identOrProp)
    {
        $varID = $this->ReadPropertyInteger($identOrProp);
        if ($varID > 0 && @IPS_VariableExists($varID)) {
            return GetValue($varID);
        }
        return null;
    }
    // === TimerEvent für Zyklischen Durchlauf ===
    public function CheckWallboxLogic()
    {
        // IDs und Werte aus den Properties holen
        $pv = $this->ReadValue('PVErzeugungID');
        $verbrauch = $this->ReadValue('HausverbrauchID');
        $batterie = $this->ReadValue('BatterieladungID');
        $wb_power = $this->ReadValue('WallboxLeistungID');
        $wb_status = $this->ReadValue('WallboxStatusID');
        $soc_hausspeicher = $this->ReadValue('SOCHausbatterieID');
        $soc_fahrzeug = $this->ReadValue('SOCFahrzeugID');
        $manuell = $this->ReadValue('ButtonManuellID');
        $pv2car = $this->ReadValue('ButtonPV2CarID');
        $zielzeit = $this->ReadValue('ButtonZielzeitID');
        $zielzeit_hour = $this->ReadValue('ZielzeitStundeID');
        $zielzeit_min = $this->ReadValue('ZielzeitMinuteID');
        $ziel_soc = $this->ReadValue('SOCAutoZielID');
        $pv2car_percent = $this->ReadValue('PV2CarPercentID');
        $phase_var = $this->ReadValue('PhaseVarID');
        $phasen = $phase_var ? 3 : 1;

        // PV-Überschussberechnung (inkl. Rückrechnung Wallbox-Leistung)
        $pv_berechnet = $pv - $verbrauch - $batterie;
        $effektiv = max($pv_berechnet + ($wb_status ? $wb_power : 0), 0);

        // Dynamischer Pufferfaktor berechnen
        $puffer_faktor = $this->BerechnePufferFaktor($effektiv);
        $effektiv -= round($effektiv * $puffer_faktor);

        // Alle wichtigen Variablen als String fürs Log
        $this->LogWB("PV: $pv | Verbrauch: $verbrauch | Batterie: $batterie | WB: $wb_power | Eff. PV-Überschuss: $effektiv W");

        // Je nach Button & Modus aufrufen
        if ($manuell) {
            $this->LadenSofortMaximal($phasen);
            return;
        }
        if ($pv2car) {
            $this->LadenPV2CarPercent($phasen, $effektiv, $pv2car_percent, $soc_hausspeicher);
            return;
        }
        if ($zielzeit) {
            $this->LadenMitZielzeit($phasen, $soc_fahrzeug, $ziel_soc, $zielzeit_hour, $zielzeit_min);
            return;
        }
        // Default: Nur mit echtem PV-Überschuss
        $this->LadenMitPVUeberschuss($phasen, $effektiv);
    }

    // Hilfsmethode: Pufffaktor dynamisch bestimmen
    protected function BerechnePufferFaktor($effektiv)
    {
        if ($effektiv < 2000) {
            return 0.20;
        } elseif ($effektiv < 4000) {
            return 0.15;
        } elseif ($effektiv < 6000) {
            return 0.10;
        } else {
            return 0.07;
        }
    }
    // Manueller Modus: Maximale Leistung sofort
    protected function LadenSofortMaximal($phasen)
    {
        $volt    = $this->ReadPropertyInteger('Volt');
        $max_amp = $this->ReadPropertyInteger('MaxAmp');
        $ladeleistung = $phasen * $volt * $max_amp;
        // Beispiel: Deine eigene API
        GOeCharger_SetCurrentChargingWatt($this->ReadPropertyInteger('WallboxAktivID'), $ladeleistung, $this->ReadPropertyInteger('MinAmp'));
        RequestAction($this->ReadPropertyInteger('WallboxModusID'), 2);
        $this->SetValue('Geplante_Ladeleistung', $ladeleistung);
        $this->LogWB("Manueller Modus: Sofort maximale Ladeleistung $ladeleistung W ($phasen-phasig)");
    }

    // PV2Car-Modus: Prozentualer Überschuss ins Auto
    protected function LadenPV2CarPercent($phasen, $effektiv, $prozent, $soc_hausspeicher)
    {
        $volt    = $this->ReadPropertyInteger('Volt');
        $max_amp = $this->ReadPropertyInteger('MaxAmp');
        $ladeleistung = round($effektiv * max(0, min(100, $prozent)) / 100);
        // Wenn Hausspeicher voll -> alles ins Auto
        if ($soc_hausspeicher >= 98) {
            $ladeleistung = min($effektiv, $phasen * $volt * $max_amp);
        }
        if ($ladeleistung > 0) {
            GOeCharger_SetCurrentChargingWatt($this->ReadPropertyInteger('WallboxAktivID'), $ladeleistung, $this->ReadPropertyInteger('MinAmp'));
            RequestAction($this->ReadPropertyInteger('WallboxModusID'), 2);
            $this->SetValue('Geplante_Ladeleistung', $ladeleistung);
            $this->LogWB("PV2Car: $prozent% von $effektiv W = $ladeleistung W (Hausspeicher-SOC $soc_hausspeicher%)");
        } else {
            GOeCharger_SetCurrentChargingWatt($this->ReadPropertyInteger('WallboxAktivID'), 0, $this->ReadPropertyInteger('MinAmp'));
            RequestAction($this->ReadPropertyInteger('WallboxModusID'), 0);
            $this->SetValue('Geplante_Ladeleistung', 0);
            $this->LogWB("PV2Car: Zu wenig PV-Überschuss, Wallbox aus.");
        }
    }

    // Zielzeit-Ladung: Ladeleistung so wählen, dass um Zielzeit der Ziel-SOC erreicht ist
    protected function LadenMitZielzeit($phasen, $soc_ist, $soc_soll, $ziel_hour, $ziel_min)
    {
        $akku_kapazitaet = 52; // kWh, anpassbar für ID.3
        $volt    = $this->ReadPropertyInteger('Volt');
        $max_amp = $this->ReadPropertyInteger('MaxAmp');
        $min_amp = $this->ReadPropertyInteger('MinAmp');

        $bedarf_kwh = max(0, ($soc_soll - $soc_ist) * $akku_kapazitaet / 100);

        // Zielzeit bestimmen
        $jetzt = time();
        $ziel_uhrzeit = mktime($ziel_hour, $ziel_min, 0);
        if ($ziel_uhrzeit <= $jetzt) $ziel_uhrzeit += 86400;
        $restzeit_stunden = ($ziel_uhrzeit - $jetzt) / 3600;
        $leistung_watt = ($bedarf_kwh * 1000) / max($restzeit_stunden, 0.5);

        $ladeleistung = min($leistung_watt, $phasen * $volt * $max_amp);
        if ($ladeleistung > 0) {
            GOeCharger_SetCurrentChargingWatt($this->ReadPropertyInteger('WallboxAktivID'), $ladeleistung, $min_amp);
            RequestAction($this->ReadPropertyInteger('WallboxModusID'), 2);
            $this->SetValue('Geplante_Ladeleistung', $ladeleistung);
            $this->LogWB("Zielladung: $ladeleistung W bis $ziel_hour:$ziel_min Uhr (Ziel $soc_soll%, Bedarf $bedarf_kwh kWh)");
        } else {
            GOeCharger_SetCurrentChargingWatt($this->ReadPropertyInteger('WallboxAktivID'), 0, $min_amp);
            RequestAction($this->ReadPropertyInteger('WallboxModusID'), 0);
            $this->SetValue('Geplante_Ladeleistung', 0);
            $this->LogWB("Zielladung: Ziel bereits erreicht oder keine Restladung nötig");
        }
    }

    // Nur PV-Überschuss laden
    protected function LadenMitPVUeberschuss($phasen, $effektiv)
    {
        $volt    = $this->ReadPropertyInteger('Volt');
        $max_amp = $this->ReadPropertyInteger('MaxAmp');
        $min_start_watt = $this->ReadPropertyFloat('MinStartWatt');

        if ($effektiv < $min_start_watt) {
            GOeCharger_SetCurrentChargingWatt($this->ReadPropertyInteger('WallboxAktivID'), 0, $this->ReadPropertyInteger('MinAmp'));
            RequestAction($this->ReadPropertyInteger('WallboxModusID'), 0);
            $this->SetValue('Geplante_Ladeleistung', 0);
            $this->LogWB("PV-Überschuss < $min_start_watt W: Wallbox gestoppt");
            return;
        }
        $ladeleistung = min($effektiv, $phasen * $volt * $max_amp);
        GOeCharger_SetCurrentChargingWatt($this->ReadPropertyInteger('WallboxAktivID'), $ladeleistung, $this->ReadPropertyInteger('MinAmp'));
        RequestAction($this->ReadPropertyInteger('WallboxModusID'), 2);
        $this->SetValue('Geplante_Ladeleistung', $ladeleistung);
        $this->LogWB("PV-Überschuss-Ladung: $ladeleistung W");
    }

    // Hysterese/Phasenumschaltung, Modbus & weitere Utilitys können hier ergänzt werden!
    // ...
}
