<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Connexion à la base de données impossible.');
}

$conn->set_charset('utf8mb4');

function h($v){
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function money($v){
    return number_format((float)$v, 0, ',', ' ') . ' FG';
}

function cleanText($v){
    return trim(preg_replace('/[|\r\n]+/', ' ', (string)$v));
}

function nextId(mysqli $conn, string $table): int {
    if (!in_array($table, ['produits','mouvements','ventes'], true)) {
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

function flash($t, $m){
    $_SESSION['flash'] = [
        'type' => $t,
        'msg'  => $m
    ];
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
        $total += (float)$r['quantite'] * (float)$r['prix_unitaire'];
    }

    $paid = (float)($meta['PAYE'] ?? 0);
    $rest = max(0, $total - $paid);

    $status = $paid <= 0
        ? 'Non payée'
        : ($rest <= 0 ? 'Payée' : 'Partiellement payée');

    ?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title><?=h($ref)?></title>

<style>
*{box-sizing:border-box}

body{
    margin:0;
    background:#f3f6fa;
    font-family:Arial,sans-serif;
    color:#1d2d3d;
}

.paper{
    width:850px;
    max-width:95%;
    margin:30px auto;
    background:#fff;
    padding:35px;
    border-radius:10px;
    box-shadow:0 5px 25px rgba(0,0,0,.08);
}

.top{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    border-bottom:2px solid #123451;
    padding-bottom:18px;
}

.brand{
    font-size:26px;
    font-weight:800;
    color:#123451;
}

.title{
    text-align:right;
    font-size:18px;
    font-weight:800;
}

.title small{
    font-size:13px;
    color:#555;
}

.info{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:15px;
    margin:20px 0;
}

.box{
    border:1px solid #dce5ee;
    padding:12px;
    border-radius:7px;
}

table{
    width:100%;
    border-collapse:collapse;
}

th,td{
    padding:11px 8px;
    border-bottom:1px solid #e4eaf0;
}

th{
    background:#f4f7fa;
    text-align:left;
    font-size:12px;
}

.num{
    text-align:right;
}

.totals{
    width:320px;
    margin-left:auto;
    margin-top:20px;
}

.totals .line{
    display:flex;
    justify-content:space-between;
    padding:7px 0;
}

.grand{
    border-top:2px solid #123451;
    font-size:17px;
    font-weight:800;
}

.status{
    padding:5px 9px;
    border-radius:20px;
    background:#eef3f8;
}

.actions{
    margin-top:25px;
    text-align:center;
}

.actions button{
    border:0;
    background:#2563eb;
    color:#fff;
    padding:11px 18px;
    border-radius:8px;
    cursor:pointer;
    font-weight:700;
}

@media print{
    body{
        background:#fff;
    }

    .paper{
        width:100%;
        max-width:none;
        margin:0;
        box-shadow:none;
    }

    .actions{
        display:none;
    }
}
</style>
</head>

<body>

<div class="paper">

    <div class="top">

        <div>
            <div class="brand">LAMBEMAH</div>
            <div>GESTION • VENTES</div>
        </div>

        <div class="title">
            FACTURE DE VENTE<br>
            <small><?=h($ref)?></small>
        </div>

    </div>

    <div class="info">

        <div class="box">
            <b>Client</b><br>
            <?=h($meta['CLIENT'] ?? 'Client comptant')?>
        </div>

        <div class="box">
            <b>Date</b><br>
            <?=h(date('d/m/Y H:i', strtotime($rows[0]['date_vente'] ?? 'now')))?>
        </div>

    </div>

    <table>

        <thead>
            <tr>
                <th>Désignation</th>
                <th>Catégorie</th>
                <th class="num">Qté</th>
                <th class="num">PVU</th>
                <th class="num">Montant</th>
            </tr>
        </thead>

        <tbody>

        <?php foreach($rows as $r):

            $m = (float)$r['quantite'] * (float)$r['prix_unitaire'];

        ?>

            <tr>

                <td><?=h($r['nom'] ?? 'Article')?></td>

                <td><?=h($r['categorie'] ?? '')?></td>

                <td class="num"><?=h($r['quantite'])?></td>

                <td class="num"><?=money($r['prix_unitaire'])?></td>

                <td class="num"><?=money($m)?></td>

            </tr>

        <?php endforeach; ?>

        </tbody>

    </table>

    <div class="totals">

        <div class="line">
            <span>Total facture</span>
            <b><?=money($total)?></b>
        </div>

        <div class="line">
            <span>Déjà encaissé</span>
            <b><?=money($paid)?></b>
        </div>

        <div class="line">
            <span>Reste client</span>
            <b><?=money($rest)?></b>
        </div>

        <div class="line grand">
            <span>Statut</span>
            <span class="status"><?=h($status)?></span>
        </div>

    </div>

    <div class="actions">
        <button onclick="window.print()">
            🖨️ Imprimer / Enregistrer en PDF
        </button>
    </div>

</div>

</body>
</html>

<?php
    exit;
}


/* =========================================================
   TRAITEMENTS POST
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        /* =====================================================
           ENREGISTRER / MODIFIER UNE FACTURE
        ===================================================== */

        if (isset($_POST['save_vente'])) {

            $client  = cleanText($_POST['client'] ?? 'Client comptant');
            $editRef = cleanText($_POST['edit_ref'] ?? '');

            $ids    = $_POST['produit_id'] ?? [];
            $qtys   = $_POST['quantite'] ?? [];
            $prices = $_POST['prix_unitaire'] ?? [];

            $lines = [];

            for ($i = 0; $i < count($ids); $i++) {

                $pid = (int)($ids[$i] ?? 0);
                $q   = (int)($qtys[$i] ?? 0);
                $p   = (float)($prices[$i] ?? 0);

                if ($pid > 0 && $q > 0 && $p > 0) {

                    $lines[] = [
                        'pid' => $pid,
                        'q'   => $q,
                        'p'   => $p
                    ];

                }
            }

            if (!$lines) {
                throw new Exception('Ajoutez au moins un article.');
            }


            /* =================================================
               CALCUL DU NOUVEAU TOTAL
            ================================================= */

            $newTotal = 0;

            foreach ($lines as $l) {
                $newTotal += $l['q'] * $l['p'];
            }


            /* =================================================
               MODIFICATION D'UNE FACTURE EXISTANTE
            ================================================= */

            if ($editRef !== '') {

                $old = invoiceRows($conn, $editRef);

                if (!$old) {
                    throw new Exception('Facture à modifier introuvable.');
                }

                $oldMeta = parseMeta($old[0]['description']);

                $oldPaid = (float)($oldMeta['PAYE'] ?? 0);


                /* ---------------------------------------------
                   UNE FACTURE TOTALLEMENT PAYÉE EST VERROUILLÉE
                --------------------------------------------- */

                $oldTotal = 0;

                foreach ($old as $r) {
                    $oldTotal +=
                        (float)$r['quantite'] *
                        (float)$r['prix_unitaire'];
                }

                if ($oldPaid >= $oldTotal - 0.01) {

                    throw new Exception(
                        'Cette facture est totalement payée et ne peut plus être modifiée.'
                    );

                }


                /* ---------------------------------------------
                   LE NOUVEAU TOTAL NE PEUT PAS ÊTRE INFÉRIEUR
                   AU MONTANT DÉJÀ ENCAISSÉ
                --------------------------------------------- */

                if ($newTotal < $oldPaid - 0.01) {

                    throw new Exception(
                        'Impossible de modifier cette facture : '
                        . 'le nouveau total ('.money($newTotal).') '
                        . 'est inférieur au montant déjà encaissé ('
                        .money($oldPaid).').'
                    );

                }


                $conn->begin_transaction();

                try {

                    /*
                     * 1. RESTAURER LE STOCK DES ANCIENS ARTICLES
                     */

                    foreach ($old as $r) {

                        $pid = (int)$r['produit_id'];
                        $q   = (int)$r['quantite'];

                        $stmtStock = $conn->prepare(
                            "UPDATE produits
                             SET stock = stock + ?
                             WHERE id = ?"
                        );

                        if (!$stmtStock) {
                            throw new Exception(
                                'Erreur lors de la restauration du stock.'
                            );
                        }

                        $stmtStock->bind_param(
                            'ii',
                            $q,
                            $pid
                        );

                        $stmtStock->execute();
                        $stmtStock->close();
                    }


                    /*
                     * 2. SUPPRIMER LES ANCIENNES LIGNES
                     */

                    $safeRef = $conn->real_escape_string($editRef);

                    $conn->query(
                        "DELETE FROM ventes
                         WHERE description LIKE '%FACTURE=$safeRef|%'"
                    );

                    $conn->query(
                        "DELETE FROM mouvements
                         WHERE type='SORTIE'
                         AND description LIKE '%FACTURE=$safeRef|%'"
                    );


                    /*
                     * 3. RECRÉER LES LIGNES AVEC LES NOUVELLES
                     *    QUANTITÉS / PRIX
                     */

                    $date = date('Y-m-d H:i:s');

                    $reste = max(0, $newTotal - $oldPaid);

                    $clientSafe = cleanText($client);

                    foreach ($lines as $l) {

                        $pid = (int)$l['pid'];
                        $q   = (int)$l['q'];
                        $p   = (float)$l['p'];

                        /*
                         * Vérification article
                         */

                        $stmtProd = $conn->prepare(
                            "SELECT id, nom, stock
                             FROM produits
                             WHERE id = ?"
                        );

                        if (!$stmtProd) {
                            throw new Exception(
                                'Erreur lors de la vérification du produit.'
                            );
                        }

                        $stmtProd->bind_param('i', $pid);
                        $stmtProd->execute();

                        $resProd = $stmtProd->get_result();

                        if (!$resProd || !$resProd->num_rows) {
                            $stmtProd->close();

                            throw new Exception(
                                'Article introuvable.'
                            );
                        }

                        $prod = $resProd->fetch_assoc();

                        $stmtProd->close();


                        /*
                         * Vérification stock
                         */

                        if ((int)$prod['stock'] < $q) {

                            throw new Exception(
                                'Stock insuffisant pour '
                                .$prod['nom']
                                .' (stock : '
                                .$prod['stock']
                                .').'
                            );

                        }


                        /*
                         * Vente
                         */

                        $vid = nextId($conn, 'ventes');

                        $montant = $q * $p;

                        $desc =
                            "FACTURE=".$editRef
                            ."|CLIENT=".$clientSafe
                            ."|PAYE=".$oldPaid
                            ."|RESTE=".$reste
                            ."|VENTE";


                        $stmt = $conn->prepare(
                            "INSERT INTO ventes
                            (
                                id,
                                produit_id,
                                quantite,
                                prix_unitaire,
                                montant,
                                description,
                                date_vente
                            )
                            VALUES (?,?,?,?,?,?,?)"
                        );

                        if (!$stmt) {
                            throw new Exception(
                                'Erreur lors de l’enregistrement de la vente.'
                            );
                        }

                        $stmt->bind_param(
                            'iiiddss',
                            $vid,
                            $pid,
                            $q,
                            $p,
                            $montant,
                            $desc,
                            $date
                        );

                        $stmt->execute();
                        $stmt->close();


                        /*
                         * Mouvement de stock
                         */

                        $mid = nextId($conn, 'mouvements');

                        $md =
                            "FACTURE=".$editRef
                            ."|CLIENT=".$clientSafe
                            ."|SORTIE VENTE";


                        $stmt2 = $conn->prepare(
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
                            VALUES (?,?,'SORTIE',?,?,?,?)"
                        );

                        if (!$stmt2) {
                            throw new Exception(
                                'Erreur lors de l’enregistrement du mouvement.'
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

                        $stmt2->execute();
                        $stmt2->close();


                        /*
                         * Déduire le nouveau stock
                         */

                        $stmtStock2 = $conn->prepare(
                            "UPDATE produits
                             SET stock = stock - ?
                             WHERE id = ?"
                        );

                        if (!$stmtStock2) {
                            throw new Exception(
                                'Erreur lors de la mise à jour du stock.'
                            );
                        }

                        $stmtStock2->bind_param(
                            'ii',
                            $q,
                            $pid
                        );

                        $stmtStock2->execute();
                        $stmtStock2->close();
                    }


                    $conn->commit();

                    flash(
                        'ok',
                        'Facture '.$editRef.' modifiée avec succès.'
                    );

                    header(
                        'Location: ventes.php?facture='
                        .urlencode($editRef)
                    );

                    exit;

                } catch (Throwable $e) {

                    $conn->rollback();

                    throw $e;
                }


            /* =================================================
               NOUVELLE FACTURE
            ================================================= */

            } else {

                $ref =
                    'VEN-'
                    .date('Ymd-His')
                    .'-'
                    .nextId($conn, 'ventes');


                $conn->begin_transaction();

                try {

                    $date = date('Y-m-d H:i:s');

                    foreach ($lines as $l) {

                        $pid = (int)$l['pid'];
                        $q   = (int)$l['q'];
                        $p   = (float)$l['p'];


                        /*
                         * Vérifier produit et stock
                         */

                        $stmtProd = $conn->prepare(
                            "SELECT id, nom, stock
                             FROM produits
                             WHERE id = ?"
                        );

                        if (!$stmtProd) {
                            throw new Exception(
                                'Erreur lors de la vérification du produit.'
                            );
                        }

                        $stmtProd->bind_param('i', $pid);
                        $stmtProd->execute();

                        $resProd = $stmtProd->get_result();

                        if (!$resProd || !$resProd->num_rows) {

                            $stmtProd->close();

                            throw new Exception(
                                'Article introuvable.'
                            );
                        }

                        $prod = $resProd->fetch_assoc();

                        $stmtProd->close();


                        if ((int)$prod['stock'] < $q) {

                            throw new Exception(
                                'Stock insuffisant pour '
                                .$prod['nom']
                                .' (stock : '
                                .$prod['stock']
                                .').'
                            );

                        }


                        /*
                         * Vente
                         */

                        $vid = nextId($conn, 'ventes');

                        $montant = $q * $p;

                        $desc =
                            "FACTURE=".$ref
                            ."|CLIENT=".$client
                            ."|PAYE=0"
                            ."|RESTE=0"
                            ."|VENTE";


                        $stmt = $conn->prepare(
                            "INSERT INTO ventes
                            (
                                id,
                                produit_id,
                                quantite,
                                prix_unitaire,
                                montant,
                                description,
                                date_vente
                            )
                            VALUES (?,?,?,?,?,?,?)"
                        );

                        if (!$stmt) {
                            throw new Exception(
                                'Erreur lors de l’enregistrement de la vente.'
                            );
                        }

                        $stmt->bind_param(
                            'iiiddss',
                            $vid,
                            $pid,
                            $q,
                            $p,
                            $montant,
                            $desc,
                            $date
                        );

                        $stmt->execute();
                        $stmt->close();


                        /*
                         * Mouvement
                         */

                        $mid = nextId($conn, 'mouvements');

                        $md =
                            "FACTURE=".$ref
                            ."|CLIENT=".$client
                            ."|SORTIE VENTE";


                        $stmt2 = $conn->prepare(
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
                            VALUES (?,?,'SORTIE',?,?,?,?)"
                        );

                        if (!$stmt2) {
                            throw new Exception(
                                'Erreur lors du mouvement de stock.'
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

                        $stmt2->execute();
                        $stmt2->close();


                        /*
                         * Déduire stock
                         */

                        $stmtStock = $conn->prepare(
                            "UPDATE produits
                             SET stock = stock - ?
                             WHERE id = ?"
                        );

                        if (!$stmtStock) {
                            throw new Exception(
                                'Erreur lors de la mise à jour du stock.'
                            );
                        }

                        $stmtStock->bind_param(
                            'ii',
                            $q,
                            $pid
                        );

                        $stmtStock->execute();
                        $stmtStock->close();
                    }


                    $conn->commit();

                    flash(
                        'ok',
                        'Facture '.$ref.' enregistrée avec succès.'
                    );

                } catch (Throwable $e) {

                    $conn->rollback();

                    throw $e;
                }


                header(
                    'Location: ventes.php?facture='
                    .urlencode($ref)
                );

                exit;
            }
        }


        /* =====================================================
           ENCAISSEMENT
        ===================================================== */

        if (isset($_POST['encaisser_facture'])) {

            $ref     = cleanText($_POST['ref'] ?? '');
            $montant = (float)($_POST['montant'] ?? 0);

            $rows = invoiceRows($conn, $ref);

            if (!$rows) {
                throw new Exception('Facture introuvable.');
            }

            $meta = parseMeta($rows[0]['description']);

            $total = 0;

            foreach ($rows as $r) {

                $total +=
                    (float)$r['quantite'] *
                    (float)$r['prix_unitaire'];
            }

            $oldPaid = (float)($meta['PAYE'] ?? 0);

            $restAvant = max(0, $total - $oldPaid);

            /*
             * La facture est déjà réglée
             */

            if ($restAvant <= 0.01) {

                throw new Exception(
                    'Cette facture est déjà totalement payée.'
                );
            }


            if ($montant <= 0) {

                throw new Exception(
                    'Montant d’encaissement invalide.'
                );
            }


            if ($montant > $restAvant + 0.01) {

                throw new Exception(
                    'Le montant encaissé ne peut pas dépasser le reste de la facture.'
                );
            }


            $newPaid = $oldPaid + $montant;

            $newRest = max(0, $total - $newPaid);

            $client =
                cleanText(
                    $meta['CLIENT'] ?? 'Client comptant'
                );


            $safeRef =
                $conn->real_escape_string($ref);

            $safeClient =
                $conn->real_escape_string($client);


            $newDescription =
                "FACTURE=".$safeRef
                ."|CLIENT=".$safeClient
                ."|PAYE=".$newPaid
                ."|RESTE=".$newRest
                ."|VENTE";


            $conn->begin_transaction();

            try {

                $stmt = $conn->prepare(
                    "UPDATE ventes
                     SET description = ?
                     WHERE description LIKE ?"
                );

                $like = "%FACTURE=".$safeRef."|%";

                $stmt->bind_param(
                    'ss',
                    $newDescription,
                    $like
                );

                $stmt->execute();
                $stmt->close();


                $conn->commit();

            } catch (Throwable $e) {

                $conn->rollback();

                throw $e;
            }


            flash(
                'ok',
                'Encaissement de '.money($montant).' enregistré.'
            );

            header(
                'Location: ventes.php?facture='
                .urlencode($ref)
            );

            exit;
        }


        /* =====================================================
           SUPPRIMER UNE FACTURE
        ===================================================== */

        if (isset($_POST['supprimer_facture'])) {

            $ref = cleanText($_POST['ref'] ?? '');

            $rows = invoiceRows($conn, $ref);

            if (!$rows) {
                throw new Exception(
                    'Facture introuvable.'
                );
            }

            $meta = parseMeta(
                $rows[0]['description']
            );

            $paid =
                (float)($meta['PAYE'] ?? 0);


            /*
             * Une facture ayant reçu un paiement
             * ne peut pas être supprimée.
             */

            if ($paid > 0) {

                throw new Exception(
                    'Impossible de supprimer une facture ayant déjà reçu un paiement.'
                );
            }


            $conn->begin_transaction();

            try {

                /*
                 * Restaurer le stock
                 */

                foreach ($rows as $r) {

                    $pid =
                        (int)$r['produit_id'];

                    $q =
                        (int)$r['quantite'];


                    $stmtStock = $conn->prepare(
                        "UPDATE produits
                         SET stock = stock + ?
                         WHERE id = ?"
                    );

                    if (!$stmtStock) {
                        throw new Exception(
                            'Erreur lors de la restauration du stock.'
                        );
                    }

                    $stmtStock->bind_param(
                        'ii',
                        $q,
                        $pid
                    );

                    $stmtStock->execute();
                    $stmtStock->close();
                }


                $safeRef =
                    $conn->real_escape_string($ref);


                $conn->query(
                    "DELETE FROM ventes
                     WHERE description LIKE '%FACTURE=$safeRef|%'"
                );


                $conn->query(
                    "DELETE FROM mouvements
                     WHERE type='SORTIE'
                     AND description LIKE '%FACTURE=$safeRef|%'"
                );


                $conn->commit();

            } catch (Throwable $e) {

                $conn->rollback();

                throw $e;
            }


            flash(
                'ok',
                'Facture supprimée avec succès.'
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
   DONNÉES
========================================================= */

$flash =
    $_SESSION['flash'] ?? null;

unset($_SESSION['flash']);


$editRef =
    cleanText(
        $_GET['modifier'] ?? ''
    );

$selectedRef =
    cleanText(
        $_GET['facture'] ?? ''
    );


$editRows =
    $editRef
    ? invoiceRows($conn, $editRef)
    : [];


$detailRows =
    $selectedRef
    ? invoiceRows($conn, $selectedRef)
    : [];


/* =========================================================
   LISTE FACTURES
========================================================= */

$factures = [];

$q = $conn->query(
    "SELECT v.*, p.nom
     FROM ventes v
     LEFT JOIN produits p ON p.id=v.produit_id
     ORDER BY v.id DESC"
);

while ($q && ($r = $q->fetch_assoc())) {

    $m =
        parseMeta(
            $r['description']
        );

    $ref =
        $m['FACTURE']
        ?? ('ANCIEN-'.$r['id']);


    if (!isset($factures[$ref])) {

        $factures[$ref] = [
            'ref'      => $ref,
            'client'   => $m['CLIENT']
                ?? 'Client comptant',
            'date'     => $r['date_vente']
                ?? '',
            'total'    => 0,
            'paye'     => (float)(
                $m['PAYE'] ?? 0
            ),
            'articles' => 0
        ];
    }


    $factures[$ref]['total'] +=
        (float)$r['quantite'] *
        (float)$r['prix_unitaire'];


    $factures[$ref]['articles']++;
}


/* =========================================================
   PRODUITS
========================================================= */

$products = [];

$q = $conn->query(
    "SELECT id,nom,categorie,prix_achat,stock
     FROM produits
     ORDER BY nom ASC"
);

while ($q && ($r = $q->fetch_assoc())) {
    $products[] = $r;
}

?>
<!doctype html>

<html lang="fr">

<head>

<meta charset="utf-8">

<meta name="viewport"
      content="width=device-width,initial-scale=1">

<title>Ventes — LAMBEMAH</title>

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
    background:linear-gradient(
        180deg,
        #103454,
        #0b2840
    );
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
    color:#647589;
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
    background:var(--green);
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
}

.stat{
    background:linear-gradient(
        135deg,
        #fff,
        #f1f7ff
    );
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

.locked{
    background:#e5e7eb;
    color:#374151;
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

.notice{
    background:#fff7ed;
    border:1px solid #fed7aa;
    color:#9a3412;
    padding:12px;
    border-radius:10px;
    margin-top:15px;
}

.locknotice{
    background:#f1f5f9;
    border:1px solid #cbd5e1;
    color:#334155;
    padding:12px;
    border-radius:10px;
    margin-top:15px;
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
        margin-bottom:12px;
    }

    .head{
        display:block;
    }

    .head h1{
        font-size:22px;
    }

    .head .btn{
        margin-top:10px;
    }

    .stats{
        grid-template-columns:1fr;
    }

    .formgrid{
        grid-template-columns:1fr;
    }

    .line{
        grid-template-columns:1fr 1fr
