<?php
session_start();
require_once "config.php";

/* =========================
   SESSION
========================= */

$connecte = isset($_SESSION["id"]);

if (!$connecte && !empty($_SESSION["username"])) {

    $stmt = $conn->prepare(
        "SELECT id, nom, username, role
         FROM utilisateurs
         WHERE username = ?
         LIMIT 1"
    );

    if ($stmt) {

        $stmt->bind_param("s", $_SESSION["username"]);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($result && $result->num_rows === 1) {

            $u = $result->fetch_assoc();

            $_SESSION["id"] = $u["id"];
            $_SESSION["nom"] = $u["nom"];
            $_SESSION["username"] = $u["username"];
            $_SESSION["role"] = $u["role"];

            $connecte = true;
        }

        $stmt->close();
    }
}

$role = $_SESSION["role"] ?? "lecture";
$isAdmin = ($role === "admin");

$message = "";
$type_message = "";

$roles = [
    "admin" => "👑 Administrateur",
    "gestion" => "💼 Gestionnaire",
    "vendeur" => "💰 Vendeur",
    "comptable" => "🧾 Comptable",
    "lecture" => "👁️ Lecture seule"
];

function nomRole($role)
{
    $roles = [
        "admin" => "👑 Administrateur",
        "gestion" => "💼 Gestionnaire",
        "vendeur" => "💰 Vendeur",
        "comptable" => "🧾 Comptable",
        "lecture" => "👁️ Lecture seule"
    ];

    return $roles[$role] ?? $role;
}


