# ✅ Checkliste für Beta-Freigabe – PVWallboxManager (ab Version 0.4)

## 🔧 Funktionalität
- [ ] PV-Überschussberechnung und Ladeleistungssteuerung stabil
- [ ] Phasenumschaltung mit Zähler-Hysterese umgesetzt
- [ ] Lademodi:
  - [ ] Manueller Modus
  - [ ] PV2Car (%-Modus)
  - [ ] Uhrzeit-Zielladung
  - [ ] Nur-PV-Modus (Fallback)

## 📈 Logik & Performance
- [ ] Nur bei Änderungen wird neu gesetzt (Modus / Ladeleistung)
- [ ] Keine Endlosschleifen oder unnötige Timer-Trigger
- [ ] Ladeleistung wird sauber auf Rundungswert angepasst (z. B. 230 V * Ampere)
- [ ] Fehlerhandling für fehlende Fahrzeugverbindung eingebaut (ggf. deaktivierbar)

## 🌐 Integration
- [ ] Modul unterstützt go-e Charger (V4)
- [ ] Kompatibilität mit Symcon 8.x getestet (ggf. 7.x optional dokumentiert)
- [ ] Optional: Vorbereitung für CarConnectivity-MQTT (Fahrzeugdaten)

## 📄 Dokumentation
- [ ] `README.md` enthält:
  - [ ] Kurze Funktionsübersicht
  - [ ] Installationsanleitung (Modul-URL, Variablen anlegen, Profile)
  - [ ] Beschreibung der Lademodi
  - [ ] Beispielkonfiguration (z. B. Screenshot mit IDs)
  - [ ] Hinweise zu bekannten Einschränkungen / Limitierungen
- [ ] `form.json` sauber strukturiert und selbsterklärend
- [ ] `changelog.md` führt alle bisherigen Änderungen

## 📢 Vorbereitung Community-Release (ab Version 0.5)
- [ ] GitHub-Repository öffentlich (falls noch privat)
- [ ] Releases mit Tags gepflegt (z. B. `v0.4-beta`)
- [ ] Screenshot für Forum-Beitrag erstellt
- [ ] Thema im Symcon-Forum vorbereiten:
  - [ ] Titel: `[Modul] PVWallboxManager – dynamische PV-Überschussladung (go-e)`
  - [ ] Link zum GitHub-Modul
  - [ ] Screenshots & Featureübersicht
  - [ ] Hinweis: *Beta, Feedback willkommen!*
