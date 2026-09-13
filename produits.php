<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Connexion à la base de données impossible.');
}

$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_OFF);

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

    $q = $conn->query("SELECT COALESCE(MAX(id),0)+1 n FROM `$table`");

    return (int)(($q && ($r = $q->fetch_assoc())) ? $r['n'] : 1);
}

function parseMeta(string $desc): array {
    $o = [];

    foreach (explode('|', $desc) as $p) {
        $p = trim($p);

        if (strpos($p, '=') !== false) {
            [$k, $v] = explode('=', $p, 2);
            $o[trim($k)] = trim($v);
            continue;
        }

        if (preg_match('/^Facture\s*:\s*(.+)$/i', $p, $m)) {
            $o['FACTURE'] = trim($m[1]);
            continue;
        }

        if (preg_match('/^Fournisseur\s*:\s*(.+)$/i', $p, $m)) {
            $o['FOURNISSEUR'] = trim($m[1]);
            continue;
        }

        if (preg_match('/^Payé\s*:\s*([0-9\s,.]+)\s*FG/i', $p, $m)) {
            $o['PAYE'] = (float)str_replace([' ', ','], ['', '.'], $m[1]);
            continue;
        }

        if (preg_match('/^Reste fournisseur\s*:\s*([0-9\s,.]+)\s*FG/i', $p, $m)) {
            $o['RESTE'] = (float)str_replace([' ', ','], ['', '.'], $m[1]);
            continue;
        }
    }

    return $o;
}

