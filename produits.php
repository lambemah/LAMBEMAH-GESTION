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
