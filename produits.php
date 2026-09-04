<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["id"])) {
    header("Location: index.php");
    exit;
}

$nom  = $_SESSION["nom"] ?? "Utilisateur";
$role = $_SESSION["role"] ?? "lecture";

$message = "";
$type = "";

/* =========================================================
   AJOUTER UN PRODUIT
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ajouter_produit"])) {

    $nom_produit = trim($_POST["nom"] ?? "");
    $categorie = trim($_POST["categorie"] ?? "");
    $prix_achat = (float)($_POST["prix_achat"] ?? 0);
    $prix_vente = (float)($_POST["prix_vente"] ?? 0);
    $stock_initial = (int)($_POST["stock_initial"] ?? 0);

    if ($nom_produit === "") {

        $message = "Le nom du produit est obligatoire.";
        $type = "error";

    } elseif ($prix_achat < 0 || $prix_vente < 0) {

        $message = "Les prix ne peuvent pas être négatifs.";
        $type = "error";

    } elseif ($stock_initial < 0) {

        $message = "Le stock ne peut pas être négatif.";
        $type = "error";

    } else {

        $conn->begin_transaction();

        try {

            /* Vérifier si le produit existe déjà */
            $check = $conn->prepare(
                "SELECT id
                 FROM produits
                 WHERE nom = ?
                 LIMIT 1"
            );

            $check->bind_param("s", $nom_produit);
            $check->execute();

            $result = $check->get_result();

            if ($result->num_rows > 0) {

                throw new Exception(
                    "Ce produit existe déjà."
                );
            }

            $check->close();


            /* Ajouter le produit */
            $stmt = $conn->prepare(
                "INSERT INTO produits
                (nom, categorie, prix_achat, prix_vente, stock)
                VALUES (?, ?, ?, ?, ?)"
            );

            $stmt->bind_param(
                "ssddi",
                $nom_produit,
                $categorie,
                $prix_achat,
                $prix_vente,
                $stock_initial
            );

            if (!$stmt->execute()) {

                throw new Exception(
                    "Impossible d'ajouter le produit."
                );
            }

            $produit_id = $stmt->insert_id;

            $stmt->close();


            /*
             * Si un stock initial est indiqué,
             * on crée également un mouvement ENTREE.
             */

            if ($stock_initial > 0) {

                $description =
                    "Stock initial du produit.";

                $mouvement = $conn->prepare(
                    "INSERT INTO mouvements
                    (produit_id, type, quantite, prix, description)
                    VALUES (?, 'ENTREE', ?, ?, ?)"
                );

                $mouvement->bind_param(
                    "iids",
                    $produit_id,
                    $stock_initial,
                    $prix_achat,
                    $description
                );

                if (!$mouvement->execute()) {

                    throw new Exception(
                        "Impossible d'enregistrer le mouvement."
                    );
                }

                $mouvement->close();
            }


            $conn->commit();

            $message =
                "Produit ajouté avec succès.";

            $type = "success";

        } catch (Exception $e) {

            $conn->rollback();

            $message = $e->getMessage();
            $type = "error";
        }
    }
}


