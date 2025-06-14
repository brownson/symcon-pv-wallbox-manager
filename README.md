# Symcon PV Wallbox Manager

Ein leistungsfähiges IP-Symcon-Modul zur intelligenten Steuerung einer go-e Charger Wallbox (ab Hardware V3/V4) auf Basis von PV-Überschuss, Batterie-Status, SOC-Zielladung und frei definierbaren Lade-Modi.

## Funktionen

- ⚡ PV-Überschussladen mit automatischer Stromanpassung
- 🔄 1-/3-phasige Umschaltung mit Hysterese
- 🔘 Manueller Lademodus (volle Leistung sofort)
- ☀️ PV2Car-Modus (prozentuale PV-Zuweisung)
- ⏰ Zielladung bis Uhrzeit und SOC
- 🔒 Nur-Netzladung via SofarSolar-Modbus
- 📊 Visualisierung & Logging im WebFront

## Voraussetzungen

- IP-Symcon 6.3 oder neuer
- go-e Charger V3 oder V4
- PV-Erzeugung, Hausverbrauch, Batteriespeicher via Symcon (z. B. per Modbus oder MQTT)
- Optional: SofarSolar Wechselrichter via Modbus TCP

## Installation

1. Modul in IP-Symcon einbinden:
    ```
    https://github.com/pesensie/symcon-pv-wallbox-manager.git
    ```

2. Instanz im Objektbaum erstellen

3. Konfiguration: Variablen & Lade-Modi zuweisen

## Struktur

```text
symcon-pv-wallbox-manager/
├── README.md
├── module.json
└── PVWallboxManager/
    ├── module.php
    ├── PVWallboxManager.json
    └── EnergieScript.php
```

## Lizenz

MIT License – siehe `LICENSE.md`
