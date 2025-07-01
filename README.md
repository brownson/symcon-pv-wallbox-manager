# ⚡ PVWallboxManager – Intelligente PV-Überschussladung für den GO-eCharger

Ein leistungsfähiges IP-Symcon Modul zur dynamischen Steuerung deiner GO-eCharger Wallbox auf Basis von PV-Überschuss – mit automatischer Phasenumschaltung, flexibler Ladelogik, voller Steuerung der Ladeleistung und intelligenter Zielzeit- sowie Strompreis-Optimierung.

---

## 🔧 Unterstützte Wallboxen

Aktuell unterstützt dieses Modul **ausschließlich den GO-eCharger (V3 und V4)** in Kombination mit dem offiziellen IP-Symcon-Modul [`IPSCoyote/GO-eCharger`](https://github.com/IPSCoyote/GO-eCharger).

> 🎯 Ziel: 100 % Feature-Unterstützung für GO-eCharger – dynamische Ladeleistung, Phasenumschaltung, PV-Optimierung, Strompreis-Optimierung.
>
> 🔄 Weitere Wallboxen (openWB, easee, …) sind denkbar – abhängig von Community-Feedback.

---

## 📖 Dokumentation

Eine **ausführliche Schritt-für-Schritt-Anleitung, FAQ und viele Tipps** findest du im  
➡️ [Benutzerhandbuch (MANUAL.md)](./MANUAL.md)

---

## 🚀 Funktionen

- 🔋 **PV-Überschussgesteuertes Laden:** PV – Hausverbrauch – (nur positive) Batterie-Leistung, inkl. Wallbox-Eigenverbrauch.
- ⚙️ **Dynamische Ladeleistungsanpassung** mit konfigurierbarem Ampere-Bereich und Sicherheits-Puffer.
- 🔁 **Automatische Phasenumschaltung (1-/3-phasig):** Mit konfigurierbaren Schwellwerten und Umschaltzähler, kein hektisches Umschalten.
- 🧠 **Dynamischer Pufferfaktor:** Sorgt dafür, dass immer ein Sicherheitspuffer bleibt (Wirkungsgrad ≈80–93 %, je nach Überschuss). Kein Puffer bei Netzladen
- 📉 **Live-Berechnung des PV-Überschusses:** Alle 60 s (einstellbar) – Bilanz aus PV-Erzeugung, Hausverbrauch, Batterie und Wallbox.
- 🚗 **Fahrzeugstatusprüfung:** Laden nur, wenn ein Fahrzeug verbunden ist (optional).
- ⏱️ **Intelligente Zielzeitladung (PV-optimiert):**
  - Tagsüber nur PV-Überschuss; spätestens X Stunden vor Zielzeit automatische Vollladung (PV+Netz).
  - Ziel-SoC, Zielzeit und Puffer individuell konfigurierbar.
- ☀️ **PV2Car-Modus:** Ein frei einstellbarer Prozentsatz des Überschusses wird ans Auto weitergegeben.
- 🔌 **Manueller Volllademodus:** Lädt mit maximaler Leistung, unabhängig von PV, auch aus Netz/Akku.
- 💶 **Preisoptimiertes Laden (Beta):** Integriert mit dem offiziellen [Symcon Strompreis-Modul](https://github.com/symcon/Strompreis) (z. B. Awattar, Tibber): Automatische Ladezeitplanung nach Preisvorhersage (Schalter, Zeitfenster, Schwellen).
- 📊 **Status- und Visualisierungsvariablen:** PV-Überschuss (W), Modus-Status, Zielzeit, aktuelle Ladeleistung, etc.
- 🛑 **Sicherheitslogik:** Start/Stop-Schwellen (Watt) für stabile Überschuss-Erkennung.
- 🏷️ **Einheiten- und Vorzeichen-Handling:** Watt/kW wählbar pro Variable, Invertierung für Bezug/Einspeisung.

---

## ⚡ So funktioniert die Berechnung

### Bilanzformel

`PV-Überschuss = PV-Erzeugung – Hausverbrauch – Batterieladung`
- **PV-Erzeugung:** Gesamte aktuelle PV-Leistung (Watt oder kW, frei konfigurierbar)
- **Hausverbrauch:** Haushaltsverbrauch **ohne** Ladeleistung der Wallbox (Watt oder kW)
- **Batterieladung:** Aktuelle Lade-/Entladeleistung der Hausbatterie
  - *Positiv*: Batterie wird geladen (zieht Energie, mindert Überschuss)
  - *Negativ*: Batterie entlädt (liefert Energie, erhöht Überschuss)
  - *Invertierbar*: Falls deine Batterie-Variable andersherum zählt (z. B. -1000 W = Entladung), kannst du dies in den Einstellungen korrigieren!
- **Netzeinspeisung** (optional): Positive Werte = Einspeisung, negative Werte = Netzbezug (auch invertierbar).
- **Wallbox-Ladeleistung:** Wird zur Visualisierung und für PV2Car herangezogen, aber nicht automatisch doppelt gezählt.

**Flexible Einheitenwahl:**  
Für PV, Hausverbrauch, Batterie, Netzeinspeisung kann Watt (W) oder Kilowatt (kW) eingestellt werden. Die Umrechnung erfolgt automatisch.

**Invertierungsoption:**  
Für jede Variable separat aktivierbar, falls dein Messwert andersherum zählt.

> **Achtung:**  
> Der Hausverbrauch muss **ohne** die aktuelle Wallbox-Ladeleistung berechnet werden! Sonst wird der Überschuss falsch berechnet.

---

### Weitere Logik & Algorithmen

- **Dynamischer Puffer**:  
  Überschuss = (PV – Haus – Batterie) × Puffer (je nach Höhe, siehe Doku/Manual).
  Kein Puffer bei Netzladen. Der dynamische Puffer wird nur beim PV-Überschussladen angewendet!
  Bei Netzladen (z. B. Zielzeit- oder Strompreismodul) wird immer die volle Leistung genutzt – ohne Abzug oder Sicherheitsreserve.

- **Start/Stop Hysterese:**  
  - Start: Überschuss ≥ `MinLadeWatt` – Hysterese: Wert muss mehrfach überschritten werden.
  - Stop: Überschuss < `MinStopWatt` – Hysterese: Wert muss mehrfach unterschritten werden.

- **Phasenumschaltung:**  
  - Umschalten auf 1-phasig, wenn Ladeleistung mehrfach unter Schwelle (`Phasen1Schwelle` + `Phasen1Limit`).
  - Umschalten auf 3-phasig, wenn Ladeleistung mehrfach über Schwelle (`Phasen3Schwelle` + `Phasen3Limit`).

- **Zielzeitladung:**  
  - Bis X Stunden vor Zielzeit: nur PV-Überschuss.
  - Im letzten Zeitfenster: Maximale Ladeleistung (PV+Netz/Akku) bis Ziel-SoC.

- **Preisoptimiertes Laden:**  
  - Wenn Strompreis-Modul aktiviert: Automatisches Aktivieren/Deaktivieren des Ladevorgangs nach günstigsten Preiszeiten möglich (Beta).

---

## 🧰 Voraussetzungen

- IP-Symcon Version 8.x (getestet)
- GO-eCharger V3 oder V4 mit lokal erreichbarer Instanz
- Installiertes Modul `GO-eCharger` (von IPSCoyote)
- PV-Erzeugung, Hausverbrauch und Batterieladung als Variablen verfügbar
- Einheiten und Vorzeichen korrekt konfiguriert!
- Aktivierter lokaler API-Zugriff im GO-eCharger (API1 + API2)
- Optional: Modul "Strompreis" für preisoptimiertes Laden

> ⚠️ **Wichtig:**  
> Im GO-eCharger müssen **API 1 und API 2 aktiviert** sein (unter Einstellungen > API-Zugriff).

---

## 🔎 Wichtige Einstellungen

- **GO-eCharger Instanz**: Die Instanz-ID deiner Wallbox.
- **PV-Erzeugung / Hausverbrauch / Batterie / Netzeinspeisung**: Jeweils Variable und Einheit (W oder kW) auswählen, ggf. Invertierung aktivieren.
- **Start bei PV-Überschuss** (`MinLadeWatt`): Unterhalb dieses Werts bleibt die Wallbox aus.
- **Stoppen bei Defizit** (`MinStopWatt`): Sinkt der Überschuss unter diesen Wert, wird gestoppt.
- **Hysterese (Start/Stop):** Wie oft muss der Wert über-/unterschritten werden, bevor umgeschaltet wird?
- **Phasenanzahl**: 1 oder 3, abhängig von der Installation.
- **Phasenumschalt-Schwellen**: Grenzwerte und Hysterese für Umschaltung.
- **Dynamischer Puffer**: Reduziert die Ladeleistung automatisch.
- **Fahrzeugdaten**: Optionale SOC-/Zielwerte für Zielzeitladung.
- **Strompreis-Modul**: Aktivierung und Konfiguration für preisoptimiertes Laden.

> **Float-Variable für PV-Logik:**  
> Die Ladeautomatik benötigt eine korrekt zugeordnete Float-Variable für den aktuellen PV-Überschuss!  
> Achtung: Hausverbrauch **ohne** Wallbox-Leistung!

> **Tipp:** Bei Problemen hilft der Status „Aktueller Lademodus“ im WebFront.

---

## 📋 Beispielkonfiguration

| Einstellung               | Beispielwert    |
|--------------------------|-----------------|
| GOEChargerID             | 58186           |
| MinAmpere                | 6               |
| MaxAmpere                | 16              |
| MinLadeWatt              | 1400            |
| MinStopWatt              | -300            |
| Start-Hysterese          | 2               |
| Stop-Hysterese           | 2               |
| Phasen                   | 3               |
| Phasen1Schwelle          | 1000            |
| Phasen3Schwelle          | 4200            |
| Dynamischer Puffer       | Aktiviert       |
| Zielzeit Vorlauf (h)     | 4               |
| Strompreis-Modul         | Aktiviert       |

---

## 📦 Roadmap

### ✅ Integriert
- 🛡️ Dynamischer Sicherheits-Puffer für Ladeleistung
- ♻️ Hysterese & automatische Phasenumschaltung
- 🕓 Zeitbasierte Zielladung inkl. Ladeplanung
- 💶 Preisoptimiertes Laden (Strompreis-Modul, Beta)
- 🧮 Lademodi: Manuell / PV2Car % / Zielzeit / Nur PV
- 🎯 Ziel-SoC konfigurierbar
- 🚗 Fahrzeugstatus-Prüfung (nur laden wenn verbunden)
- 🔋 PV-Überschussberechnung ohne Hausbatterie
- 🛑 Deaktivieren-Button (Modul-Aktiv-Schalter)
- 🔄 Invertierungs-Schalter & Einheitenwahl (W/kW) für alle Energiequellen
- 🕵️‍♂️ Diagnose/Info, warum kein Laden erfolgt

### 🧪 Beta / In Vorbereitung
- 📊 Visualisierung & WebFront-Widgets
- 💶 Optimiertes Zusammenspiel mit Symcon-Strompreis-Modul (Awattar, Tibber …)
- 🛠️ Berücksichtigung der maximalen Fahrzeug-Ladeleistung bei Ladezeit- und Forecast-Berechnung.
- 📊 Geplantes Ladefenster-Logging: Für jede Stunde geplante Ladeleistung und Strompreis transparent im Log sichtbar.
- ⚡️ Maximale Fahrzeug-Ladeleistung (W)
- ℹ️ Beim Netzladen keinen Dynamischen Puffer berrechnen. Ist nur beim PV-Überschussladen relevant
- 🏠 Hausverbrauch im Modul selbst berechnen (gesamter Hausverbrauch - Wallboxleistung zum Fahrzeug) = Hausverbrauch
- 📊 Awattar (und andere Preis-APIs) direkt integrieren
- ❌ „Nur laden, wenn Fahrzeug verbunden“ – Berechnung komplett skippen
- 🔃 Beim Mode Wechsel zu Fahrzeug verbunden soll auch initial das Modul durchlaufen


### 🔜 Geplant
- 📨 Integration externer Fahrzeugdaten (z. B. via MQTT)
- 📈 Erweiterte Statistiken und Auswertungen
- ❄️ Umschalten auf Winterbetrieb aktiv andere Standardlademodi, da im Winter weniger bis gar kein PV-Überschuss

---

### 😄 Idee, wenn mal so richtig faad ist…
- 🌍 Unterstützung für andere Wallboxen, falls Nachfrage wirklich riesig ist (aktuell Fokus: GO-e)
- 🔃 die versiedenen Modi per RFID umschaltn
- 📲 Interaktive Push-Nachricht: Beim Fahrzeug-Anstecken Modusauswahl (Vollladen, PV2Car, Zielzeit, Strompreis) per Smartphone-Button.
- ⚡️ Automatische Testladung zur Erkennung der maximalen Fahrzeug-Ladeleistung (Auto-Detection-Feature).

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
- Moduswechsel (Manuell, PV2Car, Zielzeitladung, Strompreis)
- Fahrzeugtrennung und automatische Modus-Deaktivierung
- Fehlerbehandlung bei Variablen, Status und API-Kommunikation

---

## 🚧 Hinweise

- Dieses Modul wird aktiv weiterentwickelt.
- Derzeit nur mit GO-e Charger getestet, theoretisch aber modular erweiterbar (z. B. openWB etc.).
- Bei Phasenumschaltung ist zusätzliche Hardware (z. B. Umschaltrelais + Steuerung über Symcon-Variable) erforderlich.
- Die Zielzeitladung befindet sich aktuell in der Beta-Phase.
- Der „PV2Car“-Anteil steuert nur den Prozentsatz des Überschusses, nicht die absolute Ladeleistung.
- Preisoptimiertes Laden über das Symcon-Strompreis-Modul ist noch Beta.

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
