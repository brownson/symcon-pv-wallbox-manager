# Changelog

## v0.1 – 2025-06-16

### ✅ Grundfunktionen:
- Berechnung des PV-Überschusses: `PV-Erzeugung - Hausverbrauch - Batterieladung`
- Unterstützung eines Speichers (positive Werte = laden, negative = entladen)
- Einstellbares Intervall (15–600 Sekunden)
- Visualisierung des PV-Überschusses als Modul-Variable
- Timer zur automatischen Ausführung aktiviert

### ⚙️ Dynamische Ladeleistungsberechnung:
- Ermittlung der Ladeleistung in Watt (basierend auf Ampere, Phasen, 230 V)
- Berücksichtigung konfigurierbarer min./max. Ampere und Phasenanzahl
- Schwellenwerte konfigurierbar: `MinLadeWatt`, `MinStopWatt`
- Automatische Umschaltung zwischen Start/Stopp abhängig vom Überschuss

### 🔌 go-e Charger Integration (IPSCoyote Modul):
- Wahl der go-e Instanz via `form.json`
- Unterstützung für `GOeCharger_setMode` (1 = Nicht laden, 2 = Immer laden)
- Unterstützung für `GOeCharger_SetCurrentChargingWatt`
- Automatische Erkennung des aktuellen Modus über Variable `accessStateV2`

### 🧠 Optimierungen:
- Modus wird **nur** gesetzt, wenn sich der Zustand wirklich ändert
- Ladeleistung wird **nur aktualisiert**, wenn sie sich signifikant verändert (> 50 W)
- Float-Toleranz (z. B. -1E-13 W wird als 0 behandelt)
- Detaillierte Symcon-Logmeldungen mit Symbolen und Statusangaben
