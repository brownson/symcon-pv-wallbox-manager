# ⚡ PVWallboxManager – Intelligente PV-Überschussladung für den GO-eCharger

Ein leistungsfähiges IP-Symcon Modul zur dynamischen Steuerung deiner GO-eCharger Wallbox auf Basis von PV-Überschuss – mit automatischer Phasenumschaltung, flexibler Ladelogik, voller Steuerung der Ladeleistung und intelligenter Zielzeitladung.

---

## 🔧 Unterstützte Wallboxen

Aktuell unterstützt dieses Modul **ausschließlich den GO-eCharger (V3 und V4)** in Kombination mit dem offiziellen IP-Symcon-Modul [`IPSCoyote/GO-eCharger`](https://github.com/IPSCoyote/GO-eCharger).

> 🎯 Ziel dieses Moduls ist es, den GO-eCharger **zu 100 % vollständig zu unterstützen** – inklusive dynamischer Ladeleistung, Phasenumschaltung, Modusumschaltung und PV-Optimierung.
>
> 🔄 Weitere Wallboxen (z. B. openWB, easee, Pulsar) sind möglich – **abhängig vom Interesse und Support aus der Community**.

---

## 🚀 Funktionen

- 🔋 **PV-Überschussgesteuertes Laden:** PV – Hausverbrauch – (nur positive) Batterie-Leistung, inkl. Wallbox-Eigenverbrauch.
- ⚙️ **Dynamische Ladeleistungsanpassung** mit konfigurierbarem Ampere-Bereich und Sicherheits-Puffer.
- 🔁 **Automatische Phasenumschaltung (1-/3-phasig):** Mit konfigurierbaren Schwellwerten und Umschaltzähler, kein hektisches Umschalten.
- 🧠 **Dynamischer Pufferfaktor:** Sorgt dafür, dass immer ein Sicherheitspuffer bleibt (Wirkungsgrad ≈80–93 %, je nach Überschuss).
- 📉 **Live-Berechnung des PV-Überschusses:** Alle 60 s (einstellbar) – Bilanz aus PV-Erzeugung, Hausverbrauch, Batterie und Wallbox.
- 🚗 **Fahrzeugstatusprüfung:** Laden nur wenn ein Fahrzeug verbunden ist (optional).
- ⏱️ **Intelligente Zielzeitladung (PV-optimiert):**
  - Tagsüber nur PV-Überschuss; spätestens X Stunden vor Zielzeit automatische Vollladung (PV+Netz).
  - Ziel-SoC, Zielzeit und Puffer individuell konfigurierbar.
- ☀️ **PV2Car-Modus:** Ein frei einstellbarer Prozentsatz des Überschusses wird ans Auto weitergegeben.
- 🔌 **Manueller Volllademodus:** Lädt mit maximaler Leistung, unabhängig von PV, auch aus Netz/Akku.
- 📊 **Status- und Visualisierungsvariablen:** PV-Überschuss (W), Modus-Status, Zielzeit, aktuelle Ladeleistung, etc.
- 🛑 **Sicherheitslogik:** Start/Stop-Schwellen (Watt) für stabile Überschuss-Erkennung.

## ⚡ So funktioniert die Berechnung

**Bilanzformel:**  
`PV-Überschuss = PV-Erzeugung + (Wallbox-Entnahme) - Hausverbrauch - (nur positive Batterie-Leistung)`

- Ist die Batterie im Entladebetrieb (negativ), zählt sie *nicht* zum PV-Überschuss.
- Im Modus **PV2Car** wird der eingestellte Prozentsatz vom Überschuss als Ladeleistung ans Fahrzeug gegeben.
- **Dynamischer Puffer**:  
  - <2000 W: 80 %  
  - <4000 W: 85 %  
  - <6000 W: 90 %  
  - >6000 W: 93 %
- **Start/Stopp:**  
  - Start: Überschuss >= `MinLadeWatt`
  - Stopp: Überschuss < `MinStopWatt`
  - Überschuss <0 W → Wallbox aus, Wert = 0.

**Phasenumschaltung:**  
- Umschalten auf 1-phasig, wenn Ladeleistung mehrfach unter Schwelle (`Phasen1Schwelle` + `Phasen1Limit`).
- Umschalten auf 3-phasig, wenn Ladeleistung mehrfach über Schwelle (`Phasen3Schwelle` + `Phasen3Limit`).
- Zähler werden automatisch zurückgesetzt, wenn Schwellen nicht dauerhaft erreicht.

**Zielzeitladung (PV-optimiert):**  
- Bis X Stunden vor Zielzeit: nur PV-Überschussladung.
- Im letzten Zeitfenster: Maximale Ladeleistung (PV+Netz/Akku) bis Ziel-SoC.

## 🧰 Voraussetzungen

- IP-Symcon Version 8.x (getestet)
- GO-eCharger V3 oder V4 mit lokal erreichbarer Instanz
- Installiertes Modul `GO-eCharger` (von IPSCoyote)
- PV-Erzeugung, Hausverbrauch und Batterieladung als Variablen verfügbar (in Watt)
- Aktivierter lokaler API-Zugriff im GO-eCharger (API1 + API2)

> ⚠️ **Wichtig:**  
> Im GO-eCharger müssen **API 1 und API 2 aktiviert** sein (unter Einstellungen > API-Zugriff), damit die Steuerung über das Modul funktioniert.

---


## 🔎 Wichtige Einstellungen

- **GO-eCharger Instanz**: Die Instanz-ID deiner Wallbox.
- **PV-Erzeugung / Hausverbrauch / Batterie**: Jeweils die aktuelle Leistung in Watt als Variable.
- **Start bei PV-Überschuss** (`MinLadeWatt`): Unterhalb dieses Werts bleibt die Wallbox aus.
- **Stoppen bei Defizit** (`MinStopWatt`): Sinkt der Überschuss unter diesen Wert, wird gestoppt.
- **Phasenanzahl**: 1 oder 3, abhängig von der Installation.
- **Phasenumschalt-Schwellen**: Grenzwerte und Hysterese für Umschaltung.
- **Dynamischer Puffer**: Reduziert die Ladeleistung automatisch (siehe oben).
- **Fahrzeugdaten**: Optionale SOC-/Zielwerte für Zielzeitladung.

---

## 📋 Beispielkonfiguration

| Einstellung               | Beispielwert    |
|--------------------------|-----------------|
| GOEChargerID             | 58186           |
| MinAmpere                | 6               |
| MaxAmpere                | 16              |
| MinLadeWatt              | 1400            |
| MinStopWatt              | -300            |
| Phasen                   | 3               |
| Phasen1Schwelle          | 1000            |
| Phasen3Schwelle          | 4200            |
| Dynamischer Puffer       | Aktiviert       |
| Zielzeit Vorlauf (h)     | 4               |

---

## 📦 Roadmap

- 🕓 Zeitbasierte Zielladung auf Ziel-SoC inkl. Ladeplanung (bereits Beta)
- 🔋 Ziel-SoC konfigurierbar
- 🚗 Fahrzeugstatus prüfen (nur laden wenn verbunden)
- ⏱️ Ladebeginn dynamisch rückrechnen
- 🧮 Lademodi: Manuell / PV2Car % / Zielzeit / Nur PV
- 🌐 Integration externer Fahrzeugdaten via MQTT
- 📊 Visualisierung & WebFront Widgets
- 🔧 Erweiterbarkeit für andere Hersteller (openWB, easee …)

---

## 💖 Unterstützung

Du möchtest die Weiterentwicklung unterstützen? Wir freuen uns über eine kleine Spende:

<table>
  <tr>
    <td align="center">
      <a href="https://www.paypal.com/donate/?business=PR9P7V7RMFHFQ&no_recurring=0&item_name=Spende+als+Dankesch%C3%B6n+f%C3%BCr+die+Modulentwicklung+Symcon&currency_code=EUR" target="_blank" rel="noopener noreferrer">
        <img src="imgs/paypal_logo.png" alt="Spenden mit PayPal" style="max-width: 300px;">
      </a>
    </td>
    <td align="center">
      <a href="https://www.paypal.com/donate/?business=PR9P7V7RMFHFQ&no_recurring=0&item_name=Spende+als+Dankesch%C3%B6n+f%C3%BCr+die+Modulentwicklung+Symcon&currency_code=EUR" target="_blank" rel="noopener noreferrer">
        <img src="imgs/paypal_qr.png" alt="QR-Code zur PayPal-Spende" style="max-width: 200px;">
      </a>
    </td>
  </tr>
</table>

> ☕ Vielen Dank für deine Unterstützung!  
> 📜 Dieses Modul bleibt selbstverständlich frei verfügbar und quelloffen.

---

## 📈 Logging & Analyse

Das Modul protokolliert automatisch relevante Entscheidungen:

- Start/Stop der Ladung und Phasenwechsel (inkl. Zählerstand)
- Effektive Ladeleistung und PV-Verfügbarkeit
- Moduswechsel (Manuell, PV2Car, Zielzeitladung)
- Fahrzeugtrennung und automatische Modus-Deaktivierung
- Fehlerbehandlung bei Variablen, Status und API-Kommunikation

---

## 🚧 Hinweise

- Dieses Modul wird aktiv weiterentwickelt.
- Derzeit nur mit GO-e Charger getestet, theoretisch aber modular erweiterbar (z. B. openWB etc.).
- Bei Phasenumschaltung ist zusätzliche Hardware (z. B. Umschaltrelais + Steuerung über Symcon-Variable) erforderlich.
- Die Zielzeitladung befindet sich aktuell in der Beta-Phase.
- Der „PV2Car“-Anteil steuert nur den Prozentsatz des Überschusses, nicht die absolute Ladeleistung.

---

## 🧪 Getestete Hardware

- GO-e Charger V4 (lokale API)
- GO-e Charger V3 (theoretisch kompatibel, derzeit nicht offiziell getestet)

---

## 👥 Mithelfen

- Feature-Idee? 👉 [Issue öffnen](https://github.com/Sol-IoTiv/symcon-pv-wallbox-manager/issues)
- Verbesserungsvorschlag?
- Unterstützung weiterer Wallboxen?

➡️ Du bist willkommen!

---

## 🕘 Changelog

Alle Änderungen findest du in der Datei:\
👉 [CHANGELOG.md](https://github.com/Sol-IoTiv/symcon-pv-wallbox-manager/blob/main/CHANGELOG.md)

---

## 📄 Lizenz

Dieses Projekt steht unter der MIT License:\
👉 [LICENSE.md](https://github.com/Sol-IoTiv/symcon-pv-wallbox-manager/blob/main/LICENSE.md)

---

© 2025 [Siegfried Pesendorfer](https://github.com/Sol-IoTiv) – Open Source für die Symcon-Community