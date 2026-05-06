<?php
require_once __DIR__ . '/app/bootstrap/session.php';
require_once __DIR__ . '/app/bootstrap/db.php';

start_session();
require_login();
require_admin();

?>

<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<meta name="description" content="Twój Opis">
<meta name="author" content="Twoje dane">
<meta name="keywords" content="Twoje słowa kluczowe">
<title>Hubert Filarecki</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-GLhlTQ8iRABdZLl6O3oVMWSktQOp6b7In1Zl3/Jr59b6EGGoI1aFkw7cmDA6j6gD" crossorigin="anonymous">
<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.10.24/css/dataTables.bootstrap5.min.css">
<style type="text/css" class="init"></style>
<link rel="stylesheet" type="text/css" href="twoj_css.css">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js" integrity="sha384-w76AqPfDkMBDXo30jS1Sgez6pr3x5MlQ1ZAGC+nuZB+EYdgRZgiwxhTBTkF7CXvN" crossorigin="anonymous"></script>
<script type="text/javascript" language="javascript" src="https://code.jquery.com/jquery-3.5.1.js"></script>
<script type="text/javascript" language="javascript" src="https://cdn.datatables.net/1.10.24/js/jquery.dataTables.min.js"></script>
<script type="text/javascript" language="javascript"
src="https://cdn.datatables.net/1.10.24/js/dataTables.bootstrap5.min.js"></script>
<script type="text/javascript" src="twoj_js.js"></script>
</head>
<body onload="myLoadHeader()">
<div id='myHeader'> </div>
<main class="mt-5">
<section class="sekcja1">
<?php

    // Funkcja pobierająca dane geolokalizacyjne na podstawie IP
    function ip_details($ip) {
        $json = file_get_contents ("http://ipinfo.io/{$ip}/geo");
        $details = json_decode ($json);
        return $details;
    }

    $link = db_connect("Błąd:");
    $result = mysqli_query($link, "SELECT * FROM goscieportalu"); //pobranie wszystkich danych z tabeli

    // Wyświetlanie pobranych danych w tabeli
    if (mysqli_num_rows($result) > 0) {
        echo "<table class='table w-75 mx-auto'>";
        echo "<tr>
                <th scope='col'>ID</th>
                <th scope='col'>IP</th>
                <th scope='col'>Datetime</th>
                <th scope='col'>Przeglądarka</th>
                <th scope='col'>Rozdzielczość ekranu</th>
                <th scope='col'>Rozdzielczość okna</th>
                <th scope='col'>Ilość kolorów</th>
                <th scope='col'>Ciasteczka włączone</th>
                <th scope='col'>Java włączona</th>
                <th scope='col'>Język przeglądarki</th>
                <th scope='col'>Status logowania</th>
                <th scope='col'>Link do lokalizacji</th>
              </tr>";

        while ($rekord = mysqli_fetch_assoc($result)) {
            
            $loc = ip_details($rekord['ip']) -> loc;

            echo "<tr>";
            echo "<td>" . $rekord['id'] . "</td>";
            echo "<td>" . $rekord['ip'] . "</td>"; 
            echo "<td>" . $rekord['datetime'] . "</td>"; // Data i godzina
            echo "<td>" . $rekord['browser'] . "</td>"; // Przeglądarka
            echo "<td>" . $rekord['screen_width'] . "x" . $rekord['screen_height'] . "</td>"; // Rozdzielczość ekranu
            echo "<td>" . $rekord['window_width'] . "x" . $rekord['window_height'] . "</td>"; // Rozdzielczość okna
            echo "<td>" . $rekord['color_depth'] . " bitów</td>"; // Kolory
            echo "<td>" . ($rekord['cookies_enabled'] ? 'Tak' : 'Nie') . "</td>"; // Czy ciasteczka włączone
            echo "<td>" . ($rekord['java_enabled'] ? 'Tak' : 'Nie') . "</td>"; // Czy Java włączona
            echo "<td>" . $rekord['language'] . "</td>"; // Język
            echo "<td>" . $rekord['login_status'] . "</td>"; // Status logowania
            echo "<td> <a href='https://www.google.pl/maps/place/$loc' target='_blank'>LINK</a> </td>"; // Link do lokalizacji
            echo "</tr>";
        }

        echo "</table>"; 
    } else {
        echo "Brak wyników do wyświetlenia.";
    }

    mysqli_close($link);

?>
</section>
</main>

<?php require_once 'footer.php'; ?>
</body>
</html>