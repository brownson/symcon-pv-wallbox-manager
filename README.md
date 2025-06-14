# Symcon PV Wallbox Manager

Ein leistungsfähiges IP-Symcon-Modul zur intelligenten Steuerung einer go-e Charger Wallbox (ab Hardware V3/V4) auf Basis von PV-Überschuss, Batteriestatus, SOC-Zielladung und frei definierbaren Lade-Modi.

## Features

- PV-Überschussladen mit automatischer Leistungsanpassung
- Dynamische 1-/3-phasige Umschaltung mit Hysterese
- Manueller Ladebefehl (volle Netzladung)
- PV2Car-Modus: Anteiliger PV-Überschuss fürs Auto
- Zielladung basierend auf Uhrzeit und Ziel-SOC
- SofarSolar-Wechselrichter-Modbus-Steuerung (optional)
- Live-Log und WebFront-Visualisierung

## Voraussetzungen

- IP-Symcon ab Version 6.3
- go-e Charger V3 oder V4
- PV-Erzeugung, Hausverbrauch, Batteriespeicher als Variablen
- Optional: SofarSolar-Modbusintegration

## Installation

```bash
git clone https://github.com/pesensie/symcon-pv-wallbox-manager.git
