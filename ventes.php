<?php
session_start();
require_once "config.php";

/*
|--------------------------------------------------------------------------
| LAMBEMAH GESTION - VENTES
|--------------------------------------------------------------------------
| - Vente avec plusieurs articles
| - Client
| - Prix de vente unitaire
| - Quantité
| - Payé / avance / reste
| - Modification si aucun paiement
| - Suppression uniquement si aucun paiement
| - Annulation si paiement/avance déjà effectué
| - Impression facture
| - Correction automatique du stock
| - Aucun ajout de table
|--------------------------------------------------------------------------
*/

$message = "";
$erreur = "";

/* =========================================================
   OUTILS
========================================================= */

function prochainId($conn, $table)
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);

    $sql = "SELECT COALESCE(MAX(id), 0) + 1 AS prochain_id FROM `$table`";
    $result = $conn->query($sql);

    if (!$result) {
        throw new Exception($conn->error);
    }

    $row = $result->fetch_assoc();
    return (int)$row['prochain_id'];
}

function montantDepuisDescription($description)
{
    if (preg_match('/Payé\s*:\s*([0-9\s.,]+)/i', $description, $m)) {
        $valeur = str_replace([' ', ','], ['', '.'], $m[1]);
        return (float)$valeur;
    }

    return 0;
}

function estAnnulee($description)
{
    return stripos($description, "ANNULÉE") !== false ||
           stripos($description, "ANNULEE") !== false;
}

function estPayeeOuAvance($description)
{
    return montantDepuisDescription($description) > 0;
}

function formatMoney($montant)
{
    return number_format((float)$montant, 0, ',', ' ') . " FG";
}

