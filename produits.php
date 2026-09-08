<?php
session_start();
require_once 'config.php';

$conn = $conn ?? ($mysqli ?? null);

if (!$conn) {
    die("Erreur : connexion à la base de données introuvable.");
}

mysqli_report(MYSQLI_REPORT_OFF);

/* ============================================================
   OUTILS
============================================================ */

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function money($v) {
    return number_format((float)$v, 0, ',', ' ') . ' FG';
}

function nextId($conn, $table) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);

    $q = mysqli_query(
        $conn,
        "SELECT COALESCE(MAX(id),0)+1 AS prochain FROM `$table`"
    );

    if (!$q) {
        return 1;
    }

    $r = mysqli_fetch_assoc($q);

    return (int)$r['prochain'];
}

function parseFacture($description) {

    $data = [];

    if (strpos($description, 'FACTURE=') !== 0) {
        return $data;
    }

    $parts = explode('|', $description);

    foreach ($parts as $part) {

        $tmp = explode('=', $part, 2);

        if (count($tmp) === 2) {
            $data[$tmp[0]] = $tmp[1];
        }
    }

    return $data;
}

function factureDescription(
    $numero,
    $fournisseur,
    $statut,
    $paye,
    $reste
) {

    return 'FACTURE=' . $numero .
           '|FOURNISSEUR=' . str_replace('|', '/', $fournisseur) .
           '|STATUT=' . $statut .
           '|PAYE=' . $paye .
           '|RESTE=' . $reste;
}

/* ============================================================
   BROUILLON
============================================================ */

if (!isset($_SESSION['achat_brouillon'])) {

    $_SESSION['achat_brouillon'] = [
        'fournisseur' => '',
        'lignes' => [],
        'numero_facture' => '',
        'paye_existant' => 0,
        'mode' => 'nouveau'
    ];
}

$brouillon =& $_SESSION['achat_brouillon'];

/* ============================================================
   MESSAGES
============================================================ */

$message = '';
$erreur = '';

/* ============================================================
   ANNULER MODIFICATION
============================================================ */

if (isset($_GET['annuler_modification'])) {

    $_SESSION['achat_brouillon'] = [
        'fournisseur' => '',
        'lignes' => [],
        'numero_facture' => '',
        'paye_existant' => 0,
        'mode' => 'nouveau'
    ];

    header("Location: produits.php");
    exit;
}

/* ============================================================
   MODIFIER UNE FACTURE EXISTANTE
============================================================ */

if (isset($_GET['modifier_facture'])) {

    $numero = trim($_GET['modifier_facture']);

    if ($numero !== '') {

        $numeroEsc = mysqli_real_escape_string($conn, $numero);

        $q = mysqli_query(
            $conn,
            "SELECT id, produit_id, quantite, prix, description
             FROM mouvements
             WHERE type='ENTREE'
             AND description LIKE 'FACTURE=$numeroEsc|%'
             ORDER BY id ASC"
        );

        $lignes = [];

        if ($q) {

            while ($row = mysqli_fetch_assoc($q)) {
                $lignes[] = $row;
            }
        }

        if (!empty($lignes)) {

            $infos = parseFacture($lignes[0]['description']);

            $reste = (float)($infos['RESTE'] ?? 0);
            $paye = (float)($infos['PAYE'] ?? 0);

            /*
             * Une facture totalement payée ne peut pas être modifiée.
             */

            if ($reste <= 0) {

                $erreur = "Cette facture est totalement payée et ne peut plus être modifiée.";

            } else {

                $lignesBrouillon = [];

                foreach ($lignes as $ligne) {

                    $pid = (int)$ligne['produit_id'];

                    $rp = mysqli_query(
                        $conn,
                        "SELECT nom FROM produits WHERE id=$pid LIMIT 1"
                    );

                    $nom = 'Article';

                    if ($rp && $pp = mysqli_fetch_assoc($rp)) {
                        $nom = $pp['nom'];
                    }

                    $quantite = (int)$ligne['quantite'];
                    $prix = (float)$ligne['prix'];

                    $lignesBrouillon[] = [
                        'produit_id' => $pid,
                        'nom' => $nom,
                        'quantite' => $quantite,
                        'prix' => $prix,
                        'montant' => $quantite * $prix
                    ];
                }

                $_SESSION['achat_brouillon'] = [
                    'fournisseur' => $infos['FOURNISSEUR'] ?? '',
                    'lignes' => $lignesBrouillon,
                    'numero_facture' => $numero,
                    'paye_existant' => $paye,
                    'mode' => 'modification'
                ];

                header("Location: produits.php");
                exit;
            }

        } else {

            $erreur = "Facture introuvable.";
        }
    }
}

/*
 * On recharge la référence après une éventuelle modification.
 */
$brouillon =& $_SESSION['achat_brouillon'];

/* ============================================================
   AJOUTER UNE LIGNE
============================================================ */

