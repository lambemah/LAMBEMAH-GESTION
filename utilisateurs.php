<?php
session_start();
require_once "config.php";

if (!isset($_SESSION['id'])) {
    header('Location: index.php');
    exit;
}

$role = $_SESSION['role'] ?? 'lecture';
$isAdmin = ($role === 'admin');

$message = '';
$type_message = '';

$roles = [
    'admin' => '👑 Administrateur',
    'gestion' => '💼 Gestionnaire',
    'vendeur' => '💰 Vendeur',
    'comptable' => '🧾 Comptable',
    'lecture' => '👁️ Lecture seule'
];

function nomRole($role) {
    $roles = [
        'admin' => '👑 Administrateur',
        'gestion' => '💼 Gestionnaire',
        'vendeur' => '💰 Vendeur',
        'comptable' => '🧾 Comptable',
        'lecture' => '👁️ Lecture seule'
    ];

    return $roles[$role] ?? $role;
}

/* AJOUT / MODIFICATION / SUPPRESSION */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!$isAdmin) {
        $message = 'Accès réservé à l’administrateur.';
        $type_message = 'error';

    } else {

        $action = $_POST['action'] ?? '';

        /* AJOUT */
        if ($action === 'ajouter') {

            $nom = trim($_POST['nom'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $mot_de_passe = trim($_POST['mot_de_passe'] ?? '');
            $role_nouveau = trim($_POST['role'] ?? 'lecture');

            if ($nom === '' || $username === '' || $mot_de_passe === '') {

                $message = 'Veuillez remplir tous les champs.';
                $type_message = 'error';

            } elseif (!isset($roles[$role_nouveau])) {

                $message = 'Rôle invalide.';
                $type_message = 'error';

            } else {

                $check = $conn->prepare(
                    'SELECT id FROM utilisateurs WHERE username = ? LIMIT 1'
                );

                $check->bind_param('s', $username);
                $check->execute();

                $result = $check->get_result();

                if ($result->num_rows > 0) {

                    $message = 'Ce nom d’utilisateur existe déjà.';
                    $type_message = 'error';

                } else {

                    $idResult = $conn->query(
                        'SELECT COALESCE(MAX(id),0)+1 AS prochain_id FROM utilisateurs'
                    );

                    $idRow = $idResult ? $idResult->fetch_assoc() : null;
                    $nouvel_id = (int)($idRow['prochain_id'] ?? 1);

                    $hash = password_hash(
                        $mot_de_passe,
                        PASSWORD_DEFAULT
                    );

                    $stmt = $conn->prepare(
                        'INSERT INTO utilisateurs
                        (id, nom, username, mot_de_passe, role)
                        VALUES (?, ?, ?, ?, ?)'
                    );

                    $stmt->bind_param(
                        'issss',
                        $nouvel_id,
                        $nom,
                        $username,
                        $hash,
                        $role_nouveau
                    );

                    if ($stmt->execute()) {

                        $message = 'Utilisateur ajouté.';
                        $type_message = 'success';

                    } else {

                        $message = 'Erreur lors de l’ajout.';
                        $type_message = 'error';
                    }

                    $stmt->close();
                }

                $check->close();
            }
        }

        /* MODIFICATION */
        if ($action === 'modifier') {

            $id = (int)($_POST['id'] ?? 0);
            $nom = trim($_POST['nom'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $mot_de_passe = trim($_POST['mot_de_passe'] ?? '');
            $role_nouveau = trim($_POST['role'] ?? 'lecture');

            if ($id <= 0 || $nom === '' || $username === '') {

                $message = 'Informations invalides.';
                $type_message = 'error';

            } elseif (!isset($roles[$role_nouveau])) {

                $message = 'Rôle invalide.';
                $type_message = 'error';

            } elseif (
                $id === (int)$_SESSION['id']
                && $role_nouveau !== 'admin'
            ) {

                $message = 'Ton compte administrateur doit rester administrateur.';
                $type_message = 'error';

            } else {

                $check = $conn->prepare(
                    'SELECT id
                     FROM utilisateurs
                     WHERE username = ?
                     AND id <> ?
                     LIMIT 1'
                );

                $check->bind_param(
                    'si',
                    $username,
                    $id
                );

                $check->execute();

                $result = $check->get_result();

                if ($result->num_rows > 0) {

                    $message = 'Ce nom d’utilisateur existe déjà.';
                    $type_message = 'error';

                } else {

                    if ($mot_de_passe === '') {

                        $stmt = $conn->prepare(
                            'UPDATE utilisateurs
                             SET nom = ?, username = ?, role = ?
                             WHERE id = ?'
                        );

                        $stmt->bind_param(
                            'sssi',
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
                            'UPDATE utilisateurs
                             SET nom = ?,
                                 username = ?,
                                 mot_de_passe = ?,
                                 role = ?
                             WHERE id = ?'
                        );

                        $stmt->bind_param(
                            'ssssi',
                            $nom,
                            $username,
                            $hash,
                            $role_nouveau,
                            $id
                        );
                    }

                    if ($stmt->execute()) {

                        if ($id === (int)$_SESSION['id']) {

                            $_SESSION['nom'] = $nom;
                            $_SESSION['username'] = $username;
                            $_SESSION['role'] = $role_nouveau;
                        }

                        $message = 'Utilisateur modifié.';
                        $type_message = 'success';

                    } else {

                        $message = 'Erreur lors de la modification.';
                        $type_message = 'error';
                    }

                    $stmt->close();
                }

                $check->close();
            }
        }

        /* SUPPRESSION */
        if ($action === 'supprimer') {

            $id = (int)($_POST['id'] ?? 0);

            if ($id === (int)$_SESSION['id']) {

                $message = 'Tu ne peux pas supprimer ton propre compte.';
                $type_message = 'error';

            } elseif ($id > 0) {

                $stmt = $conn->prepare(
                    'DELETE FROM utilisateurs WHERE id = ?'
                );

                $stmt->bind_param('i', $id);

                if ($stmt->execute()) {

                    $message = 'Utilisateur supprimé.';
                    $type_message = 'success';

                } else {

                    $message = 'Erreur lors de la suppression.';
                    $type_message = 'error';
                }

                $stmt->close();
            }
        }
    }
}


/* UTILISATEUR À MODIFIER */
$modifier = null;

if (isset($_GET['modifier'])) {

    $id = (int)$_GET['modifier'];

    $stmt = $conn->prepare(
        'SELECT id, nom, username, role
         FROM utilisateurs
         WHERE id = ?
         LIMIT 1'
    );

    $stmt->bind_param('i', $id);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $modifier = $result->fetch_assoc();
    }

    $stmt->close();
}


/* LISTE DES UTILISATEURS */
$utilisateurs = $conn->query(
    'SELECT id, nom, username, role
     FROM utilisateurs
     ORDER BY id DESC'
);

?>
<!doctype html>
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
    font-size:13px;
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
    width:225px;
    background:#092544;
    color:#fff;
    padding:18px 12px;
    z-index:10;
}

.brand{
    text-align:center;
    padding:5px 5px 22px;
    border-bottom:1px solid rgba(255,255,255,.12);
    margin-bottom:12px;
}

.logo{
    width:125px;
    max-height:70px;
    object-fit:contain;
    margin:auto;
    display:block;
}

.brand h2{
    font-size:17px;
    margin-top:5px;
}

.brand span{
    font-size:9px;
    opacity:.75;
}

.nav{
    list-style:none;
}

.nav li{
    margin:3px 0;
}

.nav a{
    display:block;
    color:#e8f2ff;
    padding:10px 11px;
    border-radius:10px;
    font-size:12px;
}

.nav a:hover,
.nav a.active{
    background:#1878dc;
    color:#fff;
}

.bottom{
    position:absolute;
    left:12px;
    right:12px;
    bottom:15px;
}

.logout{
    display:block;
    color:#fff;
    background:#173554;
    padding:10px;
    border-radius:10px;
    font-size:12px;
    text-align:center;
}


/* MAIN */

.main{
    margin-left:225px;
    padding:24px;
    max-width:1400px;
}

.head{
    margin-bottom:18px;
}

.head h1{
    font-size:23px;
}

.head p{
    margin-top:4px;
    color:#728197;
    font-size:12px;
}


/* MESSAGE */

.msg{
    padding:10px 12px;
    border-radius:9px;
    margin-bottom:14px;
    font-size:12px;
}

.success{
    background:#e9f8ef;
    color:#13733d;
}

.error{
    background:#fff0f0;
    color:#b52222;
}


/* GRID */

.grid{
    display:grid;
    grid-template-columns:320px 1fr;
    gap:16px;
}


/* CARDS */

.card{
    background:#fff;
    border:1px solid #e1e9f2;
    border-radius:14px;
    padding:17px;
    box-shadow:0 4px 16px rgba(24,61,95,.06);
}

.card h2{
    font-size:15px;
    margin-bottom:15px;
}


/* FORM */

.group{
    margin-bottom:11px;
}

.group label{
    display:block;
    font-size:11px;
    font-weight:bold;
    margin-bottom:5px;
    color:#52657d;
}

.group input,
.group select{
    width:100%;
    padding:9px 10px;
    border:1px solid #d7e1ec;
    border-radius:8px;
    background:#fff;
    font-size:12px;
    outline:none;
}

.group input:focus,
.group select:focus{
    border-color:#1878dc;
}

.btn{
    border:0;
    border-radius:8px;
    padding:9px 12px;
    font-size:12px;
    font-weight:bold;
    cursor:pointer;
}

.primary{
    width:100%;
    background:#1878dc;
    color:#fff;
}

.primary:hover{
    background:#1267bf;
}

.cancel{
    display:inline-block;
    margin-top:10px;
    color:#1878dc;
    font-size:11px;
}


/* INFO */

.info{
    margin-top:13px;
    background:#f3f8fd;
    border-radius:9px;
    padding:10px;
    font-size:10px;
    line-height:1.7;
    color:#5e7088;
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
    min-width:540px;
}

th,
td{
    padding:10px 8px;
    text-align:left;
    border-bottom:1px solid #edf1f5;
    font-size:12px;
}

th{
    font-size:10px;
    color:#718096;
    text-transform:uppercase;
    background:#f6f9fc;
}

td strong{
    font-size:12px;
}

.badge{
    display:inline-block;
    padding:4px 7px;
    border-radius:15px;
    background:#eaf4ff;
    color:#176fc6;
    font-size:10px;
    font-weight:bold;
}

.actions{
    display:flex;
    gap:5px;
}

.icon{
    border:0;
    border-radius:7px;
    padding:6px 8px;
    cursor:pointer;
    font-size:12px;
}

.edit{
    background:#eaf4ff;
    color:#176fc6;
}

.delete{
    background:#fff0f0;
    color:#c62828;
}


/* RECHERCHE */

.search{
    width:100%;
    max-width:280px;
    padding:8px 10px;
    border:1px solid #d7e1ec;
    border-radius:8px;
    font-size:11px;
    margin-bottom:10px;
}

.note{
    font-size:10px;
    color:#8190a3;
    margin-bottom:10px;
}


/* MOBILE */

@media(max-width:900px){

    .sidebar{
        position:relative;
        width:100%;
        height:auto;
        padding:10px;
    }

    .brand{
        display:flex;
        align-items:center;
        gap:10px;
        text-align:left;
        padding:3px 5px 10px;
        margin-bottom:7px;
    }

    .logo{
        width:75px;
        height:38px;
        margin:0;
    }

    .brand h2{
        font-size:14px;
    }

    .brand span{
        font-size:8px;
    }

    .nav{
        display:grid;
        grid-template-columns:repeat(4,1fr);
        gap:3px;
    }

    .nav a{
        text-align:center;
        padding:8px 3px;
        font-size:10px;
    }

    .bottom{
        position:static;
        margin-top:6px;
    }

    .logout{
        font-size:10px;
        padding:7px;
    }

    .main{
        margin:0;
        padding:14px;
    }

    .head h1{
        font-size:19px;
    }

    .grid{
        grid-template-columns:1fr;
        gap:12px;
    }

    .card{
        padding:14px;
    }
}

@media(max-width:520px){

    .nav{
        grid-template-columns:repeat(3,1fr);
    }

    .main{
        padding:10px;
    }

    .card h2{
        font-size:14px;
    }

    th,
    td{
        padding:8px 6px;
        font-size:11px;
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
onerror="this.style.display='none'"
alt="LAMBEMAH GESTION"
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
<a class="active" href="utilisateurs.php">👥 Équipe</a>
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
Gestion des accès à LAMBEMAH GESTION.
</p>

</div>


<?php if ($message !== ''): ?>

<div class="msg <?= htmlspecialchars($type_message) ?>">

<?= htmlspecialchars($message) ?>

</div>

<?php endif; ?>


<div class="grid">


<!-- FORMULAIRE -->

<section class="card">

<?php if ($modifier): ?>

<h2>✏️ Modifier</h2>

<form method="post">

<input
type="hidden"
name="action"
value="modifier"
>

<input
type="hidden"
name="id"
value="<?= (int)$modifier['id'] ?>"
>


<div class="group">

<label>Nom complet</label>

<input
name="nom"
value="<?= htmlspecialchars($modifier['nom']) ?>"
required
>

</div>


<div class="group">

<label>Identifiant</label>

<input
name="username"
value="<?= htmlspecialchars($modifier['username']) ?>"
required
>

</div>


<div class="group">

<label>Nouveau mot de passe</label>

<input
type="password"
name="mot_de_passe"
placeholder="Laisser vide = conserver"
>

</div>


<div class="group">

<label>Droits</label>

<select name="role">

<?php foreach ($roles as $key => $label): ?>

<option
value="<?= $key ?>"
<?= $modifier['role'] === $key ? 'selected' : '' ?>
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


<?php else: ?>


<h2>➕ Ajouter</h2>

<form method="post">

<input
type="hidden"
name="action"
value="ajouter"
>


<div class="group">

<label>Nom complet</label>

<input
name="nom"
placeholder="Ex : Ibrahima Konaté"
required
>

</div>


<div class="group">

<label>Identifiant</label>

<input
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

<b>Administrateur</b> : accès complet.<br>

<b>Gestionnaire</b> : gestion de l’activité.<br>

<b>Vendeur</b> : produits et ventes.<br>

<b>Comptable</b> : recettes, dépenses, statistiques.<br>

<b>Lecture</b> : consultation.

</div>


<?php endif; ?>

</section>


<!-- LISTE -->

<section class="card">

<h2>👥 Membres</h2>

<p class="note">
Les modifications et suppressions sont réservées à l’administrateur.
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

<th>Actions</th>

</tr>

</thead>


<tbody>

<?php if ($utilisateurs && $utilisateurs->num_rows > 0): ?>

<?php while ($u = $utilisateurs->fetch_assoc()): ?>

<tr>

<td>

<strong>

<?= htmlspecialchars($u['nom']) ?>

</strong>

</td>


<td>

<?= htmlspecialchars($u['username']) ?>

</td>


<td>

<span class="badge">

<?= htmlspecialchars(nomRole($u['role'])) ?>

</span>

</td>


<td>

<div class="actions">


<?php if ($isAdmin): ?>

<a
href="utilisateurs.php?modifier=<?= (int)$u['id'] ?>"
>

<button
type="button"
class="icon edit"
>
✏️
</button>

</a>


<?php if ((int)$u['id'] !== (int)$_SESSION['id']): ?>

<form
method="post"
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
value="<?= (int)$u['id'] ?>"
>

<button
class="icon delete"
type="submit"
>
🗑️
</button>

</form>

<?php endif; ?>


<?php else: ?>

<span
style="font-size:10px;color:#8795a8"
>
Lecture
</span>

<?php endif; ?>


</div>

</td>

</tr>

<?php endwhile; ?>


<?php else: ?>

<tr>

<td colspan="4">
Aucun utilisateur.
</td>

</tr>

<?php endif; ?>

</tbody>

</table>

</div>

</section>

</div>

</main>


<script>

function filtrer(){

    const q =
        document
        .getElementById('recherche')
        .value
        .toLowerCase();

    document
    .querySelectorAll('#tableUsers tbody tr')
    .forEach(tr => {

        tr.style.display =
            tr.innerText
            .toLowerCase()
            .includes(q)
            ? ''
            : 'none';

    });

}

</script>

</body>

</html>
