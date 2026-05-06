<?php declare(strict_types=1);

function uploadProfilePicture(array &$errors, bool $includeDebug = false): string {
    $target_dir = "uploads/";
    $default_picture = "default.png";

    if (!is_dir($target_dir)) {
        if (!mkdir($target_dir, 0775, true)) {
            $errors[] = "Nie można utworzyć katalogu na serwerze.";
            return $default_picture;
        }
    }

    if (!empty($_FILES["profile_picture"]["name"])) {
        $target_file = $target_dir . basename($_FILES["profile_picture"]["name"]);
        $imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
        $uploadOk = 1;

        $check = getimagesize($_FILES["profile_picture"]["tmp_name"]);
        if ($check === false) {
            $errors[] = "Plik nie jest obrazem.";
            $uploadOk = 0;
        }

        if ($_FILES["profile_picture"]["size"] > 500000) {
            $errors[] = "Plik musi mieć maksymalny rozmiar 500KB.";
            $uploadOk = 0;
        }

        if ($imageFileType != "jpg" && $imageFileType != "jpeg" && $imageFileType != "png") {
            $errors[] = "Dozwolone są tylko pliki JPG, JPEG i PNG.";
            $uploadOk = 0;
        }

        if ($uploadOk == 1) {
            if (move_uploaded_file($_FILES["profile_picture"]["tmp_name"], $target_file)) {
                return basename($_FILES["profile_picture"]["name"]);
            }

            $errors[] = "Wystąpił błąd podczas przesyłania zdjęcia profilowego.";
            if ($includeDebug) {
                $errors[] = "Błąd PHP: " . print_r(error_get_last(), true);
                $errors[] = "Ścieżka docelowa: " . realpath($target_dir);
                $errors[] = "Czy katalog istnieje? " . (is_dir($target_dir) ? "Tak" : "Nie");
                $errors[] = "Czy plik jest zapisywalny? " . (is_writable($target_dir) ? "Tak" : "Nie");
            }
        }
    }

    return $default_picture;
}
