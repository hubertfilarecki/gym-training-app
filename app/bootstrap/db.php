<?php declare(strict_types=1);

function load_env_file(string $path): void {
    if (!is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));

        if ($key === '' || getenv($key) !== false) {
            continue;
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function db_connect(string $errorPrefix = 'Błąd połączenia z bazą danych:'): mysqli {
    load_env_file(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env');

    $dbhost = getenv('DB_HOST') ?: '';
    $dbuser = getenv('DB_USER') ?: '';
    $dbpassword = getenv('DB_PASSWORD') ?: '';
    $dbname = getenv('DB_NAME') ?: '';
    $dbport = (int) (getenv('DB_PORT') ?: 3306);

    if ($dbhost === '' || $dbuser === '' || $dbname === '') {
        die($errorPrefix . ' Brak wymaganych zmiennych srodowiskowych DB_*');
    }

    $connection = mysqli_connect($dbhost, $dbuser, $dbpassword, $dbname, $dbport);
    if (!$connection) {
        die($errorPrefix . " " . mysqli_connect_errno() . " " . mysqli_connect_error());
    }

    mysqli_query($connection, "SET NAMES 'utf8'");
    mysqli_set_charset($connection, "utf8mb4");

    return $connection;
}