if (isset($_POST['ajouter_ligne'])) {

    $produit_id = (int)($_POST['produit_id'] ?? 0);
    $quantite = (int)($_POST['quantite'] ?? 0);
    $prix = (float)($_POST['prix_achat'] ?? 0);
    $fournisseur = trim($_POST['fournisseur'] ?? '');

    /*
     * Toujours conserver le fournisseur saisi.
     */
    $brouillon['fournisseur'] = $fournisseur;

    if ($produit_id > 0 && $quantite > 0 && $prix >= 0) {

        $stmt = mysqli_prepare(
            $conn,
            "SELECT id, nom
             FROM produits
             WHERE id = ?
             LIMIT 1"
        );

        if ($stmt) {

            mysqli_stmt_bind_param(
                $stmt,
                "i",
                $produit_id
            );

            mysqli_stmt_execute($stmt);

            $res = mysqli_stmt_get_result($stmt);

            $prod = mysqli_fetch_assoc($res);

            mysqli_stmt_close($stmt);

            if ($prod) {

                $brouillon['lignes'][] = [
                    'produit_id' => $produit_id,
                    'nom' => $prod['nom'],
                    'quantite' => $quantite,
                    'prix' => $prix,
                    'montant' => $quantite * $prix
                ];
            }
        }
    }

    header("Location: produits.php");
    exit;
}

/* ============================================================
   SUPPRIMER UNE LIGNE
============================================================ */

if (isset($_GET['supprimer_ligne'])) {

    $index = (int)$_GET['supprimer_ligne'];

    if (isset($brouillon['lignes'][$index])) {

        unset($brouillon['lignes'][$index]);

        $brouillon['lignes'] =
            array_values($brouillon['lignes']);
    }

    header("Location: produits.php");
    exit;
}

/* ============================================================
   VIDER LE BROUILLON
============================================================ */

if (isset($_POST['vider_brouillon'])) {

    $_SESSION['achat_brouillon'] = [
        'fournisseur' => '',
        'lignes' => [],
        'numero_facture' => '',
        'paye_existant' => 0,
        'mode' => 'nouveau'
    ];

    header("Location: produits.php");
    exit;
}

/* ============================================================
   MODIFIER FOURNISSEUR
============================================================ */

if (isset($_POST['modifier_fournisseur'])) {

    $brouillon['fournisseur'] =
        trim($_POST['fournisseur'] ?? '');

    header("Location: produits.php");
    exit;
}

/* ============================================================
   VALIDER / MODIFIER LA FACTURE
============================================================ */

