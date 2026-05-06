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

function get_user_id(): int {
    return (int) $_SESSION['user_id'];
}

function get_int(array $source, string $key): int {
    return isset($source[$key]) ? (int) $source[$key] : 0;
}

function fetch_exercise_details_if_owner(mysqli $connection, int $plan_exercise_id, int $user_id) {
    return mysqli_query($connection, "
        SELECT pe.*, e.name as exercise_name, e.is_weight_based
        FROM plan_exercises pe 
        JOIN workout_plans wp ON pe.plan_id = wp.id 
        JOIN exercises e ON pe.exercise_id = e.id
        WHERE pe.id = $plan_exercise_id AND wp.user_id = $user_id
    ");
}

// ==================== AJAX Endpoints ====================

// --- GET: Pobieranie ćwiczeń użytkownika z aktywnościami
if (isset($_GET['get_user_exercises'])) {
    json_header();
    $user_id = get_user_id();
    
    $query = "SELECT DISTINCT pe.id as plan_exercise_id, 
                     e.name as exercise_name, 
                     e.is_weight_based, 
                     mg.name as muscle_group_name,
                     wp.name as plan_name, 
                     pe.sets_count,
                     COUNT(DISTINCT wa.workout_date) as workout_count
              FROM plan_exercises pe 
              JOIN exercises e ON pe.exercise_id = e.id 
              JOIN muscle_groups mg ON e.muscle_group_id = mg.id 
              JOIN workout_plans wp ON pe.plan_id = wp.id 
              LEFT JOIN workout_activities wa ON pe.id = wa.plan_exercise_id AND wa.user_id = $user_id
              WHERE wp.user_id = $user_id 
              GROUP BY pe.id, e.name, e.is_weight_based, mg.name, wp.name, pe.sets_count
              HAVING workout_count > 0
              ORDER BY mg.name, e.name, wp.name";
    
    $result = mysqli_query($connection, $query);
    
    $exercises = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $exercises[] = $row;
    }
    
    echo json_encode($exercises);
    exit();
}

// --- GET: Pobieranie szczegółowych statystyk ćwiczenia
if (isset($_GET['get_detailed_stats']) && isset($_GET['plan_exercise_id'])) {
    json_header();
    
    $user_id = get_user_id();
    $plan_exercise_id = get_int($_GET, 'plan_exercise_id');
    
    // Sprawdzanie czy ćwiczenie należy do użytkownika
    $check = fetch_exercise_details_if_owner($connection, $plan_exercise_id, $user_id);
    
    if (mysqli_num_rows($check) == 0) {
        echo json_encode(['error' => 'Brak dostępu']);
        exit();
    }
    
    $exercise_info = mysqli_fetch_assoc($check);
    
    // Pobieranie aktywności treningowych
    $query = "SELECT workout_date, set_number, reps_completed, weight_used, notes
              FROM workout_activities 
              WHERE user_id = $user_id AND plan_exercise_id = $plan_exercise_id 
              AND (reps_completed IS NOT NULL OR weight_used IS NOT NULL)
              ORDER BY workout_date ASC, set_number ASC";
    
    $result = mysqli_query($connection, $query);
    
    $activities = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $activities[] = $row;
    }
    
    echo json_encode([
        'exercise_info' => $exercise_info,
        'activities' => $activities
    ]);
    exit();
}
// ==================== HTML / UI ====================
?>

