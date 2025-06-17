# ⚡ PVWallboxManager – Intelligente PV-Überschussladung für den GO-eCharger

Ein leistungsfähiges IP-Symcon Modul zur dynamischen Steuerung deiner GO-eCharger Wallbox auf Basis von PV-Überschuss – mit automatischer Phasenumschaltung, flexibler Ladelogik und voller Steuerung der Ladeleistung.

---

## 🔧 Unterstützte Wallboxen

Aktuell unterstützt dieses Modul **ausschließlich den GO-eCharger (V3 und V4)** in Kombination mit dem offiziellen IP-Symcon-Modul [`IPSCoyote/GO-eCharger`](https://github.com/IPSCoyote/GO-eCharger).

> 🎯 Ziel dieses Moduls ist es, den GO-eCharger **zu 100 % vollständig zu unterstützen** – inklusive dynamischer Ladeleistung, Phasenumschaltung, Modusumschaltung und PV-Optimierung.
>
> 🔄 Weitere Wallboxen (z. B. openWB, easee, Pulsar) sind möglich – **abhängig vom Interesse und Support aus der Community**.

---

## 🚀 Funktionen

- 🔋 **PV-Überschussgesteuertes Laden** (PV – Verbrauch – Batterie)
- ⚙️ **Dynamische Ladeleistungsanpassung** mit einstellbarem Ampere-Bereich
- 🔁 **Automatische Phasenumschaltung (1-/3-phasig)** mit Hysterese
- 🧠 **Dynamischer Pufferfaktor** für sichere Leistungsregelung
- 📉 **Live-Berechnung des PV-Überschusses**
- 🧪 Optional: Fahrzeug-SoC, Uhrzeit-Zielmodus, PV2Car (%), MQTT-Integration

---

## 🧰 Voraussetzungen

- IP-Symcon Version 8.x (getestet)
- GO-eCharger V3 oder V4 mit lokal erreichbarer Instanz
- Installiertes Modul `GO-eCharger` (von IPSCoyote)
- PV-Erzeugung, Hausverbrauch und Batterieladung als Variablen verfügbar (in Watt)

> ⚠️ **Wichtig:**  
> Im GO-eCharger müssen **API 1 und API 2 aktiviert** sein (unter Einstellungen > API-Zugriff), damit die Steuerung über das Modul funktioniert.

---

## 🛠️ Installation

1. Modul-URL im IP-Symcon hinzufügen:
   ```
   https://github.com/pesensie/symcon-pv-wallbox-manager
   ```

2. Instanz „PVWallboxManager“ anlegen

3. Konfigurationsfelder im WebFront ausfüllen:
   - GO-e Instanz auswählen
   - Energiequellen (PV, Hausverbrauch, Batterie)
   - Ladegrenzen definieren (z. B. 1400 W Start / -300 W Stop)
   - Min/Max Ampere, Phasenanzahl, Pufferlogik

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

---

## 📦 Roadmap

- 🕓 Zeitbasierte Zielladung (bis Uhrzeit auf Ziel-SoC)
- 🔋 Ziel-SoC konfigurierbar
- 🚗 Fahrzeugstatus prüfen (nur laden wenn verbunden)
- ⏱️ Ladebeginn dynamisch rückrechnen
- 🧮 Lademodi: Manuell / PV2Car % / Uhrzeit / Nur PV
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

- Start/Stop der Ladung
- Phasenwechsel (inkl. Zählerstand)
- Effektive Ladeleistung und PV-Verfügbarkeit

---

## 🚧 Hinweise

- Dieses Modul wird aktiv weiterentwickelt
- Derzeit nur mit GO-e Charger getestet, theoretisch aber modular erweiterbar (z. B. openWB etc.)
- Bei Phasenumschaltung ist zusätzliche Hardware (z. B. Umschaltrelais + Steuerung über Symcon-Variable) erforderlich

---

## 🧪 Getestete Hardware

- GO-e Charger Homefix V4 (lokale API)
- GO-e Charger Homefix V3 (theoretisch kompatibel, derzeit nicht offiziell getestet)

---

## 👥 Mithelfen

- Feature-Idee? 👉 [Issue öffnen](https://github.com/pesensie/symcon-pv-wallbox-manager/issues)
- Verbesserungsvorschlag?  
- Unterstützung weiterer Wallboxen?

➡️ Du bist willkommen!

---

## 🕘 Changelog

Alle Änderungen findest du in der Datei:  
👉 [CHANGELOG.md](https://github.com/pesensie/symcon-pv-wallbox-manager/blob/main/CHANGELOG.md)

---

## 📄 Lizenz

Dieses Projekt steht unter der MIT License:  
👉 [LICENSE.md](https://github.com/pesensie/symcon-pv-wallbox-manager/blob/main/LICENSE.md)

---

© 2025 [Siegfried Pesendorfer](https://github.com/pesensie) – Open Source für die Symcon-Community