/* =========================================================
   AJOUTER UNE ENTRÉE / ACHAT DE STOCK
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["ajouter_entree"])
) {

    $produit_id = (int)($_POST["produit_id"] ?? 0);
    $quantite = (int)($_POST["quantite"] ?? 0);
    $prix = (float)($_POST["prix"] ?? 0);
    $description = trim($_POST["description"] ?? "");

    if ($produit_id <= 0) {

        $message = "Veuillez sélectionner un produit.";
        $type = "error";

    } elseif ($quantite <= 0) {

        $message = "La quantité doit être supérieure à zéro.";
        $type = "error";

    } elseif ($prix < 0) {

        $message = "Le prix ne peut pas être négatif.";
        $type = "error";

    } else {

        $conn->begin_transaction();

        try {

            /* Vérifier le produit */
            $stmt = $conn->prepare(
                "SELECT id, nom
                 FROM produits
                 WHERE id = ?
                 LIMIT 1"
            );

            $stmt->bind_param(
                "i",
                $produit_id
            );

            $stmt->execute();

            $result = $stmt->get_result();
            $produit = $result->fetch_assoc();

            $stmt->close();

            if (!$produit) {

                throw new Exception(
                    "Produit introuvable."
                );
            }


            /* Enregistrer le mouvement */
            $description_finale =
                $description !== ""
                ? $description
                : "Achat / entrée de stock";


            $mouvement = $conn->prepare(
                "INSERT INTO mouvements
                (produit_id, type, quantite, prix, description)
                VALUES (?, 'ENTREE', ?, ?, ?)"
            );

            $mouvement->bind_param(
                "iids",
                $produit_id,
                $quantite,
                $prix,
                $description_finale
            );

            if (!$mouvement->execute()) {

                throw new Exception(
                    "Impossible d'enregistrer l'entrée."
                );
            }

            $mouvement->close();


            /*
             * Augmenter le stock
             */
            $stock = $conn->prepare(
                "UPDATE produits
                 SET stock = stock + ?,
                     prix_achat = ?
                 WHERE id = ?"
            );

            $stock->bind_param(
                "idi",
                $quantite,
                $prix,
                $produit_id
            );

            if (
                !$stock->execute()
                || $stock->affected_rows !== 1
            ) {

                throw new Exception(
                    "Impossible de mettre à jour le stock."
                );
            }

            $stock->close();


            $conn->commit();

            $message =
                "Entrée enregistrée : "
                . $produit["nom"]
                . " × "
                . $quantite
                . ".";

            $type = "success";

        } catch (Exception $e) {

            $conn->rollback();

            $message = $e->getMessage();
            $type = "error";
        }
    }
}


/* =========================================================
   SUPPRIMER UN PRODUIT
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["supprimer_produit"])
) {

    if ($role !== "admin") {

        $message =
            "Seul l'administrateur peut supprimer un produit.";

        $type = "error";

    } else {

        $produit_id =
            (int)($_POST["produit_id"] ?? 0);

        if ($produit_id > 0) {

            /*
             * On vérifie d'abord s'il existe
             * dans les ventes.
             */

            $check = $conn->prepare(
                "SELECT COUNT(*) AS total
                 FROM ventes
                 WHERE produit_id = ?"
            );

            $check->bind_param(
                "i",
                $produit_id
            );

            $check->execute();

            $result = $check->get_result();
            $data = $result->fetch_assoc();

            $check->close();


            if ((int)$data["total"] > 0) {

                $message =
                    "Impossible de supprimer ce produit : "
                    . "il possède déjà des ventes.";

                $type = "error";

            } else {

                $stmt = $conn->prepare(
                    "DELETE FROM produits
                     WHERE id = ?"
                );

                $stmt->bind_param(
                    "i",
                    $produit_id
                );

                if ($stmt->execute()) {

                    $message =
                        "Produit supprimé.";

                    $type = "success";

                } else {

                    $message =
                        "Impossible de supprimer le produit.";

                    $type = "error";
                }

                $stmt->close();
            }
        }
    }
}


/* =========================================================
   RÉCUPÉRER LES PRODUITS
========================================================= */

$produits = $conn->query(
    "SELECT
        id,
        nom,
        categorie,
        prix_achat,
        prix_vente,
        stock
     FROM produits
     ORDER BY nom ASC"
);


/* =========================================================
   STATISTIQUES STOCK
========================================================= */

$total_produits = 0;
$total_articles = 0;
$valeur_stock = 0;

$result = $conn->query(
    "SELECT
        COUNT(*) AS produits,
        COALESCE(SUM(stock),0) AS articles,
        COALESCE(
            SUM(stock * prix_achat),
            0
        ) AS valeur
     FROM produits"
);

if ($result) {

    $data = $result->fetch_assoc();

    $total_produits =
        (int)$data["produits"];

    $total_articles =
        (int)$data["articles"];

    $valeur_stock =
        (float)$data["valeur"];
}


