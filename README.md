# PVWallboxManager (Symcon-Modul)

Dieses Modul für IP-Symcon ermöglicht die intelligente Steuerung einer go-e Charger Wallbox (getestet mit **Hardware V4**, vermutlich auch kompatibel mit **V3**) auf Basis des aktuellen PV-Überschusses. Ziel ist ein möglichst netzautarkes Laden mit optionaler Phasenumschaltung.

---

## 💖 Unterstützung

Dieses Modul wird als Hobbyprojekt entwickelt und kostenlos zur Verfügung gestellt.  
Wenn du die Weiterentwicklung unterstützen möchtest, kannst du das gerne tun:

[![Spenden mit PayPal](assets/paypal_logo.png)](https://www.paypal.com/donate/?business=PR9P7V7RMFHFQ&no_recurring=0&item_name=Spende+als+Dankesch%C3%B6n+f%C3%BCr+die+Modulentwicklung+Symcon&currency_code=EUR) ![QR-Code zur PayPal-Spende](assets/paypal_qr.png)


> ☕ Vielen Dank für deine Unterstützung!  
> 📜 Dieses Modul bleibt selbstverständlich frei verfügbar und quelloffen.

---

## 🔧 Voraussetzungen

- IP-Symcon Version 8.x oder neuer
- go-e Charger (Modell V4, getestet) mit aktivem Netzwerkzugriff
- PV-Leistungsdaten als IP-Symcon-Variablen verfügbar
- Optional: Phasenumschaltung über ein externes Relais (Boolean-Variable steuerbar)

---

## 📦 Funktionen

### Version 0.1
- Berechnung des aktuellen PV-Überschusses:  
  `PV-Erzeugung – Hausverbrauch – Batterie`
- Steuerung der Ladeleistung je nach Überschuss
- Aktivierung/Deaktivierung des go-e Lade-Modus (immer laden / nicht laden)
- Nur bei signifikanter Änderung (> 50 W) wird die Ladeleistung neu gesetzt
- Konfigurierbarer Timer (15–600 Sekunden)

### Version 0.2
- Automatische Umschaltung zwischen 1-phasigem und 3-phasigem Laden
- Konfigurierbare Schwellenwerte (`Phasen1Schwelle`, `Phasen3Schwelle`)
- Zählerbasierte Hysterese: Umschaltung erst nach mehrfacher Bestätigung (z. B. 3x unter 1000 W)
- Logging für:
  - PV-Überschuss
  - Phasenumschalt-Zähler
  - Umschaltaktionen
  - Ladeleistung und Wallbox-Modus

### Version 0.3
- Dynamische Sicherheits-Pufferlogik:  
  Reduktion des berechneten PV-Überschusses um 7–20 % (je nach Gesamtleistung), um kurzfristige Schwankungen abzufangen
- Neuer Konfigurationsschalter `DynamischerPufferAktiv` (default: aktiviert)
- Konfigurierbar direkt im Instanzformular (form.json mit Beschreibung)

---

## ⚙️ Konfiguration

Die Instanzkonfiguration erfolgt über folgende Parameter:

| Name | Beschreibung |
|------|--------------|
| PVErzeugungID | Variable mit aktueller PV-Leistung |
| HausverbrauchID | Variable mit aktuellem Hausverbrauch |
| BatterieladungID | Variable mit aktuellem Lade-/Entladewert der Batterie |
| GOEChargerID | Instanz-ID des go-e Chargers |
| MinAmpere / MaxAmpere | Ladebereich in Ampere |
| MinLadeWatt | Mindestüberschuss, ab dem Laden erlaubt ist |
| MinStopWatt | Grenze, bei der das Laden gestoppt wird |
| Phasen | Aktuell verwendete Phasenanzahl (1 oder 3) |
| PhasenUmschaltID | Boolean-Variable zur Umschaltung der Ladephasen |
| Phasen1/3Schwelle | Leistungsgrenzen für Umschaltung |
| Phasen1/3Limit | Anzahl aufeinanderfolgender Schwellen-Unterschreitungen/Überschreitungen vor Umschaltung |
| DynamischerPufferAktiv | Aktiviert/Deaktiviert Sicherheitsabschlag bei schwankender PV-Leistung |

---

## 📈 Logging & Analyse

Das Modul protokolliert automatisch relevante Entscheidungen:

- Start/Stop der Ladung
- Phasenwechsel (inkl. Zählerstand)
- Effektive Ladeleistung und PV-Verfügbarkeit

---

## 🚧 Hinweise

- Dieses Modul wird aktiv weiterentwickelt
- Derzeit nur mit go-e Charger getestet, theoretisch aber modular erweiterbar (z. B. openWB etc.)
- Bei Phasenumschaltung ist zusätzliche Hardware (z. B. Umschaltrelais + Steuerung über Symcon-Variable) erforderlich

---

## 🧪 Getestete Hardware

- go-e Charger Homefix V4 (per lokaler API)
- go-e Charger V3: möglicherweise kompatibel, aber nicht verifiziert

---

## 🛠️ Mitwirken

Feature-Ideen, Fehlerberichte und Pull-Requests sind willkommen!  
👉 [GitHub Repository öffnen](https://github.com/pesensie/symcon-pv-wallbox-manager)

---

## 🕘 Changelog
Alle Änderungen findest du in der Datei:
👉 [CHANGELOG.md](https://github.com/pesensie/symcon-pv-wallbox-manager/blob/main/CHANGELOG.md)

---

## 🗺️ Roadmap
Folgende Funktionen sind für zukünftige Versionen geplant:

🕓 Zeitbasierte Zielladung
Ladeplanung z. B. bis 06:00 Uhr auf einen gewünschten SoC-Wert

🔋 Ziel-SoC aus Variable konfigurierbar
Benutzer kann über das WebFront den gewünschten Ladezielwert (in %) festlegen

🚗 Fahrzeugstatus-Erkennung
Ladevorgang nur starten, wenn das Fahrzeug als „verbunden“ erkannt wird

⏱️ Rückrechnung der Ladezeit
Automatische Startzeitberechnung auf Basis von Ziel-SoC, aktuellem SoC und Ladeleistung

🧮 Flexible Lademodi
Umschaltung zwischen manueller Steuerung, PV2Car (%), Uhrzeit-Ziel oder reiner PV-Überschussladung

🌐 Integration externer Fahrzeugdaten
Anbindung über CarConnectivity-MQTT (z. B. für VW ID.3, Skoda, Cupra etc.)

📊 Visualisierung im WebFront
Anzeige des aktuellen Phasenstatus, Ladeleistung, SOC und Lademodus mit Icons

🔧 Unterstützung weiterer Wallboxen
Modulare Erweiterung geplant (z. B. für openWB, easee, …)

---

## 📄 Lizenz
Dieses Projekt steht unter der MIT License:
👉 [GLICENSE.md](https://github.com/pesensie/symcon-pv-wallbox-manager/blob/main/LICENSE.md)

