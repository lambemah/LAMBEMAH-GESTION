
2d8b1cdc-1574-46b4-b369-eb402688de00.png

52304608-7b57-48ad-affc-e4bb2da0865f.png

23f71b5a-2855-44c7-90b4-e0d06e127f57.png
ET POURQUOI  CE  TRUCS  ROUGE

420d9bb6-7ef2-429e-a07c-5acb7b679d2c.png
 pcest xa

Capture d'écran 2026-09-04 175446(1).png
AH SA NE MARCE PAS? REGARDE LAPPLICATION DE BIK

1c944542-b9de-44e0-8ed5-4f79c7b4e387.png
VRAIMENT JE NAI PLUS LE COURAGE DE CONTINUER AVEC TOI,RIEN NE MARHE WESH,l les caractere ne sont bonnes,les instructions ne sont pas respecter seulement la table prestaion qui me plai ici sinon vraiment

66f626e0-e951-43ec-a1d4-e896f31f6aa2.png
IL nya meme plus possibilité de saisir des nouveau produits,les cases ou mettre les valeurs sont restratrainte, en gros je napprecie rien ic à part prestation pp

66f626e0-e951-43ec-a1d4-e896f31f6aa2.png
IL nya meme plus possibilité de saisir des nouveau produits,les cases ou mettre les valeurs sont restratrainte, en gros je napprecie rien ic à part prestation pp,meme quand on modifie et actualise sa ne marche pas

Voici le produits.php complet corrigé, avec une syntaxe PHP validée :


Remplace tout le contenu de produits.php dans GitHub par celui-ci, puis Commit changes.

Ensuite ouvre :

https://lambemah-gestion-production-e7c4.up.railway.app/produits.php

On ne touche à aucun autre fichier avant de vérifier cette page.



f928a6b7-07e5-4728-bb08-d798b1165cb4.png
FINALEMENT JE VAIS ABANDONNER XA BHA VRAIMENT?TOI REGARDE COMMENT CEST GROP,moi je souhaite une police vraiment petite


f928a6b7-07e5-4728-bb08-d798b1165cb4.png
FINALEMENT JE VAIS ABANDONNER XA BHA VRAIMENT?TOI REGARDE COMMENT CEST GROP,moi je souhaite une police vraiment petite pour bien travailler mais là

Non, n’abandonne pas. Je vois exactement ce que tu veux maintenant : une interface beaucoup plus compacte, avec une vraie petite police, des champs moins hauts et davantage d’informations visibles en même temps.

J’ai repris le fichier actuel de produits.php et réduit uniquement l’interface, sans toucher à sa logique. Le fichier passe la vérification PHP.

Nouveau fichier

Il est maintenant prévu avec :

police générale autour de 11–12 px

titres autour de 15–23 px

boutons plus petits

champs plus bas

tableaux plus compacts

sidebar plus étroite

beaucoup moins d’espace vide

affichage plus proche du style de la page Prestations que tu apprécies

Remplace tout le contenu de produits.php par ce fichier, puis Commit.

Après le déploiement, ouvre :
https://lambemah-gestion-production-e7c4.up.railway.app/produits.php

Cette fois, on juge uniquement la taille et l’apparence avant de modifier autre chose.



Bibliothèque
/
produits_small.php


1
2
3
4
5
6
7
8
9
10
11
12
13
14
15
16
17
18
19
20
21
22
23
24
25
26
27
28
29
30
31
32
33
34
35
36
37
38
39
40
41
42
43
44
45
46
47
48
49
50
51
52
53
54
55
56
57
58
59
60
61
62
63
64
65
66
67
68
69
70
71
<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Connexion à la base de données impossible.');
}
$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_OFF);

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($v): string { return number_format((float)$v, 0, ',', ' ') . ' FG'; }
function cleanText($v): string { return trim(preg_replace('/[|\r\n]+/', ' ', (string)$v)); }
function flash(string $type, string $msg): void { $_SESSION['flash'] = ['type'=>$type,'msg'=>$msg]; }

function nextId(mysqli $conn, string $table): int {
    $allowed = ['produits','mouvements','ventes','depenses','recettes'];
    if (!in_array($table, $allowed, true)) throw new Exception('Table non autorisée.');
    $q = $conn->query("SELECT MAX(id) AS max_id FROM `$table`");
    if (!$q) throw new Exception('Impossible de lire le prochain identifiant : ' . $conn->error);
    $r = $q->fetch_assoc();
    return ((int)($r['max_id'] ?? 0)) + 1;
}

function parseMeta(string $desc): array {
    $o = [];
    foreach (explode('|', $desc) as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (strpos($p, '=') !== false) {
            [$k,$v] = explode('=', $p, 2);
            $o[strtoupper(trim($k))] = trim($v);
            continue;
        }
        if (preg_match('/^Facture\s*:\s*(.+)$/i', $p, $m)) $o['FACTURE'] = trim($m[1]);
        if (preg_match('/^Fournisseur\s*:\s*(.+)$/i', $p, $m)) $o['FOURNISSEUR'] = trim($m[1]);
        if (preg_match('/^Pay[ée]e?\s*:\s*([0-9\s,.]+)\s*FG/i', $p, $m)) $o['PAYE'] = (float)str_replace([' ', ','], ['', '.'], $m[1]);
        if (preg_match('/^Reste fournisseur\s*:\s*([0-9\s,.]+)\s*FG/i', $p, $m)) $o['RESTE'] = (float)str_replace([' ', ','], ['', '.'], $m[1]);
    }
    return $o;
}

function invoiceRows(mysqli $conn, string $ref): array {
    $safe = $conn->real_escape_string($ref);
    $rows = [];
    $sql = "SELECT m.*, p.nom, p.categorie FROM mouvements m
            LEFT JOIN produits p ON p.id=m.produit_id
            WHERE m.type='ENTREE'
              AND (m.description LIKE '%FACTURE=$safe|%'
                   OR m.description LIKE '%FACTURE=$safe%'
                   OR m.description LIKE '%Facture : $safe%')
            ORDER BY m.id ASC";
    $q = $conn->query($sql);
    while ($q && ($r = $q->fetch_assoc())) $rows[] = $r;
    return $rows;
}

function purchaseRef(mysqli $conn): string {
    $year = date('Y');
    $max = 0;
    $q = $conn->query("SELECT description FROM mouvements WHERE type='ENTREE'");
    while ($q && ($r = $q->fetch_assoc())) {
        if (preg_match('/FACTURE=ACH-' . preg_quote($year,'/') . '-(\d{4})\|/', $r['description'] ?? '', $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    return 'ACH-' . $year . '-' . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

function deletePurchaseRows(mysqli $conn, string $ref): void {
    $safe = $conn->real_escape_string($ref);