/* =========================================================
   PRODUITS POUR LE FORMULAIRE D'ENTRÉE
========================================================= */

$produits_entree = $conn->query(
    "SELECT
        id,
        nom,
        stock,
        prix_achat
     FROM produits
     ORDER BY nom ASC"
);


/* =========================================================
   DERNIERS MOUVEMENTS
========================================================= */

$mouvements = $conn->query(
    "SELECT
        m.id,
        m.type,
        m.quantite,
        m.prix,
        m.description,
        m.date_mouvement,
        p.nom AS produit_nom
     FROM mouvements m
     INNER JOIN produits p
        ON p.id = m.produit_id
     ORDER BY m.id DESC
     LIMIT 30"
);


/* =========================================================
   FORMAT ARGENT
========================================================= */

function argent($montant)
{
    return number_format(
        (float)$montant,
        0,
        ",",
        " "
    ) . " FG";
}

?>

<!DOCTYPE html>
<html lang="fr">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Produits - LAMBEMAH GESTION</title>

<style>

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {

    font-family: Arial, sans-serif;

    background:
        linear-gradient(
            135deg,
            #eef7ff,
            #f8fbff
        );

    color: #172536;

    min-height: 100vh;
}


/* =========================================================
   SIDEBAR
========================================================= */

.sidebar {

    position: fixed;

    left: 0;
    top: 0;
    bottom: 0;

    width: 250px;

    background:
        linear-gradient(
            180deg,
            #06182c,
            #092b49,
            #073f66
        );

    color: white;

    padding: 24px 15px;

    z-index: 1000;
}

.brand {

    display: flex;

    align-items: center;

    gap: 11px;

    padding:
        5px
        10px
        25px;
}

.brand-icon {

    width: 45px;
    height: 45px;

    border-radius: 14px;

    display: flex;

    align-items: center;
    justify-content: center;

    background:
        linear-gradient(
            135deg,
            #20b8ff,
            #1264c7
        );

    font-size: 22px;
}

.brand h2 {
    font-size: 18px;
}

.brand span {

    font-size: 9px;

    color: #86a9c0;
}

.nav {
    list-style: none;
}

.nav li {
    margin: 4px 0;
}

.nav a {

    display: flex;

    align-items: center;

    gap: 12px;

    padding: 12px 14px;

    border-radius: 12px;

    color: #c5d5e1;

    text-decoration: none;

    font-size: 13px;
}

.nav a:hover,
.nav a.active {

    background:
        rgba(32,184,255,.16);

    color: white;
}

.nav a.active {

    border-left:
        3px solid #20b8ff;
}

.sidebar-bottom {

    position: absolute;

    left: 15px;
    right: 15px;
    bottom: 20px;
}

.profile {

    padding: 12px;

    border-radius: 12px;

    background:
        rgba(255,255,255,.06);

    margin-bottom: 10px;
}

.profile strong {

    display: block;

    font-size: 12px;
}

.profile span {

    color: #8ca8bb;

    font-size: 10px;
}

.logout {

    display: block;

    text-align: center;

    text-decoration: none;

    color: #ffbaba;

    background:
        rgba(255,70,70,.08);

    padding: 10px;

    border-radius: 10px;

    font-size: 11px;
}


/* =========================================================
   MAIN
========================================================= */

.main {

    margin-left: 250px;

    padding: 28px;

    max-width: 1500px;
}

.header {

    display: flex;

    justify-content: space-between;

    align-items: center;

    margin-bottom: 22px;
}

.header h1 {

    font-size: 26px;

    color: #061a35;
}

.header p {

    color: #8998a5;

    font-size: 12px;

    margin-top: 5px;
}

.avatar {

    width: 44px;
    height: 44px;

    border-radius: 14px;

    display: flex;

    align-items: center;
    justify-content: center;

    background:
        linear-gradient(
            135deg,
            #20b8ff,
            #1264c7
        );

    color: white;

    font-weight: bold;
}