function invoiceRows(mysqli $conn, string $ref): array {
    $rows = [];
    $safe = $conn->real_escape_string($ref);

    $sql = "
        SELECT m.*, p.nom, p.categorie
        FROM mouvements m
        LEFT JOIN produits p ON p.id = m.produit_id
        WHERE m.type = 'ENTREE'
        AND (
            m.description LIKE '%FACTURE=$safe|%'
            OR m.description LIKE '%Facture : $safe%'
        )
        ORDER BY m.id ASC
    ";

    $q = $conn->query($sql);

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

function purchaseRef(mysqli $conn): string {
    $year = date('Y');
    $max = 0;

    $q = $conn->query("
        SELECT description
        FROM mouvements
        WHERE type='ENTREE'
        AND description LIKE 'FACTURE=ACH-$year-%'
    ");

    while ($q && ($r = $q->fetch_assoc())) {
        if (
            preg_match(
                '/FACTURE=ACH-' . preg_quote($year, '/') . '-(\d{4})\|/',
                $r['description'],
                $m
            )
        ) {
            $max = max($max, (int)$m[1]);
        }
    }

    return 'ACH-' . $year . '-' . str_pad(
        (string)($max + 1),
        4,
        '0',
        STR_PAD_LEFT
    );
}

function qexec(mysqli $conn, string $sql): void {
    if (!$conn->query($sql)) {
        throw new Exception($conn->error);
    }
}


/* =========================================================
   IMPRESSION
========================================================= */

if (isset($_GET['imprimer'])) {

    $ref = cleanText($_GET['imprimer']);
    $rows = invoiceRows($conn, $ref);

    if (!$rows) {
        die('Facture introuvable.');
    }

    $meta = parseMeta($rows[0]['description']);

    $total = 0;

    foreach ($rows as $r) {
        $total += (float)$r['quantite'] * (float)$r['prix'];
    }

    $paid = (float)($meta['PAYE'] ?? 0);
    $rest = max(0, $total - $paid);

    ?>
    <!doctype html>
    <html lang="fr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">

        <title><?=h($ref)?></title>

        <style>
            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                background: #edf3f8;
                color: #172b40;
                font-family: Arial, sans-serif;
                font-size: 14px;
            }

            .printbar {
                max-width: 900px;
                margin: 18px auto 0;
                background: #fff;
                border: 1px solid #dce6ef;
                border-radius: 14px;
                padding: 12px 16px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 15px;
            }

            .printbar label {
                font-weight: 700;
            }

            .printbar button {
                border: 0;
                border-radius: 9px;
                padding: 10px 15px;
                background: #1478e5;
                color: #fff;
                font-weight: 700;
                cursor: pointer;
            }

            .paper {
                max-width: 900px;
                margin: 15px auto;
                background: #fff;
                padding: 38px;
                min-height: 1100px;
                box-shadow: 0 8px 30px rgba(16,52,84,.08);
            }

            .head {
                display: flex;
                justify-content: space-between;
                gap: 25px;
                border-bottom: 3px solid #103454;
                padding-bottom: 15px;
            }

            .brandline {
                display: flex;
                align-items: center;
                gap: 14px;
            }

            .brandline img {
                width: 75px;
                height: 75px;
                object-fit: contain;
            }

            .brand {
                font-size: 25px;
                font-weight: 900;
                color: #103454;
            }

            .gold {
                color: #bd8a22;
                font-weight: 800;
            }

            .contact {
                font-size: 11px;
                line-height: 1.55;
                color: #526579;
                margin-top: 5px;
            }

            .title {
                text-align: right;
                color: #103454;
                font-size: 23px;
                font-weight: 900;
            }

            .title small {
                font-size: 14px;
                color: #bd8a22;
            }

            .info {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 14px;
                margin: 20px 0;
            }

            .box {
                border: 1px solid #dce6ef;
                border-radius: 10px;
                padding: 12px;
            }

            .box b {
                color: #103454;
            }

            .table {
                width: 100%;
                border-collapse: collapse;
            }

            .table th,
            .table td {
                padding: 11px 9px;
                border-bottom: 1px solid #dce6ef;
            }

            .table th {
                background: #f4f7fa;
                color: #526579;
                text-align: left;
                font-size: 11px;
                text-transform: uppercase;
            }

            .num {
                text-align: right;
            }

            .totals {
                margin: 20px 0 0 auto;
                max-width: 380px;
            }

            .trow {
                display: flex;
                justify-content: space-between;
                padding: 8px 0;
            }

            .trow b {
                color: #103454;
            }

            .grand {
                border-top: 2px solid #103454;
                font-size: 17px;
                font-weight: 900;
            }

            .signature {
                margin-top: 65px;
                display: flex;
                justify-content: flex-end;
            }

            .sigbox {
                width: 220px;
                text-align: center;
            }

            .sigbox .space {
                height: 90px;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .sigbox img {
                max-width: 170px;
                max-height: 80px;
                object-fit: contain;
            }

            .sigline {
                border-top: 1px solid #26394d;
                padding-top: 7px;
                font-weight: 700;
            }

            .hidden {
                display: none;
            }

            @media print {
                body {
                    background: #fff;
                }

                .printbar {
                    display: none;
                }

                .paper {
                    margin: 0;
                    box-shadow: none;
                    max-width: none;
                    min-height: auto;
                    padding: 25mm 18mm;
                }

                .signature {
                    break-inside: avoid;
                }
            }

            @media(max-width:650px) {
                .printbar {
                    margin: 8px;
                    padding: 10px;
                }

                .paper {
                    margin: 8px;
                    padding: 20px;
                }

                .head {
                    display: block;
                }

                .title {
                    text-align: left;
                    margin-top: 15px;
                }

                .info {
                    grid-template-columns: 1fr;
                }

                .table {
                    font-size: 12px;
                }

                .table th,
                .table td {
                    padding: 7px 5px;
                }

                .paper {
                    min-height: 900px;
                }
            }
        </style>
    </head>

    <body>

        <div class="printbar">
            <label>
                <input type="checkbox" id="addSignature">
                Ajouter la signature
            </label>

            <button onclick="window.print()">
                🖨️ Imprimer / PDF
            </button>
        </div>

        <div class="paper">

            <div class="head">

                <div class="brandline">

                    <img
                        src="assets/logo.png"
                        onerror="this.style.display='none'"
                    >

                    <div>
                        <div class="brand">LAMBEMAH</div>

                        <div class="gold">
                            GESTION • ACHATS
                        </div>

                        <div class="contact">
                            +224 611 752 767 / 622 595 362<br>
                            konatelambetenin@gmail.com<br>
                            KM 36, Guinée
                        </div>
                    </div>

                </div>

                <div class="title">
                    FACTURE D'ACHAT<br>
                    <small><?=h($ref)?></small>
                </div>

            </div>

            <div class="info">

                <div class="box">
                    <b>Fournisseur</b><br>
                    <?=h($meta['FOURNISSEUR'] ?? '—')?>
                </div>

                <div class="box">
                    <b>Date</b><br>
                    <?=h(
                        date(
                            'd/m/Y H:i',
                            strtotime($rows[0]['date_mouvement'] ?? 'now')
                        )
                    )?>
                </div>

            </div>

            <table class="table">

                <thead>
                    <tr>
                        <th>Désignation</th>
                        <th>Catégorie</th>
                        <th class="num">Qté</th>
                        <th class="num">Prix achat</th>
                        <th class="num">Montant</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($rows as $r): ?>

                        <?php
                        $m = (float)$r['quantite'] * (float)$r['prix'];
                        ?>

                        <tr>
                            <td><?=h($r['nom'] ?? 'Article')?></td>
                            <td><?=h($r['categorie'] ?? '')?></td>
                            <td class="num"><?=h($r['quantite'])?></td>
                            <td class="num"><?=money($r['prix'])?></td>
                            <td class="num"><?=money($m)?></td>
                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

            <div class="totals">

                <div class="trow">
                    <span>Total facture</span>
                    <b><?=money($total)?></b>
                </div>

                <div class="trow">
                    <span>Déjà payé</span>
                    <b><?=money($paid)?></b>
                </div>

                <div class="trow grand">
                    <span>Reste fournisseur</span>
                    <b><?=money($rest)?></b>
                </div>

            </div>

            <div class="signature">

                <div class="sigbox">

                    <div class="space">
                        <img
                            id="signatureImg"
                            class="hidden"
                            src="assets/signature.png"
                            alt="Signature"
                        >
                    </div>

                    <div class="sigline">
                        Responsable
                    </div>

                </div>

            </div>

        </div>

        <script>
            const c = document.getElementById('addSignature');
            const img = document.getElementById('signatureImg');

            c.addEventListener('change', () => {
                img.classList.toggle('hidden', !c.checked);
            });
        </script>

    </body>
    </html>

    <?php
    exit;
}


/* =========================================================
   ACTIONS
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        /* =========================
           ENREGISTRER / MODIFIER
        ========================= */

        if (isset($_POST['save_achat'])) {

            $editRef = cleanText($_POST['edit_ref'] ?? '');
            $fourn   = cleanText($_POST['fournisseur'] ?? '');

            $ids    = $_POST['produit_id'] ?? [];
            $qtys   = $_POST['quantite'] ?? [];
            $prices = $_POST['prix'] ?? [];

            if ($fourn === '') {
                throw new Exception(
                    'Veuillez renseigner le fournisseur.'
                );
            }

            $lines = [];

            for ($i = 0; $i < count($ids); $i++) {

                $pid = (int)($ids[$i] ?? 0);
                $q   = (int)($qtys[$i] ?? 0);
                $p   = (float)($prices[$i] ?? 0);

                if ($pid > 0 && $q > 0 && $p >= 0) {

                    $lines[] = [
                        'pid' => $pid,
                        'q'   => $q,
                        'p'   => $p
                    ];
                }
            }

            if (!$lines) {
                throw new Exception(
                    'Ajoutez au moins un article.'
                );
            }

            $old = [];
            $oldPaid = 0;
            $ref = $editRef;

            /* =========================
               MODIFICATION
            ========================= */

            if ($editRef !== '') {

                $old = invoiceRows($conn, $editRef);

                if (!$old) {
                    throw new Exception(
                        'Facture à modifier introuvable.'
                    );
                }

                $om = parseMeta(
                    $old[0]['description']
                );

                $oldPaid = (float)(
                    $om['PAYE'] ?? 0
                );

                $newTotal = 0;

                foreach ($lines as $l) {
                    $newTotal += $l['q'] * $l['p'];
                }

                if (
                    $oldPaid > 0 &&
                    $newTotal + 0.01 < $oldPaid
                ) {
                    throw new Exception(
                        'Le nouveau total ne peut pas être inférieur au montant déjà payé ('
                        . money($oldPaid)
                        . ').'
                    );
                }

            } else {

                $ref = purchaseRef($conn);
            }

            $conn->begin_transaction();

            try {

                /* =========================
                   RESTAURATION ANCIEN STOCK
                ========================= */

                if ($old) {

                    foreach ($old as $r) {

                        $pid = (int)$r['produit_id'];
                        $q   = (int)$r['quantite'];

                        qexec(
                            $conn,
                            "UPDATE produits
                             SET stock = stock - $q
                             WHERE id = $pid"
                        );
                    }

                    $safe = $conn->real_escape_string(
                        $editRef
                    );

                    qexec(
                        $conn,
                        "DELETE FROM mouvements
                         WHERE type='ENTREE'
                         AND (
                            description LIKE '%FACTURE=$safe|%'
                            OR description LIKE '%Facture : $safe%'
                         )"
                    );
                }

                $newTotal = 0;

                foreach ($lines as $l) {
                    $newTotal += $l['q'] * $l['p'];
                }

                $newRest = max(
                    0,
                    $newTotal - $oldPaid
                );

                /* =========================
                   NOUVELLES LIGNES
                ========================= */

                foreach ($lines as $l) {

                    $pid = $l['pid'];
                    $q   = $l['q'];
                    $p   = $l['p'];

                    $chk = $conn->query(
                        "SELECT id, nom
                         FROM produits
                         WHERE id=$pid"
                    );

                    if (!$chk || !$chk->num_rows) {
                        throw new Exception(
                            'Article introuvable.'
                        );
                    }

                    $mid = nextId(
                        $conn,
                        'mouvements'
                    );

                    $desc =
                        'FACTURE=' . $ref .
                        '|FOURNISSEUR=' . cleanText($fourn) .
                        '|PAYE=' . $oldPaid .
                        '|RESTE=' . $newRest .
                        '|ACHAT';

                    $date = date(
                        'Y-m-d H:i:s'
                    );

                    $st = $conn->prepare(
                        "INSERT INTO mouvements
                        (
                            id,
                            produit_id,
                            type,
                            quantite,
                            prix,
                            description,
                            date_mouvement
                        )
                        VALUES
                        (
                            ?,
                            ?,
                            'ENTREE',
                            ?,
                            ?,
                            ?,
                            ?
                        )"
                    );

                    if (!$st) {
                        throw new Exception(
                            $conn->error
                        );
                    }

                    $st->bind_param(
                        'iiidss',
                        $mid,
                        $pid,
                        $q,
                        $p,
                        $desc,
                        $date
                    );

                    if (!$st->execute()) {
                        throw new Exception(
                            $st->error
                        );
                    }

                    $st->close();

                    qexec(
                        $conn,
                        "UPDATE produits
                         SET
                            stock = stock + $q,
                            prix_achat = " . (float)$p . "
                         WHERE id=$pid"
                    );
                }

                $conn->commit();

                flash(
                    'ok',
                    'Facture ' . $ref .
                    ' enregistrée avec succès.'
                    . (
                        $oldPaid > 0
                        ? ' Paiement conservé : '
                          . money($oldPaid) . '.'
                        : ''
                    )
                );

            } catch (Throwable $e) {

                $conn->rollback();
                throw $e;
            }

            header(
                'Location: produits.php?facture='
                . urlencode($ref)
            );

            exit;
        }


        /* =========================
           RÈGLEMENT
        ========================= */

        if (isset($_POST['regler_facture'])) {

            $ref = cleanText(
                $_POST['ref'] ?? ''
            );

            $montant = (float)(
                $_POST['montant'] ?? 0
            );

            $rows = invoiceRows(
                $conn,
                $ref
            );

            if (!$rows) {
                throw new Exception(
                    'Facture introuvable.'
                );
            }

            $meta = parseMeta(
                $rows[0]['description']
            );

            $total = 0;

            foreach ($rows as $r) {
                $total +=
                    (float)$r['quantite']
                    *
                    (float)$r['prix'];
            }

            $oldPaid = (float)(
                $meta['PAYE'] ?? 0
            );

            $newPaid =
                $oldPaid + $montant;

            if (
                $montant <= 0 ||
                $newPaid > $total + 0.01
            ) {
                throw new Exception(
                    'Montant de règlement invalide.'
                );
            }

            $rest = max(
                0,
                $total - $newPaid
            );

            $safe = $conn->real_escape_string(
                $ref
            );

            $fourn = cleanText(
                $meta['FOURNISSEUR'] ?? ''
            );

            $newDesc =
                'FACTURE=' . $safe .
                '|FOURNISSEUR=' .
                $conn->real_escape_string($fourn) .
                '|PAYE=' . $newPaid .
                '|RESTE=' . $rest .
                '|ACHAT';

            qexec(
                $conn,
                "UPDATE mouvements
                 SET description='"
                . $conn->real_escape_string($newDesc)
                . "'
                 WHERE type='ENTREE'
                 AND (
                    description LIKE '%FACTURE=$safe|%'
                    OR description LIKE '%Facture : $safe%'
                 )"
            );

            flash(
                'ok',
                'Règlement enregistré.'
            );

            header(
                'Location: produits.php?facture='
                . urlencode($ref)
            );

            exit;
        }


        /* =========================
           SUPPRESSION
        ========================= */

        if (isset($_POST['supprimer_facture'])) {

            $ref = cleanText(
                $_POST['ref'] ?? ''
            );

            $rows = invoiceRows(
                $conn,
                $ref
            );

            if (!$rows) {
                throw new Exception(
                    'Facture introuvable.'
                );
            }

            $meta = parseMeta(
                $rows[0]['description']
            );

            if (
                (float)($meta['PAYE'] ?? 0) > 0
            ) {
                throw new Exception(
                    'Impossible de supprimer une facture ayant un paiement.'
                );
            }

            $conn->begin_transaction();

            try {

                foreach ($rows as $r) {

                    qexec(
                        $conn,
                        "UPDATE produits
                         SET stock = stock - "
                        . (int)$r['quantite'] .
                        " WHERE id="
                        . (int)$r['produit_id']
                    );
                }

                $safe = $conn->real_escape_string(
                    $ref
                );

                qexec(
                    $conn,
                    "DELETE FROM mouvements
                     WHERE type='ENTREE'
                     AND (
                        description LIKE '%FACTURE=$safe|%'
                        OR description LIKE '%Facture : $safe%'
                     )"
                );

                $conn->commit();

            } catch (Throwable $e) {

                $conn->rollback();
                throw $e;
            }

            flash(
                'ok',
                'Facture supprimée.'
            );

            header(
                'Location: produits.php'
            );

            exit;
        }

    } catch (Throwable $e) {

        flash(
            'err',
            $e->getMessage()
        );

        header(
            'Location: produits.php'
        );

        exit;
    }
}


