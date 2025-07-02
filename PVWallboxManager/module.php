<?php

/**
 * PVWallboxManager
 * Modularer Blueprint – jede Funktion einzeln gekapselt
 * Siegfried Pesendorfer, 2025
 */
class PVWallboxManager extends IPSModule
{
    // === 1. Initialisierung ===

    /** @inheritDoc */
    public function Create()
    {
        parent::Create();
        // === 1. Modulsteuerung ===
        $this->RegisterPropertyBoolean('ModulAktiv', true);
        $this->RegisterPropertyBoolean('DebugLogging', false);

        // === 2. Wallbox-Konfiguration ===
        $this->RegisterPropertyInteger('GOEChargerID', 0);
        $this->RegisterPropertyInteger('MinAmpere', 6);
        $this->RegisterPropertyInteger('MaxAmpere', 16);
        $this->RegisterPropertyFloat('MinLadeWatt', 1400); // Standardwert nach Bedarf anpassen
        $this->RegisterPropertyInteger('StartHysterese', 0);
        $this->RegisterPropertyFloat('MinStopWatt', -300);
        $this->RegisterPropertyInteger('StopHysterese', 0);

        // === 3. Energiequellen ===
        $this->RegisterPropertyInteger('PVErzeugungID', 0);
        $this->RegisterPropertyString('PVErzeugungEinheit', 'W');
        $this->RegisterPropertyInteger('NetzeinspeisungID', 0);
        $this->RegisterPropertyString('NetzeinspeisungEinheit', 'W');
        $this->RegisterPropertyBoolean('InvertNetzeinspeisung', false);
        $this->RegisterPropertyInteger('HausverbrauchID', 0);
        $this->RegisterPropertyString('HausverbrauchEinheit', 'W');
        $this->RegisterPropertyBoolean('InvertHausverbrauch', false);
        $this->RegisterPropertyInteger('BatterieladungID', 0);
        $this->RegisterPropertyString('BatterieladungEinheit', 'W');
        $this->RegisterPropertyBoolean('InvertBatterieladung', false);
        $this->RegisterPropertyInteger('RefreshInterval', 30);

        // === 4. Phasenumschaltung ===
        $this->RegisterPropertyInteger('Phasen', 3);
        $this->RegisterPropertyFloat('Phasen1Schwelle', 1000);
        $this->RegisterPropertyInteger('Phasen1Limit', 3);
        $this->RegisterPropertyFloat('Phasen3Schwelle', 4200);
        $this->RegisterPropertyInteger('Phasen3Limit', 3);

        // === 5. Intelligente Logik ===
        $this->RegisterPropertyBoolean('DynamischerPufferAktiv', true);
        $this->RegisterPropertyBoolean('NurMitFahrzeug', false);
        $this->RegisterPropertyBoolean('AllowBatteryDischarge', true);
        $this->RegisterPropertyInteger('HausakkuSOCID', 0);
        $this->RegisterPropertyInteger('HausakkuSOCVollSchwelle', 90);

        // === 6. Fahrzeugdaten & Ziel-SOC ===
        $this->RegisterPropertyBoolean('UseCarSOC', false);
        $this->RegisterPropertyInteger('CarSOCID', 0);
        $this->RegisterPropertyInteger('CarSOCFallback', 0);
        $this->RegisterPropertyInteger('CarTargetSOCID', 0);
        $this->RegisterPropertyInteger('CarTargetSOCFallback', 0);
        $this->RegisterPropertyInteger('MaxAutoWatt', 11000);
        $this->RegisterPropertyFloat('CarBatteryCapacity', 52.0);
        $this->RegisterPropertyBoolean('AlwaysUseTargetSOC', false);

        // === 7. Strompreis-Börse / Forecast ===
        $this->RegisterPropertyBoolean('UseMarketPrices', false);
        $this->RegisterPropertyString('MarketPriceProvider', 'awattar_at');
        $this->RegisterPropertyString('MarketPriceAPI', '');
        $this->RegisterPropertyInteger('MarketPriceInterval', 30);

        // === Modul-Variablen für Visualisierung, Status, Lademodus etc. ===
        $this->RegisterVariableFloat('PV_Ueberschuss', 'PV-Überschuss (W)', '~Watt', 10);
        IPS_SetIcon($this->GetIDForIdent('PV_Ueberschuss'), 'solar-panel');
        $this->RegisterVariableString('Wallbox_Status', 'Wallbox Status', '', 20);
        IPS_SetIcon($this->GetIDForIdent('Wallbox_Status'), 'charging-station');
        $this->RegisterVariableInteger('CarChargeTargetTime', 'Ziel-Ladezeit', '~UnixTimestampTime', 42);
        IPS_SetIcon($this->GetIDForIdent('CarChargeTargetTime'), 'clock');
        $this->RegisterVariableInteger('AktiverLademodus', 'Aktiver Lademodus', 'PVWM.Lademodus', 50);

        IPS_SetIcon($this->GetIDForIdent('AktiverLademodus'), 'lightbulb');

        // Weitere Variablen nach Bedarf!
        $this->RegisterVariableInteger('HystereseZaehler', 'Phasen-Hysteresezähler', '', 60);
        $this->EnsureLademodusProfile();
        // Timer für Berechnungsintervall
        $this->RegisterTimer('UpdateCharging', $this->ReadPropertyInteger('RefreshInterval') * 1000, 'PVWBM_UpdateCharging($_IPS[\'TARGET\']);');

    }

