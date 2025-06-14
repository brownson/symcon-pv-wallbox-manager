<?php

declare(strict_types=1);

class PVWallboxManager extends IPSModule
{
    public function Create()
    {
        parent::Create();
        // Konfigurationseigenschaften
        $this->RegisterPropertyInteger('GoEChargerID', 0);
        $this->RegisterPropertyInteger('WallboxModusID', 0);
        $this->RegisterPropertyInteger('PVErzeugungID', 0);
        $this->RegisterPropertyInteger('HausverbrauchID', 0);
        $this->RegisterPropertyInteger('BatterieLadungID', 0);
        $this->RegisterPropertyInteger('WallboxLeistungID', 0);
        $this->RegisterPropertyInteger('WallboxAktivID', 0);
        $this->RegisterPropertyInteger('PhaseUmschaltID', 0);
        $this->RegisterPropertyInteger('ManuellButtonID', 0);
        $this->RegisterPropertyInteger('PV2CarButtonID', 0);
        $this->RegisterPropertyInteger('PV2CarPercentID', 0);
        $this->RegisterPropertyInteger('ZielzeitButtonID', 0);
        $this->RegisterPropertyInteger('ZielzeitID', 0);
        $this->RegisterPropertyInteger('SOCZielID', 0);
        $this->RegisterPropertyInteger('FahrzeugSOCID', 0);
        $this->RegisterPropertyInteger('ModbusID', 0);
        $this->RegisterPropertyInteger('SOCID', 0);
        $this->RegisterPropertyInteger('LogVarID', 0);
        $this->RegisterPropertyInteger('Volt', 230);
        $this->RegisterPropertyInteger('MinAmp', 6);
        $this->RegisterPropertyInteger('MaxAmp', 16);
        $this->RegisterPropertyInteger('MinStartWatt', 1400);
        $this->RegisterPropertyInteger('MinStopWatt', 300);
        $this->RegisterPropertyInteger('SOCMinimum', 10);

        $this->RegisterTimer('UpdateLadeLogik', 60000, 'PVWM_UpdateLadeLogik($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetTimerInterval('UpdateLadeLogik', 60000);
    }

    public function RequestAction($Ident, $Value)
    {
        $this->SetValue($Ident, $Value);
    }

    protected function LogWB($msg)
    {
        $log_var_id = $this->ReadPropertyInteger('LogVarID');
        $prefix = 'go-e Charger';
        IPS_LogMessage($prefix, $msg);

        if ($log_var_id > 0 && @IPS_VariableExists($log_var_id)) {
            $old = GetValue($log_var_id);
            $new = date("d.m.Y, H:i:s") . " | $prefix | $msg";
            SetValue($log_var_id, implode("\n", array_slice(array_merge([$new], explode("\n", $old)), 0, 20)));
        }
    }

    protected function SetOrCreateVar($ident, $name, $type, $value)
    {
        $parent = $this->InstanceID;
        $vid = @IPS_GetObjectIDByIdent($ident, $parent);
        if ($vid === false) {
            $vid = IPS_CreateVariable($type);
            IPS_SetIdent($vid, $ident);
            IPS_SetName($vid, $name);
            IPS_SetParent($vid, $parent);
            if (in_array($ident, ["pv_effektiv", "pv_berechnet", "ladeleistung"])) {
                IPS_SetVariableCustomProfile($vid, "~Watt");
            }
            if (@AC_GetLoggingStatus(0, $vid) === false) {
                AC_SetLoggingStatus(0, $vid, true);
                AC_SetAggregationType(0, $vid, 0);
                IPS_ApplyChanges(0);
            }
        }
        SetValue($vid, $value);
    }

    public function UpdateLadeLogik()
    {
        // ==== EINLESEN ALLER IDs & WERTE ====
        $goe_id           = $this->ReadPropertyInteger('GoEChargerID');
        $charger_modus_id = $this->ReadPropertyInteger('WallboxModusID');
        $pv_id            = $this->ReadPropertyInteger('PVErzeugungID');
        $verbrauch_id     = $this->ReadPropertyInteger('HausverbrauchID');
        $batterie_id      = $this->ReadPropertyInteger('BatterieLadungID');
        $wb_power_id      = $this->ReadPropertyInteger('WallboxLeistungID');
        $wb_aktiv_id      = $this->ReadPropertyInteger('WallboxAktivID');
        $phase_var_id     = $this->ReadPropertyInteger('PhaseUmschaltID');
        $manuell_id       = $this->ReadPropertyInteger('ManuellButtonID');
        $pv2car_id        = $this->ReadPropertyInteger('PV2CarButtonID');
        $pv2car_percent_id= $this->ReadPropertyInteger('PV2CarPercentID');
        $zielzeit_id      = $this->ReadPropertyInteger('ZielzeitID');
        $zielzeit_button_id = $this->ReadPropertyInteger('ZielzeitButtonID');
        $socziel_id       = $this->ReadPropertyInteger('SOCZielID');
        $fahrzeug_soc_id  = $this->ReadPropertyInteger('FahrzeugSOCID');
        $modbus_id        = $this->ReadPropertyInteger('ModbusID');
        $soc_id           = $this->ReadPropertyInteger('SOCID');
        $log_var_id       = $this->ReadPropertyInteger('LogVarID');

        // Ladeparameter
        $volt             = $this->ReadPropertyInteger('Volt');
        $min_amp          = $this->ReadPropertyInteger('MinAmp');
        $max_amp          = $this->ReadPropertyInteger('MaxAmp');
        $min_start_watt   = $this->ReadPropertyInteger('MinStartWatt');
        $min_stop_watt    = $this->ReadPropertyInteger('MinStopWatt');
        $soc_limit        = $this->ReadPropertyInteger('SOCMinimum');

        // Werte auslesen
        $pv         = @GetValue($pv_id);
        $verbrauch  = @GetValue($verbrauch_id);
        $batterie   = @GetValue($batterie_id);
        $wb_power   = @GetValue($wb_power_id);
        $wb_aktiv   = @GetValue($wb_aktiv_id);
        $manuell    = @GetValue($manuell_id);
        $pv2car     = @GetValue($pv2car_id);
        $pv2car_percent = @GetValue($pv2car_percent_id);
        $zielzeit   = @GetValue($zielzeit_id);
        $zielzeit_button = @GetValue($zielzeit_button_id);
        $socziel    = @GetValue($socziel_id);
        $fahrzeug_soc = @GetValue($fahrzeug_soc_id);
        $phase_bool = @GetValue($phase_var_id);
        $phasen     = $phase_bool ? 3 : 1;
        $soc        = @GetValue($soc_id);

        // ==== PV-ÜBERSCHUSS-BERECHNUNG ====
        $pv_berechnet = $pv - $verbrauch - $batterie;
        $effektiv = max($pv_berechnet + ($wb_aktiv ? $wb_power : 0), 0);

        // ==== PUFFERFAKTOR DYNAMISCH BERECHNEN ====
        if ($effektiv < 2000) {
            $puffer_faktor = 0.80;
        } elseif ($effektiv < 4000) {
            $puffer_faktor = 0.85;
        } elseif ($effektiv < 6000) {
            $puffer_faktor = 0.90;
        } else {
            $puffer_faktor = 0.93;
        }
        $effektiv = round($effektiv * $puffer_faktor);

        // PV-Berechnung & Effektiv-Wert speichern
        $this->SetOrCreateVar("pv_berechnet", "PV_Berechnet", 2, $pv_berechnet);
        $this->SetOrCreateVar("pv_effektiv", "PV_Ueberschuss_Effektiv", 2, $effektiv);

        // ========== LADEREGELUNG – NUR EIN MODUS AKTIV ==========
        // Button-Logik: Nur ein Modus aktiv oder alle false
        $modi = [$manuell, $pv2car, $zielzeit_button];
        $modus_anzahl = count(array_filter($modi));
        if ($modus_anzahl > 1) {
            $this->LogWB("Warnung: Mehrere Lademodi aktiv! Nur einer sollte aktiv sein.");
            // Optional: Automatisch nur den zuletzt aktivierten Modus erlauben
        }

        // ========== MODUS 1: MANUELL (VOLLE LEISTUNG) ==========
        if ($manuell) {
            $ladeleistung = $phasen * $volt * $max_amp;
            GOeCharger_SetCurrentChargingWatt($goe_id, $ladeleistung, $min_amp);
            RequestAction($charger_modus_id, 2);
            $this->SetOrCreateVar("ladeleistung", "Geplante_Ladeleistung", 2, $ladeleistung);
            $this->LogWB("⚡ Manueller Modus aktiv → $ladeleistung W");
            return;
        }

        // ========== MODUS 2: PV2Car-Prozentual ==========
        if ($pv2car) {
            $prozent = max(0, min(100, $pv2car_percent));
            $ladeleistung = round($effektiv * ($prozent / 100));
            $ladeleistung = min($ladeleistung, $phasen * $volt * $max_amp);
            if ($ladeleistung > 0) {
                GOeCharger_SetCurrentChargingWatt($goe_id, $ladeleistung, $min_amp);
                RequestAction($charger_modus_id, 2);
                $this->SetOrCreateVar("ladeleistung", "Geplante_Ladeleistung", 2, $ladeleistung);
                $this->LogWB("🔆 PV2Car-Modus: $prozent% von $effektiv W → $ladeleistung W");
            } else {
                GOeCharger_SetCurrentChargingWatt($goe_id, 0);
                RequestAction($charger_modus_id, 0);
                $this->SetOrCreateVar("ladeleistung", "Geplante_Ladeleistung", 2, 0);
                $this->LogWB("PV2Car: Zu wenig PV-Überschuss ($effektiv W)");
            }
            return;
        }

        // ========== MODUS 3: Zielladung bis Uhrzeit/SOC ==========
        if ($zielzeit_button) {
            // Annahmen: Zielladung immer bis 06:00 Uhr (künftig Zielzeit-Variable)
            $ziel_soc = (int)$socziel;
            $auto_soc = (int)$fahrzeug_soc;
            $akku_kapazitaet = 52; // kWh, anpassbar für ID.3 Pure
            $bedarf_kwh = max(0, ($ziel_soc - $auto_soc) * $akku_kapazitaet / 100);
            $jetzt = time();
            $ziel_unix = strtotime(date('Y-m-d') . ' 06:00:00'); // fix: 06:00 Uhr

            // Wenn Zielzeit in der Vergangenheit: nächsten Tag
            if ($ziel_unix <= $jetzt) $ziel_unix += 86400;

            $restzeit_stunden = ($ziel_unix - $jetzt) / 3600;
            $leistung_watt = ($bedarf_kwh * 1000) / max($restzeit_stunden, 0.5); // min. 0.5h

            $ladeleistung = min($leistung_watt, $phasen * $volt * $max_amp);
            if ($ladeleistung > 0) {
                GOeCharger_SetCurrentChargingWatt($goe_id, $ladeleistung, $min_amp);
                RequestAction($charger_modus_id, 2);
                $this->SetOrCreateVar("ladeleistung", "Geplante_Ladeleistung", 2, $ladeleistung);
                $this->LogWB("⏰ Zielladung: Ziel $ziel_soc% um 06:00, $bedarf_kwh kWh fehlen → $ladeleistung W");
            } else {
                GOeCharger_SetCurrentChargingWatt($goe_id, 0);
                RequestAction($charger_modus_id, 0);
                $this->SetOrCreateVar("ladeleistung", "Geplante_Ladeleistung", 2, 0);
                $this->LogWB("Zielladung: Ziel bereits erreicht oder keine Restladung nötig");
            }
            return;
        }

        // ========== MODUS 4: Nur PV-Überschuss ==========
        if ($effektiv < $min_start_watt) {
            GOeCharger_SetCurrentChargingWatt($goe_id, 0);
            RequestAction($charger_modus_id, 0);
            $this->SetOrCreateVar("ladeleistung", "Geplante_Ladeleistung", 2, 0);
            $this->LogWB("⚠️ Kein ausreichender PV-Überschuss (<$min_start_watt W) – Ladeleistung = 0");
            return;
        }
        // ========== LADESTEUERUNG: PV-Überschuss (Standard) ==========

        $ladeleistung = min($effektiv, $phasen * $volt * $max_amp);
        $amp = round($ladeleistung / ($volt * $phasen), 1);

        GOeCharger_SetCurrentChargingWatt($goe_id, $ladeleistung, $min_amp);
        RequestAction($charger_modus_id, 2);
        $this->SetOrCreateVar("ladeleistung", "Geplante_Ladeleistung", 2, $ladeleistung);
        $this->LogWB("✅ PV-Überschuss geladen: $ladeleistung W ($amp A, $phasen-phasig)");

        // ========== HYSTERESE-ZÄHLER für Phasenumschaltung ==========
        $hyst_up_id = @IPS_GetObjectIDByIdent("hysterese_up", $this->InstanceID);
        if ($hyst_up_id === false) {
            $hyst_up_id = IPS_CreateVariable(1);
            IPS_SetIdent($hyst_up_id, "hysterese_up");
            IPS_SetName($hyst_up_id, "Hysterese_Zähler_3Phasen");
            IPS_SetParent($hyst_up_id, $this->InstanceID);
        }
        $hyst_dn_id = @IPS_GetObjectIDByIdent("hysterese_dn", $this->InstanceID);
        if ($hyst_dn_id === false) {
            $hyst_dn_id = IPS_CreateVariable(1);
            IPS_SetIdent($hyst_dn_id, "hysterese_dn");
            IPS_SetName($hyst_dn_id, "Hysterese_Zähler_1Phase");
            IPS_SetParent($hyst_dn_id, $this->InstanceID);
        }

        // Hysterese: 3× über 4200 W auf 3-phasig, 3× unter 1000 W auf 1-phasig
        if ($effektiv >= 4200) {
            SetValue($hyst_up_id, GetValue($hyst_up_id) + 1);
        } else {
            SetValue($hyst_up_id, 0);
        }
        if ($effektiv < 1000) {
            SetValue($hyst_dn_id, GetValue($hyst_dn_id) + 1);
        } else {
            SetValue($hyst_dn_id, 0);
        }

        // Phasenumschaltung
        $phase_bool = @GetValue($phase_var_id);
        if (GetValue($hyst_up_id) >= 3 && !$phase_bool) {
            // Auf 3-phasig schalten
            RequestAction($phase_var_id, true);
            $this->LogWB("🔄 Umschalten auf 3-phasig (Hysterese Up)");
        }
        if (GetValue($hyst_dn_id) >= 3 && $phase_bool) {
            // Auf 1-phasig schalten
            RequestAction($phase_var_id, false);
            $this->LogWB("🔄 Umschalten auf 1-phasig (Hysterese Down)");
        }

        // ===== Ende der UpdateLadeLogik =====
    }
}
?>

