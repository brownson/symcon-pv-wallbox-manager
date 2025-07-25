# ⚡ PVWallboxManager – Intelligente PV-Überschussladung für den GO-eCharger (ab v1.0b)

Ein leistungsfähiges IP-Symcon Modul zur dynamischen Steuerung deiner GO-eCharger Wallbox auf Basis von PV-Überschuss – mit automatischer Phasenumschaltung, flexibler Ladelogik, voller Steuerung der Ladeleistung und intelligenter Zielzeit- sowie Strompreis-Optimierung.

---

**Wichtiger Hinweis:**  
Ab Version 1.0b wird das [IPSCoyote/GO-eCharger Modul](https://github.com/IPSCoyote/GO-eCharger) **nicht mehr benötigt**!  
Die Kommunikation erfolgt nun direkt und nativ mit der lokalen GO-eCharger API (V3 & V4).

---

## 🔧 Unterstützte Wallboxen

Aktuell unterstützt dieses Modul **ausschließlich den GO-eCharger (V3 und V4)** in Kombination mit dem offiziellen IP-Symcon-Modul [`IPSCoyote/GO-eCharger`](https://github.com/IPSCoyote/GO-eCharger).

> 🎯 Ziel: 100 % Feature-Unterstützung für GO-eCharger – dynamische Ladeleistung, Phasenumschaltung, PV-Optimierung, Strompreis-Optimierung.
>
> 🔄 Weitere Wallboxen (openWB, easee, …) sind denkbar – abhängig von Community-Feedback.

---

### Hinweis zur Hausverbrauchs-Variable

> **In den Modul-Einstellungen bitte immer die Variable für den gesamten Hausverbrauch (inkl. Wallbox) eintragen.**
> Das Modul zieht die Wallbox-Leistung automatisch ab und berechnet den echten Überschuss intern.
>  
> **Nicht einen bereits „bereinigten“ Wert eintragen!**

---

## Funktionsweise und Lademodi

**Standard-Modus „PV-Überschuss laden“ (`PVonly`):**
- Der PV-Überschuss wird zuerst zur Ladung des Hausspeichers genutzt, bis dieser voll ist.
- Erst danach wird der verbleibende PV-Überschuss automatisch zum Laden des Autos verwendet.

**Manueller Modus „🔌 Vollladen aktiv“:**
- Das Auto lädt sofort mit maximal möglicher Leistung – unabhängig von PV-Überschuss, Speicherstand oder Uhrzeit.
- Es wird alles verwendet, was verfügbar ist: PV-Überschuss, Hausspeicher und (falls nötig) Strom aus dem Netz.

**Modus „🌞 PV-Anteil laden“:**
- Mit dem Schieberegler kann eingestellt werden, wie viel Prozent des aktuellen PV-Überschusses ins Auto fließen (z. B. 50 %).
- Beispiel: Bei 5.000 W Überschuss gehen 2.500 W ins Auto, der Rest steht Haus und Hausspeicher zur Verfügung.
- Der Hausspeicher wird in diesem Modus bevorzugt geladen, bis die eingestellte „Voll-Schwelle“ erreicht ist.

---

### Aktualisierungsintervalle im PVWallboxManager

- **Initial-Check-Intervall:**  
  Das Modul prüft in kurzen Abständen (standardmäßig alle 10 Sekunden), ob ein Fahrzeug an der Wallbox erkannt wird.
  Hier passieren keine Berechnugen vom PV-Überschuss usw...
  Erst wenn ein Fahrzeug angeschlossen ist, schaltet das Modul automatisch auf den normalen Aktualisierungsintervall um.

- **Normaler Aktualisierungsintervall:**  
  Während des laufenden Betriebs werden alle Werte (PV-Leistung, Hausverbrauch, Wallbox-Status, etc.) standardmäßig alle **30 Sekunden** aktualisiert.  
  Das Intervall kannst du in den Moduleinstellungen (Eigenschaften der Instanz) an deine Bedürfnisse anpassen.

> **Tipp:**  
> Ein kürzeres Intervall sorgt für schnellere Reaktion bei Wetterwechseln, erzeugt aber auch mehr Systemlast.  
> 30 - 60 Sekunden ist ein guter Mittelwert für die meisten Anwendungsfälle.

---

## Was ist neu in Version v1.1b?

- Eigene Icons für „PV-Überschuss (W)“ (☀️) und „PV-Überschuss (A)“ (⚡️) im WebFront.
- Die Ladestrom-Anzeige (A) zeigt jetzt **0 A**, solange kein Überschuss vorhanden ist, und springt bei PV-Überschuss direkt auf den minimalen Ladestrom.
- Hausverbrauch abzüglich Wallbox-Leistung kann nicht mehr negativ werden – Fehlerquellen ausgeräumt.
- Alle Visualisierungswerte werden ab sofort konsequent gerundet angezeigt (keine unschönen Nachkommastellen mehr).
- Viele weitere Verbesserungen bei Stabilität, Anzeige und Status-Info.

→ **Alle Änderungen und technischen Details findest du im [CHANGELOG.md](./CHANGELOG.md).**

---

## 🚀 Funktionen

- 🔋 **PV-Überschussgesteuertes Laden:** Bilanz aus PV-Erzeugung, Hausverbrauch (selbst berechnet, exkl. Wallboxleistung) und Batterie.
- ⚙️ **Dynamische Ladeleistungsanpassung:** Amperebereich voll konfigurierbar.
- 🔁 **Automatische & intelligente Phasenumschaltung (1-/2-/3-phasig):** Mit konfigurierbaren Schwellwerten und Umschaltzähler (Hysterese).  
  > Erkennung der tatsächlich benutzen Phasen – optimal für Fahrzeuge, die nur ein- oder zweiphasig laden (z. B. Renault ZOE, viele Plug-in-Hybride).
- 📉 **Live-Berechnung des PV-Überschusses:** Alle 60 s (oder nach Wunsch), Bilanz aus allen Quellen, Wallboxverbrauch korrekt integriert.
- 🚗 **Fahrzeugstatusprüfung:** Laden nur, wenn ein Fahrzeug verbunden ist (direkt per API erkannt).
- ☀️ **PV2Car-Modus:** Prozentsatz des PV-Überschusses wird ans Auto weitergegeben (Schieberegler).
- 🔌 **Manueller Volllademodus:** Lädt sofort mit maximaler Leistung – unabhängig von PV.
- 📊 **Status- und Visualisierungsvariablen:** PV-Überschuss, Modus-Status, Zielzeit, aktuelle Ladeleistung, Phasenstatus, SOC usw.
- 🖼️ **Vorbereitung Strompreis-Forecast-HTML-Box:** Moderne, vorbereitete Visualisierung für zukünftige Strompreisprognosen direkt im WebFront.
- 🛑 **Sicherheitslogik:** Start/Stop-Schwellen (Watt) und stabile Überschusserkennung per Hysteresezähler.
- 🏷️ **Einheiten- und Vorzeichen-Handling:** Watt/kW pro Variable, Invertierung für Bezug/Einspeisung, alles frei konfigurierbar.
- 🕹️ **Lademodi-Schalter:** Es ist immer nur ein Modus gleichzeitig aktivierbar (Manuell, PV2Car, Nur PV), automatische Deaktivierung aller Modi beim Abstecken des Fahrzeugs.

---

## ⚡ So funktioniert die Berechnung

### Bilanzformel

`PV-Überschuss = PV-Erzeugung – (Hausverbrauch - Wallboxleistung zum Fahrzeug) – Batterieladung`

- **PV-Erzeugung:** Gesamte aktuelle PV-Leistung (Watt oder kW, wählbar)
- **Hausverbrauch:** Automatisch berechnet aus Gesamtverbrauch MINUS Wallboxleistung (damit keine Doppelerfassung!)
- **Batterieladung:** Lade-/Entladeleistung deiner Hausbatterie (invertierbar)
- **Netzeinspeisung** (optional): Positive Werte = Einspeisung, negative Werte = Netzbezug (Invertierung möglich)
- **Wallbox-Ladeleistung:** Korrekt erfasst; NICHT doppelt im Verbrauch!
- **Flexible Einheitenwahl:** Für alle Energiewerte wählbar (Watt/kW); automatische Umrechnung
- **Invertierungsoption:** Für jede Variable individuell

> **Achtung:**  
> Der Hausverbrauch wird automatisch korrekt berechnet – KEIN manuelles Skript mehr nötig!

---

### Weitere Logik & Algorithmen

- **Start/Stop Hysterese:**  
  - Start: Überschuss ≥ `MinLadeWatt` – Wert muss mehrfach überschritten werden (konfigurierbare Hysterese).
  - Stop: Überschuss < `MinStopWatt` – Wert muss mehrfach unterschritten werden (konfigurierbar).
- **Intelligente Phasenermittlung:**  
  - Das Modul erkennt über die API, wie viele Phasen tatsächlich belegt/genutzt werden (1/2/3), und steuert die Phasenumschaltung sowie den Hausverbrauch entsprechend.
  - **Beispiel:** Einige Fahrzeuge (z. B. Renault ZOE, viele Plug-in-Hybride) können nur zweiphasig laden – dies wird automatisch berücksichtigt!
- **Phasenumschaltung:**  
  - Umschalten auf 1-phasig, wenn Ladeleistung mehrfach unter Schwelle (`Phasen1Schwelle`)
  - Umschalten auf 3-phasig, wenn Ladeleistung mehrfach über Schwelle (`Phasen3Schwelle`)
  - Beide Umschaltungen nutzen einen eigenen Zähler (kein hektisches Hin/Her-Schalten)

---

## 🧰 Voraussetzungen

- IP-Symcon Version 8.x oder neuer
- GO-eCharger V3/V4 mit lokal erreichbarer API (API1 + API2 aktiviert)
- PV-Erzeugung, Hausverbrauch, Batterie, Wallboxleistung als Float-Variablen verfügbar
- Optional: Strompreis-Modul für preisoptimiertes Laden

> ⚠️ **Wichtig:**  
> Im GO-eCharger müssen **API 1 und API 2 aktiviert** sein (unter Einstellungen > API-Zugriff).

---

## 🔎 Wichtige Einstellungen

- **GO-eCharger IP-Adresse**: Direkte Eingabe im Modul (keine externe Instanz oder Proxy nötig)
- **PV-Erzeugung / Hausverbrauch / Batterie / Netzeinspeisung**: Variablen und Einheiten (W/kW) frei zuordenbar; Invertierung wählbar
- **Start bei PV-Überschuss** (`MinLadeWatt`): Unterhalb bleibt die Wallbox aus
- **Stoppen bei Defizit** (`MinStopWatt`): Bei Unterschreitung wird gestoppt
- **Hysterese (Start/Stop):** Wie oft muss der Wert über-/unterschritten werden?
- **Phasenanzahl**: 1 oder 3-phasig, je nach Installation
- **Phasenumschalt-Schwellen**: Konfigurierbare Grenzwerte und Hysterese
- **Fahrzeugdaten (SOC, Ziel-SOC, Zielzeit):** Optional für künftige Features
- **Strompreis-Modul:** Optional, für preisoptimiertes Laden (künftig)

---

## 📋 Beispielkonfiguration

| Einstellung         | Beispielwert    |
|---------------------|-----------------|
| GO-e IP-Adresse     | 192.168.98.5    |
| MinAmpere           | 6               |
| MaxAmpere           | 16              |
| MinLadeWatt         | 1400            |
| MinStopWatt         | 1100            |
| Start-Hysterese     | 2               |
| Stop-Hysterese      | 2               |
| Phasen1Schwelle     | 3680            |
| Phasen3Schwelle     | 4140            |

---

## 🟢 Was ist NEU in v1.0b (2025-07)

~~**Das Modul benötigt NICHT mehr das IPSCoyote/GO-eCharger Modul**~~  
- **KEIN Drittmodul (IPSCoyote) mehr nötig – native API-Anbindung**  
- **Komplette Bilanzberechnung und Hausverbrauchslogik direkt im Modul**  
- **Exklusive Lademodi-Schaltung** (Manuell, PV2Car, Nur PV – nie mehrere gleichzeitig, autom. Reset bei Fahrzeugtrennung)
- **Live-Anzeige und Logging aller Status-, Diagnose- und Bilanzwerte**
- **Vorbereitung einer modernen Strompreis-Forecast-HTML-Box für zukünftige Preisoptimierung**
- **Intelligente Phasenermittlung:** Phasen werden dynamisch und automatisch anhand der echten Fahrzeugnutzung erkannt (z. B. 1/2/3-phasig)
- **Vereinfachtes Handling der Einheiten/Invertierungen**
- **Automatische Attributinitialisierung/Self-Healing**
- **Status- und Diagnosevariablen für WebFront**
- **Verbesserte Fehler- und Statusbehandlung**

---

## ❗ Was im Vergleich zum alten Skript aktuell (noch) NICHT enthalten ist (aber geplant):

> **Wird als nächstes integriert (siehe Roadmap und offene Punkte):**
>
> - **Dynamischer Puffer:**  
>   Der aus dem alten Skript bekannte dynamische Sicherheitspuffer ist in v1.0b bewusst NICHT enthalten. Die Ladeleistung entspricht immer dem tatsächlich errechneten Überschuss (ohne weiteren Sicherheitsabschlag).  
>   → Feedback hierzu ist ausdrücklich erwünscht!
>
> - **Intelligente Zielzeitladung (PV-optimiert)**
> - **Preisoptimiertes Laden (Beta)**
> - **Automatisierte Push-Benachrichtigungen** bei Moduswechsel/Fehler
> - **Externe Fahrzeugdaten (z. B. VW API/MQTT) vollintegriert**
> - **Ladefenster-Logging (pro Stunde, Preis, etc.)**
> - **Umschaltung auf Winterbetrieb / Anpassung der Modi nach Saison**
> - **Automatische Testladung zur Erkennung der Maximalleistung**
> - **Erweiterte WebFront/PWA-Interaktivität (RFID, Push, etc.)**
> - **Vollständige Auswertung und Einsatz der Strompreis-Forecast-HTML-Box für die Preissteuerung**
> - **Intelligente, erweiterte Phasenermittlung für alle Fahrzeugtypen und Sonderfälle (z. B. Sonderfall 2-phasiges Laden)**
>
> Alle oben genannten Funktionen stehen auf der Roadmap und werden nach Community-Wunsch priorisiert umgesetzt.

---

## 📦 Roadmap

### 🧪 Beta / In Vorbereitung
- 📊 Visualisierung & WebFront-Widgets
- 💶 Optimiertes Zusammenspiel mit Symcon-Strompreis-Modul (Awattar, Tibber …)
- 🛠️ Berücksichtigung der maximalen Fahrzeug-Ladeleistung bei Ladezeit- und Forecast-Berechnung.
- 📊 Geplantes Ladefenster-Logging: Für jede Stunde geplante Ladeleistung und Strompreis transparent im Log sichtbar.
- ⚡️ Maximale Fahrzeug-Ladeleistung (W)
- 📊 Awattar (und andere Preis-APIs) direkt integrieren
- 📊 Strompreis-Forecast-HTML-Box als Vorbereitung für künftige Preissteuerung


### 🔜 Geplant
- 📨 Integration externer Fahrzeugdaten (z. B. via MQTT)
- 📈 Erweiterte Statistiken und Auswertungen
- ❄️ Umschalten auf Winterbetrieb aktiv andere Standardlademodi, da im Winter weniger bis gar kein PV-Überschuss
- ⚠️ Minimale Leistung + PV Überschuss Modus wie bei EVCC
- ⚡️ Maximale Ladeleistung berücksichtigen (zb.: Bei leistungsgemessene Netzkosten)
- ⏰ Intelligente Zielzeitladung (PV-optimiert)
- 💶 Preisoptimiertes Laden (Beta)

### 😄 Idee, wenn mal so richtig faad ist…
- 🌍 Unterstützung für andere Wallboxen, falls Nachfrage wirklich riesig ist (aktuell Fokus: GO-e)
- 🔃 die verschiedenen Modi per RFID umschaltn
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
