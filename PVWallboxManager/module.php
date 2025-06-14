<?php

declare(strict_types=1);
/** @moduleID {FAA18EC0-B0C8-D3CB-499D-6E6653E86083} */
class PVWallboxManager extends IPSModule
{
    public function GetModuleID()
    {
    return '{FAA18EC0-B0C8-D3CB-499D-6E6653E86083}';
    }
    public function Create()
    {
        parent::Create();

        // === Eigene Profile für Prozent/SOC ===
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

        // === Properties ===
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
        $this->RegisterPropertyInteger('ZielzeitladungID', 0);
        $this->RegisterPropertyInteger('SOC_ZielwertID', 0);

        // === Variablen ===
        $this->RegisterVariableInteger('Zielzeit_Uhr', 'Ziel-Zeit (bis wann geladen?)', '~UnixTimestampTime', 22);
        $vid = $this->GetIDForIdent('Zielzeit_Uhr');
        if (GetValue($vid) == 0) {
            SetValue($vid, strtotime("06:00"));
        }

        $this->RegisterVariableString('Wallbox_Log', 'Wallbox Log', '', 999);
        $this->RegisterVariableFloat('PV_Berechnet', 'PV berechnet', '~Watt', 10);
        $this->RegisterVariableFloat('PV_Effektiv', 'PV Überschuss effektiv', '~Watt', 11);
        $this->RegisterVariableFloat('Geplante_Ladeleistung', 'Geplante Ladeleistung', '~Watt', 12);
        $this->RegisterVariableInteger('SOC_Zielwert', 'Ziel-SOC Auto', 'PVW.SOC', 30);
        $this->RegisterVariableInteger('PV2CarPercent', 'PV2Car-Prozent', 'PVW.Percent', 31);
        $this->RegisterVariableInteger('PhasenHystUp', 'Hysterese 3-Phasen', '', 50);
        $this->RegisterVariableInteger('PhasenHystDn', 'Hysterese 1-Phasen', '', 51);

        // === Ladeparameter als Properties ===
        $this->RegisterPropertyFloat('MinStartWatt', 1400);
        $this->RegisterPropertyFloat('MinStopWatt', 300);
        $this->RegisterPropertyInteger('PhasenSwitchWatt3', 4200);
        $this->RegisterPropertyInteger('PhasenSwitchWatt1', 1000);
        $this->RegisterPropertyFloat('SOC_Limit', 10);
        $this->RegisterPropertyInteger('Volt', 230);
        $this->RegisterPropertyInteger('MinAmp', 6);
        $this->RegisterPropertyInteger('MaxAmp', 16);

        // Timer für zyklische Prüfung
        $this->RegisterTimer('ZyklischCheck', 60 * 1000, 'PVWallboxManager_CheckWallboxLogic($_IPS[\'TARGET\']);');
    }
}

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Validierungs-Logik
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
        $this->SetTimerInterval('ZyklischCheck', 60 * 1000);
    }

    // Wenn alles OK: Timer (wieder) aktivieren
    $this->SetTimerInterval('ZyklischCheck', 60 * 1000);

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

    // Logging-Funktion: WebFront + Systemlog
    protected function LogWB($msg)
    {
        IPS_LogMessage("PVWallbox", $msg);

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

    // Hauptlogik (wird durch Timer aufgerufen)
    public function CheckWallboxLogic()
    {
        $this->MainLogic();
    }

    // === TimerEvent für Zyklischen Durchlauf ===
    protected function MainLogic()
    {
        // === IDs/Variablen einlesen
        $pv_id       = $this->ReadPropertyInteger('PVErzeugungID');
        $verbrauch_id= $this->ReadPropertyInteger('HausverbrauchID');
        $batt_id     = $this->ReadPropertyInteger('BatterieladungID');
        $wb_power_id = $this->ReadPropertyInteger('WallboxLadeleistungID');
        $wb_aktiv_id = $this->ReadPropertyInteger('WallboxAktivID');
        $soc_auto_id = $this->ReadPropertyInteger('SOC_AutoID');
        $soc_haus_id = $this->ReadPropertyInteger('SOC_HausspeicherID');
        $soc_ziel_id = $this->ReadPropertyInteger('SOC_ZielwertID');
        $pv2car_percent_id = $this->ReadPropertyInteger('PV2CarPercentID');
        $manuell_id  = $this->ReadPropertyInteger('ManuellerModusID');
        $pv2car_id   = $this->ReadPropertyInteger('PV2CarModusID');
        $zielzeit_id = $this->GetIDForIdent('Zielzeit_Uhr');

        // Werte holen
        $pv         = GetValue($pv_id);
        $verbrauch  = GetValue($verbrauch_id);
        $batterie   = GetValue($batt_id);
        $wb_power   = GetValue($wb_power_id);
        $wb_aktiv   = GetValue($wb_aktiv_id);
        $soc_auto   = ($soc_auto_id > 0) ? GetValue($soc_auto_id) : null;
        $soc_haus   = ($soc_haus_id > 0) ? GetValue($soc_haus_id) : null;
        $soc_ziel   = ($soc_ziel_id > 0) ? GetValue($soc_ziel_id) : 80; // Default Ziel-SOC
        $pv2car_percent = ($pv2car_percent_id > 0) ? GetValue($pv2car_percent_id) : 100;
        $manuell    = ($manuell_id > 0) ? GetValue($manuell_id) : false;
        $pv2car     = ($pv2car_id > 0) ? GetValue($pv2car_id) : false;

        $zielzeit   = ($zielzeit_id > 0) ? GetValue($zielzeit_id) : strtotime("06:00");

        $volt       = $this->ReadPropertyInteger('Volt');
        $min_amp    = $this->ReadPropertyInteger('MinAmp');
        $max_amp    = $this->ReadPropertyInteger('MaxAmp');
        $min_start  = $this->ReadPropertyFloat('MinStartWatt');
        $min_stop   = $this->ReadPropertyFloat('MinStopWatt');

        // === PV-Überschuss-Berechnung mit Rückrechnung ===
        $pv_berechnet = $pv - $verbrauch - $batterie;
        $effektiv = max($pv_berechnet + ($wb_aktiv ? $wb_power : 0), 0);

        // === DYNAMISCHER PUFFER: je nach Überschuss anpassen
        if ($effektiv < 2000) {
            $puffer_faktor = 0.80;
        } elseif ($effektiv < 4000) {
            $puffer_faktor = 0.85;
        } elseif ($effektiv < 6000) {
            $puffer_faktor = 0.90;
        } else {
            $puffer_faktor = 0.93;
        }
        $effektiv = (int)($effektiv * $puffer_faktor);

        // Variablen speichern
        SetValue($this->GetIDForIdent('PV_Berechnet'), $pv_berechnet);
        SetValue($this->GetIDForIdent('PV_Effektiv'), $effektiv);

        // === Betriebsmodus auswählen ===
        if ($manuell) {
            $ladeleistung = 3 * $volt * $max_amp;
            SetValue($this->GetIDForIdent('Geplante_Ladeleistung'), $ladeleistung);
            $this->LogWB("⚡ Manueller Modus: Ladeleistung $ladeleistung W");
            // ...hier GOeCharger_SetCurrentChargingWatt() und RequestAction() einbauen
            return;
        }
        if ($pv2car) {
            $ladeleistung = intval($effektiv * ($pv2car_percent / 100));
            SetValue($this->GetIDForIdent('Geplante_Ladeleistung'), $ladeleistung);
            $this->LogWB("🔆 PV2Car-Modus: $pv2car_percent% des PV-Überschusses ($ladeleistung W) ins Auto");
            // ...hier GOeCharger_SetCurrentChargingWatt() einbauen
            return;
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

    // === Überschussmodus: nur laden wenn Überschuss > min_start ===
        if ($effektiv >= $min_start) {
            $ladeleistung = $effektiv;
            SetValue($this->GetIDForIdent('Geplante_Ladeleistung'), $ladeleistung);
            $this->LogWB("✅ Überschuss-Ladung: $ladeleistung W");
            // ...hier GOeCharger_SetCurrentChargingWatt() einbauen
        } elseif ($effektiv < $min_stop) {
            SetValue($this->GetIDForIdent('Geplante_Ladeleistung'), 0);
            $this->LogWB("🛑 Kein Überschuss, Laden gestoppt!");
            // ...hier GOeCharger_SetCurrentChargingWatt() mit 0 einbauen
        } else {
            $this->LogWB("⏸ Zwischenbereich – keine Ladeänderung");
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
