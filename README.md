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


| Property-Name         | Typ     | Zweck / Empfohlene Variable                        |
| --------------------- | ------- | -------------------------------------------------- |
| PVErzeugungID         | Integer | PV-Erzeugung (W) – z. B. Smartmeter, Zähler        |
| HausverbrauchID       | Integer | Hausverbrauch (W) – Zähler/Smartmeter              |
| BatterieladungID      | Integer | Batterie-Ladeleistung (W) – Wechselrichter         |
| WallboxLadeleistungID | Integer | Aktuelle Wallbox-Leistung (W)                      |
| WallboxAktivID        | Boolean | Wallbox aktiv (Bool)                               |
| ModbusRegisterID      | Integer | Modbus: Sofar Energy Storage Mode                  |
| SOC\_HausspeicherID   | Integer | SOC Hausspeicher (0–100 %)                         |
| SOC\_AutoID           | Integer | SOC E-Auto (0–100 %)                               |
| ManuellerModusID      | Boolean | Button: Manueller Modus                            |
| PV2CarModusID         | Boolean | Button: PV2Car-Modus                               |
| PV2CarPercentID       | Integer | Regler: Anteil PV-Überschuss fürs Auto (%)         |
| ZielzeitladungID      | Boolean | Button: Zielzeit-Ladung                            |
| SOC\_ZielwertID       | Integer | Ziel-SOC für das Auto (%)                          |
| Zielzeit\_Uhr         | Integer | Zielzeit als Uhrzeit (Profil: \~UnixTimestampTime) |
| MinStartWatt          | Float   | Ladebeginn ab diesem Überschuss (W)                |
| MinStopWatt           | Float   | Laden aus bei weniger als (W)                      |
| PhasenSwitchWatt3     | Integer | Umschalten auf 3-phasig ab (W)                     |
| PhasenSwitchWatt1     | Integer | Umschalten auf 1-phasig unter (W)                  |
| SOC\_Limit            | Float   | Untergrenze SOC Hausspeicher (%)                   |
| Volt                  | Integer | Netzspannung pro Phase (z. B. 230 V)               |
| MinAmp                | Integer | Minimaler Ladestrom (A)                            |
| MaxAmp                | Integer | Maximaler Ladestrom (A)                            |


## Lizenz

MIT License – siehe `LICENSE.md`
