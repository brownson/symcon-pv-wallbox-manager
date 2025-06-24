# ✅ PVWallboxManager – Aktueller Entwicklungsstand & To-Do-Liste

## 🟢 Abgeschlossene Funktionen

### Kernfunktionen
- [x] PV-Überschussberechnung (PV – Hausverbrauch – Batterie)
- [x] Visualisierung des PV-Überschusses in IP-Symcon
- [x] Dynamische Ladeleistungsanpassung (konfigurierbarer Ampere-Bereich)
- [x] Automatische Phasenumschaltung (1-/3-phasig) mit Hysterese
- [x] Dynamischer Puffer für stabilere Leistungsregelung
- [x] Fahrzeugstatusprüfung (Laden nur wenn Fahrzeug verbunden)

### Erweiterte Ladelogik
- [x] Manueller Volllademodus mit automatischer Deaktivierung bei Fahrzeugtrennung
- [x] PV2Car-Modus mit flexiblem Überschuss-Anteil fürs Fahrzeug
- [x] Zielzeitladung PV-optimiert (nur PV-Überschuss bis x Stunden vor Zielzeit, dann volle Ladung)
- [x] Automatischer Moduswechsel: Nur ein Modus (Manuell, PV2Car, Zielzeit) aktiv gleichzeitig
- [x] Automatisches Zurücksetzen aller Modi bei Fahrzeugtrennung

### Fahrzeugdaten-Integration
- [x] SoC-basierte Ladeentscheidungen möglich über `UseCarSOC`
- [x] Ziel-SoC flexibel über Variable oder Fallback-Wert definierbar

### Konfiguration & Oberfläche
- [x] Konfigurierbare Vorlaufzeit für Zielzeitladung im `form.json`
- [x] Detaillierte Tooltips & Beschreibungen für alle Einstellungen
- [x] Strukturierte, übersichtliche Konfigurationsoberfläche

### Dokumentation
- [x] Vollständig aktualisierte README mit allen Funktionen & Modi
- [x] Detaillierter Changelog inkl. Version 0.7 (Beta)

---

## 🔧 Offene Punkte & nächste Schritte

### Benutzeroberfläche & Dokumentation
- [ ] Screenshots und Beispieldarstellungen für WebFront ergänzen
- [ ] Englische README vorbereiten (optional)

### Funktionale Weiterentwicklung
- [ ] Ladeplanung für Zielzeitladung vervollständigen (dynamischer Startzeitpunkt je nach SoC)
- [ ] Anbindung externer Fahrzeugdaten (z. B. über MQTT oder VW-Car-API)
- [ ] Erweiterte WebFront-Visualisierung (Phasenstatus, Ladezustand, Modus)
- [ ] Unterstützung weiterer Wallbox-Typen prüfen & vorbereiten (z. B. openWB, easee)
- [ ] Zielzeitladung finalisieren nach Rückmeldungen aus der Beta-Phase

