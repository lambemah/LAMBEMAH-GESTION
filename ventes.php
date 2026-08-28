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


/*
|--------------------------------------------------------------------------
| ENREGISTRER UNE VENTE
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $produit_id = (int)($_POST["produit_id"] ?? 0);
    $quantite = (int)($_POST["quantite"] ?? 0);

    if ($produit_id <= 0 || $quantite <= 0) {

        $message = "Sélectionne un produit et une quantité valide.";
        $type = "error";

    } else {

        /*
        | Récupérer le produit
        */

        $stmt = $conn->prepare(
            "SELECT id, nom, prix_vente, stock
             FROM produits
             WHERE id = ?
             LIMIT 1"
        );

        $stmt->bind_param("i", $produit_id);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($result->num_rows !== 1) {

            $message = "Produit introuvable.";
            $type = "error";

        } else {

            $produit = $result->fetch_assoc();

            $stock = (int)$produit["stock"];
            $prix_unitaire = (float)$produit["prix_vente"];

            /*
            | Vérifier le stock
            */

            if ($quantite > $stock) {

                $message =
                    "Stock insuffisant. Stock disponible : "
                    . $stock;

                $type = "error";

            } else {

                $total = $prix_unitaire * $quantite;

                /*
                | Transaction :
                | vente + diminution stock
                */

                $conn->begin_transaction();

                try {

                    /*
                    | Ajouter la vente
                    */

                    $vente = $conn->prepare(
                        "INSERT INTO ventes
                        (produit_id, quantite, prix_unitaire, total)
                        VALUES (?, ?, ?, ?)"
                    );

                    $vente->bind_param(
                        "iidd",
                        $produit_id,
                        $quantite,
                        $prix_unitaire,
                        $total
                    );

                    if (!$vente->execute()) {
                        throw new Exception(
                            "Erreur lors de la vente."
                        );
                    }

                    $vente->close();


                    /*
                    | Diminuer le stock
                    */

                    $stock_update = $conn->prepare(
                        "UPDATE produits
                         SET stock = stock - ?
                         WHERE id = ?
                         AND stock >= ?"
                    );

                    $stock_update->bind_param(
                        "iii",
                        $quantite,
                        $produit_id,
                        $quantite
                    );

                    if (!$stock_update->execute()) {
                        throw new Exception(
                            "Erreur lors de la mise à jour du stock."
                        );
                    }

                    if ($stock_update->affected_rows !== 1) {
                        throw new Exception(
                            "Le stock a changé. Vente annulée."
                        );
                    }

                    $stock_update->close();


                    /*
                    | Enregistrer la recette
                    */

                    $libelle =
                        "Vente - " .
                        $produit["nom"];

                    $description =
                        "Vente de " .
                        $quantite .
                        " unité(s) de " .
                        $produit["nom"] .
                        " à " .
                        number_format(
                            $prix_unitaire,
                            0,
                            ",",
                            " "
                        ) .
                        " FG/unité.";

                    $recette = $conn->prepare(
                        "INSERT INTO recettes
                        (libelle, montant, description)
                        VALUES (?, ?, ?)"
                    );

                    $recette->bind_param(
                        "sds",
                        $libelle,
                        $total,
                        $description
                    );

                    if (!$recette->execute()) {
                        throw new Exception(
                            "Erreur lors de l'enregistrement de la recette."
                        );
                    }

                    $recette->close();


                    /*
                    | Tout est bon
                    */

                    $conn->commit();

                    $message =
                        "Vente enregistrée : "
                        . number_format(
                            $total,
                            0,
                            ",",
                            " "
                        )
                        . " FG.";

                    $type = "success";

                } catch (Exception $e) {

                    $conn->rollback();

                    $message =
                        "La vente n'a pas été enregistrée : "
                        . $e->getMessage();

                    $type = "error";
                }
            }
        }

        $stmt->close();
    }
}


/*
|--------------------------------------------------------------------------
| PRODUITS DISPONIBLES
|--------------------------------------------------------------------------
*/

$produits = $conn->query(
    "SELECT
        id,
        nom,
        categorie,
        prix_vente,
        stock
     FROM produits
     WHERE stock > 0
     ORDER BY nom ASC"
);


/*
|--------------------------------------------------------------------------
| STATISTIQUES
|--------------------------------------------------------------------------
*/

