```php
<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["id"])) {
    header("Location: index.php");
    exit;
}

$nom = $_SESSION["nom"] ?? "Utilisateur";
$role = $_SESSION["role"] ?? "lecture";

$message = "";
$type = "";

/* AJOUT PRODUIT */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ajouter"])) {

    $produit = trim($_POST["nom"] ?? "");
    $categorie = trim($_POST["categorie"] ?? "");
    $prix_achat = (float)($_POST["prix_achat"] ?? 0);
    $prix_vente = (float)($_POST["prix_vente"] ?? 0);
    $stock = (int)($_POST["stock"] ?? 0);

    if ($produit === "") {
        $message = "Le nom du produit est obligatoire.";
        $type = "error";
    } elseif ($prix_vente < 0 || $prix_achat < 0 || $stock < 0) {
        $message = "Les valeurs ne peuvent pas être négatives.";
        $type = "error";
    } else {

        $stmt = $conn->prepare(
            "INSERT INTO produits
            (nom, categorie, prix_achat, prix_vente, stock)
            VALUES (?, ?, ?, ?, ?)"
        );

        $stmt->bind_param(
            "ssddi",
            $produit,
            $categorie,
            $prix_achat,
            $prix_vente,
            $stock
        );

        if ($stmt->execute()) {
            $message = "Produit ajouté avec succès.";
            $type = "success";
        } else {
            $message = "Erreur lors de l'ajout du produit.";
            $type = "error";
        }

        $stmt->close();
    }
}


/* SUPPRESSION */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["supprimer"])) {

    if ($role !== "admin") {
        $message = "Seul un administrateur peut supprimer un produit.";
        $type = "error";
    } else {

        $id = (int)$_POST["id"];

        $stmt = $conn->prepare(
            "DELETE FROM produits WHERE id = ?"
        );

        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            $message = "Produit supprimé.";
            $type = "success";
        } else {
            $message = "Impossible de supprimer ce produit.";
            $type = "error";
        }

        $stmt->close();
    }
}


/* MODIFICATION */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["modifier"])) {

    $id = (int)$_POST["id"];
    $produit = trim($_POST["nom"] ?? "");
    $categorie = trim($_POST["categorie"] ?? "");
    $prix_achat = (float)($_POST["prix_achat"] ?? 0);
    $prix_vente = (float)($_POST["prix_vente"] ?? 0);
    $stock = (int)($_POST["stock"] ?? 0);

    $stmt = $conn->prepare(
        "UPDATE produits
         SET nom = ?,
             categorie = ?,
             prix_achat = ?,
             prix_vente = ?,
             stock = ?
         WHERE id = ?"
    );

    $stmt->bind_param(
        "ssddii",
        $produit,
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
        $message = "Erreur lors de la modification.";
        $type = "error";
    }

    $stmt->close();
}


/* STATISTIQUES */
$total_produits = 0;
$total_stock = 0;
$valeur_stock = 0;

$result = $conn->query(
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(stock),0) AS stock,
        COALESCE(SUM(stock * prix_achat),0) AS valeur
     FROM produits"
);

if ($result) {
    $data = $result->fetch_assoc();

    $total_produits = (int)$data["total"];
    $total_stock = (int)$data["stock"];
    $valeur_stock = (float)$data["valeur"];
}


/* LISTE */
$produits = $conn->query(
    "SELECT *
     FROM produits
     ORDER BY id DESC"
);


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

.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    bottom: 0;
    width: 250px;
    background: linear-gradient(
        180deg,
        #071b2e,
        #0b2944,
        #07395b
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
    background: linear-gradient(
        135deg,
        #21b8ff,
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
    gap: 12px;
    align-items: center;
    padding: 12px 14px;
    border-radius: 12px;
    color: #c5d5e1;
    text-decoration: none;
    font-size: 13px;
}

.nav a:hover,
.nav a.active {
    background: rgba(32,184,255,.15);
    color: white;
}

.nav a.active {
    border-left: 3px solid #20b8ff;
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
    background: rgba(255,255,255,.06);
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
    background: rgba(255,70,70,.08);
    padding: 10px;
    border-radius: 10px;
    font-size: 11px;
}

.main {
    margin-left: 250px;
    padding: 28px;
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
    background: linear-gradient(
        135deg,
        #21b8ff,
        #1264c7
    );
    color: white;
    font-weight: bold;
}

.cards {
    display: grid;
    grid-template-columns: repeat(3,1fr);
    gap: 15px;
    margin-bottom: 20px;
}

.stat {
    background: white;
    border-radius: 17px;
    padding: 18px;
    box-shadow: 0 6px 25px rgba(25,55,80,.06);
}

.stat small {
    color: #8c9aa6;
    font-size: 10px;
}

.stat h2 {
    margin-top: 8px;
    font-size: 21px;
}

.content {
    display: grid;
    grid-template-columns: 350px 1fr;
    gap: 20px;
}

.card {
    background: white;
    border-radius: 20px;
    padding: 22px;
    box-shadow: 0 6px 25px rgba(25,55,80,.06);
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

.group {
    margin-bottom: 13px;
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
    padding: 11px;
    border: 1px solid #dfe8ee;
    border-radius: 10px;
    background: #fbfdff;
    outline: none;
}

input:focus,
select:focus {
    border-color: #20b8ff;
}

button {
    border: 0;
    border-radius: 10px;
    padding: 11px 14px;
    cursor: pointer;
    font-weight: bold;
}

.btn-primary {
    width: 100%;
    color: white;
    background: linear-gradient(
        135deg,
        #20b8ff,
        #1264c7
    );
}

.btn-delete {
    background: #fff0f0;
    color: #d64545;
    font-size: 10px;
}

.message {
    padding: 11px;
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

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th {
    text-align: left;
    padding: 10px;
    color: #8998a5;
    font-size: 9px;
    border-bottom: 1px solid #edf1f4;
}

td {
    padding: 12px 10px;
    border-bottom: 1px solid #edf1f4;
    font-size: 11px;
}

td strong {
    color: #20394c;
}

.price {
    color: #1477ae;
    font-weight: bold;
}

.stock {
    font-weight: bold;
}

.low {
    color: #d94b4b;
}

.good {
    color: #159765;
}

@media(max-width:900px) {

    .content {
        grid-template-columns: 1fr;
    }

    .cards {
        grid-template-columns: repeat(3,1fr);
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
        padding: 4px 7px 10px;
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
        grid-template-columns: repeat(4,1fr);
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
        border-bottom: 2px solid #20b8ff;
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
        grid-template-columns: 1fr 1fr;
        gap: 9px;
    }

    .stat:last-child {
        grid-column: 1 / -1;
    }

    .card {
        padding: 15px;
        border-radius: 16px;
    }
}

</style>

</head>

<body>


<aside class="sidebar">

    <div class="brand">

        <div class="brand-icon">
            💼
        </div>

        <div>
            <h2>LAMBEMAH</h2>
            <span>GESTION • PRESTATION</span>
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
            <a href="depenses.php">
                💸 <span>Dépenses</span>
            </a>
        </li>

        <li>
            <a href="statistiques.php">
                📊 <span>Stats</span>
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
            <strong><?= htmlspecialchars($nom) ?></strong>
            <span><?= htmlspecialchars($role) ?></span>
        </div>

        <a
            class="logout"
            href="index.php?logout=1"
        >
            🚪 Déconnexion
        </a>

    </div>

</aside>


<main class="main">

    <div class="header">

        <div>

            <h1>📦 Produits</h1>

            <p>
                Gère ton stock de T-shirts, chemises, pulls, képis et autres articles.
            </p>

        </div>

        <div class="avatar">
            <?= strtoupper(substr($nom,0,1)) ?>
        </div>

    </div>


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
                STOCK TOTAL
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


    <div class="content">


        <div class="card">

            <h2>
                ➕ Ajouter un produit
            </h2>

            <p>
                Ajoute un article disponible à la vente.
            </p>

            <?php if ($message !== ""): ?>

                <div class="message <?= $type ?>">
                    <?= htmlspecialchars($message) ?>
                </div>

            <?php endif; ?>


            <form method="POST">

                <input
                    type="hidden"
                    name="ajouter"
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
                        placeholder="Ex : T-shirts"
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
                        placeholder="Ex : 180000"
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
                        placeholder="Ex : 220000"
                        required
                    >

                </div>


                <div class="group">

                    <label>
                        STOCK INITIAL
                    </label>

                    <input
                        type="number"
                        name="stock"
                        min="0"
                        value="0"
                        required
                    >

                </div>


                <button
                    class="btn-primary"
                    type="submit"
                >
                    📦 Ajouter au stock
                </button>

            </form>

        </div>


        <div class="card">

            <h2>
                📋 Mon stock
            </h2>

            <p>
                Tous les produits actuellement enregistrés.
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

                        <th>
                            ACTION
                        </th>

                    </tr>

                    </thead>

                    <tbody>

                    <?php if ($produits && $produits->num_rows > 0): ?>

                        <?php while ($p = $produits->fetch_assoc()): ?>

                        <tr>

                            <td>
                                <strong>
                                    <?= htmlspecialchars($p["nom"]) ?>
                                </strong>
                            </td>

                            <td>
                                <?= htmlspecialchars($p["categorie"] ?? "") ?>
                            </td>

                            <td>
                                <?= argent($p["prix_achat"]) ?>
                            </td>

                            <td class="price">
                                <?= argent($p["prix_vente"]) ?>
                            </td>

                            <td class="stock
                                <?= $p["stock"] <= 2 ? "low" : "good" ?>"
                            >
                                <?= (int)$p["stock"] ?>
                            </td>

                            <td>

                                <?php if ($role === "admin"): ?>

                                <form
                                    method="POST"
                                    onsubmit="return confirm('Supprimer ce produit ?');"
                                >

                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= $p["id"] ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="supprimer"
                                        value="1"
                                    >

                                    <button
                                        class="btn-delete"
                                        type="submit"
                                    >
                                        Supprimer
                                    </button>

                                </form>

                                <?php else: ?>

                                    <span
                                        style="
                                        color:#9aa5ad;
                                        font-size:9px;
                                        "
                                    >
                                        Lecture
                                    </span>

                                <?php endif; ?>

                            </td>

                        </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>

                            <td colspan="6">

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

</body>

</html>
```
