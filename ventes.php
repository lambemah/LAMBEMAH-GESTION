<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Connexion à la base de données impossible.');
}

$conn->set_charset('utf8mb4');

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function money($v) {
    return number_format((float)$v, 0, ',', ' ') . ' FG';
}

function cleanText($v) {
    return trim(preg_replace('/[|\r\n]+/', ' ', (string)$v));
}

function nextId(mysqli $conn, string $table): int {
    if (!in_array($table, ['produits', 'mouvements', 'ventes'], true)) {
        return 1;
    }

    $r = $conn->query("SELECT COALESCE(MAX(id),0)+1 n FROM `$table`");
    return (int)($r->fetch_assoc()['n'] ?? 1);
}

function parseMeta(string $desc): array {
    $o = [];

    foreach (explode('|', $desc) as $p) {
        if (strpos($p, '=') !== false) {
            [$k, $v] = explode('=', $p, 2);
            $o[$k] = trim($v);
        }
    }

    return $o;
}

function invoiceRows(mysqli $conn, string $ref): array {
    $rows = [];
    $safe = $conn->real_escape_string($ref);

    $q = $conn->query("
        SELECT v.*, p.nom, p.categorie
        FROM ventes v
        LEFT JOIN produits p ON p.id = v.produit_id
        WHERE v.description LIKE '%FACTURE=$safe|%'
        ORDER BY v.id ASC
    ");

    while ($q && ($r = $q->fetch_assoc())) {
        $rows[] = $r;
    }

    return $rows;
}

function flash($t, $m) {
    $_SESSION['flash'] = [
        'type' => $t,
        'msg'  => $m
    ];
}

/* =========================
   IMPRESSION DE LA FACTURE
========================= */

if (isset($_GET['imprimer'])) {

    $ref = cleanText($_GET['imprimer']);
    $rows = invoiceRows($conn, $ref);

    if (!$rows) {
        die('Facture introuvable.');
    }

    $meta = parseMeta($rows[0]['description']);

    $total = 0;

    foreach ($rows as $r) {
        $total += (float)$r['quantite'] * (float)$r['prix_unitaire'];
    }

    $paid = (float)($meta['PAYE'] ?? 0);
    $rest = max(0, $total - $paid);

    function numberWordsFr($n) {

        $n = (int)round($n);

        if ($n === 0) {
            return 'zéro';
        }

        $u = [
            'zéro','un','deux','trois','quatre','cinq','six',
            'sept','huit','neuf','dix','onze','douze','treize',
            'quatorze','quinze','seize'
        ];

        $tens = [
            20 => 'vingt',
            30 => 'trente',
            40 => 'quarante',
            50 => 'cinquante',
            60 => 'soixante'
        ];

        $under100 = function($x) use (&$under100, $u, $tens) {

            if ($x < 17) {
                return $u[$x];
            }

            if ($x < 20) {
                return 'dix-' . numberWordsFr($x - 10);
            }

            if ($x < 70) {

                $d = intdiv($x, 10) * 10;
                $r = $x % 10;
                $w = $tens[$d];

                if ($r === 1) {
                    return $w . ' et un';
                }

                return $r ? $w . '-' . numberWordsFr($r) : $w;
            }

            if ($x < 80) {

                if ($x === 71) {
                    return 'soixante et onze';
                }

                return 'soixante-' . numberWordsFr($x - 60);
            }

            if ($x === 80) {
                return 'quatre-vingts';
            }

            return 'quatre-vingt-' . numberWordsFr($x - 80);
        };

        $under1000 = function($x) use (&$under1000, &$under100, $u) {

            if ($x < 100) {
                return $under100($x);
            }

            $h = intdiv($x, 100);
            $r = $x % 100;

            $w = ($h === 1)
                ? 'cent'
                : $u[$h] . ' cent';

            if ($r === 0 && $h > 1) {
                $w .= 's';
            }

            return $r
                ? $w . ' ' . $under100($r)
                : $w;
        };

        $parts = [];

        if ($n >= 1000000000) {
            $b = intdiv($n, 1000000000);
            $n %= 1000000000;

            $parts[] =
                $under1000($b) .
                ' milliard' .
                ($b > 1 ? 's' : '');
        }

        if ($n >= 1000000) {
            $m = intdiv($n, 1000000);
            $n %= 1000000;

            $parts[] =
                $under1000($m) .
                ' million' .
                ($m > 1 ? 's' : '');
        }

        if ($n >= 1000) {
            $k = intdiv($n, 1000);
            $n %= 1000;

            $parts[] =
                $k === 1
                ? 'mille'
                : $under1000($k) . ' mille';
        }

        if ($n > 0) {
            $parts[] = $under1000($n);
        }

        return implode(' ', $parts);
    }

    $totalWords = ucfirst(numberWordsFr($total)) . ' francs guinéens';
?>
<!doctype html>
<html lang="fr">

<head>

<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">

<title><?=h($ref)?> • LAMBEMAH GESTION</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #edf3f9;
    color: #172b40;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 13px;
}

.printbar {
    max-width: 920px;
    margin: 15px auto;
    background: #fff;
    border: 1px solid #d8e3ee;
    border-radius: 12px;
    padding: 10px 14px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.printbar label {
    font-weight: 700;
    color: #17345c;
}

.printbar button {
    border: 0;
    border-radius: 8px;
    padding: 10px 15px;
    background: #1479e8;
    color: #fff;
    font-weight: 700;
    cursor: pointer;
}

.paper {
    max-width: 920px;
    min-height: 1120px;
    margin: 12px auto 25px;
    background: #fff;
    padding: 35px 42px;
    box-shadow: 0 6px 28px rgba(18,52,81,.10);
    border: 1px solid #e1e9f2;
}

.head {
    display: grid;
    grid-template-columns: 1fr 255px;
    gap: 28px;
    align-items: start;
    border-bottom: 2px solid #1479e8;
    padding-bottom: 18px;
}

.brandline {
    display: flex;
    align-items: center;
    gap: 14px;
}

.brandline img {
    width: 78px;
    height: 78px;
    object-fit: contain;
}

.brand {
    font-size: 24px;
    font-weight: 900;
    letter-spacing: .2px;
    color: #123b70;
}

.gold {
    font-size: 12px;
    letter-spacing: 1.5px;
    color: #b77905;
    font-weight: 800;
    margin-top: 3px;
}

.contact {
    font-size: 11px;
    line-height: 1.6;
    color: #64748b;
    margin-top: 7px;
}

.factbox {
    background: #eaf3fc;
    border-radius: 13px;
    padding: 15px 17px;
    border-left: 4px solid #1479e8;
}

.factbox .label {
    font-size: 22px;
    font-weight: 900;
    color: #123b70;
}

.factbox .ref {
    font-size: 14px;
    font-weight: 800;
    color: #17345c;
    margin-top: 4px;
}

.factbox .date {
    font-size: 11px;
    color: #66788d;
    margin-top: 9px;
    line-height: 1.55;
}

.info {
    display: grid;
    grid-template-columns: 1.35fr .65fr;
    gap: 18px;
    margin: 18px 0;
}

.box {
    border: 1px solid #dbe5ef;
    border-radius: 11px;
    padding: 13px 15px;
}

.box .boxtitle {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .8px;
    color: #1479e8;
    font-weight: 900;
    margin-bottom: 5px;
}

.box b {
    font-size: 14px;
    color: #17345c;
}

.table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    overflow: hidden;
    border: 1px solid #cfe0f0;
    border-radius: 10px;
}

.table th {
    background: #1479e8;
    color: #fff;
    font-size: 11px;
    padding: 11px 9px;
    text-align: left;
}

.table td {
    padding: 11px 9px;
    border-bottom: 1px solid #e4edf5;
    color: #243b53;
}

.table tr:last-child td {
    border-bottom: 0;
}

.num {
    text-align: right;
    white-space: nowrap;
}

.designation {
    font-weight: 700;
}

.bottom {
    display: grid;
    grid-template
