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

/* =====================================================
   ENREGISTRER UNE VENTE
===================================================== */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ajouter_vente"])) {

    $produit_id = (int)($_POST["produit_id"] ?? 0);
    $quantite = (int)($_POST["quantite"] ?? 0);
    $prix_unitaire = (float)($_POST["prix_unitaire"] ?? 0);
    $description = trim($_POST["description"] ?? "");

    if ($produit_id <= 0) {

        $message = "Veuillez sélectionner un produit.";
        $type = "error";

    } elseif ($quantite <= 0) {

        $message = "La quantité doit être supérieure à zéro.";
        $type = "error";

    } elseif ($prix_unitaire <= 0) {

        $message = "Le prix de vente est obligatoire.";
        $type = "error";

    } else {

        /* Récupérer le produit directement dans ta table produits */
        $stmt = $conn->prepare("
            SELECT id, nom, prix_vente, stock
            FROM produits
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->bind_param("i", $produit_id);
        $stmt->execute();

        $resultat = $stmt->get_result();

        if ($resultat && $resultat->num_rows === 1) {

            $produit = $resultat->fetch_assoc();

            $stock_actuel = (int)$produit["stock"];

            if ($stock_actuel < $quantite) {

                $message =
                    "Stock insuffisant. Stock disponible pour " .
                    $produit["nom"] .
                    " : " .
                    $stock_actuel;

                $type = "error";

            } else {

                $montant = $quantite * $prix_unitaire;

                /* Ajouter la vente */
                $vente = $conn->prepare("
                    INSERT INTO ventes
                    (
                        produit_id,
                        quantite,
                        prix_unitaire,
                        montant,
                        description
                    )
                    VALUES (?, ?, ?, ?, ?)
                ");

                $vente->bind_param(
                    "iidds",
                    $produit_id,
                    $quantite,
                    $prix_unitaire,
                    $montant,
                    $description
                );

                if ($vente->execute()) {

                    /* Retirer la quantité du stock */
                    $stock = $conn->prepare("
                        UPDATE produits
                        SET stock = stock - ?
                        WHERE id = ?
                    ");

                    $stock->bind_param(
                        "ii",
                        $quantite,
                        $produit_id
                    );

                    if ($stock->execute()) {

                        $message = "Vente enregistrée avec succès.";
                        $type = "success";

                    } else {

                        $message =
                            "La vente a été enregistrée mais le stock n'a pas pu être mis à jour.";

                        $type = "error";
                    }

                    $stock->close();

                } else {

                    $message =
                        "Erreur lors de l'enregistrement de la vente.";

                    $type = "error";
                }

                $vente->close();
            }

        } else {

            $message = "Produit introuvable dans la base de données.";
            $type = "error";
        }

        $stmt->close();
    }
}


/* =====================================================
   RÉCUPÉRER LES PRODUITS
===================================================== */

$produits = $conn->query("
    SELECT
        id,
        nom,
        categorie,
        prix_achat,
        prix_vente,
        stock
    FROM produits
    ORDER BY nom ASC
");


/* =====================================================
   HISTORIQUE DES VENTES
===================================================== */

$ventes = $conn->query("
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
    LEFT JOIN produits p
        ON p.id = v.produit_id
    ORDER BY v.id DESC
");


/* =====================================================
   TOTAL DES VENTES
===================================================== */

$total_ventes = 0;

$total = $conn->query("
    SELECT COALESCE(SUM(montant), 0) AS total
    FROM ventes
");

if ($total) {

    $data = $total->fetch_assoc();

    $total_ventes = (float)$data["total"];
}


/* =====================================================
   NOMBRE DE VENTES
===================================================== */

$nombre_ventes = 0;

$count = $conn->query("
    SELECT COUNT(*) AS total
    FROM ventes
");

if ($count) {

    $data_count = $count->fetch_assoc();

    $nombre_ventes = (int)$data_count["total"];
}


/* =====================================================
   FORMAT ARGENT
===================================================== */

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

/* =====================================================
   GLOBAL
===================================================== */

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
            #f7fbff
        );

    color: #172536;

    min-height: 100vh;
}


/* =====================================================
   SIDEBAR
===================================================== */

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

    box-shadow:
        5px 0 25px rgba(0,0,0,.10);
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

    gap: 12px;

    align-items: center;

    padding: 12px 14px;

    border-radius: 12px;

    color: #c5d5e1;

    text-decoration: none;

    font-size: 13px;

    transition: .2s;
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


/* =====================================================
   CONTENU
===================================================== */

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


/* =====================================================
   CARTES STATISTIQUES
===================================================== */

.cards {

    display: grid;

    grid-template-columns:
        repeat(2, 1fr);

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

    font-size: 23px;

    color: #071b2e;
}


/* =====================================================
   LAYOUT
===================================================== */

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

    color: #061a35;

    margin-bottom: 6px;
}


.card > p {

    color: #8b99a5;

    font-size: 11px;

    margin-bottom: 18px;
}


/* =====================================================
   MESSAGE
===================================================== */

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


/* =====================================================
   FORMULAIRE
===================================================== */

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

    border-radius: 11px;

    background: #fbfdff;

    outline: none;

    font-size: 13px;

    color: #172536;
}


input:focus,
select:focus,
textarea:focus {

    border-color: #20b8ff;

    box-shadow:
        0 0 0 3px
        rgba(32,184,255,.08);
}


textarea {

    resize: vertical;

    min-height: 75px;
}


.product-info {

    margin-top: 7px;

    padding: 9px 11px;

    border-radius: 9px;

    background: #eef8ff;

    color: #176ba4;

    font-size: 10px;

    display: none;
}


.montant {

    padding: 13px;

    border-radius: 11px;

    background:
        linear-gradient(
            135deg,
            #edf8ff,
            #e5f3ff
        );

    color: #096bc4;

    font-weight: bold;

    font-size: 15px;

    margin-bottom: 12px;
}


.btn-primary {

    width: 100%;

    color: white;

    background:
        linear-gradient(
            135deg,
            #20b8ff,
            #1264c7
        );

    padding: 13px;

    border: 0;

    border-radius: 11px;

    font-weight: bold;

    cursor: pointer;
}


/* =====================================================
   HISTORIQUE
===================================================== */

.history {

    margin-top: 20px;
}


.sale {

    border:
        1px solid #e8eef3;

    border-radius: 14px;

    padding: 15px;

    margin-bottom: 10px;

    background: #fff;
}


.sale-top {

    display: flex;

    justify-content: space-between;

    gap: 10px;
}


.sale-product {

    font-weight: bold;

    color: #17324d;

    font-size: 13px;
}


.sale-amount {

    color: #0877e8;

    font-weight: bold;

    white-space: nowrap;

    font-size: 13px;
}


.sale-info {

    display: flex;

    flex-wrap: wrap;

    gap: 7px;

    margin-top: 8px;
}


.badge {

    background: #f0f6fb;

    color: #627488;

    padding: 6px 8px;

    border-radius: 8px;

    font-size: 10px;
}


.sale-description {

    color: #647587;

    font-size: 11px;

    margin-top: 9px;
}


.sale-date {

    color: #98a5b2;

    font-size: 10px;

    margin-top: 8px;
}


/* =====================================================
   TABLEAU DESKTOP
===================================================== */

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


.price {

    color: #1477ae;

    font-weight: bold;
}


/* =====================================================
   MOBILE
===================================================== */

@media(max-width:900px) {

    .content {

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


    .cards {

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


    .table-wrap {

        display: none;
    }

}

</style>

</head>

<body>

<!-- =====================================================
     MENU
===================================================== -->

<aside class="sidebar">

```
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
        <a href="produits.php">
            📦 <span>Produits</span>
        </a>
    </li>


    <li>
        <a
            href="ventes.php"
            class="active"
        >
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
```

</aside>

<!-- =====================================================
     CONTENU
===================================================== -->

<main class="main">

```
<div class="header">

    <div>

        <h1>
            💰 Ventes
        </h1>

        <p>
            Enregistre tes ventes et suis ton chiffre d'affaires.
        </p>

    </div>


    <div class="avatar">

        <?= strtoupper(
            substr($nom, 0, 1)
        ) ?>

    </div>

</div>


<?php if ($message !== ""): ?>

    <div class="message <?= $type ?>">

        <?= htmlspecialchars($message) ?>

    </div>

<?php endif; ?>


<!-- =================================================
     STATISTIQUES
================================================== -->

<div class="cards">

    <div class="stat">

        <small>
            TOTAL DES VENTES
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


<div class="content">


    <!-- =================================================
         FORMULAIRE
    ================================================== -->

    <div class="card">

        <h2>
            ➕ Nouvelle vente
        </h2>

        <p>
            Choisis un produit enregistré dans ton stock.
        </p>


        <form method="POST">


            <input
                type="hidden"
                name="ajouter_vente"
                value="1"
            >


            <div class="group">

                <label>
                    PRODUIT
                </label>


                <select
                    name="produit_id"
                    id="produit_id"
                    required
                    onchange="produitSelectionne()"
                >

                    <option value="">
                        -- Sélectionner un produit --
                    </option>


                    <?php if ($produits && $produits->num_rows > 0): ?>

                        <?php while ($p = $produits->fetch_assoc()): ?>

                            <option
                                value="<?= (int)$p["id"] ?>"
                                data-prix="<?= htmlspecialchars($p["prix_vente"]) ?>"
                                data-stock="<?= (int)$p["stock"] ?>"
                            >

                                <?= htmlspecialchars($p["nom"]) ?>

                                —
                                Stock :
                                <?= (int)$p["stock"] ?>

                            </option>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <option value="" disabled>
                            Aucun produit enregistré
                        </option>

                    <?php endif; ?>

                </select>


                <div
                    class="product-info"
                    id="productInfo"
                ></div>

            </div>


            <div class="group">

                <label>
                    QUANTITÉ
                </label>

                <input
                    type="number"
                    name="quantite"
                    id="quantite"
                    value="1"
                    min="1"
                    required
                    oninput="calculer()"
                >

            </div>


            <div class="group">

                <label>
                    PRIX UNITAIRE
                </label>

                <input
                    type="number"
                    name="prix_unitaire"
                    id="prix_unitaire"
                    min="1"
                    step="500"
                    required
                    oninput="calculer()"
                >

            </div>


            <div class="group">

                <label>
                    MONTANT TOTAL
                </label>

                <div
                    class="montant"
                    id="montant"
                >
                    0 FG
                </div>

            </div>


            <div class="group">

                <label>
                    DESCRIPTION
                </label>

                <textarea
                    name="description"
                    placeholder="Ex : T-shirts vendus au client..."
                ></textarea>

            </div>


            <button
                type="submit"
                class="btn-primary"
            >
                💾 Enregistrer la vente
            </button>

        </form>

    </div>


    <!-- =================================================
         HISTORIQUE
    ================================================== -->

    <div class="card">

        <h2>
            📋 Historique des ventes
        </h2>

        <p>
            Les dernières ventes enregistrées.
        </p>


        <?php if ($ventes && $ventes->num_rows > 0): ?>


            <?php while ($v = $ventes->fetch_assoc()): ?>

                <div class="sale">


                    <div class="sale-top">

                        <div class="sale-product">

                            👕
                            <?= htmlspecialchars(
                                $v["produit_nom"]
                                ?? "Produit supprimé"
                            ) ?>

                        </div>


                        <div class="sale-amount">

                            <?= argent(
                                $v["montant"]
                            ) ?>

                        </div>

                    </div>


                    <div class="sale-info">

                        <span class="badge">

                            Quantité :
                            <?= (int)$v["quantite"] ?>

                        </span>


                        <span class="badge">

                            Prix unitaire :
                            <?= argent(
                                $v["prix_unitaire"]
                            ) ?>

                        </span>

                    </div>


                    <?php if (
                        !empty($v["description"])
                    ): ?>

                        <div class="sale-description">

                            <?= htmlspecialchars(
                                $v["description"]
                            ) ?>

                        </div>

                    <?php endif; ?>


                    <div class="sale-date">

                        📅
                        <?= htmlspecialchars(
                            $v["date_vente"]
                        ) ?>

                    </div>

                </div>

            <?php endwhile; ?>


        <?php else: ?>

            <div
                style="
                text-align:center;
                padding:30px 10px;
                color:#8998a5;
                font-size:12px;
                "
            >

                🛒

                <br><br>

                Aucune vente enregistrée pour le moment.

            </div>

        <?php endif; ?>

    </div>

</div>
```

</main>

<script>

/* =====================================================
   SÉLECTION DU PRODUIT
===================================================== */

function produitSelectionne() {

    const select =
        document.getElementById("produit_id");

    const prix =
        document.getElementById("prix_unitaire");

    const info =
        document.getElementById("productInfo");

    const option =
        select.options[
            select.selectedIndex
        ];


    if (
        !select.value ||
        !option
    ) {

        info.style.display = "none";

        prix.value = "";

        calculer();

        return;
    }


    const prixProduit =
        parseFloat(
            option.getAttribute(
                "data-prix"
            )
        ) || 0;


    const stock =
        parseInt(
            option.getAttribute(
                "data-stock"
            )
        ) || 0;


    prix.value = prixProduit;


    info.innerHTML =
        "📦 Stock disponible : <strong>"
        + stock
        + "</strong>"
        + " &nbsp; • &nbsp; "
        + "💰 Prix : <strong>"
        + formatNombre(prixProduit)
        + " FG</strong>";


    info.style.display = "block";


    const quantite =
        document.getElementById(
            "quantite"
        );

    quantite.max = stock;


    calculer();
}


/* =====================================================
   CALCUL DU MONTANT
===================================================== */

function calculer() {

    const quantite =
        parseFloat(
            document.getElementById(
                "quantite"
            ).value
        ) || 0;


    const prix =
        parseFloat(
            document.getElementById(
                "prix_unitaire"
            ).value
        ) || 0;


    const total =
        quantite * prix;


    document.getElementById(
        "montant"
    ).textContent =
        formatNombre(total)
        + " FG";
}


/* =====================================================
   FORMAT NOMBRE
===================================================== */

function formatNombre(nombre) {

    return new Intl.NumberFormat(
        "fr-FR"
    ).format(nombre);
}

</script>

</body>

</html>