$total_ventes = 0;
$nombre_ventes = 0;

$result = $conn->query(
    "SELECT
        COUNT(*) AS nombre,
        COALESCE(SUM(total),0) AS total
     FROM ventes"
);

if ($result) {

    $data = $result->fetch_assoc();

    $nombre_ventes =
        (int)$data["nombre"];

    $total_ventes =
        (float)$data["total"];
}


/*
|--------------------------------------------------------------------------
| DERNIÈRES VENTES
|--------------------------------------------------------------------------
*/

$ventes = $conn->query(
    "SELECT
        v.id,
        v.quantite,
        v.prix_unitaire,
        v.total,
        v.date_vente,
        p.nom AS produit
     FROM ventes v
     LEFT JOIN produits p
        ON p.id = v.produit_id
     ORDER BY v.id DESC
     LIMIT 15"
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

<title>Ventes - LAMBEMAH GESTION</title>

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

.stats {
    display: grid;
    grid-template-columns: repeat(2,1fr);
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

.layout {
    display: grid;
    grid-template-columns: 370px 1fr;
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
    margin-bottom: 20px;
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

.group {
    margin-bottom: 15px;
}

label {
    display: block;
    font-size: 10px;
    font-weight: bold;
    color: #536675;
    margin-bottom: 6px;
}

select,
input {
    width: 100%;
    padding: 12px;
    border: 1px solid #dfe8ee;
    border-radius: 10px;
    background: #fbfdff;
    outline: none;
}

select:focus,
input:focus {
    border-color: #20b8ff;
}

.stock-info {
    margin-top: 7px;
    padding: 9px;
    background: #eff9ff;
    color: #25799d;
    border-radius: 9px;
    font-size: 10px;
}

button {
    width: 100%;
    border: none;
    border-radius: 11px;
    padding: 13px;
    color: white;
    background: linear-gradient(
        135deg,
        #20b8ff,
        #1264c7
    );
    font-weight: bold;
    cursor: pointer;
}

table {
    width: 100%;
    border-collapse: collapse;
}

.table-wrap {
    overflow-x: auto;
}

th {
    padding: 10px;
    text-align: left;
    color: #8998a5;
    font-size: 9px;
    border-bottom: 1px solid #edf1f4;
}

td {
    padding: 12px 10px;
    font-size: 11px;
    border-bottom: 1px solid #edf1f4;
}

.amount {
    color: #1378b2;
    font-weight: bold;
}

.date {
    color: #9ba5ad;
    font-size: 9px;
}

@media(max-width:900px) {

    .layout {
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

    .stats {
        gap: 9px;
    }

    .stat {
        padding: 14px;
    }

    .stat h2 {
        font-size: 16px;
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
            <a href="produits.php">
                📦 <span>Produits</span>
            </a>
        </li>

        <li>
            <a href="ventes.php" class="active">
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


<main class="main">


    <div class="header">

        <div>

            <h1>
                💰 Ventes
            </h1>

            <p>
                Enregistre tes ventes et mets automatiquement ton stock à jour.
            </p>

        </div>

        <div class="avatar">
            <?= strtoupper(substr($nom,0,1)) ?>
        </div>

    </div>


    <div class="stats">

        <div class="stat">

            <small>
                CHIFFRE D'AFFAIRES
            </small>

            <h2>
                <?= argent($total_ventes) ?>
            </h2>

        </div>

        <div class="stat">

            <small>
                NOMBRE DE VENTES
            </small>

            <h2>
                <?= $nombre_ventes ?>
            </h2>

        </div>

    </div>


    <div class="layout">


        <div class="card">

            <h2>
                🛒 Nouvelle vente
            </h2>

            <p>
                Choisis le produit vendu.
            </p>


            <?php if ($message !== ""): ?>

                <div class="message <?= $type ?>">
                    <?= htmlspecialchars($message) ?>
                </div>

            <?php endif; ?>


            <?php if (!$produits || $produits->num_rows === 0): ?>

                <div class="message error">

                    Aucun produit disponible en stock.

                    <br><br>

                    Ajoute d'abord un produit dans
                    <strong>Produits</strong>.

                </div>

            <?php else: ?>


            <form method="POST">


                <div class="group">

                    <label>
                        PRODUIT
                    </label>

                    <select
                        name="produit_id"
                        id="produit"
                        required
                    >

                        <option value="">
                            Sélectionner un produit
                        </option>

                        <?php while (
                            $p =
                            $produits->fetch_assoc()
                        ): ?>

                            <option
                                value="<?= $p["id"] ?>"
                                data-stock="<?= $p["stock"] ?>"
                                data-prix="<?= $p["prix_vente"] ?>"
                            >

                                <?= htmlspecialchars($p["nom"]) ?>

                                —
                                <?= argent($p["prix_vente"]) ?>

                                —
                                stock :
                                <?= $p["stock"] ?>

                            </option>

                        <?php endwhile; ?>

                    </select>

                </div>


                <div
                    class="stock-info"
                    id="stockInfo"
                >
                    Sélectionne un produit pour voir son stock.
                </div>


                <div class="group">

                    <label>
                        QUANTITÉ
                    </label>

                    <input
                        type="number"
                        name="quantite"
                        id="quantite"
                        min="1"
                        value="1"
                        required
                    >

                </div>


                <div
                    class="stock-info"
                    id="totalInfo"
                >
                    Total : 0 FG
                </div>


                <br>


                <button type="submit">

                    💰 ENREGISTRER LA VENTE

                </button>


            </form>


            <?php endif; ?>

        </div>


        <div class="card">

            <h2>
                📋 Historique des ventes
            </h2>

            <p>
                Les 15 dernières ventes.
            </p>


            <div class="table-wrap">

                <table>

                    <thead>

                    <tr>

                        <th>
                            PRODUIT
                        </th>

                        <th>
                            QTÉ
                        </th>

                        <th>
                            PRIX
                        </th>

                        <th>
                            TOTAL
                        </th>

                        <th>
                            DATE
                        </th>

                    </tr>

                    </thead>


                    <tbody>

                    <?php if (
                        $ventes &&
                        $ventes->num_rows > 0
                    ): ?>

                        <?php while (
                            $v =
                            $ventes->fetch_assoc()
                        ): ?>

                        <tr>

                            <td>

                                <strong>
                                    <?= htmlspecialchars(
                                        $v["produit"]
                                        ?? "Produit supprimé"
                                    ) ?>
                                </strong>

                            </td>

                            <td>
                                <?= (int)$v["quantite"] ?>
                            </td>

                            <td>
                                <?= argent(
                                    $v["prix_unitaire"]
                                ) ?>
                            </td>

                            <td class="amount">
                                <?= argent(
                                    $v["total"]
                                ) ?>
                            </td>

                            <td class="date">

                                <?= date(
                                    "d/m/Y H:i",
                                    strtotime(
                                        $v["date_vente"]
                                    )
                                ) ?>

                            </td>

                        </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>

                            <td colspan="5">
                                Aucune vente enregistrée.
                            </td>

                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</main>


<script>

/*
|--------------------------------------------------------------------------
| APERÇU STOCK + TOTAL
|--------------------------------------------------------------------------
*/

const produit =
    document.getElementById("produit");

const quantite =
    document.getElementById("quantite");

const stockInfo =
    document.getElementById("stockInfo");

const totalInfo =
    document.getElementById("totalInfo");


function actualiser() {

    if (!produit || !quantite) {
        return;
    }

    const option =
        produit.options[produit.selectedIndex];

    if (!option || !option.value) {

        stockInfo.textContent =
            "Sélectionne un produit pour voir son stock.";

        totalInfo.textContent =
            "Total : 0 FG";

        return;
    }

    const stock =
        parseInt(
            option.dataset.stock
        ) || 0;

    const prix =
        parseFloat(
            option.dataset.prix
        ) || 0;

    const qte =
        parseInt(
            quantite.value
        ) || 0;

    stockInfo.textContent =
        "Stock disponible : " +
        stock +
        " unité(s)";

    const total =
        prix * qte;

    totalInfo.textContent =
        "Total : " +
        new Intl.NumberFormat(
            "fr-FR"
        ).format(total) +
        " FG";
}


if (produit) {
    produit.addEventListener(
        "change",
        actualiser
    );
}

if (quantite) {
    quantite.addEventListener(
        "input",
        actualiser
    );
}

actualiser();

</script>

</body>

</html>
```
