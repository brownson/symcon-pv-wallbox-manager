<?php

class PVWallboxManager extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Eigene Profile anlegen
        if (!@IPS_VariableProfileExists('PVW.Percent')) {
            IPS_CreateVariableProfile('PVW.Percent', 1);
            IPS_SetVariableProfileDigits('PVW.Percent', 0);
            IPS_SetVariableProfileText('PVW.Percent', '', ' %');
            IPS_SetVariableProfileValues('PVW.Percent', 0, 100, 1);
        }
        if (!@IPS_VariableProfileExists('PVW.SOC')) {
            IPS_CreateVariableProfile('PVW.SOC', 1);
            IPS_SetVariableProfileDigits('PVW.SOC', 0);
            IPS_SetVariableProfileText('PVW.SOC', '', ' %');
            IPS_SetVariableProfileValues('PVW.SOC', 0, 100, 1);
        }
        if (!@IPS_VariableProfileExists('PVW.Hour')) {
            IPS_CreateVariableProfile('PVW.Hour', 1);
            IPS_SetVariableProfileDigits('PVW.Hour', 0);
            IPS_SetVariableProfileValues('PVW.Hour', 0, 23, 1);
            IPS_SetVariableProfileText('PVW.Hour', '', ' h');
        }
        if (!@IPS_VariableProfileExists('PVW.Minute')) {
            IPS_CreateVariableProfile('PVW.Minute', 1);
            IPS_SetVariableProfileDigits('PVW.Minute', 0);
            IPS_SetVariableProfileValues('PVW.Minute', 0, 59, 1);
            IPS_SetVariableProfileText('PVW.Minute', '', ' min');
        }

        // Properties für IDs externer Variablen
        $this->RegisterPropertyInteger('PVErzeugungID', 0);
        $this->RegisterPropertyInteger('HausverbrauchID', 0);
        $this->RegisterPropertyInteger('BatterieladungID', 0);
        $this->RegisterPropertyInteger('WallboxLadeleistungID', 0);
        $this->RegisterPropertyInteger('WallboxAktivID', 0);
        $this->RegisterPropertyInteger('ModbusRegisterID', 0);
        $this->RegisterPropertyInteger('SOC_HausspeicherID', 0);
        $this->RegisterPropertyInteger('SOC_AutoID', 0);
        $this->RegisterPropertyInteger('ManuellerModusID', 0);
        $this->RegisterPropertyInteger('PV2CarModusID', 0);
        $this->RegisterPropertyInteger('PV2CarPercentID', 0);
        $this->RegisterPropertyInteger('SOC_ZielwertID', 0);

        // Timer-Intervall
        $this->RegisterPropertyInteger('TimerInterval', 60);

        // Eigene Variablen (Stunde/Minute für Zielzeit)
        $this->RegisterVariableInteger('Zielzeit_Stunde', 'Zielzeit Stunde', 'PVW.Hour', 20);
        $this->RegisterVariableInteger('Zielzeit_Minute', 'Zielzeit Minute', 'PVW.Minute', 21);

        // Standard-Zielzeit (6:00 Uhr)
        $vid_h = $this->GetIDForIdent('Zielzeit_Stunde');
        $vid_m = $this->GetIDForIdent('Zielzeit_Minute');
        if (GetValue($vid_h) == 0) SetValue($vid_h, 6);
        if (GetValue($vid_m) == 0) SetValue($vid_m, 0);

        // Weitere Variablen
        $this->RegisterVariableString('Wallbox_Log', 'Wallbox Log', '', 999);
        $this->RegisterVariableFloat('PV_Berechnet', 'PV berechnet', '~Watt', 10);
        $this->RegisterVariableFloat('PV_Effektiv', 'PV Überschuss effektiv', '~Watt', 11);
        $this->RegisterVariableFloat('Geplante_Ladeleistung', 'Geplante Ladeleistung', '~Watt', 12);
        $this->RegisterVariableInteger('SOC_Zielwert', 'Ziel-SOC Auto', 'PVW.SOC', 30);
        $this->RegisterVariableInteger('PV2CarPercent', 'PV2Car-Prozent', 'PVW.Percent', 31);
        $this->RegisterVariableInteger('PhasenHystUp', 'Hysterese 3-Phasen', '', 50);
        $this->RegisterVariableInteger('PhasenHystDn', 'Hysterese 1-Phasen', '', 51);

        // Modus-Buttons (Boolean)
        $this->RegisterVariableBoolean('Button_Manuell', 'Manueller Modus (Maximal)', '~Switch', 101);
        $this->RegisterVariableBoolean('Button_PV2Car', 'PV2Car-Regler', '~Switch', 102);
        $this->RegisterVariableBoolean('Button_Zielladung', 'Zielladung', '~Switch', 103);

        // Ladeparameter als Properties
        $this->RegisterPropertyFloat('MinStartWatt', 1400);
        $this->RegisterPropertyFloat('MinStopWatt', 300);
        $this->RegisterPropertyInteger('PhasenSwitchWatt3', 4200);
        $this->RegisterPropertyInteger('PhasenSwitchWatt1', 1000);
        $this->RegisterPropertyFloat('SOC_Limit', 10);
        $this->RegisterPropertyInteger('Volt', 230);
        $this->RegisterPropertyInteger('MinAmp', 6);
        $this->RegisterPropertyInteger('MaxAmp', 16);

        // Timer
        $interval = max(15, $this->ReadPropertyInteger('TimerInterval'));
        $this->RegisterTimer('ZyklischCheck', $interval * 1000, 'PVWallboxManager_CheckWallboxLogic($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Pflicht-IDs prüfen
        $requiredIDs = [
            'PVErzeugungID',
            'HausverbrauchID',
            'BatterieladungID',
            'WallboxLadeleistungID',
            'WallboxAktivID'
        ];

        $missing = [];
        foreach ($requiredIDs as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id == 0 || !@IPS_VariableExists($id)) {
                $missing[] = $prop;
            }
        }
        if (!empty($missing)) {
            $msg = "Fehlende oder ungültige Variablen-IDs: ".implode(', ', $missing).". Modul ist inaktiv!";
            $this->LogWB($msg);
            $this->SetTimerInterval('ZyklischCheck', 0);
            return;
        }

        // Logging-Variable (Multiline)
        $logID = $this->GetIDForIdent('Wallbox_Log');
        IPS_SetInfo($logID, 'Wird für Wallbox-Ereignisse genutzt');
        if (GetValue($logID) == '') {
            SetValue($logID, date("d.m.Y, H:i:s") . " | Modul geladen\n");
        }

        // Timer-Intervall setzen
        $interval = max(15, $this->ReadPropertyInteger('TimerInterval'));
        $this->SetTimerInterval('ZyklischCheck', $interval * 1000);
    }

    public function RequestAction($ident, $value)
    {
        // Nur ein Button darf TRUE sein!
        switch ($ident) {
            case 'Button_Manuell':
            case 'Button_PV2Car':
            case 'Button_Zielladung':
                if ($value) {
                    SetValue($this->GetIDForIdent('Button_Manuell'), $ident == 'Button_Manuell');
                    SetValue($this->GetIDForIdent('Button_PV2Car'), $ident == 'Button_PV2Car');
                    SetValue($this->GetIDForIdent('Button_Zielladung'), $ident == 'Button_Zielladung');
                } else {
                    SetValue($this->GetIDForIdent($ident), false);
                }
                break;
            default:
                throw new Exception("Invalid Ident for RequestAction: " . $ident);
        }
    }

    // Logging
    protected function LogWB($msg)
    {
        IPS_LogMessage("PVWallbox", $msg);
        $logID = $this->GetIDForIdent('Wallbox_Log');
        $old = GetValue($logID);
        $new = date("d.m.Y, H:i:s") . " | PVWallbox | $msg";
        $entries = array_merge([$new], explode("\n", $old));
        SetValue($logID, implode("\n", array_slice($entries, 0, 50)));
    }

    // Hauptlogik (Timer)
    public function CheckWallboxLogic()
    {
        $this->MainLogic();
    }

    protected function MainLogic()
    {
        $phasen = 3; // Später dynamisch!

        // Modus-Buttons
        $manuell    = GetValue($this->GetIDForIdent('Button_Manuell'));
        $pv2car     = GetValue($this->GetIDForIdent('Button_PV2Car'));
        $zielladung = GetValue($this->GetIDForIdent('Button_Zielladung'));

        // Modul-Variablen und externe Variablen holen
        $effektiv      = GetValue($this->GetIDForIdent('PV_Effektiv'));
        $pv2car_percent = GetValue($this->GetIDForIdent('PV2CarPercent'));
        $soc_auto_id   = $this->ReadPropertyInteger('SOC_AutoID');
        $soc_auto      = ($soc_auto_id > 0 && @IPS_VariableExists($soc_auto_id)) ? GetValue($soc_auto_id) : null;
        $soc_ziel      = GetValue($this->GetIDForIdent('SOC_Zielwert'));
        $soc_haus_id   = $this->ReadPropertyInteger('SOC_HausspeicherID');
        $soc_haus      = ($soc_haus_id > 0 && @IPS_VariableExists($soc_haus_id)) ? GetValue($soc_haus_id) : null;

        // Zielzeit zusammensetzen
        $ziel_stunde = GetValue($this->GetIDForIdent('Zielzeit_Stunde'));
        $ziel_minute = GetValue($this->GetIDForIdent('Zielzeit_Minute'));
        $jetzt = time();
        $zielzeit = mktime($ziel_stunde, $ziel_minute, 0);
        if ($zielzeit <= $jetzt) $zielzeit += 86400;

        // Betriebsmodus
        if ($manuell) {
            $this->LadenSofortMaximal($phasen);
            $this->LogWB("Manueller Modus aktiv: Maximale Leistung.");
            return;
        }
        if ($pv2car) {
            $this->LadenPV2CarPercent($phasen, $effektiv, $pv2car_percent, $soc_haus);
            $this->LogWB("PV2Car aktiv: $pv2car_percent% des Überschusses ins Auto.");
            return;
        }
        if ($zielladung) {
            $this->LadenMitZielzeit($phasen, $soc_auto, $soc_ziel, $zielzeit);
            $this->LogWB("Zielladung aktiv: bis ".date('H:i', $zielzeit)." Ziel-SOC $soc_ziel%.");
            return;
        }
        // Standardfall
        $this->LadenMitPVUeberschuss($phasen, $effektiv);
        $this->LogWB("Standardmodus: Nur PV-Überschuss laden.");
    }

    // Hilfsmethode: Pufffaktor (kann genutzt werden, z.B. für $effektiv-Berechnung)
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

    // Manueller Modus: Maximal laden
    protected function LadenSofortMaximal($phasen = 3)
    {
        $volt    = $this->ReadPropertyInteger('Volt');
        $max_amp = $this->ReadPropertyInteger('MaxAmp');
        $ladeleistung = $phasen * $volt * $max_amp;

        GOeCharger_SetCurrentChargingWatt($this->ReadPropertyInteger('WallboxAktivID'), $ladeleistung, $this->ReadPropertyInteger('MinAmp'));
        RequestAction($this->ReadPropertyInteger('WallboxModusID'), 2);
        SetValue($this->GetIDForIdent('Geplante_Ladeleistung'), $ladeleistung);

        $this->LogWB("Manueller Modus: Sofort maximale Ladeleistung $ladeleistung W ($phasen-phasig)");
    }

    // PV2Car-Modus: Prozentualer Überschuss ins Auto
    protected function LadenPV2CarPercent($phasen, $effektiv, $prozent, $soc_hausspeicher = null)
    {
        $volt    = $this->ReadPropertyInteger('Volt');
        $max_amp = $this->ReadPropertyInteger('MaxAmp');

        if ($soc_hausspeicher !== null && $soc_hausspeicher >= 98) {
            $prozent = 100;
        }

        $ladeleistung = round($effektiv * max(0, min(100, $prozent)) / 100);

        if ($ladeleistung > 0) {
            GOeCharger_SetCurrentChargingWatt($this->ReadPropertyInteger('WallboxAktivID'), $ladeleistung, $this->ReadPropertyInteger('MinAmp'));
            RequestAction($this->ReadPropertyInteger('WallboxModusID'), 2);
            SetValue($this->GetIDForIdent('Geplante_Ladeleistung'), $ladeleistung);
            $this->LogWB("PV2Car: $prozent% von $effektiv W = $ladeleistung W (Hausspeicher-SOC: $soc_hausspeicher%)");
        } else {
            GOeCharger_SetCurrentChargingWatt($this->ReadPropertyInteger('WallboxAktivID'), 0, $this->ReadPropertyInteger('MinAmp'));
            RequestAction($this->ReadPropertyInteger('WallboxModusID'), 0);
            SetValue($this->GetIDForIdent('Geplante_Ladeleistung'), 0);
            $this->LogWB("PV2Car: Zu wenig PV-Überschuss, Wallbox aus.");
        }
    }

    // Zielladung
    protected function LadenMitZielzeit($phasen, $soc_ist, $soc_soll, $zielzeit)
    {
        $akku_kapazitaet = 52; // kWh, ggf. Property machen
        $volt    = $this->ReadPropertyInteger('Volt');
        $max_amp = $this->ReadPropertyInteger('MaxAmp');
        $min_amp = $this->ReadPropertyInteger('MinAmp');

        $bedarf_kwh = max(0, ($soc_soll - $soc_ist) * $akku_kapazitaet / 100);

        $jetzt = time();
        if ($zielzeit <= $jetzt) $zielzeit += 86400;
        $restzeit_stunden = ($zielzeit - $jetzt) / 3600;
        $leistung_watt = ($bedarf_kwh * 1000) / max($restzeit_stunden, 0.5);

        $ladeleistung = min($leistung_watt, $phasen * $volt * $max_amp);
        if ($ladeleistung > 0) {
            GOeCharger_SetCurrentChargingWatt($this->ReadPropertyInteger('WallboxAktivID'), $ladeleistung, $min_amp);
            RequestAction($this->ReadPropertyInteger('WallboxModusID'), 2);
            SetValue($this->GetIDForIdent('Geplante_Ladeleistung'), $ladeleistung);
            $uhrzeit = date("H:i", $zielzeit);
            $this->LogWB("Zielladung: $ladeleistung W bis $uhrzeit Uhr (Ziel $soc_soll%, Bedarf $bedarf_kwh kWh)");
        } else {
            GOeCharger_SetCurrentChargingWatt($this->ReadPropertyInteger('WallboxAktivID'), 0, $min_amp);
            RequestAction($this->ReadPropertyInteger('WallboxModusID'), 0);
            SetValue($this->GetIDForIdent('Geplante_Ladeleistung'), 0);
            $this->LogWB("Zielladung: Ziel bereits erreicht oder keine Restladung nötig");
        }
    }

    // PV-Überschuss laden
    protected function LadenMitPVUeberschuss($phasen, $effektiv)
    {
        $volt    = $this->ReadPropertyInteger('Volt');
        $max_amp = $this->ReadPropertyInteger('MaxAmp');
        $min_amp = $this->ReadPropertyInteger('MinAmp');
        $min_start_watt = $this->ReadPropertyFloat('MinStartWatt');

        if ($effektiv < $min_start_watt) {
            GOeCharger_SetCurrentChargingWatt($this->ReadPropertyInteger('WallboxAktivID'), 0, $min_amp);
            RequestAction($this->ReadPropertyInteger('WallboxModusID'), 0);
            SetValue($this->GetIDForIdent('Geplante_Ladeleistung'), 0);
            $this->LogWB("PV-Überschuss < $min_start_watt W: Wallbox gestoppt");
            return;
        }
        $ladeleistung = min($effektiv, $phasen * $volt * $max_amp);
        GOeCharger_SetCurrentChargingWatt($this->ReadPropertyInteger('WallboxAktivID'), $ladeleistung, $min_amp);
        RequestAction($this->ReadPropertyInteger('WallboxModusID'), 2);
        SetValue($this->GetIDForIdent('Geplante_Ladeleistung'), $ladeleistung);
        $this->LogWB("PV-Überschuss-Ladung: $ladeleistung W");
    }
}
?>
