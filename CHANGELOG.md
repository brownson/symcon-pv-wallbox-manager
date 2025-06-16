# Changelog

## [v0.3] – 2025-06-17

### ✨ Hinzugefügt
- Dynamische Sicherheits-Pufferlogik für PV-Überschuss: Je nach verfügbarem Überschuss werden 7–20 % abgezogen, um kurzfristige Einbrüche abzufedern.
- Neuer Konfigurationsschalter `DynamischerPufferAktiv` (Standard: aktiv), um diese Funktion zu aktivieren/deaktivieren.
- Konfigurierbare Checkbox in der `form.json`, mit Beschreibung zur Wirkung des Puffers im Instanzformular.

### 🔁 Geändert
- Ladeleistungsberechnung berücksichtigt nun optional den Puffer – wirkt sich direkt auf Phasenumschaltung und Ladeentscheidungen aus.


## [v0.2] – 2025-06-16

### ✨ Hinzugefügt
- Automatische Umschaltung zwischen 1-phasigem und 3-phasigem Laden basierend auf PV-Überschuss.
- Konfigurierbare Hysterese mit Schwellenwerten (`Phasen1Schwelle`, `Phasen3Schwelle`) und Zählerlimits (`Phasen1Limit`, `Phasen3Limit`).
- Vermeidung unnötiger Umschaltungen durch intelligente Zählerlogik mit Reset bei Zwischenwerten.
- Ausführliches Logging für:
  - PV-Überschuss und berechnete Ladeleistung
  - Phasenumschalt-Zählerstände
  - Ausgelöste Phasenumschaltungen
  - Ladeleistungsänderungen und Moduswechsel des go-e Chargers

### 🛠️ Geändert
- Ladeleistung wird nur gesetzt, wenn sich der neue Wert um mehr als 50 W vom aktuellen unterscheidet.
- Der go-e Modus (Laden/Nicht laden) wird nur umgeschaltet, wenn sich der Zustand wirklich ändert.


## [v0.1] – 2025-06-16

### ✅ Grundfunktionen:
- Berechnung des PV-Überschusses: `PV-Erzeugung – Hausverbrauch – Batterieladung`
- Unterstützung für Hausbatterien (positiv = Laden, negativ = Entladen)
- Visualisierung des Überschusses als IP-Symcon Variable `PV_Ueberschuss`
- Timer zur zyklischen Ausführung (konfigurierbar 15–600 s)

### ⚙️ Dynamische Ladeleistungsberechnung:
- Ampere-Berechnung basierend auf konfigurierbarer Phasenanzahl und 230 V
- Konfigurierbarer Bereich für min. und max. Ampere (z. B. 6–16 A)
- Ladeleistung wird nur gesetzt, wenn sie sich um mehr als ±50 W ändert

### 🔌 go-e Charger Integration (via IPSCoyote):
- Auswahl der go-e Instanz im Modul-Konfigurator
- Verwendung von `GOeCharger_setMode` und `GOeCharger_SetCurrentChargingWatt`
- Verwendeter Ident für Modus: `accessStateV2`
- Moduswechsel nur bei tatsächlicher Änderung
- **NEU:** Logausgabe bei unveränderter Moduslage („🟡 Modus bereits X – keine Umschaltung notwendig“)
- **NEU:** Logausgabe bei unveränderter Ladeleistung („🟡 Ladeleistung unverändert – keine Änderung notwendig“)

### 🔍 Logging und Verhalten:
- Umfangreiche Logmeldungen mit Symbolen zur Nachvollziehbarkeit
- Float-Toleranzfilter (z. B. 2.273736754E-13 W → 0)
- Negative PV-Überschüsse führen zur Deaktivierung der Wallbox
- Schwellwerte für Start (`MinLadeWatt`) und Stopp (`MinStopWatt`) frei konfigurierbar

### 🧱 Technisches:
- Properties vollständig über `form.json` konfigurierbar
- Automatische Erkennung der Ziel-Instanz und verwendeter Variablen
- Optimierte `SetLadeleistung()`-Funktion mit robuster Ident-Erkennung
