<?php
session_start();

/* =========================
   CONNEXION BASE DE DONNÉES
   ========================= */
require_once "config.php";

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Erreur : connexion à la base de données impossible.");
}

$conn->set_charset("utf8mb4");


/* =========================
   DÉCONNEXION
   ========================= */
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}


/* =========================
   AJOUTER UNE VENTE
   ========================= */
$message = "";
$message_type = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["enregistrer_vente"])) {

    $client = trim($_POST["client"] ?? "");
    $client = $client !== "" ? $client : "Client comptant";

    $produits_ids = $_POST["produit_id"] ?? [];
    $quantites    = $_POST["quantite"] ?? [];
    $prix_unitaires = $_POST["prix_unitaire"] ?? [];

    $montant_paye = floatval($_POST["montant_paye"] ?? 0);

    if (!is_array($produits_ids)) {
        $produits_ids = [];
    }

    if (!is_array($quantites)) {
        $quantites = [];
    }

    if (!is_array($prix_unitaires)) {
        $prix_unitaires = [];
    }

    /* =========================
       PRÉPARATION DES ARTICLES
       ========================= */

    $articles = [];
    $total_vente = 0;

    foreach ($produits_ids as $i => $produit_id) {

        $produit_id = intval($produit_id);
        $quantite = intval($quantites[$i] ?? 0);
        $prix_unitaire = floatval($prix_unitaires[$i] ?? 0);

        if ($produit_id <= 0 || $quantite <= 0 || $prix_unitaire < 0) {
            continue;
        }

        /* Vérifier le produit */
        $stmtProduit = $conn->prepare("
            SELECT id, nom, stock
            FROM produits
            WHERE id = ?
            LIMIT 1
        ");

        if (!$stmtProduit) {
            $message = "Erreur lors de la vérification du produit.";
            $message_type = "danger";
            break;
        }

        $stmtProduit->bind_param("i", $produit_id);
        $stmtProduit->execute();

        $resultProduit = $stmtProduit->get_result();
        $produit = $resultProduit->fetch_assoc();

        $stmtProduit->close();

        if (!$produit) {
            $message = "Un des articles sélectionnés n'existe pas.";
            $message_type = "danger";
            break;
        }

        $stock_actuel = intval($produit["stock"]);

        if ($quantite > $stock_actuel) {
            $message = "Stock insuffisant pour : " . htmlspecialchars($produit["nom"]) .
                       ". Stock disponible : " . $stock_actuel;
            $message_type = "danger";
            break;
        }

        $montant_ligne = $quantite * $prix_unitaire;

        $articles[] = [
            "produit_id" => $produit_id,
            "nom" => $produit["nom"],
            "quantite" => $quantite,
            "prix_unitaire" => $prix_unitaire,
            "montant" => $montant_ligne
        ];

        $total_vente += $montant_ligne;
    }

    /* =========================
       VÉRIFICATION
       ========================= */

    if ($message === "" && count($articles) === 0) {
        $message = "Ajoute au moins un article.";
        $message_type = "danger";
    }

    if ($message === "" && $montant_paye < 0) {
        $message = "Le montant payé est invalide.";
        $message_type = "danger";
    }

    if ($message === "" && $montant_paye > $total_vente) {
        $montant_paye = $total_vente;
    }

    $reste_client = $total_vente - $montant_paye;

    /* =========================
       ENREGISTREMENT
       ========================= */

    if ($message === "") {

        $conn->begin_transaction();

        try {

            /*
             * DATE AUTOMATIQUE
             * Exemple : 2026-09-05 14:30:00
             */
            $date_vente = date("Y-m-d H:i:s");

            /*
             * Générer manuellement l'ID de la vente
             * pour éviter l'erreur :
             * "Field 'id' doesn't have a default value"
             */
            $resultId = $conn->query("
                SELECT COALESCE(MAX(id), 0) + 1 AS prochain_id
                FROM ventes
            ");

            if (!$resultId) {
                throw new Exception("Impossible de générer l'identifiant de la vente.");
            }

            $rowId = $resultId->fetch_assoc();
            $prochain_id_vente = intval($rowId["prochain_id"]);

            /*
             * Description générale
             */
            $description_base =
                "VENTE | Client : " . $client .
                " | Total : " . number_format($total_vente, 0, ',', ' ') . " FG" .
                " | Payé : " . number_format($montant_paye, 0, ',', ' ') . " FG" .
                " | Reste client : " . number_format($reste_client, 0, ',', ' ') . " FG";

            /*
             * Une ligne dans ventes pour chaque article.
             */
            foreach ($articles as $article) {

                /*
                 * ID manuel pour chaque ligne de vente
                 */
                if ($prochain_id_vente > 1) {
                    $prochain_id_vente++;
                }

                $description_article =
                    $description_base .
                    " | Article : " . $article["nom"] .
                    " | Quantité : " . $article["quantite"];

                $stmt = $conn->prepare("
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
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                if (!$stmt) {
                    throw new Exception(
                        "Erreur préparation vente : " . $conn->error
                    );
                }

                $stmt->bind_param(
                    "iiiddss",
                    $prochain_id_vente,
                    $article["produit_id"],
                    $article["quantite"],
                    $article["prix_unitaire"],
                    $article["montant"],
                    $description_article,
                    $date_vente
                );

                if (!$stmt->execute()) {
                    throw new Exception(
                        "Erreur enregistrement vente : " . $stmt->error
                    );
                }

                $stmt->close();


                /* =========================
                   DIMINUER LE STOCK
                   ========================= */

                $stmtStock = $conn->prepare("
                    UPDATE produits
                    SET stock = stock - ?
                    WHERE id = ?
                ");

                if (!$stmtStock) {
                    throw new Exception(
                        "Erreur préparation stock : " . $conn->error
                    );
                }

                $stmtStock->bind_param(
                    "ii",
                    $article["quantite"],
                    $article["produit_id"]
                );

                if (!$stmtStock->execute()) {
                    throw new Exception(
                        "Erreur mise à jour du stock : " . $stmtStock->error
                    );
                }

                $stmtStock->close();


                /* =========================
                   MOUVEMENT SORTIE
                   ========================= */

                $resultMvtId = $conn->query("
                    SELECT COALESCE(MAX(id), 0) + 1 AS prochain_id
                    FROM mouvements
                ");

                if (!$resultMvtId) {
                    throw new Exception(
                        "Impossible de générer l'identifiant du mouvement."
                    );
                }

                $rowMvt = $resultMvtId->fetch_assoc();
                $prochain_id_mouvement = intval($rowMvt["prochain_id"]);

                $description_mouvement =
                    "SORTIE - REVENTE | Client : " . $client .
                    " | Article : " . $article["nom"] .
                    " | Quantité : " . $article["quantite"] .
                    " | PVU : " . number_format($article["prix_unitaire"], 0, ',', ' ') . " FG" .
                    " | Montant : " . number_format($article["montant"], 0, ',', ' ') . " FG" .
                    " | Total vente : " . number_format($total_vente, 0, ',', ' ') . " FG" .
                    " | Payé : " . number_format($montant_paye, 0, ',', ' ') . " FG" .
                    " | Reste : " . number_format($reste_client, 0, ',', ' ') . " FG";

                $stmtMvt = $conn->prepare("
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
                    VALUES (?, ?, 'SORTIE', ?, ?, ?, ?)
                ");

                if (!$stmtMvt) {
                    throw new Exception(
                        "Erreur préparation mouvement : " . $conn->error
                    );
                }

                $stmtMvt->bind_param(
                    "iiidss",
                    $prochain_id_mouvement,
                    $article["produit_id"],
                    $article["quantite"],
                    $article["prix_unitaire"],
                    $description_mouvement,
                    $date_vente
                );

                if (!$stmtMvt->execute()) {
                    throw new Exception(
                        "Erreur enregistrement mouvement : " . $stmtMvt->error
                    );
                }

                $stmtMvt->close();
            }

            $conn->commit();

            $message = "Vente enregistrée avec succès !";
            $message_type = "success";

        } catch (Exception $e) {

            $conn->rollback();

            $message = "Impossible d'enregistrer la vente : " . $e->getMessage();
            $message_type = "danger";
        }
    }
}


/* =========================
   PRODUITS DISPONIBLES
   ========================= */

$produits = [];

$resultProduits = $conn->query("
    SELECT id, nom, stock
    FROM produits
    ORDER BY nom ASC
");

if ($resultProduits) {
    while ($row = $resultProduits->fetch_assoc()) {
        $produits[] = $row;
    }
}


/* =========================
   HISTORIQUE DES VENTES
   ========================= */

$ventes = [];

$resultVentes = $conn->query("
    SELECT
        v.id,
        v.produit_id,
        v.quantite,
        v.prix_unitaire,
        v.montant,
        v.description,
        v.date_vente,
        p.nom AS produit_nom
    FROM ventes v
    LEFT JOIN produits p ON p.id = v.produit_id
    ORDER BY v.id DESC
    LIMIT 100
");

if ($resultVentes) {
    while ($row = $resultVentes->fetch_assoc()) {
        $ventes[] = $row;
    }
}


/* =========================
   STATISTIQUES
   ========================= */

$total_ventes = 0;
$total_articles_vendus = 0;

$resultStats = $conn->query("
    SELECT
        COALESCE(SUM(montant), 0) AS total_ventes,
        COALESCE(SUM(quantite), 0) AS total_articles
    FROM ventes
");

if ($resultStats) {
    $stats = $resultStats->fetch_assoc();

    $total_ventes = floatval($stats["total_ventes"]);
    $total_articles_vendus = intval($stats["total_articles"]);
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
    font-family: Arial, Helvetica, sans-serif;
    background: #f4f7fb;
    color: #172033;
}

/* =========================
   HEADER
   ========================= */

.header {
    background: #0b1f3a;
    color: white;
    padding: 15px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 100;
}

.logo {
    font-size: 21px;
    font-weight: bold;
}

.menu-btn {
    background: #173b68;
    border: none;
    color: white;
    padding: 9px 12px;
    border-radius: 8px;
    font-size: 20px;
    cursor: pointer;
}

/* =========================
   SIDEBAR
   ========================= */

.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    width: 240px;
    height: 100vh;
    background: #0b1f3a;
    color: white;
    padding: 20px;
    z-index: 200;
    overflow-y: auto;
}

.sidebar h2 {
    margin-top: 0;
    margin-bottom: 25px;
}

.sidebar a {
    display: block;
    color: white;
    text-decoration: none;
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 6px;
}

.sidebar a:hover {
    background: #173b68;
}

.close-menu {
    display: none;
}

/* =========================
   CONTENU
   ========================= */

.main {
    margin-left: 240px;
    padding: 25px;
}

.page-title {
    margin-bottom: 20px;
}

.page-title h1 {
    margin: 0;
    color: #0b1f3a;
}

.page-title p {
    color: #667085;
}

/* =========================
   STATISTIQUES
   ========================= */

.stats {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
    margin-bottom: 25px;
}

.stat {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 3px 12px rgba(0,0,0,0.06);
}

.stat small {
    color: #667085;
}

.stat strong {
    display: block;
    margin-top: 7px;
    font-size: 24px;
    color: #0b1f3a;
}

/* =========================
   CARTES
   ========================= */

.card {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 3px 12px rgba(0,0,0,0.06);
    margin-bottom: 25px;
}

.card h2 {
    margin-top: 0;
    color: #0b1f3a;
}

/* =========================
   FORMULAIRE
   ========================= */

.form-group {
    margin-bottom: 15px;
}

label {
    display: block;
    margin-bottom: 6px;
    font-weight: bold;
}

input,
select {
    width: 100%;
    padding: 12px;
    border: 1px solid #d0d5dd;
    border-radius: 8px;
    font-size: 15px;
    background: white;
}

input:focus,
select:focus {
    outline: none;
    border-color: #2f80ed;
}

/* =========================
   LIGNES ARTICLES
   ========================= */

.article-line {
    border: 1px solid #dce3ec;
    padding: 15px;
    border-radius: 10px;
    margin-bottom: 12px;
    background: #fafcff;
}

.article-grid {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr auto;
    gap: 10px;
    align-items: end;
}

.line-total {
    font-weight: bold;
    padding: 12px 5px;
}

.btn-remove {
    background: #dc3545;
    color: white;
    border: none;
    padding: 11px 13px;
    border-radius: 8px;
    cursor: pointer;
}

/* =========================
   BOUTONS
   ========================= */

.btn {
    border: none;
    border-radius: 8px;
    padding: 12px 17px;
    cursor: pointer;
    font-weight: bold;
    font-size: 14px;
}

.btn-add {
    background: #e8f1ff;
    color: #1456a0;
}

.btn-save {
    background: #0b1f3a;
    color: white;
    width: 100%;
    margin-top: 15px;
}

.btn-save:hover {
    background: #173b68;
}

/* =========================
   TOTAL
   ========================= */

.payment-box {
    background: #f5f8fc;
    border-radius: 10px;
    padding: 18px;
    margin-top: 20px;
}

.total-row {
    display: flex;
    justify-content: space-between;
    padding: 7px 0;
}

.total-row strong {
    font-size: 19px;
}

.reste {
    color: #c62828;
}

/* =========================
   MESSAGE
   ========================= */

.alert {
    padding: 14px;
    border-radius: 9px;
    margin-bottom: 20px;
}

.alert-success {
    background: #e7f7ed;
    color: #176b3a;
}

.alert-danger {
    background: #fdecec;
    color: #a51d1d;
}

/* =========================
   TABLEAU
   ========================= */

.table-container {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 750px;
}

th,
td {
    padding: 12px;
    border-bottom: 1px solid #e5e7eb;
    text-align: left;
}

th {
    background: #f4f7fb;
    color: #0b1f3a;
}

.badge {
    display: inline-block;
    padding: 5px 8px;
    border-radius: 6px;
    background: #e8f1ff;
    color: #1456a0;
    font-size: 12px;
}

/* =========================
   MOBILE
   ========================= */

@media (max-width: 900px) {

    .sidebar {
        transform: translateX(-100%);
        transition: 0.25s;
    }

    .sidebar.active {
        transform: translateX(0);
    }

    .close-menu {
        display: block;
        text-align: right;
        cursor: pointer;
        font-size: 24px;
        margin-bottom: 15px;
    }

    .main {
        margin-left: 0;
        padding: 15px;
    }

    .stats {
        grid-template-columns: 1fr;
    }

    .article-grid {
        grid-template-columns: 1fr;
    }

    .line-total {
        padding: 5px 0;
    }

    .header {
        padding: 12px 15px;
    }

    .card {
        padding: 15px;
    }
}

</style>

</head>

<body>


<!-- =========================
     SIDEBAR
     ========================= -->

<div class="sidebar" id="sidebar">

    <div class="close-menu" onclick="fermerMenu()">×</div>

    <h2>LTK</h2>

    <a href="index.php">🏠 Accueil</a>

    <a href="produits.php">📦 Achats / Stock</a>

    <a href="ventes.php">💰 Revente</a>

    <a href="depenses.php">💸 Dépenses</a>

    <a href="recettes.php">💵 Recettes</a>

    <a href="statistiques.php">📊 Statistiques</a>

    <a href="utilisateurs.php">👥 Utilisateurs</a>

    <a href="index.php?logout=1">🚪 Déconnexion</a>

</div>


<!-- =========================
     HEADER
     ========================= -->

<header class="header">

    <div class="logo">
        LAMBEMAH GESTION
    </div>

    <button class="menu-btn" onclick="ouvrirMenu()">
        ☰
    </button>

</header>


<!-- =========================
     CONTENU
     ========================= -->

<main class="main">

    <div class="page-title">

        <h1>💰 Revente</h1>

        <p>
            Enregistrer les ventes, les clients, les paiements et les restes.
        </p>

    </div>


    <?php if ($message !== ""): ?>

        <div class="alert alert-<?php echo $message_type; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>

    <?php endif; ?>


    <!-- =========================
         STATISTIQUES
         ========================= -->

    <div class="stats">

        <div class="stat">

            <small>Total des ventes</small>

            <strong>
                <?php
                echo number_format(
                    $total_ventes,
                    0,
                    ',',
                    ' '
                );
                ?>
                FG
            </strong>

        </div>


        <div class="stat">

            <small>Articles vendus</small>

            <strong>
                <?php echo number_format($total_articles_vendus, 0, ',', ' '); ?>
            </strong>

        </div>

    </div>


    <!-- =========================
         FORMULAIRE VENTE
         ========================= -->

    <div class="card">

        <h2>➕ Nouvelle vente</h2>

        <form method="POST">


            <!-- CLIENT -->

            <div class="form-group">

                <label for="client">
                    Client
                </label>

                <input
                    type="text"
                    id="client"
                    name="client"
                    placeholder="Nom du client"
                >

            </div>


            <!-- ARTICLES -->

            <h3>Articles vendus</h3>

            <div id="articles-container">


                <!-- PREMIÈRE LIGNE -->

                <div class="article-line">

                    <div class="article-grid">


                        <div>

                            <label>Article</label>

                            <select
                                name="produit_id[]"
                                class="produit"
                                onchange="calculerTotal()"
                                required
                            >

                                <option value="">
                                    -- Choisir un article --
                                </option>

                                <?php foreach ($produits as $produit): ?>

                                    <option
                                        value="<?php echo $produit['id']; ?>"
                                        data-stock="<?php echo $produit['stock']; ?>"
                                    >

                                        <?php
                                        echo htmlspecialchars(
                                            $produit['nom']
                                        );
                                        ?>

                                        — Stock :
                                        <?php echo $produit['stock']; ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <div>

                            <label>PVU (FG)</label>

                            <input
                                type="number"
                                name="prix_unitaire[]"
                                class="prix"
                                min="0"
                                step="1"
                                placeholder="0"
                                oninput="calculerTotal()"
                                required
                            >

                        </div>


                        <div>

                            <label>Quantité</label>

                            <input
                                type="number"
                                name="quantite[]"
                                class="quantite"
                                min="1"
                                step="1"
                                value="1"
                                oninput="calculerTotal()"
                                required
                            >

                        </div>


                        <div>

                            <label>Montant</label>

                            <div class="line-total">
                                <span class="montant-ligne">0</span> FG
                            </div>

                        </div>


                        <div>

                            <button
                                type="button"
                                class="btn-remove"
                                onclick="supprimerLigne(this)"
                            >
                                ×
                            </button>

                        </div>


                    </div>

                </div>

            </div>


            <button
                type="button"
                class="btn btn-add"
                onclick="ajouterLigne()"
            >
                ➕ Ajouter un autre article
            </button>


            <!-- =========================
                 PAIEMENT
                 ========================= -->

            <div class="payment-box">

                <div class="total-row">

                    <span>Total vente :</span>

                    <strong>
                        <span id="totalVente">0</span> FG
                    </strong>

                </div>


                <div class="form-group">

                    <label for="montant_paye">
                        Montant payé / avance
                    </label>

                    <input
                        type="number"
                        id="montant_paye"
                        name="montant_paye"
                        min="0"
                        step="1"
                        value="0"
                        oninput="calculerReste()"
                    >

                </div>


                <div class="total-row">

                    <span>Reste client :</span>

                    <strong class="reste">
                        <span id="resteClient">0</span> FG
                    </strong>

                </div>

            </div>


            <button
                type="submit"
                name="enregistrer_vente"
                class="btn btn-save"
            >
                💾 ENREGISTRER LA VENTE
            </button>


        </form>

    </div>


    <!-- =========================
         HISTORIQUE
         ========================= -->

    <div class="card">

        <h2>📋 Historique des ventes</h2>

        <div class="table-container">

            <table>

                <thead>

                    <tr>

                        <th>Date</th>

                        <th>Client</th>

                        <th>Article</th>

                        <th>PVU</th>

                        <th>Qté</th>

                        <th>Montant</th>

                        <th>Paiement</th>

                    </tr>

                </thead>

                <tbody>

                <?php if (count($ventes) > 0): ?>

                    <?php foreach ($ventes as $vente): ?>

                        <?php

                        $description = $vente["description"] ?? "";

                        $client_affiche = "Client comptant";

                        if (preg_match(
                            '/Client\s*:\s*(.*?)\s*\|/',
                            $description,
                            $matches
                        )) {
                            $client_affiche = trim($matches[1]);
                        }

                        $paye_affiche = null;
                        $reste_affiche = null;

                        if (preg_match(
                            '/Payé\s*:\s*([\d\s]+)\s*FG/',
                            $description,
                            $matches
                        )) {
                            $paye_affiche = trim($matches[1]);
                        }

                        if (preg_match(
                            '/Reste client\s*:\s*([\d\s]+)\s*FG/',
                            $description,
                            $matches
                        )) {
                            $reste_affiche = trim($matches[1]);
                        }

                        ?>

                        <tr>

                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $vente["date_vente"] ?? ""
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $client_affiche
                                );
                                ?>
                            </td>

                            <td>

                                <?php
                                echo htmlspecialchars(
                                    $vente["produit_nom"] ??
                                    "Article supprimé"
                                );
                                ?>

                            </td>

                            <td>

                                <?php
                                echo number_format(
                                    floatval(
                                        $vente["prix_unitaire"]
                                    ),
                                    0,
                                    ',',
                                    ' '
                                );
                                ?>

                                FG

                            </td>

                            <td>
                                <?php echo intval($vente["quantite"]); ?>
                            </td>

                            <td>

                                <strong>

                                    <?php
                                    echo number_format(
                                        floatval(
                                            $vente["montant"]
                                        ),
                                        0,
                                        ',',
                                        ' '
                                    );
                                    ?>

                                    FG

                                </strong>

                            </td>

                            <td>

                                <?php if ($paye_affiche !== null): ?>

                                    <span class="badge">
                                        Payé :
                                        <?php echo htmlspecialchars($paye_affiche); ?>
                                        FG
                                    </span>

                                    <br><br>

                                    <span class="badge">
                                        Reste :
                                        <?php echo htmlspecialchars($reste_affiche ?? "0"); ?>
                                        FG
                                    </span>

                                <?php else: ?>

                                    <span class="badge">
                                        Voir détail
                                    </span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>

                        <td colspan="7" style="text-align:center;">
                            Aucune vente enregistrée.
                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</main>


<!-- =========================
     JAVASCRIPT
     ========================= -->

<script>

function ouvrirMenu() {

    document
        .getElementById("sidebar")
        .classList
        .add("active");

}


function fermerMenu() {

    document
        .getElementById("sidebar")
        .classList
        .remove("active");

}


/* =========================
   AJOUTER UNE LIGNE
   ========================= */

function ajouterLigne() {

    const container =
        document.getElementById("articles-container");

    const premiereLigne =
        container.querySelector(".article-line");

    const nouvelleLigne =
        premiereLigne.cloneNode(true);


    /* Réinitialiser les valeurs */

    const select =
        nouvelleLigne.querySelector(".produit");

    const prix =
        nouvelleLigne.querySelector(".prix");

    const quantite =
        nouvelleLigne.querySelector(".quantite");

    const montant =
        nouvelleLigne.querySelector(".montant-ligne");


    select.value = "";

    prix.value = "";

    quantite.value = 1;

    montant.textContent = "0";


    container.appendChild(nouvelleLigne);

}


/* =========================
   SUPPRIMER UNE LIGNE
   ========================= */

function supprimerLigne(button) {

    const lignes =
        document.querySelectorAll(".article-line");


    if (lignes.length <= 1) {

        alert("Il faut garder au moins un article.");

        return;
    }


    button
        .closest(".article-line")
        .remove();


    calculerTotal();

}


/* =========================
   CALCUL TOTAL
   ========================= */

function calculerTotal() {

    const lignes =
        document.querySelectorAll(".article-line");

    let total = 0;


    lignes.forEach(function(ligne) {

        const prix =
            parseFloat(
                ligne.querySelector(".prix").value
            ) || 0;

        const quantite =
            parseInt(
                ligne.querySelector(".quantite").value
            ) || 0;

        const montant =
            prix * quantite;


        ligne.querySelector(
            ".montant-ligne"
        ).textContent =
            montant.toLocaleString("fr-FR");


        total += montant;

    });


    document.getElementById(
        "totalVente"
    ).textContent =
        total.toLocaleString("fr-FR");


    calculerReste();

}


/* =========================
   CALCUL RESTE CLIENT
   ========================= */

function calculerReste() {

    let totalText =
        document.getElementById(
            "totalVente"
        ).textContent;

    totalText =
        totalText.replace(/\s/g, "")
                  .replace(/\./g, "")
                  .replace(/,/g, "");


    const total =
        parseFloat(totalText) || 0;


    const paye =
        parseFloat(
            document.getElementById(
                "montant_paye"
            ).value
        ) || 0;


    let reste = total - paye;


    if (reste < 0) {
        reste = 0;
    }


    document.getElementById(
        "resteClient"
    ).textContent =
        reste.toLocaleString("fr-FR");

}


document.addEventListener(
    "DOMContentLoaded",
    function() {

        calculerTotal();

    }
);

</script>

</body>
</html>
