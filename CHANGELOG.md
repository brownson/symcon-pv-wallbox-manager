# Changelog

## 🧪 [0.7] – 24.06.2025 (Beta-Phase)

### Neue Funktionen

- Zielzeitladung PV-optimiert:
  - Neuer Button im WebFront aktiviert eine intelligente Zielzeitladung.
  - Während dieser aktiv ist, wird nur PV-Überschuss verwendet.
  - Automatisches Umschalten auf gezielte Ladung (volle Leistung oder dynamisch berechnet) ab konfigurierbarer Vorlaufzeit (Standard: 4 Stunden) vor Zielzeit.
- PV2Car-Modus:
  - Getrennte Aktivierung für PV2Car-Laden mit festem prozentualen Anteil des Überschusses.
- Gegenseitiger Ausschluss der Modi:
  - Nur ein Modus (Manuell, PV2Car, Zielzeitladung) kann gleichzeitig aktiv sein.
  - Aktivierung eines Modus deaktiviert automatisch die anderen.
- Automatische Deaktivierung der Modi:
  - Alle Modi (Manuell, PV2Car, Zielzeitladung) deaktivieren sich automatisch, wenn das Fahrzeug abgesteckt wird.
- Formular-Erweiterung:
  - Vorlaufzeit für die Zielzeitladung ist jetzt konfigurierbar.

### Verbesserungen
- Verbesserte Status-Logik beim Trennen des Fahrzeugs.
- Logging ergänzt für Modus-Umschaltungen und Fahrzeugtrennung.

## 🚀 [0.6] – 18.06.2025

### Neue Funktionen
- `ManuellVollladen`: Neuer Button zum Laden mit voller Leistung – unabhängig von PV-Zustand oder Netzbezug
- Automatische Deaktivierung des manuellen Modus, wenn das Fahrzeug abgesteckt wird
- Schutz: PV-Berechnung (`BerechnePVUeberschuss`) wird bei aktiviertem Volllade-Modus unterdrückt

### Verbesserungen
  - 🔌 Berechnung des PV-Überschusses berücksichtigt jetzt:
  - Netzeinspeisung nur bei positiven Werten
  - Batterieladung nur wenn positiv (nur Laden zählt)
  - Aktuelle Ladeleistung zur Wallbox wird aufgerechnet
  - Bei zu geringem Überschuss (unter Aktivierungsgrenze) wird die Wallbox zuverlässig deaktiviert (`SetLadeleistung(0)`)

## 🚗 [0.5] – Integration Fahrzeugdaten

- NEU: Unterstützung für Fahrzeugdaten wie aktueller SoC und Ziel-SoC
- Konfigurierbarer Schalter „Fahrzeugdaten berücksichtigen (UseCarSOC)“
- Fallback-Ziel-SoC nutzbar, falls keine Variable angegeben ist
- Dynamisches Verhalten: Nur wenn UseCarSOC aktiv, wird SOC-Logik berücksichtigt
- Optimierter Code für saubere Ladeentscheidung basierend auf Zielwert

## [0.4] – 2025-06-17
🚀 Hinzugefügt
- Fahrzeugstatusprüfung: Ladung wird nur gestartet, wenn ein Fahrzeug angeschlossen ist (Status 2 oder 4)
- Neue Option „Nur laden, wenn Fahrzeug verbunden ist“ in der Konfiguration (deaktivierbar)
- Umfangreiche Beschreibungen & Icons zu allen Eingabefeldern im `form.json`
- Modulstruktur vereinfacht: Unterstützung aktuell ausschließlich für GO-e Charger
- Fehlerbehandlung und Logging verbessert (z. B. Statusabfrage, Ladeleistung)

🛠️ Geändert
- Logik zur Statusauswertung (Status 1 und 3 führen jetzt zuverlässig zum Abbruch)
- Entfernt: `ReadPropertyString('WallboxTyp')` (nur GO-e aktiv)

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
