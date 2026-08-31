```php
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
   AJOUT D'UN PRODUIT
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ajouter_produit"])) {

    $nom_produit = trim($_POST["nom"] ?? "");
    $categorie = trim($_POST["categorie"] ?? "");
    $prix_achat = (float)($_POST["prix_achat"] ?? 0);
    $prix_vente = (float)($_POST["prix_vente"] ?? 0);
    $stock = (int)($_POST["stock"] ?? 0);

    if ($nom_produit === "") {

        $message = "Veuillez saisir le nom du produit.";
        $type = "error";

    } elseif ($stock < 0) {

        $message = "Le stock ne peut pas être négatif.";
        $type = "error";

    } else {

        $stmt = $conn->prepare(
            "INSERT INTO produits
            (nom, categorie, prix_achat, prix_vente, stock)
            VALUES (?, ?, ?, ?, ?)"
        );

        if ($stmt) {

            $stmt->bind_param(
                "ssddi",
                $nom_produit,
                $categorie,
                $prix_achat,
                $prix_vente,
                $stock
            );

            if ($stmt->execute()) {

                $message = "Produit ajouté avec succès.";
                $type = "success";

            } else {

                $message = "Impossible d'ajouter le produit.";
                $type = "error";
            }

            $stmt->close();

        } else {

            $message = "Erreur de préparation.";
            $type = "error";
        }
    }
}


/* =========================================================
   MODIFICATION D'UN PRODUIT
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["modifier_produit"])) {

    $id = (int)($_POST["id"] ?? 0);
    $nom_produit = trim($_POST["nom"] ?? "");
    $categorie = trim($_POST["categorie"] ?? "");
    $prix_achat = (float)($_POST["prix_achat"] ?? 0);
    $prix_vente = (float)($_POST["prix_vente"] ?? 0);
    $stock = (int)($_POST["stock"] ?? 0);

    if ($id <= 0 || $nom_produit === "") {

        $message = "Informations invalides.";
        $type = "error";

    } elseif ($stock < 0) {

        $message = "Le stock ne peut pas être négatif.";
        $type = "error";

    } else {

        $stmt = $conn->prepare(
            "UPDATE produits
             SET nom = ?,
                 categorie = ?,
                 prix_achat = ?,
                 prix_vente = ?,
                 stock = ?
             WHERE id = ?"
        );

        if ($stmt) {

            $stmt->bind_param(
                "ssddii",
                $nom_produit,
                $categorie,
                $prix_achat,
                $prix_vente,
                $stock,
                $id
            );

            if ($stmt->execute()) {

                $message = "Produit modifié avec succès.";
                $type = "success";

            } else {

                $message = "Impossible de modifier le produit.";
                $type = "error";
            }

            $stmt->close();

        } else {

            $message = "Erreur de préparation.";
            $type = "error";
        }
    }
}


/* =========================================================
   SUPPRESSION D'UN PRODUIT
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["supprimer_produit"])) {

    if ($role !== "admin") {

        $message = "Seul l'administrateur peut supprimer un produit.";
        $type = "error";

    } else {

        $id = (int)($_POST["id"] ?? 0);

        if ($id > 0) {

            $stmt = $conn->prepare(
                "DELETE FROM produits WHERE id = ?"
            );

            if ($stmt) {

                $stmt->bind_param("i", $id);

                if ($stmt->execute()) {

                    $message = "Produit supprimé.";
                    $type = "success";

                } else {

                    $message = "Impossible de supprimer le produit.";
                    $type = "error";
                }

                $stmt->close();
            }
        }
    }
}


/* =========================================================
   RECHERCHE
========================================================= */

$recherche = trim($_GET["recherche"] ?? "");

if ($recherche !== "") {

    $motif = "%" . $recherche . "%";

    $stmt = $conn->prepare(
        "SELECT
            id,
            nom,
            categorie,
            prix_achat,
            prix_vente,
            stock,
            date_creation
         FROM produits
         WHERE nom LIKE ?
            OR categorie LIKE ?
         ORDER BY id DESC"
    );

    $stmt->bind_param(
        "ss",
        $motif,
        $motif
    );

    $stmt->execute();

    $produits = $stmt->get_result();

} else {

    $produits = $conn->query(
        "SELECT
            id,
            nom,
            categorie,
            prix_achat,
            prix_vente,
            stock,
            date_creation
         FROM produits
         ORDER BY id DESC"
    );
}


/* =========================================================
   STATISTIQUES
========================================================= */

$total_produits = 0;
$total_stock = 0;
$valeur_stock = 0;

$result = $conn->query(
    "SELECT
        COUNT(*) AS nombre,
        COALESCE(SUM(stock),0) AS stock_total,
        COALESCE(SUM(stock * prix_achat),0) AS valeur
     FROM produits"
);

if ($result) {

    $data = $result->fetch_assoc();

    $total_produits = (int)$data["nombre"];
    $total_stock = (int)$data["stock_total"];
    $valeur_stock = (float)$data["valeur"];
}


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
    background: #f3f7fb;
    color: #172536;
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
            #061a2d,
            #092d4b,
            #07527c
        );

    color: white;

    padding: 24px 15px;

    z-index: 1000;
}

.brand {

    display: flex;
    align-items: center;

    gap: 11px;

    padding: 5px 10px 25px;
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

    transition: .2s;
}

.nav a:hover,
.nav a.active {

    background: rgba(32,184,255,.16);

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
    font-size: 25px;
}

.header p {

    color: #8998a5;

    font-size: 12px;

    margin-top: 5px;
}

.avatar {

    width: 42px;
    height: 42px;

    border-radius: 13px;

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

    border-radius: 10px;

    margin-bottom: 15px;

    font-size: 11px;
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

.cards {

    display: grid;

    grid-template-columns:
        repeat(3, 1fr);

    gap: 15px;

    margin-bottom: 20px;
}

.stat {

    background: white;

    border-radius: 17px;

    padding: 18px;

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

    font-size: 21px;

    color: #0c79b5;
}


/* =========================================================
   LAYOUT
========================================================= */

.content {

    display: grid;

    grid-template-columns:
        360px 1fr;

    gap: 20px;
}

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
}

.card > p {

    color: #8b99a5;

    font-size: 11px;

    margin-bottom: 18px;
}


/* =========================================================
   FORMULAIRE
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
select {

    width: 100%;

    padding: 12px;

    border:
        1px solid #dfe8ee;

    border-radius: 10px;

    background: #fbfdff;

    outline: none;

    font-family: Arial, sans-serif;

    font-size: 12px;
}

input:focus,
select:focus {

    border-color: #20b8ff;
}

.btn-primary {

    width: 100%;

    border: 0;

    border-radius: 11px;

    padding: 13px;

    cursor: pointer;

    color: white;

    background:
        linear-gradient(
            135deg,
            #20b8ff,
            #1264c7
        );

    font-weight: bold;

    font-size: 12px;
}

.btn-primary:hover {
    opacity: .9;
}


/* =========================================================
   RECHERCHE
========================================================= */

.search {

    display: flex;

    gap: 8px;

    margin-bottom: 18px;
}

.search input {

    flex: 1;
}

.btn-search {

    width: auto;

    padding:
        0 18px;

    border: 0;

    border-radius: 10px;

    background:
        #0c79b5;

    color: white;

    cursor: pointer;
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

    min-width: 720px;
}

th {

    color: #95a1aa;

    font-size: 9px;

    text-align: left;

    padding: 10px;

    border-bottom:
        1px solid #edf1f4;
}

td {

    padding: 12px 10px;

    border-bottom:
        1px solid #f0f3f5;

    font-size: 11px;
}

td strong {
    color: #20394c;
}

.stock {

    font-weight: bold;

    color: #159765;
}

.stock.zero {
    color: #d33;
}

.prix {
    color: #0878b7;
    font-weight: bold;
}

.actions {

    display: flex;

    gap: 5px;
}

.btn-edit,
.btn-delete {

    border: 0;

    border-radius: 7px;

    padding: 7px 9px;

    cursor: pointer;

    font-size: 10px;
}

.btn-edit {

    background: #eef8ff;

    color: #0878b7;
}

.btn-delete {

    background: #fff0f0;

    color: #d33;
}


/* =========================================================
   MODAL
========================================================= */

.modal {

    display: none;

    position: fixed;

    inset: 0;

    background:
        rgba(5,25,40,.55);

    z-index: 2000;

    align-items: center;

    justify-content: center;

    padding: 15px;
}

.modal.show {
    display: flex;
}

.modal-box {

    width: 100%;

    max-width: 430px;

    background: white;

    border-radius: 18px;

    padding: 22px;

}

.modal-box h2 {

    font-size: 18px;

    margin-bottom: 18px;
}

.modal-actions {

    display: flex;

    gap: 8px;

    margin-top: 18px;
}

.btn-cancel {

    flex: 1;

    border: 0;

    border-radius: 10px;

    padding: 12px;

    background: #edf2f5;

    color: #536675;

    cursor: pointer;
}

.btn-save {

    flex: 1;

    border: 0;

    border-radius: 10px;

    padding: 12px;

    background:
        linear-gradient(
            135deg,
            #20b8ff,
            #1264c7
        );

    color: white;

    font-weight: bold;

    cursor: pointer;
}


/* =========================================================
   MOBILE
========================================================= */

@media(max-width:900px) {

    .content {

        grid-template-columns: 1fr;
    }

    .cards {

        grid-template-columns:
            repeat(3, 1fr);
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
            4px 7px 10px;
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
            repeat(4, 1fr);

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
        font-size: 20px;
    }

    .cards {

        grid-template-columns:
            1fr 1fr;

        gap: 9px;
    }

    .stat {
        padding: 14px;
    }

    .stat:last-child {

        grid-column:
            1 / -1;
    }

    .stat h2 {
        font-size: 17px;
    }

    .card {

        padding: 15px;

        border-radius: 16px;
    }

    .search {
        flex-direction: column;
    }

    .btn-search {

        width: 100%;

        padding: 11px;
    }
}

@media(max-width:390px) {

    .nav a {
        font-size: 7px;
    }

    .stat h2 {
        font-size: 15px;
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
                📦 Produits
            </h1>

            <p>
                Gère tes produits, leurs prix et ton stock.
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

    <div class="cards">

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
                <?= $total_stock ?>
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


    <!-- =====================================================
         CONTENU
    ====================================================== -->

    <div class="content">


        <!-- =================================================
             AJOUT
        ================================================== -->

        <div class="card">

            <h2>
                ➕ Nouveau produit
            </h2>

            <p>
                Ajoute un article à ton stock.
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
                        placeholder="Ex : T-shirt adulte"
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
                        placeholder="Ex : T-shirt, Lacoste, Képi..."
                    >

                </div>


                <div class="group">

                    <label>
                        PRIX D'ACHAT / UNITÉ
                    </label>

                    <input
                        type="number"
                        name="prix_achat"
                        value="0"
                        min="0"
                        step="500"
                    >

                </div>


                <div class="group">

                    <label>
                        PRIX DE VENTE / UNITÉ
                    </label>

                    <input
                        type="number"
                        name="prix_vente"
                        value="0"
                        min="0"
                        step="500"
                    >

                </div>


                <div class="group">

                    <label>
                        STOCK INITIAL
                    </label>

                    <input
                        type="number"
                        name="stock"
                        value="0"
                        min="0"
                    >

                </div>


                <button
                    type="submit"
                    class="btn-primary"
                >
                    💾 Ajouter le produit
                </button>

            </form>

        </div>


        <!-- =================================================
             LISTE
        ================================================== -->

        <div class="card">

            <h2>
                📋 Mes produits
            </h2>

            <p>
                Tous les articles enregistrés dans ton stock.
            </p>


            <form
                method="GET"
                class="search"
            >

                <input
                    type="text"
                    name="recherche"
                    value="<?= htmlspecialchars($recherche) ?>"
                    placeholder="🔎 Rechercher un produit..."
                >

                <button
                    type="submit"
                    class="btn-search"
                >
                    Rechercher
                </button>

            </form>


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

                        <th>
                            ACTIONS
                        </th>

                    </tr>

                    </thead>


                    <tbody>

                    <?php if (
                        $produits &&
                        $produits->num_rows > 0
                    ): ?>

                        <?php while (
                            $p =
                            $produits->fetch_assoc()
                        ): ?>

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
                                        ?: "—"
                                    ) ?>

                                </td>


                                <td class="prix">

                                    <?= argent(
                                        $p["prix_achat"]
                                    ) ?>

                                </td>


                                <td class="prix">

                                    <?= argent(
                                        $p["prix_vente"]
                                    ) ?>

                                </td>


                                <td>

                                    <span
                                        class="stock <?= ((int)$p["stock"] <= 0 ? "zero" : "") ?>"
                                    >

                                        <?= (int)$p["stock"] ?>

                                    </span>

                                </td>


                                <td>

                                    <div class="actions">

                                        <button
                                            type="button"
                                            class="btn-edit"
                                            onclick='ouvrirModification(
                                                <?= json_encode($p, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
                                            )'
                                        >
                                            ✏️
                                        </button>


                                        <?php if ($role === "admin"): ?>

                                            <form
                                                method="POST"
                                                onsubmit="return confirm('Supprimer ce produit ?');"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="supprimer_produit"
                                                    value="1"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="id"
                                                    value="<?= (int)$p["id"] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="btn-delete"
                                                >
                                                    🗑️
                                                </button>

                                            </form>

                                        <?php endif; ?>

                                    </div>

                                </td>

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

        </div>

    </div>

</main>


<!-- =========================================================
     MODIFICATION
========================================================= -->

<div
    class="modal"
    id="modalModification"
>

    <div class="modal-box">

        <h2>
            ✏️ Modifier le produit
        </h2>


        <form method="POST">

            <input
                type="hidden"
                name="modifier_produit"
                value="1"
            >

            <input
                type="hidden"
                name="id"
                id="edit_id"
            >


            <div class="group">

                <label>
                    NOM DU PRODUIT
                </label>

                <input
                    type="text"
                    name="nom"
                    id="edit_nom"
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
                    id="edit_categorie"
                >

            </div>


            <div class="group">

                <label>
                    PRIX D'ACHAT
                </label>

                <input
                    type="number"
                    name="prix_achat"
                    id="edit_prix_achat"
                    min="0"
                    step="500"
                >

            </div>


            <div class="group">

                <label>
                    PRIX DE VENTE
                </label>

                <input
                    type="number"
                    name="prix_vente"
                    id="edit_prix_vente"
                    min="0"
                    step="500"
                >

            </div>


            <div class="group">

                <label>
                    STOCK
                </label>

                <input
                    type="number"
                    name="stock"
                    id="edit_stock"
                    min="0"
                >

            </div>


            <div class="modal-actions">

                <button
                    type="button"
                    class="btn-cancel"
                    onclick="fermerModification()"
                >
                    Annuler
                </button>

                <button
                    type="submit"
                    class="btn-save"
                >
                    💾 Enregistrer
                </button>

            </div>

        </form>

    </div>

</div>


<script>

function ouvrirModification(produit) {

    document.getElementById("edit_id").value =
        produit.id;

    document.getElementById("edit_nom").value =
        produit.nom || "";

    document.getElementById("edit_categorie").value =
        produit.categorie || "";

    document.getElementById("edit_prix_achat").value =
        produit.prix_achat || 0;

    document.getElementById("edit_prix_vente").value =
        produit.prix_vente || 0;

    document.getElementById("edit_stock").value =
        produit.stock || 0;

    document
        .getElementById("modalModification")
        .classList.add("show");
}


function fermerModification() {

    document
        .getElementById("modalModification")
        .classList.remove("show");
}


document
    .getElementById("modalModification")
    .addEventListener(
        "click",
        function(event) {

            if (
                event.target === this
            ) {

                fermerModification();

            }

        }
    );

</script>

</body>

</html>
```