/* =========================================================
   DONNÉES
========================================================= */

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$editRef = cleanText(
    $_GET['modifier'] ?? ''
);

$selectedRef = cleanText(
    $_GET['facture'] ?? ''
);

$editRows = $editRef
    ? invoiceRows($conn, $editRef)
    : [];

$detailRows = $selectedRef
    ? invoiceRows($conn, $selectedRef)
    : [];


/* =========================================================
   FACTURES
========================================================= */

$factures = [];

$q = $conn->query(
    "SELECT m.*, p.nom
     FROM mouvements m
     LEFT JOIN produits p
        ON p.id=m.produit_id
     WHERE m.type='ENTREE'
     ORDER BY m.id DESC"
);

while (
    $q &&
    ($r = $q->fetch_assoc())
) {

    $m = parseMeta(
        $r['description']
    );

    $ref = $m['FACTURE']
        ?? ('ANCIEN-' . $r['id']);

    if (!isset($factures[$ref])) {

        $factures[$ref] = [
            'ref'         => $ref,
            'fournisseur' => $m['FOURNISSEUR']
                ?? 'Fournisseur non renseigné',
            'date'        => $r['date_mouvement']
                ?? '',
            'total'       => 0,
            'paye'        => (float)(
                $m['PAYE'] ?? 0
            ),
            'articles'    => 0
        ];
    }

    $factures[$ref]['total'] +=
        (float)$r['quantite']
        *
        (float)$r['prix'];

    $factures[$ref]['articles']++;

    if (
        empty($factures[$ref]['date'])
    ) {
        $factures[$ref]['date'] =
            $r['date_mouvement'] ?? '';
    }
}