if (isset($_POST['valider_facture'])) {

    $fournisseur =
        trim($brouillon['fournisseur'] ?? '');

    if (!empty($_POST['fournisseur'])) {
        $fournisseur =
            trim($_POST['fournisseur']);
    }

    $lignes =
        $brouillon['lignes'] ?? [];

    $mode =
        $brouillon['mode'] ?? 'nouveau';

    $ancienneFacture =
        $brouillon['numero_facture'] ?? '';

    $payeExistant =
        (float)($brouillon['paye_existant'] ?? 0);

    if ($fournisseur === '') {

        $erreur =
            "Veuillez renseigner le fournisseur.";

    } elseif (empty($lignes)) {

        $erreur =
            "Ajoutez au moins un article à la facture.";

    } else {

        $total = 0;

        foreach ($lignes as $ligne) {

            $total +=
                (float)$ligne['montant'];
        }

        /*
         * Pour une nouvelle facture :
         * paiement saisi maintenant.
         *
         * Pour une modification :
         * on conserve le paiement déjà effectué.
         */

        if ($mode === 'modification') {

            $paye = $payeExistant;

        } else {

            $paye =
                (float)($_POST['paye'] ?? 0);
        }

        if ($paye < 0) {
            $paye = 0;
        }

        if ($paye > $total) {
            $paye = $total;
        }

        $reste =
            $total - $paye;

        if ($reste <= 0) {

            $statut = 'PAYEE';
            $reste = 0;

        } elseif ($paye > 0) {

            $statut = 'PARTIELLE';

        } else {

            $statut = 'IMPAYEE';
        }

        /*
         * Nouveau numéro seulement pour une nouvelle facture.
         */

        if ($mode === 'modification') {

            $numero = $ancienneFacture;

        } else {

            $numero =
                'ACH-' .
                date('Ymd-His') .
                '-' .
                rand(100, 999);
        }

        /*
         * Vérification des produits.
         */

        $ok = true;

        foreach ($lignes as $ligne) {

            $pid =
                (int)$ligne['produit_id'];

            $test = mysqli_query(
                $conn,
                "SELECT id
                 FROM produits
                 WHERE id=$pid
                 LIMIT 1"
            );

            if (!$test ||
                mysqli_num_rows($test) === 0) {

                $ok = false;
                break;
            }
        }

        /*
         * ====================================================
         * MODIFICATION D'UNE FACTURE EXISTANTE
         * ====================================================
         */

        if ($ok && $mode === 'modification') {

            $numeroEsc =
                mysqli_real_escape_string(
                    $conn,
                    $numero
                );

            /*
             * On récupère les anciennes lignes
             * avant de les remplacer.
             */

            $anciennes = [];

            $qa = mysqli_query(
                $conn,
                "SELECT id, produit_id, quantite
                 FROM mouvements
                 WHERE type='ENTREE'
                 AND description LIKE 'FACTURE=$numeroEsc|%'"
            );

            if ($qa) {

                while ($row =
                    mysqli_fetch_assoc($qa)) {

                    $anciennes[] = $row;
                }
            }

            /*
             * Transaction.
             */

            mysqli_begin_transaction($conn);

            try {

                /*
                 * 1. Retirer l'ancien stock.
                 */

                foreach ($anciennes as $ancienne) {

                    $pid =
                        (int)$ancienne['produit_id'];

                    $qte =
                        (int)$ancienne['quantite'];

                    $updateStock = mysqli_query(
                        $conn,
                        "UPDATE produits
                         SET stock =
                             COALESCE(stock,0) - $qte
                         WHERE id=$pid"
                    );

                    if (!$updateStock) {
                        throw new Exception(
                            "Impossible de corriger le stock."
                        );
                    }
                }

                /*
                 * 2. Supprimer les anciennes lignes
                 *    de la facture.
                 */

                $delete = mysqli_query(
                    $conn,
                    "DELETE FROM mouvements
                     WHERE type='ENTREE'
                     AND description LIKE 'FACTURE=$numeroEsc|%'"
                );

                if (!$delete) {
                    throw new Exception(
                        "Impossible de remplacer la facture."
                    );
                }

                /*
                 * 3. Réinsérer les nouvelles lignes.
                 */

                foreach ($lignes as $ligne) {

                    $mouvement_id =
                        nextId($conn, 'mouvements');

                    $pid =
                        (int)$ligne['produit_id'];

                    $quantite =
                        (int)$ligne['quantite'];

                    $prix =
                        (float)$ligne['prix'];

                    $description =
                        factureDescription(
                            $numero,
                            $fournisseur,
                            $statut,
                            $paye,
                            $reste
                        );

                    $stmt = mysqli_prepare(
                        $conn,
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
                            NOW()
                        )"
                    );

                    if (!$stmt) {
                        throw new Exception(
                            "Erreur de préparation."
                        );
                    }

                    mysqli_stmt_bind_param(
                        $stmt,
                        "iiids",
                        $mouvement_id,
                        $pid,
                        $quantite,
                        $prix,
                        $description
                    );

                    if (!mysqli_stmt_execute($stmt)) {

                        mysqli_stmt_close($stmt);

                        throw new Exception(
                            "Erreur lors de l'enregistrement."
                        );
                    }

                    mysqli_stmt_close($stmt);

                    /*
                     * 4. Ajouter le nouveau stock.
                     */

                    $updateStock = mysqli_query(
                        $conn,
                        "UPDATE produits
                         SET stock =
                             COALESCE(stock,0) + $quantite,
                             prix_achat = $prix
                         WHERE id=$pid"
                    );

                    if (!$updateStock) {

                        throw new Exception(
                            "Erreur de mise à jour du stock."
                        );
                    }
                }

                mysqli_commit($conn);

                $_SESSION['achat_brouillon'] = [
                    'fournisseur' => '',
                    'lignes' => [],
                    'numero_facture' => '',
                    'paye_existant' => 0,
                    'mode' => 'nouveau'
                ];

                $message =
                    "Facture $numero modifiée avec succès.";

            } catch (Exception $e) {

                mysqli_rollback($conn);

                $erreur =
                    "La modification n'a pas pu être enregistrée : "
                    . $e->getMessage();
            }

        /*
         * ====================================================
         * NOUVELLE FACTURE
         * ====================================================
         */

        } elseif ($ok) {

            mysqli_begin_transaction($conn);

            try {

                foreach ($lignes as $ligne) {

                    $mouvement_id =
                        nextId($conn, 'mouvements');

                    $pid =
                        (int)$ligne['produit_id'];

                    $quantite =
                        (int)$ligne['quantite'];

                    $prix =
                        (float)$ligne['prix'];

                    $description =
                        factureDescription(
                            $numero,
                            $fournisseur,
                            $statut,
                            $paye,
                            $reste
                        );

                    $stmt = mysqli_prepare(
                        $conn,
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
                            NOW()
                        )"
                    );

                    if (!$stmt) {
                        throw new Exception(
                            "Erreur de préparation."
                        );
                    }

                    mysqli_stmt_bind_param(
                        $stmt,
                        "iiids",
                        $mouvement_id,
                        $pid,
                        $quantite,
                        $prix,
                        $description
                    );

                    if (!mysqli_stmt_execute($stmt)) {

                        mysqli_stmt_close($stmt);

                        throw new Exception(
                            "Erreur lors de l'enregistrement."
                        );
                    }

                    mysqli_stmt_close($stmt);

                    mysqli_query(
                        $conn,
                        "UPDATE produits
                         SET stock =
                             COALESCE(stock,0) + $quantite,
                             prix_achat = $prix
                         WHERE id=$pid"
                    );
                }

                mysqli_commit($conn);

                $_SESSION['achat_brouillon'] = [
                    'fournisseur' => '',
                    'lignes' => [],
                    'numero_facture' => '',
                    'paye_existant' => 0,
                    'mode' => 'nouveau'
                ];

                $message =
                    "Facture $numero validée avec succès.";

            } catch (Exception $e) {

                mysqli_rollback($conn);

                $erreur =
                    "La facture n'a pas pu être enregistrée.";
            }
        }
    }
}

/* ============================================================
   RÈGLEMENT FACTURE
============================================================ */

