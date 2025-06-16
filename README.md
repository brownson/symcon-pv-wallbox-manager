# PVWallboxManager

**Version 0.1**

Dieses Modul für IP‑Symcon berechnet automatisch den PV‑Überschuss und kann diesen z. B. für die Wallbox‑Steuerung verwenden.

### 🔧 Funktionen

- PV-Überschuss = PV-Erzeugung – Hausverbrauch – Batterieladung (positiv = lädt, negativ = entlädt)
- Einstellbares Timerintervall: 15–600 Sekunden
- Automatische, timergetriebene Berechnung
- Logging mit Symbolen (☀️🔋❌) für verschiedene Überschuss-Zustände

### ⚙️ Konfiguration (`form.json`)

| Feldname           | Typ              | Beschreibung |
|--------------------|------------------|--------------|
| PVErzeugungID      | SelectVariable   | Variable mit aktueller PV-Leistung (W) |
| HausverbrauchID    | SelectVariable   | Variable mit aktuellem Verbrauch (W) |
| BatterieladungID   | SelectVariable   | Lade-/Entladeleistung des Speichers (W) |
| RefreshInterval    | NumberSpinner    | Intervall (15–600 Sekunden) |

### 🚀 Installation und Nutzung

1. Modul in IP‑Symcon importieren und Instanz anlegen  
2. Quell-Variablen (PV, Verbrauch, Akku) und Intervall einstellen  
3. Instanz speichern – die automatische Berechnung läuft im Hintergrund  
4. In den IP‑Symcon-Meldungen siehst du, ob Überschuss vorhanden ist (Log-Meldungen mit Symbolen)

### 📌 Hinweise

- Batterieentladung (negativ) erhöht den Überschuss  
- Batterie-Ladung (positiv) reduziert den Überschuss  
- Software-Version: **0.1**

### 🛠️ Weiterentwicklung

Geplante Erweiterungen für zukünftige Versionen, z. B.:

- Steuerung einer go‑e Wallbox oder Ladeziele
- Einbindung eines Batteriespeicher-Zielzustands
- Logging in separater Variable