<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="pl" lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="Progres treningowy">
    <meta name="author" content="Filarecki">
    <meta name="keywords" content="trening, progres, statystyki, siłownia">
    <title>Progres treningowy - Filarecki</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" type="text/css" href="twoj_css.css">

    <style>
        .stats-card {
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .exercise-selector {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin: 10px 0;
            cursor: pointer;
            transition: all 0.2s ease;
            background: white;
        }
        
        .exercise-selector:hover {
            border-color: #007bff;
            box-shadow: 0 2px 8px rgba(0,123,255,0.2);
        }
        
        .exercise-selector.active {
            border-color: #007bff;
            background: #e7f3ff;
            box-shadow: 0 2px 8px rgba(0,123,255,0.3);
        }
        
        .chart-container {
            height: 400px;
            margin-top: 20px;
        }
        
        .exercise-info {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        
        .summary-stats {
            display: flex;
            justify-content: space-around;
            flex-wrap: wrap;
            margin: 20px 0;
        }
        
        .summary-stat {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            margin: 5px;
            flex: 1;
            min-width: 120px;
        }
        
        .summary-stat .value {
            font-size: 24px;
            font-weight: bold;
            color: #007bff;
        }
        
        .summary-stat .label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
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
            gap: 10px;
            margin-top: 10px;
        }

        .series-checkbox {
            background: white;
            border: 2px solid #ddd;
            border-radius: 6px;
            padding: 8px 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            user-select: none;
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
            justify-content: space-between;
            align-items: center;
            margin-top: 10px;
        }

        .series-info {
            font-size: 12px;
            color: #666;
        }
    </style>
</head>
<body onload="myLoadHeader()">
    <!-- Nagłówek -->
    <div id='myHeader' class="text-center"></div>

    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <h2><i class="fas fa-chart-line"></i> Progres treningowy</h2>
                <p class="text-muted">Śledź swój postęp w ćwiczeniach z planów treningowych</p>
                
                <div class="stats-card">
                    <h5><i class="fas fa-dumbbell"></i> Wybierz ćwiczenie</h5>
                    <div id="exercisesContainer">
                        <div class="text-center">
                            <i class="fas fa-spinner fa-spin"></i> Ładowanie ćwiczeń...
                        </div>
                    </div>
                </div>
                
                <!-- Panel statystyk -->
                <div id="statsPanel" style="display: none;">
                    <!-- Informacje o ćwiczeniu -->
                    <div class="exercise-info">
                        <h5 id="selectedExerciseName"></h5>
                        <div id="exerciseDetails"></div>
                    </div>
                    
                    <div class="series-selector" id="seriesSelector" style="display: none;">
                        <h6><i class="fas fa-list-ol"></i> Wybierz serie do analizy:</h6>
                        <div class="series-checkboxes" id="seriesCheckboxes">
                            <!-- Tutaj generowane są serie -->
                        </div>
                        <div class="series-controls">
                            <div class="series-info" id="seriesInfo">
                                Analizowane serie: <span id="selectedSeriesCount">0</span>
                            </div>
                            <div>
                                <button class="btn btn-sm btn-outline-primary" onclick="selectAllSeries()">
                                    <i class="fas fa-check-double"></i> Wszystkie
                                </button>
                                <button class="btn btn-sm btn-outline-secondary" onclick="clearSeriesSelection()">
                                    <i class="fas fa-times"></i> Wyczyść
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Statystyki -->
                    <div class="summary-stats" id="summaryStats">
                        <!-- Tutaj generowane są statystyki -->
                    </div>
                    
                    <!-- Przyciski wyboru typu wykresu -->
                    <div class="stats-card">
                        <h6><i class="fas fa-chart-area"></i> Wykres postępów:</h6>
                        
                        <div class="chart-container">
                            <canvas id="progressChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- Informacja w przypadku braku danych treningowych -->
                <div id="noDataPanel" class="no-data" style="display: none;">
                    <i class="fas fa-info-circle fa-3x mb-3"></i>
                    <h5>Brak danych treningowych</h5>
                    <p>Wybierz ćwiczenie, dla którego masz zapisane aktywności treningowe.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.5.1.js"></script>
    <!-- Mój JS -->
    <script src="twoj_js.js"></script>

    <script>
        let userExercises = [];
        let currentExerciseId = null;
        let progressChart = null;
        let currentExerciseData = null;
        let availableSeries = [];
        let selectedSeries = [];

        document.addEventListener('DOMContentLoaded', loadUserExercises);

        // Funkcja ładująca listę ćwiczeń użytkownika z serwera
        function loadUserExercises() {
            fetch('progres.php?get_user_exercises=1')
                .then(response => response.json())
                .then(exercises => {
                    userExercises = exercises;
                    displayExercises();
                })
                .catch(error => {
                    console.error('Błąd:', error);
                    document.getElementById('exercisesContainer').innerHTML = 
                        '<div class="alert alert-danger">Błąd ładowania ćwiczeń</div>';
                });
        }

        // Funkcja wyświetlająca listę ćwiczeń pogrupowanych według partii mięśniowych
        function displayExercises() {
            const container = document.getElementById('exercisesContainer');
            container.innerHTML = '';
            
            if (userExercises.length === 0) {
                container.innerHTML = `
                    <div class="text-center text-muted p-4">
                        <i class="fas fa-info-circle fa-2x mb-3"></i>
                        <h5>Brak ćwiczeń z danymi</h5>
                        <p>Dodaj aktywności treningowe w swoich planach, aby śledzić postęp.</p>
                        <a href="plany.php" class="btn btn-primary">
                            <i class="fas fa-arrow-left"></i> Przejdź do planów
                        </a>
                    </div>
                `;
                return;
            }
            
            const exercisesByGroup = {};
            userExercises.forEach(exercise => {
                if (!exercisesByGroup[exercise.muscle_group_name]) {
                    exercisesByGroup[exercise.muscle_group_name] = [];
                }
                exercisesByGroup[exercise.muscle_group_name].push(exercise);
            });
            
            Object.keys(exercisesByGroup).forEach(groupName => {
                const groupDiv = document.createElement('div');
                groupDiv.innerHTML = `<h6 class="mt-3 mb-2 text-primary"><i class="fas fa-dumbbell"></i> ${groupName}</h6>`;
                container.appendChild(groupDiv);
                
                exercisesByGroup[groupName].forEach(exercise => {
                    const exerciseDiv = document.createElement('div');
                    exerciseDiv.className = 'exercise-selector';
                    exerciseDiv.dataset.exerciseId = exercise.plan_exercise_id;
                    
                    exerciseDiv.innerHTML = `
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong>${exercise.exercise_name}</strong>
                                <div class="small text-muted">
                                    Plan: ${exercise.plan_name} | 
                                    ${exercise.workout_count} ${exercise.workout_count == 1 ? 'trening' : 'treningi'} |
                                    <span class="badge ${exercise.is_weight_based == 1 ? 'bg-primary' : 'bg-success'}">
                                        ${exercise.is_weight_based == 1 ? 'Z ciężarem' : 'Bez ciężaru'}
                                    </span>
                                </div>
                            </div>
                            <i class="fas fa-chart-line text-primary"></i>
                        </div>
                    `;
                    
                    exerciseDiv.addEventListener('click', () => selectExercise(exercise.plan_exercise_id, exercise));
                    container.appendChild(exerciseDiv);
                });
            });
        }

        // Funkcja obsługująca wybór ćwiczenia przez użytkownika
        function selectExercise(exerciseId, exerciseData) {
            document.querySelectorAll('.exercise-selector').forEach(el => el.classList.remove('active'));
            document.querySelector(`[data-exercise-id="${exerciseId}"]`).classList.add('active');
            
            currentExerciseId = exerciseId;
            
            document.getElementById('selectedExerciseName').textContent = exerciseData.exercise_name;
            document.getElementById('exerciseDetails').innerHTML = `
                <div class="row">
                    <div class="col-md-3"><strong>Plan:</strong> ${exerciseData.plan_name}</div>
                    <div class="col-md-3"><strong>Grupa:</strong> ${exerciseData.muscle_group_name}</div>
                    <div class="col-md-3"><strong>Serie:</strong> ${exerciseData.sets_count}</div>
                    <div class="col-md-3"><strong>Treningi:</strong> ${exerciseData.workout_count}</div>
                </div>
            `;
            
            loadExerciseDetails(exerciseId);
        }

        // Funkcja ładująca szczegółowe dane treningowe dla wybranego ćwiczenia
        function loadExerciseDetails(exerciseId) {
            fetch(`progres.php?get_detailed_stats=1&plan_exercise_id=${exerciseId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error || data.activities.length === 0) {
                        showNoDataPanel();
                        return;
                    }
                    
                    currentExerciseData = data;
                    document.getElementById('statsPanel').style.display = 'block';
                    document.getElementById('noDataPanel').style.display = 'none';
                    
                    generateSummaryStats(data);
                    loadExerciseSeries(exerciseId);
                    loadProgressChart(exerciseId);
                })
                .catch(error => {
                    console.error('Błąd:', error);
                    showNoDataPanel();
                });
        }

        // Funkcja ładująca dostępne serie dla wybranego ćwiczenia
        function loadExerciseSeries(exerciseId) {
            fetch(`plany.php?get_exercise_sets=1&plan_exercise_id=${exerciseId}`)
                .then(response => response.json())
                .then(series => {
                    if (!series.error && series.length > 0) {
                        availableSeries = series;
                        selectedSeries = [...series];
                        displaySeriesSelector();
                    }
                })
                .catch(error => console.error('Błąd:', error));
        }

        // Funkcja wyświetlająca selektor serii do analizy
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
                checkbox.textContent = `Seria ${seriesNum}`;
                checkbox.addEventListener('click', () => toggleSeries(seriesNum));
                container.appendChild(checkbox);
            });
            
            updateSeriesInfo();
        }

        // Funkcja przełączająca zaznaczenie wybranej serii
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
            if (currentExerciseId && selectedSeries.length > 0) {
                loadProgressChart(currentExerciseId);
            }
        }

        // Funkcja zaznaczająca wszystkie dostępne serie
        function selectAllSeries() {
            selectedSeries = [...availableSeries];
            document.querySelectorAll('.series-checkbox').forEach(cb => cb.classList.add('selected'));
            updateSeriesInfo();
            if (currentExerciseId) loadProgressChart(currentExerciseId);
        }

        // Funkcja odznaczająca wszystkie serie i czyszcząca wykres
        function clearSeriesSelection() {
            selectedSeries = [];
            document.querySelectorAll('.series-checkbox').forEach(cb => cb.classList.remove('selected'));
            updateSeriesInfo();
            if (progressChart) {
                progressChart.destroy();
                progressChart = null;
            }
        }

        // Funkcja aktualizująca licznik zaznaczonych serii
        function updateSeriesInfo() {
            document.getElementById('selectedSeriesCount').textContent = selectedSeries.length;
        }

        // Funkcja generująca podsumowanie statystyk dla wybranego ćwiczenia
        function generateSummaryStats(data) {
            const container = document.getElementById('summaryStats');
            const activities = data.activities;
            const exerciseInfo = data.exercise_info;
            
            const totalWorkouts = [...new Set(activities.map(a => a.workout_date))].length;
            const totalSets = activities.length;
            const totalReps = activities.reduce((sum, a) => sum + (parseInt(a.reps_completed) || 0), 0);
            
            let maxWeight = 0, maxReps = 0, totalVolume = 0;
            
            activities.forEach(activity => {
                const reps = parseInt(activity.reps_completed) || 0;
                const weight = parseFloat(activity.weight_used) || 0;
                maxWeight = Math.max(maxWeight, weight);
                maxReps = Math.max(maxReps, reps);
                totalVolume += reps * weight;
            });
            
            const stats = [
                { label: 'Treningi', value: totalWorkouts },
                { label: 'Serie', value: totalSets },
                { label: 'Suma powtórzeń', value: totalReps }
            ];

            if (exerciseInfo.is_weight_based == 1) {
                stats.push(
                    { label: 'Rekord ciężaru', value: maxWeight + ' kg' },
                    { label: 'Suma objętości', value: Math.round(totalVolume) + ' kg' }
                );
            } else {
                stats.push({ label: 'Rekord powtórzeń', value: maxReps });
            }
            
            container.innerHTML = stats.map(stat => `
                <div class="summary-stat">
                    <div class="value">${stat.value}</div>
                    <div class="label">${stat.label}</div>
                </div>
            `).join('');
        }

        // Funkcja wyświetlająca panel informujący o braku danych
        function showNoDataPanel() {
            document.getElementById('statsPanel').style.display = 'none';
            document.getElementById('noDataPanel').style.display = 'block';
        }

        // Funkcja ładująca dane i generująca wykres dla wybranego ćwiczenia
        function loadProgressChart(exerciseId) {
            if (selectedSeries.length === 0) {
                const ctx = document.getElementById('progressChart');
                if (progressChart) progressChart.destroy();
                ctx.parentElement.innerHTML = `
                    <div class="text-center text-muted" style="height: 400px; display: flex; align-items: center; justify-content: center; flex-direction: column;">
                        <i class="fas fa-info-circle fa-2x mb-3"></i>
                        <h5>Wybierz serie</h5>
                        <p>Zaznacz przynajmniej jedną serię aby zobaczyć wykres.</p>
                    </div>
                `;
                ctx.parentElement.innerHTML += '<canvas id="progressChart"></canvas>';
                return;
            }
            
            const setsParam = selectedSeries.join(',');
            fetch(`plany.php?get_chart_data=1&plan_exercise_id=${exerciseId}&selected_sets=${setsParam}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) return;
                    createCombinedChart(data);
                })
                .catch(error => console.error('Błąd:', error));
        }

        // Funkcja tworząca wykres kombinowany pokazujący zarówno ciężar jak i powtórzenia
        function createCombinedChart(rawData) {
            const ctx = document.getElementById('progressChart').getContext('2d');
            if (progressChart) progressChart.destroy();
            
            if (!rawData || rawData.length === 0) {
                showChartError('chart-line', 'Brak danych');
                return;
            }
            
            const seriesData = {};
            const allDates = new Set();
            
            rawData.forEach(item => {
                const seriesNum = item.set_number;
                const date = item.workout_date;
                
                if (!seriesData[seriesNum]) {
                    seriesData[seriesNum] = { reps: {}, weights: {} };
                }
                
                seriesData[seriesNum].reps[date] = parseInt(item.reps_completed) || 0;
                seriesData[seriesNum].weights[date] = parseFloat(item.weight_used) || 0;
                allDates.add(date);
            });
            
            const sortedDates = Array.from(allDates).sort();
            const hasWeightData = rawData.some(item => parseFloat(item.weight_used) > 0);
            const colors = ['#28a745', '#007bff', '#dc3545', '#ffc107', '#17a2b8', '#6f42c1'];
            const datasets = [];
            
            selectedSeries.sort((a, b) => a - b).forEach((seriesNum, index) => {
                if (!seriesData[seriesNum]) return;
                
                const repsData = sortedDates.map(date => seriesData[seriesNum].reps[date] || null);
                if (repsData.some(val => val !== null && val > 0)) {
                    datasets.push({
                        label: `Seria ${seriesNum} - Powtórzenia`,
                        data: repsData,
                        borderColor: colors[index % colors.length],
                        yAxisID: 'y',
                        ...getDatasetStyle()
                    });
                }
                
                if (hasWeightData) {
                    const weightData = sortedDates.map(date => seriesData[seriesNum].weights[date] || null);
                    if (weightData.some(val => val !== null && val > 0)) {
                        datasets.push({
                            label: `Seria ${seriesNum} - Ciężar (kg)`,
                            data: weightData,
                            borderColor: colors[index % colors.length],
                            borderDash: [5, 5],
                            yAxisID: 'y1',
                            ...getDatasetStyle()
                        });
                    }
                }
            });
            
            const scales = {
                x: { title: { display: true, text: 'Data treningu' }, grid: { display: true } },
                y: { 
                    type: 'linear', position: 'left', beginAtZero: true,
                    title: { display: true, text: 'Powtórzenia' },
                    ticks: { stepSize: 1 }
                }
            };
            
            if (hasWeightData) {
                scales.y1 = {
                    type: 'linear', position: 'right', beginAtZero: true,
                    title: { display: true, text: 'Ciężar (kg)' },
                    grid: { drawOnChartArea: false },
                    ticks: { stepSize: 2.5 }
                };
            }
            
            progressChart = new Chart(ctx, {
                type: 'line',
                data: { labels: formatDates(sortedDates), datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: { display: true, text: `Ciężar i powtórzenia: seria ${selectedSeries.join(', ')}`, font: { size: 16 } },
                        legend: { display: true, position: 'top' },
                        tooltip: { mode: 'index', intersect: false }
                    },
                    scales,
                    interaction: { intersect: false, mode: 'index' }
                }
            });
        }

        // Funkcja zwracająca wspólne właściwości stylu dla linii wykresu
        function getDatasetStyle() {
            return {
                borderWidth: 3,
                pointRadius: 6,
                pointHoverRadius: 8,
                fill: false,
                tension: 0.1,
                spanGaps: false
            };
        }

        // Funkcja formatująca daty do krótkiego formatu dla osi X wykresu
        function formatDates(dates) {
            return dates.map(date => {
                const d = new Date(date);
                return d.toLocaleDateString('pl-PL', { month: 'short', day: 'numeric' });
            });
        }

        // Funkcja wyświetlająca komunikat błędu w miejscu wykresu
        function showChartError(icon, message) {
            const ctx = document.getElementById('progressChart');
            ctx.parentElement.innerHTML = `
                <div class="text-center text-muted" style="height: 400px; display: flex; align-items: center; justify-content: center; flex-direction: column;">
                    <i class="fas fa-${icon} fa-3x mb-3"></i>
                    <h5>${message}</h5>
                </div>
            `;
            ctx.parentElement.innerHTML += '<canvas id="progressChart"></canvas>';
        }
    </script>

    <!-- Stopka -->
    <?php require_once 'footer.php'; ?>
    
</body>
</html>

<?php mysqli_close($connection); ?>