/* =========================
   ACTIONS
========================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && $isAdmin) {

    $action = $_POST["action"] ?? "";


    /* AJOUT */
    if ($action === "ajouter") {

        $nom = trim($_POST["nom"] ?? "");
        $username = trim($_POST["username"] ?? "");
        $mot_de_passe = trim($_POST["mot_de_passe"] ?? "");
        $role_nouveau = trim($_POST["role"] ?? "lecture");

        if (
            $nom === "" ||
            $username === "" ||
            $mot_de_passe === ""
        ) {

            $message = "Veuillez remplir tous les champs.";
            $type_message = "error";

        } elseif (!isset($roles[$role_nouveau])) {

            $message = "Rôle invalide.";
            $type_message = "error";

        } else {

            $check = $conn->prepare(
                "SELECT id
                 FROM utilisateurs
                 WHERE username = ?
                 LIMIT 1"
            );

            $check->bind_param("s", $username);
            $check->execute();

            $result = $check->get_result();

            if ($result && $result->num_rows > 0) {

                $message = "Ce nom d'utilisateur existe déjà.";
                $type_message = "error";

            } else {

                /* ID manuel */
                $idResult = $conn->query(
                    "SELECT COALESCE(MAX(id),0)+1 AS prochain_id
                     FROM utilisateurs"
                );

                $idRow = $idResult
                    ? $idResult->fetch_assoc()
                    : null;

                $nouvel_id = (int)($idRow["prochain_id"] ?? 1);

                /* Mot de passe sécurisé */
                $hash = password_hash(
                    $mot_de_passe,
                    PASSWORD_DEFAULT
                );

                $stmt = $conn->prepare(
                    "INSERT INTO utilisateurs
                    (id, nom, username, mot_de_passe, role)
                    VALUES (?, ?, ?, ?, ?)"
                );

                if ($stmt) {

                    $stmt->bind_param(
                        "issss",
                        $nouvel_id,
                        $nom,
                        $username,
                        $hash,
                        $role_nouveau
                    );

                    if ($stmt->execute()) {

                        $message = "Utilisateur ajouté.";
                        $type_message = "success";

                    } else {

                        $message = "Erreur lors de l'ajout.";
                        $type_message = "error";
                    }

                    $stmt->close();

                } else {

                    $message = "Erreur de préparation.";
                    $type_message = "error";
                }
            }

            $check->close();
        }
    }


    /* MODIFICATION */
    if ($action === "modifier") {

        $id = (int)($_POST["id"] ?? 0);
        $nom = trim($_POST["nom"] ?? "");
        $username = trim($_POST["username"] ?? "");
        $mot_de_passe = trim($_POST["mot_de_passe"] ?? "");
        $role_nouveau = trim($_POST["role"] ?? "lecture");

        if (
            $id <= 0 ||
            $nom === "" ||
            $username === ""
        ) {

            $message = "Informations invalides.";
            $type_message = "error";

        } elseif (!isset($roles[$role_nouveau])) {

            $message = "Rôle invalide.";
            $type_message = "error";

        } elseif (
            $id === (int)($_SESSION["id"] ?? 0) &&
            $role_nouveau !== "admin"
        ) {

            $message = "Ton propre compte doit rester administrateur.";
            $type_message = "error";

        } else {

            /* Vérifier username */
            $check = $conn->prepare(
                "SELECT id
                 FROM utilisateurs
                 WHERE username = ?
                 AND id <> ?
                 LIMIT 1"
            );

            $check->bind_param(
                "si",
                $username,
                $id
            );

            $check->execute();

            $result = $check->get_result();

            if ($result && $result->num_rows > 0) {

                $message = "Ce nom d'utilisateur existe déjà.";
                $type_message = "error";

            } else {

                if ($mot_de_passe === "") {

                    $stmt = $conn->prepare(
                        "UPDATE utilisateurs
                         SET nom = ?,
                             username = ?,
                             role = ?
                         WHERE id = ?"
                    );

                    $stmt->bind_param(
                        "sssi",
                        $nom,
                        $username,
                        $role_nouveau,
                        $id
                    );

                } else {

                    $hash = password_hash(
                        $mot_de_passe,
                        PASSWORD_DEFAULT
                    );

                    $stmt = $conn->prepare(
                        "UPDATE utilisateurs
                         SET nom = ?,
                             username = ?,
                             mot_de_passe = ?,
                             role = ?
                         WHERE id = ?"
                    );

                    $stmt->bind_param(
                        "ssssi",
                        $nom,
                        $username,
                        $hash,
                        $role_nouveau,
                        $id
                    );
                }

                if ($stmt && $stmt->execute()) {

                    if (
                        $id ===
                        (int)($_SESSION["id"] ?? 0)
                    ) {

                        $_SESSION["nom"] = $nom;
                        $_SESSION["username"] = $username;
                        $_SESSION["role"] = $role_nouveau;
                    }

                    $message = "Utilisateur modifié.";
                    $type_message = "success";

                } else {

                    $message = "Erreur lors de la modification.";
                    $type_message = "error";
                }

                if ($stmt) {
                    $stmt->close();
                }
            }

            $check->close();
        }
    }


    /* SUPPRESSION */
    if ($action === "supprimer") {

        $id = (int)($_POST["id"] ?? 0);

        if (
            $id ===
            (int)($_SESSION["id"] ?? 0)
        ) {

            $message = "Impossible de supprimer ton propre compte.";
            $type_message = "error";

        } elseif ($id > 0) {

            $stmt = $conn->prepare(
                "DELETE FROM utilisateurs
                 WHERE id = ?"
            );

            if ($stmt) {

                $stmt->bind_param("i", $id);

                if ($stmt->execute()) {

                    $message = "Utilisateur supprimé.";
                    $type_message = "success";

                } else {

                    $message = "Erreur lors de la suppression.";
                    $type_message = "error";
                }

                $stmt->close();
            }
        }
    }
}


/* =========================
   MODIFIER
========================= */

$modifier = null;

if (isset($_GET["modifier"])) {

    $id = (int)$_GET["modifier"];

    $stmt = $conn->prepare(
        "SELECT id, nom, username, role
         FROM utilisateurs
         WHERE id = ?
         LIMIT 1"
    );

    if ($stmt) {

        $stmt->bind_param("i", $id);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($result && $result->num_rows === 1) {
            $modifier = $result->fetch_assoc();
        }

        $stmt->close();
    }
}


/* =========================
   LISTE
========================= */

$utilisateurs = $conn->query(
    "SELECT id, nom, username, role
     FROM utilisateurs
     ORDER BY id DESC"
);

?>
<!DOCTYPE html>

<html lang="fr">

<head>

<meta charset="UTF-8">

<meta
name="viewport"
content="width=device-width,initial-scale=1"
>

<title>Équipe - LAMBEMAH GESTION</title>

<style>

*{
    box-sizing:border-box;
    margin:0;
    padding:0;
}

body{
    font-family:Arial,sans-serif;
    background:#f4f8fc;
    color:#12233d;
    font-size:12px;
}