/* =========================================================
   ANNULER UNE VENTE
   On ne supprime pas la ligne.
   On restaure le stock et on garde l'historique.
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["annuler_vente"])) {

    $vente_id = (int)($_POST["vente_id"] ?? 0);

    try {

        $stmt = $conn->prepare("
            SELECT *
            FROM ventes
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->bind_param("i", $vente_id);
        $stmt->execute();

        $result = $stmt->get_result();
        $vente = $result->fetch_assoc();

        if (!$vente) {
            throw new Exception("Vente introuvable.");
        }

        if (estAnnulee($vente['description'])) {
            throw new Exception("Cette vente est déjà annulée.");
        }

        /*
         * On peut annuler même une facture avec avance,
         * mais on ne la supprime jamais.
         */

        $conn->begin_transaction();

        /* Restaurer le stock */
        $stmtStock = $conn->prepare("
            UPDATE produits
            SET stock = stock + ?
            WHERE id = ?
        ");

        $stmtStock->bind_param(
            "ii",
            $vente['quantite'],
            $vente['produit_id']
        );

        if (!$stmtStock->execute()) {
            throw new Exception($stmtStock->error);
        }

        /* Mouvement inverse */
        $mouvement_id = prochainId($conn, "mouvements");
        $date_mouvement = date("Y-m-d H:i:s");

        $descriptionMouvement =
            "ANNULATION VENTE #" . $vente_id .
            " | Restauration stock | " .
            $vente['description'];

        $stmtMouvement = $conn->prepare("
            INSERT INTO mouvements
            (id, produit_id, type, quantite, prix, description, date_mouvement)
            VALUES (?, ?, 'ENTREE', ?, ?, ?, ?)
        ");

        $stmtMouvement->bind_param(
            "iiidss",
            $mouvement_id,
            $vente['produit_id'],
            $vente['quantite'],
            $vente['prix_unitaire'],
            $descriptionMouvement,
            $date_mouvement
        );

        if (!$stmtMouvement->execute()) {
            throw new Exception($stmtMouvement->error);
        }

        /* Marquer la vente comme annulée */
        $nouvelleDescription =
            $vente['description'] .
            " | ANNULÉE LE " . date("d/m/Y H:i");

        $stmtUpdate = $conn->prepare("
            UPDATE ventes
            SET description = ?
            WHERE id = ?
        ");

        $stmtUpdate->bind_param(
            "si",
            $nouvelleDescription,
            $vente_id
        );

        if (!$stmtUpdate->execute()) {
            throw new Exception($stmtUpdate->error);
        }

        $conn->commit();

        $message = "La vente #".$vente_id." a été annulée et le stock a été restauré.";

    } catch (Exception $e) {

        if ($conn->errno) {
            @$conn->rollback();
        }

        $erreur = "Impossible d'annuler la vente : " . $e->getMessage();
    }
}


/* =========================================================
   SUPPRIMER UNE VENTE
   Autorisé uniquement si aucun paiement / avance.
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["supprimer_vente"])) {

    $vente_id = (int)($_POST["vente_id"] ?? 0);

    try {

        $stmt = $conn->prepare("
            SELECT *
            FROM ventes
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->bind_param("i", $vente_id);
        $stmt->execute();

        $result = $stmt->get_result();
        $vente = $result->fetch_assoc();

        if (!$vente) {
            throw new Exception("Vente introuvable.");
        }

        if (estAnnulee($vente['description'])) {
            throw new Exception("Cette vente est déjà annulée.");
        }

        /* PROTECTION PAIEMENT */
        if (estPayeeOuAvance($vente['description'])) {
            throw new Exception(
                "Cette facture ne peut pas être supprimée car un paiement ou une avance existe. Utilisez ANNULER."
            );
        }

        $conn->begin_transaction();

        /* Restaurer le stock */
        $stmtStock = $conn->prepare("
            UPDATE produits
            SET stock = stock + ?
            WHERE id = ?
        ");

        $stmtStock->bind_param(
            "ii",
            $vente['quantite'],
            $vente['produit_id']
        );

        if (!$stmtStock->execute()) {
            throw new Exception($stmtStock->error);
        }

        /* Mouvement inverse */
        $mouvement_id = prochainId($conn, "mouvements");
        $date_mouvement = date("Y-m-d H:i:s");

        $descriptionMouvement =
            "SUPPRESSION VENTE #" . $vente_id .
            " | Stock restauré";

        $stmtMouvement = $conn->prepare("
            INSERT INTO mouvements
            (id, produit_id, type, quantite, prix, description, date_mouvement)
            VALUES (?, ?, 'ENTREE', ?, ?, ?, ?)
        ");

        $stmtMouvement->bind_param(
            "iiidss",
            $mouvement_id,
            $vente['produit_id'],
            $vente['quantite'],
            $vente['prix_unitaire'],
            $descriptionMouvement,
            $date_mouvement
        );

        if (!$stmtMouvement->execute()) {
            throw new Exception($stmtMouvement->error);
        }

        /* Supprimer la vente */
        $stmtDelete = $conn->prepare("
            DELETE FROM ventes
            WHERE id = ?
        ");

        $stmtDelete->bind_param("i", $vente_id);

        if (!$stmtDelete->execute()) {
            throw new Exception($stmtDelete->error);
        }

        $conn->commit();

        $message = "La vente #".$vente_id." a été supprimée.";

    } catch (Exception $e) {

        @$conn->rollback();

        $erreur = "Impossible de supprimer la vente : " . $e->getMessage();
    }
}


/* =========================================================
   MODIFIER UNE VENTE
   Autorisé uniquement si aucun paiement.
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["modifier_vente"])) {

    $vente_id = (int)($_POST["vente_id"] ?? 0);
    $nouveau_produit = (int)($_POST["produit_id"] ?? 0);
    $nouvelle_quantite = (int)($_POST["quantite"] ?? 0);
    $nouveau_pvu = (float)($_POST["prix_unitaire"] ?? 0);
    $nouveau_client = trim($_POST["client"] ?? "");

    try {

        if ($nouvelle_quantite <= 0) {
            throw new Exception("La quantité doit être supérieure à zéro.");
        }

        if ($nouveau_pvu <= 0) {
            throw new Exception("Le prix de vente doit être supérieur à zéro.");
        }

        $stmt = $conn->prepare("
            SELECT *
            FROM ventes
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->bind_param("i", $vente_id);
        $stmt->execute();

        $result = $stmt->get_result();
        $ancienne = $result->fetch_assoc();

        if (!$ancienne) {
            throw new Exception("Vente introuvable.");
        }

        if (estAnnulee($ancienne['description'])) {
            throw new Exception("Une vente annulée ne peut pas être modifiée.");
        }

        if (estPayeeOuAvance($ancienne['description'])) {
            throw new Exception(
                "Cette facture est verrouillée car un paiement ou une avance existe."
            );
        }

        /* Vérifier le produit */
        $stmtProduit = $conn->prepare("
            SELECT id, nom, stock
            FROM produits
            WHERE id = ?
            LIMIT 1
        ");

        $stmtProduit->bind_param("i", $nouveau_produit);
        $stmtProduit->execute();

        $produit = $stmtProduit->get_result()->fetch_assoc();

        if (!$produit) {
            throw new Exception("Produit introuvable.");
        }

        /*
         * L'ancien stock avait déjà été diminué.
         * On le remet d'abord.
         */
        $conn->begin_transaction();

        $stmtStockAncien = $conn->prepare("
            UPDATE produits
            SET stock = stock + ?
            WHERE id = ?
        ");

        $stmtStockAncien->bind_param(
            "ii",
            $ancienne['quantite'],
            $ancienne['produit_id']
        );

        if (!$stmtStockAncien->execute()) {
            throw new Exception($stmtStockAncien->error);
        }

        /* Vérifier le nouveau stock */
        $stmtStock = $conn->prepare("
            SELECT stock
            FROM produits
            WHERE id = ?
            LIMIT 1
        ");

        $stmtStock->bind_param("i", $nouveau_produit);
        $stmtStock->execute();

        $stockDisponible = (int)$stmtStock->get_result()->fetch_assoc()['stock'];

        if ($stockDisponible < $nouvelle_quantite) {
            throw new Exception(
                "Stock insuffisant. Disponible : " . $stockDisponible
            );
        }

        /* Déduire le nouveau stock */
        $stmtStockNouveau = $conn->prepare("
            UPDATE produits
            SET stock = stock - ?
            WHERE id = ?
        ");

        $stmtStockNouveau->bind_param(
            "ii",
            $nouvelle_quantite,
            $nouveau_produit
        );

        if (!$stmtStockNouveau->execute()) {
            throw new Exception($stmtStockNouveau->error);
        }

        $montant = $nouvelle_quantite * $nouveau_pvu;

        /*
         * On garde la date originale.
         * Le paiement reste 0 puisque la facture était impayée.
         */
        $description =
            "Client : " . ($nouveau_client ?: "Non renseigné") .
            " | Vente de " . $produit['nom'] .
            " | Quantité : " . $nouvelle_quantite .
            " | Total : " . $montant .
            " | Payé : 0" .
            " | Reste : " . $montant;

        $stmtUpdate = $conn->prepare("
            UPDATE ventes
            SET produit_id = ?,
                quantite = ?,
                prix_unitaire = ?,
                montant = ?,
                description = ?
            WHERE id = ?
        ");

        $stmtUpdate->bind_param(
            "iiddsi",
            $nouveau_produit,
            $nouvelle_quantite,
            $nouveau_pvu,
            $montant,
            $description,
            $vente_id
        );

        if (!$stmtUpdate->execute()) {
            throw new Exception($stmtUpdate->error);
        }

        $conn->commit();

        $message = "La vente #".$vente_id." a été modifiée.";

    } catch (Exception $e) {

        @$conn->rollback();

        $erreur = "Impossible de modifier la vente : " . $e->getMessage();
    }
}


/* =========================================================
   AJOUTER UNE VENTE
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["enregistrer_vente"])) {

    $client = trim($_POST["client"] ?? "");
    $payé = (float)($_POST["paye"] ?? 0);

    $produitsIds = $_POST["produit_id"] ?? [];
    $quantites = $_POST["quantite"] ?? [];
    $prixUnitaires = $_POST["prix_unitaire"] ?? [];

    try {

        if (!is_array($produitsIds)) {
            $produitsIds = [];
        }

        if (count($produitsIds) === 0) {
            throw new Exception("Ajoutez au moins un article.");
        }

        if ($payé < 0) {
            throw new Exception("Le montant payé est incorrect.");
        }

        $lignes = [];
        $total = 0;

        /* Vérification des lignes */
        foreach ($produitsIds as $i => $produit_id) {

            $produit_id = (int)$produit_id;
            $quantite = (int)($quantites[$i] ?? 0);
            $pvu = (float)($prixUnitaires[$i] ?? 0);

            if ($produit_id <= 0 || $quantite <= 0 || $pvu <= 0) {
                continue;
            }

            $stmt = $conn->prepare("
                SELECT id, nom, stock
                FROM produits
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->bind_param("i", $produit_id);
            $stmt->execute();

            $produit = $stmt->get_result()->fetch_assoc();

            if (!$produit) {
                throw new Exception("Un des articles sélectionnés est introuvable.");
            }

            if ($produit['stock'] < $quantite) {
                throw new Exception(
                    "Stock insuffisant pour : " .
                    $produit['nom'] .
                    " | Disponible : " .
                    $produit['stock']
                );
            }

            $montantLigne = $quantite * $pvu;

            $lignes[] = [
                "produit_id" => $produit_id,
                "nom" => $produit['nom'],
                "quantite" => $quantite,
                "pvu" => $pvu,
                "montant" => $montantLigne
            ];

            $total += $montantLigne;
        }

        if (count($lignes) === 0) {
            throw new Exception("Aucun article valide n'a été ajouté.");
        }

        if ($payé > $total) {
            throw new Exception(
                "Le montant payé ne peut pas dépasser le total."
            );
        }

        $reste = $total - $payé;

        $conn->begin_transaction();

        $date_vente = date("Y-m-d H:i:s");

        /*
         * Pour chaque article, une ligne est créée dans ventes.
         * On garde le même client et les informations de paiement
         * dans la description.
         */

        foreach ($lignes as $ligne) {

            $vente_id = prochainId($conn, "ventes");

            $description =
                "Client : " . ($client ?: "Non renseigné") .
                " | Vente de " . $ligne['nom'] .
                " | Quantité : " . $ligne['quantite'] .
                " | Total facture : " . $total .
                " | Payé : " . $payé .
                " | Reste : " . $reste;

            $stmt = $conn->prepare("
                INSERT INTO ventes
                (id, produit_id, quantite, prix_unitaire, montant, description, date_vente)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->bind_param(
                "iiiddss",
                $vente_id,
                $ligne['produit_id'],
                $ligne['quantite'],
                $ligne['pvu'],
                $ligne['montant'],
                $description,
                $date_vente
            );

            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }

            /* Déduction du stock */
            $stmtStock = $conn->prepare("
                UPDATE produits
                SET stock = stock - ?
                WHERE id = ?
            ");

            $stmtStock->bind_param(
                "ii",
                $ligne['quantite'],
                $ligne['produit_id']
            );

            if (!$stmtStock->execute()) {
                throw new Exception($stmtStock->error);
            }

            /* Mouvement SORTIE */
            $mouvement_id = prochainId($conn, "mouvements");

            $descriptionMouvement =
                "VENTE #" . $vente_id .
                " | Client : " . ($client ?: "Non renseigné") .
                " | " . $ligne['nom'];

            $stmtMouvement = $conn->prepare("
                INSERT INTO mouvements
                (id, produit_id, type, quantite, prix, description, date_mouvement)
                VALUES (?, ?, 'SORTIE', ?, ?, ?, ?)
            ");

            $stmtMouvement->bind_param(
                "iiidss",
                $mouvement_id,
                $ligne['produit_id'],
                $ligne['quantite'],
                $ligne['pvu'],
                $descriptionMouvement,
                $date_vente
            );

            if (!$stmtMouvement->execute()) {
                throw new Exception($stmtMouvement->error);
            }
        }

        $conn->commit();

        $message =
            "Vente enregistrée avec succès. Total : " .
            formatMoney($total) .
            " | Payé : " .
            formatMoney($payé) .
            " | Reste : " .
            formatMoney($reste);

    } catch (Exception $e) {

        @$conn->rollback();

        $erreur = "Impossible d'enregistrer la vente : " . $e->getMessage();
    }
}


/* =========================================================
   FACTURE
========================================================= */

if (isset($_GET["facture"])) {

    $vente_id = (int)$_GET["facture"];

    $stmt = $conn->prepare("
        SELECT
            v.*,
            p.nom AS produit_nom
        FROM ventes v
        LEFT JOIN produits p ON p.id = v.produit_id
        WHERE v.id = ?
        LIMIT 1
    ");

    $stmt->bind_param("i", $vente_id);
    $stmt->execute();

    $facture = $stmt->get_result()->fetch_assoc();

    if (!$facture) {
        die("Facture introuvable.");
    }

    $description = $facture['description'];

    preg_match('/Client\s*:\s*(.*?)\s*\|/i', $description, $clientMatch);
    $clientFacture = $clientMatch[1] ?? "Non renseigné";

    $payeFacture = montantDepuisDescription($description);
    $totalFacture = (float)$facture['montant'];

    if (preg_match('/Total facture\s*:\s*([0-9\s.,]+)/i', $description, $totalMatch)) {
        $totalFacture = (float)str_replace(
            [' ', ','],
            ['', '.'],
            $totalMatch[1]
        );
    }

    $resteFacture = $totalFacture - $payeFacture;

    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Facture #<?= $facture['id'] ?></title>

        <style>
            body {
                font-family: Arial, sans-serif;
                background: #f2f5f9;
                margin: 0;
                padding: 20px;
                color: #172033;
            }

            .facture {
                max-width: 800px;
                margin: auto;
                background: white;
                padding: 35px;
                border-radius: 12px;
                box-shadow: 0 5px 25px rgba(0,0,0,.08);
            }

            .haut {
                display: flex;
                justify-content: space-between;
                gap: 20px;
                border-bottom: 2px solid #172033;
                padding-bottom: 20px;
            }

            h1 {
                margin: 0;
            }

            .numero {
                text-align: right;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 30px;
            }

            th, td {
                padding: 12px;
                border-bottom: 1px solid #ddd;
                text-align: left;
            }

            th {
                background: #172033;
                color: white;
            }

            .totaux {
                margin-top: 25px;
                margin-left: auto;
                max-width: 350px;
            }

            .ligne-total {
                display: flex;
                justify-content: space-between;
                padding: 8px 0;
            }

            .grand-total {
                font-size: 22px;
                font-weight: bold;
                border-top: 2px solid #172033;
            }

            .boutons {
                margin-top: 30px;
                text-align: center;
            }

            button {
                background: #172033;
                color: white;
                border: 0;
                padding: 12px 22px;
                border-radius: 8px;
                cursor: pointer;
            }

            @media print {
                body {
                    background: white;
                    padding: 0;
                }

                .facture {
                    box-shadow: none;
                    border-radius: 0;
                }

                .boutons {
                    display: none;
                }
            }
        </style>
    </head>

    <body>

    <div class="facture">

        <div class="haut">
            <div>
                <h1>LAMBEMAH GESTION</h1>
                <p>Facture commerciale</p>
            </div>

            <div class="numero">
                <strong>FACTURE #<?= $facture['id'] ?></strong><br>
                <?= date("d/m/Y H:i", strtotime($facture['date_vente'])) ?>
            </div>
        </div>

        <p>
            <strong>Client :</strong>
            <?= htmlspecialchars($clientFacture) ?>
        </p>

        <table>
            <thead>
            <tr>
                <th>Désignation</th>
                <th>Qté</th>
                <th>PVU</th>
                <th>Montant</th>
            </tr>
            </thead>

            <tbody>
            <tr>
                <td><?= htmlspecialchars($facture['produit_nom'] ?? 'Article') ?></td>
                <td><?= $facture['quantite'] ?></td>
                <td><?= formatMoney($facture['prix_unitaire']) ?></td>
                <td><?= formatMoney($facture['montant']) ?></td>
            </tr>
            </tbody>
        </table>

        <div class="totaux">

            <div class="ligne-total">
                <span>Total</span>
                <strong><?= formatMoney($totalFacture) ?></strong>
            </div>

            <div class="ligne-total">
                <span>Payé / Avance</span>
                <strong><?= formatMoney($payeFacture) ?></strong>
            </div>

            <div class="ligne-total grand-total">
                <span>Reste</span>
                <strong><?= formatMoney($resteFacture) ?></strong>
            </div>

        </div>

        <div class="boutons">
            <button onclick="window.print()">
                🖨️ Imprimer / Enregistrer PDF
            </button>
        </div>

    </div>

    </body>
    </html>

    <?php
    exit;
}


/* =========================================================
   PRODUITS POUR LES LISTES
========================================================= */

$produits = [];

$resultProduits = $conn->query("
    SELECT id, nom, categorie, prix_achat, stock
    FROM produits
    ORDER BY nom ASC
");

if ($resultProduits) {
    while ($row = $resultProduits->fetch_assoc()) {
        $produits[] = $row;
    }
}


/* =========================================================
   HISTORIQUE DES VENTES
========================================================= */

$ventes = [];

$resultVentes = $conn->query("
    SELECT
        v.*,
        p.nom AS produit_nom
    FROM ventes v
    LEFT JOIN produits p ON p.id = v.produit_id
    ORDER BY v.date_vente DESC, v.id DESC
");

if ($resultVentes) {

    while ($row = $resultVentes->fetch_assoc()) {

        $description = $row['description'];

        $client = "Non renseigné";

        if (preg_match('/Client\s*:\s*(.*?)\s*\|/i', $description, $m)) {
            $client = trim($m[1]);
        }

        $paye = montantDepuisDescription($description);

        $totalFacture = (float)$row['montant'];

        if (preg_match('/Total facture\s*:\s*([0-9\s.,]+)/i', $description, $m)) {
            $totalFacture = (float)str_replace(
                [' ', ','],
                ['', '.'],
                $m[1]
            );
        }

        $reste = $totalFacture - $paye;

        $row['client'] = $client;
        $row['paye'] = $paye;
        $row['total_facture'] = $totalFacture;
        $row['reste'] = $reste;
        $row['annulee'] = estAnnulee($description);

        $ventes[] = $row;
    }
}


/* =========================================================
   STATISTIQUES
========================================================= */

$totalVentes = 0;
$totalPaye = 0;
$totalReste = 0;

foreach ($ventes as $vente) {

    if (!$vente['annulee']) {

        $totalVentes += $vente['montant'];
        $totalPaye += $vente['paye'];
        $totalReste += $vente['reste'];
    }
}

?>
<!DOCTYPE html>
<html lang="fr">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Ventes - LAMBEMAH GESTION</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f3f6fa;
            color: #172033;
        }

        .container {
            max-width: 1250px;
            margin: auto;
            padding: 20px;
        }

        .top {
            background: #172033;
            color: white;
            padding: 18px 22px;
            border-radius: 12px;
            margin-bottom: 20px;
        }

        .top h1 {
            margin: 0;
            font-size: 25px;
        }

        .top p {
            margin: 5px 0 0;
            opacity: .8;
        }

        .message {
            background: #d1fae5;
            color: #065f46;
            padding: 13px;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .erreur {
            background: #fee2e2;
            color: #991b1b;
            padding: 13px;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat {
            background: white;
            padding: 18px;
            border-radius: 12px;
            box-shadow: 0 3px 15px rgba(0,0,0,.05);
        }

        .stat small {
            color: #6b7280;
        }

        .stat strong {
            display: block;
            font-size: 20px;
            margin-top: 6px;
        }

        .card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 3px 15px rgba(0,0,0,.05);
        }

        .card h2 {
            margin-top: 0;
            font-size: 20px;
        }

        .ligne {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto;
            gap: 10px;
            margin-bottom: 10px;
            align-items: end;
        }

        .champ {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        label {
            font-size: 13px;
            font-weight: bold;
        }

        input,
        select {
            width: 100%;
            padding: 11px;
            border: 1px solid #d5dae2;
            border-radius: 8px;
            background: white;
        }

        button {
            border: 0;
            border-radius: 8px;
            padding: 11px 15px;
            cursor: pointer;
            font-weight: bold;
        }

        .btn-principal {
            background: #172033;
            color: white;
        }

        .btn-ajouter {
            background: #e8eef7;
            color: #172033;
        }

        .btn-supprimer {
            background: #fee2e2;
            color: #991b1b;
        }

        .btn-modifier {
            background: #e0f2fe;
            color: #075985;
        }

        .btn-annuler {
            background: #fff7ed;
            color: #9a3412;
        }

        .btn-facture {
            background: #ecfdf5;
            color: #047857;
        }

        .total-box {
            background: #f5f7fa;
            padding: 15px;
            border-radius: 10px;
            margin: 15px 0;
        }

        .total-box strong {
            font-size: 22px;
        }

        .paiement {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 950px;
        }

        th,
        td {
            padding: 11px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
        }

        th {
            background: #172033;
            color: white;
        }

        .badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-ok {
            background: #dcfce7;
            color: #166534;
        }

        .badge-reste {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-annule {
            background: #fee2e2;
            color: #991b1b;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }

        .actions form {
            margin: 0;
        }

        .aide {
            background: #eef4ff;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 14px;
        }

        @media (max-width: 800px) {

            .container {
                padding: 10px;
            }

            .stats {
                grid-template-columns: 1fr;
            }

            .ligne {
                grid-template-columns: 1fr;
            }

            .paiement {
                grid-template-columns: 1fr;
            }

            .top h1 {
                font-size: 21px;
            }

            .card {
                padding: 15px;
            }
        }

    </style>

</head>

<body>

<div class="container">

    <div class="top">
        <h1>🛒 VENTES</h1>
        <p>Revente des articles — LAMBEMAH GESTION</p>
    </div>


    <?php if ($message): ?>
        <div class="message">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>


    <?php if ($erreur): ?>
        <div class="erreur">
            <?= htmlspecialchars($erreur) ?>
        </div>
    <?php endif; ?>


    <!-- STATISTIQUES -->

    <div class="stats">

        <div class="stat">
            <small>Ventes enregistrées</small>
            <strong><?= count($ventes) ?></strong>
        </div>

        <div class="stat">
            <small>Total vendu</small>
            <strong><?= formatMoney($totalVentes) ?></strong>
        </div>

        <div class="stat">
            <small>Reste clients</small>
            <strong><?= formatMoney($totalReste) ?></strong>
        </div>

    </div>


    <!-- NOUVELLE VENTE -->

    <div class="card">

        <h2>➕ Nouvelle vente</h2>

        <div class="aide">
            Ajoutez un ou plusieurs articles. Le stock sera automatiquement diminué.
            Vous pouvez enregistrer un paiement complet, une avance ou zéro paiement.
        </div>

        <form method="POST">

            <div class="champ" style="margin-bottom:15px;">
                <label>Client</label>

                <input
                    type="text"
                    name="client"
                    placeholder="Nom du client"
                >
            </div>


            <div id="lignes">

                <div class="ligne ligne-vente">

                    <div class="champ">

                        <label>Article</label>

                        <select
                            name="produit_id[]"
                            onchange="calculerTotal()"
                            required
                        >

                            <option value="">
                                -- Choisir un article --
                            </option>

                            <?php foreach ($produits as $produit): ?>

                                <option
                                    value="<?= $produit['id'] ?>"
                                    data-stock="<?= $produit['stock'] ?>"
                                >
                                    <?= htmlspecialchars($produit['nom']) ?>
                                    — Stock : <?= $produit['stock'] ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div class="champ">

                        <label>PVU (FG)</label>

                        <input
                            type="number"
                            name="prix_unitaire[]"
                            min="1"
                            step="1"
                            oninput="calculerTotal()"
                            required
                        >

                    </div>


                    <div class="champ">

                        <label>Quantité</label>

                        <input
                            type="number"
                            name="quantite[]"
                            min="1"
                            value="1"
                            oninput="calculerTotal()"
                            required
                        >

                    </div>


                    <button
                        type="button"
                        class="btn-supprimer"
                        onclick="supprimerLigne(this)"
                    >
                        ✕
                    </button>

                </div>

            </div>


            <button
                type="button"
                class="btn-ajouter"
                onclick="ajouterLigne()"
            >
                + Ajouter un autre article
            </button>


            <div class="total-box">

                Total de la vente :

                <strong>
                    <span id="total">0</span> FG
                </strong>

            </div>


            <div class="paiement">

                <div class="champ">

                    <label>Payé / Avance (FG)</label>

                    <input
                        type="number"
                        name="paye"
                        id="paye"
                        value="0"
                        min="0"
                        step="1"
                        oninput="calculerReste()"
                    >

                </div>


                <div class="champ">

                    <label>Reste client</label>

                    <input
                        type="text"
                        id="reste"
                        value="0 FG"
                        readonly
                    >

                </div>

            </div>


            <br>

            <button
                type="submit"
                name="enregistrer_vente"
                class="btn-principal"
            >
                💾 Enregistrer la vente
            </button>

        </form>

    </div>


    <!-- HISTORIQUE -->

    <div class="card">

        <h2>📋 Historique des ventes</h2>

        <div class="table-container">

            <table>

                <thead>

                <tr>
                    <th>N°</th>
                    <th>Client</th>
                    <th>Désignation</th>
                    <th>PVU</th>
                    <th>Qté</th>
                    <th>Total</th>
                    <th>Payé</th>
                    <th>Reste</th>
                    <th>État</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>

                </thead>

                <tbody>

                <?php if (empty($ventes)): ?>

                    <tr>
                        <td colspan="11">
                            Aucune vente enregistrée.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($ventes as $vente): ?>

                        <tr>

                            <td>
                                <strong>#<?= $vente['id'] ?></strong>
                            </td>

                            <td>
                                <?= htmlspecialchars($vente['client']) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($vente['produit_nom'] ?? 'Article supprimé') ?>
                            </td>

                            <td>
                                <?= formatMoney($vente['prix_unitaire']) ?>
                            </td>

                            <td>
                                <?= $vente['quantite'] ?>
                            </td>

                            <td>
                                <?= formatMoney($vente['total_facture']) ?>
                            </td>

                            <td>
                                <?= formatMoney($vente['paye']) ?>
                            </td>

                            <td>
                                <?= formatMoney($vente['reste']) ?>
                            </td>

                            <td>

                                <?php if ($vente['annulee']): ?>

                                    <span class="badge badge-annule">
                                        ANNULÉE
                                    </span>

                                <?php elseif ($vente['reste'] <= 0): ?>

                                    <span class="badge badge-ok">
                                        PAYÉE
                                    </span>

                                <?php elseif ($vente['paye'] > 0): ?>

                                    <span class="badge badge-reste">
                                        AVANCE
                                    </span>

                                <?php else: ?>

                                    <span class="badge badge-reste">
                                        NON PAYÉE
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td>
                                <?= date(
                                    "d/m/Y H:i",
                                    strtotime($vente['date_vente'])
                                ) ?>
                            </td>

                            <td>

                                <div class="actions">

                                    <!-- FACTURE -->

                                    <a
                                        href="?facture=<?= $vente['id'] ?>"
                                        target="_blank"
                                    >
                                        <button
                                            type="button"
                                            class="btn-facture"
                                        >
                                            🧾 Facture
                                        </button>
                                    </a>


                                    <?php if (!$vente['annulee']): ?>

                                        <?php if ($vente['paye'] <= 0): ?>

                                            <!-- MODIFIER -->

                                            <button
                                                type="button"
                                                class="btn-modifier"
                                                onclick="ouvrirModification(
                                                    <?= $vente['id'] ?>,
                                                    <?= $vente['produit_id'] ?>,
                                                    <?= $vente['quantite'] ?>,
                                                    <?= $vente['prix_unitaire'] ?>,
                                                    '<?= htmlspecialchars(
                                                        $vente['client'],
                                                        ENT_QUOTES
                                                    ) ?>'
                                                )"
                                            >
                                                ✏️ Modifier
                                            </button>


                                            <!-- SUPPRIMER -->

                                            <form
                                                method="POST"
                                                onsubmit="return confirm(
                                                    'Supprimer définitivement cette vente ? Le stock sera restauré.'
                                                )"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="vente_id"
                                                    value="<?= $vente['id'] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    name="supprimer_vente"
                                                    class="btn-supprimer"
                                                >
                                                    🗑️ Supprimer
                                                </button>

                                            </form>

                                        <?php endif; ?>


                                        <!-- ANNULER -->

                                        <form
                                            method="POST"
                                            onsubmit="return confirm(
                                                'Annuler cette vente ? Le stock sera restauré.'
                                            )"
                                        >

                                            <input
                                                type="hidden"
                                                name="vente_id"
                                                value="<?= $vente['id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                name="annuler_vente"
                                                class="btn-annuler"
                                            >
                                                ↩️ Annuler
                                            </button>

                                        </form>

                                    <?php endif; ?>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>


    <!-- FORMULAIRE MODIFICATION -->

    <div
        class="card"
        id="modification"
        style="display:none;"
    >

        <h2>✏️ Modifier la vente</h2>

        <div class="aide">
            Une facture avec avance ou paiement ne peut pas être modifiée.
        </div>

        <form method="POST">

            <input
                type="hidden"
                name="vente_id"
                id="mod_vente_id"
            >

            <div class="champ">

                <label>Client</label>

                <input
                    type="text"
                    name="client"
                    id="mod_client"
                >

            </div>

            <br>

            <div class="champ">

                <label>Article</label>

                <select
                    name="produit_id"
                    id="mod_produit"
                    required
                >

                    <?php foreach ($produits as $produit): ?>

                        <option value="<?= $produit['id'] ?>">
                            <?= htmlspecialchars($produit['nom']) ?>
                            — Stock : <?= $produit['stock'] ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <br>

            <div class="paiement">

                <div class="champ">

                    <label>PVU</label>

                    <input
                        type="number"
                        name="prix_unitaire"
                        id="mod_pvu"
                        min="1"
                        required
                    >

                </div>

                <div class="champ">

                    <label>Quantité</label>

                    <input
                        type="number"
                        name="quantite"
                        id="mod_quantite"
                        min="1"
                        required
                    >

                </div>

            </div>

            <br>

            <button
                type="submit"
                name="modifier_vente"
                class="btn-principal"
            >
                💾 Enregistrer la modification
            </button>

            <button
                type="button"
                class="btn-supprimer"
                onclick="fermerModification()"
            >
                Fermer
            </button>

        </form>

    </div>

</div>


<script>

function calculerTotal() {

    let total = 0;

    const lignes = document.querySelectorAll(".ligne-vente");

    lignes.forEach(function(ligne) {

        const prix = parseFloat(
            ligne.querySelector(
                'input[name="prix_unitaire[]"]'
            ).value
        ) || 0;

        const quantite = parseInt(
            ligne.querySelector(
                'input[name="quantite[]"]'
            ).value
        ) || 0;

        total += prix * quantite;

    });

    document.getElementById("total").innerText =
        total.toLocaleString("fr-FR");

    calculerReste();
}


function calculerReste() {

    let totalText =
        document.getElementById("total").innerText
            .replace(/\s/g, "")
            .replace(/,/g, "");

    let total = parseFloat(totalText) || 0;

    let paye =
        parseFloat(
            document.getElementById("paye").value
        ) || 0;

    let reste = total - paye;

    if (reste < 0) {
        reste = 0;
    }

    document.getElementById("reste").value =
        reste.toLocaleString("fr-FR") + " FG";
}


function ajouterLigne() {

    const conteneur =
        document.getElementById("lignes");

    const premiere =
        document.querySelector(".ligne-vente");

    const nouvelle =
        premiere.cloneNode(true);

    nouvelle
        .querySelector(
            'select[name="produit_id[]"]'
        )
        .value = "";

    nouvelle
        .querySelector(
            'input[name="prix_unitaire[]"]'
        )
        .value = "";

    nouvelle
        .querySelector(
            'input[name="quantite[]"]'
        )
        .value = "1";

    conteneur.appendChild(nouvelle);

    calculerTotal();
}


function supprimerLigne(bouton) {

    const lignes =
        document.querySelectorAll(".ligne-vente");

    if (lignes.length <= 1) {

        alert("Il faut garder au moins un article.");

        return;
    }

    bouton.closest(".ligne-vente").remove();

    calculerTotal();
}


function ouvrirModification(
    id,
    produit,
    quantite,
    pvu,
    client
) {

    document.getElementById(
        "modification"
    ).style.display = "block";

    document.getElementById(
        "mod_vente_id"
    ).value = id;

    document.getElementById(
        "mod_produit"
    ).value = produit;

    document.getElementById(
        "mod_quantite"
    ).value = quantite;

    document.getElementById(
        "mod_pvu"
    ).value = pvu;

    document.getElementById(
        "mod_client"
    ).value = client;

    document.getElementById(
        "modification"
    ).scrollIntoView({
        behavior: "smooth"
    });
}


function fermerModification() {

    document.getElementById(
        "modification"
    ).style.display = "none";

}

</script>

</body>
</html>