/* =========================================================
   MESSAGE
========================================================= */

.message {

    padding: 12px;

    border-radius: 11px;

    margin-bottom: 15px;

    font-size: 11px;

    font-weight: bold;
}

.success {

    background: #eafaf2;

    color: #168653;
}

.error {

    background: #fff0f0;

    color: #d33;
}


/* =========================================================
   STATISTIQUES
========================================================= */

.stats {

    display: grid;

    grid-template-columns:
        repeat(3, 1fr);

    gap: 15px;

    margin-bottom: 20px;
}

.stat {

    background: white;

    border-radius: 18px;

    padding: 19px;

    box-shadow:
        0 6px 25px
        rgba(25,55,80,.06);
}

.stat small {

    color: #8c9aa6;

    font-size: 10px;
}

.stat h2 {

    margin-top: 8px;

    font-size: 22px;

    color: #0878b7;
}


/* =========================================================
   GRILLE
========================================================= */

.grid {

    display: grid;

    grid-template-columns:
        370px 1fr;

    gap: 20px;
}


/* =========================================================
   CARDS
========================================================= */

.card {

    background: white;

    border-radius: 20px;

    padding: 22px;

    box-shadow:
        0 6px 25px
        rgba(25,55,80,.06);
}

.card h2 {

    font-size: 17px;

    margin-bottom: 6px;

    color: #061a35;
}

.card > p {

    color: #8b99a5;

    font-size: 11px;

    margin-bottom: 18px;
}


/* =========================================================
   FORM
========================================================= */

.group {

    margin-bottom: 14px;
}

label {

    display: block;

    font-size: 10px;

    font-weight: bold;

    color: #536675;

    margin-bottom: 6px;
}

input,
select,
textarea {

    width: 100%;

    padding: 12px;

    border:
        1px solid #dfe8ee;

    border-radius: 10px;

    background: #fbfdff;

    outline: none;

    font-size: 12px;
}

input:focus,
select:focus,
textarea:focus {

    border-color: #20b8ff;
}

textarea {

    resize: vertical;

    min-height: 70px;
}

.btn {

    width: 100%;

    border: 0;

    border-radius: 11px;

    padding: 13px;

    color: white;

    background:
        linear-gradient(
            135deg,
            #20b8ff,
            #1264c7
        );

    font-weight: bold;

    cursor: pointer;
}

.btn:hover {
    opacity: .92;
}


/* =========================================================
   TABLE
========================================================= */

.table-wrap {

    overflow-x: auto;
}

table {

    width: 100%;

    border-collapse: collapse;
}

th {

    text-align: left;

    padding: 11px;

    color: #8998a5;

    font-size: 9px;

    border-bottom:
        1px solid #edf1f4;
}

td {

    padding: 12px 10px;

    border-bottom:
        1px solid #edf1f4;

    font-size: 11px;
}

td strong {
    color: #17324d;
}

.price {

    color: #0877b7;

    font-weight: bold;
}

.stock {

    font-weight: bold;

    color: #168653;
}

.low {

    color: #d9822b;
}

.delete-btn {

    border: 0;

    background: #fff0f0;

    color: #d33;

    padding: 6px 9px;

    border-radius: 7px;

    cursor: pointer;

    font-size: 9px;
}


/* =========================================================
   MOUVEMENTS
========================================================= */

.movement {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 10px;

    padding: 12px 0;

    border-bottom:
        1px solid #edf1f4;
}

.movement:last-child {
    border-bottom: 0;
}

.movement-left strong {

    display: block;

    font-size: 11px;
}

.movement-left span {

    color: #8c99a5;

    font-size: 9px;
}

.entree {

    color: #159765;

    font-weight: bold;
}

.sortie {

    color: #d33;

    font-weight: bold;
}


/* =========================================================
   MOBILE
========================================================= */

@media(max-width:900px) {

    .grid {

        grid-template-columns: 1fr;
    }

}