/* =========================================================
   PRODUITS / STOCK
========================================================= */

$products = [];

$q = $conn->query(
    "SELECT
        id,
        nom,
        categorie,
        prix_achat,
        stock
     FROM produits
     ORDER BY nom ASC"
);

while (
    $q &&
    ($r = $q->fetch_assoc())
) {
    $products[] = $r;
}

$stockValue = 0;
$totalStock = 0;

foreach ($products as $p) {

    $totalStock += (int)$p['stock'];

    $stockValue +=
        (int)$p['stock']
        *
        (float)$p['prix_achat'];
}

?>

<!doctype html>
<html lang="fr">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width,initial-scale=1"
    >

    <title>
        Achats & Fournisseurs — LAMBEMAH
    </title>

    <style>

        :root {
            --navy: #092746;
            --navy2: #103b62;
            --blue: #1478e5;
            --blue2: #1b87ef;
            --gold: #c59a3d;
            --bg: #edf4fa;
            --white: #ffffff;
            --text: #162b40;
            --muted: #6e8092;
            --line: #dce6ef;
            --green: #079669;
            --red: #dc3f4b;
            --shadow: 0 10px 35px rgba(9,39,70,.08);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            font-family: Arial, sans-serif;

            background:
                radial-gradient(
                    circle at 85% 10%,
                    rgba(20,120,229,.12),
                    transparent 30%
                ),
                radial-gradient(
                    circle at 10% 90%,
                    rgba(197,154,61,.08),
                    transparent 28%
                ),
                linear-gradient(
                    135deg,
                    #f7fbff,
                    #e9f1f8
                );
        }

        .layout {
            min-height: 100vh;
        }

        /* =========================
           MENU PC
        ========================= */

        .side {
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;

            width: 235px;

            padding: 25px 15px;

            color: #fff;

            background:
                linear-gradient(
                    180deg,
                    #092746 0%,
                    #0c3152 100%
                );

            box-shadow:
                8px 0 30px rgba(9,39,70,.10);
        }

        .side .brand {
            font-size: 22px;
            font-weight: 900;
            letter-spacing: .3px;
        }

        .side .sub {
            color: #b9d2e8;
            font-size: 10px;
            margin-top: 5px;
        }

        .nav {
            margin-top: 32px;
        }

        .nav a {
            display: block;

            color: #eaf4ff;
            text-decoration: none;

            padding: 10px 12px;
            margin: 5px 0;

            border-radius: 10px;

            font-size: 13px;
            font-weight: 600;
        }

        .nav a:hover,
        .nav a.active {
            background: var(--blue);
            color: #fff;
        }

        /* =========================
           CONTENU
        ========================= */

        .main {
            margin-left: 235px;

            width: calc(100% - 235px);

            padding: 24px;

            max-width: 1550px;
        }

        .mobile {
            display: none;
        }

        .head {
            display: flex;
            justify-content: space-between;
            align-items: center;

            gap: 15px;
        }

        .head h1 {
            margin: 0;

            color: var(--navy);

            font-size: 22px;
            font-weight: 900;
        }

        .head p {
            margin: 5px 0 0;

            color: var(--muted);

            font-size: 12px;
        }

        /* =========================
           BOUTONS
        ========================= */

        .btn {
            border: 0;

            border-radius: 9px;

            padding: 9px 13px;

            background:
                linear-gradient(
                    135deg,
                    var(--blue),
                    var(--blue2)
                );

            color: #fff;

            font-weight: 800;
            font-size: 12px;

            cursor: pointer;

            text-decoration: none;

            display: inline-block;

            box-shadow:
                0 5px 15px rgba(20,120,229,.15);
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn.secondary {
            background: #e7f0f8;
            color: var(--navy);
            box-shadow: none;
        }

        .btn.green {
            background: var(--green);
        }

        .btn.danger {
            background: #fff0f1;
            color: #b52634;
            box-shadow: none;
        }

        /* =========================
           MESSAGE
        ========================= */

        .flash {
            margin-top: 13px;

            padding: 10px 12px;

            border-radius: 9px;

            font-size: 12px;
            font-weight: 700;
        }

        .flash.ok {
            background: #e5f8f1;
            color: #087454;
        }

        .flash.err {
            background: #fff0f1;
            color: #a52130;
        }

        /* =========================
           STATS
        ========================= */

        .stats {
            display: grid;

            grid-template-columns:
                repeat(3, 1fr);

            gap: 12px;

            margin-top: 15px;
        }

        .stat {
            background: rgba(255,255,255,.95);

            border: 1px solid var(--line);

            border-radius: 13px;

            padding: 13px 15px;

            color: var(--muted);

            box-shadow: var(--shadow);

            font-size: 11px;
        }

        .stat b {
            display: block;

            margin-top: 5px;

            color: var(--navy);

            font-size: 19px;
        }

        /* =========================
           CARTES
        ========================= */

        .card {
            margin-top: 15px;

            background: rgba(255,255,255,.96);

            border: 1px solid var(--line);

            border-radius: 14px;

            padding: 16px;

            box-shadow: var(--shadow);
        }

        .card h2 {
            margin: 0 0 12px;

            color: var(--navy);

            font-size: 16px;
        }

        .card h3 {
            color: var(--navy);

            font-size: 13px;

            margin: 16px 0 9px;
        }

        .card p {
            color: var(--muted);

            font-size: 11px;

            margin: 4px 0 10px;
        }

        /* =========================
           FORMULAIRES
        ========================= */

        .formgrid {
            display: grid;

            grid-template-columns:
                1.4fr 1fr;

            gap: 10px;
        }

        .field label {
            display: block;

            margin-bottom: 4px;

            font-size: 11px;

            font-weight: 800;

            color: var(--navy);
        }

        .field input,
        .field select {
            width: 100%;

            padding: 9px 10px;

            border:
                1px solid #cfdce8;

            border-radius: 8px;

            background: #fff;

            color: var(--text);

            font-size: 12px;

            outline: none;
        }

        .field input:focus,
        .field select:focus {
            border-color: var(--blue);

            box-shadow:
                0 0 0 3px
                rgba(20,120,229,.10);
        }

        .line {
            display: grid;

            grid-template-columns:
                2.2fr .8fr 1fr 1fr auto;

            gap: 7px;

            align-items: end;

            margin-bottom: 8px;
        }

        .totalbox {
            text-align: right;

            margin-top: 12px;

            color: var(--navy);

            font-size: 16px;

            font-weight: 900;
        }

        /* =========================
           TABLEAUX
        ========================= */

        .tablewrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;

            border-collapse: collapse;

            min-width: 650px;
        }

        th,
        td {
            padding: 9px 8px;

            border-bottom:
                1px solid var(--line);

            text-align: left;

            font-size: 11px;
        }

        th {
            background: #f3f7fb;

            color: #63778b;

            font-size: 9px;

            text-transform: uppercase;

            letter-spacing: .3px;
        }

        td {
            color: #31465b;
        }

        td b {
            color: var(--navy);
        }

        .money {
            color: var(--navy);

            font-weight: 800;
        }

        /* =========================
           ACTIONS
        ========================= */

        .actions {
            display: flex;

            flex-wrap: wrap;

            gap: 6px;

            margin-top: 7px;
        }

        /* =========================
           DÉTAIL FACTURE
        ========================= */

        .detailhead {
            display: flex;

            justify-content: space-between;

            align-items: flex-start;

            gap: 15px;
        }

        .paybox {
            margin-top: 13px;

            padding: 13px;

            border:
                1px solid #d5e5f3;

            border-radius: 11px;

            background: #f1f7fc;
        }

        .paygrid {
            display: grid;

            grid-template-columns:
                repeat(3,1fr);

            gap: 10px;
        }

        .paygrid small {
            color: #708398;

            font-size: 10px;
        }

        .paygrid b {
            display: inline-block;

            margin-top: 3px;

            color: var(--navy);

            font-size: 15px;
        }

        /* =========================
           MOBILE
        ========================= */

        @media(max-width:850px) {

            .side {
                display: none;
            }

            .main {
                margin: 0;

                width: 100%;

                padding:
                    12px 10px 25px;
            }

            .mobile {
                display: block;

                margin-bottom: 8px;
            }

            .mobile .btn {
                padding: 7px 10px;

                font-size: 10px;
            }

            .head {
                display: block;
            }

            .head h1 {
                font-size: 18px;
            }

            .head p {
                font-size: 10px;
            }

            .head > .btn {
                margin-top: 8px;

                padding: 8px 10px;

                font-size: 10px;
            }

            .stats {
                grid-template-columns:
                    repeat(3,1fr);

                gap: 7px;

                margin-top: 10px;
            }

            .stat {
                padding: 9px 7px;

                border-radius: 10px;

                font-size: 9px;

                text-align: center;
            }

            .stat b {
                font-size: 14px;

                margin-top: 3px;
            }

            .card {
                padding: 11px;

                margin-top: 10px;

                border-radius: 11px;
            }

            .card h2 {
                font-size: 14px;

                margin-bottom: 8px;
            }

            .card h3 {
                font-size: 12px;

                margin: 11px 0 7px;
            }

            .card p {
                font-size: 9px;
            }

            .formgrid {
                grid-template-columns: 1fr;
            }

            .line {
                grid-template-columns:
                    1fr 1fr;
            }

            .line .wide {
                grid-column: 1 / -1;
            }

            .line .amount {
                grid-column: 1 / -1;
            }

            .line > .btn {
                font-size: 10px;

                padding: 8px;
            }

            .field label {
                font-size: 9px;
            }

            .field input,
            .field select {
                padding: 8px;

                font-size: 10px;

                border-radius: 7px;
            }

            .btn {
                padding: 7px 9px;

                font-size: 10px;

                border-radius: 8px;
            }

            table {
                min-width: 600px;
            }

            th,
            td {
                padding: 7px 6px;

                font-size: 9px;
            }

            th {
                font-size: 8px;
            }

            .detailhead {
                display: block;
            }

            .paygrid {
                grid-template-columns:
                    repeat(3,1fr);

                gap: 6px;
            }

            .paygrid small {
                font-size: 8px;
            }

            .paygrid b {
                font-size: 12px;
            }

            .totalbox {
                font-size: 14px;
            }
        }

    </style>

