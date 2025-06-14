# Symcon PV Wallbox Manager

Ein leistungsfähiges IP-Symcon-Modul zur intelligenten Steuerung einer go-e Charger Wallbox (ab Hardware V3/V4) auf Basis von PV-Überschuss, Batteriespeicher, SOC-Zielladung und flexiblen Lademodi.

## Features

- ⚡ PV-Überschussladen mit automatischer Leistungsanpassung
- 🔁 Dynamische 1-/3-phasige Umschaltung mit Hysterese
- 🚗 Manueller Ladebefehl (volle Netzladung)
- 🌤️ PV2Car-Modus: Anteiliger PV-Überschuss fürs Auto
- ⏰ Zielladung nach Uhrzeit und SOC-Ziel
- 🔌 SofarSolar-Modbus-Steuerung (optional)
- 📋 Logging & Visualisierung im WebFront

## Voraussetzungen

- IP-Symcon ab Version 6.3
- go-e Charger ab Hardwareversion V3/V4
- Messwerte: PV-Leistung, Hausverbrauch, Batteriespeicher (kW)
- Optional: SofarSolar Hybrid-Wechselrichter via Modbus

## Installation

Füge das Modul in der IP-Symcon Modulverwaltung hinzu:

https://github.com/pesensie/symcon-pv-wallbox-manager.git


## Beispiel – Visualisierung im WebFront

...

## Lizenz

Dieses Projekt steht unter der MIT-Lizenz – siehe [LICENSE](LICENSE).