@media(max-width:700px) {

    .sidebar {

        position: relative;

        width: 100%;

        height: auto;

        padding: 10px;
    }

    .brand {

        padding:
            4px
            7px
            10px;
    }

    .brand-icon {

        width: 39px;
        height: 39px;
    }

    .brand h2 {
        font-size: 15px;
    }

    .nav {

        display: grid;

        grid-template-columns:
            repeat(4,1fr);

        gap: 4px;
    }

    .nav a {

        flex-direction: column;

        gap: 4px;

        padding: 8px 3px;

        text-align: center;

        font-size: 8px;
    }

    .nav a.active {

        border-left: 0;

        border-bottom:
            2px solid #20b8ff;
    }

    .sidebar-bottom {

        position: static;

        margin-top: 8px;
    }

    .profile {
        display: none;
    }

    .main {

        margin-left: 0;

        padding: 14px;
    }

    .header h1 {
        font-size: 21px;
    }

    .stats {

        grid-template-columns:
            1fr 1fr;

        gap: 9px;
    }

    .stat {

        padding: 15px;
    }

    .stat h2 {
        font-size: 17px;
    }

    .card {

        padding: 16px;

        border-radius: 17px;
    }

}

</style>

</head>

<body>


<!-- =========================================================
     SIDEBAR
========================================================= -->

<aside class="sidebar">

    <div class="brand">

        <div class="brand-icon">
            💼
        </div>

        <div>

            <h2>LAMBEMAH</h2>

            <span>
                GESTION • PRESTATION
            </span>

        </div>

    </div>


    <ul class="nav">

        <li>
            <a href="index.php">
                🏠 <span>Accueil</span>
            </a>
        </li>

        <li>
            <a href="produits.php" class="active">
                📦 <span>Produits</span>
            </a>
        </li>

        <li>
            <a href="ventes.php">
                💰 <span>Ventes</span>
            </a>
        </li>

        <li>
            <a href="prestations.php">
                🖨️ <span>Prestations</span>
            </a>
        </li>

        <li>
            <a href="recettes.php">
                💵 <span>Recettes</span>
            </a>
        </li>

        <li>
            <a href="depenses.php">
                💸 <span>Dépenses</span>
            </a>
        </li>

        <li>
            <a href="statistiques.php">
                📊 <span>Statistiques</span>
            </a>
        </li>

        <?php if ($role === "admin"): ?>

        <li>
            <a href="utilisateurs.php">
                👥 <span>Équipe</span>
            </a>
        </li>

        <?php endif; ?>

    </ul>


    <div class="sidebar-bottom">

        <div class="profile">

            <strong>
                <?= htmlspecialchars($nom) ?>
            </strong>

            <span>
                <?= htmlspecialchars($role) ?>
            </span>

        </div>


        <a
            class="logout"
            href="index.php?logout=1"
        >
            🚪 Déconnexion
        </a>

    </div>

</aside>


<!-- =========================================================
     MAIN
========================================================= -->

