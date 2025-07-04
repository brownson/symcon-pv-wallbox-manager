<?php
/**
 * PVWallboxManager
 * Modularer Blueprint – jede Funktion einzeln gekapselt
 * Siegfried Pesendorfer, 2025
 */
class PVWallboxManager extends IPSModule
{
    // === Private Klassen ===
    private $ladeStartZaehler = 0;
    private $ladeStopZaehler = 0;
    private $StartHystereseCounter = 0;
    private $StopHystereseCounter = 0;


    // === 1. Initialisierung ===

    /** @inheritDoc */
    public function Create()
    {
        parent::Create();
        // === 1. Modulsteuerung ===
        $this->RegisterPropertyBoolean('ModulAktiv', true);
        $this->RegisterPropertyBoolean('DebugLogging', false);

        // === 2. Wallbox-Konfiguration ===
        $this->RegisterPropertyString('WallboxIP', '');
        $this->RegisterPropertyString('WallboxAPIKey', '');
        $this->RegisterPropertyInteger('GOeChargerID', 0);
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
        $this->RegisterVariableFloat('PV_Ueberschuss', '☀️ PV-Überschuss (W)', '~Watt', 10);
        IPS_SetIcon($this->GetIDForIdent('PV_Ueberschuss'), 'solar-panel');

        // Hausverbrauch (W)
        $this->RegisterVariableFloat('Hausverbrauch_W', '🏠 Hausverbrauch (W)', '~Watt', 12);
        IPS_SetIcon($this->GetIDForIdent('Hausverbrauch_W'), 'home');

        // Wallbox-Leistung (W)
        $this->RegisterVariableFloat('WB_Ladeleistung_Soll', '🔌 WB geplante Ladeleistung (W)', '~Watt', 24);
        IPS_SetIcon($this->GetIDForIdent('WB_Ladeleistung_Soll'), 'wand');
        $this->RegisterVariableFloat('WB_Ladeleistung_Ist', '🔌 WB aktuelle Leistung zum Fahrzeug (W)', '~Watt', 25);
        IPS_SetIcon($this->GetIDForIdent('WB_Ladeleistung_Ist'), 'charging-station');
 
        // Hausverbrauch abzügl. Wallbox (W) – wie vorher empfohlen
        $this->RegisterVariableFloat('Hausverbrauch_abz_Wallbox', '🏠 Hausverbrauch abzügl. Wallbox (W)', '~Watt', 15);
        IPS_SetIcon($this->GetIDForIdent('Hausverbrauch_abz_Wallbox'), 'home');

        $this->RegisterVariableString('AccessStateText', 'Wallbox Freigabe-Modus', '', 88);
        IPS_SetIcon($this->GetIDForIdent('AccessStateText'), 'lock');

        $this->RegisterVariableString('Wallbox_Status', 'Wallbox Status', '', 20);
        IPS_SetIcon($this->GetIDForIdent('Wallbox_Status'), 'charging-station');
        $this->RegisterVariableInteger('CarChargeTargetTime', 'Ziel-Ladezeit', '~UnixTimestampTime', 42);
        IPS_SetIcon($this->GetIDForIdent('CarChargeTargetTime'), 'clock');

         // Sicherstellen, dass das Profil existiert (für 'AktiverLademodus')
        $this->EnsureLademodusProfile();
        $this->RegisterVariableInteger('AktiverLademodus', 'Aktiver Lademodus', 'PVWM.Lademodus', 50);

        IPS_SetIcon($this->GetIDForIdent('AktiverLademodus'), 'lightbulb');

        // Weitere Variablen nach Bedarf!
        $this->RegisterVariableInteger('HystereseZaehler', 'Phasen-Hysteresezähler', '', 60);
        $this->RegisterVariableInteger('AktuellePhasen', 'Aktuelle Phasen', '', 80);

        // Timer für Berechnungsintervall
        $this->RegisterTimer('UpdateCharging', $this->ReadPropertyInteger('RefreshInterval') * 1000, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateCharging", 0);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Variablenprofil für Lademodus sicherstellen
        $this->EnsureLademodusProfile();

        // GO-e Charger Instanz-ID holen
        $goeID = $this->ReadPropertyInteger('GOeChargerID');

        // Hysterese-Zähler initialisieren (nur beim ersten Mal)
        if ($this->GetBuffer('StartHystereseCounter') === false) {
            $this->SetBuffer('StartHystereseCounter', 0);
        }
        if ($this->GetBuffer('StopHystereseCounter') === false) {
            $this->SetBuffer('StopHystereseCounter', 0);
        }

        // Ereignis für Fahrzeugstatus anlegen/prüfen
        $this->CreateCarStatusEvent($goeID);

        // Timer setzen oder deaktivieren (optional)
        if ($this->ReadPropertyBoolean('ModulAktiv')) {
            $this->SetTimerInterval('UpdateCharging', $this->ReadPropertyInteger('RefreshInterval') * 1000);
        } else {
            $this->SetTimerInterval('UpdateCharging', 0);
        }
        $this->UpdateAccessStateText(); // <-- hier!
    }


    public function UpdateCharging()
    {
        // Wallbox Werte direkt abfragen ohne zusäzliches Instanz
        $wallboxWerte = $this->HoleGoEWallboxDaten();
        if (!is_array($wallboxWerte)) {
            // Fehlerhandling, ggf. sofort abbrechen oder Fallback
            return;
        }

        $goeID = $this->ReadPropertyInteger('GOeChargerID');
        if ($goeID <= 0 || !@IPS_InstanceExists($goeID)) {
            $this->LogTemplate('warn', "Keine Wallbox-Instanz ausgewählt oder ID ungültig.", "Bitte GO-e Charger im Modul konfigurieren.");
            $this->SetLademodusStatus("Wallbox nicht konfiguriert!");
            return;
        }

        // Prüfe: Nur laden, wenn Fahrzeug verbunden
        if ($this->ReadPropertyBoolean('NurMitFahrzeug') && !$this->IstFahrzeugVerbunden()) {
            $this->SetzeAccessStateV2WennNoetig($goeID, 1); // Gesperrt
            $status = "Bitte das Fahrzeug mit der Wallbox verbinden.";
            $this->SetLademodusStatus($status);
            $this->LogTemplate('info', "Warte auf Fahrzeug.", $status);
            $this->SetLademodusAutoReset();
            $this->UpdateAccessStateText();
            return;
        }

        // Modul aktiv?
        if (!$this->ReadPropertyBoolean('ModulAktiv')) {
            $this->LogTemplate('warn', "PVWallbox-Manager ist deaktiviert.", "Automatische Steuerung aktuell ausgesetzt.");
            return;
        }

        // Energiewerte lesen
        $pv          = $this->LesePVErzeugung();
        $haus        = $this->LeseHausverbrauch();
        $batt        = $this->LeseBatterieleistung();
        $wb_leistung = $this->LeseWallboxLeistung();

        // Rohwert und Puffer berechnen
        $roh_ueberschuss = $this->BerechnePVUeberschuss($pv, $haus, $batt, $wb_leistung);
        list($ueberschuss, $pufferFaktor) = $this->BerechnePVUeberschussMitPuffer($roh_ueberschuss);

        $puffer_prozent = round($pufferFaktor * 100);
        $puffer_diff    = round($roh_ueberschuss - $ueberschuss);

        // Werte schreiben
        $this->SetValueSafe('PV_Ueberschuss', max(0, $ueberschuss), 1 , 'W',);
        $this->SetValueSafe('Hausverbrauch_W', $haus, 1, 'W');
        $haus_abz_wb = max(0, $haus - $wb_leistung);
        $this->SetValueSafe('Hausverbrauch_abz_Wallbox', $haus_abz_wb, 1, 'W');

        // Aktiven Lademodus bestimmen
        $modus = $this->ErmittleAktivenLademodus();

        if (!$this->IstFahrzeugVerbunden()) {
            $this->DeaktiviereLaden(); // ← korrigierte Funktion, die nur noch Mode 1 setzt!
            $status = "Die Wallbox wartet auf ein angestecktes Auto.";
            $this->SetLademodusStatus($status);
            $this->LogTemplate('info', "Kein Fahrzeug verbunden.", $status);
            $this->SetLademodusAutoReset();
            $this->UpdateAccessStateText();
            return;
        }

        // Ladeleistung ermitteln
        switch ($modus) {
            case 'manuell':
                $ladeleistung = $this->BerechneLadeleistungManuell();
                break;
            case 'pv2car':
                $prozent = $this->GetPV2CarProzent();
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
                $preis = $this->GetCurrentMarketPrice();
                $maxPreis = $this->GetMaxAllowedPrice();
                $ladeleistung = $this->BerechneLadeleistungStrompreis($preis, $maxPreis);
                break;
            case 'nurpv':
            default:
                $minLadeWatt     = $this->ReadPropertyInteger('MinLadeWatt');
                $minStopWatt     = $this->ReadPropertyInteger('MinStopWatt');
                $startHysterese  = $this->ReadPropertyInteger('StartHysterese');
                $stopHysterese   = $this->ReadPropertyInteger('StopHysterese');
                $istAmLaden = $this->GetValue('WallboxAktiv');
                $startCounter = (int)$this->GetBuffer('StartHystereseCounter');
                $stopCounter  = (int)$this->GetBuffer('StopHystereseCounter');

                if (!$istAmLaden) {
                    if ($ueberschuss >= $minLadeWatt) {
                        $startCounter++;
                        if ($startCounter >= $startHysterese) {
                            $ladeleistung = $this->BerechneLadeleistungNurPV($ueberschuss);
                            $startCounter = 0;
                            $stopCounter  = 0;
                        } else {
                            $ladeleistung = 0;
                        }
                    } else {
                        $startCounter = 0;
                        $ladeleistung = 0;
                    }
                } else {
                    if ($ueberschuss <= $minStopWatt) {
                        $stopCounter++;
                        if ($stopCounter >= $stopHysterese) {
                            $ladeleistung = 0;
                            $stopCounter  = 0;
                            $startCounter = 0;
                        } else {
                            $ladeleistung = $this->BerechneLadeleistungNurPV($ueberschuss);
                        }
                    } else {
                        $stopCounter = 0;
                        $ladeleistung = $this->BerechneLadeleistungNurPV($ueberschuss);
                    }
                }

                $this->SetBuffer('StartHystereseCounter', $startCounter);
                $this->SetBuffer('StopHystereseCounter', $stopCounter);

                $this->SetValueSafe('WB_Ladeleistung_Soll', $ladeleistung, 1);
                $this->SetValueSafe('WB_Ladeleistung_Ist', $this->LeseWallboxLeistung(), 1);

                $this->LogTemplate('debug', 
                    "PV-Überschuss-Berechnung: PV {$pv} W, Haus {$haus} W, Batterie {$batt} W, Wallbox {$wb_leistung} W, Puffer {$puffer_diff} W ({$puffer_prozent}%), Überschuss {$ueberschuss} W, StartHyst: {$startCounter}/{$startHysterese}, StopHyst: {$stopCounter}/{$stopHysterese}");
                break;
        }

        // Phasenumschaltung prüfen und ggf. umschalten
        $this->PruefePhasenumschaltung($ladeleistung);

    // Ladefreigabe setzen
    if ($ladeleistung > 0) {
        $this->SetzeAccessStateV2WennNoetig($goeID, 2); // Laden erzwingen
        $this->SetzeLadeleistung($ladeleistung); // Ladeleistung, KEIN SetMode!
        $status = "Laden: ".round($ladeleistung)." W im Modus: ".$this->GetLademodusText($this->GetValue('AktiverLademodus'));
    } else {
        $this->SetzeAccessStateV2WennNoetig($goeID, 1); // Gesperrt
        $status = "Nicht laden (kein Überschuss/Modus)";
    }

    // Status und Logging
        $this->SetLademodusStatus($status);
        $this->UpdateAccessStateText();
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
        $id = $this->ReadPropertyInteger('GOeChargerID');
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
    $pufferFaktor = 1.0;
    if ($this->ReadPropertyBoolean('DynamischerPufferAktiv')) {
        if ($rohwert < 2000) {
            $pufferFaktor = 0.80;
        } elseif ($rohwert < 4000) {
            $pufferFaktor = 0.85;
        } elseif ($rohwert < 6000) {
            $pufferFaktor = 0.90;
        } else {
            $pufferFaktor = 0.93;
        }
    }
    $PVUeberschussMitPuffer = $rohwert * $pufferFaktor;
    return [$PVUeberschussMitPuffer, $pufferFaktor];
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

    private function PruefeLadeHysterese($ueberschuss)
    {
        $startSchwelle   = $this->ReadPropertyFloat('MinLadeWatt');
        $stopSchwelle    = $this->ReadPropertyFloat('MinStopWatt');
        $startHysterese  = max(1, $this->ReadPropertyInteger('StartHysterese'));
        $stopHysterese   = max(1, $this->ReadPropertyInteger('StopHysterese'));

        // START-HYSTERESE
        if ($ueberschuss >= $startSchwelle) {
            $this->ladeStartZaehler++;
            $this->ladeStopZaehler = 0;
            $this->LogTemplate('debug', "Lade-Hysterese (volatile): Start-Zähler {$this->ladeStartZaehler}/$startHysterese (Schwelle: {$startSchwelle} W, Überschuss: {$ueberschuss} W)");
            if ($this->ladeStartZaehler >= $startHysterese) {
                return true;
            }
        }
        // STOP-HYSTERESE
        elseif ($ueberschuss <= $stopSchwelle) {
            $this->ladeStopZaehler++;
            $this->ladeStartZaehler = 0;
            $this->LogTemplate('debug', "Lade-Hysterese (volatile): Stop-Zähler {$this->ladeStopZaehler}/$stopHysterese (Schwelle: {$stopSchwelle} W, Überschuss: {$ueberschuss} W)");
            if ($this->ladeStopZaehler >= $stopHysterese) {
                return false;
            }
        }
        // Bedingungen nicht erfüllt: Zähler zurücksetzen
        else {
            $this->ladeStartZaehler = 0;
            $this->ladeStopZaehler = 0;
        }

        // Standard: Status bleibt wie gehabt (z.B. false oder true)
        return $this->ladeStartZaehler > 0; // oder eine andere Default-Logik
    }

    // === 4. Modussteuerung ===

    /** Welcher Lademodus ist aktiv? (manuell/PV2Car/Zielzeit/NurPV/...) */
    private function ErmittleAktivenLademodus()
    {
        $id = @$this->GetIDForIdent('AktiverLademodus');
        $modus = ($id > 0) ? GetValue($id) : 0;

        // (Pseudologik – baue nach deinen Regeln aus)
        // Reihenfolge: Manuell > PV2Car > Zielzeit > NurPV > Strompreis

        // Beispiel: Prüfen, ob Volllademodus aktiviert ist (Variable oder Property)
        if ($modus == 1) {
            return 'manuell';  // Wenn manuell aktiviert
        }
        if ($modus == 2) {
            return 'pv2car';   // Wenn PV2Car aktiviert
        }
        if ($modus == 3) {
            return 'zielzeit'; // Wenn Zielzeit aktiviert
        }
        if ($modus == 4) {
            return 'strompreis'; // Wenn Strompreis aktiviert
        }

        // Wenn keiner der obigen Modi aktiviert ist, Standardmodi 'nurpv'
        return 'nurpv';
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

        // Nur laden, wenn Überschuss >= Mindestleistung
        if ($ueberschuss < $minWatt) {
        return 0;
        }
        return min($ueberschuss, $maxWatt);
    }

    /** Strompreis-Modus: Ladeleistung, wenn Preis <= maxPreis, sonst 0 */
    private function BerechneLadeleistungStrompreis($preis, $maxPreis)
    {
        if ($preis <= $maxPreis) {
            return $this->ReadPropertyInteger('MaxAmpere') * 230 * $this->ReadPropertyInteger('Phasen');
        }
        return 0; 
    }

    /** private function GetPV2CarProzent()
    {
        return 0;
    }*/

    /** private function GetCurrentMarketPrice()
    {
         return 0;
    } */
    
    /** private function GetMaxAllowedPrice()
    {
        return 0;
    } */

    // === 6. Phasenumschaltung / Hysterese ===

    /** Prüfe, ob Phasenumschaltung nötig ist (inkl. Hysterese) */
    private function PruefePhasenumschaltung($ladeleistung)
    {
        // Ermittlung der aktuellen Phase
        $aktuellePhasen = $this->ReadPropertyInteger('Phasen');
        $instanzID = $this->ReadPropertyInteger('GOeChargerID');

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
            //$this->SetValue('AktuellePhasen', 1); // Phasenstatus intern speichern
            $this->SetValueSafe('AktuellePhasen', 1); // Phasenstatus intern speichern
        }
    }

    /** Schalte auf 3-phasig */
    private function UmschaltenAuf3Phasig($instanzID)
    {
        /// Prüfe, ob wir wirklich auf 3-phasig umschalten müssen
        if ($this->ReadPropertyInteger('Phasen') != 3) {
            // Wallbox auf 3-phasig umschalten
            GOeCharger_SetSinglePhaseCharging($instanzID, false);
            //$this->SetValue('AktuellePhasen', 3); // Phasenstatus intern speichern
            $this->SetValueSafe('AktuellePhasen', 3); // Phasenstatus intern speichern
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

    /**
     * Prüft, ob das Fahrzeug verbunden ist (z. B. über go-e Charger Status).
     * Rückgabe: true = Fahrzeug erkannt, false = nichts gesteckt.
     */
    private function IstFahrzeugVerbunden()
    {
        $goeID = $this->ReadPropertyInteger('GOeChargerID');
        if ($goeID <= 0 || !@IPS_InstanceExists($goeID)) {
            $this->LogTemplate('warn', "Wallbox nicht erreichbar.", "Bitte GO-e Instanz in den Modul-Einstellungen prüfen.");
            return false;
        }
        $status = @GOeCharger_GetStatus($goeID);

        if ($status === false || $status === null) {
            $this->LogTemplate('warn', "Wallbox nicht erreichbar.", "Abfrage der GO-e Wallbox ist fehlgeschlagen.");
            return false;
        }

        // KEINE Logmeldung mehr hier!
        // 2 = Fahrzeug lädt, 3 = Warte auf Fahrzeug (Fahrzeug angesteckt)
        return ($status == 2 || $status == 3 || $status == 4);
    }


    /**
     * Setzt alle Lademodi-Buttons auf "aus" (gegenseitiger Ausschluss bei Fahrzeugwechsel).
     * Hier Dummy-Implementierung, je nach Modul-Aufbau anpassen.
     */
    private function SetLademodusAutoReset()
    {
        // Beispiel: Lademodus-Variablen zurücksetzen
        // SetValue($this->GetIDForIdent('ManuellLaden'), false);
        // SetValue($this->GetIDForIdent('PV2CarModus'), false);
        // SetValue($this->GetIDForIdent('ZielzeitModus'), false);
        // ... ggf. weitere
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
        $goeID = $this->ReadPropertyInteger('GOeChargerID');
        if ($goeID > 0 && @IPS_InstanceExists($goeID)) {
            if ($leistung > 0) {
                $this->SetzeAccessStateV2WennNoetig($goeID, 2); // Laden erzwingen!
                @GOeCharger_SetCurrentChargingWatt($goeID, (int)$leistung);
            } else {
                $this->SetzeAccessStateV2WennNoetig($goeID, 1); // Immer blockieren!
                @GOeCharger_SetCurrentChargingWatt($goeID, 0); // Sicherheit: auf 0 setzen
            }
        }
    }
    /**
    private function SetzeLadeleistung($leistung)
    {
        $goeID = $this->ReadPropertyInteger('GOeChargerID');
        if ($goeID > 0 && @IPS_InstanceExists($goeID)) {
            // Setze NUR die Ladeleistung, KEINEN Modus!
            @GOeCharger_SetCurrentChargingWatt($goeID, (int)$leistung); // Falls das Modul das so unterstützt
        }
    }
    */
    /** Setzt den Wallbox-Modus (optional: z. B. für Phasenumschaltung/Status) */
    private function SetzeWallboxModus($modus)
    {
        // Hier kannst du je nach Wallbox Typ/Modul z. B. zwischen Sofortladen, Überschuss etc. umschalten.
        // Bei go-e gibt's dafür oft keine extra Methode – Status könnte aber über Variable o. Ä. gesetzt werden.
    }

/** Deaktiviert die Ladung komplett */
private function DeaktiviereLaden()
    {
        $this->SetzeLadeleistung(0);
    }

    // === 9. Logging / Statusmeldungen ===

    /** Loggt eine Nachricht mit Level (info, warn, error, debug) */
    /**private function Log($msg, $level = 'info')
    {
        $prefix = "[PVWM]";
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
    }*/

    /**
     * Strukturierte Status-/Log-Meldungen mit Emojis
     * @param string $type   info, warn, error, ok, debug
     * @param string $short  Kurztext
     * @param string $detail Optional: Detailtext
     */
    private function LogTemplate($type, $short, $detail = '')
    {
        $emojis = [
            'info'  => 'ℹ️',
            'warn'  => '⚠️',
            'error' => '❌',
            'ok'    => '✅',
            'debug' => '🐞'
        ];
        $icon = isset($emojis[$type]) ? $emojis[$type] : 'ℹ️';
        $msg = $icon . ' ' . $short;
        if ($detail !== '') {
            $msg .= "\n➡️ " . $detail;
        }
        if ($type === 'debug' && !$this->ReadPropertyBoolean('DebugLogging')) {
            return; // Kein Debug, wenn nicht aktiviert
        }
        IPS_LogMessage('[PVWM]', $msg);
    }


    /** Setzt Statusanzeige im Modul (WebFront, Variablen, ...) */

    private function SetLademodusStatus($msg)
    {
        $this->SetValueSafe('Wallbox_Status', $msg);
    }

    /** Loggt aktuelle Energiedaten für Debug */
    private function LogDebugData($daten)
    {
        if ($this->ReadPropertyBoolean('DebugLogging')) {
            $debug = [];
            foreach ($daten as $key => $val) {
                $debug[] = "{$key}={$val}";
            }
            // Trennzeichen ist jetzt |
            $msg = 'Debug-Daten: ' . implode(' | ', $debug);
            $this->LogTemplate('debug', $msg);
        }
    }

    // === 10. RequestAction-Handler ===

    /** Handler für WebFront-Aktionen/Buttons */
    public function RequestAction($ident, $value)
    {
        switch ($ident) {
            case 'AktiverLademodus':
                $this->SetValueSafe($ident, $value);
                $this->LogTemplate('info', "Lademodus geändert.", "Neuer Modus: ".$this->GetLademodusText($value));
                $this->ladeStartZaehler = 0; // Hysterese-Zähler zurücksetzen!
                $this->ladeStopZaehler = 0;  // Hysterese-Zähler zurücksetzen!
                $this->UpdateCharging(); // Nach jedem Wechsel berechnen
                break;
            case 'UpdateCharging':
                $this->UpdateCharging(); // <- Hier wird die Methode wirklich ausgeführt!
                break;
            case 'LockReset':
                if ($value) {
                    $this->ResetLock();
                    $this->SetValue('LockReset', false); // Button sofort zurücksetzen
                }
                break;
            default:
                throw new Exception("Invalid ident: $ident");
        }
    }

    // === 11. Timer/Cron-Handling ===

    public function OnFahrzeugStatusChange(int $neuerStatus)
    {
        // Status 2 = verbunden, 3 = lädt (go-e Standard)
        if ($neuerStatus == 2 || $neuerStatus == 3) {
            // Initialisierungen ausführen:
            $this->LogTemplate('info', "Fahrzeug verbunden.", "Starte Initial-Check für Lademanager.");

            // Alle relevanten Variablen/Lademodi zurücksetzen
            $this->SetLademodusAutoReset();

            // Sofort den Ladeprozess und die Ladeberechnung anstoßen
            $this->UpdateCharging();

            // Zusätzliche Aktionen: Logging, Timestamp setzen, etc.
            $this->SetValueSafe('LetzterFahrzeugCheck', time(), 1);

            // Optional: Push-Notification
            // $this->SendePush("Fahrzeug angesteckt: Lademanager aktiv.");
        }
    }

    private function CreateCarStatusEvent($goeID)
    {
        if ($goeID <= 0 || !@IPS_InstanceExists($goeID)) {
            $this->LogTemplate('warn', "Ereignis-Setup fehlgeschlagen.", "Keine gültige GO-e Instanz hinterlegt (ID: $goeID).");
            return;
        }

        // 'car' statt 'status'!
        $carIdent = 'status';
        $carVarID = @IPS_GetObjectIDByIdent($carIdent, $goeID);

        if ($carVarID === false) {
            $this->LogTemplate('warn', "Fahrzeugstatus-Variable nicht gefunden.", "Kein 'status'-Wert in der GO-e Instanz (ID: $goeID).");
            return;
        }

        $eventIdent = 'Trigger_UpdateCharging_OnCarStatusChange';
        $eventID = @IPS_GetObjectIDByIdent($eventIdent, $this->InstanceID);

        if ($eventID === false) {
            $eventID = IPS_CreateEvent(0); // Trigger bei Wertänderung
            IPS_SetParent($eventID, $this->InstanceID);
            IPS_SetIdent($eventID, $eventIdent);
            IPS_SetName($eventID, "Trigger: UpdateCharging bei Fahrzeugstatus (car=2/3)");
            IPS_SetEventTrigger($eventID, 1, $carVarID); // Wertänderung

            // Hier: Auf Status 2 oder 3 prüfen (Fahrzeug angesteckt oder lädt)
            $code = 'if ($_IPS["VALUE"] == 2 || $_IPS["VALUE"] == 3) {'
                . ' IPS_RequestAction(' . $this->InstanceID . ', "UpdateCharging", true);'
                . '}';

            IPS_SetEventScript($eventID, $code);
            IPS_SetEventActive($eventID, true);

            $this->LogTemplate('info', "Ereignis für Fahrzeugstatus erstellt.", "Event-ID: {$eventID}");
        } else {
            // Existierendes Ereignis ggf. anpassen
            if (@IPS_GetEvent($eventID)['TriggerVariableID'] != $carVarID) {
                IPS_SetEventTrigger($eventID, 1, $carVarID);
                $this->LogTemplate('debug', "Trigger-Variable im Ereignis aktualisiert. (Event-ID: {$eventID})");
            }
            IPS_SetEventActive($eventID, true);
            $this->LogTemplate('debug', "Ereignis zum sofortigen Update geprüft und reaktiviert. (Event-ID: {$eventID})");
        }
    }

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

    private function HoleGoEWallboxDaten()
    {
        $ip    = $this->ReadPropertyString('WallboxIP');
        $key   = trim($this->ReadPropertyString('WallboxAPIKey'));
        $url   = "http://$ip/api/status"; // APIv2: alles in einem JSON

        // Optional: Auth als Header
        $opts = [
            "http" => [
                "method" => "GET",
                "header" => $key ? "X-API-KEY: $key\r\n" : "",
                "timeout" => 3
            ]
        ];
        $context = stream_context_create($opts);

        $json = @file_get_contents($url, false, $context);
        if ($json === false) {
            $this->LogTemplate('error', "Wallbox unter $ip nicht erreichbar.");
            return;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            $this->LogTemplate('error', "Fehler beim Parsen der Wallbox-API-Antwort.");
            return;
        }

        // --- Phasen auswerten: numerisch 1 oder 3 ---
        $phasen = 0;
        if (isset($data['pha']) && is_array($data['pha'])) {
            $aktivePhasen = 0;
            // Bei go-e: pha[3],[4],[5] → true, wenn Phase aktiv
            foreach ([3,4,5] as $idx) {
                if (!empty($data['pha'][$idx])) $aktivePhasen++;
            }
            if ($aktivePhasen == 1 || $aktivePhasen == 3) {
                $phasen = $aktivePhasen;
            }
        }

        $werte = [
            'WB_Ladeleistung_W'    => $data['nrg'][11] ?? null,   // Aktuelle Ladeleistung am Ladepunkt in Watt (W)
            'WB_Status'            => $data['car'] ?? null,       // Status des Fahrzeugs: 1 = bereit, 2 = lädt, 3 = angesteckt (wartet auf Ladung)
            'WB_Phasen'            => $phasen,                    // 1 oder 3 Phasen aktiv (numerisch)
            'WB_Ampere'            => $data['amp'] ?? null,       // Maximal erlaubter Ladestrom (Ampere)
            'WB_Ladefreigabe'      => $data['alw'] ?? null,       // Ladefreigabe: 1 = freigegeben, 0 = gesperrt
            'WB_Firmware'          => $data['fwv'] ?? null,       // Firmware-Version (z.B. "040.0")
            'WB_Fehlercode'        => $data['err'] ?? null,       // Fehlercode laut API (siehe Doku)
            // 'WB_SOC_BMS'        => $data['bcs'] ?? null,       // State of Charge BMS (%), nur wenn vom Fahrzeug geliefert
        ];

        // Ausgabe im Log (zum Testen)
        foreach ($werte as $name => $wert) {
            $this->LogTemplate('info', "$name: ".var_export($wert, true));
        }
        return $werte;
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

    /**
     * Setzt eine Variable nur, wenn sich ihr Wert wirklich geändert hat.
     * Optional: Präzision für Floats (Standard: 2 Nachkommastellen).
     * Erkennt und behandelt auch Integer, String und Bool sauber.
     */
    private function SetValueSafe($ident, $value, $precision = 2, $unit = '')

    {
        $current = $this->GetValue($ident);
        $einheit = $unit ? " $unit" : ''; // Leerzeichen für Trennung

        // Float: mit Präzision vergleichen
        if (is_float($value) || is_float($current)) {
            $cur = round((float)$current, $precision);
            $neu = round((float)$value, $precision);
            if ($cur !== $neu) {
                $this->LogTemplate('debug', "{$ident}: Wert geändert von {$cur}{$einheit} => {$neu}{$einheit}");
                $this->SetValue($ident, $value);
            } else {
                $this->LogTemplate('debug', "{$ident}: Keine Änderung ({$cur}{$einheit})");
            }
            return;
        }

        // Integer: strikt vergleichen
        if (is_int($value) || is_int($current)) {
            if ((int)$current !== (int)$value) {
                $this->LogTemplate('debug', "{$ident}: Wert geändert von {$current} => {$value}");
                $this->SetValue($ident, $value);
            } else {
                $this->LogTemplate('debug', "{$ident}: Keine Änderung ({$current})");
            }
            return;
        }

        // Boolean: direkt vergleichen
        if (is_bool($value) || is_bool($current)) {
            if ((bool)$current !== (bool)$value) {
                $this->LogTemplate('debug', "{$ident}: Wert geändert von " . ($current ? 'true' : 'false') . " => " . ($value ? 'true' : 'false'));
                $this->SetValue($ident, $value);
            } else {
                $this->LogTemplate('debug', "{$ident}: Keine Änderung (" . ($current ? 'true' : 'false') . ")");
            }
            return;
        }

        // String: trim und vergleichen
        if (is_string($value) || is_string($current)) {
            $cur = trim((string)$current);
            $neu = trim((string)$value);
            if ($cur !== $neu) {
                $this->LogTemplate('debug', "{$ident}: Wert geändert von '{$cur}' => '{$neu}'");
                $this->SetValue($ident, $value);
            } else {
                $this->LogTemplate('debug', "{$ident}: Keine Änderung ('{$cur}')");
            }
            return;
        }

        // Fallback für alle anderen Typen (notfalls trotzdem setzen)
        if ($current !== $value) {
            $this->LogTemplate('debug', "{$ident}: Wert geändert von {$current} => {$value}");
            $this->SetValue($ident, $value);
        } else {
            $this->LogTemplate('debug', "{$ident}: Keine Änderung ({$current})");
        }
    }

    private function GetAttributeOrDefault($name, $default)
    {
        $val = @$this->ReadAttribute($name);
        if ($val === null) return $default;
        return $val;
    }

    private function LogAccessStateV2()
    {
        $goeID = $this->ReadPropertyInteger('GOeChargerID');
        $modusID = @IPS_GetObjectIDByIdent('accessStateV2', $goeID);

        if ($modusID && @IPS_VariableExists($modusID)) {
            $current = GetValue($modusID);
            $msg = $this->GetAccessStateV2Text($current);
            $this->LogTemplate('debug', "accessStateV2 aktuell: $msg");
        } else {
            $this->LogTemplate('warn', "Wallbox-Freigabestatus nicht verfügbar.", "Zugriffs-Status (accessStateV2) fehlt in der GO-e Instanz.");
        }
    }

    private function UpdateAccessStateText()
    {
        $goeID = $this->ReadPropertyInteger('GOeChargerID');
        $modusID = @IPS_GetObjectIDByIdent('accessStateV2', $goeID);

        $text = "unbekannt";
        if ($modusID && @IPS_VariableExists($modusID)) {
            $val = GetValue($modusID);
            $text = $this->GetAccessStateV2Text($val);
        }
        $this->SetValueSafe('AccessStateText', $text, 0);
    }

    /** Setzt accessStateV2 (GOeCharger_SetMode) nur, wenn sich der Wert wirklich ändert.*/
    private function SetzeAccessStateV2WennNoetig($goeID, $neuerModus)
    {
        $modusID = @IPS_GetObjectIDByIdent('accessStateV2', $goeID);
        $aktuellerModus = ($modusID && @IPS_VariableExists($modusID)) ? GetValue($modusID) : null;
        $this->LogTemplate('debug', "Vorher: accessStateV2 = $aktuellerModus, Ziel: $neuerModus");/**================================================ */
        if ($aktuellerModus !== $neuerModus) {
            @GOeCharger_SetMode($goeID, $neuerModus);
            $this->LogTemplate('debug', "accessStateV2 geändert: $aktuellerModus → $neuerModus");
        } else {
            $this->LogTemplate('debug', "accessStateV2 unverändert: $aktuellerModus (kein Setzen nötig)");
        }
    }

    private function GetAccessStateV2Text($val)
    {
        switch ($val) {
            case 0:  return "⚪ Neutral (Wallbox entscheidet selbst)";
            case 1:  return "🚫 Nicht laden (gesperrt)";
            case 2:  return "⚡ Laden (erzwungen)";
            default: return "❔ Unbekannter Modus ($val)";
        }
    }

}
