<?php
$wallboxIP = '192.168.98.5'; // <- Deine Wallbox-IP eintragen
$url = "http://$wallboxIP/status";

$options = [
    "http" => [
        "timeout" => 5, // 5 Sekunden Timeout
    ]
];
$context = stream_context_create($options);

$json = @file_get_contents($url, false, $context);

if ($json === false) {
    die("Fehler: Konnte keine Daten von der Wallbox holen!");
}

$data = json_decode($json, true);

if (!is_array($data)) {
    die("Fehler: Ungültiges JSON erhalten!");
}

// Beispiel: Ladeleistung anzeigen
echo "Status: " . $data['car'] . PHP_EOL;
echo "Aktuelle Ladeleistung: " . $data['nrg'][11] . " W" . PHP_EOL; // nrg[11] = Leistung in W