if (isset($_POST['regler_facture'])) {

    $numero =
        trim($_POST['numero_facture'] ?? '');

    if ($numero !== '') {

        $numeroEsc =
            mysqli_real_escape_string(
                $conn,
                $numero
            );

        $q = mysqli_query(
            $conn,
            "SELECT id, description
             FROM mouvements
             WHERE type='ENTREE'
             AND description LIKE 'FACTURE=$numeroEsc|%'
             ORDER BY id ASC"
        );

        $lignesFacture = [];

        if ($q) {

            while ($row =
                mysqli_fetch_assoc($q)) {

                $lignesFacture[] = $row;
            }
        }

        if (!empty($lignesFacture)) {

            $premier =
                parseFacture(
                    $lignesFacture[0]['description']
                );

            $ancienReste =
                (float)($premier['RESTE'] ?? 0);

            if ($ancienReste > 0) {

                $paye =
                    (float)($premier['PAYE'] ?? 0);

                $fournisseur =
                    $premier['FOURNISSEUR'] ?? '';

                $nouvelleDescription =
                    factureDescription(
                        $numero,
                        $fournisseur,
                        'PAYEE',
                        $paye + $ancienReste,
                        0
                    );

                $descEsc =
                    mysqli_real_escape_string(
                        $conn,
                        $nouvelleDescription
                    );

                foreach ($lignesFacture as $ligne) {

                    $idMouvement =
                        (int)$ligne['id'];

                    mysqli_query(
                        $conn,
                        "UPDATE mouvements
                         SET description='$descEsc'
                         WHERE id=$idMouvement"
                    );
                }

                $message =
                    "La facture $numero est maintenant entièrement payée.";

            } else {

                $erreur =
                    "Cette facture est déjà payée.";
            }

        } else {

            $erreur =
                "Facture introuvable.";
        }
    }
}

/* ============================================================
   PRODUITS
============================================================ */

$produits = [];

$qProduits = mysqli_query(
    $conn,
    "SELECT
        id,
        nom,
        categorie,
        prix_achat,
        stock
     FROM produits
     ORDER BY nom ASC"
);

if ($qProduits) {

    while ($row =
        mysqli_fetch_assoc($qProduits)) {

        $produits[] = $row;
    }
}

/* ============================================================
   FACTURES
============================================================ */

$factures = [];

$qFactures = mysqli_query(
    $conn,
    "SELECT
        id,
        produit_id,
        quantite,
        prix,
        description,
        date_mouvement
     FROM mouvements
     WHERE type='ENTREE'
     AND description LIKE 'FACTURE=%'
     ORDER BY id DESC"
);

if ($qFactures) {

    $groupes = [];

    while ($row =
        mysqli_fetch_assoc($qFactures)) {

        $data =
            parseFacture(
                $row['description']
            );

        if (empty($data['FACTURE'])) {
            continue;
        }

        $numero =
            $data['FACTURE'];

        if (!isset($groupes[$numero])) {

            $groupes[$numero] = [
                'numero' =>
                    $numero,

                'fournisseur' =>
                    $data['FOURNISSEUR'] ?? '',

                'statut' =>
                    $data['STATUT'] ?? 'IMPAYEE',

                'paye' =>
                    (float)($data['PAYE'] ?? 0),

                'reste' =>
                    (float)($data['RESTE'] ?? 0),

                'total' =>
                    0,

                'date' =>
                    $row['date_mouvement'],

                'lignes' =>
                    0
            ];
        }

        $groupes[$numero]['total'] +=
            ((float)$row['quantite'] *
             (float)$row['prix']);

        $groupes[$numero]['lignes']++;
    }

    $factures =
        array_values($groupes);
}

/* ============================================================
   STOCK
============================================================ */

$totalStock = 0;
$valeurStock = 0;

foreach ($produits as $p) {

    $stock =
        (int)($p['stock'] ?? 0);

    $prix =
        (float)($p['prix_achat'] ?? 0);

    $totalStock += $stock;

    $valeurStock +=
        ($stock * $prix);
}

$totalFactures =
    count($factures);

$totalImpayes = 0;
$totalPartiel = 0;
$totalPaye = 0;

foreach ($factures as $f) {

    if ($f['statut'] === 'IMPAYEE') {
        $totalImpayes++;
    }

    if ($f['statut'] === 'PARTIELLE') {
        $totalPartiel++;
    }

    if ($f['statut'] === 'PAYEE') {
        $totalPaye++;
    }
}

/* ============================================================
   IMPRESSION
============================================================ */