a{
    text-decoration:none;
}


/* SIDEBAR */

.sidebar{
    position:fixed;
    left:0;
    top:0;
    bottom:0;
    width:220px;
    background:#092544;
    color:white;
    padding:16px 11px;
}

.brand{
    text-align:center;
    padding:4px 4px 17px;
    border-bottom:1px solid #ffffff1c;
    margin-bottom:10px;
}

.logo{
    width:105px;
    max-height:55px;
    object-fit:contain;
    margin:auto;
    display:block;
}

.brand h2{
    font-size:16px;
    margin-top:4px;
}

.brand span{
    font-size:8px;
    opacity:.7;
}

.nav{
    list-style:none;
}

.nav li{
    margin:2px 0;
}

.nav a{
    display:block;
    color:#e7f1ff;
    padding:9px 10px;
    border-radius:8px;
    font-size:11px;
}

.nav a:hover,
.nav a.active{
    background:#1878dc;
}

.bottom{
    position:absolute;
    bottom:12px;
    left:11px;
    right:11px;
}

.logout{
    display:block;
    background:#173554;
    color:white;
    padding:9px;
    border-radius:8px;
    text-align:center;
    font-size:10px;
}


/* MAIN */

.main{
    margin-left:220px;
    padding:22px;
}

.head{
    margin-bottom:15px;
}

.head h1{
    font-size:21px;
}

.head p{
    color:#7b8b9e;
    font-size:10px;
    margin-top:3px;
}


/* MESSAGE */

.msg{
    padding:9px 11px;
    border-radius:8px;
    margin-bottom:12px;
    font-size:11px;
}

.success{
    background:#e9f8ef;
    color:#14743e;
}

.error{
    background:#fff0f0;
    color:#b52222;
}


/* GRID */

.grid{
    display:grid;
    grid-template-columns:300px 1fr;
    gap:14px;
}


/* CARD */

.card{
    background:#fff;
    border:1px solid #e1e9f2;
    border-radius:12px;
    padding:15px;
    box-shadow:0 3px 12px #183d5f0a;
}

.card h2{
    font-size:14px;
    margin-bottom:13px;
}


/* FORM */

.group{
    margin-bottom:9px;
}

.group label{
    display:block;
    color:#52657d;
    font-size:10px;
    font-weight:bold;
    margin-bottom:4px;
}

.group input,
.group select{
    width:100%;
    padding:8px 9px;
    border:1px solid #d7e1ec;
    border-radius:7px;
    font-size:11px;
    background:white;
}

.btn{
    width:100%;
    border:0;
    border-radius:7px;
    padding:8px;
    font-size:11px;
    font-weight:bold;
    cursor:pointer;
}

.primary{
    background:#1878dc;
    color:white;
}

.primary:hover{
    background:#1267bf;
}

.cancel{
    display:block;
    margin-top:8px;
    color:#1878dc;
    font-size:10px;
}


/* INFO */

.info{
    margin-top:11px;
    padding:9px;
    background:#f3f8fd;
    border-radius:8px;
    font-size:9px;
    line-height:1.7;
    color:#65778b;
}

.info b{
    color:#1878dc;
}


/* TABLE */

.table-wrap{
    overflow-x:auto;
}

table{
    width:100%;
    border-collapse:collapse;
    min-width:500px;
}

th,
td{
    padding:9px 7px;
    text-align:left;
    border-bottom:1px solid #edf1f5;
    font-size:11px;
}

th{
    background:#f6f9fc;
    color:#718096;
    font-size:9px;
}

.badge{
    display:inline-block;
    padding:4px 6px;
    border-radius:12px;
    background:#eaf4ff;
    color:#176fc6;
    font-size:9px;
    font-weight:bold;
}

.actions{
    display:flex;
    gap:4px;
}

.icon{
    border:0;
    border-radius:6px;
    padding:5px 7px;
    cursor:pointer;
    font-size:10px;
}

.edit{
    background:#eaf4ff;
    color:#176fc6;
}

.delete{
    background:#fff0f0;
    color:#c62828;
}

.search{
    width:100%;
    max-width:250px;
    padding:7px 9px;
    border:1px solid #d7e1ec;
    border-radius:7px;
    font-size:10px;
    margin-bottom:9px;
}

.note{
    color:#8290a1;
    font-size:9px;
    margin-bottom:9px;
}


