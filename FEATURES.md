# 🌟 Feature- und Ideenliste für PVWallboxManager

Hier werden geplante Features gesammelt, Community-Wünsche, Ideen und größere ToDos für die Weiterentwicklung des Moduls.
  
**Pull Requests, Kommentare und Vorschläge sind willkommen!**
  
---

## 🚗 Lademodi

- [ ] **🌤️Hybrid-Laden-Modus**  
      Immer Mindestleistung laden (z.B. 6A), PV-Überschuss wird aufaddiert (wie bei evcc).
      **Status:** Geplant

- [ ] **Dynamisches Lastmanagement (Netzanschluss-Absicherung)**  
      Die Wallbox regelt die Leistung dynamisch herunter, falls das Haus (inkl. aller Verbraucher) den maximalen Netzanschluss (z.B. 35A/7kW) zu überschreiten droht.  
      **Status:** Idee  
      **Hintergrund:** Jederzeit Vorrang für das Haus, nie Sicherungsauslösung!  
      **Beispiel:** Haus braucht 6kW, dann bleiben nur noch 1kW (1-phasig) für die Wallbox übrig.  

- [ ] **Weitere Lademodi und Features**
    - [ ] Zeitgesteuertes Laden (z.B. Zielzeit, günstige Börsenzeiten)
    - [x] PV2Car mit Prozentsteuerung
    **Umgesetzt in: v1.0b**

---

## 🛠️ Technische Verbesserungen

- [ ] Konfigurierbare Hysterese und Phasenumschaltung
- [ ] Mehr Visualisierung/Logging im WebFront
- [ ] Automatisches Reset nach Stromausfall
- [x] Börsenpreise sollen zur vollen Stunde aktualisiert werden
      **Umgesetzt in: v1.1b**
- [ ] Wenn Auto SOC erreicht hat soll der Ladenodus auch beendet werden. Derzeit Versucht das Modul verzweifelt zu laden.
