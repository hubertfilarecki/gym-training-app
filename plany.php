<?php declare(strict_types=1); ?>
<?php
// ==================== Bootstrap / Init ====================
require_once __DIR__ . '/app/bootstrap/session.php';
require_once __DIR__ . '/app/bootstrap/db.php';

start_session();
require_login();

date_default_timezone_set('Europe/Warsaw');

$connection = db_connect("MySQL Connection error:");

// ==================== Local Helpers ====================
function json_header(): void {
    header('Content-Type: application/json');
}

function deny_demo_write(): void {
    json_header();
    echo json_encode(['success' => false, 'error' => 'Konto demo ma tylko podglad']);
    exit();
}

function get_user_id(): int {
    return (int) $_SESSION['user_id'];
}

function get_int(array $source, string $key): int {
    return isset($source[$key]) ? (int) $source[$key] : 0;
}

function fetch_plan_if_owner(mysqli $connection, int $plan_id, int $user_id) {
    return mysqli_query($connection, "SELECT * FROM workout_plans WHERE id = $plan_id AND user_id = $user_id");
}

function fetch_plan_exercise_if_owner(mysqli $connection, int $plan_exercise_id, int $user_id) {
    return mysqli_query($connection, "
        SELECT pe.* FROM plan_exercises pe 
        JOIN workout_plans wp ON pe.plan_id = wp.id 
        WHERE pe.id = $plan_exercise_id AND wp.user_id = $user_id
    ");
}

// ==================== AJAX Endpoints ====================

// --- GET: Pobieranie planów treningowych
if (isset($_GET['get_plans'])) {
    json_header();
    $user_id = get_user_id();
    
    $query = "SELECT wp.*, COUNT(DISTINCT pe.id) as exercise_count, COUNT(DISTINCT wd.id) as days_count
              FROM workout_plans wp 
              LEFT JOIN workout_days wd ON wp.id = wd.plan_id
              LEFT JOIN plan_exercises pe ON wd.id = pe.workout_day_id 
              WHERE wp.user_id = $user_id 
              GROUP BY wp.id 
              ORDER BY wp.created_at DESC";
    $result = mysqli_query($connection, $query);
    
    $plans = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $plans[] = $row;
    }
    
    echo json_encode($plans);
    exit();
}

// --- GET: Pobieranie ćwiczeń grupowanych
if (isset($_GET['get_exercises'])) {
    json_header();
    $user_id = get_user_id();
    
    $query = "SELECT e.*, mg.name as muscle_group_name 
              FROM exercises e 
              JOIN muscle_groups mg ON e.muscle_group_id = mg.id 
              WHERE e.user_id IS NULL OR e.user_id = $user_id 
              ORDER BY mg.name, e.name";
    $result = mysqli_query($connection, $query);
    
    $exercises = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $exercises[] = $row;
    }
    
    echo json_encode($exercises);
    exit();
}

// --- POST: Tworzenie nowego planu treningowego
if (isset($_POST['create_plan'])) {
    if (is_demo_user()) {
        deny_demo_write();
    }
    json_header();
    
    $user_id = get_user_id();
    $name = mysqli_real_escape_string($connection, $_POST['name']);
    $training_days = intval($_POST['training_days']);
    $plan_data = json_decode($_POST['plan_data'], true);
    
    if (empty($plan_data)) {
        echo json_encode(['success' => false, 'error' => 'Musisz dodać przynajmniej jedno ćwiczenie do jednego dnia']);
        exit();
    }
    
    mysqli_begin_transaction($connection);
    
    try {
        // Tworzenie planu
        $query = "INSERT INTO workout_plans (user_id, name, training_days_per_week) 
                  VALUES ($user_id, '$name', $training_days)";
        if (!mysqli_query($connection, $query)) {
            throw new Exception("Błąd tworzenia planu: " . mysqli_error($connection));
        }
        $plan_id = mysqli_insert_id($connection);
        
        // Tworzenie dni treningowych i dodawanie ćwiczeń
        foreach ($plan_data as $day_number => $day_data) {
            $day_name = mysqli_real_escape_string($connection, $day_data['name']);
            
            $query = "INSERT INTO workout_days (plan_id, day_number, name) 
                      VALUES ($plan_id, $day_number, '$day_name')";
            if (!mysqli_query($connection, $query)) {
                throw new Exception("Błąd tworzenia dnia treningowego: " . mysqli_error($connection));
            }
            $day_id = mysqli_insert_id($connection);
            
            // Dodawanie ćwiczeń do tego dnia
            if (!empty($day_data['exercises'])) {
                $order_index = 0;
                foreach ($day_data['exercises'] as $exercise_data) {
                    $exercise_id = intval($exercise_data['exercise_id']);
                    
                    if ($exercise_id <= 0) {
                        throw new Exception("Nieprawidłowe ID ćwiczenia");
                    }
                    
                    $sets_count = intval($exercise_data['sets_count']);
                    
                    $query = "INSERT INTO plan_exercises (plan_id, workout_day_id, exercise_id, sets_count, order_index) 
                              VALUES ($plan_id, $day_id, $exercise_id, $sets_count, $order_index)";
                    if (!mysqli_query($connection, $query)) {
                        throw new Exception("Błąd dodawania ćwiczenia: " . mysqli_error($connection));
                    }
                    $order_index++;
                }
            }
        }
        
        mysqli_commit($connection);
        echo json_encode(['success' => true, 'plan_id' => $plan_id]);
        
    } catch (Exception $e) {
        mysqli_rollback($connection);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    
    exit();
}

// --- GET: Pobieranie szczegółów planu
if (isset($_GET['get_plan_details']) && isset($_GET['plan_id'])) {
    json_header();
    
    $plan_id = get_int($_GET, 'plan_id');
    $user_id = get_user_id();
    
    // Sprawdzenie czy plan należy do użytkownika
    $check = fetch_plan_if_owner($connection, $plan_id, $user_id);
    if (mysqli_num_rows($check) == 0) {
        echo json_encode(['error' => 'Brak dostępu']);
        exit();
    }
    
    $plan_info = mysqli_fetch_assoc($check);
    
    // Pobieranie dni treningowych z ćwiczeniami
    $query = "SELECT wd.*, pe.id as plan_exercise_id, pe.sets_count, pe.order_index,
                 e.id as exercise_id, e.name as exercise_name, e.is_weight_based, mg.name as muscle_group_name,
                 wa_last.workout_date as last_workout_date,
                 wa_last.reps_completed as last_reps,
                 wa_last.weight_used as last_weight
          FROM workout_days wd 
          LEFT JOIN plan_exercises pe ON wd.id = pe.workout_day_id
          LEFT JOIN exercises e ON pe.exercise_id = e.id 
          LEFT JOIN muscle_groups mg ON e.muscle_group_id = mg.id 
          LEFT JOIN (
              SELECT wa1.plan_exercise_id, wa1.workout_date, wa1.reps_completed, wa1.weight_used
              FROM workout_activities wa1
              WHERE wa1.user_id = $user_id
              AND wa1.workout_date = (
                  SELECT MAX(wa2.workout_date) 
                  FROM workout_activities wa2 
                  WHERE wa2.plan_exercise_id = wa1.plan_exercise_id 
                  AND wa2.user_id = $user_id
              )
              GROUP BY wa1.plan_exercise_id
          ) wa_last ON pe.id = wa_last.plan_exercise_id
          WHERE wd.plan_id = $plan_id 
          ORDER BY wd.day_number, pe.order_index";
    
    $result = mysqli_query($connection, $query);
    
    $workout_days = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $day_number = $row['day_number'];
        
        if (!isset($workout_days[$day_number])) {
            $workout_days[$day_number] = [
                'id' => $row['id'],
                'day_number' => $row['day_number'],
                'name' => $row['name'],
                'exercises' => []
            ];
        }
        
        if ($row['plan_exercise_id']) {
            $workout_days[$day_number]['exercises'][] = [
                'plan_exercise_id' => $row['plan_exercise_id'],
                'exercise_id' => $row['exercise_id'],
                'exercise_name' => $row['exercise_name'],
                'muscle_group_name' => $row['muscle_group_name'],
                'sets_count' => $row['sets_count'],
                'is_weight_based' => $row['is_weight_based'],
                'order_index' => $row['order_index'],
                'last_workout_date' => $row['last_workout_date'],
                'last_reps' => $row['last_reps'],
                'last_weight' => $row['last_weight']
            ];
        }
    }
    
    echo json_encode(['plan_info' => $plan_info, 'workout_days' => array_values($workout_days)]);
    exit();
}

// --- POST: Zapisywanie aktywności treningowej
if (isset($_POST['save_activity'])) {
    if (is_demo_user()) {
        deny_demo_write();
    }
    json_header();
    
    $user_id = get_user_id();
    $plan_exercise_id = get_int($_POST, 'plan_exercise_id');
    $workout_date = mysqli_real_escape_string($connection, $_POST['workout_date']);
    $sets_data = json_decode($_POST['sets_data'], true);
    $notes = mysqli_real_escape_string($connection, $_POST['notes']);
    
    // Sprawdzenie czy plan_exercise należy do użytkownika
    $check = fetch_plan_exercise_if_owner($connection, $plan_exercise_id, $user_id);
    
    if (mysqli_num_rows($check) == 0) {
        echo json_encode(['success' => false, 'error' => 'Brak dostępu']);
        exit();
    }
    
    mysqli_begin_transaction($connection);
    
    try {
        // Usunięcie istniejących wpisów dla danej daty i danego ćwiczenia
        $delete_query = "DELETE FROM workout_activities 
                        WHERE plan_exercise_id = $plan_exercise_id 
                        AND user_id = $user_id 
                        AND workout_date = '$workout_date'";
        mysqli_query($connection, $delete_query);
        
        // Dodawanie nowych wpisów
        foreach ($sets_data as $set_number => $set_data) {
            $reps = isset($set_data['reps']) && $set_data['reps'] !== '' ? intval($set_data['reps']) : 'NULL';
            $weight = isset($set_data['weight']) && $set_data['weight'] !== '' ? floatval($set_data['weight']) : 'NULL';
            
            $query = "INSERT INTO workout_activities 
                      (plan_exercise_id, user_id, workout_date, set_number, reps_completed, weight_used, notes) 
                      VALUES ($plan_exercise_id, $user_id, '$workout_date', " . ($set_number + 1) . ", $reps, $weight, '$notes')";
            
            if (!mysqli_query($connection, $query)) {
                throw new Exception("Błąd zapisywania serii: " . mysqli_error($connection));
            }
        }
        
        mysqli_commit($connection);
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        mysqli_rollback($connection);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    
    exit();
}

// --- GET: Pobieranie statystyk ćwiczenia
if (isset($_GET['get_exercise_stats']) && isset($_GET['plan_exercise_id'])) {
    json_header();
    
    $user_id = get_user_id();
    $plan_exercise_id = get_int($_GET, 'plan_exercise_id');
    
    // Sprawdzenie dostępu do ćwiczenia
    $check = fetch_plan_exercise_if_owner($connection, $plan_exercise_id, $user_id);
    
    if (mysqli_num_rows($check) == 0) {
        echo json_encode(['error' => 'Brak dostępu do tego ćwiczenia']);
        exit();
    }
    
    // Pobieranie statystyk
    $query = "SELECT workout_date, set_number, reps_completed, weight_used, notes
              FROM workout_activities 
              WHERE user_id = $user_id AND plan_exercise_id = $plan_exercise_id 
              ORDER BY workout_date DESC, set_number ASC 
              LIMIT 300";
    
    $result = mysqli_query($connection, $query);
    
    if (!$result) {
        echo json_encode(['error' => 'Błąd zapytania: ' . mysqli_error($connection)]);
        exit();
    }
    
    $stats = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $stats[] = $row;
    }
    
    echo json_encode($stats);
    exit();
}

// --- POST: Tworzenie nowego ćwiczenia
if (isset($_POST['create_exercise'])) {
    if (is_demo_user()) {
        deny_demo_write();
    }
    json_header();
    
    $user_id = get_user_id();
    $name = mysqli_real_escape_string($connection, trim($_POST['name']));
    $muscle_group_id = intval($_POST['muscle_group_id']);
    $is_weight_based = intval($_POST['is_weight_based']);
    
    // Sprawdzenie czy nazwa nie jest pusta
    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'Nazwa ćwiczenia nie może być pusta']);
        exit();
    }
    
    // Sprawdzenie czy ćwiczenie już istnieje
    $check_query = "SELECT id FROM exercises 
                    WHERE LOWER(name) = LOWER('$name') 
                    AND (user_id = $user_id OR user_id IS NULL)";
    $check_result = mysqli_query($connection, $check_query);
    
    if (mysqli_num_rows($check_result) > 0) {
        echo json_encode(['success' => false, 'error' => 'Ćwiczenie o tej nazwie już istnieje']);
        exit();
    }
    
    // Dodawanie nowego ćwiczenia
    $query = "INSERT INTO exercises (name, muscle_group_id, is_weight_based, user_id, is_custom) 
              VALUES ('$name', $muscle_group_id, $is_weight_based, $user_id, 1)";
    
    if (mysqli_query($connection, $query)) {
        $exercise_id = mysqli_insert_id($connection);
        
        // Pobieranie danych z nowo utworzonego ćwiczenia
        $get_exercise = mysqli_query($connection, "
            SELECT e.id, e.name, e.is_weight_based, e.is_custom, mg.name as muscle_group_name 
            FROM exercises e 
            JOIN muscle_groups mg ON e.muscle_group_id = mg.id 
            WHERE e.id = $exercise_id
        ");
        $exercise_data = mysqli_fetch_assoc($get_exercise);
        
        echo json_encode(['success' => true, 'exercise' => $exercise_data]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Błąd dodawania ćwiczenia: ' . mysqli_error($connection)]);
    }
    
    exit();
}

// --- GET: Pobieranie grup mięśniowych
if (isset($_GET['get_muscle_groups'])) {
    json_header();
    
    $query = "SELECT * FROM muscle_groups ORDER BY name";
    $result = mysqli_query($connection, $query);
    
    $groups = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $groups[] = $row;
    }
    
    echo json_encode($groups);
    exit();
}

