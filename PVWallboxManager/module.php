<?php
/**
 * PVWallboxManager
 * Modularer Blueprint – jede Funktion einzeln gekapselt
 * Siegfried Pesendorfer, 2025
 */
class PVWallboxManager extends IPSModule
{
    // === Private Klassenvariablen ===
    private $ladeStartZaehler = 0;
    private $ladeStopZaehler = 0;
    private $StartHystereseCounter = 0;
    private $StopHystereseCounter = 0;
    private $PhasenDownCounter = 0;
    private $PhasenUpCounter = 0;
    private $LastSetLadeleistung = 0;
    private $LastSetGoEActive = false;

    // =========================================================================
    // 1. INITIALISIERUNG
    // =========================================================================

    /** @inheritDoc */
    public function Create()
    {
        parent::Create();
        //$this->EnsurePhasenCounterAttributes();

        // === 1. Modulsteuerung ===
        $this->RegisterPropertyBoolean('ModulAktiv', true);
        $this->RegisterPropertyBoolean('DebugLogging', false);

        // === 2. Wallbox-Konfiguration ===
        $this->RegisterPropertyInteger('GOeChargerID', 0);
        $this->RegisterPropertyInteger('MinAmpere', 6);
        $this->RegisterPropertyInteger('MaxAmpere', 16);
        $this->RegisterPropertyFloat('MinLadeWatt', 1400); // Standardwert nach Bedarf anpassen
        $this->RegisterPropertyInteger('StartHysterese', 0);
        $this->RegisterPropertyFloat('MinStopWatt', 1200);
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
        $this->RegisterPropertyFloat('Phasen1Schwelle', 3400);
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
        //$this->RegisterVariableInteger('HystereseZaehler', 'Phasen-Hysteresezähler', '', 60);
        $this->RegisterVariableInteger('AktuellePhasen', 'Aktuelle Phasen', '', 80);

        // Timer für Berechnungsintervall
        $this->RegisterTimer('UpdateCharging', $this->ReadPropertyInteger('RefreshInterval') * 1000, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateCharging", 0);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        
        $this->WriteAttributeInteger('PhasenDownCounter', 0);
        $this->WriteAttributeInteger('PhasenUpCounter', 0);
        $this->WriteAttributeInteger('LastSetLadeleistung', 0);
        $this->WriteAttributeBoolean('LastSetGoEActive', false);
        $this->WriteAttributeInteger('StartHystereseCounter', 0);
        $this->WriteAttributeInteger('StopHystereseCounter', 0);

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
        $this->UpdateAccessStateText();
        $this->CheckSchwellenwerte();
        
    }

    // =========================================================================
    // 2. REQUESTACTION / TIMER / EVENTS
    // =========================================================================

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

        $carIdent = 'status'; // go-e Instanz: Variable 'status' gibt den Fahrzeugstatus
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
            IPS_SetName($eventID, "Trigger: UpdateCharging bei Fahrzeugstatus-Änderung");
            IPS_SetEventTrigger($eventID, 1, $carVarID);

            // <<< HIER PATCH >>>
            $code = 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateCharging", 0);';
            IPS_SetEventScript($eventID, $code);
            // <<< PATCH ENDE >>>
            IPS_SetEventActive($eventID, true);

            $this->LogTemplate('info', "Ereignis für Fahrzeugstatus erstellt.", "Event-ID: {$eventID}");
        } else {
            // Existierendes Ereignis ggf. anpassen
            if (@IPS_GetEvent($eventID)['TriggerVariableID'] != $carVarID) {
                IPS_SetEventTrigger($eventID, 1, $carVarID);
                $this->LogTemplate('debug', "Trigger-Variable im Ereignis aktualisiert. (Event-ID: {$eventID})");
            }
            IPS_SetEventActive($eventID, true);
            // PATCH AUCH HIER SICHERSTELLEN:
            $code = 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateCharging", 0);';
            IPS_SetEventScript($eventID, $code);
            $this->LogTemplate('debug', "Ereignis zum sofortigen Update geprüft und reaktiviert. (Event-ID: {$eventID})");
        }
    }

    private function StarteRegelmaessigeBerechnung()
    {
        $interval = $this->ReadPropertyInteger('RefreshInterval') * 1000;
        $this->SetTimerInterval('UpdateCharging', $interval);
    }

    private function StoppeRegelmaessigeBerechnung()
    {
        $this->SetTimerInterval('UpdateCharging', 0);
    }

    // =========================================================================
    // 3. ZENTRALE STEUERLOGIK
    // =========================================================================

    public function UpdateCharging()
    {
        $this->LogTemplate('debug', "=== UpdateCharging Durchlauf Start === ".date('H:i:s'));

        $goeID = $this->ReadPropertyInteger('GOeChargerID');
        $this->LogTemplate('debug', "Aktuelle GOeChargerID im Modul: $goeID");
        if ($goeID > 0 && !IPS_InstanceExists($goeID)) {
            $this->LogTemplate('error', "GO-e Instanz $goeID existiert NICHT!");
        }

        // ---- Werte direkt aus der IPSCoyote/GO-eCharger Instanz holen ----
        // Standard-IDs in IPSCoyote: status (int), psm (int), powerToCar (float), nrg[11] (akt. Ladeleistung), pha (Phasen)
        $statusVarID = @IPS_GetObjectIDByIdent('status', $goeID);
        $phasenVarID = @IPS_GetObjectIDByIdent('psm', $goeID);
        $powerToCarVarID = @IPS_GetObjectIDByIdent('powerToCar', $goeID);

        $statusNum = ($statusVarID && @IPS_VariableExists($statusVarID)) ? GetValue($statusVarID) : 0;
        $phasen_ist = ($phasenVarID && @IPS_VariableExists($phasenVarID)) ? GetValue($phasenVarID) : 1;
        $wb_leistung = ($powerToCarVarID && @IPS_VariableExists($powerToCarVarID)) ? GetValue($powerToCarVarID) : 0.0;

        // Wrapper-Array für vorhandene Funktionen
        $wb = [
            'WB_Status'   => $statusNum,
            'WB_Phasen'   => $phasen_ist,
            'WB_Ladeleistung_W' => $wb_leistung
        ];

        $this->LogTemplate('info', "Check: \$status = " . var_export($statusNum, true) . ", verbunden? " . ($this->IstFahrzeugVerbunden($wb) ? 'JA' : 'NEIN'));

        // Prüfe: Nur laden, wenn Fahrzeug verbunden
        if ($this->ReadPropertyBoolean('NurMitFahrzeug') && !$this->IstFahrzeugVerbunden($wb)) {
            $this->SetGoEActive($goeID, false);
            $statusText = "Bitte das Fahrzeug mit der Wallbox verbinden.";
            $this->SetLademodusStatus($statusText);
            $this->LogTemplate('info', "Warte auf Fahrzeug.", $statusText);
            $this->SetLademodusAutoReset();
            $this->UpdateAccessStateText();
            $this->SetValueSafe('WB_Ladeleistung_Soll', 0, 1, 'W');
            $this->SetValueSafe('WB_Ladeleistung_Ist', 0, 1, 'W');
            $this->SetValueSafe('PV_Ueberschuss', 0, 1, 'W');
            return;
        }

        if (!$this->ReadPropertyBoolean('ModulAktiv')) {
            $this->LogTemplate('warn', "PVWallbox-Manager ist deaktiviert.", "Automatische Steuerung aktuell ausgesetzt.");
            $this->DeaktiviereLaden();
            $this->SetValueSafe('WB_Ladeleistung_Soll', 0, 1, 'W');
            $this->SetValueSafe('WB_Ladeleistung_Ist', 0, 1, 'W');
            $this->SetValueSafe('AktuellePhasen', 0);
            $this->SetValueSafe('Ziel-Ladezeit', 0);
            return;
        }

        // Energiewerte lesen
        $pv          = $this->LesePVErzeugung();
        $haus        = $this->LeseHausverbrauch();
        $batt        = $this->LeseBatterieleistung();
        // $wb_leistung schon oben!

        // Rohwert und Puffer berechnen
        $roh_ueberschuss = $this->BerechnePVUeberschuss($pv, $haus, $batt, $wb_leistung);
        list($ueberschuss, $pufferFaktor) = $this->BerechnePVUeberschussMitPuffer($roh_ueberschuss);

        $ueberschuss = max(0, $ueberschuss);
        $puffer_prozent = round($pufferFaktor * 100);
        $puffer_diff    = round($roh_ueberschuss - $ueberschuss);

        // Werte schreiben
        $this->SetValueSafe('PV_Ueberschuss', $ueberschuss, 1, 'W');
        $this->SetValueSafe('Hausverbrauch_W', $haus, 1, 'W');
        $haus_abz_wb = max(0, $haus - $wb_leistung);
        $this->SetValueSafe('Hausverbrauch_abz_Wallbox', $haus_abz_wb, 1, 'W');

        // Aktiven Lademodus bestimmen
        $modus = $this->ErmittleAktivenLademodus();

        // Statusanzeige im WebFront
        switch ($statusNum) {
            case 0:
                $this->SetLademodusStatus("❔ Unbekannter Status oder Fehler.");
                break;
            case 1:
                $this->SetLademodusStatus("🅿️ Wallbox bereit (kein Fahrzeug angesteckt).");
                break;
            case 2:
                $this->SetLademodusStatus("⚡ Fahrzeug wird geladen.");
                break;
            case 3:
                $this->SetLademodusStatus("🚗 Fahrzeug angesteckt – warte auf Start.");
                break;
            case 4:
                $this->SetLademodusStatus("✅ Laden abgeschlossen – Fahrzeug verbunden.");
                break;
            case 5:
                $this->SetLademodusStatus("❌ Wallbox-Fehler! Bitte prüfen.");
                break;
            default:
                $this->SetLademodusStatus("❔ Status unbekannt.");
                break;
        }

        // Ladeleistung ermitteln
        switch ($modus) {
            case 'manuell':
                $ladeleistung = $this->BerechneLadeleistungManuell();
                $this->PruefePhasenumschaltung($ladeleistung, $wb);
                $this->LogTemplate('debug', sprintf(
                    "Hysteresecounter: Down=%d | Up=%d",
                    $this->PhasenDownCounter,
                    $this->PhasenUpCounter
                ));
                $this->SetzeLadeleistung($ladeleistung);
                break;

            case 'pv2car':
                $prozent = $this->GetPV2CarProzent();
                $ladeleistung = $this->BerechneLadeleistungPV2Car($ueberschuss, $prozent);
                $this->PruefePhasenumschaltung($ladeleistung, $wb);
                $this->LogTemplate('debug', sprintf(
                    "Hysteresecounter: Down=%d | Up=%d",
                    $this->PhasenDownCounter,
                    $this->PhasenUpCounter
                ));
                $this->SetzeLadeleistung($ladeleistung);
                break;

            case 'zielzeit':
                $istSOC = $this->LeseFahrzeugSOC();
                $zielSOC = $this->LeseZielSOC();
                $zielzeit = $this->LeseZielzeit();
                $maxLeistung = $this->ReadPropertyInteger('MaxAutoWatt');
                $ladeleistung = $this->BerechneLadeleistungZielzeit($zielSOC, $istSOC, $zielzeit, $maxLeistung);
                $this->PruefePhasenumschaltung($ladeleistung, $wb);
                $this->LogTemplate('debug', sprintf(
                    "Hysteresecounter: Down=%d | Up=%d",
                    $this->PhasenDownCounter,
                    $this->PhasenUpCounter
                ));
                $this->SetzeLadeleistung($ladeleistung);
                break;

            case 'strompreis':
                $preis = $this->GetCurrentMarketPrice();
                $maxPreis = $this->GetMaxAllowedPrice();
                $ladeleistung = $this->BerechneLadeleistungStrompreis($preis, $maxPreis);
                $this->PruefePhasenumschaltung($ladeleistung, $wb);
                $this->LogTemplate('debug', sprintf(
                    "Hysteresecounter: Down=%d | Up=%d",
                    $this->PhasenDownCounter,
                    $this->PhasenUpCounter
                ));
                $this->SetzeLadeleistung($ladeleistung);
                break;

            case 'nurpv':
            default:
                $battSOC = 0;
                $hausakkuSOCID = $this->ReadPropertyInteger('HausakkuSOCID');
                if ($hausakkuSOCID > 0 && @IPS_VariableExists($hausakkuSOCID)) {
                    $battSOC = (float)GetValue($hausakkuSOCID);
                }
                $hausakkuVollSchwelle = $this->ReadPropertyInteger('HausakkuSOCVollSchwelle');
                $autoAngesteckt = $this->IstFahrzeugVerbunden($wb);

                list($ladeleistungAuto, $ladeleistungHausakku) = $this->PriorisiereEigenverbrauch(
                    $pv, $haus, $battSOC, $hausakkuVollSchwelle, $autoAngesteckt
                );

                $startCounter = (int)$this->GetBuffer('StartHystereseCounter');
                $stopCounter  = (int)$this->GetBuffer('StopHystereseCounter');
                $ladeleistung = 0;

                $minLadeWatt    = $this->ReadPropertyFloat('MinLadeWatt');
                $minStopWatt    = $this->ReadPropertyFloat('MinStopWatt');
                $startHysterese = $this->ReadPropertyInteger('StartHysterese');
                $stopHysterese  = $this->ReadPropertyInteger('StopHysterese');
                $istAmLaden     = ($wb['WB_Status'] ?? 0) == 2;

                $this->PruefePhasenumschaltung($ueberschuss, $wb);
                $this->LogTemplate('debug', sprintf(
                    "Hysteresecounter: Down=%d | Up=%d",
                    $this->PhasenDownCounter,
                    $this->PhasenUpCounter
                ));

                // Niemals laden ohne PV-Überschuss
                if ($ueberschuss < $minLadeWatt) {
                    $this->SetGoEActive($goeID, false);
                    $this->SetzeLadeleistung(0);
                    $this->LogTemplate('info', "Kein PV-Überschuss – Ladung deaktiviert.");
                    $this->SetBuffer('StartHystereseCounter', 0);
                    $this->SetBuffer('StopHystereseCounter', 0);
                    break;
                }

                if (!$istAmLaden) {
                    if ($ueberschuss >= $minLadeWatt) {
                        $startCounter++;
                        $stopCounter = 0;
                        if ($startCounter >= $startHysterese) {
                            $ladeleistung = $this->BerechneLadeleistungNurPV($ueberschuss, $wb);
                            $this->SetzeLadeleistung($ladeleistung);
                            $startCounter = 0;
                        }
                    } else {
                        $startCounter = 0;
                    }
                } else {
                    if ($ueberschuss <= $minStopWatt) {
                        $stopCounter++;
                        $startCounter = 0;
                        if ($stopCounter >= $stopHysterese) {
                            $ladeleistung = 0;
                            $this->SetzeLadeleistung($ladeleistung);
                            $stopCounter = 0;
                        }
                    } else {
                        $stopCounter = 0;
                        $ladeleistung = $this->BerechneLadeleistungNurPV($ueberschuss, $wb);
                        $this->SetzeLadeleistung($ladeleistung);
                    }
                }

                // Buffer sichern
                $this->SetBuffer('StartHystereseCounter', $startCounter);
                $this->SetBuffer('StopHystereseCounter', $stopCounter);

                $this->SetValueSafe('WB_Ladeleistung_Soll', $ladeleistung, 1);
                $this->SetValueSafe('WB_Ladeleistung_Ist', $wb_leistung, 1);
                $this->SetValueSafe('AktuellePhasen', $phasen_ist);

                $this->LogTemplate(
                    'debug',
                    sprintf(
                        "PV: %.0f W | Haus: %.0f W | Batt: %.0f W | WB: %.0f W | Puffer: %d W (%d%%) | Überschuss: %.0f W | Hyst: %d/%d",
                        $pv, $haus, $batt, $wb_leistung,
                        round($puffer_diff), $puffer_prozent,
                        $ueberschuss, $startCounter, $stopCounter
                    )
                );
                break;
        }
    }

    // =========================================================================
    // 4. LADEMODI-HANDLER
    // =========================================================================

    private function ErmittleAktivenLademodus()
    {
        $id = @$this->GetIDForIdent('AktiverLademodus');
        $modus = ($id > 0) ? GetValue($id) : 0;

        // (Pseudologik – baue nach deinen Regeln aus)
        // Reihenfolge: Manuell > PV2Car > Zielzeit > NurPV > Strompreis

            switch ($modus) {
            case 1:  return 'manuell';
            case 2:  return 'pv2car';
            case 3:  return 'zielzeit';
            case 4:  return 'strompreis';
            default: return 'nurpv';
        }
    }

    private function ModusManuell()
    {
        // TODO
    }

    private function ModusPV2Car()
    {
        // TODO
    }

    private function ModusZielzeit()
    {
        // TODO
    }

    private function ModusNurPV()
    {
        // TODO
    }

    private function ModusStrompreis()
    {
        // TODO
    }

    // =========================================================================
    // 5. LADELEISTUNG / BERECHNUNGEN / HYSTERESE / PHASEN
    // =========================================================================

    // --- Energie-/Überschussberechnung ---
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

    private function LesePVErzeugung()
    {
        return $this->LeseEnergiewert(
            $this->ReadPropertyInteger('PVErzeugungID'),
            $this->ReadPropertyString('PVErzeugungEinheit'),
            false
        );
    }

    private function LeseNetzeinspeisung()
    {
        return $this->LeseEnergiewert(
            $this->ReadPropertyInteger('NetzeinspeisungID'),
            $this->ReadPropertyString('NetzeinspeisungEinheit'),
            $this->ReadPropertyBoolean('InvertNetzeinspeisung')
        );
    }

    private function LeseHausverbrauch()
    {
        return $this->LeseEnergiewert(
            $this->ReadPropertyInteger('HausverbrauchID'),
            $this->ReadPropertyString('HausverbrauchEinheit'),
            $this->ReadPropertyBoolean('InvertHausverbrauch')
        );
    }

    private function LeseBatterieleistung()
    {
        return $this->LeseEnergiewert(
            $this->ReadPropertyInteger('BatterieladungID'),
            $this->ReadPropertyString('BatterieladungEinheit'),
            $this->ReadPropertyBoolean('InvertBatterieladung')
        );
    }

    private function LeseWallboxLeistung($wb = null)
    {
        $goeID = $this->ReadPropertyInteger('GOeChargerID');
        if ($goeID > 0 && @IPS_InstanceExists($goeID)) {
            // Die Ident der aktuellen Ladeleistung (meist „powerToCar“)
            $ident = 'powerToCar'; // Diesen Ident ggf. anpassen, wenn dein GO-e-Modul einen anderen Ident nutzt!
            $varID = @IPS_GetObjectIDByIdent($ident, $goeID);
            if ($varID && @IPS_VariableExists($varID)) {
                return (float)GetValue($varID);
            }
        }
        // Fallback: aus übergebenem Array, falls vorhanden (Kompatibilität zu Wrapper)
        if (is_array($wb) && isset($wb['WB_Ladeleistung_W'])) {
            return (float)$wb['WB_Ladeleistung_W'];
        }
        return 0.0;
    }

    private function BerechnePVUeberschuss($pv, $verbrauch, $batterie, $wallbox = 0)
    {
        //$batt = max(0, $batterie); // Nur wenn >0
        return $pv - $verbrauch - $batterie + $wallbox;
    }

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

    // --- Modus-spezifische Ladeleistungsberechnung ---
    private function BerechneLadeleistungManuell()
    {
        return $this->ReadPropertyInteger('MaxAmpere') * 230 * $this->ReadPropertyInteger('Phasen'); // z.B. für Drehstrom
    }

    private function BerechneLadeleistungPV2Car($ueberschuss, $prozent)
    {
        $wert = $ueberschuss * ($prozent / 100.0);
        $max = $this->ReadPropertyInteger('MaxAmpere') * 230 * $this->ReadPropertyInteger('Phasen');
        return min($wert, $max);
    }

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

    private function BerechneLadeleistungNurPV($ueberschuss, $wb = null)
    {
        $minA = $this->ReadPropertyInteger('MinAmpere');
        $maxA = $this->ReadPropertyInteger('MaxAmpere');
        $spannung = 230;
        
        // Hole aktuelle Phasenanzahl aus der Wallbox, falls verfügbar
        $phasen = 1; // Default
        if (is_array($wb) && isset($wb['WB_Phasen'])) {
            $phasen = max(1, (int)$wb['WB_Phasen']);
        } else {
            $phasen = max(1, (int)$this->ReadPropertyInteger('Phasen'));
        }

        $minWatt = $minA * $spannung * $phasen;
        $maxWatt = $maxA * $spannung * $phasen;

        // Nur laden, wenn Überschuss >= Mindestleistung
        if ($ueberschuss < $minWatt) {
            return 0;
        }
        return min($ueberschuss, $maxWatt);
    }

    private function BerechneLadeleistungStrompreis($preis, $maxPreis)
    {
        if ($preis <= $maxPreis) {
            return $this->ReadPropertyInteger('MaxAmpere') * 230 * $this->ReadPropertyInteger('Phasen');
        }
        return 0; 
    }

    // --- Phasenumschaltung & Hysterese ---
    private function PruefePhasenumschaltung($ladeleistung, $wb)
    {
        $goeID = $this->ReadPropertyInteger('GOeChargerID');

        // Phasenstatus prüfen (robust)
        $phasen_ist = isset($wb['WB_Phasen']) && in_array($wb['WB_Phasen'], [1, 3]) ? $wb['WB_Phasen'] : 0;
        if ($phasen_ist === 0) {
            $this->LogTemplate('warn', "Phasenstatus ungültig oder unbekannt, kann nicht umschalten!");
            return false;
        }

        $umschaltung = false;

        // Umschaltung auf 1-phasig
        if ($phasen_ist == 3 && $this->PruefeHystereseDown($ladeleistung)) {
            $this->UmschaltenAuf1Phasig($goeID);
            IPS_Sleep(1500);
            $wbNeu = $this->HoleGoEWallboxDaten();
            $phasen_ist = $wbNeu['WB_Phasen'] ?? 1;
            $this->LogTemplate('info', 'Umschaltung auf 1-phasig ausgelöst.', "Leistung: $ladeleistung W | ECHTE Phasen: $phasen_ist");
            $umschaltung = true;
        }
        // Umschaltung auf 3-phasig
        elseif ($phasen_ist == 1 && $this->PruefeHystereseUp($ladeleistung)) {
            $this->UmschaltenAuf3Phasig($goeID);
            IPS_Sleep(1500);
            $wbNeu = $this->HoleGoEWallboxDaten();
            $phasen_ist = $wbNeu['WB_Phasen'] ?? 3;
            $this->LogTemplate('info', 'Umschaltung auf 3-phasig ausgelöst.', "Leistung: $ladeleistung W | ECHTE Phasen: $phasen_ist");
            $umschaltung = true;
        }

        // Immer aktualisieren – vermeidet „hängende“ Anzeige
        $this->SetValueSafe('AktuellePhasen', $phasen_ist);

        $this->LogTemplate('debug', sprintf(
            "Hysteresecounter: Down=%d | Up=%d",
            $this->PhasenDownCounter,
            $this->PhasenUpCounter
        ));

        if (!$umschaltung) {
            $this->LogTemplate('debug', "Keine Phasenumschaltung nötig. (Phasen: $phasen_ist, Leistung: $ladeleistung W)");
        }

        return $umschaltung;
    }

    private function PruefeHystereseDown($ladeleistung)
    {
        $limit = $this->ReadPropertyInteger('Phasen1Limit');
        $schwelle = $this->ReadPropertyFloat('Phasen1Schwelle');

        // Counter aus Attribut lesen oder initialisieren
        $counter = $this->GetOrInitAttributeInteger('PhasenDownCounter', 0);
        if (!is_int($counter)) $counter = 0;

        if ($ladeleistung < $schwelle) {
            $counter++;
            $this->WriteAttributeInteger('PhasenUpCounter', 0); // Gegenseite immer resetten!
            $this->LogTemplate('debug', "Phasen-Hysterese-Down: {$counter}/{$limit} (Schwelle: {$schwelle} W)");
            if ($counter >= $limit) {
                $this->WriteAttributeInteger('PhasenDownCounter', 0); // Reset nach Erreichen
                return true;
            }
        } else {
            $counter = 0;
        }
        $this->WriteAttributeInteger('PhasenDownCounter', $counter);
        return false;
    }

    private function PruefeHystereseUp($ladeleistung)
    {
        $limit = $this->ReadPropertyInteger('Phasen3Limit');
        $schwelle = $this->ReadPropertyFloat('Phasen3Schwelle');

        $counter = $this->GetOrInitAttributeInteger('PhasenUpCounter', 0);
        if (!is_int($counter)) $counter = 0;

        if ($ladeleistung > $schwelle) {
            $counter++;
            $this->WriteAttributeInteger('PhasenDownCounter', 0); // Gegenseite resetten
            $this->LogTemplate('debug', "Phasen-Hysterese-Up: {$counter}/{$limit} (Schwelle: {$schwelle} W)");
            if ($counter >= $limit) {
                $this->WriteAttributeInteger('PhasenUpCounter', 0);
                return true;
            }
        } else {
            $counter = 0;
        }
        $this->WriteAttributeInteger('PhasenUpCounter', $counter);
        return false;
    }

    private function UmschaltenAuf1Phasig()
    {
        $goeID = $this->ReadPropertyInteger('GOeChargerID'); // <--- HINZUFÜGEN!
        $this->SetGoEActive($goeID, false);
        IPS_Sleep(1200);
        $this->GOeCharger_SetMode($goeID, '1P'); // für 1-phasig
        IPS_Sleep(1500);
        $this->SetGoEActive($goeID, true);
        $this->LogTemplate('info', 'Phasenumschaltung auf 1-phasig abgeschlossen.');
    }

    private function UmschaltenAuf3Phasig()
    {
        $goeID = $this->ReadPropertyInteger('GOeChargerID'); // <--- HINZUFÜGEN!
        $this->SetGoEActive($goeID, false);
        IPS_Sleep(1200);
        $this->GOeCharger_SetMode($goeID, '3P'); // für 3-phasig
        IPS_Sleep(1200);
        $this->SetGoEActive($goeID, true);
        $this->LogTemplate('info', 'Phasenumschaltung auf 3-phasig abgeschlossen.');
    }

    private function InkrementiereStartHysterese($max)
    {
        if ($this->ladeStartZaehler < $max) {
            $this->ladeStartZaehler++;
        }
        $this->ladeStopZaehler = 0; // Reset des Gegen-Zählers
        $this->LogTemplate('debug', "Inkrementiere StartHysterese: {$this->ladeStartZaehler}/{$max}");
        return $this->ladeStartZaehler;
    }

    private function InkrementiereStopHysterese($max)
    {
        if ($this->ladeStopZaehler < $max) {
            $this->ladeStopZaehler++;
        }
        $this->ladeStartZaehler = 0; // Reset des Gegen-Zählers
        $this->LogTemplate('debug', "Inkrementiere StopHysterese: {$this->ladeStopZaehler}/{$max}");
        return $this->ladeStopZaehler;
    }

    private function ResetHystereseZaehler()
    {
        $this->ladeStartZaehler = 0;
        $this->ladeStopZaehler = 0;
    }

    // =========================================================================
    // 6. WALLBOX-KOMMUNIKATION
    // =========================================================================

    private function SetzeLadeleistung($leistung)
    {
        $goeID = $this->ReadPropertyInteger('GOeChargerID');
        if ($goeID <= 0 || !@IPS_InstanceExists($goeID)) {
            $this->LogTemplate('warn', "SetzeLadeleistung: Keine gültige GO-e Instanz konfiguriert!");
            return;
        }

        // Verwende immer die persistenten Attribute!
        $lastWatt   = $this->GetOrInitAttributeInteger('LastSetLadeleistung', 0);
        $lastActive = $this->GetOrInitAttributeBoolean('LastSetGoEActive', false);

        $phasen   = max(1, (int)$this->ReadPropertyInteger('Phasen'));
        $spannung = 230;
        $minAmp   = max(6, (int)$this->ReadPropertyInteger('MinAmpere'));
        $maxAmp   = min(32, (int)$this->ReadPropertyInteger('MaxAmpere'));
        $ampere   = round($leistung / ($phasen * $spannung));
        $ampere   = max($minAmp, min($maxAmp, $ampere));
        $minWatt  = $minAmp * $phasen * $spannung;

        if ($leistung < $minWatt) {
            if ($lastActive !== false) {
                $this->SetGoEActive($goeID, false);
                $this->LogTemplate('info', "Ladung deaktiviert (Leistung zu gering, alw=0).");
                $this->WriteAttributeInteger('LastSetLadeleistung', 0);
                $this->WriteAttributeBoolean('LastSetGoEActive', false);
                $status = $this->HoleGoEWallboxDaten();
                $this->LogTemplate('debug', "Wallbox nach alw=0: " . print_r($status, true));
            }
            return;
        }

        // 2. Leistung oder Status hat sich nicht geändert → nix tun!
        if ($lastWatt === $leistung && $lastActive === true) {
            $this->LogTemplate('debug', "SetzeLadeleistung: Wert unverändert ($leistung W, $ampere A, $phasen Phasen) – kein API-Call.");
            return;
        }

        // 3. Änderung nötig: Einschalten und gewünschten Wert setzen!
        $this->SetGoEActive($goeID, true);
        IPS_Sleep(1500); // ggf. noch länger!
        GOeCharger_SetCurrentChargingWatt($goeID, $leistung);
        $this->LogTemplate('info', "Ladung aktiviert: alw=1, amp=$ampere (für $leistung W, $phasen Phasen)");

        // Update der Attribute
        $this->WriteAttributeInteger('LastSetLadeleistung', $leistung);
        $this->WriteAttributeBoolean('LastSetGoEActive', true);

        $status = $this->HoleGoEWallboxDaten();
        $this->LogTemplate('debug', "Wallbox nach Setzen: " . print_r($status, true));
    }

    private function SetzeWallboxModus($modus)
    {
        $goeID = $this->ReadPropertyInteger('GOeChargerID');
        if ($goeID <= 0 || !@IPS_InstanceExists($goeID)) {
            $this->LogTemplate('warn', "SetzeWallboxModus: Keine gültige GO-e Instanz konfiguriert!");
            return false;
        }

        // --- Für GO-eCharger: Modus ist KEINE API-Funktion ---
        // Die tatsächliche Logik erfolgt durch SetzeLadeleistung & SetAccessState.
        // Du kannst hier ggf. einen Status-Text für Visualisierung schreiben:
        switch ($modus) {
            case 'sofort':
            case 'manuell':
                // Freigeben und Maximalleistung setzen
                $this->SetGoEActive($goeID, true);
                GOeCharger_SetCurrentChargingWatt($goeID, $this->ReadPropertyInteger('MaxAmpere') * 230 * $this->ReadPropertyInteger('Phasen'));
                $this->SetLademodusStatus("🔋 Sofortladen aktiviert (manuell/maximal)");
                break;

            case 'pv':
            case 'nurpv':
                // Freigeben, aber nur wenn Überschuss reicht (wird in Logik geprüft)
                $this->SetGoEActive($goeID, true);
                // Ladeleistung wird durch SetzeLadeleistung geregelt!
                $this->SetLademodusStatus("☀️ PV-Überschussmodus aktiviert");
                break;

            case 'stop':
            default:
                // Sperren, also Laden deaktivieren
                $this->SetGoEActive($goeID, false);
                $this->SetLademodusStatus("⛔️ Wallbox gesperrt");
                break;
        }
        return true;
    }


    private function DeaktiviereLaden()
    {
        $this->SetzeLadeleistung(0);
        $this->SetLademodusStatus("⛔️ Laden deaktiviert.");
        $this->LogTemplate('info', "Ladung per Modul deaktiviert (SetzeLadeleistung=0).");
    }


    private function HoleGoEWallboxDaten()
    {
        $goeID = $this->ReadPropertyInteger('GOeChargerID');
        if ($goeID <= 0 || !@IPS_InstanceExists($goeID)) {
            $this->LogTemplate('error', "Ungültige oder fehlende GO-eCharger Instanz-ID ($goeID)");
            return false;
        }
        // Hole Daten direkt aus der Instanz
        $status  = @GOeCharger_GetStatus($goeID);           // 1=bereit, 2=lädt, 3=warte, 4=abgeschlossen, 5=Fehler
        $leistung = @GOeCharger_GetPowerToCar($goeID);      // Aktuelle Ladeleistung (Watt)
        $phasen  = @GOeCharger_GetPhases($goeID);           // 1 oder 3

        // Rückgabe als Array, wie im Modul überall genutzt
        return [
            'WB_Ladeleistung_W' => (float)$leistung,
            'WB_Status'         => (int)$status,
            'WB_Phasen'         => (int)$phasen,
            // Weitere Werte (z. B. Spannung, Stromstärke, etc.) bei Bedarf ergänzen!
        ];
    }

    private function SetGoEActive($goeID, $active)
    {
        if ($goeID > 0 && @IPS_InstanceExists($goeID)) {
            if (function_exists('GOeCharger_SetActive')) {
                GOeCharger_SetActive($goeID, $active);
                $this->LogTemplate('debug', "GOeCharger_SetActive aufgerufen: " . ($active ? "aktiv" : "inaktiv"));
                return true;
            } else {
                $this->LogTemplate('error', 'GOeCharger_SetActive nicht verfügbar!');
                return false;
            }
        }
        $this->LogTemplate('error', 'Ungültige GO-e Instanz-ID!');
        return false;
    }


    /*private function SetGoEParameter(array $params)
    {
        //$ip = trim($this->ReadPropertyString('WallboxIP'));
        $ip = '192.168.98.5';
        $key = trim($this->ReadPropertyString('WallboxAPIKey'));

        $this->LogTemplate('debug', "DEBUG: SetGoEParameter mit IP = '$ip'");

        if (empty($ip) || $ip == "0.0.0.0") {
            $this->LogTemplate('error', "SetGoEParameter - Wallbox-IP nicht gesetzt! Kann keine Verbindung aufbauen.");
            return;
        }

        $url = "http://$ip/api/set?" . http_build_query($params);
        $opts = [
            "http" => [
                "method" => "GET",
                "header" => $key ? "X-API-KEY: $key\r\n" : "",
                "timeout" => 3
            ]
        ];
        $context = stream_context_create($opts);
        $result = @file_get_contents($url, false, $context);

        $this->LogTemplate('info', "API-Set gesendet: $url");
        if ($result === false) {
            $this->LogTemplate('error', "Fehler beim Senden des API-Set-Kommandos.");
        }
        // Optional: Result zurückgeben/prüfen
        return $result;
    }*/

    /*private function SetGoEChargingActive($active)
    {
        //$ip = trim($this->ReadPropertyString('WallboxIP'));
        $ip = '192.168.98.5';
        $apiKey = trim($this->ReadPropertyString('WallboxAPIKey'));
        if (empty($ip) || $ip == "0.0.0.0") {
            $this->LogTemplate('error', "SetGoEChargingActive - Wallbox-IP nicht gesetzt! Kann keine Verbindung aufbauen.");
            return;
        }

        // --- NEU: Live-Abfrage ---
        $alwStatus = $this->GetGoEAlwStatus();
        if ($alwStatus !== null && (int)$alwStatus === (int)$active) {
            $this->LogTemplate('debug', "alw bereits auf $alwStatus – kein Setzen nötig.");
            return true;
        }

        // Nur beim Aktivieren: dwo=0 setzen!
        if ($active) {
            $headers = $apiKey ? ["http" => [
                "header" => "X-API-KEY: $apiKey\r\n",
                "timeout" => 2
            ]] : ["http" => ["timeout" => 2]];
            $context = stream_context_create($headers);
            $resetUrl = "http://$ip/api/set?dwo=0";
            $resetResult = @file_get_contents($resetUrl, false, $context);

            if ($resetResult === false) {
                $this->LogTemplate('warn', "Konnte dwo=0 nicht setzen! (ggf. API-Key prüfen)");
            } else {
                $this->LogTemplate('debug', "dwo=0 erfolgreich gesetzt.");
            }
        }

        // Ladefreigabe setzen
        $alwValue = $active ? 1 : 0;
        $setUrl = "http://$ip/mqtt?payload=alw=$alwValue";
        $headers = $apiKey ? ["http" => [
            "header" => "X-API-KEY: $apiKey\r\n",
            "timeout" => 2
        ]] : ["http" => ["timeout" => 2]];
        $context = stream_context_create($headers);

        $result = @file_get_contents($setUrl, false, $context);

        if ($result === false) {
            $this->LogTemplate('error', "Fehler beim Setzen von alw=$alwValue an $ip (/mqtt)!");
            return false;
        }
        $this->LogTemplate('info', "Ladefreigabe gesetzt: alw=$alwValue an $ip (/mqtt)");
        return true;
    }*/


    // =========================================================================
    // 7. FAHRZEUGSTATUS / SOC / ZIELZEIT
    // =========================================================================

    private function IstFahrzeugVerbunden($wb)
    {
        // go-e Status: 2 = lädt, 3 = verbunden, wartet, 4 = vollgeladen
        $status = $wb['WB_Status'] ?? 0;
        return in_array($status, [2, 3, 4], true);
    }


    private function SetLademodusAutoReset()
    {
        // Setzt alle Lademodi zurück: NurPV ist Standard (0)
        $id = @$this->GetIDForIdent('AktiverLademodus');
        if ($id > 0) {
            SetValue($id, 0); // 0 = NurPV-Modus (Standard)
        }
    }

    private function LeseFahrzeugSOC()
    {
        $socID = $this->ReadPropertyInteger('CarSOCID');
        if ($socID > 0 && @IPS_VariableExists($socID)) {
            return (float)GetValue($socID);
        }
        // Rückfall auf einen festen Wert (Property)
        return (float)$this->ReadPropertyInteger('CarSOCFallback');
    }

    private function LeseZielSOC()
    {
        $targetID = $this->ReadPropertyInteger('CarTargetSOCID');
        if ($targetID > 0 && @IPS_VariableExists($targetID)) {
            return (float)GetValue($targetID);
        }
        // Fallback: Property-Wert (z. B. 80 für 80 %)
        return (float)$this->ReadPropertyInteger('CarTargetSOCFallback');
    }

    private function LeseZielzeit()
    {
        $varID = @$this->GetIDForIdent('CarChargeTargetTime');
        if ($varID > 0) {
            return (int)GetValue($varID);
        }
        // Fallback: z. B. 6:00 Uhr heute
        return strtotime('today 06:00');
    }

    private function BerechneLadedauerBisZiel($istSOC, $sollSOC, $ladeleistung)
    {
        $akku_kwh = $this->ReadPropertyFloat('CarBatteryCapacity');
        $delta_soc = max(0, $sollSOC - $istSOC);
        $bedarf_kwh = $akku_kwh * $delta_soc / 100.0;
        // Ladeleistung in kW
        $ladeleistung_kw = $ladeleistung / 1000.0;
        if ($ladeleistung_kw > 0.1) {
            return $bedarf_kwh / $ladeleistung_kw; // Stunden (dezimal)
        }
        return INF; // Unendlich, wenn keine Ladeleistung verfügbar
    }
	
	private function BerechneLadestartzeit($zielzeit, $dauer_stunden)
	{
        return max(time(), $zielzeit - (int)($dauer_stunden * 3600));

	}

    // =========================================================================
    // 8. LOGGING / STATUSMELDUNGEN / DEBUG
    // =========================================================================

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

    private function SetLademodusStatus($msg)
    {
        $this->SetValueSafe('Wallbox_Status', $msg);
    }

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

    // =========================================================================
    // 9. HILFSFUNKTIONEN & GETTER/SETTER
    // =========================================================================

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

    private function FormatiereZeit($timestamp)
    {
        // TODO
    }

    private function LeseVariable($id, $typ = 'float', $invert = false)
    {
        // TODO
    }

    private function WerteValidieren($daten)
    {
        // TODO
    }

    private function CheckSchwellenwerte()
    {
        $minA    = $this->ReadPropertyInteger('MinAmpere');
        $maxA    = $this->ReadPropertyInteger('MaxAmpere');
        $phasen  = $this->ReadPropertyInteger('Phasen');

        // Technisches Minimum: 1-phasig und 3-phasig
        $min1Watt = $minA * 230 * 1;
        $min3Watt = $minA * 230 * 3;

        // Technisches Maximum: 1-phasig und 3-phasig
        $max1Watt = $maxA * 230 * 1;
        $max3Watt = $maxA * 230 * 3;

        $schwelle1 = $this->ReadPropertyFloat('Phasen1Schwelle');
        $schwelle3 = $this->ReadPropertyFloat('Phasen3Schwelle');

        // Schwelle 3-phasig prüfen
        if ($schwelle3 < $min3Watt) {
            $this->LogTemplate(
                'warn',
                "Die Schwelle für 3-phasig ($schwelle3 W) liegt **unter** dem technischen Minimum ($min3Watt W).",
                "Bitte stelle die Schwelle für 3-phasig mindestens auf $min3Watt W oder etwas darüber ein!"
            );
        } elseif ($schwelle3 > $max3Watt) {
            $this->LogTemplate(
                'warn',
                "Die Schwelle für 3-phasig ($schwelle3 W) liegt **über** dem technischen Maximum ($max3Watt W).",
                "Bitte stelle die Schwelle für 3-phasig höchstens auf $max3Watt W!"
            );
        } else {
            $this->LogTemplate(
                'ok',
                "Schwelle 3-phasig plausibel ($schwelle3 W ∈ [$min3Watt, $max3Watt] W)."
            );
        }

        // Schwelle 1-phasig prüfen
        if ($schwelle1 < $min1Watt) {
            $this->LogTemplate(
                'warn',
                "Die Schwelle für 1-phasig ($schwelle1 W) liegt **unter** dem technischen Minimum ($min1Watt W).",
                "Bitte stelle die Schwelle für 1-phasig mindestens auf $min1Watt W oder etwas darüber ein!"
            );
        } elseif ($schwelle1 > $max1Watt) {
            $this->LogTemplate(
                'warn',
                "Die Schwelle für 1-phasig ($schwelle1 W) liegt **über** dem technischen Maximum ($max1Watt W).",
                "Bitte stelle die Schwelle für 1-phasig höchstens auf $max1Watt W!"
            );
        } else {
            $this->LogTemplate(
                'ok',
                "Schwelle 1-phasig plausibel ($schwelle1 W ∈ [$min1Watt, $max1Watt] W)."
            );
        }
    }

    private function SetValueSafe($ident, $value, $precision = 2, $unit = '')
    {
        $current = $this->GetValue($ident);
        $einheit = $unit ? " $unit" : '';

        // Float-Vergleich mit Präzision
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

        // Integer-Vergleich (exakt)
        if (is_int($value) || is_int($current)) {
            if ((int)$current !== (int)$value) {
                $this->LogTemplate('debug', "{$ident}: Wert geändert von {$current}{$einheit} => {$value}{$einheit}");
                $this->SetValue($ident, $value);
            } else {
                $this->LogTemplate('debug', "{$ident}: Keine Änderung ({$current}{$einheit})");
            }
            return;
        }

        // Boolean-Vergleich
        if (is_bool($value) || is_bool($current)) {
            $cur = $current ? 'true' : 'false';
            $neu = $value ? 'true' : 'false';
            if ((bool)$current !== (bool)$value) {
                $this->LogTemplate('debug', "{$ident}: Wert geändert von {$cur}{$einheit} => {$neu}{$einheit}");
                $this->SetValue($ident, $value);
            } else {
                $this->LogTemplate('debug', "{$ident}: Keine Änderung ({$cur}{$einheit})");
            }
            return;
        }

        // String-Vergleich (mit trim)
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

        // Fallback für alle anderen Typen
        if ($current !== $value) {
            $this->LogTemplate('debug', "{$ident}: Wert geändert von {$current}{$einheit} => {$value}{$einheit}");
            $this->SetValue($ident, $value);
        } else {
            $this->LogTemplate('debug', "{$ident}: Keine Änderung ({$current}{$einheit})");
        }
    }

    private function GetAttributeOrDefault($name, $default)
    {
        // Typ des Defaults bestimmen (int, float, string, bool)
        switch (gettype($default)) {
            case 'integer':
                $val = @$this->ReadAttributeInteger($name);
                break;
            case 'double': // float in PHP = double
                $val = @$this->ReadAttributeFloat($name);
                break;
            case 'boolean':
                $val = @$this->ReadAttributeBoolean($name);
                break;
            case 'string':
            default:
                $val = @$this->ReadAttributeString($name);
                break;
        }
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

    private function GetAccessStateV2Text($state)
    {
        switch ($state) {
            case 1: return "Gesperrt";
            case 2: return "Freigegeben (Laden möglich)";
            case 3: return "Unbekannt";
            default: return "Unbekannter Status ($state)";
        }
    }

    private function UpdateAccessStateText()
    {
        $goeID = $this->ReadPropertyInteger('GOeChargerID');
        $modusID = @IPS_GetObjectIDByIdent('accessStateV2', $goeID);

        $text = "Unbekannt";
        if ($modusID && @IPS_VariableExists($modusID)) {
            $val = GetValue($modusID);
            $text = $this->GetAccessStateV2Text($val);
        } else {
            $this->LogTemplate('warn', "AccessStateText konnte nicht aktualisiert werden.", "accessStateV2-Variable nicht gefunden (GO-e Instanz $goeID)");
        }
        $this->SetValueSafe('AccessStateText', $text, 0);
    }

    private function SetzeAccessStateV2WennNoetig($goeID, $neuerModus)
    {
        if ($goeID <= 0 || !@IPS_InstanceExists($goeID)) {
            $this->LogTemplate('warn', "Kein gültiger GO-e Charger angegeben (ID: $goeID)");
            return;
        }
        $modusID = @IPS_GetObjectIDByIdent('accessStateV2', $goeID);
        $aktuellerModus = ($modusID && @IPS_VariableExists($modusID)) ? GetValue($modusID) : null;
        $this->LogTemplate('debug', "Vorher: accessStateV2 = $aktuellerModus, Ziel: $neuerModus");

        if ($aktuellerModus !== $neuerModus) {
            // IPSCoyote: Setzt per API
            $success = $this->SetGoEChargingActive((int)$neuerModus);
            if ($success) {
                $this->LogTemplate('debug', "accessStateV2 geändert: $aktuellerModus → $neuerModus (per API gesetzt)");
            } else {
                $this->LogTemplate('error', "Fehler beim Setzen von accessStateV2 auf $neuerModus!");
            }
        } else {
            $this->LogTemplate('debug', "accessStateV2 unverändert: $aktuellerModus (kein Setzen nötig)");
        }
    }

    private function PriorisiereEigenverbrauch($pv, $haus, $battSOC, $hausakkuVollSchwelle, $autoAngesteckt)
    {
        // Schwellenwert für "Hausbatterie voll" (z.B. 90 %)
        $battMax = $hausakkuVollSchwelle > 0 ? $hausakkuVollSchwelle : 90;

        // PV-Überschuss berechnen
        $ueberschuss = $pv - $haus;

        // Kein Überschuss vorhanden – nichts verteilen
        if ($ueberschuss <= 0) {
            return [0, 0];
        }

        // 1. Hausbatterie zuerst füllen
        if ($battSOC < $battMax) {
            return [0, $ueberschuss];  // Lade alles in den Speicher
        }

        // 2. Wenn Hausbatterie voll und Auto angesteckt → Ladeleistung fürs Auto
        if ($autoAngesteckt) {
            return [$ueberschuss, 0];
        }

        // 3. Kein Speicherbedarf und kein Auto → nichts tun
        return [0, 0];
    }

    private function GetOrInitAttributeInteger($name, $default = 0)
    {
        // Wenn Attribut nicht existiert, direkt setzen!
        if (!property_exists($this, 'Attribute' . $name) && method_exists($this, 'WriteAttributeInteger')) {
            $this->WriteAttributeInteger($name, $default);
            return $default;
        }
        $val = @$this->ReadAttributeInteger($name);
        return ($val === null) ? $default : $val;
    }

    private function GetOrInitAttributeBoolean($name, $default = false)
    {
        if (!property_exists($this, 'Attribute' . $name) && method_exists($this, 'WriteAttributeBoolean')) {
            $this->WriteAttributeBoolean($name, $default);
            return $default;
        }
        $val = @$this->ReadAttributeBoolean($name);
        return ($val === null) ? $default : $val;
    }

    private function GetAllCustomAttributes()
    {
        $all = [];
        // Liste aller Attribut-Namen, die du nutzt
        $attributNamen = [
            'PhasenDownCounter',
            'PhasenUpCounter',
            'LastSetLadeleistung',
            'LastSetGoEActive',
            // ... ergänzen!
        ];
        foreach ($attributNamen as $name) {
            $all[$name] = $this->ReadAttribute($name);
        }
        return $all;
    }

    private function ZaehleAktivePhasen($pha)
    {
        return substr_count(decbin(intval($pha)), '1');
    }

    /*private function GetGoEAlwStatus()
    {
        //$ip = trim($this->ReadPropertyString('WallboxIP'));
        $ip = '192.168.98.5';
        $key = trim($this->ReadPropertyString('WallboxAPIKey'));

        if (empty($ip) || $ip == "0.0.0.0") {
            $this->LogTemplate('error', "GetGoEAlwStatus - Wallbox-IP nicht gesetzt! Kann keine Verbindung aufbauen.");
            return;
        }

        $url = "http://$ip/api/status?filter=alw";
        $opts = [
            "http" => [
                "method" => "GET",
                "header" => $key ? "X-API-KEY: $key\r\n" : "",
                "timeout" => 2
            ]
        ];
        $context = stream_context_create($opts);
        $json = @file_get_contents($url, false, $context);
        if ($json === false) {
            $this->LogTemplate('warn', "Konnte alw-Status nicht von der Wallbox lesen!");
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !array_key_exists('alw', $data)) {
            $this->LogTemplate('warn', "alw-Status: Antwortformat unerwartet ($json)");
            return null;
        }
        // bool oder int, je nach Firmware!
        return (int)$data['alw'];
    }*/

}