if (isset($_GET['imprimer'])) {

    $numero =
        trim($_GET['imprimer']);

    $numeroEsc =
        mysqli_real_escape_string(
            $conn,
            $numero
        );

    $q = mysqli_query(
        $conn,
        "SELECT *
         FROM mouvements
         WHERE type='ENTREE'
         AND description LIKE 'FACTURE=$numeroEsc|%'
         ORDER BY id ASC"
    );

    $lignes = [];

    if ($q) {

        while ($row =
            mysqli_fetch_assoc($q)) {

            $lignes[] = $row;
        }
    }

    if (empty($lignes)) {
        die("Facture introuvable.");
    }

    $infos =
        parseFacture(
            $lignes[0]['description']
        );

    $total = 0;

    foreach ($lignes as $l) {

        $total +=
            ((float)$l['quantite'] *
             (float)$l['prix']);
    }

    ?>
    <!DOCTYPE html>
    <html lang="fr">

    <head>

        <meta charset="UTF-8">

        <meta name="viewport"
              content="width=device-width,initial-scale=1">

        <title><?= h($numero) ?></title>

        <style>

            body {
                font-family: Arial,sans-serif;
                margin: 30px;
                color:#111827;
            }

            .facture {
                max-width:850px;
                margin:auto;
            }

            h1 {
                margin-bottom:5px;
            }

            .entete {
                display:flex;
                justify-content:space-between;
                border-bottom:2px solid #111827;
                padding-bottom:20px;
                margin-bottom:25px;
            }

            table {
                width:100%;
                border-collapse:collapse;
                margin-top:25px;
            }

            th,td {
                border:1px solid #ddd;
                padding:10px;
            }

            th {
                background:#f1f5f9;
            }

            .total {
                margin-top:25px;
                text-align:right;
                font-size:18px;
            }

            .actions {
                margin-bottom:20px;
            }

            button {
                padding:10px 18px;
                cursor:pointer;
            }

            @media print {
                .actions {
                    display:none;
                }

                body {
                    margin:0;
                }
            }

        </style>

    </head>

    <body>

    <div class="facture">

        <div class="actions">

            <button onclick="window.print()">
                🖨️ Imprimer / PDF
            </button>

            <button onclick="window.history.back()">
                Retour
            </button>

        </div>

        <div class="entete">

            <div>

                <h1>LAMBEMAH GESTION</h1>

                <div>
                    Facture d'achat
                </div>

            </div>

            <div>

                <strong>
                    <?= h($numero) ?>
                </strong>

                <br>

                Date :
                <?= h(
                    date(
                        'd/m/Y',
                        strtotime(
                            $lignes[0]['date_mouvement']
                        )
                    )
                ) ?>

            </div>

        </div>

        <p>

            <strong>
                Fournisseur :
            </strong>

            <?= h(
                $infos['FOURNISSEUR'] ?? ''
            ) ?>

        </p>

        <table>

            <thead>

            <tr>

                <th>Article</th>
                <th>Quantité</th>
                <th>Prix d'achat</th>
                <th>Total</th>

            </tr>

            </thead>

            <tbody>

            <?php foreach ($lignes as $l): ?>

                <tr>

                    <td>

                        <?php

                        $pid =
                            (int)$l['produit_id'];

                        $rp = mysqli_query(
                            $conn,
                            "SELECT nom
                             FROM produits
                             WHERE id=$pid
                             LIMIT 1"
                        );

                        $nomProduit =
                            'Article';

                        if (
                            $rp &&
                            $pp =
                                mysqli_fetch_assoc($rp)
                        ) {

                            $nomProduit =
                                $pp['nom'];
                        }

                        echo h($nomProduit);

                        ?>

                    </td>

                    <td>
                        <?= (int)$l['quantite'] ?>
                    </td>

                    <td>
                        <?= money($l['prix']) ?>
                    </td>

                    <td>
                        <?= money(
                            (float)$l['quantite'] *
                            (float)$l['prix']
                        ) ?>
                    </td>

                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

        <div class="total">

            <div>

                <strong>
                    Total facture :
                </strong>

                <?= money($total) ?>

            </div>

            <div>

                Payé :
                <?= money(
                    $infos['PAYE'] ?? 0
                ) ?>

            </div>

            <div>

                Reste :
                <?= money(
                    $infos['RESTE'] ?? 0
                ) ?>

            </div>

            <br>

            <strong>

                Statut :
                <?= h(
                    $infos['STATUT'] ?? ''
                ) ?>

            </strong>

        </div>

    </div>

    </body>

    </html>

    <?php
    exit;
}

?>
<!DOCTYPE html>
<html lang="fr">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width,initial-scale=1">

<title>
LAMBEMAH GESTION - Achats
</title>

<style>

* {
    box-sizing:border-box;
}

body {
    margin:0;
    font-family:Arial,sans-serif;
    background:#f4f7fb;
    color:#172033;
}