// --- GET: Pobieranie dostępnych serii dla ćwiczenia
if (isset($_GET['get_exercise_sets']) && isset($_GET['plan_exercise_id'])) {
    json_header();
    
    $user_id = get_user_id();
    $plan_exercise_id = get_int($_GET, 'plan_exercise_id');
    
    // Sprawdzenie dostępu
    $check = fetch_plan_exercise_if_owner($connection, $plan_exercise_id, $user_id);
    
    if (mysqli_num_rows($check) == 0) {
        echo json_encode(['error' => 'Brak dostępu']);
        exit();
    }
    
    // Pobieranie dostępnych numerów serii
    $query = "SELECT DISTINCT set_number 
              FROM workout_activities 
              WHERE user_id = $user_id AND plan_exercise_id = $plan_exercise_id 
              AND set_number IS NOT NULL
              ORDER BY set_number ASC";
    
    $result = mysqli_query($connection, $query);
    
    $sets = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $sets[] = intval($row['set_number']);
    }
    
    echo json_encode($sets);
    exit();
}

// --- GET: Pobieranie danych do wykresu z wybranymi seriami
if (isset($_GET['get_chart_data']) && isset($_GET['plan_exercise_id'])) {
    json_header();
    
    $user_id = get_user_id();
    $plan_exercise_id = get_int($_GET, 'plan_exercise_id');
    $selected_sets = isset($_GET['selected_sets']) ? $_GET['selected_sets'] : 'all';
    
    // Sprawdzenie dostępu
    $check = fetch_plan_exercise_if_owner($connection, $plan_exercise_id, $user_id);
    
    if (mysqli_num_rows($check) == 0) {
        echo json_encode(['error' => 'Brak dostępu']);
        exit();
    }
    
    // Budowanie warunku dla serii
    $sets_condition = '';
    if ($selected_sets !== 'all') {
        $sets_array = explode(',', $selected_sets);
        $sets_array = array_map('intval', $sets_array);
        if (!empty($sets_array)) {
            $sets_condition = ' AND set_number IN (' . implode(',', $sets_array) . ')';
        }
    }
    
    // Pobieranie danych
    $query = "SELECT workout_date, set_number, reps_completed, weight_used 
              FROM workout_activities 
              WHERE user_id = $user_id AND plan_exercise_id = $plan_exercise_id 
              AND reps_completed IS NOT NULL 
              $sets_condition
              ORDER BY workout_date ASC, set_number ASC";
    
    $result = mysqli_query($connection, $query);
    
    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
    
    echo json_encode($data);
    exit();
}

// --- GET: Pobieranie podsumowania tygodnia
if (isset($_GET['get_weekly_summary'])) {
    json_header();
    
    $user_id = get_user_id();
    $week_start = isset($_GET['week_start']) ? $_GET['week_start'] : date('Y-m-d', strtotime('monday this week'));
    $week_end = date('Y-m-d', strtotime($week_start . ' +6 days'));
    
    // Pobieranie wszystkich aktywności z tego tygodnia
    $query = "SELECT wa.*, DATE(wa.workout_date) as workout_day,
                     DAYNAME(wa.workout_date) as day_name,
                     e.name as exercise_name, e.is_weight_based,
                     mg.name as muscle_group_name,
                     pe.sets_count as planned_sets,
                     wp.name as plan_name
              FROM workout_activities wa
              JOIN plan_exercises pe ON wa.plan_exercise_id = pe.id
              JOIN exercises e ON pe.exercise_id = e.id
              JOIN muscle_groups mg ON e.muscle_group_id = mg.id
              JOIN workout_plans wp ON pe.plan_id = wp.id
              WHERE wa.user_id = $user_id 
              AND DATE(wa.workout_date) BETWEEN '$week_start' AND '$week_end'
              AND wa.reps_completed IS NOT NULL
              ORDER BY wa.workout_date ASC, pe.order_index ASC, wa.set_number ASC";
    
    $result = mysqli_query($connection, $query);
    
    $weekly_data = [
        'week_start' => $week_start,
        'week_end' => $week_end,
        'muscle_group_stats' => [],
        'daily_workouts' => []
    ];
    
    $muscle_group_totals = [];
    $daily_exercises = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        $muscle_group = $row['muscle_group_name'];
        $workout_day = $row['workout_day'];
        $day_name = $row['day_name'];
        
        // Zliczanie serii według grup mięśniowych
        if (!isset($muscle_group_totals[$muscle_group])) {
            $muscle_group_totals[$muscle_group] = 0;
        }
        $muscle_group_totals[$muscle_group]++;
        
        // Grupowanie ćwiczeń według dni
        if (!isset($daily_exercises[$workout_day])) {
            $daily_exercises[$workout_day] = [
                'date' => $workout_day,
                'day_name' => $day_name,
                'exercises' => []
            ];
        }
        
        // Grupowanie serii według ćwiczeń
        $exercise_key = $row['plan_exercise_id'] . '_' . $row['exercise_name'];
        if (!isset($daily_exercises[$workout_day]['exercises'][$exercise_key])) {
            $daily_exercises[$workout_day]['exercises'][$exercise_key] = [
                'exercise_name' => $row['exercise_name'],
                'muscle_group_name' => $muscle_group,
                'is_weight_based' => $row['is_weight_based'],
                'planned_sets' => $row['planned_sets'],
                'plan_name' => $row['plan_name'],
                'sets' => []
            ];
        }
        
        // Dodawanie serii
        $daily_exercises[$workout_day]['exercises'][$exercise_key]['sets'][] = [
            'set_number' => $row['set_number'],
            'reps_completed' => $row['reps_completed'],
            'weight_used' => $row['weight_used'],
            'notes' => $row['notes']
        ];
    }
    
    // Przygotowanie danych wyjściowych
    $weekly_data['muscle_group_stats'] = $muscle_group_totals;
    
    // Konwertowanie daily_exercises do odpowiedniego formatu
    foreach ($daily_exercises as $day_data) {
        $day_data['exercises'] = array_values($day_data['exercises']);
        $weekly_data['daily_workouts'][] = $day_data;
    }
    
    echo json_encode($weekly_data);
    exit();
}

