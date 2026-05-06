<!DOCTYPE html>
<?php
require_once __DIR__ . '/app/bootstrap/session.php';

start_session();

// Sprawdzenie, czy użytkownik jest już zalogowany
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header('Location: plany.php'); // Przekierowanie na stronę planów treningowych
    exit();
}
?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="pl" lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Hubert Filarecki</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="twoj_css.css">
    <script src="https://code.jquery.com/jquery-3.5.1.js"></script>
    <script>
        // Pobranie informacji o przeglądarce i urządzeniu
        document.addEventListener('DOMContentLoaded', function() {
            function getBrowserName() {
                var userAgent = navigator.userAgent;
                if (userAgent.indexOf("Firefox") > -1) return "Mozilla Firefox";
                if (userAgent.indexOf("SamsungBrowser") > -1) return "Samsung Internet";
                if (userAgent.indexOf("OPR") > -1 || userAgent.indexOf("Opera") > -1) return "Opera";
                if (userAgent.indexOf("Trident") > -1 || userAgent.indexOf("MSIE") > -1) return "Internet Explorer";
                if (userAgent.indexOf("Edg") > -1) return "Microsoft Edge";
                if (userAgent.indexOf("Chrome") > -1) return "Google Chrome";
                if (userAgent.indexOf("Safari") > -1 && userAgent.indexOf("Chrome") === -1) return "Safari";
                return "Nieznana przeglądarka";
            }

            document.getElementById('browser_name').value = getBrowserName();
            document.getElementById("screenWidth").value = screen.width;
            document.getElementById("screenHeight").value = screen.height;
            document.getElementById("windowWidth").value = window.innerWidth;
            document.getElementById("windowHeight").value = window.innerHeight;
            document.getElementById("colorDepth").value = screen.colorDepth;
            document.getElementById("cookiesEnabled").value = navigator.cookieEnabled;
            document.getElementById("javaEnabled").value = navigator.javaEnabled();
            document.getElementById("language").value = navigator.language;
        });
    </script>
</head>
<body>
<div class="login-form">
    <h3>Formularz logowania</h3>
    <p>Zaloguj się, aby kontynuować</p>
    <form method="post" action="weryfikuj3.php">
        <!-- Login -->
        <div class="mb-3">
            <label for="user" class="form-label">Login</label>
            <input type="text" id="user" name="user" maxlength="20" class="form-control" required placeholder="Wpisz swój login">
        </div>

        <!-- Hasło -->
        <div class="mb-3">
            <label for="pass" class="form-label">Hasło</label>
            <input type="password" id="pass" name="pass" maxlength="20" class="form-control" required placeholder="Wpisz swoje hasło">
        </div>

        <!-- Dane urządzenia i przeglądarki -->
        <input type="hidden" name="browserName" id="browser_name">
        <input type="hidden" id="screenWidth" name="screenWidth">
        <input type="hidden" id="screenHeight" name="screenHeight">
        <input type="hidden" id="windowWidth" name="windowWidth">
        <input type="hidden" id="windowHeight" name="windowHeight">
        <input type="hidden" id="colorDepth" name="colorDepth">
        <input type="hidden" id="cookiesEnabled" name="cookiesEnabled">
        <input type="hidden" id="javaEnabled" name="javaEnabled">
        <input type="hidden" id="language" name="language">

        <!-- Przycisk logowania -->
        <input type="submit" value="Zaloguj się" class="btn btn-primary w-100">
    </form>

    <!-- Wyświetlanie błędów logowania -->
    <?php
    if (isset($_SESSION['error'])) {
        echo '<div class="alert alert-danger mt-3">' . $_SESSION['error'] . '</div>';
        unset($_SESSION['error']);
    }
    ?>

    <!-- Odnośnik do rejestracji -->
    <p class="mt-3">Nie masz konta? <a href="rejestruj.php" class="register-link">Zarejestruj się</a></p>
</div>
</body>
</html>
