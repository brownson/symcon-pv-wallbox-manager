# PVWallboxManager (IP-Symcon Modul)

Der PVWallboxManager ist ein IP-Symcon Modul zur intelligenten Steuerung einer Wallbox basierend auf PV-Überschuss. Ziel ist eine flexible und modulare Ladeautomatik für verschiedene Wallboxtypen, beginnend mit dem go-e Charger (via IPSCoyote-Modul).

## 🔧 Funktionen

- Berechnung des aktuellen PV-Überschusses:
  - Formel: **PV-Erzeugung – Hausverbrauch – Batterieladung**
  - Unterstützung für Hausbatterien (positiv = lädt, negativ = entlädt)
- Dynamische Ladeleistungsregelung basierend auf:
  - Anzahl der Phasen (1/3)
  - konfigurierbarer min./max. Stromstärke
  - Netzspannung (standardmäßig 230 V)
- Ladefreigabe nur bei ausreichendem Überschuss (Schwellenwert)
- Automatische Umschaltung zwischen Laden und Nicht-Laden:
  - `Modus 2` = Immer laden
  - `Modus 1` = Nicht laden
- Nur bei tatsächlicher Änderung wird Modus oder Ladeleistung gesetzt
- Float-Toleranzfilter für saubere Berechnung
- Umfangreiches Logging im IP-Symcon Meldungsfenster mit Symbolen

## ⚙️ Konfiguration

Alle Einstellungen erfolgen direkt im IP-Symcon-Modul-Konfigurator (`form.json`):

- PV-Erzeugung (Variable-ID in Watt)
- Hausverbrauch (Variable-ID in Watt)
- Batterieladung (Variable-ID in Watt)
- go-e Charger Instanz (IPSCoyote)
- Refresh-Intervall in Sekunden (15–600)
- Phasenanzahl (1 oder 3)
- Min. & Max. Ampere (z. B. 6–16 A)
- MinLadeWatt (Schwelle für Start)
- MinStopWatt (Schwelle für Stop)

## 📦 Installation

In der IP-Symcon Konsole:

1. „Kerninstanzen → Module → Hinzufügen“
2. Git-URL:  
https://github.com/pesensie/symcon-pv-wallbox-manager
3. Instanz „PVWallboxManager“ hinzufügen und konfigurieren

Oder via Konsole:
git clone https://github.com/pesensie/symcon-pv-wallbox-manager.git

## ✅ Voraussetzungen
IP-Symcon v8 oder höher

GO-eCharger Modul von IPSCoyote (für go-e Unterstützung)

korrekte Zuweisung der Energie-Messwerte und Wallbox-Instanz

## 🕘 Changelog
Alle Änderungen findest du in CHANGELOG.md

## 🚀 Roadmap
Unterstützung weiterer Wallbox-Marken (z. B. Heidelberg, openWB, SMA EV-Charger)
Phasenumschaltung bei Bedarf (1 ↔ 3)
Zeitgesteuerte Zielladung (z. B. „bis 06:00 Uhr 80 %“)
PV2Car-Modus mit %-Regler
Visualisierung im WebFront
MQTT-Integration
Debug- und Simulationsmodus

## 📄 Lizenz
Dieses Projekt steht unter der MIT License.