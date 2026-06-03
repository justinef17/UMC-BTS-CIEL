<?php
header('Content-Type: text/plain');

$pdo = new PDO(
    'mysql:host=mysql-gestionenergiemaupertuis.alwaysdata.net;dbname=gestionenergiemaupertuis_bdd',
    'gestionenergiemaupertuis',
    'M2977UDjCH'
);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare("INSERT INTO mesures
        (bat_tension, bat_courant, soc, ac_out_tension, ac_out_courant)
        VALUES (?, ?, ?, ?, ?)");

    $stmt->execute([
        $_GET['bat_tension']    ?? null,
        $_GET['bat_courant']    ?? null,
        $_GET['soc']            ?? null,
        $_GET['ac_in_tension']  ?? null,
        $_GET['ac_in_courant'] ?? null,
        $_GET['ac_out_tension'] ?? null,
        $_GET['ac_out_courant'] ?? null
    ]);

    echo "OK";
}
?>