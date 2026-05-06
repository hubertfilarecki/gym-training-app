<?php
require_once __DIR__ . '/app/bootstrap/session.php';
require_once __DIR__ . '/app/bootstrap/db.php';
require_once __DIR__ . '/app/helpers/upload.php';

start_session();
$link = db_connect("Błąd połączenia:");

$errors = [];
$success_message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user = htmlentities($_POST['user'], ENT_QUOTES, "UTF-8");
    $pass = htmlentities($_POST['pass'], ENT_QUOTES, "UTF-8");
    $pass_confirm = htmlentities($_POST['pass_confirm'], ENT_QUOTES, "UTF-8");

    // Walidacja danych
    if (empty($user)) {
        $errors[] = "Pole login nie może być puste!";
    } elseif (!preg_match("/^[a-zA-Z0-9]+$/", $user)) {
        $errors[] = "Login może zawierać tylko litery i cyfry!";
    }

    if (empty($pass)) {
        $errors[] = "Pole hasło nie może być puste!";
    }
    if (empty($pass_confirm)) {
        $errors[] = "Pole powtórz hasło nie może być puste!";
    }
    if (!empty($pass) && $pass !== $pass_confirm) {
        $errors[] = "Hasła nie są takie same!";
    }

    if (empty($errors)) {
        // Przygotowanie zapytania SQL do sprawdzenia istnienia użytkownika
        $stmt = mysqli_prepare($link, "SELECT * FROM users WHERE username = ?");
        mysqli_stmt_bind_param($stmt, "s", $user);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if (mysqli_fetch_array($result)) {
            $errors[] = "Użytkownik o takim loginie już istnieje!";
        } else {
            // Przesyłanie zdjęcia
            $profile_picture = uploadProfilePicture($errors);

            if (empty($errors)) {
                // Przygotowanie zapytania SQL do wstawienia danych
                $stmt = mysqli_prepare($link, "INSERT INTO users (username, password, profile_picture) VALUES (?, ?, ?)");
                mysqli_stmt_bind_param($stmt, "sss", $user, $pass, $profile_picture);
                mysqli_stmt_execute($stmt);

                // Tworzenie folderu o nazwie loginu
                $user_folder = "uploads/" . $user;
                if (!is_dir($user_folder)) {
                    mkdir($user_folder, 0775, true);  // Tworzenie folderu z uprawnieniami
                }


                $success_message = "Rejestracja udana. Możesz teraz się zalogować.";
            }
        }

        mysqli_stmt_close($stmt);
    }

    mysqli_close($link);
}
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rejestracja</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="twoj_css.css">
</head>
<body>
<div class="login-form">
    <h3>Formularz rejestracji</h3>
    <p>Utwórz konto, aby kontynuować</p>

    <?php
    if (!empty($errors)) {
        echo "<ul>";
        foreach ($errors as $error) {
            echo "<li style='color: red;'>$error</li>";
        }
        echo "</ul>";
    }
    ?>

    <form method="post" action="" enctype="multipart/form-data">
        <div class="mb-3">
            <label for="user" class="form-label">Login</label>
            <input type="text" id="user" name="user" maxlength="20" class="form-control" value="<?php echo isset($user) ? $user : ''; ?>">
        </div>
        <div class="mb-3">
            <label for="pass" class="form-label">Hasło</label>
            <input type="password" id="pass" name="pass" maxlength="20" class="form-control">
        </div>
        <div class="mb-3">
            <label for="pass_confirm" class="form-label">Powtórz hasło</label>
            <input type="password" id="pass_confirm" name="pass_confirm" maxlength="20" class="form-control">
        </div>
        <div class="mb-3">
            <label for="profile_picture" class="form-label">Zdjęcie profilowe (opcjonalne)</label>
            <input type="file" id="profile_picture" name="profile_picture" class="form-control" accept=".jpg, .jpeg, .png">
            <label for="profile_picture" class="form-label"><p>(maksymalny rozmiar pliku 500KB)</p></label>
        </div>

        <input type="submit" value="Zarejestruj" class="btn btn-primary w-100">
        
        <!-- Wyświetlanie informacji o pomyślnej rejestracji -->
        <?php if (!empty($success_message)): ?>
            <p class="text-success mt-3"><?php echo $success_message; ?></p>
        <?php endif; ?>
    </form>
    <p class="mt-3">Masz już konto? <a href="logowanie.php" class="register-link">Zaloguj się</a></p>
</div>
</body>
</html>