<main class="main">


    <div class="header">

        <div>

            <h1>
                📦 Produits & Stock
            </h1>

            <p>
                Gère tes produits, tes achats et ton stock.
            </p>

        </div>

        <div class="avatar">

            <?= strtoupper(
                substr($nom, 0, 1)
            ) ?>

        </div>

    </div>


    <?php if ($message !== ""): ?>

        <div class="message <?= htmlspecialchars($type) ?>">

            <?= htmlspecialchars($message) ?>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         STATISTIQUES
    ====================================================== -->

    <div class="stats">

        <div class="stat">

            <small>
                PRODUITS
            </small>

            <h2>
                <?= $total_produits ?>
            </h2>

        </div>


        <div class="stat">

            <small>
                ARTICLES EN STOCK
            </small>

            <h2>
                <?= $total_articles ?>
            </h2>

        </div>


        <div class="stat">

            <small>
                VALEUR DU STOCK
            </small>

            <h2>
                <?= argent($valeur_stock) ?>
            </h2>

        </div>

    </div>


    <div class="grid">


        <!-- =================================================
             GAUCHE
        ================================================== -->

        <div>


            <!-- AJOUT PRODUIT -->

            <div class="card">

                <h2>
                    ➕ Nouveau produit
                </h2>

                <p>
                    Ajoute un article à ton catalogue.
                </p>


                <form method="POST">

                    <input
                        type="hidden"
                        name="ajouter_produit"
                        value="1"
                    >


                    <div class="group">

                        <label>
                            NOM DU PRODUIT
                        </label>

                        <input
                            type="text"
                            name="nom"
                            placeholder="Ex : T-shirt enfant"
                            required
                        >

                    </div>


                    <div class="group">

                        <label>
                            CATÉGORIE
                        </label>

                        <input
                            type="text"
                            name="categorie"
                            placeholder="Ex : T-shirt, DTF, Lacoste..."
                        >

                    </div>


                    <div class="group">

                        <label>
                            PRIX D'ACHAT
                        </label>

                        <input
                            type="number"
                            name="prix_achat"
                            min="0"
                            step="500"
                            value="0"
                        >

                    </div>


                    <div class="group">

                        <label>
                            PRIX DE VENTE
                        </label>

                        <input
                            type="number"
                            name="prix_vente"
                            min="0"
                            step="500"
                            value="0"
                        >

                    </div>


                    <div class="group">

                        <label>
                            STOCK INITIAL
                        </label>

                        <input
                            type="number"
                            name="stock_initial"
                            min="0"
                            value="0"
                        >

                    </div>


                    <button
                        type="submit"
                        class="btn"
                    >
                        💾 Ajouter le produit
                    </button>

                </form>

            </div>


            <br>


            <!-- NOUVEL ACHAT -->

            <div class="card">

                <h2>
                    🛒 Nouvelle entrée / achat
                </h2>

                <p>
                    Ajoute une quantité achetée au stock.
                </p>


                <form method="POST">

                    <input
                        type="hidden"
                        name="ajouter_entree"
                        value="1"
                    >


                    <div class="group">

                        <label>
                            PRODUIT
                        </label>

                        <select
                            name="produit_id"
                            required
                        >

                            <option value="">
                                -- Sélectionner --
                            </option>

                            <?php
                            if (
                                $produits_entree
                                && $produits_entree->num_rows > 0
                            ):
                            ?>

                                <?php
                                while (
                                    $p =
                                    $produits_entree->fetch_assoc()
                                ):
                                ?>

                                    <option
                                        value="<?= (int)$p["id"] ?>"
                                    >

                                        <?= htmlspecialchars(
                                            $p["nom"]
                                        ) ?>

                                        —
                                        Stock :
                                        <?= (int)$p["stock"] ?>

                                    </option>

                                <?php endwhile; ?>

                            <?php endif; ?>

                        </select>

                    </div>


                    <div class="group">

                        <label>
                            QUANTITÉ ACHETÉE
                        </label>

                        <input
                            type="number"
                            name="quantite"
                            min="1"
                            value="1"
                            required
                        >

                    </div>


                    <div class="group">

                        <label>
                            PRIX D'ACHAT / UNITÉ
                        </label>

                        <input
                            type="number"
                            name="prix"
                            min="0"
                            step="500"
                            value="0"
                            required
                        >

                    </div>


                    <div class="group">

                        <label>
                            FOURNISSEUR / NOTE
                        </label>

                        <textarea
                            name="description"
                            placeholder="Ex : Achat chez fournisseur X, transport inclus..."
                        ></textarea>

                    </div>


                    <button
                        type="submit"
                        class="btn"
                    >
                        📥 Enregistrer l'entrée
                    </button>

                </form>

            </div>

        </div>


        <!-- =================================================
             DROITE
        ================================================== -->

        <div class="card">

            <h2>
                📦 Catalogue des produits
            </h2>

            <p>
                Tous les produits enregistrés et leur stock actuel.
            </p>


            <div class="table-wrap">

                <table>

                    <thead>

                    <tr>

                        <th>
                            PRODUIT
                        </th>

                        <th>
                            CATÉGORIE
                        </th>

                        <th>
                            ACHAT
                        </th>

                        <th>
                            VENTE
                        </th>

                        <th>
                            STOCK
                        </th>

                        <?php if ($role === "admin"): ?>

                            <th>
                                ACTION
                            </th>

                        <?php endif; ?>

                    </tr>

                    </thead>


                    <tbody>

                    <?php
                    if (
                        $produits
                        && $produits->num_rows > 0
                    ):
                    ?>

                        <?php
                        while (
                            $p =
                            $produits->fetch_assoc()
                        ):
                        ?>

                            <tr>

                                <td>

                                    <strong>
                                        <?= htmlspecialchars(
                                            $p["nom"]
                                        ) ?>
                                    </strong>

                                </td>


                                <td>

                                    <?= htmlspecialchars(
                                        $p["categorie"]
                                    ) ?>

                                </td>


                                <td class="price">

                                    <?= argent(
                                        $p["prix_achat"]
                                    ) ?>

                                </td>


                                <td class="price">

                                    <?= argent(
                                        $p["prix_vente"]
                                    ) ?>

                                </td>


                                <td>

                                    <span
                                        class="<?= (
                                            (int)$p["stock"] <= 3
                                            ? "low"
                                            : "stock"
                                        ) ?>"
                                    >

                                        <?= (int)$p["stock"] ?>

                                    </span>

                                </td>


                                <?php if ($role === "admin"): ?>

                                    <td>

                                        <form
                                            method="POST"
                                            onsubmit="
                                                return confirm(
                                                    'Supprimer ce produit ?'
                                                );
                                            "
                                        >

                                            <input
                                                type="hidden"
                                                name="supprimer_produit"
                                                value="1"
                                            >

                                            <input
                                                type="hidden"
                                                name="produit_id"
                                                value="<?= (int)$p["id"] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="delete-btn"
                                            >
                                                🗑️
                                            </button>

                                        </form>

                                    </td>

                                <?php endif; ?>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>

                            <td
                                colspan="6"
                                style="
                                    text-align:center;
                                    padding:35px;
                                    color:#8998a5;
                                "
                            >

                                📦

                                <br><br>

                                Aucun produit enregistré.

                            </td>

                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>


            <br>


            <!-- =================================================
                 MOUVEMENTS
            ================================================== -->

            <h2>
                🔄 Derniers mouvements
            </h2>

            <p>
                Les dernières entrées et sorties de stock.
            </p>


            <?php
            if (
                $mouvements
                && $mouvements->num_rows > 0
            ):
            ?>

                <?php
                while (
                    $m =
                    $mouvements->fetch_assoc()
                ):
                ?>

                    <div class="movement">

                        <div class="movement-left">

                            <strong>

                                <?= htmlspecialchars(
                                    $m["produit_nom"]
                                ) ?>

                            </strong>

                            <span>

                                <?= htmlspecialchars(
                                    $m["description"]
                                ) ?>

                                •
                                <?= date(
                                    "d/m/Y H:i",
                                    strtotime(
                                        $m["date_mouvement"]
                                    )
                                ) ?>

                            </span>

                        </div>


                        <div>

                            <?php if (
                                $m["type"] === "ENTREE"
                            ): ?>

                                <span class="entree">

                                    +<?= (int)$m["quantite"] ?>

                                </span>

                            <?php else: ?>

                                <span class="sortie">

                                    -<?= (int)$m["quantite"] ?>

                                </span>

                            <?php endif; ?>

                        </div>

                    </div>

                <?php endwhile; ?>

            <?php else: ?>

                <div
                    style="
                        text-align:center;
                        padding:25px;
                        color:#8998a5;
                        font-size:11px;
                    "
                >

                    🔄 Aucun mouvement enregistré.

                </div>

            <?php endif; ?>

        </div>

    </div>

</main>

</body>

</html>
