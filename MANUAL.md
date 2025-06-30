# 📖 Benutzerhandbuch (MANUAL.md) – PVWallboxManager

## Inhaltsverzeichnis

1. [Was ist der PVWallboxManager?](#-was-ist-der-pvwallboxmanager)
2. [Die wichtigsten Funktionen](#-die-wichtigsten-funktionen)
3. [Einrichtung & Inbetriebnahme](#-einrichtung--inbetriebnahme)
4. [Typische Anwendungsbeispiele](#-typische-anwendungsbeispiele)
5. [Tipps & Best Practices](#-tipps--best-practices)
6. [Modulsteuerung und Debug-Logging](#-modulsteuerung-und-debug-logging)
7. [Fehlersuche & FAQ](#-fehlersuche--faq)
8. [Sicherheitshinweis](#-sicherheitshinweis)
9. [Roadmap & Mitmachen](#-roadmap--mitmachen)
10. [Dank & Credits](#-dank--credits)
11. [Versionshistorie (Auszug)](#-versionshistorie-auszug)
12. [Feedback & Support](#-feedback--support)

---

## ⚡ Was ist der PVWallboxManager?

Der **PVWallboxManager** ist ein intelligentes IP-Symcon-Modul, mit dem du deine GO-eCharger Wallbox optimal mit deiner Photovoltaik-Anlage (PV) betreibst. Ziel: So viel wie möglich mit eigenem PV-Strom laden, teuren Netzbezug vermeiden, und dein Auto genau dann laden, wenn es für dich am sinnvollsten ist.  
Das Modul steuert vollautomatisch Ladeleistung, Phasenumschaltung (1-/3-phasig), berücksichtigt Hausverbrauch, Batteriespeicher, Strompreis & Zielzeiten – und lässt sich flexibel auf deine Bedürfnisse anpassen.

---

## 🚀 Die wichtigsten Funktionen

- **PV-Überschussladen:** Die Wallbox lädt nur dann, wenn genug Sonnenstrom übrig ist – auf Wunsch vollautomatisch.
- **Dynamische Ladeleistung:** Das Auto wird mit genau so viel Strom geladen, wie gerade als PV-Überschuss zur Verfügung steht.
- **Automatische Phasenumschaltung:** Abhängig vom Überschuss schaltet das System selbständig zwischen 1-phasigem und 3-phasigem Laden um – für maximale Effizienz und geringe Netzbelastung.
- **Lademodi:** Verschiedene Betriebsarten stehen zur Wahl:  
  - **Manueller Modus:** Immer volle Power, unabhängig vom Überschuss.
  - **PV2Car (prozentual):** Nur ein Anteil des PV-Überschusses wird geladen, einstellbar per Schieberegler.
  - **Zielzeit-Ladung:** Das Auto ist bis zu einer bestimmten Uhrzeit garantiert geladen (z. B. bis 06:00 Uhr morgens), PV-optimiert oder bei Bedarf auch aus dem Netz.
  - **Nur-PV:** Es wird ausschließlich mit Überschuss geladen, sonst nicht.
- **Intelligente Hysterese:** Die Umschaltung der Phasen passiert erst, wenn die Bedingungen mehrfach erfüllt sind – für mehr Stabilität.
- **Kompatibilität:** Volle Unterstützung für GO-eCharger V3/V4 in Kombination mit dem offiziellen [IPSCoyote/GO-eCharger](https://github.com/IPSCoyote/GO-eCharger) Modul.
- **Übersichtliches WebFront:** Ladeleistung, Modus, SOC, Status und Logs auf einen Blick im Symcon WebFront.

---

## 🛠️ Einrichtung & Inbetriebnahme

### 1. Voraussetzungen

- **Symcon** ab Version 7.0 empfohlen
- GO-eCharger V3 oder V4 (LAN/WLAN)
- Installiertes [GO-eCharger-Modul von IPSCoyote](https://github.com/IPSCoyote/GO-eCharger)
- Aktuelle PV-, Hausverbrauchs- und (optional) Batteriedaten in Symcon verfügbar

### 2. Modulinstallation

- Modul über den Symcon Module Store suchen: `PVWallboxManager`
- Oder GitHub Repo einbinden:  
  `https://github.com/pesensie/symcon-pv-wallbox-manager`
- Instanz anlegen und konfigurieren

### 3. Konfiguration (Wesentliche Einstellungen)

- **GO-eCharger Instanz wählen**
- **PV-Erzeugung:** Variable für aktuelle PV-Leistung wählen
- **Hausverbrauch:** Variable für aktuellen Hausverbrauch
- **Batterieladung:** (optional) Variable für Batterie (positiv = laden, negativ = entladen)
- **Modi aktivieren/deaktivieren** je nach Bedarf  
  ![Beispiel WebFront](assets/example_webfront.png)

---

## 🌞 Typische Anwendungsbeispiele

### **PV-Überschussladen (Standard)**
„Ich möchte nur dann laden, wenn genug Sonnenstrom übrig ist.“  
→ Modus: *Nur PV-Überschuss*  
→ Der PVWallboxManager startet/stopt die Ladung vollautomatisch und passt die Ladeleistung laufend an.

### **Manuelles Vollladen**
„Ich brauche das Auto dringend – egal ob PV oder nicht.“  
→ Im WebFront auf „🔌 Manuell: Vollladen“ klicken  
→ Die Wallbox lädt sofort mit maximal möglicher Leistung (ACHTUNG: Es wird auch Netzstrom verwendet, falls nötig.)

### **Zielzeit-Ladung**
„Bis morgen 6 Uhr soll das Auto auf 80 % geladen sein.“  
→ Ziel-SoC und Zielzeit einstellen  
→ Modus „⏰ Zielzeit-Ladung“ aktivieren  
→ Das Modul berechnet den optimalen Ladebeginn und nutzt soweit möglich PV-Überschuss. Wenige Stunden vor Zielzeit wird ggf. mit voller Power geladen.

### **PV2Car (prozentual)**
„Nur einen Teil meines Überschusses fürs Auto nutzen.“  
→ Schieberegler auf z. B. 50 % stellen  
→ Im PV2Car-Modus lädt die Wallbox nur mit der Hälfte des aktuellen Überschusses.

---

## ⚙️ Tipps & Best Practices

- **Phasenumschaltung:**  
  Umschaltung erfolgt automatisch, wenn z. B. der Überschuss 3x in Folge über/unter dem Schwellwert liegt (Hysterese für Stabilität).
- **SOC-Ziel nicht erreichbar:**  
  Ist bis zur Zielzeit nicht genug PV da, wird 4 h vor der Zielzeit ggf. auch Netzstrom genutzt, damit das Auto garantiert voll ist.
- **Automatische Deaktivierung:**  
  Wird das Fahrzeug abgesteckt, deaktiviert sich jeder Modus automatisch. So vermeidest du Fehlschaltungen.
- **Logs:**  
  Sämtliche Aktionen, Status- und Fehlermeldungen werden im Log dokumentiert – für volle Nachvollziehbarkeit.
- **Feinjustage:**  
  Schwellwerte (z. B. ab wie viel Watt Überschuss geladen wird) können flexibel angepasst werden.

---

## 🛠️ Modulsteuerung und Debug-Logging

In der Instanzkonfiguration des PVWallboxManager findest du unter **„Modulsteuerung“** wichtige Zusatzfunktionen:

- **Modul (de-)aktivieren:**  
  Über einen Schalter kannst du das gesamte Modul temporär deaktivieren – praktisch zum Testen, Debugging oder für Wartungsarbeiten. Im deaktivierten Zustand werden keinerlei Aktionen oder Steuerbefehle mehr ausgelöst.

- **Debug-Logging aktivieren:**  
  Setze einfach das Häkchen bei „Debug-Logging“. Dann werden alle Modulaktionen (z. B. Ladeentscheidungen, Statuswechsel, Phasenumschaltung, Fehler etc.) besonders ausführlich ins Symcon-Debug-Fenster geschrieben.  
  Das ist ideal zur Fehlersuche, Optimierung oder zur Nachvollziehbarkeit, was genau wann passiert.

  > **Tipp:**  
  > Die ausführlichen Debug-Logs siehst du direkt im Symcon-Objektbaum, wenn du auf das Modul klickst und oben „Debug“ auswählst.

---

### Best Practices

- Nutze das Debug-Logging gezielt, wenn du z. B. Probleme beim Laden, mit der Phasenumschaltung oder bei der Modusauswahl hast. Nach der Problemanalyse kann das Logging wieder abgeschaltet werden.
- Bei Umbauten, Tests oder zur Fehlersuche kannst du das gesamte Modul in der Instanzkonfiguration deaktivieren – so wird nichts mehr gesteuert oder geschaltet.

---

## 🔍 Fehlersuche & FAQ

**Q:** *Die Wallbox startet nicht, obwohl Überschuss vorhanden ist.*  
**A:** Prüfe, ob der Modus aktiv ist, das Auto verbunden ist und alle Variablen korrekt zugewiesen sind.

**Q:** *Im Log stehen doppelte oder unerwartete Meldungen.*  
**A:** Prüfe die Einstellungen und ggf. ob mehrere Timer/Skripte parallel laufen.

**Q:** *Die Phasenumschaltung erfolgt zu oft/zu selten.*  
**A:** Passe die Schwellwerte und die Hysterese im Modul an.

**Q:** *Kann ich auch Strompreis-basiertes Laden nutzen?*  
**A:** Ja, das Modul kann so konfiguriert werden, dass nur bei günstigem Strom geladen wird (z. B. Tibber, Awattar, ...).

**Q:** *Wie kann ich genau sehen, warum mein Ladevorgang gestartet (oder gestoppt) wurde?*  
**A:** Aktiviere in der Instanz die Debug-Ausgabe. Im Debug-Fenster findest du alle Details zu den Steuerungsentscheidungen.

**Q:** *Wie kann ich das Modul kurzfristig anhalten, ohne es zu löschen?*  
**A:** In der Instanzkonfiguration auf „Deaktivieren“ klicken – alle Modulaktionen werden solange pausiert.

---

## 🛡️ Sicherheitshinweis

- **ACHTUNG:** Unsachgemäße Steuerung kann zu ungewolltem Netzbezug oder unnötigem Verschleiß der Wallbox führen. Prüfe deine Einstellungen und prüfe regelmäßig, ob die Steuerung wie gewünscht arbeitet!

---

## 💡 Roadmap & Mitmachen

- Zielzeitladung, Fahrzeugerkennung, flexible Lademodi, weitere Wallbox-Unterstützung, Visualisierungen u. v. m.  
- **Feature-Wünsche?** Feedback willkommen im Symcon-Forum!

---

## ❤️ Dank & Credits

Ein herzliches Dankeschön an die Community und an [@Coyote](https://github.com/IPSCoyote) für das geniale GO-eCharger-Modul!

---

## 📝 Versionshistorie (Auszug)

> Die vollständige Changelog siehe `CHANGELOG.md`.

| Version | Datum       | Änderungen                                                    |
|---------|------------|---------------------------------------------------------------|
| 0.9.0   | 2025-06-30 | Button-Logik exklusiv, Zielzeit-Puffer einstellbar, diverse Fixes |
| 0.8.0   | 2025-06-25 | Zielzeitladen, Button für Modus, exakte Statuskontrolle        |
| 0.7.0   | 2025-06-17 | Fahrzeugstatus, Hysterese Phasenumschaltung                   |
| ...     | ...        | ...                                                           |

---

## 📬 Feedback & Support

- **Symcon Forum:** [Link zum Modul-Thread](https://community.symcon.de/)
- **GitHub:** [https://github.com/pesensie/symcon-pv-wallbox-manager](https://github.com/pesensie/symcon-pv-wallbox-manager)

---

