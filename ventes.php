<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

session_start();

require_once __DIR__ . '/config.php';

mysqli_report(MYSQLI_REPORT_OFF);

$conn = $conn ?? ($mysqli ?? null);

if (!$conn || !($conn instanceof mysqli)) {
    die("Erreur : connexion à la base de données introuvable.");
}

$conn->set_charset('utf8mb4');


/* =========================================================
   FONCTIONS
   ========================================================= */

function h($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function money($v)
{
    return number_format((float)$v, 0, ',', ' ') . ' FG';
}


/*
 * Génère :
 * FAC-2026-0001
 * FAC-2026-0002
 * FAC-2026-0003
 */
function nextInvoiceNumber(mysqli $conn): string
{
    $year = date('Y');

    $result = $conn->query("
        SELECT description
        FROM ventes
        WHERE description LIKE 'FACTURE=FAC-$year-%|%'
        ORDER BY id DESC
    ");

    if (!$result) {
        throw new Exception(
            "Erreur recherche numéro facture : " . $conn->error
        );
    }

    $max = 0;

    while ($row = $result->fetch_assoc()) {

        $description = (string)$row['description'];

        if (preg_match(
            '/FACTURE=FAC-' . preg_quote($year, '/') . '-(\d{4})\|/',
            $description,
            $matches
        )) {
            $numero = (int)$matches[1];

            if ($numero > $max) {
                $max = $numero;
            }
        }
    }

    $max++;

    return 'FAC-' . $year . '-' . str_pad(
        (string)$max,
        4,
        '0',
        STR_PAD_LEFT
    );
}


function nextId(mysqli $conn, string $table): int
{
    $allowed = [
        'produits',
        'ventes',
        'mouvements'
    ];

    if (!in_array($table, $allowed, true)) {
        throw new Exception("Table non autorisée.");
    }

    $result = $conn->query(
        "SELECT COALESCE(MAX(id),0)+1 AS prochain FROM `$table`"
    );

    if (!$result) {
        throw new Exception(
            "Erreur génération ID ($table) : " . $conn->error
        );
    }

    $row = $result->fetch_assoc();

    return (int)($row['prochain'] ?? 1);
}


function parseMeta($description): array
{
    $meta = [];

    foreach (explode('|', (string)$description) as $part) {

        if (strpos($part, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $part, 2);

        $key = trim($key);

        if ($key !== '') {
            $meta[$key] = trim($value);
        }
    }

    return $meta;
}


function buildDescription(
    string $facture,
    string $client,
    string $statut,
    float $paye,
    float $reste
): string {

    return
        'FACTURE=' . $facture .
        '|CLIENT=' . str_replace('|', '/', $client) .
        '|STATUT=' . $statut .
        '|PAYE=' . $paye .
        '|RESTE=' . $reste;
}


function getInvoiceRows(
    mysqli $conn,
    string $facture
): array {

    $safe = $conn->real_escape_string($facture);

    $sql = "
        SELECT
            v.id,
            v.produit_id,
            v.quantite,
            v.prix_unitaire,
            v.montant,
            v.description,
            v.date_vente,
            p.nom,
            p.categorie,
            p.prix_vente,
            p.stock
        FROM ventes v
        LEFT JOIN produits p
            ON p.id = v.produit_id
        WHERE v.description LIKE '%FACTURE=$safe|%'
        ORDER BY v.id ASC
    ";

    $result = $conn->query($sql);

    if (!$result) {
        throw new Exception(
            "Erreur récupération facture : " . $conn->error
        );
    }

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}


function invoiceTotal(array $rows): float
{
    $total = 0;

    foreach ($rows as $row) {
        $total += (float)$row['montant'];
    }

    return $total;
}


function invoicePaid(array $rows): float
{
    if (!$rows) {
        return 0;
    }

    $meta = parseMeta(
        $rows[0]['description'] ?? ''
    );

    return (float)($meta['PAYE'] ?? 0);
}


function invoiceClient(array $rows): string
{
    if (!$rows) {
        return '';
    }

    $meta = parseMeta(
        $rows[0]['description'] ?? ''
    );

    return $meta['CLIENT'] ?? '';
}


function invoiceStatus(
    float $total,
    float $paid
): string {

    if ($paid <= 0) {
        return 'IMPAYEE';
    }

    if ($paid >= $total) {
        return 'PAYEE';
    }

    return 'PARTIEL';
}


function redirectPage()
{
    header('Location: ventes.php');
    exit;
}


/* =========================================================
   VARIABLES
   ========================================================= */

$error = '';

$editFacture = trim(
    $_GET['modifier'] ?? ''
);

$printFacture = trim(
    $_GET['imprimer'] ?? ''
);

$editRows = [];

$editMeta = [];

$produits = [];

$factures = [];


/* =========================================================
   CHARGER LES PRODUITS
   ========================================================= */

$resultProduits = $conn->query("
    SELECT
        id,
        nom,
        categorie,
        prix_vente,
        stock
    FROM produits
    ORDER BY nom ASC
");

if (!$resultProduits) {

    die(
        '<h3>Erreur SQL produits</h3>' .
        '<p>' . h($conn->error) . '</p>'
    );
}

while ($row = $resultProduits->fetch_assoc()) {
    $produits[] = $row;
}


/* =========================================================
   FORMULAIRES
   ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    try {

        /* =================================================
           ENREGISTRER / MODIFIER FACTURE
           ================================================= */

        if ($action === 'save_invoice') {

            $facture = trim(
                $_POST['facture'] ?? ''
            );

            $client = trim(
                $_POST['client'] ?? ''
            );

            $date = trim(
                $_POST['date_vente'] ?? ''
            );

            $produitIds =
                $_POST['produit_id'] ?? [];

            $quantites =
                $_POST['quantite'] ?? [];

            $prixUnitaires =
                $_POST['prix_unitaire'] ?? [];


            if ($facture === '') {
                throw new Exception(
                    "La référence de facture est obligatoire."
                );
            }


            if ($date === '') {
                $date = date('Y-m-d');
            }


            /*
             * Ancienne facture si modification
             */
            $anciennesLignes =
                getInvoiceRows(
                    $conn,
                    $facture
                );


            $ancienTotal =
                invoiceTotal(
                    $anciennesLignes
                );


            $ancienPaye =
                invoicePaid(
                    $anciennesLignes
                );


            /*
             * Facture payée = verrouillée
             */
            if (
                $anciennesLignes &&
                $ancienPaye >= $ancienTotal
            ) {

                throw new Exception(
                    "Cette facture est totalement payée. Elle est verrouillée."
                );
            }


            /*
             * Nouvelles lignes
             */
            $nouvellesLignes = [];

            $nombre = max(
                count($produitIds),
                count($quantites),
                count($prixUnitaires)
            );


            for ($i = 0; $i < $nombre; $i++) {

                $produitId =
                    (int)($produitIds[$i] ?? 0);

                $quantite =
                    (int)($quantites[$i] ?? 0);

                $prix =
                    (float)($prixUnitaires[$i] ?? 0);


                if (
                    $produitId <= 0 ||
                    $quantite <= 0
                ) {
                    continue;
                }


                if ($prix < 0) {
                    $prix = 0;
                }


                /*
                 * Vérifier produit
                 */
                $stmt =
                    $conn->prepare("
                        SELECT id, nom, stock
                        FROM produits
                        WHERE id = ?
                        LIMIT 1
                    ");


                if (!$stmt) {
                    throw new Exception(
                        "Erreur produit : " . $conn->error
                    );
                }


                $stmt->bind_param(
                    'i',
                    $produitId
                );


                if (!$stmt->execute()) {

                    throw new Exception(
                        "Erreur produit : " .
                        $stmt->error
                    );
                }


                $res =
                    $stmt->get_result();

                $produit =
                    $res->fetch_assoc();


                $stmt->close();


                if (!$produit) {

                    throw new Exception(
                        "Le produit sélectionné n'existe pas."
                    );
                }


                $montant =
                    $quantite * $prix;


                $nouvellesLignes[] = [

                    'produit_id' =>
                        $produitId,

                    'quantite' =>
                        $quantite,

                    'prix_unitaire' =>
                        $prix,

                    'montant' =>
                        $montant
                ];
            }


            if (!$nouvellesLignes) {

                throw new Exception(
                    "Ajoute au moins un article."
                );
            }


            /*
             * Nouveau total
             */
            $nouveauTotal = 0;

            foreach ($nouvellesLignes as $ligne) {

                $nouveauTotal +=
                    $ligne['montant'];
            }


            /*
             * Le paiement existant est conservé
             */
            if ($ancienPaye > $nouveauTotal) {

                throw new Exception(
                    "Le montant déjà payé (" .
                    money($ancienPaye) .
                    ") dépasse le nouveau total (" .
                    money($nouveauTotal) .
                    ")."
                );
            }


            $nouveauReste =
                $nouveauTotal -
                $ancienPaye;


            $nouveauStatut =
                invoiceStatus(
                    $nouveauTotal,
                    $ancienPaye
                );


            /*
             * TRANSACTION
             */
            $conn->begin_transaction();


            /*
             * Restaurer ancien stock
             */
            foreach ($anciennesLignes as $ancienne) {

                $produitId =
                    (int)$ancienne['produit_id'];

                $quantite =
                    (int)$ancienne['quantite'];


                $stmt =
                    $conn->prepare("
                        UPDATE produits
                        SET stock = stock + ?
                        WHERE id = ?
                    ");


                if (!$stmt) {
                    throw new Exception(
                        "Erreur stock : " .
                        $conn->error
                    );
                }


                $stmt->bind_param(
                    'ii',
                    $quantite,
                    $produitId
                );


                if (!$stmt->execute()) {

                    throw new Exception(
                        "Erreur restauration stock : " .
                        $stmt->error
                    );
                }


                $stmt->close();
            }


            /*
             * Supprimer anciennes ventes
             */
            if ($anciennesLignes) {

                $safe =
                    $conn->real_escape_string(
                        $facture
                    );


                if (!$conn->query("
                    DELETE FROM ventes
                    WHERE description LIKE '%FACTURE=$safe|%'
                ")) {

                    throw new Exception(
                        "Erreur suppression ancienne facture : " .
                        $conn->error
                    );
                }
            }


            /*
             * Insérer nouvelles ventes
             */
            foreach ($nouvellesLignes as $ligne) {

                $venteId =
                    nextId(
                        $conn,
                        'ventes'
                    );


                $description =
                    buildDescription(
                        $facture,
                        $client,
                        $nouveauStatut,
                        $ancienPaye,
                        $nouveauReste
                    );


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
                        VALUES
                        (
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?
                        )
                    ");


                if (!$stmt) {

                    throw new Exception(
                        "Erreur vente : " .
                        $conn->error
                    );
                }


                $stmt->bind_param(
                    'iiiddss',
                    $venteId,
                    $ligne['produit_id'],
                    $ligne['quantite'],
                    $ligne['prix_unitaire'],
                    $ligne['montant'],
                    $description,
                    $date
                );


                if (!$stmt->execute()) {

                    throw new Exception(
                        "Erreur enregistrement vente : " .
                        $stmt->error
                    );
                }


                $stmt->close();


                /*
                 * Diminuer stock
                 */
                $stmt =
                    $conn->prepare("
                        UPDATE produits
                        SET stock = stock - ?
                        WHERE id = ?
                    ");


                if (!$stmt) {

                    throw new Exception(
                        "Erreur stock : " .
                        $conn->error
                    );
                }


                $stmt->bind_param(
                    'ii',
                    $ligne['quantite'],
                    $ligne['produit_id']
                );


                if (!$stmt->execute()) {

                    throw new Exception(
                        "Erreur diminution stock : " .
                        $stmt->error
                    );
                }


                $stmt->close();
            }


            /*
             * Mouvements SORTIE
             */
            foreach ($nouvellesLignes as $ligne) {

                $mouvementId =
                    nextId(
                        $conn,
                        'mouvements'
                    );


                $descriptionMouvement =
                    'FACTURE=' .
                    $facture .
                    '|CLIENT=' .
                    str_replace(
                        '|',
                        '/',
                        $client
                    );


                $stmt =
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
                        (
                            ?,
                            ?,
                            'SORTIE',
                            ?,
                            ?,
                            ?,
                            ?
                        )
                    ");


                /*
                 * Si la structure mouvements ne permet pas
                 * l'insertion, on ne bloque pas la vente.
                 */
                if ($stmt) {

                    $stmt->bind_param(
                        'iiidss',
                        $mouvementId,
                        $ligne['produit_id'],
                        $ligne['quantite'],
                        $ligne['prix_unitaire'],
                        $descriptionMouvement,
                        $date
                    );

                    $stmt->execute();

                    $stmt->close();
                }
            }


            $conn->commit();

            redirectPage();
        }


        /* =================================================
           PAIEMENT
           ================================================= */

        if ($action === 'pay_invoice') {

            $facture =
                trim(
                    $_POST['facture'] ?? ''
                );


            $montant =
                (float)(
                    $_POST['montant_paiement'] ?? 0
                );


            if ($facture === '') {
                throw new Exception(
                    "Facture introuvable."
                );
            }


            if ($montant <= 0) {
                throw new Exception(
                    "Le montant doit être supérieur à zéro."
                );
            }


            $rows =
                getInvoiceRows(
                    $conn,
                    $facture
                );


            if (!$rows) {
                throw new Exception(
                    "Cette facture n'existe pas."
                );
            }


            $total =
                invoiceTotal($rows);


            $paye =
                invoicePaid($rows);


            $reste =
                max(
                    0,
                    $total - $paye
                );


            if ($paye >= $total) {
                throw new Exception(
                    "Cette facture est déjà totalement payée."
                );
            }


            if ($montant > $reste) {
                throw new Exception(
                    "Le paiement dépasse le reste à payer : " .
                    money($reste)
                );
            }


            $nouveauPaye =
                $paye + $montant;


            $nouveauReste =
                max(
                    0,
                    $total - $nouveauPaye
                );


            $statut =
                invoiceStatus(
                    $total,
                    $nouveauPaye
                );


            $safe =
                $conn->real_escape_string(
                    $facture
                );


            $result =
                $conn->query("
                    SELECT id, description
                    FROM ventes
                    WHERE description LIKE '%FACTURE=$safe|%'
                    ORDER BY id ASC
                ");


            if (!$result) {
                throw new Exception(
                    "Erreur paiement : " .
                    $conn->error
                );
            }


            while ($row = $result->fetch_assoc()) {

                $id =
                    (int)$row['id'];


                $meta =
                    parseMeta(
                        $row['description']
                    );


                $client =
                    $meta['CLIENT'] ?? '';


                $description =
                    buildDescription(
                        $facture,
                        $client,
                        $statut,
                        $nouveauPaye,
                        $nouveauReste
                    );


                $stmt =
                    $conn->prepare("
                        UPDATE ventes
                        SET description = ?
                        WHERE id = ?
                    ");


                if (!$stmt) {
                    throw new Exception(
                        "Erreur mise à jour paiement : " .
                        $conn->error
                    );
                }


                $stmt->bind_param(
                    'si',
                    $description,
                    $id
                );


                if (!$stmt->execute()) {
                    throw new Exception(
                        "Erreur paiement : " .
                        $stmt->error
                    );
                }


                $stmt->close();
            }


            redirectPage();
        }


        /* =================================================
           SUPPRIMER FACTURE
           ================================================= */

        if ($action === 'delete_invoice') {

            $facture =
                trim(
                    $_POST['facture'] ?? ''
                );


            if ($facture === '') {
                throw new Exception(
                    "Facture introuvable."
                );
            }


            $rows =
                getInvoiceRows(
                    $conn,
                    $facture
                );


            if (!$rows) {
                throw new Exception(
                    "Facture introuvable."
                );
            }


            $paye =
                invoicePaid($rows);


            if ($paye > 0) {
                throw new Exception(
                    "Une facture ayant reçu un paiement ne peut pas être supprimée."
                );
            }


            $conn->begin_transaction();


            /*
             * Restaurer stock
             */
            foreach ($rows as $row) {

                $produitId =
                    (int)$row['produit_id'];

                $quantite =
                    (int)$row['quantite'];


                $stmt =
                    $conn->prepare("
                        UPDATE produits
                        SET stock = stock + ?
                        WHERE id = ?
                    ");


                if (!$stmt) {
                    throw new Exception(
                        "Erreur stock : " .
                        $conn->error
                    );
                }


                $stmt->bind_param(
                    'ii',
                    $quantite,
                    $produitId
                );


                if (!$stmt->execute()) {
                    throw new Exception(
                        "Erreur stock : " .
                        $stmt->error
                    );
                }


                $stmt->close();
            }


            $safe =
                $conn->real_escape_string(
                    $facture
                );


            if (!$conn->query("
                DELETE FROM ventes
                WHERE description LIKE '%FACTURE=$safe|%'
            ")) {

                throw new Exception(
                    "Erreur suppression : " .
                    $conn->error
                );
            }


            /*
             * Nettoyage mouvements associés
             */
            $conn->query("
                DELETE FROM mouvements
                WHERE description LIKE '%FACTURE=$safe|%'
            ");


            $conn->commit();

            redirectPage();
        }

    } catch (Throwable $e) {

        try {
            $conn->rollback();
        } catch (Throwable $ignore) {
        }

        $error =
            $e->getMessage();
    }
}


/* =========================================================
   CHARGER FACTURE À MODIFIER
   ========================================================= */

if ($editFacture !== '') {

    try {

        $editRows =
            getInvoiceRows(
                $conn,
                $editFacture
            );


        if (!$editRows) {

            $error =
                "Cette facture n'existe pas.";

        } else {

            $editMeta =
                parseMeta(
                    $editRows[0]['description']
                );


            $total =
                invoiceTotal(
                    $editRows
                );


            $paye =
                invoicePaid(
                    $editRows
                );


            if ($paye >= $total) {

                $error =
                    "Cette facture est totalement payée. Elle est verrouillée.";

                $editRows = [];
            }
        }

    } catch (Throwable $e) {

        $error =
            $e->getMessage();
    }
}


/* =========================================================
   LISTE FACTURES
   ========================================================= */

try {

    $result =
        $conn->query("
            SELECT
                id,
                description,
                date_vente
            FROM ventes
            WHERE description LIKE '%FACTURE=%'
            ORDER BY date_vente DESC, id DESC
        ");


    if (!$result) {
        throw new Exception(
            "Erreur SQL factures : " .
            $conn->error
        );
    }


    $dejaVues = [];


    while ($row = $result->fetch_assoc()) {

        $meta =
            parseMeta(
                $row['description']
            );


        $ref =
            $meta['FACTURE'] ?? '';


        if ($ref === '') {
            continue;
        }


        if (isset($dejaVues[$ref])) {
            continue;
        }


        $dejaVues[$ref] = true;


        $rows =
            getInvoiceRows(
                $conn,
                $ref
            );


        if (!$rows) {
            continue;
        }


        $total =
            invoiceTotal($rows);


        $paid =
            invoicePaid($rows);


        $rest =
            max(
                0,
                $total - $paid
            );


        $factures[] = [

            'ref' =>
                $ref,

            'client' =>
                invoiceClient($rows),

            'date' =>
                $row['date_vente'],

            'total' =>
                $total,

            'paid' =>
                $paid,

            'rest' =>
                $rest,

            'status' =>
                invoiceStatus(
                    $total,
                    $paid
                ),

            'count' =>
                count($rows)
        ];
    }

} catch (Throwable $e) {

    $error =
        $e->getMessage();
}


/* =========================================================
   IMPRESSION
   ========================================================= */

if ($printFacture !== '') {

    try {

        $rows =
            getInvoiceRows(
                $conn,
                $printFacture
            );


        if (!$rows) {
            die("Facture introuvable.");
        }


        $total =
            invoiceTotal($rows);


        $paid =
            invoicePaid($rows);


        $rest =
            max(
                0,
                $total - $paid
            );


        $client =
            invoiceClient($rows);

?>

<!DOCTYPE html>
<html lang="fr">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>

<title>
    <?= h($printFacture) ?>
</title>

<style>

body{
    font-family:Arial,sans-serif;
    margin:0;
    padding:30px;
    color:#172033;
}

.facture{
    max-width:800px;
    margin:auto;
}

.headerPrint{
    display:flex;
    justify-content:space-between;
    gap:20px;
    border-bottom:3px solid #1677ff;
    padding-bottom:20px;
}

.headerPrint h1{
    color:#1677ff;
    margin:0;
}

table{
    width:100%;
    border-collapse:collapse;
    margin-top:25px;
}

th,td{
    border:1px solid #ddd;
    padding:12px;
}

th{
    background:#eaf4ff;
    color:#0d4ea6;
}

.total{
    text-align:right;
    margin-top:25px;
}

.total strong{
    font-size:22px;
}

@media print{
    .no-print{
        display:none;
    }

    body{
        padding:0;
    }
}

</style>

</head>

<body>

<div class="facture">

<div class="headerPrint">

<div>

<h1>
LAMBEMAH GESTION
</h1>

<p>
Gestion commerciale
</p>

</div>

<div>

<strong>
FACTURE
</strong>

<br>

<?= h($printFacture) ?>

<br>

<?= h($rows[0]['date_vente']) ?>

</div>

</div>


<p>

<strong>
Client :
</strong>

<?= h(
    $client ?: 'Client comptant'
) ?>

</p>


<table>

<thead>

<tr>

<th>
Article
</th>

<th>
Qté
</th>

<th>
Prix unitaire
</th>

<th>
Montant
</th>

</tr>

</thead>

<tbody>

<?php foreach ($rows as $row): ?>

<tr>

<td>
<?= h(
    $row['nom'] ?? 'Article'
) ?>
</td>

<td>
<?= (int)$row['quantite'] ?>
</td>

<td>
<?= money(
    $row['prix_unitaire']
) ?>
</td>

<td>
<?= money(
    $row['montant']
) ?>
</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>


<div class="total">

<p>
Total :
<strong>
<?= money($total) ?>
</strong>
</p>

<p>
Déjà payé :
<?= money($paid) ?>
</p>

<p>
Reste :
<strong>
<?= money($rest) ?>
</strong>
</p>

</div>


<div class="no-print">

<button
    onclick="window.print()"
>
🖨️ Imprimer
</button>

</div>

</div>

<script>
window.onload=function(){
    window.print();
};
</script>

</body>

</html>

<?php

        exit;

    } catch (Throwable $e) {

        die(
            "Erreur impression : " .
            h($e->getMessage())
        );
    }
}

?>


<!DOCTYPE html>

<html lang="fr">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>

<title>
Ventes - LAMBEMAH GESTION
</title>


<style>

*{
    box-sizing:border-box;
}

body{
    margin:0;
    font-family:
        Arial,
        Helvetica,
        sans-serif;

    background:#f4f8fc;

    color:#172033;
}


/* HEADER */

.header{

    background:
        linear-gradient(
            135deg,
            #1677ff,
            #0d4ea6
        );

    color:white;

    padding:18px 25px;

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:15px;

    box-shadow:
        0 4px 15px
        rgba(0,0,0,.10);
}

.header h1{
    margin:0;
    font-size:22px;
}

.header p{
    margin:5px 0 0;
    opacity:.85;
}


/* CONTAINER */

.container{

    max-width:1200px;

    margin:25px auto;

    padding:
        0 18px;
}


/* ALERT */

.alert{

    padding:14px 18px;

    border-radius:10px;

    margin-bottom:20px;

    font-weight:600;
}

.alert-error{

    background:#ffecec;

    color:#a30000;

    border:
        1px solid
        #ffb8b8;
}


/* CARD */

.card{

    background:white;

    border-radius:14px;

    padding:22px;

    margin-bottom:22px;

    box-shadow:
        0 4px 18px
        rgba(15,50,90,.07);

    border:
        1px solid
        #e4edf7;
}

.card h2{

    margin:
        0 0 18px;

    color:#0d4ea6;

    font-size:19px;
}


/* GRID */

.grid{

    display:grid;

    grid-template-columns:
        repeat(2,1fr);

    gap:15px;
}

.field{

    display:flex;

    flex-direction:column;

    gap:7px;
}

.field label{
    font-weight:600;
}


/* INPUT */

input,
select{

    width:100%;

    padding:
        11px 12px;

    border:
        1px solid
        #ccd8e6;

    border-radius:9px;

    background:white;

    font-size:14px;

    outline:none;
}

input:focus,
select:focus{

    border-color:#1677ff;

    box-shadow:
        0 0 0 3px
        rgba(22,119,255,.10);
}


/* ARTICLE TABLE */

.article-table{

    width:100%;

    border-collapse:collapse;

    margin-top:20px;
}

.article-table th{

    background:#eaf4ff;

    color:#0d4ea6;

    text-align:left;
}

.article-table th,
.article-table td{

    padding:9px;

    border:
        1px solid
        #e1e8f0;
}


/* BUTTONS */

.btn{

    display:inline-flex;

    align-items:center;

    justify-content:center;

    gap:6px;

    padding:
        10px 15px;

    border:0;

    border-radius:9px;

    cursor:pointer;

    text-decoration:none;

    font-weight:600;

    font-size:14px;
}

.btn-primary{
    background:#1677ff;
    color:white;
}

.btn-secondary{
    background:#eef4fb;
    color:#174a7c;
}

.btn-success{
    background:#16a05d;
    color:white;
}

.btn-danger{
    background:#dc3545;
    color:white;
}

.btn-warning{
    background:#f0a000;
    color:white;
}


.actions{

    display:flex;

    gap:8px;

    flex-wrap:wrap;

    margin-top:20px;
}


/* TOTAL */

.total-box{

    margin-top:20px;

    padding:18px;

    background:#f4f9ff;

    border:
        1px solid
        #dcecff;

    border-radius:10px;

    text-align:right;
}

.grand-total{

    font-size:24px;

    font-weight:bold;

    color:#0d4ea6;
}


/* FACTURES */

.invoice-table{

    width:100%;

    border-collapse:collapse;
}

.invoice-table th{

    background:#1677ff;

    color:white;

    text-align:left;
}

.invoice-table th,
.invoice-table td{

    padding:12px 10px;

    border-bottom:
        1px solid
        #e5eaf0;
}

.invoice-table tr:hover{
    background:#f7fbff;
}


/* BADGES */

.badge{

    display:inline-block;

    padding:
        6px 10px;

    border-radius:20px;

    font-size:12px;

    font-weight:bold;
}

.badge-paid{
    background:#dff7e9;
    color:#117333;
}

.badge-partial{
    background:#fff2cf;
    color:#8a5a00;
}

.badge-unpaid{
    background:#ffe0e0;
    color:#a30000;
}


/* MOBILE */

@media(max-width:800px){

    .header{

        padding:15px;

        flex-direction:column;

        align-items:flex-start;
    }


    .container{
        padding:0 10px;
    }


    .card{
        padding:15px;
    }


    .grid{
        grid-template-columns:1fr;
    }


    .article-table{

        display:block;

        overflow-x:auto;

        white-space:nowrap;
    }


    .invoice-table{

        display:block;

        overflow-x:auto;

        white-space:nowrap;
    }


    .actions{

        flex-direction:column;
    }


    .actions .btn{
        width:100%;
    }

}


@media(max-width:480px){

    .header h1{
        font-size:18px;
    }

    .card h2{
        font-size:17px;
    }

}

</style>

</head>


<body>


<header class="header">

<div>

<h1>
💰 LAMBEMAH GESTION — Ventes
</h1>

<p>
Gestion des factures et paiements
</p>

</div>


<a
    href="index.php"
    class="btn btn-secondary"
>
🏠 Accueil
</a>

</header>


<div class="container">


<?php if ($error): ?>

<div class="alert alert-error">

❌ <?= h($error) ?>

</div>

<?php endif; ?>


<!-- =====================================================
     NOUVELLE FACTURE
     ===================================================== -->

<div class="card">

<h2>

<?= $editRows
    ? '✏️ Modifier la facture ' . h($editFacture)
    : '➕ Nouvelle facture'
?>

</h2>


<form method="POST">

<input
    type="hidden"
    name="action"
    value="save_invoice"
>


<div class="grid">


<div class="field">

<label>
Référence facture
</label>

<input
    type="text"
    name="facture"
    value="<?= h(
        $editFacture ?: nextInvoiceNumber($conn)
    ) ?>"
    <?= $editRows ? 'readonly' : '' ?>
    required
>

</div>


<div class="field">

<label>
Client
</label>

<input
    type="text"
    name="client"
    value="<?= h(
        $editMeta['CLIENT'] ?? ''
    ) ?>"
    placeholder="Nom du client"
>

</div>


<div class="field">

<label>
Date
</label>

<input
    type="date"
    name="date_vente"
    value="<?= h(
        $editRows[0]['date_vente']
        ?? date('Y-m-d')
    ) ?>"
    required
>

</div>


</div>


<table class="article-table">

<thead>

<tr>

<th>
Article
</th>

<th>
Quantité
</th>

<th>
Prix unitaire
</th>

<th>
Montant
</th>

<th>
Action
</th>

</tr>

</thead>


<tbody id="articlesBody">


<?php if ($editRows): ?>


<?php foreach ($editRows as $row): ?>

<tr>

<td>

<select
    name="produit_id[]"
    class="produit"
    onchange="changerPrix(this)"
    required
>

<option value="">
Choisir...
</option>


<?php foreach ($produits as $produit): ?>

<option
    value="<?= (int)$produit['id'] ?>"
    data-prix="<?= h(
        $produit['prix_vente']
    ) ?>"
    <?= (
        (int)$produit['id']
        ===
        (int)$row['produit_id']
    )
    ? 'selected'
    : '' ?>
>

<?= h($produit['nom']) ?>

— stock:
<?= (int)$produit['stock'] ?>

</option>

<?php endforeach; ?>

</select>

</td>


<td>

<input
    type="number"
    name="quantite[]"
    class="quantite"
    min="1"
    value="<?= (int)$row['quantite'] ?>"
    oninput="calculer()"
    required
>

</td>


<td>

<input
    type="number"
    name="prix_unitaire[]"
    class="prix"
    min="0"
    step="1"
    value="<?= h(
        $row['prix_unitaire']
    ) ?>"
    oninput="calculer()"
    required
>

</td>


<td class="montant">
<?= money($row['montant']) ?>
</td>


<td>

<button
    type="button"
    class="btn btn-danger"
    onclick="supprimerLigne(this)"
>
🗑️
</button>

</td>

</tr>

<?php endforeach; ?>


<?php else: ?>


<tr>

<td>

<select
    name="produit_id[]"
    class="produit"
    onchange="changerPrix(this)"
    required
>

<option value="">
Choisir un article...
</option>


<?php foreach ($produits as $produit): ?>

<option
    value="<?= (int)$produit['id'] ?>"
    data-prix="<?= h(
        $produit['prix_vente']
    ) ?>"
>

<?= h($produit['nom']) ?>

— stock:
<?= (int)$produit['stock'] ?>

</option>

<?php endforeach; ?>

</select>

</td>


<td>

<input
    type="number"
    name="quantite[]"
    class="quantite"
    min="1"
    value="1"
    oninput="calculer()"
    required
>

</td>


<td>

<input
    type="number"
    name="prix_unitaire[]"
    class="prix"
    min="0"
    step="1"
    value="0"
    oninput="calculer()"
    required
>

</td>


<td class="montant">
0 FG
</td>


<td>

<button
    type="button"
    class="btn btn-danger"
    onclick="supprimerLigne(this)"
>
🗑️
</button>

</td>

</tr>


<?php endif; ?>


</tbody>

</table>


<div class="actions">

<button
    type="button"
    class="btn btn-secondary"
    onclick="ajouterLigne()"
>
➕ Ajouter un article
</button>


<button
    type="submit"
    class="btn btn-primary"
>
<?= $editRows
    ? '💾 Enregistrer les modifications'
    : '✅ Enregistrer la facture'
?>
</button>


<?php if ($editRows): ?>

<a
    href="ventes.php"
    class="btn btn-secondary"
>
❌ Annuler
</a>

<?php endif; ?>

</div>


<div class="total-box">

<div>
Total de la facture
</div>

<div
    class="grand-total"
    id="grandTotal"
>
0 FG
</div>

</div>


</form>

</div>


<!-- =====================================================
     LISTE FACTURES
     ===================================================== -->

<div class="card">

<h2>
📋 Toutes les factures
</h2>


<?php if (!$factures): ?>

<p>
Aucune facture enregistrée pour le moment.
</p>


<?php else: ?>


<div style="overflow-x:auto">

<table class="invoice-table">

<thead>

<tr>

<th>
Facture
</th>

<th>
Client
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
Statut
</th>

<th>
Actions
</th>

</tr>

</thead>


<tbody>


<?php foreach ($factures as $facture): ?>

<tr>


<td>

<strong>
<?= h($facture['ref']) ?>
</strong>

</td>


<td>

<?= h(
    $facture['client']
    ?: 'Client comptant'
) ?>

</td>


<td>

<?= h(
    $facture['date']
) ?>

</td>


<td>

<?= (int)$facture['count'] ?>

</td>


<td>

<strong>
<?= money(
    $facture['total']
) ?>
</strong>

</td>


<td>

<?= money(
    $facture['paid']
) ?>

</td>


<td>

<?= money(
    $facture['rest']
) ?>

</td>


<td>


<?php if (
    $facture['status']
    === 'PAYEE'
): ?>

<span class="badge badge-paid">
✅ PAYÉE
</span>


<?php elseif (
    $facture['status']
    === 'PARTIEL'
): ?>

<span class="badge badge-partial">
🟠 PARTIEL
</span>


<?php else: ?>

<span class="badge badge-unpaid">
🔴 IMPAYÉE
</span>

<?php endif; ?>


</td>


<td>

<div
    class="actions"
    style="margin:0"
>


<?php if (
    $facture['status']
    !== 'PAYEE'
): ?>

<a
    href="ventes.php?modifier=<?= urlencode(
        $facture['ref']
    ) ?>"
    class="btn btn-primary"
>
✏️
</a>

<?php endif; ?>


<?php if (
    $facture['rest'] > 0
): ?>

<button
    type="button"
    class="btn btn-success"
    onclick="ouvrirPaiement(
        '<?= h($facture['ref']) ?>',
        <?= (float)$facture['rest'] ?>
    )"
>
💰
</button>

<?php endif; ?>


<a
    href="ventes.php?imprimer=<?= urlencode(
        $facture['ref']
    ) ?>"
    target="_blank"
    class="btn btn-secondary"
>
🖨️
</a>


<?php if (
    $facture['paid'] <= 0
): ?>

<form
    method="POST"
    style="display:inline"
    onsubmit="
        return confirm(
            'Supprimer définitivement cette facture ?'
        );
    "
>

<input
    type="hidden"
    name="action"
    value="delete_invoice"
>

<input
    type="hidden"
    name="facture"
    value="<?= h(
        $facture['ref']
    ) ?>"
>

<button
    type="submit"
    class="btn btn-danger"
>
🗑️
</button>

</form>

<?php endif; ?>


</div>

</td>

</tr>

<?php endforeach; ?>


</tbody>

</table>

</div>

<?php endif; ?>

</div>


</div>


<!-- =====================================================
     MODAL PAIEMENT
     ===================================================== -->

<div
    id="paiementModal"
    style="
        display:none;
        position:fixed;
        inset:0;
        background:rgba(0,0,0,.45);
        align-items:center;
        justify-content:center;
        padding:15px;
        z-index:9999;
    "
>

<div
    style="
        background:white;
        width:100%;
        max-width:420px;
        border-radius:14px;
        padding:25px;
        box-shadow:0 15px 50px rgba(0,0,0,.2);
    "
>

<h2 style="color:#0d4ea6;margin-top:0">
💰 Paiement
</h2>


<form method="POST">

<input
    type="hidden"
    name="action"
    value="pay_invoice"
>


<input
    type="hidden"
    name="facture"
    id="paiementFacture"
>


<div class="field">

<label>
Facture
</label>

<input
    type="text"
    id="paiementFactureText"
    readonly
>

</div>


<div
    class="field"
    style="margin-top:15px"
>

<label>
Reste à payer
</label>

<input
    type="text"
    id="paiementResteText"
    readonly
>

</div>


<div
    class="field"
    style="margin-top:15px"
>

<label>
Montant du paiement
</label>

<input
    type="number"
    name="montant_paiement"
    id="montantPaiement"
    min="1"
    step="1"
    required
>

</div>


<div class="actions">

<button
    type="submit"
    class="btn btn-success"
>
💰 Valider
</button>


<button
    type="button"
    class="btn btn-secondary"
    onclick="fermerPaiement()"
>
Annuler
</button>

</div>

</form>

</div>

</div>


<script>

/* =========================================================
   PRIX AUTOMATIQUE
   ========================================================= */

function changerPrix(select){

    const row =
        select.closest('tr');

    if(!row){
        return;
    }


    const option =
        select.options[
            select.selectedIndex
        ];


    const prix =
        option.dataset.prix || 0;


    const input =
        row.querySelector('.prix');


    if(input){

        /*
         * Pour une nouvelle ligne,
         * on met automatiquement le prix.
         */
        if(
            parseFloat(input.value || 0)
            === 0
        ){

            input.value = prix;
        }
    }


    calculer();
}


/* =========================================================
   CALCUL
   ========================================================= */

function calculer(){

    const rows =
        document.querySelectorAll(
            '#articlesBody tr'
        );


    let total = 0;


    rows.forEach(function(row){

        const qte =
            row.querySelector(
                '.quantite'
            );


        const prix =
            row.querySelector(
                '.prix'
            );


        const montant =
            row.querySelector(
                '.montant'
            );


        if(
            !qte ||
            !prix ||
            !montant
        ){
            return;
        }


        const quantite =
            parseFloat(
                qte.value || 0
            );


        const prixUnitaire =
            parseFloat(
                prix.value || 0
            );


        const valeur =
            quantite *
            prixUnitaire;


        total += valeur;


        montant.textContent =
            valeur.toLocaleString(
                'fr-FR'
            ) + ' FG';

    });


    const grandTotal =
        document.getElementById(
            'grandTotal'
        );


    if(grandTotal){

        grandTotal.textContent =
            total.toLocaleString(
                'fr-FR'
            ) + ' FG';
    }
}


/* =========================================================
   AJOUTER ARTICLE
   ========================================================= */

function ajouterLigne(){

    const tbody =
        document.getElementById(
            'articlesBody'
        );


    const tr =
        document.createElement(
            'tr'
        );


    tr.innerHTML = `

<td>

<select
    name="produit_id[]"
    class="produit"
    onchange="changerPrix(this)"
    required
>

<option value="">
Choisir un article...
</option>

<?php foreach ($produits as $produit): ?>

<option
    value="<?= (int)$produit['id'] ?>"
    data-prix="<?= h(
        $produit['prix_vente']
    ) ?>"
>

<?= h(
    $produit['nom']
) ?>

— stock:
<?= (int)$produit['stock'] ?>

</option>

<?php endforeach; ?>

</select>

</td>


<td>

<input
    type="number"
    name="quantite[]"
    class="quantite"
    min="1"
    value="1"
    oninput="calculer()"
    required
>

</td>


<td>

<input
    type="number"
    name="prix_unitaire[]"
    class="prix"
    min="0"
    step="1"
    value="0"
    oninput="calculer()"
    required
>

</td>


<td class="montant">
0 FG
</td>


<td>

<button
    type="button"
    class="btn btn-danger"
    onclick="supprimerLigne(this)"
>
🗑️
</button>

</td>

`;


    tbody.appendChild(tr);


    calculer();
}


/* =========================================================
   SUPPRIMER ARTICLE
   ========================================================= */

function supprimerLigne(button){

    const tbody =
        document.getElementById(
            'articlesBody'
        );


    const rows =
        tbody.querySelectorAll(
            'tr'
        );


    if(rows.length <= 1){

        const row =
            button.closest('tr');


        const select =
            row.querySelector(
                'select'
            );


        const qte =
            row.querySelector(
                '.quantite'
            );


        const prix =
            row.querySelector(
                '.prix'
            );


        if(select){
            select.selectedIndex = 0;
        }


        if(qte){
            qte.value = 1;
        }


        if(prix){
            prix.value = 0;
        }


        calculer();

        return;
    }


    const row =
        button.closest('tr');


    if(row){
        row.remove();
    }


    calculer();
}


/* =========================================================
   PAIEMENT
   ========================================================= */

function ouvrirPaiement(
    facture,
    reste
){

    const modal =
        document.getElementById(
            'paiementModal'
        );


    document.getElementById(
        'paiementFacture'
    ).value = facture;


    document.getElementById(
        'paiementFactureText'
    ).value = facture;


    document.getElementById(
        'paiementResteText'
    ).value =
        Number(reste).toLocaleString(
            'fr-FR'
        ) + ' FG';


    const montant =
        document.getElementById(
            'montantPaiement'
        );


    montant.value = reste;

    montant.max = reste;


    modal.style.display =
        'flex';
}


function fermerPaiement(){

    const modal =
        document.getElementById(
            'paiementModal'
        );


    modal.style.display =
        'none';
}


document.addEventListener(
    'click',
    function(event){

        const modal =
            document.getElementById(
                'paiementModal'
            );


        if(
            modal &&
            event.target === modal
        ){

            fermerPaiement();
        }

    }
);


/* CALCUL INITIAL */

document.addEventListener(
    'DOMContentLoaded',
    function(){

        calculer();

    }
);

</script>


</body>

</html>