// --- POST: Aktualizowanie istniejącego planu
if (isset($_POST['update_plan'])) {
    if (is_demo_user()) {
        deny_demo_write();
    }
    json_header();
    
    $user_id = get_user_id();
    $plan_id = get_int($_POST, 'plan_id');
    $name = mysqli_real_escape_string($connection, $_POST['name']);
    $training_days = intval($_POST['training_days']);
    $plan_data = json_decode($_POST['plan_data'], true);
    
    // Sprawdzenie czy plan należy do użytkownika
    $check = fetch_plan_if_owner($connection, $plan_id, $user_id);
    if (mysqli_num_rows($check) == 0) {
        echo json_encode(['success' => false, 'error' => 'Brak dostępu do tego planu']);
        exit();
    }
    
    if (empty($plan_data)) {
        echo json_encode(['success' => false, 'error' => 'Musisz dodać przynajmniej jedno ćwiczenie do jednego dnia']);
        exit();
    }
    
    mysqli_begin_transaction($connection);
    
    try {
        // Aktualizowanie podstawowych informacji planu
        $query = "UPDATE workout_plans 
                  SET name = '$name', training_days_per_week = $training_days 
                  WHERE id = $plan_id AND user_id = $user_id";
        if (!mysqli_query($connection, $query)) {
            throw new Exception("Błąd aktualizacji planu: " . mysqli_error($connection));
        }
        
        // Usuwanie istniejących dni treningowych
        $query = "DELETE FROM workout_days WHERE plan_id = $plan_id";
        if (!mysqli_query($connection, $query)) {
            throw new Exception("Błąd usuwania starych dni: " . mysqli_error($connection));
        }
        
        // Dodawanie zaktualizowanych dni i ćwiczeń
        foreach ($plan_data as $day_number => $day_data) {
            $day_name = mysqli_real_escape_string($connection, $day_data['name']);
            
            $query = "INSERT INTO workout_days (plan_id, day_number, name) 
                      VALUES ($plan_id, $day_number, '$day_name')";
            if (!mysqli_query($connection, $query)) {
                throw new Exception("Błąd tworzenia dnia treningowego: " . mysqli_error($connection));
            }
            $day_id = mysqli_insert_id($connection);
            
            // Dodawanie ćwiczeń do danego dnia z zachowaniem kolejności
            if (!empty($day_data['exercises'])) {
                foreach ($day_data['exercises'] as $order_index => $exercise_data) {
                    $exercise_id = intval($exercise_data['exercise_id']);
                    
                    if ($exercise_id <= 0) {
                        throw new Exception("Nieprawidłowe ID ćwiczenia");
                    }
                    
                    $sets_count = intval($exercise_data['sets_count']);
                    
                    $query = "INSERT INTO plan_exercises (plan_id, workout_day_id, exercise_id, sets_count, order_index) 
                              VALUES ($plan_id, $day_id, $exercise_id, $sets_count, $order_index)";
                    if (!mysqli_query($connection, $query)) {
                        throw new Exception("Błąd dodawania ćwiczenia: " . mysqli_error($connection));
                    }
                }
            }
        }
        
        mysqli_commit($connection);
        echo json_encode(['success' => true, 'plan_id' => $plan_id]);
        
    } catch (Exception $e) {
        mysqli_rollback($connection);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    
    exit();
}

// --- POST: Usuwanie planu
if (isset($_POST['delete_plan'])) {
    if (is_demo_user()) {
        deny_demo_write();
    }
    json_header();
    
    $user_id = get_user_id();
    $plan_id = get_int($_POST, 'plan_id');
    
    // Sprawdzanie czy plan należy do użytkownika
    $check = fetch_plan_if_owner($connection, $plan_id, $user_id);
    if (mysqli_num_rows($check) == 0) {
        echo json_encode(['success' => false, 'error' => 'Brak dostępu do tego planu']);
        exit();
    }
    
    mysqli_begin_transaction($connection);
    
    try {
        // Usuwanie wszystkich aktywności związanych z tym planem
        $query = "DELETE wa FROM workout_activities wa 
                  JOIN plan_exercises pe ON wa.plan_exercise_id = pe.id 
                  WHERE pe.plan_id = $plan_id";
        mysqli_query($connection, $query);
        
        // Usuwanie planu
        $query = "DELETE FROM workout_plans WHERE id = $plan_id AND user_id = $user_id";
        if (!mysqli_query($connection, $query)) {
            throw new Exception("Błąd usuwania planu: " . mysqli_error($connection));
        }
        
        mysqli_commit($connection);
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        mysqli_rollback($connection);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    
    exit();
}

// ==================== HTML / UI ====================
?>

<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="pl" lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="Plany treningowe">
    <meta name="author" content="hubert Filarecki">
    <meta name="keywords" content="trening, siłownia, plany treningowe">
    <title>Plany treningowe</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Dodanie adapteru dat dla Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
    <!-- Mój CSS -->
    <link rel="stylesheet" type="text/css" href="twoj_css.css">

    <style>
        .plan-card {
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            background: #f8f9fa;
            transition: all 0.2s ease;
        }
        
        .plan-card:hover {
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .exercise-group {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 15px;
            background: white;
        }
        
        .exercise-group-header {
            background: #f8f9fa;
            padding: 10px 15px;
            border-bottom: 1px solid #e0e0e0;
            border-radius: 7px 7px 0 0;
            font-weight: bold;
        }
        
        .exercise-item {
            padding: 15px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .exercise-item:last-child {
            border-bottom: none;
        }
        
        .exercise-selector {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 10px;
            margin: 5px 0;
            cursor: pointer;
            transition: all 0.2s ease;
            background: white;
        }
        
        .exercise-selector:hover {
            border-color: #007bff;
            background: #f8f9fa;
        }
        
        .exercise-selector.selected {
            border-color: #007bff;
            background: #e7f3ff;
        }
        
        .day-tab {
            border: 2px solid #ddd;
            border-radius: 10px;
            margin-bottom: 20px;
            background: white;
        }
        
        .day-tab-header {
            background: #f8f9fa;
            padding: 15px;
            border-bottom: 2px solid #ddd;
            border-radius: 8px 8px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .day-tab.active {
            border-color: #007bff;
        }
        
        .day-tab.active .day-tab-header {
            background: #007bff;
            color: white;
            border-bottom-color: #007bff;
        }
        
        .day-exercises {
            padding: 15px;
            min-height: 100px;
        }
        
        .sets-input {
            width: 70px;
            margin-left: 10px;
        }
        
        .exercise-in-day {
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 10px;
            margin: 5px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .day-name-input {
            background: transparent;
            border: none;
            color: inherit;
            font-weight: bold;
            width: 200px;
        }
        
        .day-name-input:focus {
            outline: 1px solid rgba(255,255,255,0.5);
            background: rgba(255,255,255,0.1);
        }
        
        .exercise-bank {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 10px;
            background: #f8f9fa;
        }
        
        .workout-day-card {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 15px;
            background: white;
        }
        
        .workout-day-header {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            padding: 15px;
            border-radius: 7px 7px 0 0;
            font-weight: bold;
        }

        .search-container {
            position: sticky;
            top: 0;
            background: #f8f9fa;
            padding: 10px;
            border-bottom: 1px solid #ddd;
            z-index: 10;
        }

        .exercise-group-search {
            position: relative;
            margin-bottom: 10px;
        }

        .exercise-group-toggle {
            cursor: pointer;
            padding: 8px 12px;
            background: #e9ecef;
            border: 1px solid #ddd;
            border-radius: 5px;
            transition: all 0.2s ease;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .exercise-group-toggle:hover {
            background: #dee2e6;
        }

        .exercise-group-toggle.collapsed {
            border-bottom-left-radius: 5px;
            border-bottom-right-radius: 5px;
        }

        .exercise-group-content {
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 5px 5px;
            background: white;
            max-height: 200px;
            overflow-y: auto;
        }

        .exercise-group-content.collapsed {
            display: none;
        }

        .search-input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .search-input:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 5px rgba(0,123,255,0.3);
        }

        .exercise-selector {
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 8px;
            margin: 3px;
            cursor: pointer;
            transition: all 0.2s ease;
            background: white;
        }

        .exercise-selector:hover {
            border-color: #007bff;
            background: #f8f9fa;
        }

        .exercise-selector.hidden {
            display: none;
        }

        .no-results {
            text-align: center;
            color: #666;
            font-style: italic;
            padding: 15px;
        }

        .clear-search {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            font-size: 16px;
        }

        .clear-search:hover {
            color: #333;
        }

        .custom-exercise-badge {
            background: linear-gradient(45deg, #ff6b6b, #ff8e53) !important;
            color: white !important;
            font-size: 10px;
            padding: 2px 6px;
        }

        .add-exercise-btn {
            border: 2px dashed #007bff;
            background: rgba(0,123,255,0.1);
            color: #007bff;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            margin: 10px 0;
        }

        .add-exercise-btn:hover {
            background: rgba(0,123,255,0.2);
            border-color: #0056b3;
        }

        .series-selector {
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }

        .series-checkboxes {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 10px;
        }

        .series-checkbox {
            background: white;
            border: 2px solid #ddd;
            border-radius: 6px;
            padding: 6px 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            user-select: none;
            font-size: 14px;
        }

        .series-checkbox:hover {
            border-color: #007bff;
            background: #f8f9fa;
        }

        .series-checkbox.selected {
            border-color: #007bff;
            background: #007bff;
            color: white;
        }

        .series-controls {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .weekly-summary-card {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .muscle-group-stats {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            padding: 15px;
            border-radius: 9px 9px 0 0;
        }

        .muscle-group-stat {
            background: rgba(255,255,255,0.1);
            border-radius: 6px;
            padding: 8px 12px;
            margin: 4px;
            display: inline-block;
            font-size: 13px;
        }

        .muscle-group-stat .count {
            font-weight: bold;
            font-size: 16px;
        }

        .daily-workout {
            border-bottom: 1px solid #f0f0f0;
            padding: 15px;
        }

        .daily-workout:last-child {
            border-bottom: none;
        }

        .daily-workout-header {
            background: #f8f9fa;
            padding: 8px 12px;
            border-radius: 6px;
            margin-bottom: 10px;
            font-weight: bold;
            color: #495057;
        }

        .exercise-summary {
            background: #f8f9fa;
            border-radius: 6px;
            padding: 10px;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .exercise-summary-header {
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }

        .sets-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 5px;
        }

        .set-badge {
            background: #007bff;
            color: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
        }

        .set-badge.bodyweight {
            background: #28a745;
        }

        .no-workouts {
            text-align: center;
            color: #666;
            padding: 40px 20px;
        }

        .week-navigation {
            margin-bottom: 15px;
        }

        .sticky-summary {
            position: sticky;
            top: 20px;
        }

        @media (max-width: 991.98px) {
            .sticky-summary {
                position: relative;
                top: 0;
            }
        }
        .exercise-in-day-edit {
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin: 10px 0;
            transition: all 0.2s ease;
        }

        .exercise-in-day-edit:hover {
            border-color: #007bff;
            box-shadow: 0 2px 8px rgba(0,123,255,0.15);
        }

        .exercise-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
        }

        .exercise-main {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex: 1;
            gap: 15px;
        }

        .exercise-info {
            flex: 1;
        }

        .exercise-controls {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .sets-control {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .sets-control .form-label {
            margin: 0;
            font-weight: bold;
            color: #495057;
        }

        .sets-input-edit {
            width: 70px;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
        }

        .order-controls {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
            min-width: 50px;
        }

        .order-number {
            background: #007bff;
            color: white;
            border-radius: 50%;
            width: 25px;
            height: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: bold;
        }

        .order-controls .btn {
            padding: 4px 8px;
            font-size: 12px;
        }

        .sortable-exercises {
            min-height: 80px;
            border: 2px dashed transparent;
            border-radius: 8px;
            transition: border-color 0.2s ease;
            padding: 10px;
        }

        .sortable-exercises:hover,
        .sortable-exercises.drag-over {
            border-color: #007bff;
            background: rgba(0,123,255,0.05);
        }

        .exercise-selector {
            cursor: grab;
            user-select: none;
            transition: all 0.2s ease;
        }

        .exercise-selector:active {
            cursor: grabbing;
        }

        .exercise-selector.dragging {
            opacity: 0.5;
            transform: scale(0.95);
        }

        .day-exercises {
            min-height: 100px;
            border: 2px dashed #ddd;
            border-radius: 8px;
            padding: 15px;
            transition: all 0.3s ease;
        }

        .day-exercises:hover {
            border-color: #adb5bd;
            background: #f8f9fa;
        }

        .day-exercises.drag-over {
            background: #e7f3ff !important;
            border-color: #007bff !important;
            border-style: solid !important;
            box-shadow: 0 0 10px rgba(0,123,255,0.3);
        }
    </style>
</head>
<body onload="myLoadHeader()">
    <!-- Nagłówek -->
    <div id='myHeader' class="text-center"></div>

    <div class="container-fluid">
        <div class="row">
            <!-- Plany treningowe -->
            <div class="col-lg-8">
                <h2><i class="fas fa-dumbbell"></i> Plany treningowe</h2>
                
                <!-- Przyciski akcji -->
                <div class="mb-4">
                    <button class="btn btn-primary" onclick="showCreatePlanModal()">
                        <i class="fas fa-plus"></i> Utwórz nowy plan
                    </button>
                </div>
                
                <!-- Lista planów treningowych -->
                <div id="plansContainer">
                    <div class="text-center">
                        <i class="fas fa-sync-alt fa-spin"></i> Ładowanie planów...
                    </div>
                </div>
            </div>
            
            <!-- Podsumowanie danego tygodnia -->
            <div class="col-lg-4">
                <div class="sticky-top" style="top: 20px;">
                    <h4><i class="fas fa-calendar-week"></i> Podsumowanie tygodnia</h4>
                    
                    <!-- Nawigacja tygodni -->
                    <div class="week-navigation mb-3">
                        <div class="btn-group w-100" role="group">
                            <button type="button" class="btn btn-outline-secondary" onclick="changeWeek(-1)">
                                <i class="fas fa-chevron-left"></i> Poprzedni
                            </button>
                            <button type="button" class="btn btn-outline-primary" id="currentWeekBtn" onclick="goToCurrentWeek()">
                                Bieżący tydzień
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="changeWeek(1)">
                                Następny <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                        <div class="text-center mt-2">
                            <small class="text-muted" id="weekRangeDisplay">
                                <!-- Zakres tygodnia -->
                            </small>
                        </div>
                    </div>
                    
                    <!-- Podsumowanie danego tygodnia -->
                    <div id="weeklySummaryContainer">
                        <div class="text-center">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tworzenie nowego planu -->
    <div class="modal fade" id="createPlanModal" tabindex="-1">
        <div class="modal-dialog modal-fullscreen">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Utwórz nowy plan treningowy</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="planName" class="form-label">Nazwa planu:</label>
                                <input type="text" class="form-control" id="planName" placeholder="Mój plan treningowy" required>
                            </div>
                            <div class="mb-3">
                                <label for="trainingDays" class="form-label">Dni treningowe w tygodniu:</label>
                                <select class="form-control" id="trainingDays" onchange="generateWorkoutDays()">
                                    <option value="2">2 dni</option>
                                    <option value="3" selected>3 dni</option>
                                    <option value="4">4 dni</option>
                                    <option value="5">5 dni</option>
                                    <option value="6">6 dni</option>
                                    <option value="7">7 dni</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> <strong>Jak to działa:</strong>
                                <ul class="mb-0 mt-2">
                                    <li>Wybierz liczbę dni treningowych</li>
                                    <li>Nazwij każdy dzień (np. "Push", "Pull", "Nogi")</li>
                                    <li>Przeciągnij ćwiczenia do odpowiednich dni</li>
                                    <li>Ustaw liczbę serii dla każdego ćwiczenia</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="row">
                        <!-- Dni treningowe -->
                        <div class="col-md-8">
                            <h6><i class="fas fa-calendar-alt"></i> Dni treningowe:</h6>
                            <div id="workoutDaysContainer">
                                <!-- Tu są wyświetlane dni -->
                            </div>
                        </div>
                        
                        <!-- Bank ćwiczeń -->
                        <div class="col-md-4">
                            <h6><i class="fas fa-list"></i> Bank ćwiczeń:</h6>
                            <div class="exercise-bank" id="exerciseBank">
                                <div class="text-center">
                                    <i class="fas fa-spinner fa-spin"></i> Ładowanie ćwiczeń...
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                    <button type="button" class="btn btn-primary" onclick="createPlan()">
                        <i class="fas fa-save"></i> Utwórz plan
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Szczegóły planu -->
    <div class="modal fade" id="planDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="planDetailsTitle"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="planDetailsContainer">
                        <!-- Tu są wyświetlane szczegóły planu -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Dodawanie aktywności -->
    <div class="modal fade" id="addActivityModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus"></i> Dodaj aktywność treningową
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="activityPlanExerciseId">
                    
                    <div class="mb-3">
                        <label class="form-label">Ćwiczenie:</label>
                        <div id="activityExerciseName" class="form-control-plaintext fw-bold text-primary"></div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="activityDate" class="form-label">Data treningu:</label>
                            <input type="date" class="form-control" id="activityDate" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Zaplanowane serie:</label>
                            <div id="activityPlannedSets" class="form-control-plaintext fw-bold text-success"></div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <h6><i class="fas fa-list-ol"></i> Wykonane serie:</h6>
                    <div id="setsContainer">
                        <!-- Tu są wyświetlane serie -->
                    </div>
                    
                    <hr>
                    
                    <div class="mb-3">
                        <label for="activityNotes" class="form-label">
                            <i class="fas fa-sticky-note"></i> Notatki:
                        </label>
                        <textarea class="form-control" id="activityNotes" rows="3" 
                                  placeholder="Dodatkowe informacje o treningu, samopoczucie, tempo, itp..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i> Anuluj
                    </button>
                    <button type="button" class="btn btn-success" onclick="saveActivity()">
                        <i class="fas fa-save"></i> Zapisz aktywność
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Statystyki -->
    <div class="modal fade" id="statsModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-chart-line"></i> Statystyki postępów</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Ćwiczenie:</label>
                            <div id="statsExerciseName" class="form-control-plaintext fw-bold"></div>
                        </div>
                        <div class="col-md-6">
                            <div id="seriesSelector" style="display: none;">
                                <label class="form-label">Wybierz serie do analizy:</label>
                                <div class="series-checkboxes" id="seriesCheckboxes">
                                    <!-- Tu są wyświetlane serie -->
                                </div>
                                <div class="series-controls mt-2">
                                    <button class="btn btn-sm btn-outline-primary" onclick="selectAllSeries()">
                                        <i class="fas fa-check-double"></i> Wszystkie
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary" onclick="clearSeriesSelection()">
                                        <i class="fas fa-times"></i> Wyczyść
                                    </button>
                                    <span class="ms-3 text-muted">
                                        Wybrane: <span id="selectedSeriesCount">0</span> serii
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stats-container" style="height: 500px;">
                        <canvas id="progressChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Dodawanie własnego ćwiczenia -->
    <div class="modal fade" id="createExerciseModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Dodaj własne ćwiczenie</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="exerciseName" class="form-label">Nazwa ćwiczenia:</label>
                        <input type="text" class="form-control" id="exerciseName" 
                               placeholder="Wpisz nazwę ćwiczenia..." required>
                        <div class="form-text">Podaj dokładną nazwę ćwiczenia (np. "Wyciskanie hantli")</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="exerciseMuscleGroup" class="form-label">Grupa mięśniowa:</label>
                        <select class="form-control" id="exerciseMuscleGroup" required>
                            <option value="">Wybierz grupę mięśniową...</option>
                            <!-- Tu są wyświetlane grupy mięśniowe -->
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Typ ćwiczenia:</label>
                        <div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="exerciseType" 
                                       id="exerciseWithWeight" value="1" checked>
                                <label class="form-check-label" for="exerciseWithWeight">
                                    <i class="fas fa-weight-hanging text-primary"></i> Z ciężarem
                                    <small class="text-muted d-block">Ćwiczenie z dodatkowym obciążeniem (kg)</small>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="exerciseType" 
                                       id="exerciseBodyweight" value="0">
                                <label class="form-check-label" for="exerciseBodyweight">
                                    <i class="fas fa-running text-success"></i> Bez ciężaru
                                    <small class="text-muted d-block">Ćwiczenie z wagą własnego ciała</small>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>Informacja:</strong> Dodane ćwiczenia będą dostępne tylko dla Ciebie 
                        i oznaczone jako "Własne" w banku ćwiczeń.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                    <button type="button" class="btn btn-success" onclick="createCustomExercise()">
                        <i class="fas fa-save"></i> Dodaj ćwiczenie
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.5.1.js"></script>
    <!-- Twój JS -->
    <script src="twoj_js.js"></script>

    <script>
        let allExercises = [];
        let workoutDays = {};
        let progressChart = null;
        let muscleGroups = [];

        let availableSeries = [];
        let selectedSeries = [];
        let currentExerciseId = null;

        let currentWeekStart = null;

        // Funkcja ładująca dane przy starcie
        document.addEventListener('DOMContentLoaded', function() {
            loadPlans();
            loadExercises();
            initWeeklySummary();
        });

        // Funkcja ładująca plany treningowe
        function loadPlans() {
            fetch('plany.php?get_plans=1')
                .then(response => response.json())
                .then(plans => {
                    const container = document.getElementById('plansContainer');
                    container.innerHTML = '';
                    
                    if (plans.length === 0) {
                        container.innerHTML = '<div class="text-center text-muted"><i class="fas fa-info-circle"></i> Brak planów treningowych. Utwórz swój pierwszy plan!</div>';
                        return;
                    }
                    
                    plans.forEach(plan => {
                        const planCard = document.createElement('div');
                        planCard.className = 'plan-card';
                        
                        planCard.innerHTML = `
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h5><i class="fas fa-dumbbell"></i> ${plan.name}</h5>
                                    <div class="mb-2">
                                        <span class="badge bg-info">${plan.training_days_per_week} dni/tydzień</span>
                                        <span class="badge bg-secondary">${plan.exercise_count} ćwiczeń</span>
                                        <span class="badge bg-success">${plan.days_count} dni</span>
                                    </div>
                                    <small class="text-muted">Utworzono: ${plan.created_at}</small>
                                </div>
                                <div>
                                    <button class="btn btn-primary btn-sm me-2" onclick="viewPlanDetails(${plan.id}, '${plan.name}')">
                                        <i class="fas fa-eye"></i> Zobacz plan
                                    </button>
                                    <button class="btn btn-warning btn-sm me-2" onclick="editPlan(${plan.id}, '${plan.name}')">
                                        <i class="fas fa-edit"></i> Edytuj plan
                                    </button>
                                    <button class="btn btn-danger btn-sm" onclick="deletePlan(${plan.id}, '${plan.name}')">
                                        <i class="fas fa-trash"></i> Usuń
                                    </button>
                                </div>
                            </div>
                        `;
                        container.appendChild(planCard);
                    });
                })
                .catch(error => console.error('Błąd ładowania planów:', error));
        }

        // Funkcja ładująca wszystkie ćwiczenia
        function loadExercises() {
            fetch('plany.php?get_exercises=1')
                .then(response => response.json())
                .then(exercises => {
                    allExercises = exercises;
                    displayExerciseBank();
                })
                .catch(error => console.error('Błąd ładowania ćwiczeń:', error));
        }

        // Funkcja wyświetlająca bank ćwiczeń z wyszukiwarką
        function displayExerciseBank() {
            console.log('Wyświetlanie banku ćwiczeń...');
            
            const container = document.getElementById('exerciseBank');
            container.innerHTML = '';
            
            // Dodawanie wyszukiwarki
            const searchContainer = document.createElement('div');
            searchContainer.className = 'search-container';
            searchContainer.innerHTML = `
                <div class="position-relative">
                    <input type="text" class="search-input" id="globalExerciseSearch" 
                           placeholder="Szukaj ćwiczeń..." 
                           onkeyup="debouncedFilterExercises(this.value)">
                    <button type="button" class="clear-search" onclick="clearGlobalSearch()" 
                            id="clearGlobalSearch" style="display: none;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <small class="text-muted">
                    <i class="fas fa-info-circle"></i> 
                    Wpisz nazwę ćwiczenia lub partii mięśniowej
                </small>
            `;
            container.appendChild(searchContainer);
            
            // Grupowanie ćwiczeń według partii mięśniowych
            const exercisesByGroup = {};
            allExercises.forEach(exercise => {
                if (!exercisesByGroup[exercise.muscle_group_name]) {
                    exercisesByGroup[exercise.muscle_group_name] = [];
                }
                exercisesByGroup[exercise.muscle_group_name].push(exercise);
            });
            
            console.log('Grupy ćwiczeń:', Object.keys(exercisesByGroup));
            
            // Wyświetlanie grup z możliwością zwijania
            Object.keys(exercisesByGroup).forEach(groupName => {
                const groupDiv = document.createElement('div');
                groupDiv.className = 'exercise-group-search';
                groupDiv.dataset.groupName = groupName.toLowerCase();
                
                // Nagłówek grupy z przyciskiem zwijania
                const groupToggle = document.createElement('div');
                groupToggle.className = 'exercise-group-toggle';
                groupToggle.innerHTML = `
                    <span>
                        <i class="fas fa-dumbbell"></i> 
                        <strong>${groupName}</strong> 
                        <span class="badge bg-secondary ms-2">${exercisesByGroup[groupName].length}</span>
                    </span>
                    <i class="fas fa-chevron-down toggle-icon"></i>
                `;
                
                // Zawartość grupy
                const groupContent = document.createElement('div');
                groupContent.className = 'exercise-group-content';
                
                // Dodawanie wyszukiwarki dla tej grupy
                const groupSearchDiv = document.createElement('div');
                groupSearchDiv.className = 'p-2 border-bottom';
                groupSearchDiv.innerHTML = `
                    <div class="position-relative">
                        <input type="text" class="search-input" 
                               placeholder="Szukaj w ${groupName}..." 
                               onkeyup="debouncedFilterGroupExercises(this.value, '${groupName}')">
                        <button type="button" class="clear-search" 
                                onclick="clearGroupSearch('${groupName}')" 
                                style="display: none;">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                `;
                groupContent.appendChild(groupSearchDiv);
                
                // Dodawanie ćwiczeń
                const exercisesContainer = document.createElement('div');
                exercisesContainer.className = 'exercises-container';
                exercisesContainer.dataset.group = groupName;
                
                exercisesByGroup[groupName].forEach(exercise => {
                    const exerciseDiv = document.createElement('div');
                    exerciseDiv.className = 'exercise-selector';
                    exerciseDiv.dataset.exerciseId = exercise.id;
                    exerciseDiv.dataset.exerciseName = exercise.name.toLowerCase();
                    exerciseDiv.dataset.groupName = groupName.toLowerCase();
                    exerciseDiv.draggable = true;
                    
                    exerciseDiv.innerHTML = `
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong>${exercise.name}</strong>
                                <span class="badge ${exercise.is_weight_based == 1 ? 'bg-primary' : 'bg-success'} ms-1">
                                    ${exercise.is_weight_based == 1 ? 'Ciężar' : 'Powtórzenia'}
                                </span>
                                ${exercise.is_custom == 1 ? '<span class="badge custom-exercise-badge ms-1">Własne</span>' : ''}
                            </div>
                            <i class="fas fa-grip-vertical text-muted"></i>
                        </div>
                    `;
                    
                    // Obsługa drag & drop
                    exerciseDiv.addEventListener('dragstart', function(e) {
                        console.log('Dragstart - Exercise ID:', exercise.id, 'Name:', exercise.name);
                        e.dataTransfer.setData('text/plain', exercise.id);
                        e.dataTransfer.effectAllowed = 'copy';
                        this.style.opacity = '0.5';
                        this.classList.add('dragging');
                    });
                    
                    exerciseDiv.addEventListener('dragend', function(e) {
                        console.log('Dragend');
                        this.style.opacity = '1';
                        this.classList.remove('dragging');
                    });
                    
                    exercisesContainer.appendChild(exerciseDiv);
                });
                
                groupContent.appendChild(exercisesContainer);
                
                // Obsługa zwijania i rozwijania grupy
                groupToggle.addEventListener('click', function() {
                    const isCollapsed = groupContent.classList.contains('collapsed');
                    const icon = this.querySelector('.toggle-icon');
                    
                    if (isCollapsed) {
                        groupContent.classList.remove('collapsed');
                        icon.className = 'fas fa-chevron-down toggle-icon';
                        this.classList.remove('collapsed');
                    } else {
                        groupContent.classList.add('collapsed');
                        icon.className = 'fas fa-chevron-right toggle-icon';
                        this.classList.add('collapsed');
                    }
                });
                
                groupDiv.appendChild(groupToggle);
                groupDiv.appendChild(groupContent);
                container.appendChild(groupDiv);
            });
            
            // Dodawanie informacji o liczbie ćwiczeń
            const totalInfo = document.createElement('div');
            totalInfo.className = 'text-center text-muted mt-2';
            totalInfo.innerHTML = `
                <small>
                    <i class="fas fa-info-circle"></i> 
                    Łącznie <strong>${allExercises.length}</strong> ćwiczeń w <strong>${Object.keys(exercisesByGroup).length}</strong> grupach
                </small>
            `;
            container.appendChild(totalInfo);

            // Przycisk do dodawania własnego ćwiczenia
            const addExerciseBtn = document.createElement('div');
            addExerciseBtn.className = 'add-exercise-btn';
            addExerciseBtn.innerHTML = `
                <i class="fas fa-plus fa-2x mb-2"></i>
                <div><strong>Dodaj własne ćwiczenie</strong></div>
                <small>Nie ma Twojego ćwiczenia na liście?</small>
            `;
            addExerciseBtn.addEventListener('click', showCreateExerciseModal);
            container.appendChild(addExerciseBtn);
        }

        // Funkcja wyszukiwania ćwiczeń
        function filterExercises(searchTerm) {
            const clearBtn = document.getElementById('clearGlobalSearch');
            
            if (searchTerm.length > 0) {
                clearBtn.style.display = 'block';
            } else {
                clearBtn.style.display = 'none';
            }
            
            searchTerm = searchTerm.toLowerCase().trim();
            
            // Pokazywanie wszystkich jeśli brak wyszukań
            if (searchTerm === '') {
                document.querySelectorAll('.exercise-selector').forEach(exercise => {
                    exercise.classList.remove('hidden');
                });
                document.querySelectorAll('.exercise-group-search').forEach(group => {
                    group.style.display = 'block';
                });
                updateGroupVisibility();
                return;
            }
            
            let hasVisibleExercises = false;
            
            // Przeszukiwanie wszystkich ćwiczeń
            document.querySelectorAll('.exercise-selector').forEach(exercise => {
                const exerciseName = exercise.dataset.exerciseName;
                const groupName = exercise.dataset.groupName;
                
                if (exerciseName.includes(searchTerm) || groupName.includes(searchTerm)) {
                    exercise.classList.remove('hidden');
                    hasVisibleExercises = true;
                } else {
                    exercise.classList.add('hidden');
                }
            });
            
            // Ukrywanie grup bez widocznych ćwiczeń
            document.querySelectorAll('.exercise-group-search').forEach(group => {
                const visibleExercises = group.querySelectorAll('.exercise-selector:not(.hidden)');
                if (visibleExercises.length > 0) {
                    group.style.display = 'block';

                    const content = group.querySelector('.exercise-group-content');
                    const toggle = group.querySelector('.exercise-group-toggle');
                    const icon = group.querySelector('.toggle-icon');
                    
                    content.classList.remove('collapsed');
                    toggle.classList.remove('collapsed');
                    icon.className = 'fas fa-chevron-down toggle-icon';
                } else {
                    group.style.display = 'none';
                }
            });
            
            // Komunikat o braku wyników
            showNoResultsMessage(!hasVisibleExercises, searchTerm);
        }

        // Funkcja filtrująca ćwiczenia w konkretnej grupie
        function filterGroupExercises(searchTerm, groupName) {
            const group = document.querySelector(`[data-group="${groupName}"]`);
            const clearBtn = group.parentElement.querySelector('.clear-search');
            
            if (searchTerm.length > 0) {
                clearBtn.style.display = 'block';
            } else {
                clearBtn.style.display = 'none';
            }
            
            searchTerm = searchTerm.toLowerCase().trim();
            
            const exercises = group.querySelectorAll('.exercise-selector');
            let hasVisible = false;
            
            exercises.forEach(exercise => {
                const exerciseName = exercise.dataset.exerciseName;
                
                if (searchTerm === '' || exerciseName.includes(searchTerm)) {
                    exercise.classList.remove('hidden');
                    hasVisible = true;
                } else {
                    exercise.classList.add('hidden');
                }
            });
            
            // Komunikat o braku wyników w tej grupie
            let noResultsDiv = group.querySelector('.no-results');
            if (!hasVisible && searchTerm !== '') {
                if (!noResultsDiv) {
                    noResultsDiv = document.createElement('div');
                    noResultsDiv.className = 'no-results';
                    group.appendChild(noResultsDiv);
                }
                noResultsDiv.textContent = `Brak ćwiczeń pasujących do "${searchTerm}"`;
            } else if (noResultsDiv) {
                noResultsDiv.remove();
            }
        }

        // Funkcja czyszcząca globalną wyszukiwarkę
        function clearGlobalSearch() {
            document.getElementById('globalExerciseSearch').value = '';
            filterExercises('');
        }

        // Funkcja czyszcząca wyszukiwarkę grupy
        function clearGroupSearch(groupName) {
            const group = document.querySelector(`[data-group="${groupName}"]`);
            const searchInput = group.parentElement.querySelector('.search-input');
            searchInput.value = '';
            filterGroupExercises('', groupName);
        }

        // Funkcja aktualizująca widoczność grup
        function updateGroupVisibility() {
            document.querySelectorAll('.exercise-group-search').forEach(group => {
                const visibleExercises = group.querySelectorAll('.exercise-selector:not(.hidden)');
                const badge = group.querySelector('.badge');
                badge.textContent = visibleExercises.length;
            });
        }

        // Funkcja pokazująca komunikat o braku wyników
        function showNoResultsMessage(show, searchTerm) {
            let noResultsDiv = document.getElementById('globalNoResults');
            
            if (show) {
                if (!noResultsDiv) {
                    noResultsDiv = document.createElement('div');
                    noResultsDiv.id = 'globalNoResults';
                    noResultsDiv.className = 'text-center text-muted p-4';
                    document.getElementById('exerciseBank').appendChild(noResultsDiv);
                }
                noResultsDiv.innerHTML = `
                    <i class="fas fa-search fa-2x mb-3"></i>
                    <h6>Brak wyników</h6>
                    <p>Nie znaleziono ćwiczeń pasujących do "<strong>${searchTerm}</strong>"</p>
                    <button class="btn btn-sm btn-outline-primary" onclick="clearGlobalSearch()">
                        <i class="fas fa-times"></i> Wyczyść wyszukiwanie
                    </button>
                `;
            } else if (noResultsDiv) {
                noResultsDiv.remove();
            }
        }

        // Funkcja zwiększająca wydajność wyszukiwania
        let searchTimeout;
        function debounceSearch(callback, delay = 300) {
            return function(searchTerm) {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => callback(searchTerm), delay);
            }
        }

        const debouncedFilterExercises = debounceSearch(filterExercises);
        const debouncedFilterGroupExercises = debounceSearch(filterGroupExercises);

        // Funkcja generująca dni treningowe
        function generateWorkoutDays() {
            console.log('Generowanie dni treningowych...');
            
            const daysCount = parseInt(document.getElementById('trainingDays').value);
            const container = document.getElementById('workoutDaysContainer');
            container.innerHTML = '';
            
            workoutDays = {};
            
            for (let i = 1; i <= daysCount; i++) {
                workoutDays[i] = {
                    name: `Dzień ${i}`,
                    exercises: []
                };
                
                const dayDiv = document.createElement('div');
                dayDiv.className = 'day-tab';
                dayDiv.dataset.dayNumber = i;
                
                dayDiv.innerHTML = `
                    <div class="day-tab-header">
                        <input type="text" class="day-name-input" value="Dzień ${i}" 
                            onchange="updateDayName(${i}, this.value)" placeholder="Nazwa dnia">
                        <small class="ms-3">
                            <i class="fas fa-hand-pointer"></i> Przeciągnij tutaj ćwiczenia
                        </small>
                    </div>
                    <div class="day-exercises" id="day_${i}_exercises">
                        <div class="text-muted text-center py-3">
                            <i class="fas fa-arrow-down"></i> Przeciągnij ćwiczenia z prawej strony
                        </div>
                    </div>
                `;
                
                container.appendChild(dayDiv);
                
                // Dodawanie event listenerów drag & drop
                const dayExercisesDiv = dayDiv.querySelector('.day-exercises');
                
                dayExercisesDiv.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'copy';
                    this.style.background = '#e7f3ff';
                    this.style.borderColor = '#007bff';
                });
                
                dayExercisesDiv.addEventListener('dragleave', function(e) {
                    this.style.background = '';
                    this.style.borderColor = '';
                });
                
                dayExercisesDiv.addEventListener('drop', function(e) {
                    window.dropExercise(e, i);
                });
                
                console.log(`Dzień ${i} utworzony z event listenerami`);
            }
            
            console.log('Wszystkie dni treningowe wygenerowane');
        }

        // Funkcja aktualizująca nazwę dnia
        function updateDayName(dayNumber, name) {
            workoutDays[dayNumber].name = name;
        }

        // Funkcja pozwalająca na drop
        function allowDrop(ev) {
            ev.preventDefault();
            ev.currentTarget.style.background = '#e7f3ff';
            ev.currentTarget.style.borderColor = '#007bff';
        }

        // Funkcja aktualizująca wyświetlanie dnia
        function updateDayDisplay(dayNumber) {
            console.log('Aktualizacja wyświetlania dnia', dayNumber);
            
            const container = document.getElementById(`day_${dayNumber}_exercises`);
            const exercises = workoutDays[dayNumber].exercises;
            
            container.innerHTML = '';
            
            if (exercises.length === 0) {
                container.innerHTML = `
                    <div class="text-muted text-center py-3">
                        <i class="fas fa-arrow-down"></i> Przeciągnij ćwiczenia z prawej strony
                    </div>
                `;
                return;
            }
    
            exercises.forEach((exercise, index) => {
                const exerciseDiv = document.createElement('div');
                exerciseDiv.className = 'exercise-in-day';
                exerciseDiv.style.animation = 'slideIn 0.3s ease';
                
                exerciseDiv.innerHTML = `
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <strong>${exercise.exercise_name}</strong>
                            <span class="badge ${exercise.is_weight_based == 1 ? 'bg-primary' : 'bg-success'} ms-2">
                                ${exercise.sets_count} ${exercise.sets_count == 1 ? 'seria' : 'serie'}
                            </span>
                            <small class="text-muted d-block">${exercise.muscle_group_name}</small>
                        </div>
                        <button class="btn btn-sm btn-outline-danger" 
                                onclick="removeExerciseFromDay(${dayNumber}, ${index})"
                                title="Usuń ćwiczenie">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <label class="form-label mb-0 me-2">Liczba serii:</label>
                        <input type="number" class="form-control form-control-sm sets-input" 
                            min="1" max="10" value="${exercise.sets_count}" 
                            onchange="updateExerciseSets(${dayNumber}, ${index}, this.value)"
                            style="width: 80px;">
                    </div>
                `;
                
                container.appendChild(exerciseDiv);
            });
            
            console.log('Wyświetlono', exercises.length, 'ćwiczeń w dniu', dayNumber);
        }

        // Funkcja usuwająca ćwiczenie z dnia
        function removeExerciseFromDay(dayNumber, exerciseIndex) {
            console.log('Usuwanie ćwiczenia:', dayNumber, exerciseIndex);
            
            if (confirm('Czy na pewno chcesz usunąć to ćwiczenie z tego dnia?')) {
                workoutDays[dayNumber].exercises.splice(exerciseIndex, 1);
                
                // Odświeżenie wyświetlania
                if (isEditMode) {
                    updateDayDisplayWithSort(dayNumber);
                } else {
                    updateDayDisplay(dayNumber);
                }
                
                console.log('Ćwiczenie usunięte');
            }
        }

        // Funkcja aktualizująca liczby serii
        function updateExerciseSets(dayNumber, exerciseIndex, newSetsCount) {
            const sets = parseInt(newSetsCount);
            
            if (sets < 1 || sets > 10) {
                alert('Liczba serii musi być w zakresie 1-10');
                return;
            }
            
            workoutDays[dayNumber].exercises[exerciseIndex].sets_count = sets;
            console.log('Zaktualizowano liczbę serii:', sets, 'dla ćwiczenia w dniu', dayNumber);
        }

        // Funkcja obsługująca drop ćwiczenia
        function dropExercise(ev, dayNumber) {
            ev.preventDefault();
            ev.currentTarget.style.background = '';
            ev.currentTarget.style.borderColor = '';
            
            const exerciseId = ev.dataTransfer.getData("text/plain");
            console.log('Drop - Exercise ID:', exerciseId, 'Day:', dayNumber);
            
            if (!exerciseId) {
                console.error('Brak ID ćwiczenia');
                alert('Błąd: Nie można dodać ćwiczenia');
                return;
            }
            
            const exercise = allExercises.find(ex => ex.id == exerciseId);
            
            if (!exercise) {
                console.error('Nie znaleziono ćwiczenia o ID:', exerciseId);
                alert('Błąd: Nie znaleziono ćwiczenia');
                return;
            }
            
            console.log('Znaleziono ćwiczenie:', exercise);
            
            // Sprawdzanie czy ćwiczenie już istnieje
            const existingExercise = workoutDays[dayNumber].exercises.find(ex => ex.exercise_id == exerciseId);
            if (existingExercise) {
                alert('To ćwiczenie już jest w tym dniu!');
                return;
            }
            
            // Dodawanie ćwiczenia do dnia
            workoutDays[dayNumber].exercises.push({
                exercise_id: exerciseId,
                exercise_name: exercise.name,
                muscle_group_name: exercise.muscle_group_name,
                is_weight_based: exercise.is_weight_based,
                sets_count: 3,
                order_index: workoutDays[dayNumber].exercises.length
            });
            
            console.log('Dodano ćwiczenie do dnia', dayNumber);
            console.log('Aktualna lista ćwiczeń:', workoutDays[dayNumber].exercises);
            
            // Odświeżenie wyświetlania
            if (isEditMode) {
                updateDayDisplayWithSort(dayNumber);
            } else {
                updateDayDisplay(dayNumber);
            }
        };

        // Funkcja pokazująca panel tworzenia planu
        function showCreatePlanModal() {
            document.getElementById('planName').value = '';
            document.getElementById('trainingDays').value = '3';
            
            const modal = new bootstrap.Modal(document.getElementById('createPlanModal'));
            modal.show();
            
            // Generowanie początkowych dni
            setTimeout(() => {
                generateWorkoutDays();
            }, 100);
        }

        // Funkcja tworząca plan
        function createPlan() {
            const planName = document.getElementById('planName').value.trim();
            const trainingDays = document.getElementById('trainingDays').value;
            
            if (!planName) {
                alert('Podaj nazwę planu!');
                return;
            }
            
            // Sprawdzanie czy przynajmniej jeden dzień ma ćwiczenia
            let hasExercises = false;
            Object.values(workoutDays).forEach(day => {
                if (day.exercises.length > 0) {
                    hasExercises = true;
                }
            });
            
            if (!hasExercises) {
                alert('Dodaj przynajmniej jedno ćwiczenie do jednego z dni!');
                return;
            }
            
            const formData = new FormData();
            formData.append('create_plan', '1');
            formData.append('name', planName);
            formData.append('training_days', trainingDays);
            formData.append('plan_data', JSON.stringify(workoutDays));
            
            fetch('plany.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('createPlanModal')).hide();
                    loadPlans();
                    alert('Plan utworzony pomyślnie');
                } else {
                    alert('Błąd: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Błąd:', error);
                alert('Wystąpił błąd podczas tworzenia planu');
            });
        }

        // Funkcja wyświetlająca szczegóły planu
        function viewPlanDetails(planId, planName) {
            document.getElementById('planDetailsTitle').textContent = planName;
            
            fetch(`plany.php?get_plan_details=1&plan_id=${planId}`)
                .then(response => response.json())
                .then(data => {
                    const container = document.getElementById('planDetailsContainer');
                    container.innerHTML = '';
                    
                    if (data.error) {
                        container.innerHTML = `<div class="alert alert-danger">${data.error}</div>`;
                        return;
                    }
                    
                    const planInfo = data.plan_info;
                    const workoutDays = data.workout_days;
                    
                    // Informacje o planie
                    container.innerHTML = `
                        <div class="mb-4">
                            <p><strong>Dni treningowe:</strong> ${planInfo.training_days_per_week}/tydzień</p>
                            <p><strong>Liczba dni:</strong> ${workoutDays.length}</p>
                        </div>
                    `;
                    
                    // Wyświetlanie dni treningowych
                    workoutDays.forEach(day => {
                        const dayDiv = document.createElement('div');
                        dayDiv.className = 'workout-day-card';
                        
                        const dayHeader = document.createElement('div');
                        dayHeader.className = 'workout-day-header';
                        dayHeader.innerHTML = `<i class="fas fa-calendar-day"></i> ${day.name}`;
                        dayDiv.appendChild(dayHeader);
                        
                        if (day.exercises.length === 0) {
                            const emptyDiv = document.createElement('div');
                            emptyDiv.className = 'p-3 text-muted text-center';
                            emptyDiv.innerHTML = '<i class="fas fa-info-circle"></i> Brak ćwiczeń w tym dniu';
                            dayDiv.appendChild(emptyDiv);
                        } else {
                            day.exercises.forEach(exercise => {
                                const exerciseDiv = document.createElement('div');
                                exerciseDiv.className = 'exercise-item';
                                
                                let lastWorkoutInfo = '';
                                if (exercise.last_workout_date) {
                                    lastWorkoutInfo = `<div class="last-workout">
                                        Ostatnio: ${exercise.last_workout_date}`;
                                    if (exercise.last_reps) lastWorkoutInfo += ` - ${exercise.last_reps} powt.`;
                                    if (exercise.last_weight) lastWorkoutInfo += ` @ ${exercise.last_weight}kg`;
                                    lastWorkoutInfo += `</div>`;
                                } else {
                                    lastWorkoutInfo = '<div class="last-workout">Nie wykonano jeszcze</div>';
                                }
                                
                                exerciseDiv.innerHTML = `
                                    <div>
                                        <strong>${exercise.exercise_name}</strong>
                                        <span class="badge ${exercise.is_weight_based == 1 ? 'bg-primary' : 'bg-success'} ms-2">
                                            ${exercise.sets_count} ${exercise.sets_count == 1 ? 'seria' : 'serie'}
                                        </span>
                                        <small class="text-muted d-block">${exercise.muscle_group_name}</small>
                                        ${lastWorkoutInfo}
                                    </div>
                                    <div>
                                        <button class="btn btn-success btn-sm me-2" onclick="showAddActivityModal(${exercise.plan_exercise_id}, '${exercise.exercise_name}', ${exercise.sets_count}, ${exercise.is_weight_based})">
                                            <i class="fas fa-plus"></i> Dodaj aktywność
                                        </button>
                                        <button class="btn btn-info btn-sm" onclick="showStatsModal(${exercise.plan_exercise_id}, '${exercise.exercise_name}')">
                                            <i class="fas fa-chart-line"></i> Statystyki
                                        </button>
                                    </div>
                                `;
                                
                                dayDiv.appendChild(exerciseDiv);
                            });
                        }
                        
                        container.appendChild(dayDiv);
                    });
                    
                    const modal = new bootstrap.Modal(document.getElementById('planDetailsModal'));
                    modal.show();
                })
                .catch(error => console.error('Błąd ładowania szczegółów planu:', error));
        }

        // Funkcja pokazująca panel dodawania aktywności
        function showAddActivityModal(planExerciseId, exerciseName, setsCount, isWeightBased) {
            
            // Ustawienie danych
            document.getElementById('activityPlanExerciseId').value = planExerciseId;
            document.getElementById('activityExerciseName').textContent = exerciseName;
            document.getElementById('activityPlannedSets').textContent = `${setsCount} ${setsCount == 1 ? 'seria' : 'serie'}`;
            
            // Ustawienie dzisiejszej daty
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('activityDate').value = today;
            
            // Czyszczenie notatek
            document.getElementById('activityNotes').value = '';
            
            // Generowanie pól dla serii
            generateSetsInputs(setsCount, isWeightBased);
            
            // Pokazywanie panelu
            const modal = new bootstrap.Modal(document.getElementById('addActivityModal'));
            modal.show();
        }

        // Funkcja generująca pola dla serii
        function generateSetsInputs(setsCount, isWeightBased) {
            const container = document.getElementById('setsContainer');
            container.innerHTML = '';
            
            for (let i = 1; i <= setsCount; i++) {
                const setDiv = document.createElement('div');
                setDiv.className = 'row mb-3 align-items-center';
                
                let inputFields = `
                    <div class="col-2">
                        <label class="form-label"><strong>Seria ${i}:</strong></label>
                    </div>
                    <div class="col-3">
                        <label class="form-label text-muted small">Powtórzenia:</label>
                        <input type="number" class="form-control" id="reps_${i}" 
                               min="1" max="100" placeholder="0">
                    </div>
                `;
                
                if (isWeightBased == 1) {
                    inputFields += `
                        <div class="col-3">
                            <label class="form-label text-muted small">Ciężar (kg):</label>
                            <input type="number" class="form-control" id="weight_${i}" 
                                   min="0" max="500" step="0.5" placeholder="0">
                        </div>
                        <div class="col-4">
                            <small class="text-muted">
                                <i class="fas fa-info-circle"></i> 
                                Zostaw puste jeśli nie wykonano serii
                            </small>
                        </div>
                    `;
                } else {
                    inputFields += `
                        <div class="col-7">
                            <small class="text-muted">
                                <i class="fas fa-info-circle"></i> 
                                Ćwiczenie bez dodatkowego ciężaru - wpisz tylko powtórzenia
                            </small>
                        </div>
                    `;
                }
                
                setDiv.innerHTML = inputFields;
                container.appendChild(setDiv);
            }
            
            // Dodawanie przykładów dla pierwszej serii
            const exampleDiv = document.createElement('div');
            exampleDiv.className = 'alert alert-info mt-3';
            exampleDiv.innerHTML = `
                <i class="fas fa-lightbulb"></i> <strong>Wskazówki:</strong>
                <ul class="mb-0 mt-2">
                    <li>Wpisuj tylko serie które faktycznie wykonałeś</li>
                    <li>Zostaw puste pola dla serii których nie zrobiłeś</li>
                    <li>Możesz dodać notatki o treningu poniżej</li>
                </ul>
            `;
            container.appendChild(exampleDiv);
        }

        // Funkcja zapisująca aktywność
        function saveActivity() {
            const planExerciseId = document.getElementById('activityPlanExerciseId').value;
            const workoutDate = document.getElementById('activityDate').value;
            const notes = document.getElementById('activityNotes').value;
            
            if (!workoutDate) {
                alert('Wybierz datę treningu!');
                return;
            }
            
            // Zbieranie danych wszystkich serii
            const setsData = [];
            const setsInputs = document.querySelectorAll('#setsContainer .row');
            
            setsInputs.forEach((setRow, index) => {

                if (setRow.classList.contains('alert')) return;
                
                const repsInput = setRow.querySelector(`#reps_${index + 1}`);
                const weightInput = setRow.querySelector(`#weight_${index + 1}`);
                
                if (repsInput) {
                    const reps = repsInput.value.trim();
                    const weight = weightInput ? weightInput.value.trim() : '';
                    
                    // Dodawanie serii tylko jeśli ma jakieś dane
                    if (reps !== '' || weight !== '') {
                        setsData.push({
                            reps: reps !== '' ? parseInt(reps) : null,
                            weight: weight !== '' ? parseFloat(weight) : null
                        });
                    }
                }
            });
            
            if (setsData.length === 0) {
                alert('Dodaj przynajmniej jedną serię z danymi!');
                return;
            }
            
            // Wysyłanie danych
            const formData = new FormData();
            formData.append('save_activity', '1');
            formData.append('plan_exercise_id', planExerciseId);
            formData.append('workout_date', workoutDate);
            formData.append('sets_data', JSON.stringify(setsData));
            formData.append('notes', notes);
            
            fetch('plany.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Zamknięcie panelu
                    bootstrap.Modal.getInstance(document.getElementById('addActivityModal')).hide();
                    
                    alert('Aktywność zapisana pomyślnie!');
                    
                    // Odświeżanie podsumowania tygodnia
                    loadWeeklySummary();
                    
                    // Odświeżanie szczegółów planu jeśli są otwarte
                    const planModal = document.getElementById('planDetailsModal');
                    if (planModal.classList.contains('show')) {
                        const planTitle = document.getElementById('planDetailsTitle').textContent;
                    }
                } else {
                    alert('Błąd: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Błąd:', error);
                alert('Wystąpił błąd podczas zapisywania aktywności');
            });
        }

        // Pokazywanie panelu ze statystykami
        function showStatsModal(planExerciseId, exerciseName) {
            currentExerciseId = planExerciseId;
            document.getElementById('statsExerciseName').textContent = exerciseName;
            
            // Resetowanie
            const container = document.querySelector('.stats-container');
            container.innerHTML = '<canvas id="progressChart"></canvas>';
            
            loadAvailableSeries(planExerciseId);
            
            const modal = new bootstrap.Modal(document.getElementById('statsModal'));
            modal.show();
        }

        // Ładowanie dostępnych serii
        function loadAvailableSeries(exerciseId) {
            fetch(`plany.php?get_exercise_sets=1&plan_exercise_id=${exerciseId}`)
                .then(response => response.json())
                .then(series => {
                    if (series.error) {
                        console.error('Błąd:', series.error);
                        return;
                    }
                    
                    availableSeries = series;
                    selectedSeries = [...series];
                    
                    if (series.length > 0) {
                        displaySeriesSelector();
                        loadChartData(exerciseId);
                    } else {
                        // Komunikat w przypadku braku danych
                        const container = document.querySelector('.stats-container');
                        container.innerHTML = `
                            <div class="text-center text-muted p-4">
                                <i class="fas fa-chart-line fa-3x mb-3"></i>
                                <h5>Brak danych</h5>
                                <p>Nie masz jeszcze zapisanych aktywności dla tego ćwiczenia.</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Błąd ładowania serii:', error);
                });
        }

        // Wyświetlanie selektora serii
        function displaySeriesSelector() {
            const container = document.getElementById('seriesCheckboxes');
            container.innerHTML = '';
            
            if (availableSeries.length === 0) {
                document.getElementById('seriesSelector').style.display = 'none';
                return;
            }
            
            document.getElementById('seriesSelector').style.display = 'block';
            
            availableSeries.forEach(seriesNum => {
                const checkbox = document.createElement('div');
                checkbox.className = 'series-checkbox selected';
                checkbox.dataset.seriesNum = seriesNum;
                checkbox.innerHTML = `Seria ${seriesNum}`;
                
                checkbox.addEventListener('click', function() {
                    toggleSeries(seriesNum);
                });
                
                container.appendChild(checkbox);
            });
            
            updateSeriesInfo();
        }

        // Przełączanie zaznaczenia serii
        function toggleSeries(seriesNum) {
            const index = selectedSeries.indexOf(seriesNum);
            const checkbox = document.querySelector(`[data-series-num="${seriesNum}"]`);
            
            if (index > -1) {
                selectedSeries.splice(index, 1);
                checkbox.classList.remove('selected');
            } else {
                selectedSeries.push(seriesNum);
                checkbox.classList.add('selected');
            }
            
            updateSeriesInfo();
            
            // Przeładowanie wykresu
            if (currentExerciseId && selectedSeries.length > 0) {
                loadChartData(currentExerciseId);
            }
        }

        // Zaznaczanie wszystkich serii
        function selectAllSeries() {
            selectedSeries = [...availableSeries];
            
            document.querySelectorAll('.series-checkbox').forEach(checkbox => {
                checkbox.classList.add('selected');
            });
            
            updateSeriesInfo();
            
            if (currentExerciseId) {
                loadChartData(currentExerciseId);
            }
        }

        // Czyszczenie zaznaczonych serii
        function clearSeriesSelection() {
            selectedSeries = [];
            
            document.querySelectorAll('.series-checkbox').forEach(checkbox => {
                checkbox.classList.remove('selected');
            });
            
            updateSeriesInfo();
            
            // Czyszczenie wykresu
            if (progressChart) {
                progressChart.destroy();
                progressChart = null;
            }
            
            // Komunikat po wyczyszczeniu
            const container = document.querySelector('.stats-container');
            if (container && !container.querySelector('canvas')) {
                container.innerHTML = `
                    <div class="text-center text-muted p-4">
                        <i class="fas fa-info-circle fa-2x mb-3"></i>
                        <h5>Wybierz serie</h5>
                        <p>Zaznacz przynajmniej jedną serię aby zobaczyć wykres.</p>
                    </div>
                `;
            }
        }

        // Aktualizowanie informacji o zaznaczonych seriach
        function updateSeriesInfo() {
            document.getElementById('selectedSeriesCount').textContent = selectedSeries.length;
        }

        // Ładowanie danych wykresu
        function loadChartData(exerciseId) {
            if (selectedSeries.length === 0) {
                const container = document.querySelector('.stats-container');
                container.innerHTML = `
                    <div class="text-center text-muted p-4">
                        <i class="fas fa-info-circle fa-2x mb-3"></i>
                        <h5>Wybierz serie</h5>
                        <p>Zaznacz przynajmniej jedną serię aby zobaczyć wykres.</p>
                    </div>
                `;
                return;
            }
            
            const setsParam = selectedSeries.join(',');
            
            fetch(`plany.php?get_chart_data=1&plan_exercise_id=${exerciseId}&selected_sets=${setsParam}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        console.error('Błąd:', data.error);
                        return;
                    }
                    
                    createAdvancedChart(data);
                })
                .catch(error => {
                    console.error('Błąd ładowania danych wykresu:', error);
                });
        }

        // Tworzenie wykresu z konkretnych wartości dla każdej serii
        function createAdvancedChart(rawData) {
            const ctx = document.getElementById('progressChart').getContext('2d');
            
            // Usuwanie poprzedniego wykresu
            if (progressChart) {
                progressChart.destroy();
            }
            
            if (!rawData || rawData.length === 0) {
                ctx.canvas.parentElement.innerHTML = `
                    <div class="text-center text-muted p-4">
                        <i class="fas fa-info-circle fa-2x mb-3"></i>
                        <h5>Brak danych</h5>
                        <p>Brak danych dla wybranych serii.</p>
                    </div>
                `;
                return;
            }
            
            // Grupowanie według serii
            const seriesData = {};
            const allDates = new Set();
            
            rawData.forEach(item => {
                const seriesNum = item.set_number;
                const date = item.workout_date;
                const reps = parseInt(item.reps_completed) || 0;
                const weight = parseFloat(item.weight_used) || 0;
                
                if (!seriesData[seriesNum]) {
                    seriesData[seriesNum] = {
                        reps: {},
                        weights: {}
                    };
                }
                
                seriesData[seriesNum].reps[date] = reps;
                seriesData[seriesNum].weights[date] = weight;
                allDates.add(date);
            });
            
            // Sortowanie dat
            const sortedDates = Array.from(allDates).sort();
            
            const hasWeightData = rawData.some(item => parseFloat(item.weight_used) > 0);
            
            // Sortowanie numeryczne
            const sortedSeries = [...selectedSeries].sort((a, b) => a - b);

            // Przygotowanie datasets dla każdej serii
            const datasets = [];
            const colors = ['#28a745', '#007bff', '#dc3545', '#ffc107', '#17a2b8', '#6f42c1', '#fd7e14', '#20c997'];
            
            // Grupowanie według serii (powtórzenia + ciężar razem)
            sortedSeries.forEach((seriesNum, index) => {
                if (seriesData[seriesNum]) {
                    // Dataset dla powtórzeń
                    const repsData = sortedDates.map(date => seriesData[seriesNum].reps[date] || null);
                    const hasRepsData = repsData.some(val => val !== null && val > 0);
                    
                    if (hasRepsData) {
                        datasets.push({
                            label: `Seria ${seriesNum} - Powtórzenia`,
                            data: repsData,
                            borderColor: colors[index % colors.length],
                            backgroundColor: colors[index % colors.length] + '20',
                            borderWidth: 2,
                            pointRadius: 5,
                            pointHoverRadius: 7,
                            fill: false,
                            tension: 0.1,
                            spanGaps: false,
                            yAxisID: 'y',
                            order: seriesNum * 10 + 1
                        });
                    }
                    
                    // Dataset dla ciężaru
                    if (hasWeightData) {
                        const weightData = sortedDates.map(date => seriesData[seriesNum].weights[date] || null);
                        const hasSeriesWeightData = weightData.some(val => val !== null && val > 0);
                        
                        if (hasSeriesWeightData) {
                            datasets.push({
                                label: `Seria ${seriesNum} - Ciężar (kg)`,
                                data: weightData,
                                borderColor: colors[index % colors.length],
                                backgroundColor: colors[index % colors.length] + '20',
                                borderWidth: 2,
                                pointRadius: 5,
                                pointHoverRadius: 7,
                                fill: false,
                                tension: 0.1,
                                spanGaps: false,
                                borderDash: [5, 5],
                                yAxisID: 'y1',
                                order: seriesNum * 10 + 2
                            });
                        }
                    }
                }
            });
            
            if (datasets.length === 0) {
                ctx.canvas.parentElement.innerHTML = `
                    <div class="text-center text-muted p-4">
                        <i class="fas fa-info-circle fa-2x mb-3"></i>
                        <h5>Brak danych</h5>
                        <p>Wybrane serie nie mają danych do wyświetlenia.</p>
                    </div>
                `;
                return;
            }
            
            const labels = sortedDates.map(date => {
                const d = new Date(date);
                return d.toLocaleDateString('pl-PL', { month: 'short', day: 'numeric' });
            });
            
            // Konfiguracja skal Y
            const scales = {
                x: {
                    title: {
                        display: true,
                        text: 'Data treningu'
                    },
                    grid: {
                        display: true
                    }
                },
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Powtórzenia'
                    },
                    grid: {
                        display: true
                    },
                    ticks: {
                        stepSize: 1
                    }
                }
            };
            
            // Druga oś Y dla ciężaru
            if (hasWeightData) {
                scales.y1 = {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Ciężar (kg)'
                    },
                    grid: {
                        drawOnChartArea: false
                    },
                    ticks: {
                        stepSize: 2.5
                    }
                };
            }
            
            progressChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: `Konkretne wartości dla serii: ${selectedSeries.join(', ')}`,
                            font: {
                                size: 16
                            }
                        },
                        legend: {
                            display: true,
                            position: 'top'
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                title: function(tooltipItems) {
                                    return 'Trening: ' + tooltipItems[0].label;
                                },
                                label: function(context) {
                                    if (context.parsed.y === null) {
                                        return context.dataset.label + ': brak danych';
                                    }
                                    
                                    if (context.dataset.label.includes('Ciężar')) {
                                        return context.dataset.label + ': ' + context.parsed.y + ' kg';
                                    } else {
                                        return context.dataset.label + ': ' + context.parsed.y + ' powtórzeń';
                                    }
                                },
                                // Sortowanie etykiet
                                labelSort: function(a, b) {
                                    // Wyciąganie numeru z etykiety
                                    const seriesA = parseInt(a.dataset.label.match(/Seria (\d+)/)[1]);
                                    const seriesB = parseInt(b.dataset.label.match(/Seria (\d+)/)[1]);
                                    
                                    if (seriesA !== seriesB) {
                                        return seriesA - seriesB;
                                    }
                                    
                                    const isRepsA = a.dataset.label.includes('Powtórzenia');
                                    const isRepsB = b.dataset.label.includes('Powtórzenia');
                                    
                                    if (isRepsA && !isRepsB) return -1;
                                    if (!isRepsA && isRepsB) return 1;
                                    return 0;
                                }
                            }
                        }
                    },
                    scales: scales,
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    }
                }
            });
        }

        // Inicjalizacja podsumowania tygodnia
        function initWeeklySummary() {
            goToCurrentWeek();
        }

        // Ładowanie podsumowania tygodnia
        function loadWeeklySummary() {
            const container = document.getElementById('weeklySummaryContainer');
            
            updateWeekRangeDisplay();
            
            fetch(`plany.php?get_weekly_summary=1&week_start=${currentWeekStart}`)
                .then(response => response.json())
                .then(data => {
                    displayWeeklySummary(data);
                })
                .catch(error => {
                    console.error('Błąd ładowania podsumowania tygodnia:', error);
                    container.innerHTML = '<div class="alert alert-danger">Błąd ładowania danych</div>';
                });
        }

        // Wyświetlanie podsumowania tygodnia
        function displayWeeklySummary(data) {
            const container = document.getElementById('weeklySummaryContainer');
            container.innerHTML = '';
            
            const summaryCard = document.createElement('div');
            summaryCard.className = 'weekly-summary-card';
            
            // Podsumowanie grup mięśniowych
            if (Object.keys(data.muscle_group_stats).length > 0) {
                const muscleGroupsHeader = document.createElement('div');
                muscleGroupsHeader.className = 'muscle-group-stats';
                muscleGroupsHeader.innerHTML = `
                    <h6>Serie według grup mięśniowych</h6>
                `;
                
                // Sortowanie grupy według liczby serii
                const sortedGroups = Object.entries(data.muscle_group_stats)
                    .sort(([,a], [,b]) => b - a);
                
                const muscleGroupsContainer = document.createElement('div');
                muscleGroupsContainer.style.marginTop = '10px';
                
                sortedGroups.forEach(([groupName, count]) => {
                    const statDiv = document.createElement('div');
                    statDiv.className = 'muscle-group-stat';
                    statDiv.innerHTML = `
                        <div class="count">${count}</div>
                        <div>${groupName}</div>
                    `;
                    muscleGroupsContainer.appendChild(statDiv);
                });
                
                muscleGroupsHeader.appendChild(muscleGroupsContainer);
                summaryCard.appendChild(muscleGroupsHeader);
            }
            
            const workoutsContainer = document.createElement('div');
            
            // Sprawdzanie czy są jakieś treningi
            if (data.daily_workouts.length === 0) {
                workoutsContainer.innerHTML = `
                    <div class="no-workouts">
                        <i class="fas fa-calendar-times fa-2x mb-3"></i>
                        <h6>Brak treningów</h6>
                        <p>W tym tygodniu nie zapisałeś żadnych aktywności treningowych.</p>
                    </div>
                `;
            } else {
                // Sortowanie dni chronologicznie
                data.daily_workouts.sort((a, b) => new Date(a.date) - new Date(b.date));
                
                data.daily_workouts.forEach(day => {
                    const dayDiv = document.createElement('div');
                    dayDiv.className = 'daily-workout';
                    
                    const dayHeader = document.createElement('div');
                    dayHeader.className = 'daily-workout-header';
                    const dayDate = new Date(day.date);
                    const dayName = dayDate.toLocaleDateString('pl-PL', { weekday: 'long' });
                    const dayFormatted = dayDate.toLocaleDateString('pl-PL', { day: 'numeric', month: 'short' });
                    
                    dayHeader.innerHTML = `
                        <i class="fas fa-calendar-day"></i> 
                        ${dayName.charAt(0).toUpperCase() + dayName.slice(1)}, ${dayFormatted}
                        <span class="badge bg-primary ms-2">${day.exercises.length} ćwiczeń</span>
                    `;
                    dayDiv.appendChild(dayHeader);
                    
                    // Ćwiczenia z tego dnia
                    day.exercises.forEach(exercise => {
                        const exerciseDiv = document.createElement('div');
                        exerciseDiv.className = 'exercise-summary';
                        
                        const exerciseHeader = document.createElement('div');
                        exerciseHeader.className = 'exercise-summary-header';
                        exerciseHeader.innerHTML = `
                            <i class="fas fa-dumbbell"></i> ${exercise.exercise_name}
                            <span class="badge ${exercise.is_weight_based == 1 ? 'bg-primary' : 'bg-success'} ms-2">
                                ${exercise.muscle_group_name}
                            </span>
                        `;
                        exerciseDiv.appendChild(exerciseHeader);
                        
                        // Informacje o planie i seriach
                        const setsInfo = document.createElement('div');
                        setsInfo.innerHTML = `
                            <small class="text-muted">
                                <i class="fas fa-clipboard-list"></i> Plan: ${exercise.plan_name} | 
                                Wykonano ${exercise.sets.length}/${exercise.planned_sets} serii
                            </small>
                        `;
                        exerciseDiv.appendChild(setsInfo);
                        
                        // Serie
                        const setsContainer = document.createElement('div');
                        setsContainer.className = 'sets-summary';
                        
                        exercise.sets.forEach(set => {
                            const setSpan = document.createElement('span');
                            setSpan.className = `set-badge ${exercise.is_weight_based == 1 ? '' : 'bodyweight'}`;
                            
                            let setText = `${set.reps_completed || 0}`;
                            if (exercise.is_weight_based == 1 && set.weight_used) {
                                setText += ` @ ${set.weight_used}kg`;
                            }
                            
                            setSpan.textContent = setText;
                            setSpan.title = `Seria ${set.set_number}`;
                            setsContainer.appendChild(setSpan);
                        });
                        
                        exerciseDiv.appendChild(setsContainer);
                        dayDiv.appendChild(exerciseDiv);
                    });
                    
                    workoutsContainer.appendChild(dayDiv);
                });
            }
            
            summaryCard.appendChild(workoutsContainer);
            container.appendChild(summaryCard);
        }

        // Nawigacja tygodni
        function changeWeek(direction) {
            const currentDate = new Date(currentWeekStart);
            currentDate.setDate(currentDate.getDate() + (direction * 7));
            currentWeekStart = currentDate.toISOString().split('T')[0];
            loadWeeklySummary();
        }

        // Przejście do bieżącego tygodnia
        function goToCurrentWeek() {
            const today = new Date();
            const monday = new Date(today);
            monday.setDate(today.getDate() - (today.getDay() === 0 ? 6 : today.getDay() - 1));
            
            currentWeekStart = monday.toISOString().split('T')[0];
            loadWeeklySummary();
        }

        // Aktualizacja wyświetlania zakresu tygodnia
        function updateWeekRangeDisplay() {
            const startDate = new Date(currentWeekStart);
            const endDate = new Date(startDate);
            endDate.setDate(startDate.getDate() + 6);
            
            const startFormatted = startDate.toLocaleDateString('pl-PL', { day: 'numeric', month: 'short' });
            const endFormatted = endDate.toLocaleDateString('pl-PL', { day: 'numeric', month: 'short', year: 'numeric' });
            
            document.getElementById('weekRangeDisplay').textContent = `${startFormatted} - ${endFormatted}`;
            
            // Sprawdzanie czy to bieżący tydzień
            const today = new Date();
            const thisMonday = new Date(today);
            thisMonday.setDate(today.getDate() - (today.getDay() === 0 ? 6 : today.getDay() - 1));
            
            const isCurrentWeek = currentWeekStart === thisMonday.toISOString().split('T')[0];
            const currentWeekBtn = document.getElementById('currentWeekBtn');
            
            if (isCurrentWeek) {
                currentWeekBtn.classList.remove('btn-outline-primary');
                currentWeekBtn.classList.add('btn-primary');
                currentWeekBtn.innerHTML = '<i class="fas fa-calendar-check"></i> Bieżący tydzień';
            } else {
                currentWeekBtn.classList.remove('btn-primary');
                currentWeekBtn.classList.add('btn-outline-primary');
                currentWeekBtn.innerHTML = 'Bieżący tydzień';
            }
        }

        // Ładowanie grup mięśniowych
        function loadMuscleGroups() {
            fetch('plany.php?get_muscle_groups=1')
                .then(response => response.json())
                .then(groups => {
                    muscleGroups = groups;
                    
                    const select = document.getElementById('exerciseMuscleGroup');
                    if (select) {
                        select.innerHTML = '<option value="">Wybierz grupę mięśniową...</option>';
                        groups.forEach(group => {
                            const option = document.createElement('option');
                            option.value = group.id;
                            option.textContent = group.name;
                            select.appendChild(option);
                        });
                    }
                })
                .catch(error => console.error('Błąd ładowania grup mięśniowych:', error));
        }

        // Pokazanie panelu tworzenia własnego ćwiczenia
        function showCreateExerciseModal() {
            document.getElementById('exerciseName').value = '';
            document.getElementById('exerciseMuscleGroup').value = '';
            document.getElementById('exerciseWithWeight').checked = true;
            
            loadMuscleGroupsForModal();
            
            const modal = new bootstrap.Modal(document.getElementById('createExerciseModal'));
            modal.show();
        }

        function loadMuscleGroupsForModal() {
            fetch('plany.php?get_muscle_groups=1')
                .then(response => response.json())
                .then(groups => {
                    const select = document.getElementById('exerciseMuscleGroup');
                    select.innerHTML = '<option value="">Wybierz grupę mięśniową...</option>';
                    
                    groups.forEach(group => {
                        const option = document.createElement('option');
                        option.value = group.id;
                        option.textContent = group.name;
                        select.appendChild(option);
                    });
                })
                .catch(error => {
                    console.error('Błąd ładowania grup mięśniowych:', error);
                });
        }

        // Tworzenie własnego ćwiczenia
        function createCustomExercise() {
            const name = document.getElementById('exerciseName').value.trim();
            const muscleGroupId = document.getElementById('exerciseMuscleGroup').value;
            const isWeightBased = document.querySelector('input[name="exerciseType"]:checked').value;
            
            console.log('Dane ćwiczenia:', { name, muscleGroupId, isWeightBased });
            
            if (!name) {
                alert('Podaj nazwę ćwiczenia!');
                return;
            }
            
            if (!muscleGroupId) {
                alert('Wybierz grupę mięśniową!');
                return;
            }
            
            const formData = new FormData();
            formData.append('create_exercise', '1');
            formData.append('name', name);
            formData.append('muscle_group_id', muscleGroupId);
            formData.append('is_weight_based', isWeightBased);
            
            const submitBtn = document.querySelector('#createExerciseModal .btn-success');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Dodawanie...';
            submitBtn.disabled = true;
            
            fetch('plany.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                console.log('Odpowiedź serwera:', data);
                
                if (data.success) {
                    // Zamknięcie panelu
                    bootstrap.Modal.getInstance(document.getElementById('createExerciseModal')).hide();
                    
                    console.log('Nowe ćwiczenie:', data.exercise);
                    
                    // Dodanie nowego ćwiczenia do listy
                    allExercises.push(data.exercise);
                    
                    // Odświeżenie banku ćwiczeń
                    displayExerciseBank();
                    
                    alert('Ćwiczenie zostało dodane pomyślnie!');
                } else {
                    alert('Błąd: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Błąd:', error);
                alert('Wystąpił błąd podczas dodawania ćwiczenia');
            })
            .finally(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        }

        let isEditMode = false;
        let editingPlanId = null;

        // Edycja istniejącego planu
        function editPlan(planId, planName) {
            isEditMode = true;
            editingPlanId = planId;
            
            document.querySelector('#createPlanModal .modal-title').innerHTML = 
                '<i class="fas fa-edit"></i> Edytuj plan treningowy';
            
            const saveBtn = document.querySelector('#createPlanModal .modal-footer .btn-primary');
            saveBtn.innerHTML = '<i class="fas fa-save"></i> Zapisz zmiany';
            saveBtn.onclick = () => updatePlan();
            
            // Ładowanie danych planu
            fetch(`plany.php?get_plan_details=1&plan_id=${planId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert('Błąd: ' + data.error);
                        return;
                    }
                    
                    document.getElementById('planName').value = data.plan_info.name;
                    document.getElementById('trainingDays').value = data.plan_info.training_days_per_week;
                    
                    workoutDays = {};
                    
                    // Ładowanie dni treningowych
                    data.workout_days.forEach(day => {
                        workoutDays[day.day_number] = {
                            id: day.id,
                            name: day.name,
                            exercises: day.exercises.map(ex => ({
                                plan_exercise_id: ex.plan_exercise_id,
                                exercise_id: ex.exercise_id,
                                exercise_name: ex.exercise_name,
                                muscle_group_name: ex.muscle_group_name,
                                is_weight_based: ex.is_weight_based,
                                sets_count: ex.sets_count,
                                order_index: ex.order_index || 0
                            }))
                        };
                        
                        // Sortowanie ćwiczeń
                        workoutDays[day.day_number].exercises.sort((a, b) => 
                            (a.order_index || 0) - (b.order_index || 0)
                        );
                    });
                    
                    // Generowanie dni treningowych z danymi
                    generateWorkoutDaysForEdit();
                    
                    const modal = new bootstrap.Modal(document.getElementById('createPlanModal'));
                    modal.show();
                })
                .catch(error => {
                    console.error('Błąd ładowania planu:', error);
                    alert('Wystąpił błąd podczas ładowania planu');
                });
        }

        // Funkcja do znajdowania ID ćwiczenia po nazwie
        function findExerciseIdByName(exerciseName) {
            const exercise = allExercises.find(ex => ex.name === exerciseName);
            return exercise ? exercise.id : null;
        }

        // Generowanie dni treningowych
        function generateWorkoutDaysForEdit() {
            const container = document.getElementById('workoutDaysContainer');
            container.innerHTML = '';
            
            Object.keys(workoutDays).forEach(dayNumber => {
                const dayData = workoutDays[dayNumber];
                
                const dayDiv = document.createElement('div');
                dayDiv.className = 'day-tab';
                dayDiv.dataset.dayNumber = dayNumber;
                
                dayDiv.innerHTML = `
                    <div class="day-tab-header">
                        <input type="text" class="day-name-input" value="${dayData.name}" 
                            onchange="updateDayName(${dayNumber}, this.value)" placeholder="Nazwa dnia">
                        <small class="ms-3">
                            <i class="fas fa-info-circle"></i> 
                            Przeciągnij ćwiczenia z prawej | Zmień kolejność używając przycisków
                        </small>
                    </div>
                    <div class="day-exercises sortable-exercises" 
                        ondrop="dropExercise(event, ${dayNumber})" 
                        ondragover="allowDrop(event)"
                        id="day_${dayNumber}_exercises">
                    </div>
                `;
                
                container.appendChild(dayDiv);
                updateDayDisplayWithSort(dayNumber);
            });
        }

        // Aktualizacja wyświetlania dnia z przyciskami sortowania
        function updateDayDisplayWithSort(dayNumber) {
            const container = document.getElementById(`day_${dayNumber}_exercises`);
            const exercises = workoutDays[dayNumber].exercises;
            
            container.innerHTML = '';
            
            if (exercises.length === 0) {
                container.innerHTML = `
                    <div class="text-muted text-center py-3">
                        <i class="fas fa-arrow-down"></i> Przeciągnij ćwiczenia z prawej strony
                    </div>
                `;
                return;
            }
            
            exercises.forEach((exercise, index) => {
                const exerciseDiv = document.createElement('div');
                exerciseDiv.className = 'exercise-in-day-edit';
                
                exerciseDiv.innerHTML = `
                    <div class="exercise-content">
                        <div class="exercise-main">
                            <div class="exercise-info">
                                <strong>${exercise.exercise_name}</strong>
                                <small class="text-muted d-block">${exercise.muscle_group_name}</small>
                                <span class="badge ${exercise.is_weight_based == 1 ? 'bg-primary' : 'bg-success'} mt-1">
                                    ${exercise.is_weight_based == 1 ? 'Ciężar' : 'Powtórzenia'}
                                </span>
                            </div>
                            <div class="exercise-controls">
                                <div class="sets-control">
                                    <label class="form-label">Serie:</label>
                                    <input type="number" class="form-control sets-input-edit" min="1" max="10" 
                                        value="${exercise.sets_count}" 
                                        onchange="updateExerciseSets(${dayNumber}, ${index}, this.value)">
                                </div>
                                <div class="action-buttons">
                                    <button class="btn btn-sm btn-danger" 
                                            onclick="removeExerciseFromDay(${dayNumber}, ${index})"
                                            title="Usuń ćwiczenie">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="order-controls">
                            <button class="btn btn-sm btn-outline-secondary" 
                                    onclick="moveExercise(${dayNumber}, ${index}, 'up')"
                                    ${index === 0 ? 'disabled' : ''}
                                    title="Przenieś w górę">
                                <i class="fas fa-arrow-up"></i>
                            </button>
                            <span class="order-number">${index + 1}</span>
                            <button class="btn btn-sm btn-outline-secondary" 
                                    onclick="moveExercise(${dayNumber}, ${index}, 'down')"
                                    ${index === exercises.length - 1 ? 'disabled' : ''}
                                    title="Przenieś w dół">
                                <i class="fas fa-arrow-down"></i>
                            </button>
                        </div>
                    </div>
                `;
                
                container.appendChild(exerciseDiv);
            });
        }

        // Funkcja do przesuwania ćwiczeń w górę/dół
        function moveExercise(dayNumber, exerciseIndex, direction) {
            const exercises = workoutDays[dayNumber].exercises;
            
            if (direction === 'up' && exerciseIndex > 0) {
                // Zamiana miejscami z poprzednim
                [exercises[exerciseIndex], exercises[exerciseIndex - 1]] = 
                [exercises[exerciseIndex - 1], exercises[exerciseIndex]];
            } else if (direction === 'down' && exerciseIndex < exercises.length - 1) {
                // Zamiana miejscami z następnym
                [exercises[exerciseIndex], exercises[exerciseIndex + 1]] = 
                [exercises[exerciseIndex + 1], exercises[exerciseIndex]];
            }
            
            // Odświeżenie
            if (isEditMode) {
                updateDayDisplayWithSort(dayNumber);
            } else {
                updateDayDisplay(dayNumber);
            }
        }

        // Aktualizacja planu
        function updatePlan() {
            const planName = document.getElementById('planName').value.trim();
            const trainingDays = document.getElementById('trainingDays').value;
            
            if (!planName) {
                alert('Podaj nazwę planu!');
                return;
            }
            
            // Sprawdzenie czy przynajmniej jeden dzień ma ćwiczenia
            let hasExercises = false;
            Object.values(workoutDays).forEach(day => {
                if (day.exercises.length > 0) {
                    hasExercises = true;
                }
            });
            
            if (!hasExercises) {
                alert('Dodaj przynajmniej jedno ćwiczenie do jednego z dni!');
                return;
            }
            
            const formData = new FormData();
            formData.append('update_plan', '1');
            formData.append('plan_id', editingPlanId);
            formData.append('name', planName);
            formData.append('training_days', trainingDays);
            formData.append('plan_data', JSON.stringify(workoutDays));
            
            fetch('plany.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('createPlanModal')).hide();
                    loadPlans();
                    alert('Plan zaktualizowany pomyślnie!');
                    
                    resetCreatePlanModal();
                } else {
                    alert('Błąd: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Błąd:', error);
                alert('Wystąpił błąd podczas zapisywania planu');
            });
        }

        // Usuwanie planu
        function deletePlan(planId, planName) {
            if (!confirm(`Czy na pewno chcesz usunąć plan "${planName}"?\n\nTa operacja jest nieodwracalna i usunie również wszystkie związane z nim dane treningowe.`)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('delete_plan', '1');
            formData.append('plan_id', planId);
            
            fetch('plany.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadPlans();
                    alert('Plan został usunięty pomyślnie!');
                } else {
                    alert('Błąd: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Błąd:', error);
                alert('Wystąpił błąd podczas usuwania planu');
            });
        }

        // Resetowanie panelu do stanu tworzenia
        function resetCreatePlanModal() {
            isEditMode = false;
            editingPlanId = null;
            
            document.querySelector('#createPlanModal .modal-title').innerHTML = 
                '<i class="fas fa-plus"></i> Utwórz nowy plan treningowy';
            
            const saveBtn = document.querySelector('#createPlanModal .modal-footer .btn-primary');
            saveBtn.innerHTML = '<i class="fas fa-save"></i> Utwórz plan';
            saveBtn.onclick = createPlan;
        }

        function showCreatePlanModal() {
            resetCreatePlanModal();
            
            document.getElementById('planName').value = '';
            document.getElementById('trainingDays').value = '3';
            
            const modal = new bootstrap.Modal(document.getElementById('createPlanModal'));
            modal.show();
            
            // Generowanie początkowych dni
            setTimeout(() => {
                generateWorkoutDays();
            }, 100);
        }
    </script>

    <!-- Stopka -->
    <?php require_once 'footer.php'; ?>
    
</body>
</html>

<?php mysqli_close($connection); ?>
