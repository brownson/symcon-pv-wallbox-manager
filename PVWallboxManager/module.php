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
        $this->RegisterPropertyString('WallboxIP', '');
        $this->RegisterPropertyString('WallboxAPIKey', '');
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

        // Attribut-Initialisierung für Phasen-Hysteresezähler (robust, keine IPS-Warnings)
        //$this->EnsurePhasenCounterAttributes();

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

        $wb = $this->HoleGoEWallboxDaten();
        if (!is_array($wb)) {
            $this->LogTemplate('error', "Wallbox-Daten konnten nicht abgerufen werden, Update abgebrochen.");
            return;
        }

        $goeID = $this->ReadPropertyInteger('GOeChargerID');
        if ($goeID <= 0 || !@IPS_InstanceExists($goeID)) {
            $this->LogTemplate('warn', "Keine Wallbox-Instanz ausgewählt oder ID ungültig.", "Bitte GO-e Charger im Modul konfigurieren.");
            $this->SetLademodusStatus("Wallbox nicht konfiguriert!");
            return;
        }

        $status = $wb['WB_Status'] ?? null;
        $this->LogTemplate('info', "Check: \$status = " . var_export($status, true) . ", verbunden? " . ($this->IstFahrzeugVerbunden($wb) ? 'JA' : 'NEIN'));

        // Prüfe: Nur laden, wenn Fahrzeug verbunden
        if ($this->ReadPropertyBoolean('NurMitFahrzeug') && !$this->IstFahrzeugVerbunden($wb)) {
            $this->SetzeAccessStateV2WennNoetig($goeID, 1); // Gesperrt
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
        $wb_leistung = $this->LeseWallboxLeistung($wb);

        // Rohwert und Puffer berechnen
        $roh_ueberschuss = $this->BerechnePVUeberschuss($pv, $haus, $batt, $wb_leistung);
        list($ueberschuss, $pufferFaktor) = $this->BerechnePVUeberschussMitPuffer($roh_ueberschuss);

        // --- Konsistenz: Diesen Wert verwenden wir immer weiter ---
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
        $statusNum = $wb['WB_Status'] ?? 0;
        switch ($statusNum) {
            case 1:
                $this->SetLademodusStatus("Fahrzeug bereit – warte auf Freigabe."); break;
            case 2:
                $this->SetLademodusStatus("Fahrzeug lädt."); break;
            case 3:
                $this->SetLademodusStatus("Fahrzeug angesteckt – warte auf Start."); break;
            default:
                $this->SetLademodusStatus("Status unbekannt.");
        }

        // Ladeleistung ermitteln
        switch ($modus) {
            case 'manuell':
                //$this->EnsurePhasenCounterAttributes();
                $ladeleistung = $this->BerechneLadeleistungManuell();
                $this->PruefePhasenumschaltung($ladeleistung, $wb);
                $this->LogTemplate('debug', sprintf(
                    "Hysteresecounter: Down=%d | Up=%d",
                    $this->GetOrInitAttributeInteger('PhasenDownCounter'),
                    $this->GetOrInitAttributeInteger('PhasenUpCounter')
                ));
                $this->SetzeLadeleistung($ladeleistung);
                break;

            case 'pv2car':
                $prozent = $this->GetPV2CarProzent();
                $ladeleistung = $this->BerechneLadeleistungPV2Car($ueberschuss, $prozent);
                $this->PruefePhasenumschaltung($ladeleistung, $wb);
                $this->LogTemplate('debug', sprintf(
                    "Hysteresecounter: Down=%d | Up=%d",
                    $this->GetOrInitAttributeInteger('PhasenDownCounter'),
                    $this->GetOrInitAttributeInteger('PhasenUpCounter')
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
                    $this->GetOrInitAttributeInteger('PhasenDownCounter'),
                    $this->GetOrInitAttributeInteger('PhasenUpCounter')
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
                    $this->GetOrInitAttributeInteger('PhasenDownCounter'),
                    $this->GetOrInitAttributeInteger('PhasenUpCounter')
                ));
                $this->SetzeLadeleistung($ladeleistung);
                break;

            case 'nurpv':
            default:
                // Eigenverbrauchs-Priorisierung (falls aktiviert)
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

                // Ladeleistung für Auto ist der relevante Überschuss
                //$ueberschuss = $ladeleistungAuto;

                // Counter laden
                $startCounter = (int)$this->GetBuffer('StartHystereseCounter');
                $stopCounter  = (int)$this->GetBuffer('StopHystereseCounter');
                $ladeleistung = 0;

                // Schwellwerte und Hysterese einlesen
                $minLadeWatt    = $this->ReadPropertyFloat('MinLadeWatt');
                $minStopWatt    = $this->ReadPropertyFloat('MinStopWatt');
                $startHysterese = $this->ReadPropertyInteger('StartHysterese');
                $stopHysterese  = $this->ReadPropertyInteger('StopHysterese');
                $istAmLaden     = ($wb['WB_Status'] ?? 0) == 2;

                // Phasenumschaltung/Hysterese prüfen vor dem Ladeentscheid!
                $this->PruefePhasenumschaltung($ueberschuss, $wb);
                $this->LogTemplate('debug', sprintf(
                    "Hysteresecounter: Down=%d | Up=%d",
                    $this->GetOrInitAttributeInteger('PhasenDownCounter'),
                    $this->GetOrInitAttributeInteger('PhasenUpCounter')
                ));

                // ** Niemals laden ohne PV-Überschuss (egal was Hysterese oder Status)**
                if ($ueberschuss < $minLadeWatt) {
                    $this->SetGoEChargingActive(false);
                    $this->SetzeLadeleistung(0);
                    $this->LogTemplate('info', "Kein PV-Überschuss – Ladung deaktiviert.");
                    // Buffer zurücksetzen!
                    $this->SetBuffer('StartHystereseCounter', 0);
                    $this->SetBuffer('StopHystereseCounter', 0);
                    break; // Nichts tun!
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

                // Variablen schreiben (nur exakt dieser Überschuss-Wert zählt!)
                $this->SetValueSafe('WB_Ladeleistung_Soll', $ladeleistung, 1);
                $this->SetValueSafe('WB_Ladeleistung_Ist', $wb_leistung, 1);
                $phasen_ist = $wb['WB_Phasen'] ?? 1;
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

    private function LeseWallboxLeistung($wb)
    {
        return (float)($wb['WB_Ladeleistung_W'] ?? 0.0);
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
        // Phasenstatus prüfen (robust)
        $phasen_ist = isset($wb['WB_Phasen']) && in_array($wb['WB_Phasen'], [1, 3]) ? $wb['WB_Phasen'] : 0;
        if ($phasen_ist === 0) {
            $this->LogTemplate('warn', "Phasenstatus ungültig oder unbekannt, kann nicht umschalten!");
            return false;
        }

        $umschaltung = false;

        // Umschaltung auf 1-phasig
        if ($phasen_ist == 3 && $this->PruefeHystereseDown($ladeleistung)) {
            $this->UmschaltenAuf1Phasig();
            IPS_Sleep(1500);
            $wbNeu = $this->HoleGoEWallboxDaten();
            $phasen_ist = $wbNeu['WB_Phasen'] ?? 1;
            $this->LogTemplate('info', 'Umschaltung auf 1-phasig ausgelöst.', "Leistung: $ladeleistung W | ECHTE Phasen: $phasen_ist");
            $umschaltung = true;
        }
        // Umschaltung auf 3-phasig
        elseif ($phasen_ist == 1 && $this->PruefeHystereseUp($ladeleistung)) {
            $this->UmschaltenAuf3Phasig();
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
            $this->GetOrInitAttributeInteger('PhasenDownCounter'), 
            $this->GetOrInitAttributeInteger('PhasenUpCounter')
        ));

        if (!$umschaltung) {
            $this->LogTemplate('debug', "Keine Phasenumschaltung nötig. (Phasen: $phasen_ist, Leistung: $ladeleistung W)");
        }

        return $umschaltung;
    }

    private function PruefeHystereseDown($ladeleistung)
    {
        $phasen1Schwelle = $this->ReadPropertyFloat('Phasen1Schwelle');    // z.B. 3400 W
        $phasen1Limit    = $this->ReadPropertyInteger('Phasen1Limit');      // z.B. 3

        // Typ-stabil und immer initialisiert
        $counter = $this->GetOrInitAttributeInteger('PhasenDownCounter', 0);

        if ($ladeleistung < $phasen1Schwelle) {
            $counter++;
            $this->LogTemplate('debug', "Phasen-Hysterese-Down: $counter x < {$phasen1Schwelle} W");
        } else {
            $counter = 0;
        }
        $this->WriteAttributeInteger('PhasenDownCounter', $counter);
        if ($counter >= $phasen1Limit) {
            $this->WriteAttributeInteger('PhasenDownCounter', 0);
            return true;
        }
        return false;
    }

    private function PruefeHystereseUp($ladeleistung)
    {
        $phasen3Schwelle = $this->ReadPropertyFloat('Phasen3Schwelle');    // z.B. 4200 W
        $phasen3Limit    = $this->ReadPropertyInteger('Phasen3Limit');      // z.B. 3

        // Typ-stabil und immer initialisiert
        $counter = $this->GetOrInitAttributeInteger('PhasenUpCounter', 0);

        if ($ladeleistung > $phasen3Schwelle) {
            $counter++;
            $this->LogTemplate('debug', "Phasen-Hysterese-Up: $counter x > {$phasen3Schwelle} W");
        } else {
            $counter = 0;
        }
        $this->WriteAttributeInteger('PhasenUpCounter', $counter);
        if ($counter >= $phasen3Limit) {
            $this->WriteAttributeInteger('PhasenUpCounter', 0);
            return true;
        }
        return false;
    }

    private function UmschaltenAuf1Phasig()
    {
        $this->SetGoEChargingActive(false);
        IPS_Sleep(1200);
        $this->SetGoEParameter(['psm' => 1]); // 1-phasig: psm=1!
        IPS_Sleep(1500);
        $this->SetGoEChargingActive(false); // <--- NEU: immer aus!
        $this->LogTemplate('info', 'Phasenumschaltung auf 1-phasig abgeschlossen.');
    }

    private function UmschaltenAuf3Phasig()
    {
        $this->SetGoEChargingActive(false);
        IPS_Sleep(1200);
        $this->SetGoEParameter(['psm' => 2]); // 3-phasig: psm=2!
        IPS_Sleep(1200);
        $this->SetGoEChargingActive(false); // <--- NEU: immer aus!
        $this->LogTemplate('info', 'Phasenumschaltung auf 3-phasig abgeschlossen.');
    }

    private function EnsurePhasenCounterAttributes()
    {
        if (!@is_int($this->ReadAttributeInteger('PhasenDownCounter'))) {
            $this->WriteAttributeInteger('PhasenDownCounter', 0);
        }
        if (!@is_int($this->ReadAttributeInteger('PhasenUpCounter'))) {
            $this->WriteAttributeInteger('PhasenUpCounter', 0);
        }
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
        $lastWatt   = $this->ReadAttributeInteger('LastSetLadeleistung');
        $lastActive = $this->ReadAttributeBoolean('LastSetGoEActive');

        $phasen   = max(1, (int)$this->ReadPropertyInteger('Phasen'));
        $spannung = 230;
        $minAmp   = max(6, (int)$this->ReadPropertyInteger('MinAmpere'));
        $maxAmp   = min(32, (int)$this->ReadPropertyInteger('MaxAmpere'));
        $ampere   = round($leistung / ($phasen * $spannung));
        $ampere   = max($minAmp, min($maxAmp, $ampere));
        $minWatt  = $minAmp * $phasen * $spannung;

        // 1. Zu geringe Leistung: abschalten, wenn Änderung
        if ($leistung < $minWatt) {
            if ($lastActive !== false) {
                $this->SetGoEChargingActive(false);
                $this->LogTemplate('info', "Ladung deaktiviert (Leistung zu gering, alw=0).");
                $this->WriteAttributeInteger('LastSetLadeleistung', 0);
                $this->WriteAttributeBoolean('LastSetGoEActive', false);

                // Wallbox-Status neu abfragen & loggen
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
        $this->SetGoEChargingActive(true);
        IPS_Sleep(1200);
        $this->SetGoEParameter(['amp' => $ampere]);
        $this->LogTemplate('info', "Ladung aktiviert: alw=1, amp=$ampere (für $leistung W, $phasen Phasen)");

        $this->WriteAttributeInteger('LastSetLadeleistung', $leistung);
        $this->WriteAttributeBoolean('LastSetGoEActive', true);

        // Wallbox-Status neu abfragen & loggen
        $status = $this->HoleGoEWallboxDaten();
        $this->LogTemplate('debug', "Wallbox nach Setzen: " . print_r($status, true));
    }


    private function SetzeWallboxModus($modus)
    {
        // Hier kannst du je nach Wallbox Typ/Modul z. B. zwischen Sofortladen, Überschuss etc. umschalten.
        // Bei go-e gibt's dafür oft keine extra Methode – Status könnte aber über Variable o. Ä. gesetzt werden.
    }

    private function DeaktiviereLaden()
    {
        $this->SetzeLadeleistung(0);
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
        /**$phasen = 0;
        if (isset($data['pha']) && is_array($data['pha'])) {
            // In V4 ist das ein Array mit den Strömen pro Phase (A)
            // 0: L1, 1: L2, 2: L3, 3: bool L1 aktiv, 4: bool L2 aktiv, 5: bool L3 aktiv
            $aktivePhasen = 0;
            foreach ([3, 4, 5] as $idx) {
                if (!empty($data['pha'][$idx])) $aktivePhasen++;
            }
            $phasen = $aktivePhasen; // Kann auch 2 sein, falls nur 2 aktiv!
            // Nur 1 oder 3 ist korrekt, alles andere als Fehler behandeln
            if ($phasen !== 1 && $phasen !== 3) {
                $this->LogTemplate('warn', "Unerwartete Phasenanzahl: $phasen (pha-Array: ".json_encode($data['pha']).")");
                // Du kannst notfalls auf 1 oder 3 mappen oder Fehler werfen
            }
        }
        */
        $phasen = 0;
        if (isset($data['pha'])) {
            $phasen = $this->ZaehleAktivePhasen($data['pha']);
        }
        $werte = [
            // ...
            'WB_Phasen' => $phasen,
            // ...
        ];
        $this->SetValueSafe('AktuellePhasen', $phasen, 0);

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

        $this->LogTemplate(
            'info',
            "Wallbox-Status laut API (car): " . var_export($werte['WB_Status'], true),
            "Erklärung: 0=keins, 1=bereit, 2=lade, 3=angesteckt"
        );

        // Ausgabe im Log (zum Testen)
        foreach ($werte as $name => $wert) {
            $this->LogTemplate('debug', "$name: ".var_export($wert, true));
        }
        return $werte;
    }

    private function SetGoEParameter(array $params)
    {
        $ip  = $this->ReadPropertyString('WallboxIP');
        $key = trim($this->ReadPropertyString('WallboxAPIKey'));

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
    }

    private function SetGoEChargingActive($active)
    {
        $ip = trim($this->ReadPropertyString('WallboxIP'));
        $apiKey = trim($this->ReadPropertyString('WallboxAPIKey'));
        if (empty($ip) || $ip == "0.0.0.0") {
            $this->LogTemplate('error', 'Keine gültige IP für die Wallbox eingetragen!');
            return false;
        }

        // Status merken
        $lastActive = $this->ReadAttributeBoolean('LastSetGoEActive');

        // Nur wenn Status sich ändert:
        if ($lastActive === $active) {
            $this->LogTemplate('debug', "alw bereits auf $active – kein Setzen nötig.");
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

        // Jetzt alw setzen (immer, wenn sich Status ändert)
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
        $this->WriteAttributeBoolean('LastSetGoEActive', $active);
        return true;
    }


    // =========================================================================
    // 7. FAHRZEUGSTATUS / SOC / ZIELZEIT
    // =========================================================================

    private function IstFahrzeugVerbunden($wb)
    {
        $status = $wb['WB_Status'] ?? 0;
        return ($status == 2 || $status == 3 || $status == 4);
    }

    private function SetLademodusAutoReset()
    {
        // Beispiel: Lademodus-Variablen zurücksetzen
        // SetValue($this->GetIDForIdent('ManuellLaden'), false);
        // SetValue($this->GetIDForIdent('PV2CarModus'), false);
        // SetValue($this->GetIDForIdent('ZielzeitModus'), false);
        // ... ggf. weitere
    }

    private function LeseFahrzeugSOC()
    {
        $socID = $this->ReadPropertyInteger('CarSOCID');
        if ($socID > 0 && @IPS_VariableExists($socID)) {
            return (float)GetValue($socID);
        }
        return (float)$this->ReadPropertyInteger('CarSOCFallback');
    }

    private function LeseZielSOC()
    {
        $targetID = $this->ReadPropertyInteger('CarTargetSOCID');
        if ($targetID > 0 && @IPS_VariableExists($targetID)) {
            return (float)GetValue($targetID);
        }
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
			return $bedarf_kwh / $ladeleistung_kw;
		}
		return INF; // "Unendlich", wenn keine Ladeleistung verfügbar
	}
	
	private function BerechneLadestartzeit($zielzeit, $dauer_stunden)
	{
		return $zielzeit - (int)($dauer_stunden * 3600);
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
        $minA = $this->ReadPropertyInteger('MinAmpere');
        $phasen = $this->ReadPropertyInteger('Phasen');

        // Technisches Minimum: 1-phasig
        $min1Watt = $minA * 230 * 1;
        // Technisches Minimum: 3-phasig
        $min3Watt = $minA * 230 * 3;

        $schwelle1 = $this->ReadPropertyFloat('Phasen1Schwelle');
        $schwelle3 = $this->ReadPropertyFloat('Phasen3Schwelle');

        // Prüfen auf Plausibilität
        if ($schwelle3 < $min3Watt) {
            $this->LogTemplate(
                'warn',
                "Die Schwelle für 3-phasig ($schwelle3 W) liegt **unter** dem technischen Minimum ($min3Watt W).",
                "Bitte stelle die Schwelle für 3-phasig mindestens auf $min3Watt W oder etwas darüber ein!"
            );
        } else {
            $this->LogTemplate(
                'ok',
                "Schwelle 3-phasig plausibel ($schwelle3 W ≥ $min3Watt W)."
            );
        }

        if ($schwelle1 < $min1Watt) {
            $this->LogTemplate(
                'warn',
                "Die Schwelle für 1-phasig ($schwelle1 W) liegt **unter** dem technischen Minimum ($min1Watt W).",
                "Bitte stelle die Schwelle für 1-phasig mindestens auf $min1Watt W oder etwas darüber ein!"
            );
        } else {
            $this->LogTemplate(
                'ok',
                "Schwelle 1-phasig plausibel ($schwelle1 W ≥ $min1Watt W)."
            );
        }
    }

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

    private function SetzeAccessStateV2WennNoetig($goeID, $neuerModus)
    {
        $modusID = @IPS_GetObjectIDByIdent('accessStateV2', $goeID);
        $aktuellerModus = ($modusID && @IPS_VariableExists($modusID)) ? GetValue($modusID) : null;
        $this->LogTemplate('debug', "Vorher: accessStateV2 = $aktuellerModus, Ziel: $neuerModus");

        if ($aktuellerModus !== $neuerModus) {
            // ---- NEU: API-Call via SetGoEChargingActive ----
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

    private function GetAccessStateV2Text($val)
    {
        switch ($val) {
            case 0:  return "⚪ Neutral (Wallbox entscheidet selbst)";
            case 1:  return "🚫 Nicht laden (gesperrt)";
            case 2:  return "⚡ Laden (erzwungen)";
            default: return "❔ Unbekannter Modus ($val)";
        }
    }

    private function PriorisiereEigenverbrauch($pv, $haus, $battSOC, $hausakkuVollSchwelle, $autoAngesteckt)
    {
        $battMax = $hausakkuVollSchwelle; // z.B. 90 %
        $ueberschuss = $pv - $haus;

        if ($ueberschuss <= 0) {
            return [0, 0];
        }
        // Hausbatterie nicht voll?
        if ($battSOC < $battMax) {
            return [0, $ueberschuss];
        }
        // Hausbatterie voll, Auto angesteckt?
        if ($autoAngesteckt) {
            return [$ueberschuss, 0];
        }
        // Hausbatterie voll, kein Auto
        return [0, 0];
    }

    private function GetOrInitAttributeInteger($name, $default = 0)
    {
        // Versuche das Attribut zu lesen (Suppress Warning)
        $val = @$this->ReadAttributeInteger($name);

        // Prüfe, ob das Ergebnis tatsächlich ein Integer ist
        if (!is_int($val)) {
            // Schreibe Attribut (wird beim nächsten Durchlauf dann „existieren“)
            $this->WriteAttributeInteger($name, $default);
            // Gib Default-Wert zurück (Warnung ist jetzt einmalig, danach nicht mehr)
            return $default;
        }
        return $val;
    }

    private function GetAttributes()
	{
        $obj = IPS_GetInstance($this->InstanceID);
        return isset($obj['Attributes']) ? $obj['Attributes'] : [];
    }

    private function ZaehleAktivePhasen($pha)
    {
        // pha kann als int oder string (dezimal, hex, etc.) kommen
        $value = intval($pha);
        // Alle Bits zählen, die auf 1 stehen
        $anzahl = 0;
        for ($i = 0; $i < 8; $i++) {
            if (($value >> $i) & 1) $anzahl++;
        }
        return $anzahl;
    }
}
