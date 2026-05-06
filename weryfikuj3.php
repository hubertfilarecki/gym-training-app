<?php
require_once __DIR__ . '/app/bootstrap/session.php';
require_once __DIR__ . '/app/bootstrap/db.php';

start_session();
$user = htmlentities($_POST['user'], ENT_QUOTES, "UTF-8");
$pass = htmlentities($_POST['pass'], ENT_QUOTES, "UTF-8");

$link = db_connect("Błąd:");
$result = mysqli_query($link, "SELECT * FROM users WHERE username='$user'");
$rekord = mysqli_fetch_array($result);

$ip = $_SERVER["REMOTE_ADDR"];
date_default_timezone_set('Europe/Warsaw');
$datetime = date("Y-m-d H:i:s");

$browserName = $_POST['browserName'];
$screenWidth = $_POST['screenWidth'];
$screenHeight = $_POST['screenHeight'];
$windowWidth = $_POST['windowWidth'];
$windowHeight = $_POST['windowHeight'];
$colorDepth = $_POST['colorDepth'];
$cookiesEnabled = $_POST['cookiesEnabled'];
$javaEnabled = $_POST['javaEnabled'];
$language = $_POST['language'];

$login_status = "Nieudane"; // Domyślnie jest status nieudane

if (!$rekord) {
    $_SESSION['error'] = "Brak użytkownika o takim loginie!";
} else {
    if ($rekord['password'] == $pass) {
        // Zmiana statusu logowania na udane
        $login_status = "Udane";

        // Zapisywanie informacji o zalogowanym użytkowniku w sesji
        $_SESSION['loggedin'] = true;
        $_SESSION['username'] = $rekord['username'];
        $_SESSION['user_id'] = $rekord['id'];
        $_SESSION['role'] = $rekord['role'];
        $_SESSION['profile_picture'] = !empty($rekord['profile_picture']) ? "uploads/" . $rekord['profile_picture'] : "uploads/default.png";

        // Zapisywanie próby logowania do bazy danych przed przekierowaniem
        mysqli_query($link, "INSERT INTO goscieportalu (ip, datetime, browser, screen_width, screen_height, window_width, window_height, color_depth, cookies_enabled, java_enabled, language, login_status) 
                             VALUES ('$ip', '$datetime', '$browserName', '$screenWidth', '$screenHeight', '$windowWidth', '$windowHeight', '$colorDepth', '$cookiesEnabled', '$javaEnabled', '$language', '$login_status')");

        // Przekierowanie użytkownika po udanym logowaniu
        mysqli_close($link);
        header('Location: plany.php');
        exit;
    } else {
        $_SESSION['error'] = "Błąd w haśle!";
    }
}

// Zapisywanie próby logowania dla błędnych prób logowania
mysqli_query($link, "INSERT INTO goscieportalu (ip, datetime, browser, screen_width, screen_height, window_width, window_height, color_depth, cookies_enabled, java_enabled, language, login_status) 
                     VALUES ('$ip', '$datetime', '$browserName', '$screenWidth', '$screenHeight', '$windowWidth', '$windowHeight', '$colorDepth', '$cookiesEnabled', '$javaEnabled', '$language', '$login_status')");

// Zamknięcie połączenia i przekierowanie w przypadku błędu
mysqli_close($link);
header('Location: logowanie.php');
exit;
?>
