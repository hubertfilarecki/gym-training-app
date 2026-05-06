<?php declare(strict_types=1); ?>
<?php
require_once __DIR__ . '/app/bootstrap/session.php';

start_session();
require_login();

$username = current_username();
$profile_picture = current_profile_picture();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal Treningowy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="twoj_css.css" rel="stylesheet">
</head>
<body>

<header>
    <div class="container">
        <nav class="navbar navbar-expand-lg navbar-dark fixed-top custom-navbar">
            <div class="container-fluid">
                <div class="collapse navbar-collapse d-flex justify-content-between align-items-center" id="main_nav">
                    <div class="d-flex align-items-center">
                        <a href="plany.php" class="me-3">
                            <img src="uploads/politechnika.png" alt="Strona główna" width="210" height="102" class="rounded">
                        </a>
                        <a href="plany.php" class="btn btn-outline-light me-2">
                            <i class="fas fa-dumbbell"></i> Plany treningowe
                        </a>
                        <a href="komunikator.php" class="btn btn-outline-light me-2">
                            <i class="fas fa-comments"></i> Komunikator
                        </a>
                        
                        <!-- Przycisk dostępny tylko dla administratorów -->
                        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                        <a href="goscie.php" class="btn btn-outline-warning me-2">
                            <i class="fas fa-users"></i> Goście portalu
                        </a>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex align-items-center">
                        <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Zdjęcie profilowe" class="rounded-circle me-2" width="40" height="40">
                        <span class="user-name text-light me-2">
                            <?php echo htmlspecialchars($username); ?>
                            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                                <span class="badge bg-danger ms-1" title="Administrator">ADMIN</span>
                            <?php endif; ?>
                        </span>
                        <a href="logout.php" class="btn btn-outline-danger">
                            <i class="fas fa-sign-out-alt"></i> Wyloguj
                        </a>
                    </div>
                </div>

                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#main_nav" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
            </div>
        </nav>
    </div>
</header>

<main style="margin-top: 120px;">

<?php
function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function getUserRole() {
    return $_SESSION['role'] ?? 'user';
}
?>