/* MOBILE */

@media(max-width:850px){

    .sidebar{
        position:relative;
        width:100%;
        height:auto;
        padding:9px;
    }

    .brand{
        display:flex;
        align-items:center;
        gap:8px;
        text-align:left;
        padding:2px 4px 9px;
    }

    .logo{
        width:70px;
        height:35px;
        margin:0;
    }

    .brand h2{
        font-size:13px;
    }

    .brand span{
        font-size:7px;
    }

    .nav{
        display:grid;
        grid-template-columns:repeat(4,1fr);
        gap:2px;
    }

    .nav a{
        text-align:center;
        padding:7px 2px;
        font-size:9px;
    }

    .bottom{
        position:static;
        margin-top:5px;
    }

    .logout{
        padding:6px;
        font-size:9px;
    }

    .main{
        margin:0;
        padding:11px;
    }

    .head h1{
        font-size:18px;
    }

    .grid{
        grid-template-columns:1fr;
        gap:10px;
    }

    .card{
        padding:12px;
    }
}

@media(max-width:500px){

    .nav{
        grid-template-columns:repeat(3,1fr);
    }

    .main{
        padding:8px;
    }

    .card h2{
        font-size:13px;
    }

    th,
    td{
        padding:7px 5px;
        font-size:10px;
    }
}

</style>

</head>


<body>


<aside class="sidebar">

<div class="brand">

<img
class="logo"
src="assets/logo.png"
alt="LAMBEMAH"
onerror="this.style.display='none'"
>

<div>

<h2>LAMBEMAH</h2>

<span>GESTION • PRESTATION</span>

</div>

</div>


<ul class="nav">

<li>
<a href="index.php">🏠 Accueil</a>
</li>

<li>
<a href="produits.php">📦 Produits</a>
</li>

<li>
<a href="ventes.php">💰 Ventes</a>
</li>

<li>
<a href="prestations.php">🖨️ Prestations</a>
</li>

<li>
<a href="recettes.php">💵 Recettes</a>
</li>

<li>
<a href="depenses.php">💸 Dépenses</a>
</li>

<li>
<a href="statistiques.php">📊 Statistiques</a>
</li>

<li>
<a
class="active"
href="utilisateurs.php"
>
👥 Équipe
</a>
</li>

</ul>


<div class="bottom">

<a
class="logout"
href="index.php?logout=1"
>
🚪 Déconnexion
</a>

</div>

</aside>


<main class="main">


<div class="head">

<h1>👥 Équipe</h1>

<p>
Gestion des utilisateurs et des droits.
</p>

</div>


<?php if (!$connecte): ?>

<div class="msg error">

⚠️ Session non reconnue.
<br>
Retourne à l'accueil et reconnecte-toi.

<br><br>

<a
href="index.php"
style="color:#b52222;font-weight:bold"
>
→ Se connecter
</a>

</div>


<?php else: ?>


<?php if ($message !== ""): ?>

<div class="msg <?= htmlspecialchars($type_message) ?>">

<?= htmlspecialchars($message) ?>

</div>

<?php endif; ?>


<div class="grid">


<!-- FORMULAIRE -->

<section class="card">


<?php if ($modifier && $isAdmin): ?>


<h2>✏️ Modifier</h2>


<form method="POST">

<input
type="hidden"
name="action"
value="modifier"
>

<input
type="hidden"
name="id"
value="<?= (int)$modifier["id"] ?>"
>


<div class="group">

<label>Nom complet</label>

<input
type="text"
name="nom"
value="<?= htmlspecialchars($modifier["nom"]) ?>"
required
>

</div>


<div class="group">

<label>Identifiant</label>

<input
type="text"
name="username"
value="<?= htmlspecialchars($modifier["username"]) ?>"
required
>

</div>


<div class="group">

<label>Nouveau mot de passe</label>

<input
type="password"
name="mot_de_passe"
placeholder="Vide = conserver"
>

</div>


<div class="group">

<label>Droits</label>

<select name="role">

<?php foreach ($roles as $key => $label): ?>

<option
value="<?= $key ?>"
<?= $modifier["role"] === $key ? "selected" : "" ?>
>

<?= $label ?>

</option>

<?php endforeach; ?>

</select>

</div>


<button
class="btn primary"
type="submit"
>
💾 Enregistrer
</button>