.top {
    background:#0f2747;
    color:white;
    padding:14px 20px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.top strong {
    font-size:18px;
}

.top a {
    color:white;
    text-decoration:none;
    margin-left:15px;
    font-size:13px;
}

.container {
    max-width:1250px;
    margin:20px auto;
    padding:0 15px;
}

.title {
    margin-bottom:15px;
}

.title h1 {
    margin:0;
    font-size:24px;
}

.title p {
    margin:5px 0;
    color:#64748b;
    font-size:13px;
}

.stats {
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:12px;
    margin-bottom:18px;
}

.card {
    background:white;
    border-radius:10px;
    padding:15px;
    box-shadow:0 2px 8px rgba(0,0,0,.05);
}

.card small {
    color:#64748b;
    display:block;
    margin-bottom:6px;
}

.card strong {
    font-size:20px;
}

.grid {
    display:grid;
    grid-template-columns:1fr 1.4fr;
    gap:18px;
    align-items:start;
}

.box {
    background:white;
    border-radius:10px;
    padding:18px;
    margin-bottom:18px;
    box-shadow:0 2px 8px rgba(0,0,0,.05);
}

.box h2 {
    margin:0 0 15px;
    font-size:17px;
}

.edit-title {
    background:#fff7ed;
    border:1px solid #fed7aa;
    color:#9a3412;
    padding:10px;
    border-radius:8px;
    margin-bottom:15px;
    font-size:12px;
    font-weight:bold;
}

label {
    display:block;
    font-size:12px;
    font-weight:bold;
    margin-bottom:5px;
}

input,
select {
    width:100%;
    padding:10px;
    border:1px solid #d7dee8;
    border-radius:7px;
    margin-bottom:12px;
    background:white;
}

button,
.btn {
    border:0;
    background:#0f2747;
    color:white;
    padding:10px 14px;
    border-radius:7px;
    cursor:pointer;
    text-decoration:none;
    display:inline-block;
    font-size:12px;
}

button:hover,
.btn:hover {
    opacity:.9;
}

.btn-danger {
    background:#b42318;
}

.btn-success {
    background:#16794c;
}

.btn-warning {
    background:#a86400;
}

.btn-secondary {
    background:#64748b;
}

.message {
    background:#e8f7ee;
    color:#17663e;
    padding:12px;
    border-radius:7px;
    margin-bottom:15px;
}

.error {
    background:#fff0f0;
    color:#a11a1a;
    padding:12px;
    border-radius:7px;
    margin-bottom:15px;
}

.brouillon {
    border:2px solid #dbeafe;
}

.ligne {
    border-bottom:1px solid #edf1f5;
    padding:10px 0;
    display:flex;
    justify-content:space-between;
    gap:10px;
    align-items:center;
}

.ligne:last-child {
    border-bottom:0;
}

.ligne-info {
    flex:1;
}

.ligne-info strong {
    display:block;
    font-size:13px;
}

.ligne-info small {
    color:#64748b;
}

.total-brouillon {
    margin-top:15px;
    padding-top:15px;
    border-top:2px solid #e5e7eb;
    text-align:right;
    font-size:18px;
}

.facture {
    border:1px solid #e1e7ef;
    border-radius:9px;
    margin-bottom:10px;
    padding:13px;
}

.facture-header {
    display:flex;
    justify-content:space-between;
    gap:10px;
    align-items:center;
}

.facture-header strong {
    font-size:13px;
}

.facture-info {
    margin-top:8px;
    font-size:12px;
    color:#64748b;
}

.facture-actions {
    margin-top:10px;
    display:flex;
    gap:7px;
    flex-wrap:wrap;
}

.badge {
    padding:5px 8px;
    border-radius:20px;
    font-size:10px;
    font-weight:bold;
}

.impayee {
    background:#fee2e2;
    color:#991b1b;
}

.partielle {
    background:#fef3c7;
    color:#92400e;
}

.payee {
    background:#dcfce7;
    color:#166534;
}

table {
    width:100%;
    border-collapse:collapse;
}

th,
td {
    padding:9px;
    border-bottom:1px solid #edf1f5;
    text-align:left;
    font-size:12px;
}

th {
    color:#64748b;
    font-size:11px;
}

.stock-value {
    text-align:right;
    white-space:nowrap;
}

.mini {
    font-size:11px;
    color:#64748b;
}

.locked {
    color:#166534;
    font-size:11px;
    font-weight:bold;
}

@media(max-width:850px) {

    .stats {
        grid-template-columns:repeat(2,1fr);
    }

    .grid {
        grid-template-columns:1fr;
    }
}

@media(max-width:550px) {

    .top {
        padding:12px;
    }

    .top strong {
        font-size:15px;
    }

    .top a {
        font-size:11px;
        margin-left:8px;
    }

    .container {
        margin-top:12px;
        padding:0 9px;
    }

    .stats {
        grid-template-columns:repeat(2,1fr);
        gap:7px;
    }

    .card {
        padding:11px;
    }

    .card strong {
        font-size:16px;
    }

    .box {
        padding:12px;
    }

    table {
        display:block;
        overflow-x:auto;
        white-space:nowrap;
    }

    .facture-actions .btn,
    .facture-actions button {
        font-size:11px;
        padding:9px 10px;
    }
}

</style>

</head>

<body>

<div class="top">

    <strong>
        💼 LAMBEMAH GESTION
    </strong>

    <div>

        <a href="index.php">
            Accueil
        </a>

        <a href="ventes.php">
            Ventes
        </a>

    </div>

</div>

<div class="container">

    <div class="title">

        <h1>
            Achats & Stock
        </h1>

        <p>
            Création et suivi des factures d'achat
        </p>

    </div>

    <?php if ($message): ?>

        <div class="message">
            <?= h($message) ?>
        </div>

    <?php endif; ?>

    <?php if ($erreur): ?>

        <div class="error">
            <?= h($erreur) ?>
        </div>

    <?php endif; ?>

    <!-- =====================================================
         STATISTIQUES
    ====================================================== -->

    <div class="stats">

        <div class="card">

            <small>
                Factures d'achat
            </small>

            <strong>
                <?= $totalFactures ?>
            </strong>

        </div>

        <div class="card">

            <small>
                Factures impayées
            </small>

            <strong>
                <?= $totalImpayes ?>
            </strong>

        </div>

        <div class="card">

            <small>
                Articles en stock
            </small>

            <strong>
                <?= number_format(
                    $totalStock,
                    0,
                    ',',
                    ' '
                ) ?>
            </strong>

        </div>

        <div class="card">

            <small>
                Valeur du stock
            </small>

            <strong>
                <?= money($valeurStock) ?>
            </strong>

        </div>

    </div>

    <div class="grid">

        <!-- =================================================
             NOUVEL ACHAT / MODIFICATION
        ================================================== -->

        <div>

            <div class="box">

                <?php
                $estModification =
                    ($brouillon['mode'] ?? 'nouveau')
                    === 'modification';
                ?>

                <?php if ($estModification): ?>

                    <div class="edit-title">

                        ✏️ MODIFICATION DE FACTURE

                        <br>

                        <?= h(
                            $brouillon['numero_facture']
                        ) ?>

                        <br>

                        <span style="font-weight:normal;">
                            Cette facture n'est pas encore
                            totalement payée. Vous pouvez
                            corriger les articles, quantités
                            et prix.
                        </span>

                    </div>

                    <h2>
                        ✏️ Modifier la facture
                    </h2>

                <?php else: ?>

                    <h2>
                        🧾 Nouvelle facture d'achat
                    </h2>

                <?php endif; ?>

                <!-- FOURNISSEUR + ARTICLE DANS LE MEME FORMULAIRE -->

                <form method="post">

                    <label>
                        Fournisseur
                    </label>

                    <input
                        type="text"
                        name="fournisseur"
                        value="<?= h(
                            $brouillon['fournisseur']
                        ) ?>"
                        placeholder="Nom du fournisseur"
                        required
                    >

                    <label>
                        Article
                    </label>

                    <select
                        name="produit_id"
                        required
                    >

                        <option value="">
                            -- Choisir un article --
                        </option>

                        <?php foreach ($produits as $p): ?>

                            <option
                                value="<?= (int)$p['id'] ?>"
                            >

                                <?= h($p['nom']) ?>

                                —
                                stock :
                                <?= (int)$p['stock'] ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                    <label>
                        Quantité achetée
                    </label>

                    <input
                        type="number"
                        name="quantite"
                        min="1"
                        value="1"
                        required
                    >

                    <label>
                        Prix d'achat unitaire
                    </label>

                    <input
                        type="number"
                        name="prix_achat"
                        min="0"
                        step="1"
                        placeholder="Ex : 15000"
                        required
                    >

                    <button
                        type="submit"
                        name="ajouter_ligne"
                    >

                        + Ajouter l'article

                    </button>

                </form>

                <?php if ($estModification): ?>

                    <br>

                    <a
                        class="btn btn-secondary"
                        href="produits.php?annuler_modification=1"
                    >

                        ← Annuler la modification

                    </a>

                <?php endif; ?>

            </div>

            <!-- =================================================
                 BROUILLON
            ================================================== -->

            <div class="box brouillon">

                <h2>
                    📝
                    <?= $estModification
                        ? 'Facture à corriger'
                        : 'Facture en saisie'
                    ?>
                </h2>

                <?php if (empty($brouillon['lignes'])): ?>

                    <div class="mini">

                        Aucun article ajouté.

                        Ajoutez les articles un par un.

                    </div>

                <?php else: ?>

                    <?php

                    $totalBrouillon = 0;

                    foreach (
                        $brouillon['lignes']
                        as $i => $ligne
                    ):

                        $totalBrouillon +=
                            $ligne['montant'];

                    ?>

                        <div class="ligne">

                            <div class="ligne-info">

                                <strong>

                                    <?= h(
                                        $ligne['nom']
                                    ) ?>

                                </strong>

                                <small>

                                    <?= (int)
                                        $ligne['quantite']
                                    ?>

                                    ×

                                    <?= money(
                                        $ligne['prix']
                                    ) ?>

                                </small>

                            </div>

                            <strong>

                                <?= money(
                                    $ligne['montant']
                                ) ?>

                            </strong>

                            <a
                                class="btn btn-danger"
                                href="produits.php?supprimer_ligne=<?= $i ?>"
                            >

                                ×

                            </a>

                        </div>

                    <?php endforeach; ?>

                    <div class="total-brouillon">

                        <strong>

                            Total :

                            <?= money(
                                $totalBrouillon
                            ) ?>

                        </strong>

                    </div>

                    <hr
                        style="
                        border:0;
                        border-top:1px solid #eee;
                        margin:15px 0;
                        "
                    >

                    <?php if ($estModification): ?>

                        <div class="mini"
                             style="margin-bottom:10px;">

                            Déjà payé :

                            <strong>

                                <?= money(
                                    $brouillon[
                                        'paye_existant'
                                    ] ?? 0
                                ) ?>

                            </strong>

                        </div>

                        <button
                            type="button"
                            class="btn-success"
                            onclick="
                                document
                                .getElementById('formValidation')
                                .submit();
                            "
                        >

                            ✓ Enregistrer la correction

                        </button>

                        <form
                            method="post"
                            id="formValidation"
                            style="display:none;"
                        >

                            <input
                                type="hidden"
                                name="fournisseur"
                                value="<?= h(
                                    $brouillon[
                                        'fournisseur'
                                    ]
                                ) ?>"
                            >

                            <button
                                type="submit"
                                name="valider_facture"
                            ></button>

                        </form>

                    <?php else: ?>

                        <form method="post">

                            <input
                                type="hidden"
                                name="fournisseur"
                                value="<?= h(
                                    $brouillon[
                                        'fournisseur'
                                    ]
                                ) ?>"
                            >

                            <label>
                                Montant payé maintenant
                            </label>

                            <input
                                type="number"
                                name="paye"
                                min="0"
                                step="1"
                                value="0"
                            >

                            <button
                                type="submit"
                                name="valider_facture"
                                class="btn-success"
                            >

                                ✓ Valider la facture

                            </button>

                        </form>

                    <?php endif; ?>

                    <br>

                    <form method="post">

                        <button
                            type="submit"
                            name="vider_brouillon"
                            class="btn-danger"
                        >

                            Vider la saisie

                        </button>

                    </form>

                <?php endif; ?>

            </div>

        </div>

        <!-- =================================================
             FACTURES
        ================================================== -->

        <div>

            <div class="box">

                <h2>
                    📋 Factures d'achat
                </h2>

                <?php if (empty($factures)): ?>

                    <div class="mini">

                        Aucune facture d'achat enregistrée.

                    </div>

                <?php else: ?>

                    <?php foreach ($factures as $f): ?>

                        <div class="facture">

                            <div class="facture-header">

                                <div>

                                    <strong>

                                        <?= h(
                                            $f['numero']
                                        ) ?>

                                    </strong>

                                    <br>

                                    <span class="mini">

                                        <?= h(
                                            $f['fournisseur']
                                        ) ?>

                                    </span>

                                </div>

                                <?php

                                $classe =
                                    'impayee';

                                $texte =
                                    'IMPAYÉE';

                                if (
                                    $f['statut']
                                    === 'PARTIELLE'
                                ) {

                                    $classe =
                                        'partielle';

                                    $texte =
                                        'PARTIELLEMENT PAYÉE';
                                }

                                if (
                                    $f['statut']
                                    === 'PAYEE'
                                ) {

                                    $classe =
                                        'payee';

                                    $texte =
                                        'PAYÉE';
                                }

                                ?>

                                <span
                                    class="badge <?= $classe ?>"
                                >

                                    <?= $texte ?>

                                </span>

                            </div>

                            <div class="facture-info">

                                <?= (int)
                                    $f['lignes']
                                ?>

                                article(s)

                                • Total :

                                <strong>

                                    <?= money(
                                        $f['total']
                                    ) ?>

                                </strong>

                                • Payé :

                                <?= money(
                                    $f['paye']
                                ) ?>

                                • Reste :

                                <strong>

                                    <?= money(
                                        $f['reste']
                                    ) ?>

                                </strong>

                            </div>

                            <div class="facture-actions">

                                <!-- IMPRIMER -->

                                <a
                                    class="btn"
                                    href="produits.php?imprimer=<?= urlencode(
                                        $f['numero']
                                    ) ?>"
                                    target="_blank"
                                >

                                    🖨️ Facture

                                </a>

                                <?php if ($f['reste'] > 0): ?>

                                    <!-- MODIFIER -->

                                    <a
                                        class="btn btn-warning"
                                        href="produits.php?modifier_facture=<?= urlencode(
                                            $f['numero']
                                        ) ?>"
                                    >

                                        ✏️ Modifier

                                    </a>

                                    <!-- RÉGLER -->

                                    <form
                                        method="post"
                                        style="display:inline;"
                                    >

                                        <input
                                            type="hidden"
                                            name="numero_facture"
                                            value="<?= h(
                                                $f['numero']
                                            ) ?>"
                                        >

                                        <button
                                            type="submit"
                                            name="regler_facture"
                                            class="btn-success"
                                        >

                                            💰 Régler

                                        </button>

                                    </form>

                                <?php else: ?>

                                    <span class="locked">

                                        🔒 Facture verrouillée
                                        — entièrement payée

                                    </span>

                                <?php endif; ?>

                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php endif; ?>

            </div>

        </div>

    </div>

    <!-- =====================================================
         STOCK
    ====================================================== -->

    <div class="box">

        <h2>
            📦 Stock actuel
        </h2>

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
                    Stock
                </th>

                <th>
                    Prix d'achat
                </th>

                <th>
                    Valeur
                </th>

            </tr>

            </thead>

            <tbody>

            <?php foreach ($produits as $p): ?>

                <?php

                $stock =
                    (int)($p['stock'] ?? 0);

                $prix =
                    (float)($p['prix_achat'] ?? 0);

                $valeur =
                    $stock * $prix;

                ?>

                <tr>

                    <td>

                        <strong>

                            <?= h(
                                $p['nom']
                            ) ?>

                        </strong>

                    </td>

                    <td>

                        <?= h(
                            $p['categorie'] ?? ''
                        ) ?>

                    </td>

                    <td>

                        <?= number_format(
                            $stock,
                            0,
                            ',',
                            ' '
                        ) ?>

                    </td>

                    <td>

                        <?= money(
                            $prix
                        ) ?>

                    </td>

                    <td class="stock-value">

                        <?= money(
                            $valeur
                        ) ?>

                    </td>

                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>

</body>

</html>
