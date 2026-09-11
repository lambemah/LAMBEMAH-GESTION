<?php
session_start();

require_once __DIR__ . '/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Connexion à la base de données impossible.');
}

$conn->set_charset('utf8mb4');


/* =========================================================
   OUTILS
   ========================================================= */

function h($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function money($v)
{
    return number_format((float)$v, 0, ',', ' ') . ' FG';
}

function cleanText($v)
{
    return trim(preg_replace('/[|\r\n]+/', ' ', (string)$v));
}

function nextId(mysqli $conn, string $table): int
{
    $allowed = ['produits', 'mouvements', 'ventes'];

    if (!in_array($table, $allowed, true)) {
        return 1;
    }

    $r = $conn->query(
        "SELECT COALESCE(MAX(id),0)+1 n FROM `$table`"
    );

    return (int)($r->fetch_assoc()['n'] ?? 1);
}

function flash($type, $msg)
{
    $_SESSION['flash'] = [
        'type' => $type,
        'msg'  => $msg
    ];
}


/*
 * Lecture des métadonnées nouvelles + anciennes.
 *
 * Nouveau :
 * FACTURE=VEN-...
 * CLIENT=...
 * PAYE=...
 * RESTE=...
 *
 * Ancien :
 * Facture : VEN-...
 * Client : ...
 * Payé : ...
 * Reste : ...
 */
function parseMeta(string $desc): array
{
    $o = [];

    foreach (explode('|', $desc) as $p) {

        $p = trim($p);

        if ($p === '') {
            continue;
        }

        if (strpos($p, '=') !== false) {

            [$k, $v] = explode('=', $p, 2);

            $k = strtoupper(trim($k));
            $v = trim($v);

            $o[$k] = $v;

            continue;
        }

        if (preg_match('/^facture\s*:\s*(.+)$/i', $p, $m)) {
            $o['FACTURE'] = trim($m[1]);
        }

        if (preg_match('/^client\s*:\s*(.+)$/i', $p, $m)) {
            $o['CLIENT'] = trim($m[1]);
        }

        if (preg_match('/^pay[ée]?\s*:\s*([0-9\.\s,]+)/iu', $p, $m)) {
            $o['PAYE'] = (float)str_replace(
                [' ', ','],
                ['', '.'],
                trim($m[1])
            );
        }

        if (preg_match('/^reste\s*:\s*([0-9\.\s,]+)/iu', $p, $m)) {
            $o['RESTE'] = (float)str_replace(
                [' ', ','],
                ['', '.'],
                trim($m[1])
            );
        }
    }

    return $o;
}


/*
 * Recherche toutes les lignes d'une facture.
 * Compatible avec les formats actuels et certaines anciennes factures.
 */
function invoiceRows(mysqli $conn, string $ref): array
{
    $rows = [];

    $safe = $conn->real_escape_string($ref);

    $sql = "
        SELECT
            v.*,
            p.nom,
            p.categorie
        FROM ventes v
        LEFT JOIN produits p
            ON p.id = v.produit_id
        WHERE
            v.description LIKE '%FACTURE=$safe|%'
            OR v.description LIKE '%FACTURE=$safe%'
            OR v.description LIKE '%Facture : $safe%'
        ORDER BY v.id ASC
    ";

    $q = $conn->query($sql);

    while ($q && ($r = $q->fetch_assoc())) {
        $rows[] = $r;
    }

    return $rows;
}


/*
 * Supprime les lignes de vente d'une facture.
 */
function deleteInvoiceRows(mysqli $conn, string $ref): void
{
    $safe = $conn->real_escape_string($ref);

    $conn->query("
        DELETE FROM ventes
        WHERE
            description LIKE '%FACTURE=$safe|%'
            OR description LIKE '%FACTURE=$safe%'
            OR description LIKE '%Facture : $safe%'
    ");
}


/*
 * Supprime les mouvements SORTIE liés à une facture.
 */
function deleteInvoiceMovements(mysqli $conn, string $ref): void
{
    $safe = $conn->real_escape_string($ref);

    $conn->query("
        DELETE FROM mouvements
        WHERE
            type = 'SORTIE'
            AND (
                description LIKE '%FACTURE=$safe|%'
                OR description LIKE '%FACTURE=$safe%'
                OR description LIKE '%Facture : $safe%'
            )
    ");
}


/*
 * Met à jour les descriptions des ventes d'une facture.
 */
function updateInvoiceMeta(
    mysqli $conn,
    string $ref,
    string $client,
    float $paid,
    float $rest
): void {

    $safeRef    = $conn->real_escape_string($ref);
    $safeClient = $conn->real_escape_string($client);

    $desc = "FACTURE=$safeRef|CLIENT=$safeClient|PAYE=$paid|RESTE=$rest|VENTE";

    $safeDesc = $conn->real_escape_string($desc);

    $conn->query("
        UPDATE ventes
        SET description = '$safeDesc'
        WHERE
            description LIKE '%FACTURE=$safeRef|%'
            OR description LIKE '%FACTURE=$safeRef%'
            OR description LIKE '%Facture : $safeRef%'
    ");
}


/*
 * Nombre en lettres.
 */
function numberWordsFr($n)
{
    $n = (int)round($n);

    if ($n === 0) {
        return 'zéro';
    }

    $u = [
        'zéro',
        'un',
        'deux',
        'trois',
        'quatre',
        'cinq',
        'six',
        'sept',
        'huit',
        'neuf',
        'dix',
        'onze',
        'douze',
        'treize',
        'quatorze',
        'quinze',
        'seize'
    ];

    $tens = [
        20 => 'vingt',
        30 => 'trente',
        40 => 'quarante',
        50 => 'cinquante',
        60 => 'soixante'
    ];

    $under100 = function ($x) use (&$under100, $u, $tens) {

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

            return $r
                ? $w . '-' . numberWordsFr($r)
                : $w;
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

    $under1000 = function ($x) use (&$under1000, $under100, $u) {

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
            $under1000($b)
            . ' milliard'
            . ($b > 1 ? 's' : '');
    }

    if ($n >= 1000000) {

        $m = intdiv($n, 1000000);
        $n %= 1000000;

        $parts[] =
            $under1000($m)
            . ' million'
            . ($m > 1 ? 's' : '');
    }

    if ($n >= 1000) {

        $k = intdiv($n, 1000);
        $n %= 1000;

        $parts[] =
            ($k === 1
                ? 'mille'
                : $under1000($k) . ' mille');
    }

    if ($n > 0) {
        $parts[] = $under1000($n);
    }

    return implode(' ', $parts);
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

        $total +=
            (float)$r['quantite']
            *
            (float)$r['prix_unitaire'];
    }

    $paid = (float)($meta['PAYE'] ?? 0);

    $rest = max(0, $total - $paid);

    $totalWords =
        ucfirst(numberWordsFr($total))
        . ' francs guinéens';

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
            <?=h($ref)?> • LAMBEMAH GESTION
        </title>

        <style>

        *{
            box-sizing:border-box;
        }

        body{
            margin:0;
            background:#edf3f9;
            color:#172b40;
            font-family:Arial,Helvetica,sans-serif;
            font-size:13px;
        }

        .printbar{
            max-width:920px;
            margin:15px auto;
            background:#fff;
            border:1px solid #d8e3ee;
            border-radius:12px;
            padding:10px 14px;
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
        }

        .printbar label{
            font-weight:700;
            color:#17345c;
        }

        .printbar button{
            border:0;
            border-radius:8px;
            padding:10px 15px;
            background:#1479e8;
            color:#fff;
            font-weight:700;
            cursor:pointer;
        }

        .paper{
            max-width:920px;
            min-height:1120px;
            margin:12px auto 25px;
            background:#fff;
            padding:35px 42px;
            box-shadow:0 6px 28px rgba(18,52,81,.10);
            border:1px solid #e1e9f2;
        }

        .head{
            display:grid;
            grid-template-columns:1fr 255px;
            gap:28px;
            align-items:start;
            border-bottom:2px solid #1479e8;
            padding-bottom:18px;
        }

        .brandline{
            display:flex;
            align-items:center;
            gap:14px;
        }

        .brandline img{
            width:78px;
            height:78px;
            object-fit:contain;
        }

        .brand{
            font-size:24px;
            font-weight:900;
            letter-spacing:.2px;
            color:#123b70;
        }

        .gold{
            font-size:12px;
            letter-spacing:1.5px;
            color:#b77905;
            font-weight:800;
            margin-top:3px;
        }

        .contact{
            font-size:11px;
            line-height:1.6;
            color:#64748b;
            margin-top:7px;
        }

        .factbox{
            background:#eaf3fc;
            border-radius:13px;
            padding:15px 17px;
            border-left:4px solid #1479e8;
        }

        .factbox .label{
            font-size:22px;
            font-weight:900;
            color:#123b70;
        }

        .factbox .ref{
            font-size:14px;
            font-weight:800;
            color:#17345c;
            margin-top:4px;
        }

        .factbox .date{
            font-size:11px;
            color:#66788d;
            margin-top:9px;
            line-height:1.55;
        }

        .info{
            display:grid;
            grid-template-columns:1.35fr .65fr;
            gap:18px;
            margin:18px 0;
        }

        .box{
            border:1px solid #dbe5ef;
            border-radius:11px;
            padding:13px 15px;
        }

        .box .boxtitle{
            font-size:11px;
            text-transform:uppercase;
            letter-spacing:.8px;
            color:#1479e8;
            font-weight:900;
            margin-bottom:5px;
        }

        .box b{
            font-size:14px;
            color:#17345c;
        }

        .table{
            width:100%;
            border-collapse:separate;
            border-spacing:0;
            overflow:hidden;
            border:1px solid #cfe0f0;
            border-radius:10px;
        }

        .table th{
            background:#1479e8;
            color:#fff;
            font-size:11px;
            padding:11px 9px;
            text-align:left;
        }

        .table td{
            padding:11px 9px;
            border-bottom:1px solid #e4edf5;
            color:#243b53;
        }

        .table tr:last-child td{
            border-bottom:0;
        }

        .num{
            text-align:right;
            white-space:nowrap;
        }

        .designation{
            font-weight:700;
        }

        .bottom{
            display:grid;
            grid-template-columns:1fr 300px;
            gap:24px;
            margin-top:20px;
            align-items:start;
        }

        .words{
            background:#f1f7fd;
            border:1px solid #d7e6f4;
            border-radius:12px;
            padding:15px;
        }

        .words .label{
            font-size:11px;
            color:#1479e8;
            font-weight:900;
            text-transform:uppercase;
            letter-spacing:.7px;
        }

        .words .value{
            font-size:15px;
            font-weight:800;
            color:#17345c;
            margin-top:7px;
            line-height:1.45;
        }

        .totals{
            border:1px solid #d7e6f4;
            border-radius:12px;
            overflow:hidden;
        }

        .trow{
            display:flex;
            justify-content:space-between;
            padding:10px 14px;
            border-bottom:1px solid #e4edf5;
        }

        .trow b{
            color:#17345c;
        }

        .grand{
            background:#eaf3fc;
            border-bottom:0;
            font-size:17px;
            font-weight:900;
        }

        .grand b{
            color:#123b70;
        }

        .signature{
            margin-top:58px;
            display:flex;
            justify-content:flex-end;
        }

        .sigbox{
            width:220px;
            text-align:center;
        }

        .sigbox .space{
            height:92px;
            display:flex;
            align-items:center;
            justify-content:center;
        }

        .sigbox img{
            max-width:180px;
            max-height:82px;
            object-fit:contain;
        }

        .sigline{
            border-top:1px solid #49627a;
            padding-top:7px;
            color:#17345c;
            font-weight:700;
        }

        .hidden{
            display:none !important;
        }

        .footer{
            margin-top:60px;
            padding-top:10px;
            border-top:1px solid #dbe5ef;
            text-align:center;
            color:#6b7d90;
            font-size:10px;
        }

        .footer strong{
            color:#17345c;
        }

        @media print{

            body{
                background:#fff;
            }

            .printbar{
                display:none;
            }

            .paper{
                margin:0;
                max-width:none;
                min-height:auto;
                border:0;
                box-shadow:none;
                padding:14mm 13mm;
            }

            .footer{
                margin-top:35px;
            }
        }

        @media(max-width:650px){

            .paper{
                margin:8px;
                padding:20px;
                min-height:auto;
            }

            .head{
                grid-template-columns:1fr;
            }

            .factbox{
                max-width:100%;
            }

            .info,
            .bottom{
                grid-template-columns:1fr;
            }

            .table{
                font-size:11px;
            }

            .table th,
            .table td{
                padding:8px 6px;
            }

            .brand{
                font-size:20px;
            }

            .brandline img{
                width:62px;
                height:62px;
            }

            .signature{
                margin-top:35px;
            }

            .printbar{
                margin:8px;
                flex-direction:column;
                align-items:stretch;
            }
        }

        </style>

    </head>

    <body>

        <div class="printbar">

            <label>
                <input
                    type="checkbox"
                    id="addSignature"
                >
                Ajouter la signature
            </label>

            <button
                type="button"
                onclick="window.print()"
            >
                🖨️ Imprimer / PDF
            </button>

        </div>


        <div class="paper">

            <div class="head">

                <div class="brandline">

                    <img
                        src="/assets/logo.png"
                        alt="LAMBEMAH"
                        onerror="this.style.display='none'"
                    >

                    <div>

                        <div class="brand">
                            LAMBEMAH GESTION
                        </div>

                        <div class="gold">
                            GESTION • PRESTATION • VENTE
                        </div>

                        <div class="contact">
                            +224 611 752 767 / 622 595 362<br>
                            konatelambetenin@gmail.com<br>
                            KM 36, Guinée
                        </div>

                    </div>

                </div>


                <div class="factbox">

                    <div class="label">
                        FACTURE
                    </div>

                    <div class="ref">
                        N° <?=h($ref)?>
                    </div>

                    <div class="date">

                        Date d'émission :
                        <?=h(
                            date(
                                'd/m/Y',
                                strtotime(
                                    $rows[0]['date_vente'] ?? 'now'
                                )
                            )
                        )?>

                        <br>

                        Heure :
                        <?=h(
                            date(
                                'H:i',
                                strtotime(
                                    $rows[0]['date_vente'] ?? 'now'
                                )
                            )
                        )?>

                    </div>

                </div>

            </div>


            <div class="info">

                <div class="box">

                    <div class="boxtitle">
                        Client
                    </div>

                    <b>
                        <?=h(
                            $meta['CLIENT']
                            ??
                            'Client comptant'
                        )?>
                    </b>

                </div>


                <div class="box">

                    <div class="boxtitle">
                        Paiement
                    </div>

                    <b>

                        <?=

                        $paid > 0

                        ? 'Avance / règlement enregistré'

                        : 'À régler'

                        ?>

                    </b>

                </div>

            </div>


            <table class="table">

                <thead>

                    <tr>

                        <th style="width:55%">
                            Désignation
                        </th>

                        <th class="num">
                            Qté
                        </th>

                        <th class="num">
                            Prix unitaire
                        </th>

                        <th class="num">
                            Montant
                        </th>

                    </tr>

                </thead>

                <tbody>

                <?php
                foreach ($rows as $r):

                    $m =
                        (float)$r['quantite']
                        *
                        (float)$r['prix_unitaire'];
                ?>

                    <tr>

                        <td class="designation">
                            <?=h(
                                $r['nom'] ?? 'Article'
                            )?>
                        </td>

                        <td class="num">
                            <?=h($r['quantite'])?>
                        </td>

                        <td class="num">
                            <?=money($r['prix_unitaire'])?>
                        </td>

                        <td class="num">
                            <?=money($m)?>
                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>


            <div class="bottom">

                <div class="words">

                    <div class="label">
                        Arrêtée à la somme de
                    </div>

                    <div class="value">
                        <?=h($totalWords)?>
                    </div>

                </div>


                <div class="totals">

                    <div class="trow">

                        <span>
                            Total
                        </span>

                        <b>
                            <?=money($total)?>
                        </b>

                    </div>


                    <div class="trow">

                        <span>
                            Déjà encaissé
                        </span>

                        <b>
                            <?=money($paid)?>
                        </b>

                    </div>


                    <div class="trow grand">

                        <span>
                            Reste
                        </span>

                        <b>
                            <?=money($rest)?>
                        </b>

                    </div>

                </div>

            </div>


            <div class="signature">

                <div class="sigbox">

                    <div class="space">

                        <img
                            id="signatureImg"
                            class="hidden"
                            src="/assets/signature.png"
                            alt="Signature"
                            onerror="this.style.display='none'"
                        >

                    </div>

                    <div class="sigline">
                        Responsable
                    </div>

                </div>

            </div>


            <div class="footer">

                <strong>
                    LAMBEMAH GESTION
                </strong>

                — Merci pour votre confiance.

            </div>

        </div>


        <script>

        const c =
            document.getElementById(
                'addSignature'
            );

        const img =
            document.getElementById(
                'signatureImg'
            );

        c.addEventListener(
            'change',
            () => {

                img.classList.toggle(
                    'hidden',
                    !c.checked
                );

            }
        );

        </script>

    </body>

    </html>

    <?php
    exit;
}


/* =========================================================
   ACTIONS POST
   ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        /* =====================================================
           NOUVELLE VENTE / MODIFICATION
           ===================================================== */

        if (isset($_POST['save_vente'])) {

            $client =
                cleanText(
                    $_POST['client']
                    ??
                    'Client comptant'
                );

            $editRef =
                cleanText(
                    $_POST['edit_ref']
                    ??
                    ''
                );

            $ids =
                $_POST['produit_id']
                ??
                [];

            $qtys =
                $_POST['quantite']
                ??
                [];

            $prices =
                $_POST['prix_unitaire']
                ??
                [];

            $lines = [];

            for (
                $i = 0;
                $i < count($ids);
                $i++
            ) {

                $pid =
                    (int)$ids[$i];

                $q =
                    (int)(
                        $qtys[$i]
                        ??
                        0
                    );

                $p =
                    (float)(
                        $prices[$i]
                        ??
                        0
                    );

                if (
                    $pid > 0
                    &&
                    $q > 0
                    &&
                    $p > 0
                ) {

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


            /* =================================================
               MODIFICATION D'UNE FACTURE
               ================================================= */

            if ($editRef !== '') {

                $old =
                    invoiceRows(
                        $conn,
                        $editRef
                    );

                if (!$old) {
                    throw new Exception(
                        'Facture à modifier introuvable.'
                    );
                }

                $oldMeta =
                    parseMeta(
                        $old[0]['description']
                    );

                $oldPaid =
                    (float)(
                        $oldMeta['PAYE']
                        ??
                        0
                    );


                /*
                 * Calcul du nouveau total AVANT modification.
                 */
                $newTotal = 0;

                foreach ($lines as $l) {

                    $newTotal +=
                        $l['q']
                        *
                        $l['p'];
                }


                /*
                 * Une facture totalement payée
                 * est verrouillée.
                 */
                $oldTotal = 0;

                foreach ($old as $r) {

                    $oldTotal +=
                        (float)$r['quantite']
                        *
                        (float)$r['prix_unitaire'];
                }

                $oldRest =
                    max(
                        0,
                        $oldTotal - $oldPaid
                    );


                if ($oldRest <= 0.01) {

                    throw new Exception(
                        'Cette facture est totalement payée et ne peut plus être modifiée.'
                    );
                }


                /*
                 * Le nouveau total ne doit jamais
                 * être inférieur à ce qui est déjà encaissé.
                 */
                if (
                    $newTotal
                    <
                    $oldPaid
                ) {

                    throw new Exception(
                        'Impossible de réduire cette facture sous le montant déjà encaissé (' .
                        money($oldPaid) .
                        ').'
                    );
                }


                $newRest =
                    max(
                        0,
                        $newTotal - $oldPaid
                    );


                /*
                 * TOUTE la modification dans une transaction.
                 */
                $conn->begin_transaction();

                try {

                    /*
                     * 1. Restaurer l'ancien stock.
                     */
                    foreach ($old as $r) {

                        $pid =
                            (int)$r['produit_id'];

                        $qOld =
                            (int)$r['quantite'];

                        $conn->query("
                            UPDATE produits
                            SET stock = stock + $qOld
                            WHERE id = $pid
                        ");
                    }


                    /*
                     * 2. Supprimer ancienne facture
                     *    + anciens mouvements.
                     */
                    deleteInvoiceRows(
                        $conn,
                        $editRef
                    );

                    deleteInvoiceMovements(
                        $conn,
                        $editRef
                    );


                    /*
                     * 3. Insérer les nouvelles lignes.
                     */
                    foreach ($lines as $l) {

                        $pid = $l['pid'];
                        $q   = $l['q'];
                        $p   = $l['p'];

                        $r =
                            $conn->query("
                                SELECT
                                    id,
                                    nom,
                                    stock
                                FROM produits
                                WHERE id = $pid
                                LIMIT 1
                            ");

                        if (
                            !$r
                            ||
                            !$r->num_rows
                        ) {

                            throw new Exception(
                                'Article introuvable.'
                            );
                        }

                        $prod =
                            $r->fetch_assoc();

                        if (
                            (int)$prod['stock']
                            <
                            $q
                        ) {

                            throw new Exception(
                                'Stock insuffisant pour ' .
                                $prod['nom'] .
                                ' (stock : ' .
                                $prod['stock'] .
                                ').'
                            );
                        }

                        $vid =
                            nextId(
                                $conn,
                                'ventes'
                            );

                        $mid =
                            nextId(
                                $conn,
                                'mouvements'
                            );

                        $date =
                            date(
                                'Y-m-d H:i:s'
                            );

                        $mont =
                            $q * $p;

                        $desc =
                            "FACTURE=$editRef|CLIENT=" .
                            cleanText($client) .
                            "|PAYE=$oldPaid|RESTE=$newRest|VENTE";

                        $stmt =
                            $conn->prepare("
                                INSERT INTO ventes
                                (
                                    id,
                                    produit_id,
                                    quantite,
                                    prix_unitaire,
                                    montant,
                                    description,
                                    date_vente
                                )
                                VALUES (?,?,?,?,?,?,?)
                            ");

                        if (!$stmt) {
                            throw new Exception(
                                'Erreur préparation vente.'
                            );
                        }

                        $stmt->bind_param(
                            'iiiddss',
                            $vid,
                            $pid,
                            $q,
                            $p,
                            $mont,
                            $desc,
                            $date
                        );

                        if (!$stmt->execute()) {

                            throw new Exception(
                                'Erreur lors de l’enregistrement de la vente.'
                            );
                        }

                        $stmt->close();


                        $md =
                            "FACTURE=$editRef|CLIENT=" .
                            cleanText($client) .
                            "|SORTIE VENTE";

                        $stmt2 =
                            $conn->prepare("
                                INSERT INTO mouvements
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
                                (?,?, 'SORTIE',?,?,?,?)
                            ");

                        if (!$stmt2) {
                            throw new Exception(
                                'Erreur préparation mouvement.'
                            );
                        }

                        $stmt2->bind_param(
                            'iiidss',
                            $mid,
                            $pid,
                            $q,
                            $p,
                            $md,
                            $date
                        );

                        if (!$stmt2->execute()) {

                            throw new Exception(
                                'Erreur lors de l’enregistrement du mouvement.'
                            );
                        }

                        $stmt2->close();


                        $conn->query("
                            UPDATE produits
                            SET stock = stock - $q
                            WHERE id = $pid
                        ");
                    }


                    $conn->commit();

                    flash(
                        'ok',
                        'Facture ' .
                        $editRef .
                        ' modifiée avec succès.'
                    );

                } catch (Throwable $e) {

                    $conn->rollback();

                    throw $e;
                }


                header(
                    'Location: ventes.php?facture=' .
                    urlencode($editRef)
                );

                exit;
            }


            /* =================================================
               NOUVELLE FACTURE
               ================================================= */

            $ref =
                'VEN-' .
                date('Ymd-His') .
                '-' .
                nextId(
                    $conn,
                    'ventes'
                );

            $conn->begin_transaction();

            try {

                foreach ($lines as $l) {

                    $pid = $l['pid'];
                    $q   = $l['q'];
                    $p   = $l['p'];

                    $r =
                        $conn->query("
                            SELECT
                                id,
                                nom,
                                stock
                            FROM produits
                            WHERE id = $pid
                            LIMIT 1
                        ");

                    if (
                        !$r
                        ||
                        !$r->num_rows
                    ) {

                        throw new Exception(
                            'Article introuvable.'
                        );
                    }

                    $prod =
                        $r->fetch_assoc();

                    if (
                        (int)$prod['stock']
                        <
                        $q
                    ) {

                        throw new Exception(
                            'Stock insuffisant pour ' .
                            $prod['nom'] .
                            ' (stock : ' .
                            $prod['stock'] .
                            ').'
                        );
                    }

                    $vid =
                        nextId(
                            $conn,
                            'ventes'
                        );

                    $mid =
                        nextId(
                            $conn,
                            'mouvements'
                        );

                    $date =
                        date(
                            'Y-m-d H:i:s'
                        );

                    $mont =
                        $q * $p;

                    $desc =
                        "FACTURE=$ref|CLIENT=" .
                        cleanText($client) .
                        "|PAYE=0|RESTE=0|VENTE";

                    $stmt =
                        $conn->prepare("
                            INSERT INTO ventes
                            (
                                id,
                                produit_id,
                                quantite,
                                prix_unitaire,
                                montant,
                                description,
                                date_vente
                            )
                            VALUES (?,?,?,?,?,?,?)
                        ");

                    if (!$stmt) {
                        throw new Exception(
                            'Erreur préparation vente.'
                        );
                    }

                    $stmt->bind_param(
                        'iiiddss',
                        $vid,
                        $pid,
                        $q,
                        $p,
                        $mont,
                        $desc,
                        $date
                    );

                    if (!$stmt->execute()) {

                        throw new Exception(
                            'Erreur lors de l’enregistrement de la vente.'
                        );
                    }

                    $stmt->close();


                    $md =
                        "FACTURE=$ref|CLIENT=" .
                        cleanText($client) .
                        "|SORTIE VENTE";

                    $stmt2 =
                        $conn->prepare("
                            INSERT INTO mouvements
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
                            (?,?, 'SORTIE',?,?,?,?)
                        ");

                    if (!$stmt2) {
                        throw new Exception(
                            'Erreur préparation mouvement.'
                        );
                    }

                    $stmt2->bind_param(
                        'iiidss',
                        $mid,
                        $pid,
                        $q,
                        $p,
                        $md,
                        $date
                    );

                    if (!$stmt2->execute()) {

                        throw new Exception(
                            'Erreur lors de l’enregistrement du mouvement.'
                        );
                    }

                    $stmt2->close();


                    $conn->query("
                        UPDATE produits
                        SET stock = stock - $q
                        WHERE id = $pid
                    ");
                }

                $conn->commit();

                flash(
                    'ok',
                    'Facture ' .
                    $ref .
                    ' enregistrée.'
                );

            } catch (Throwable $e) {

                $conn->rollback();

                throw $e;
            }


            header(
                'Location: ventes.php?facture=' .
                urlencode($ref)
            );

            exit;
        }


        /* =====================================================
           ENCAISSEMENT
           ===================================================== */

        if (isset($_POST['encaisser_facture'])) {

            $ref =
                cleanText(
                    $_POST['ref']
                    ??
                    ''
                );

            $montant =
                (float)(
                    $_POST['montant']
                    ??
                    0
                );

            $rows =
                invoiceRows(
                    $conn,
                    $ref
                );

            if (!$rows) {
                throw new Exception(
                    'Facture introuvable.'
                );
            }

            $meta =
                parseMeta(
                    $rows[0]['description']
                );

            $total = 0;

            foreach ($rows as $r) {

                $total +=
                    (float)$r['quantite']
                    *
                    (float)$r['prix_unitaire'];
            }

            $old =
                (float)(
                    $meta['PAYE']
                    ??
                    0
                );

            $restBefore =
                max(
                    0,
                    $total - $old
                );


            /*
             * Une facture déjà totalement payée
             * ne peut plus être encaissée.
             */
            if ($restBefore <= 0.01) {

                throw new Exception(
                    'Cette facture est déjà totalement payée.'
                );
            }


            $new =
                $old + $montant;

            if (
                $montant <= 0
                ||
                $new > $total + 0.01
            ) {

                throw new Exception(
                    'Montant d’encaissement invalide.'
                );
            }

            $rest =
                max(
                    0,
                    $total - $new
                );


            $conn->begin_transaction();

            try {

                updateInvoiceMeta(
                    $conn,
                    $ref,
                    cleanText(
                        $meta['CLIENT']
                        ??
                        'Client comptant'
                    ),
                    $new,
                    $rest
                );


                $conn->commit();

            } catch (Throwable $e) {

                $conn->rollback();

                throw $e;
            }


            flash(
                'ok',
                'Encaissement enregistré.'
            );

            header(
                'Location: ventes.php?facture=' .
                urlencode($ref)
            );

            exit;
        }


        /* =====================================================
           SUPPRESSION
           ===================================================== */

        if (isset($_POST['supprimer_facture'])) {

            $ref =
                cleanText(
                    $_POST['ref']
                    ??
                    ''
                );

            $rows =
                invoiceRows(
                    $conn,
                    $ref
                );

            if (!$rows) {
                throw new Exception(
                    'Facture introuvable.'
                );
            }

            $meta =
                parseMeta(
                    $rows[0]['description']
                );

            $paid =
                (float)(
                    $meta['PAYE']
                    ??
                    0
                );

            if ($paid > 0) {

                throw new Exception(
                    'Impossible de supprimer une facture ayant déjà reçu un paiement.'
                );
            }


            $conn->begin_transaction();

            try {

                /*
                 * Restaurer le stock.
                 */
                foreach ($rows as $r) {

                    $pid =
                        (int)$r['produit_id'];

                    $q =
                        (int)$r['quantite'];

                    $conn->query("
                        UPDATE produits
                        SET stock = stock + $q
                        WHERE id = $pid
                    ");
                }


                deleteInvoiceRows(
                    $conn,
                    $ref
                );

                deleteInvoiceMovements(
                    $conn,
                    $ref
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
                'Location: ventes.php'
            );

            exit;
        }

    } catch (Throwable $e) {

        flash(
            'err',
            $e->getMessage()
        );

        header(
            'Location: ventes.php'
        );

        exit;
    }
}


/* =========================================================
   DONNÉES AFFICHAGE
   ========================================================= */

$flash =
    $_SESSION['flash']
    ??
    null;

unset($_SESSION['flash']);


$editRef =
    cleanText(
        $_GET['modifier']
        ??
        ''
    );

$selectedRef =
    cleanText(
        $_GET['facture']
        ??
        ''
    );


$editRows =
    $editRef
    ? invoiceRows(
        $conn,
        $editRef
    )
    : [];


$detailRows =
    $selectedRef
    ? invoiceRows(
        $conn,
        $selectedRef
    )
    : [];


/* =========================================================
   LISTE FACTURES
   ========================================================= */

$factures = [];

$q =
    $conn->query("
        SELECT
            v.*,
            p.nom
        FROM ventes v
        LEFT JOIN produits p
            ON p.id = v.produit_id
        ORDER BY v.id DESC
    ");

while (
    $q
    &&
    ($r = $q->fetch_assoc())
) {

    $m =
        parseMeta(
            $r['description']
        );

    $ref =
        $m['FACTURE']
        ??
        ('ANCIEN-' . $r['id']);


    if (
        !isset(
            $factures[$ref]
        )
    ) {

        $factures[$ref] = [
            'ref'      => $ref,
            'client'   => $m['CLIENT'] ?? 'Client comptant',
            'date'     => $r['date_vente'] ?? '',
            'total'    => 0,
            'paye'     => (float)($m['PAYE'] ?? 0),
            'articles' => 0
        ];
    }


    $factures[$ref]['total'] +=
        (float)$r['quantite']
        *
        (float)$r['prix_unitaire'];

    $factures[$ref]['articles']++;
}


/* =========================================================
   PRODUITS
   ========================================================= */

$products = [];

$q =
    $conn->query("
        SELECT
            id,
            nom,
            categorie,
            prix_achat,
            stock
        FROM produits
        ORDER BY nom ASC
    ");

while (
    $q
    &&
    ($r = $q->fetch_assoc())
) {

    $products[] = $r;
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
    Ventes — LAMBEMAH
</title>

<style>

:root{
    --blue:#2563eb;
    --navy:#102f4b;
    --sky:#eef5ff;
    --green:#059669;
    --red:#dc2626;
}

*{
    box-sizing:border-box;
}

body{
    margin:0;
    font-family:Inter,Arial,sans-serif;
    background:#f4f8fd;
    color:#14283d;
}

.layout{
    display:flex;
    min-height:100vh;
}

.side{
    width:270px;
    background:linear-gradient(180deg,#103454,#0b2840);
    color:#fff;
    padding:28px 20px;
    position:fixed;
    inset:0 auto 0 0;
}

.brand{
    font-size:29px;
    font-weight:800;
}

.sub{
    margin-top:7px;
    color:#d8e7f6;
}

.nav{
    margin-top:40px;
}

.nav a{
    display:block;
    color:#fff;
    text-decoration:none;
    padding:14px 16px;
    border-radius:13px;
    margin:7px 0;
    font-size:17px;
}

.nav a.active,
.nav a:hover{
    background:#2563eb;
}

.main{
    margin-left:270px;
    width:calc(100% - 270px);
    padding:30px;
}

.head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
}

.head h1{
    margin:0;
    font-size:30px;
}

.head p{
    color:#617286;
}

.btn{
    border:0;
    border-radius:10px;
    padding:11px 15px;
    background:var(--blue);
    color:#fff;
    font-weight:700;
    cursor:pointer;
    text-decoration:none;
    display:inline-block;
}

.btn.secondary{
    background:#e8f0fb;
    color:var(--navy);
}

.btn.danger{
    background:#fee2e2;
    color:#991b1b;
}

.btn.green{
    background:#059669;
}

.card{
    background:#fff;
    border:1px solid #e1eaf5;
    border-radius:18px;
    padding:22px;
    margin-top:22px;
    box-shadow:0 7px 25px #1e3a5f0a;
}

.stats{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:15px;
    margin-top:22px;
}

.stat{
    background:linear-gradient(135deg,#fff,#f1f7ff);
    border:1px solid #dfebfa;
    border-radius:16px;
    padding:18px;
}

.stat b{
    font-size:25px;
    display:block;
    margin-top:7px;
}

.tablewrap{
    overflow:auto;
}

table{
    width:100%;
    border-collapse:collapse;
    min-width:780px;
}

th,
td{
    padding:14px 12px;
    border-bottom:1px solid #e5edf6;
    text-align:left;
}

th{
    background:#f3f7fc;
    color:#49627a;
    font-size:13px;
    text-transform:uppercase;
}

.money{
    font-weight:800;
}

.status{
    padding:7px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:800;
}

.paid{
    background:#dcfce7;
    color:#166534;
}

.partial{
    background:#fff7ed;
    color:#9a3412;
}

.unpaid{
    background:#fee2e2;
    color:#991b1b;
}

.actions{
    display:flex;
    gap:7px;
    flex-wrap:wrap;
}

.flash{
    padding:13px 16px;
    border-radius:12px;
    margin:16px 0;
}

.ok{
    background:#dcfce7;
    color:#166534;
}

.err{
    background:#fee2e2;
    color:#991b1b;
}

.formgrid{
    display:grid;
    grid-template-columns:1.2fr 1fr;
    gap:10px;
}

.field label{
    display:block;
    font-weight:700;
    font-size:13px;
    margin-bottom:6px;
}

.field input,
.field select{
    width:100%;
    padding:11px;
    border:1px solid #cbd8e7;
    border-radius:9px;
    background:#fff;
}

.line{
    display:grid;
    grid-template-columns:2fr 1fr 1fr 1fr auto;
    gap:8px;
    align-items:end;
    margin-bottom:9px;
}

.totalbox{
    display:flex;
    justify-content:flex-end;
    font-size:22px;
    font-weight:800;
    padding-top:12px;
}

.detailhead{
    display:flex;
    justify-content:space-between;
    gap:20px;
    align-items:flex-start;
}

.paybox{
    background:#f1f7ff;
    border:1px solid #d5e5fb;
    padding:18px;
    border-radius:14px;
    margin-top:18px;
}

.mobile{
    display:none;
}

@media(max-width:850px){

    .side{
        display:none;
    }

    .main{
        margin:0;
        width:100%;
        padding:16px;
    }

    .mobile{
        display:block;
        margin-bottom:10px;
    }

    .head{
        align-items:flex-start;
    }

    .stats{
        grid-template-columns:1fr;
    }

    .formgrid{
        grid-template-columns:1fr;
    }

    .line{
        grid-template-columns:1fr 1fr;
    }

    .line .wide{
        grid-column:1/-1;
    }

    .card{
        padding:15px;
    }

    .detailhead{
        flex-direction:column;
    }

}

</style>

</head>


<body>

<div class="layout">


    <aside class="side">

        <div class="brand">
            LAMBEMAH
        </div>

        <div class="sub">
            GESTION • PRESTATION
        </div>

        <nav class="nav">

            <a href="index.php">
                Accueil
            </a>

            <a href="produits.php">
                Achats / Fournisseurs
            </a>

            <a
                class="active"
                href="ventes.php"
            >
                Ventes / Clients
            </a>

            <a href="prestations.php">
                Prestations
            </a>

            <a href="recettes.php">
                Recettes
            </a>

            <a href="depenses.php">
                Dépenses
            </a>

            <a href="statistiques.php">
                Statistiques
            </a>

            <a href="utilisateurs.php">
                Équipe
            </a>

            <a href="index.php?logout=1">
                Déconnexion
            </a>

        </nav>

    </aside>


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
                    🛒 Ventes & Clients
                </h1>

                <p>
                    Chaque vente = une facture indépendante.
                </p>

            </div>


            <a
                class="btn"
                href="?nouvelle_vente=1"
            >
                ＋ Nouvelle facture de vente
            </a>

        </div>


        <?php if ($flash): ?>

            <div
                class="flash <?=h($flash['type'])?>"
            >
                <?=h($flash['msg'])?>
            </div>

        <?php endif; ?>


        <div class="stats">

            <div class="stat">

                Factures de vente

                <b>
                    <?=count($factures)?>
                </b>

            </div>


            <div class="stat">

                Articles vendus

                <b>
                    <?=array_sum(
                        array_column(
                            $factures,
                            'articles'
                        )
                    )?>
                </b>

            </div>


            <div class="stat">

                Total facturé

                <b>
                    <?=money(
                        array_sum(
                            array_column(
                                $factures,
                                'total'
                            )
                        )
                    )?>
                </b>

            </div>

        </div>


        <?php if (
            isset($_GET['nouvelle_vente'])
            ||
            $editRows
        ): ?>

            <div class="card">

                <h2>

                    <?=$editRows
                        ? '✏️ Modifier la facture ' . h($editRef)
                        : '＋ Nouvelle facture de vente'
                    ?>

                </h2>


                <?php if ($editRows): ?>

                    <?php
                    $editMeta =
                        parseMeta(
                            $editRows[0]['description']
                        );

                    $editPaid =
                        (float)(
                            $editMeta['PAYE']
                            ??
                            0
                        );

                    $editOldTotal = 0;

                    foreach ($editRows as $er) {

                        $editOldTotal +=
                            (float)$er['quantite']
                            *
                            (float)$er['prix_unitaire'];
                    }

                    $editOldRest =
                        max(
                            0,
                            $editOldTotal - $editPaid
                        );
                    ?>

                    <div
                        class="flash ok"
                        style="margin-top:10px"
                    >
                        Facture non totalement payée :
                        modification autorisée.
                        <br>
                        Déjà encaissé :
                        <b>
                            <?=money($editPaid)?>
                        </b>
                        —
                        Reste actuel :
                        <b>
                            <?=money($editOldRest)?>
                        </b>
                    </div>

                <?php endif; ?>


                <form method="post">

                    <input
                        type="hidden"
                        name="save_vente"
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
                                Client
                            </label>

                            <input
                                name="client"
                                value="<?=
                                    h(
                                        $editRows
                                        ? (
                                            parseMeta(
                                                $editRows[0]['description']
                                            )['CLIENT']
                                            ??
                                            'Client comptant'
                                        )
                                        : ''
                                    )
                                ?>"
                                placeholder="Nom du client"
                            >

                        </div>


                        <div class="field">

                            <label>
                                Référence
                            </label>

                            <input
                                readonly
                                value="<?=
                                    h(
                                        $editRef
                                        ?: 'Automatique'
                                    )
                                ?>"
                            >

                        </div>

                    </div>


                    <h3>
                        Articles de la facture
                    </h3>


                    <div id="lines">

                        <?php

                        $base =
                            $editRows
                            ?: [
                                [
                                    'produit_id' => '',
                                    'quantite' => 1,
                                    'prix_unitaire' => ''
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

                                        <?php foreach (
                                            $products as $p
                                        ): ?>

                                            <option
                                                value="<?=$p['id']?>"
                                                <?=
                                                    (
                                                        (int)$p['id']
                                                        ===
                                                        (int)(
                                                            $r['produit_id']
                                                            ??
                                                            0
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
                                            ??
                                            1
                                        )?>"
                                        required
                                    >

                                </div>


                                <div class="field">

                                    <label>
                                        PV unitaire
                                    </label>

                                    <input
                                        type="number"
                                        min="1"
                                        name="prix_unitaire[]"
                                        value="<?=h(
                                            $r['prix_unitaire']
                                            ??
                                            ''
                                        )?>"
                                        required
                                    >

                                </div>


                                <div class="field">

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
                                    onclick="this.parentElement.remove();calc()"
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
                        💾 Enregistrer la facture
                    </button>


                    <a
                        class="btn secondary"
                        href="ventes.php"
                    >
                        Annuler
                    </a>

                </form>

            </div>

        <?php endif; ?>


        <?php if ($detailRows): ?>

            <?php

            $meta =
                parseMeta(
                    $detailRows[0]['description']
                );

            $total = 0;

            foreach (
                $detailRows as $r
            ) {

                $total +=
                    (float)$r['quantite']
                    *
                    (float)$r['prix_unitaire'];
            }

            $paid =
                (float)(
                    $meta['PAYE']
                    ??
                    0
                );

            $rest =
                max(
                    0,
                    $total - $paid
                );

            $status =
                $paid <= 0
                ? 'Non payée'
                : (
                    $rest <= 0
                    ? 'Payée'
                    : 'Partiellement payée'
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

                            <b>
                                Client :
                            </b>

                            <?=h(
                                $meta['CLIENT']
                                ??
                                'Client comptant'
                            )?>

                        </p>

                    </div>


                    <div class="actions">


                        <a
                            class="btn secondary"
                            target="_blank"
                            href="?imprimer=<?=urlencode(
                                $selectedRef
                            )?>"
                        >
                            🖨️ Imprimer / PDF
                        </a>


                        <?php
                        /*
                         * MODIFICATION :
                         * autorisée tant que la facture
                         * n'est PAS totalement payée.
                         */
                        if ($rest > 0.01):
                        ?>

                            <a
                                class="btn secondary"
                                href="?modifier=<?=urlencode(
                                    $selectedRef
                                )?>"
                            >
                                ✏️ Modifier
                            </a>

                        <?php endif; ?>


                        <?php
                        /*
                         * SUPPRESSION :
                         * uniquement si aucun paiement.
                         */
                        if ($paid <= 0.01):
                        ?>

                            <form
                                method="post"
                                onsubmit="return confirm('Supprimer toute cette facture ?')"
                                style="display:inline"
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

                                <button class="btn danger">
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
                                    PVU
                                </th>

                                <th>
                                    Montant
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php foreach (
                            $detailRows as $r
                        ): ?>

                            <tr>

                                <td>
                                    <?=h(
                                        $r['nom']
                                        ??
                                        'Article'
                                    )?>
                                </td>

                                <td>
                                    <?=h(
                                        $r['quantite']
                                    )?>
                                </td>

                                <td>
                                    <?=money(
                                        $r['prix_unitaire']
                                    )?>
                                </td>

                                <td class="money">

                                    <?=money(
                                        (float)$r['quantite']
                                        *
                                        (float)$r['prix_unitaire']
                                    )?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>


                <div class="paybox">


                    <div
                        style="
                            display:grid;
                            grid-template-columns:repeat(3,1fr);
                            gap:12px
                        "
                    >

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
                                Encaissé
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


                    <p>

                        Statut :

                        <span
                            class="status <?=
                                $rest <= 0
                                ? 'paid'
                                : (
                                    $paid > 0
                                    ? 'partial'
                                    : 'unpaid'
                                )
                            ?>"
                        >
                            <?=h($status)?>
                        </span>

                    </p>


                    <?php if ($rest > 0): ?>

                        <form
                            method="post"
                            class="formgrid"
                        >

                            <input
                                type="hidden"
                                name="encaisser_facture"
                                value="1"
                            >

                            <input
                                type="hidden"
                                name="ref"
                                value="<?=h(
                                    $selectedRef
                                )?>"
                            >


                            <div class="field">

                                <label>
                                    Montant encaissé
                                </label>

                                <input
                                    type="number"
                                    min="1"
                                    max="<?=h($rest)?>"
                                    name="montant"
                                    required
                                >

                            </div>


                            <div>

                                <button class="btn green">
                                    💰 Encaisser cette facture
                                </button>

                            </div>

                        </form>

                    <?php else: ?>

                        <div
                            class="flash ok"
                            style="margin-bottom:0"
                        >
                            ✅ Facture totalement payée.
                            Elle est maintenant verrouillée.
                        </div>

                    <?php endif; ?>


                </div>

            </div>

        <?php endif; ?>


        <div class="card">

            <h2>
                👥 Clients & factures
            </h2>

            <p>
                Cliquez sur une facture pour voir ses articles
                et gérer son encaissement.
            </p>


            <div class="tablewrap">

                <table>

                    <thead>

                        <tr>

                            <th>
                                Client
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
                                Encaissé
                            </th>

                            <th>
                                Reste
                            </th>

                            <th>
                                Statut
                            </th>

                            <th></th>

                        </tr>

                    </thead>


                    <tbody>


                    <?php
                    foreach (
                        $factures as $f
                    ):

                        $rest =
                            max(
                                0,
                                $f['total']
                                -
                                $f['paye']
                            );

                        $st =
                            $f['paye'] <= 0
                            ? 'Non payée'
                            : (
                                $rest <= 0
                                ? 'Payée'
                                : 'Partiellement payée'
                            );
                    ?>

                        <tr>

                            <td>
                                <b>
                                    <?=h(
                                        $f['client']
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
                                        strtotime($f['date'])
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

                                <span
                                    class="status <?=
                                        $rest <= 0
                                        ? 'paid'
                                        : (
                                            $f['paye'] > 0
                                            ? 'partial'
                                            : 'unpaid'
                                        )
                                    ?>"
                                >
                                    <?=h($st)?>
                                </span>

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

                            <td colspan="9">
                                Aucune facture de vente.
                            </td>

                        </tr>

                    <?php endif; ?>


                    </tbody>

                </table>

            </div>

        </div>


    </main>

</div>


<script>

function fmt(n)
{
    return new Intl.NumberFormat('fr-FR')
        .format(Math.round(n))
        + ' FG';
}


function calc()
{
    let t = 0;

    document
        .querySelectorAll('.line')
        .forEach(l => {

            let q =
                parseFloat(
                    l.querySelector(
                        '[name="quantite[]"]'
                    )?.value || 0
                );

            let p =
                parseFloat(
                    l.querySelector(
                        '[name="prix_unitaire[]"]'
                    )?.value || 0
                );

            let m = q * p;

            t += m;

            let x =
                l.querySelector(
                    '.lineTotal'
                );

            if (x) {
                x.value = fmt(m);
            }

        });

    let g =
        document.getElementById(
            'grand'
        );

    if (g) {
        g.textContent = fmt(t);
    }
}


function addLine()
{
    let b =
        document.getElementById(
            'lines'
        );

    let s =
        b.querySelector(
            '.line'
        );

    if (!s) {
        return;
    }

    let x =
        s.cloneNode(true);


    x
        .querySelectorAll('input')
        .forEach(i => {

            if (
                i.name === 'quantite[]'
            ) {

                i.value = 1;

            } else if (
                i.name === 'prix_unitaire[]'
            ) {

                i.value = '';

            } else if (
                i.classList.contains(
                    'lineTotal'
                )
            ) {

                i.value = '0 FG';
            }
        });


    let select =
        x.querySelector(
            'select'
        );

    if (select) {
        select.selectedIndex = 0;
    }


    b.appendChild(x);

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