</form>


<a
class="cancel"
href="utilisateurs.php"
>
← Annuler
</a>


<?php elseif ($isAdmin): ?>


<h2>➕ Ajouter</h2>


<form method="POST">

<input
type="hidden"
name="action"
value="ajouter"
>


<div class="group">

<label>Nom complet</label>

<input
type="text"
name="nom"
placeholder="Ex : Ibrahima Konaté"
required
>

</div>


<div class="group">

<label>Identifiant</label>

<input
type="text"
name="username"
placeholder="Ex : ibrahima"
required
>

</div>


<div class="group">

<label>Mot de passe</label>

<input
type="password"
name="mot_de_passe"
required
>

</div>


<div class="group">

<label>Droits</label>

<select name="role">

<option value="lecture">
👁️ Lecture seule
</option>

<option value="vendeur">
💰 Vendeur
</option>

<option value="comptable">
🧾 Comptable
</option>

<option value="gestion">
💼 Gestionnaire
</option>

<option value="admin">
👑 Administrateur
</option>

</select>

</div>


<button
class="btn primary"
type="submit"
>
➕ Créer
</button>

</form>


<div class="info">

<b>Admin</b> : accès complet.<br>
<b>Gestion</b> : gestion activité.<br>
<b>Vendeur</b> : produits + ventes.<br>
<b>Comptable</b> : recettes + dépenses.<br>
<b>Lecture</b> : consultation.

</div>


<?php else: ?>


<h2>👁️ Consultation</h2>

<div class="info">

Tu es connecté avec le rôle :

<br><br>

<b><?= htmlspecialchars(nomRole($role)) ?></b>

<br><br>

Seul l'administrateur peut modifier l'équipe.

</div>


<?php endif; ?>


</section>


<!-- LISTE -->

<section class="card">


<h2>👥 Membres</h2>


<p class="note">
<?= $isAdmin
    ? "Ajouter, modifier ou supprimer un membre."
    : "Consultation des membres."
?>
</p>


<input
class="search"
id="recherche"
type="text"
placeholder="🔎 Rechercher..."
onkeyup="filtrer()"
>


<div class="table-wrap">

<table id="tableUsers">

<thead>

<tr>

<th>Nom</th>

<th>Identifiant</th>

<th>Droits</th>

<?php if ($isAdmin): ?>

<th>Actions</th>

<?php endif; ?>

</tr>

</thead>


<tbody>


<?php if ($utilisateurs && $utilisateurs->num_rows > 0): ?>


<?php while ($u = $utilisateurs->fetch_assoc()): ?>


<tr>


<td>

<strong>

<?= htmlspecialchars($u["nom"]) ?>

</strong>

</td>


<td>

<?= htmlspecialchars($u["username"]) ?>

</td>


<td>

<span class="badge">

<?= htmlspecialchars(nomRole($u["role"])) ?>

</span>

</td>


<?php if ($isAdmin): ?>

<td>

<div class="actions">


<a
href="utilisateurs.php?modifier=<?= (int)$u["id"] ?>"
>

<button
class="icon edit"
type="button"
>
✏️
</button>

</a>


<?php if (
    (int)$u["id"] !==
    (int)$_SESSION["id"]
): ?>


<form
method="POST"
onsubmit="return confirm('Supprimer cet utilisateur ?');"
>

<input
type="hidden"
name="action"
value="supprimer"
>

<input
type="hidden"
name="id"
value="<?= (int)$u["id"] ?>"
>

<button
class="icon delete"
type="submit"
>
🗑️
</button>

</form>


<?php endif; ?>


</div>

</td>

<?php endif; ?>


</tr>


<?php endwhile; ?>


<?php else: ?>


<tr>

<td
colspan="<?= $isAdmin ? 4 : 3 ?>"
>
Aucun utilisateur.
</td>

</tr>


<?php endif; ?>


</tbody>

</table>

</div>


</section>


</div>


<?php endif; ?>


</main>


<script>

function filtrer(){

    const recherche =
        document
        .getElementById("recherche")
        .value
        .toLowerCase();

    document
    .querySelectorAll("#tableUsers tbody tr")
    .forEach(function(ligne){

        ligne.style.display =
            ligne.innerText
            .toLowerCase()
            .includes(recherche)
            ? ""
            : "none";

    });
}

</script>


</body>

</html>