</head>

<body>

<div class="layout">

    <!-- MENU PC -->

    <aside class="side">

        <div class="brand">
            LAMBEMAH
        </div>

        <div class="sub">
            GESTION • PRESTATION
        </div>

        <nav class="nav">

            <a href="index.php">
                🏠 Accueil
            </a>

            <a
                class="active"
                href="produits.php"
            >
                📦 Achats / Fournisseurs
            </a>

            <a href="ventes.php">
                💰 Ventes / Clients
            </a>

            <a href="prestations.php">
                🖨️ Prestations
            </a>

            <a href="recettes.php">
                💵 Recettes
            </a>

            <a href="depenses.php">
                💸 Dépenses
            </a>

            <a href="statistiques.php">
                📊 Statistiques
            </a>

            <a href="utilisateurs.php">
                👥 Équipe
            </a>

            <a href="index.php?logout=1">
                🚪 Déconnexion
            </a>

        </nav>

    </aside>


    <!-- CONTENU -->

    <main class="main">

        <div class="mobile">
            <a
                class="btn secondary"
                href="index.php"
            >
                ☰ Menu
            </a>
        </div>


        <div class="head">

            <div>

                <h1>
                    📦 Achats & Fournisseurs
                </h1>

                <p>
                    Factures, paiements et stock.
                </p>

            </div>

            <a
                class="btn"
                href="?nouvel_achat=1"
            >
                ＋ Nouvelle facture
            </a>

        </div>


        <?php if ($flash): ?>

            <div class="flash <?=h($flash['type'])?>">
                <?=h($flash['msg'])?>
            </div>

        <?php endif; ?>


        <!-- STATS -->

        <div class="stats">

            <div class="stat">
                Factures
                <b><?=count($factures)?></b>
            </div>

            <div class="stat">
                Stock
                <b>
                    <?=number_format(
                        $totalStock,
                        0,
                        ',',
                        ' '
                    )?>
                </b>
            </div>

            <div class="stat">
                Valeur stock
                <b>
                    <?=money($stockValue)?>
                </b>
            </div>

        </div>


        <!-- NOUVEL ACHAT / MODIFICATION -->

        <?php if (
            isset($_GET['nouvel_achat'])
            || $editRows
        ): ?>

            <div class="card">

                <h2>
                    <?=$editRows
                        ? '✏️ Modifier ' . h($editRef)
                        : '＋ Nouvelle facture d’achat'
                    ?>
                </h2>

                <form method="post">

                    <input
                        type="hidden"
                        name="save_achat"
                        value="1"
                    >

                    <input
                        type="hidden"
                        name="edit_ref"
                        value="<?=h($editRef)?>"
                    >


                    <div class="formgrid">

                        <div class="field">

                            <label>
                                Fournisseur
                            </label>

                            <input
                                required
                                name="fournisseur"
                                value="<?=h(
                                    $editRows
                                    ? (
                                        parseMeta(
                                            $editRows[0]['description']
                                        )['FOURNISSEUR']
                                        ?? ''
                                    )
                                    : ''
                                )?>"
                                placeholder="Nom du fournisseur"
                            >

                        </div>


                        <div class="field">

                            <label>
                                Référence
                            </label>

                            <input
                                readonly
                                value="<?=h(
                                    $editRef
                                    ?: 'Automatique'
                                )?>"
                            >

                        </div>

                    </div>


                    <h3>
                        Articles
                    </h3>


                    <div id="lines">

                        <?php

                        $base = $editRows ?: [
                            [
                                'produit_id' => '',
                                'quantite'   => 1,
                                'prix'       => ''
                            ]
                        ];

                        foreach ($base as $r):

                        ?>

                            <div class="line">

                                <div class="field wide">

                                    <label>
                                        Article
                                    </label>

                                    <select
                                        name="produit_id[]"
                                        required
                                    >

                                        <option value="">
                                            Choisir
                                        </option>

                                        <?php foreach ($products as $p): ?>

                                            <option
                                                value="<?=$p['id']?>"
                                                <?=(
                                                    (int)$p['id']
                                                    ===
                                                    (int)(
                                                        $r['produit_id']
                                                        ?? 0
                                                    )
                                                )
                                                    ? 'selected'
                                                    : ''
                                                ?>
                                            >

                                                <?=h($p['nom'])?>

                                                —
                                                stock
                                                <?=$p['stock']?>

                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>


                                <div class="field">

                                    <label>
                                        Quantité
                                    </label>

                                    <input
                                        type="number"
                                        min="1"
                                        name="quantite[]"
                                        value="<?=h(
                                            $r['quantite']
                                            ?? 1
                                        )?>"
                                        required
                                    >

                                </div>


                                <div class="field">

                                    <label>
                                        Prix achat
                                    </label>

                                    <input
                                        type="number"
                                        min="0"
                                        name="prix[]"
                                        value="<?=h(
                                            $r['prix']
                                            ?? ''
                                        )?>"
                                        required
                                    >

                                </div>


                                <div class="field amount">

                                    <label>
                                        Montant
                                    </label>

                                    <input
                                        readonly
                                        class="lineTotal"
                                        value="0 FG"
                                    >

                                </div>


                                <button
                                    type="button"
                                    class="btn danger"
                                    onclick="
                                        this.parentElement.remove();
                                        calc();
                                    "
                                >
                                    ×
                                </button>

                            </div>

                        <?php endforeach; ?>

                    </div>


                    <button
                        type="button"
                        class="btn secondary"
                        onclick="addLine()"
                    >
                        ＋ Ajouter une ligne
                    </button>


                    <div class="totalbox">

                        Total :
                        <span id="grand">
                            0 FG
                        </span>

                    </div>


                    <br>


                    <button
                        class="btn green"
                        type="submit"
                    >
                        💾 Enregistrer
                    </button>


                    <a
                        class="btn secondary"
                        href="produits.php"
                    >
                        Annuler
                    </a>

                </form>

            </div>

        <?php endif; ?>


        <!-- DÉTAIL FACTURE -->

        <?php if ($detailRows): ?>

            <?php

            $meta = parseMeta(
                $detailRows[0]['description']
            );

            $total = 0;

            foreach ($detailRows as $r) {
                $total +=
                    (float)$r['quantite']
                    *
                    (float)$r['prix'];
            }

            $paid = (float)(
                $meta['PAYE'] ?? 0
            );

            $rest = max(
                0,
                $total - $paid
            );

            ?>

            <div class="card">

                <div class="detailhead">

                    <div>

                        <h2>
                            📄 Facture
                            <?=h($selectedRef)?>
                        </h2>

                        <p>
                            <b>Fournisseur :</b>
                            <?=h(
                                $meta['FOURNISSEUR']
                                ?? '—'
                            )?>
                        </p>

                    </div>


                    <div class="actions">

                        <a
                            class="btn secondary"
                            href="?imprimer=<?=urlencode(
                                $selectedRef
                            )?>"
                            target="_blank"
                        >
                            🖨️ Imprimer
                        </a>


                        <?php if ($rest > 0): ?>

                            <a
                                class="btn secondary"
                                href="?modifier=<?=urlencode(
                                    $selectedRef
                                )?>"
                            >
                                ✏️ Modifier
                            </a>

                        <?php endif; ?>


                        <?php if ($paid <= 0): ?>

                            <form
                                method="post"
                                onsubmit="
                                    return confirm(
                                        'Supprimer toute cette facture ?'
                                    );
                                "
                            >

                                <input
                                    type="hidden"
                                    name="supprimer_facture"
                                    value="1"
                                >

                                <input
                                    type="hidden"
                                    name="ref"
                                    value="<?=h($selectedRef)?>"
                                >

                                <button
                                    class="btn danger"
                                    type="submit"
                                >
                                    🗑️ Supprimer
                                </button>

                            </form>

                        <?php endif; ?>

                    </div>

                </div>


                <div class="tablewrap">

                    <table>

                        <thead>

                            <tr>

                                <th>
                                    Désignation
                                </th>

                                <th>
                                    Qté
                                </th>

                                <th>
                                    Prix achat
                                </th>

                                <th>
                                    Montant
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php foreach ($detailRows as $r): ?>

                                <tr>

                                    <td>
                                        <?=h(
                                            $r['nom']
                                            ?? 'Article'
                                        )?>
                                    </td>

                                    <td>
                                        <?=h(
                                            $r['quantite']
                                        )?>
                                    </td>

                                    <td>
                                        <?=money(
                                            $r['prix']
                                        )?>
                                    </td>

                                    <td class="money">
                                        <?=money(
                                            (float)$r['quantite']
                                            *
                                            (float)$r['prix']
                                        )?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>


                <!-- PAIEMENT -->

                <div class="paybox">

                    <div class="paygrid">

                        <div>

                            <small>
                                Total
                            </small>

                            <br>

                            <b>
                                <?=money($total)?>
                            </b>

                        </div>


                        <div>

                            <small>
                                Payé
                            </small>

                            <br>

                            <b>
                                <?=money($paid)?>
                            </b>

                        </div>


                        <div>

                            <small>
                                Reste
                            </small>

                            <br>

                            <b>
                                <?=money($rest)?>
                            </b>

                        </div>

                    </div>


                    <?php if ($rest > 0): ?>

                        <form
                            method="post"
                            class="formgrid"
                            style="margin-top:12px"
                        >

                            <input
                                type="hidden"
                                name="regler_facture"
                                value="1"
                            >

                            <input
                                type="hidden"
                                name="ref"
                                value="<?=h($selectedRef)?>"
                            >


                            <div class="field">

                                <label>
                                    Montant à régler
                                </label>

                                <input
                                    type="number"
                                    min="1"
                                    max="<?=h($rest)?>"
                                    step="1"
                                    name="montant"
                                    required
                                >

                            </div>


                            <div>

                                <button
                                    class="btn green"
                                    type="submit"
                                >
                                    💰 Régler la facture
                                </button>

                            </div>

                        </form>

                    <?php endif; ?>

                </div>

            </div>

        <?php endif; ?>


        <!-- LISTE FACTURES -->

        <div class="card">

            <h2>
                📋 Fournisseurs & factures
            </h2>

            <p>
                Ouvrez une facture pour voir ses lignes
                et gérer son règlement.
            </p>


            <div class="tablewrap">

                <table>

                    <thead>

                        <tr>

                            <th>
                                Fournisseur
                            </th>

                            <th>
                                Facture
                            </th>

                            <th>
                                Date
                            </th>

                            <th>
                                Articles
                            </th>

                            <th>
                                Total
                            </th>

                            <th>
                                Payé
                            </th>

                            <th>
                                Reste
                            </th>

                            <th>
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php foreach ($factures as $f): ?>

                            <?php
                            $rest =
                                max(
                                    0,
                                    $f['total']
                                    -
                                    $f['paye']
                                );
                            ?>

                            <tr>

                                <td>
                                    <b>
                                        <?=h(
                                            $f['fournisseur']
                                        )?>
                                    </b>
                                </td>

                                <td>
                                    <?=h(
                                        $f['ref']
                                    )?>
                                </td>

                                <td>
                                    <?=h(
                                        $f['date']
                                        ? date(
                                            'd/m/Y',
                                            strtotime(
                                                $f['date']
                                            )
                                        )
                                        : '—'
                                    )?>
                                </td>

                                <td>
                                    <?=h(
                                        $f['articles']
                                    )?>
                                </td>

                                <td class="money">
                                    <?=money(
                                        $f['total']
                                    )?>
                                </td>

                                <td>
                                    <?=money(
                                        $f['paye']
                                    )?>
                                </td>

                                <td>
                                    <?=money(
                                        $rest
                                    )?>
                                </td>

                                <td>

                                    <a
                                        class="btn"
                                        href="?facture=<?=urlencode(
                                            $f['ref']
                                        )?>"
                                    >
                                        Ouvrir
                                    </a>

                                </td>

                            </tr>

                        <?php endforeach; ?>


                        <?php if (!$factures): ?>

                            <tr>

                                <td colspan="8">
                                    Aucune facture
                                    d'achat pour le moment.
                                </td>

                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>


        <!-- STOCK -->

        <div class="card">

            <h2>
                📦 Stock actuel
            </h2>


            <div class="tablewrap">

                <table>

                    <thead>

                        <tr>

                            <th>
                                Article
                            </th>

                            <th>
                                Catégorie
                            </th>

                            <th>
                                Prix achat
                            </th>

                            <th>
                                Stock
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php foreach ($products as $p): ?>

                            <tr>

                                <td>
                                    <?=h(
                                        $p['nom']
                                    )?>
                                </td>

                                <td>
                                    <?=h(
                                        $p['categorie']
                                    )?>
                                </td>

                                <td>
                                    <?=money(
                                        $p['prix_achat']
                                    )?>
                                </td>

                                <td>
                                    <b>
                                        <?=h(
                                            $p['stock']
                                        )?>
                                    </b>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </main>