    /** @inheritDoc */
    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Timer-Intervall ggf. neu setzen, wenn RefreshInterval geändert wurde
        $this->SetTimerInterval('UpdateCharging', $this->ReadPropertyInteger('RefreshInterval') * 1000);
    }

    public function UpdateCharging()
    {
        // Modul aktiv? Sonst abbrechen!
        if (!$this->ReadPropertyBoolean('ModulAktiv')) {
            $this->Log("Modul ist deaktiviert. Keine Aktion.", 'warn');
            return;
        }

        // 1. Energiewerte lesen
        $pv          = $this->LesePVErzeugung();
        $haus        = $this->LeseHausverbrauch();
        $batt        = $this->LeseBatterieleistung();
        $wb_leistung = $this->LeseWallboxLeistung();

        // 2. PV-Überschuss berechnen (mit/ohne Puffer)
        $roh_ueberschuss = $this->BerechnePVUeberschuss($pv, $haus, $batt, $wb_leistung);
        $ueberschuss     = $this->BerechnePVUeberschussMitPuffer($roh_ueberschuss);

        // 3. Aktiven Lademodus bestimmen
        $modus = $this->ErmittleAktivenLademodus();

        // 4. Ladeleistung ermitteln
        switch ($modus) {
            case 'manuell':
                $ladeleistung = $this->BerechneLadeleistungManuell();
                break;
            case 'pv2car':
                $prozent = $this->GetPV2CarProzent(); // Dummy
                $ladeleistung = $this->BerechneLadeleistungPV2Car($ueberschuss, $prozent);
                break;
            case 'zielzeit':
                $istSOC = $this->LeseFahrzeugSOC();
                $zielSOC = $this->LeseZielSOC();
                $zielzeit = $this->LeseZielzeit();
                $maxLeistung = $this->ReadPropertyInteger('MaxAutoWatt');
                $ladeleistung = $this->BerechneLadeleistungZielzeit($zielSOC, $istSOC, $zielzeit, $maxLeistung);
                break;
            case 'strompreis':
                $preis = $this->GetCurrentMarketPrice(); // Dummy
                $maxPreis = $this->GetMaxAllowedPrice(); // Dummy
                $ladeleistung = $this->BerechneLadeleistungStrompreis($preis, $maxPreis);
                break;
            case 'nurpv':
            default:
                $ladeleistung = $this->BerechneLadeleistungNurPV($ueberschuss);
                break;
        }

        // 5. Phasenumschaltung prüfen und ggf. umschalten
        $this->PruefePhasenumschaltung($ladeleistung);

        // 6. Ladeleistung setzen/Wallbox steuern
        if ($ladeleistung > 0) {
            $this->SetzeLadeleistung($ladeleistung);
            $status = "Laden: ".round($ladeleistung)." W im Modus: ".$this->GetLademodusText($this->GetValue('AktiverLademodus'));
        } else {
            $this->DeaktiviereLaden();
            $status = "Nicht laden (kein Überschuss/Modus)";
        }

        // 7. Statusvariable und Logging
        $this->SetLademodusStatus($status);
        $this->LogDebugData([
            'PV'          => $pv,
            'Haus'        => $haus,
            'Batterie'    => $batt,
            'WB-Leistung' => $wb_leistung,
            'Überschuss'  => $ueberschuss,
            'Modus'       => $modus,
            'Ladeleistung'=> $ladeleistung
        ]);
    }

    private function EnsureLademodusProfile()
    {
        $profil = 'PVWM.Lademodus';
        if (!IPS_VariableProfileExists($profil)) {
            IPS_CreateVariableProfile($profil, 1); // 1 = Integer
            IPS_SetVariableProfileIcon($profil, 'ElectricCar');
            IPS_SetVariableProfileValues($profil, 0, 4, 1);
            IPS_SetVariableProfileAssociation($profil, 0, 'Nur PV', '', -1);
            IPS_SetVariableProfileAssociation($profil, 1, 'Manuell', 'lightbulb', -1);
            IPS_SetVariableProfileAssociation($profil, 2, 'PV2Car', 'solar-panel', -1);
            IPS_SetVariableProfileAssociation($profil, 3, 'Zielzeit', 'clock', -1);
            IPS_SetVariableProfileAssociation($profil, 4, 'Strompreis', 'euro', -1);
        }
    }

    private function LeseEnergiewert($id, $einheit = 'W', $invert = false)
    {
        if ($id > 0 && @IPS_VariableExists($id)) {
            $wert = (float) GetValue($id);
            if ($einheit === 'kW') {
                $wert *= 1000.0;
            }
            if ($invert) {
                $wert *= -1.0;
            }
            return $wert;
        }
        return 0.0;
    }

    /** PV-Erzeugung (Watt, immer positiv) */
    private function LesePVErzeugung()
    {
        return $this->LeseEnergiewert(
            $this->ReadPropertyInteger('PVErzeugungID'),
            $this->ReadPropertyString('PVErzeugungEinheit'),
            false
        );
    }

    /** Netzeinspeisung (Watt, positiv = Einspeisung) */
    private function LeseNetzeinspeisung()
    {
        return $this->LeseEnergiewert(
            $this->ReadPropertyInteger('NetzeinspeisungID'),
            $this->ReadPropertyString('NetzeinspeisungEinheit'),
            $this->ReadPropertyBoolean('InvertNetzeinspeisung')
        );
    }

    /** Hausverbrauch (Watt, immer positiv) */
    private function LeseHausverbrauch()
    {
        return $this->LeseEnergiewert(
            $this->ReadPropertyInteger('HausverbrauchID'),
            $this->ReadPropertyString('HausverbrauchEinheit'),
            $this->ReadPropertyBoolean('InvertHausverbrauch')
        );
    }

    /** Batterie-Leistung (Watt, positiv = Laden, negativ = Entladen) */
    private function LeseBatterieleistung()
    {
        return $this->LeseEnergiewert(
            $this->ReadPropertyInteger('BatterieladungID'),
            $this->ReadPropertyString('BatterieladungEinheit'),
            $this->ReadPropertyBoolean('InvertBatterieladung')
        );
    }

    /** Holt die aktuelle Ladeleistung aus der GO-e Instanz */
    private function LeseWallboxLeistung()
    {
        $id = $this->ReadPropertyInteger('GOEChargerID');
        if ($id > 0 && @IPS_InstanceExists($id)) {
            // Replace with actual method if needed (z. B. GOeCharger_GetPowerToCar)
            return (float) @GOeCharger_GetPowerToCar($id);
        }
        return 0.0;
    }

    // === 3. Überschuss-Berechnung ===

    /** Berechnet den aktuellen PV-Überschuss. */
    private function BerechnePVUeberschuss($pv, $verbrauch, $batterie, $wallbox = 0)
    {
        // Berechnung des Überschusses
        return $pv - $verbrauch - $batterie + $wallbox;
    }

    /** Überschuss ggf. mit dynamischem Puffer/Hysterese berechnen */
    private function BerechnePVUeberschussMitPuffer($rohwert)
    {
        if ($this->ReadPropertyBoolean('DynamischerPufferAktiv')) {
            // Beispiel: Pufferwert um den Faktor 0.9 (Dämpfung)
            $puffer = 0.9;
            return $rohwert * $puffer;
        }
        return $rohwert;
    }

    /** Berechnet den PV-Überschuss unter Berücksichtigung der Hysterese für Start- und Stoppwerte.*/
    private function BerechneUeberschussMitHysterese($ueberschuss, $start = true)
    {
    // Hole die Hysterese aus den Properties (Start oder Stopp)
    $hysterese = $start ? $this->ReadPropertyInteger('StartHysterese') : $this->ReadPropertyInteger('StopHysterese');
    
    if ($start) {
        // Beispiel: Für Startwert überschreiten muss 3 Zyklen
        if ($ueberschuss >= $this->ReadPropertyFloat('MinLadeWatt')) {
            // Zähler erhöhen (z. B. in einer Variablen für Zyklen speichern)
            return true;
        }
    } else {
        // Beispiel: Für Stoppwert Unterschreiten muss 3 Zyklen
        if ($ueberschuss <= $this->ReadPropertyFloat('MinStopWatt')) {
            // Zähler erhöhen (z. B. in einer Variablen für Zyklen speichern)
            return true;
        }
    }

    return false;
}

    // === 4. Modussteuerung ===

    /** Welcher Lademodus ist aktiv? (manuell/PV2Car/Zielzeit/NurPV/...) */
    private function ErmittleAktivenLademodus()
    {
        $id = @$this->GetIDForIdent('AktiverLademodus');
        $modus = ($id > 0) ? GetValue($id) : 0;
        return 'nurpv'; // Solange keine weiteren Modi implementiert sind, immer 'nurpv'

        // (Pseudologik – baue nach deinen Regeln aus)
        // Reihenfolge: Manuell > PV2Car > Zielzeit > NurPV > Strompreis

        // Beispiel: Prüfen, ob Volllademodus aktiviert ist (Variable oder Property)
        if (/*...*/ false) return 'manuell';
        if (/*...*/ false) return 'pv2car';
        if (/*...*/ false) return 'zielzeit';
        if (/*...*/ false) return 'strompreis';
    }

    /** Manuell-Modus behandeln */
    private function ModusManuell()
    {
        // TODO
    }

    /** PV2Car-Modus behandeln */
    private function ModusPV2Car()
    {
        // TODO
    }

    /** Zielzeit-Lademodus behandeln */
    private function ModusZielzeit()
    {
        // TODO
    }

    /** Nur-PV-Lademodus behandeln */
    private function ModusNurPV()
    {
        // TODO
    }

    /** Strompreisgesteuerter Lademodus behandeln */
    private function ModusStrompreis()
    {
        // TODO
    }

    // === 5. Ladeleistungs-Berechnung (je Modus) ===

    /** Volllademodus: Immer maximale Ladeleistung (max aus Property) */
    private function BerechneLadeleistungManuell()
    {
        return $this->ReadPropertyInteger('MaxAmpere') * 230 * $this->ReadPropertyInteger('Phasen'); // z.B. für Drehstrom
    }

    /** PV2Car-Modus: Anteil vom PV-Überschuss nutzen (Prozentslider) */
    private function BerechneLadeleistungPV2Car($ueberschuss, $prozent)
    {
        $wert = $ueberschuss * ($prozent / 100.0);
        $max = $this->ReadPropertyInteger('MaxAmpere') * 230 * $this->ReadPropertyInteger('Phasen');
        return min($wert, $max);
    }

    /** Zielzeitladung: Ladeleistung berechnen, um Ziel-SoC bis Zielzeit zu erreichen */
    private function BerechneLadeleistungZielzeit($sollSOC, $istSOC, $zielzeit, $maxLeistung)
    {
        // Einfaches Beispiel: Berechne nötige Energie & teile durch verfügbare Zeit
        $akku_kwh = $this->ReadPropertyFloat('CarBatteryCapacity');
        $delta_soc = max(0, $sollSOC - $istSOC); // %
        $bedarf_kwh = $akku_kwh * $delta_soc / 100.0;

        $jetzt = time();
        $verbleibende_stunden = max(1, ($zielzeit - $jetzt) / 3600.0);

        $erforderliche_leistung = ($bedarf_kwh / $verbleibende_stunden) * 1000; // kW → W

        // Maximal erlaubte Ladeleistung beachten
        return min($erforderliche_leistung, $maxLeistung);
    }

    /** NurPV: Ladeleistung entspricht ausschließlich dem verfügbaren Überschuss */
    private function BerechneLadeleistungNurPV($ueberschuss)
    {
        $minA = $this->ReadPropertyInteger('MinAmpere');
        $maxA = $this->ReadPropertyInteger('MaxAmpere');
        $phasen = $this->ReadPropertyInteger('Phasen');
        $spannung = 230;
        
        $minWatt = $minA * $spannung * $phasen;
        $maxWatt = $maxA * $spannung * $phasen;

        $ladeleistung = min(max($ueberschuss, $minWatt), $maxWatt);

        if ($ladeleistung < $minWatt) {
            $ladeleistung = 0;
        }
        return $ladeleistung;
    }

    /** Strompreis-Modus: Ladeleistung, wenn Preis <= maxPreis, sonst 0 */
    private function BerechneLadeleistungStrompreis($preis, $maxPreis)
    {
        if ($preis <= $maxPreis) {
            return $this->ReadPropertyInteger('MaxAmpere') * 230 * $this->ReadPropertyInteger('Phasen');
        }
        return 0; 
    }

    private function GetPV2CarProzent()
    {
        return 0;
    }

    private function GetCurrentMarketPrice()
    {
         return 0;
        }
    
    private function GetMaxAllowedPrice()
    {
        return 0;
    }

    // === 6. Phasenumschaltung / Hysterese ===

    /** Prüfe, ob Phasenumschaltung nötig ist (inkl. Hysterese) */
    private function PruefePhasenumschaltung($ladeleistung)
    {
        // Ermittlung der aktuellen Phase
        $aktuellePhasen = $this->ReadPropertyInteger('Phasen');
        $instanzID = $this->ReadPropertyInteger('GOEChargerID');

        // Startbedingungen für Phasenumschaltung (z. B. bei Überschuss unter/über Schwellenwerten)
        if ($aktuellePhasen == 3) {
            // Wenn die Leistung unter die 1-phasige Schwelle fällt, und Hysterese erfüllt ist
            if ($ladeleistung < $this->ReadPropertyFloat('Phasen1Schwelle')) {
                $this->UmschaltenAuf1Phasig($instanzID);
            }
        } else {
            // Wenn die Leistung über die 3-phasige Schwelle steigt, und Hysterese erfüllt ist
            if ($ladeleistung > $this->ReadPropertyFloat('Phasen3Schwelle')) {
                $this->UmschaltenAuf3Phasig($instanzID);
            }
        }
    }

    /** Schalte auf 1-phasig */
    private function UmschaltenAuf1Phasig($instanzID)
    {
        // Prüfe, ob wir wirklich auf 1-phasig umschalten müssen
        if ($this->ReadPropertyInteger('Phasen') != 1) {
            // Wallbox auf 1-phasig umschalten
            GOeCharger_SetSinglePhaseCharging($instanzID, true);
            $this->WritePropertyInteger('Phasen', 1);  // Phasen umschalten
        }
    }

    /** Schalte auf 3-phasig */
    private function UmschaltenAuf3Phasig($instanzID)
    {
        /// Prüfe, ob wir wirklich auf 3-phasig umschalten müssen
        if ($this->ReadPropertyInteger('Phasen') != 3) {
            // Wallbox auf 3-phasig umschalten
            GOeCharger_SetSinglePhaseCharging($instanzID, false);
            $this->WritePropertyInteger('Phasen', 3);  // Phasen umschalten
        }
    }

    /** Zählt Hysterese-Schwellwerte hoch/runter */
    private function VerwalteHystereseZaehler($richtung, $schwellwert)
    {
        $zaehler = $this->GetValue('HystereseZaehler');
        if ($richtung == 'hoch') {
            if ($zaehler < $schwellwert) {
                $zaehler++;
            }
        } elseif ($richtung == 'runter') {
            if ($zaehler > 0) {
                $zaehler--;
            }
        }
        // Speichern des Zählers
        $this->SetValue('HystereseZaehler', $zaehler);
    }

    // === 7. Fahrzeugstatus/SOC/Zielzeit ===

    /** Prüft, ob ein Fahrzeug verbunden ist */
    private function IstFahrzeugVerbunden()
    {
        $goeID = $this->ReadPropertyInteger('GOEChargerID');
        if ($goeID > 0 && @IPS_InstanceExists($goeID)) {
            // Typisch: Status-Abfrage via GO-e Instanz (z. B. 2 = lädt, 3 = wartet, etc.)
            $status = @GOeCharger_GetStatus($goeID); // je nach API ggf. anpassen!
            return in_array($status, [2,3,4]); // Werte je nach API
        }
        return false;
    }

    /** Liest den aktuellen SoC des Fahrzeugs (aus Variable, mit Fallback) */
    private function LeseFahrzeugSOC()
    {
        $socID = $this->ReadPropertyInteger('CarSOCID');
        if ($socID > 0 && @IPS_VariableExists($socID)) {
            return (float)GetValue($socID);
        }
        return (float)$this->ReadPropertyInteger('CarSOCFallback');
    }

    /** Liest den Ziel-SoC des Fahrzeugs (aus Variable, mit Fallback) */
    private function LeseZielSOC()
    {
        $targetID = $this->ReadPropertyInteger('CarTargetSOCID');
        if ($targetID > 0 && @IPS_VariableExists($targetID)) {
            return (float)GetValue($targetID);
        }
        return (float)$this->ReadPropertyInteger('CarTargetSOCFallback');
    }

    /** Liest die Zielzeit für die Fertigladung (bspw. 06:00 Uhr als Timestamp oder Zeitwert) */
    private function LeseZielzeit()
    {
        $varID = @$this->GetIDForIdent('CarChargeTargetTime');
        if ($varID > 0) {
            return (int)GetValue($varID);
        }
        // Fallback: z. B. 6:00 Uhr heute
        return strtotime('today 06:00');
    }

    /** Berechnet geschätzte Ladedauer bis zum Ziel (in Stunden) */
    private function BerechneLadedauerBisZiel($istSOC, $sollSOC, $ladeleistung)
    {
        $akku_kwh = $this->ReadPropertyFloat('CarBatteryCapacity');
        $delta_soc = max(0, $sollSOC - $istSOC);
        $bedarf_kwh = $akku_kwh * $delta_soc / 100.0;
        // Ladeleistung in kW
        $ladeleistung_kw = $ladeleistung / 1000.0;
        if ($ladeleistung_kw > 0.1) {
            return $bedarf_kwh / $ladeleistung_kw;
        }
        return INF; // "Unendlich", wenn keine Ladeleistung verfügbar
    }

    /** Berechnet Startzeitpunkt der Ladung (Timestamp) */
    private function BerechneLadestartzeit($zielzeit, $dauer_stunden)
    {
        return $zielzeit - (int)($dauer_stunden * 3600);
    }

    // === 8. Wallbox-Steuerung ===

    /** Setzt die gewünschte Ladeleistung (Watt) */
    private function SetzeLadeleistung($leistung)
    {
        $goeID = $this->ReadPropertyInteger('GOEChargerID');
        if ($goeID > 0 && @IPS_InstanceExists($goeID)) {
            @GOeCharger_SetCurrentChargingWatt($goeID, (int)$leistung);
        }
    }

    /** Setzt den Wallbox-Modus (optional: z. B. für Phasenumschaltung/Status) */
    private function SetzeWallboxModus($modus)
    {
        // Hier kannst du je nach Wallbox Typ/Modul z. B. zwischen Sofortladen, Überschuss etc. umschalten.
        // Bei go-e gibt's dafür oft keine extra Methode – Status könnte aber über Variable o. Ä. gesetzt werden.
    }

    /** Deaktiviert die Ladung komplett */
    private function DeaktiviereLaden()
    {
        $goeID = $this->ReadPropertyInteger('GOEChargerID');
        if ($goeID > 0 && @IPS_InstanceExists($goeID)) {
            @GOeCharger_SetCurrentChargingWatt($goeID, 0);
        }
    }

    // === 9. Logging / Statusmeldungen ===

    /** Loggt eine Nachricht mit Level (info, warn, error, debug) */
    private function Log($msg, $level = 'info')
    {
        $prefix = "[PVWallboxManager]";
        switch ($level) {
            case 'warn':
            case 'warning':
                IPS_LogMessage($prefix, "⚠️ $msg");
                break;
            case 'error':
                IPS_LogMessage($prefix, "❌ $msg");
                break;
            case 'debug':
                if ($this->ReadPropertyBoolean('DebugLogging')) {
                    IPS_LogMessage($prefix, "🐞 $msg");
                }
                break;
            default:
                IPS_LogMessage($prefix, $msg);
        }
    }

    /** Setzt Statusanzeige im Modul (WebFront, Variablen, ...) */
    private function SetLademodusStatus($msg)
    {
        $this->SetValue('Wallbox_Status', $msg);
    }

    /** Loggt aktuelle Energiedaten für Debug */
    private function LogDebugData($daten)
    {
        if ($this->ReadPropertyBoolean('DebugLogging')) {
            $this->Log('Debug-Daten: ' . json_encode($daten), 'debug');
        }
    }

    // === 10. RequestAction-Handler ===

    /** Handler für WebFront-Aktionen/Buttons */
    public function RequestAction($ident, $value)
    {
        switch ($ident) {
            case 'AktiverLademodus':
                $this->SetValue($ident, $value);
                $this->Log("Lademodus umgeschaltet auf: ".$this->GetLademodusText($value), 'info');
                $this->UpdateCharging(); // Nach jedem Wechsel berechnen
                break;
            // ... weitere Variablen/Button-Handler
            default:
                throw new Exception("Invalid ident: $ident");
        }
    }

    // === 11. Timer/Cron-Handling ===

    /** Startet regelmäßige Berechnung/Ladesteuerung */
    private function StarteRegelmaessigeBerechnung()
    {
        $interval = $this->ReadPropertyInteger('RefreshInterval') * 1000;
        $this->SetTimerInterval('UpdateCharging', $interval);
    }

    /** Stoppt regelmäßige Berechnung/Ladesteuerung */
    private function StoppeRegelmaessigeBerechnung()
    {
        $this->SetTimerInterval('UpdateCharging', 0);
    }

    // === 12. Hilfsfunktionen ===

    /** Hilfsfunktion: Text zum Moduswert */
    private function GetLademodusText($mode)
    {
        switch ($mode) {
            case 1: return "Manuell";
            case 2: return "PV2Car";
            case 3: return "Zielzeit";
            case 4: return "Strompreis";
            default: return "Nur PV";
        }
    }

    /** Formatiert einen Timestamp lesbar */
    private function FormatiereZeit($timestamp)
    {
        // TODO
    }

    /** Liest Variable mit Typ und ggf. Invertierung */
    private function LeseVariable($id, $typ = 'float', $invert = false)
    {
        // TODO
    }

    /** Validiert Energiedaten vor Berechnung */
    private function WerteValidieren($daten)
    {
        // TODO
    }
}