</div>


<script>

function fmt(n) {

    return new Intl.NumberFormat(
        'fr-FR'
    ).format(
        Math.round(n)
    ) + ' FG';
}


function calc() {

    let total = 0;

    document
        .querySelectorAll(
            '#lines .line'
        )
        .forEach(line => {

            const q =
                parseFloat(
                    line.querySelector(
                        '[name="quantite[]"]'
                    )?.value || 0
                );

            const p =
                parseFloat(
                    line.querySelector(
                        '[name="prix[]"]'
                    )?.value || 0
                );

            const montant =
                q * p;

            total += montant;

            const result =
                line.querySelector(
                    '.lineTotal'
                );

            if (result) {
                result.value =
                    fmt(montant);
            }
        });


    const grand =
        document.getElementById(
            'grand'
        );

    if (grand) {
        grand.textContent =
            fmt(total);
    }
}


function addLine() {

    const box =
        document.getElementById(
            'lines'
        );

    const first =
        box.querySelector(
            '.line'
        );

    if (!first) {
        return;
    }

    const clone =
        first.cloneNode(true);


    clone
        .querySelectorAll('input')
        .forEach(input => {

            if (
                input.name ===
                'quantite[]'
            ) {
                input.value = 1;

            } else if (
                input.name ===
                'prix[]'
            ) {
                input.value = '';

            } else if (
                input.classList.contains(
                    'lineTotal'
                )
            ) {
                input.value = '0 FG';
            }
        });


    const select =
        clone.querySelector(
            'select'
        );

    if (select) {
        select.selectedIndex = 0;
    }


    box.appendChild(clone);

    calc();
}


document.addEventListener(
    'input',
    calc
);

calc();

</script>

</body>
</html>
