<?php declare(strict_types=1); ?>
<?php

// ==================== Bootstrap / Init ====================
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/app/bootstrap/session.php';
require_once __DIR__ . '/app/bootstrap/db.php';

start_session();
require_login();

// Połączenie z bazą danych
$connection = db_connect("Błąd połączenia z bazą danych:");

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

// ==================== AJAX Endpoints ====================

// --- POST: Kasowanie wiadomości (tylko admin)
if (isset($_POST['delete_message']) && $_SESSION['role'] === 'admin') {
    if (is_demo_user()) {
        deny_demo_write();
    }
    json_header();
    
    $message_id = get_int($_POST, 'message_id');
    
    if ($message_id > 0) {
        $delete_query = "DELETE FROM messages WHERE id = ? AND receiver_id IS NULL";
        $stmt = mysqli_prepare($connection, $delete_query);
        mysqli_stmt_bind_param($stmt, "i", $message_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $affected_rows = mysqli_stmt_affected_rows($stmt);
            if ($affected_rows > 0) {
                echo json_encode(['success' => true, 'message' => 'Wiadomość została usunięta']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Nie znaleziono wiadomości do usunięcia']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Błąd podczas usuwania wiadomości']);
        }
        mysqli_stmt_close($stmt);
    } else {
        echo json_encode(['success' => false, 'message' => 'Nieprawidłowy ID wiadomości']);
    }
    
    mysqli_close($connection);
    exit();
}

// --- POST: Wysyłanie publicznych wiadomości przez AJAX
if (isset($_POST['ajax_public_message'])) {
    if (is_demo_user()) {
        deny_demo_write();
    }
    json_header();
    
    $message = trim($_POST['message'] ?? '');
    $user_id = get_user_id();
    $file_path = '';
    
    // Obsługa przesyłania pliku
    if (isset($_FILES['public_file']) && $_FILES['public_file']['error'] == UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_name = basename($_FILES['public_file']['name']);
        $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'mp3', 'pdf', 'doc', 'docx', 'txt'];
        
        if (in_array($file_extension, $allowed_extensions)) {
            $new_file_name = time() . '_' . $file_name;
            $target_path = $upload_dir . $new_file_name;
            
            if (move_uploaded_file($_FILES['public_file']['tmp_name'], $target_path)) {
                $file_path = $target_path;
            } else {
                echo json_encode(['success' => false, 'error' => 'Błąd przesyłania pliku']);
                mysqli_close($connection);
                exit();
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Niedozwolony format pliku. Dozwolone: ' . implode(', ', $allowed_extensions)]);
            mysqli_close($connection);
            exit();
        }
    }
    
    // Sprawdzanie, czy wiadomość lub plik nie są puste
    if (empty($message) && empty($file_path)) {
        echo json_encode(['success' => false, 'error' => 'Wiadomość lub plik są wymagane']);
        mysqli_close($connection);
        exit();
    }
    
    // Ustawienie aktualnej daty i czasu
    $current_datetime = date('Y-m-d H:i:s');
    
    // Zapis do bazy (receiver_id = NULL dla publicznych wiadomości)
    $stmt = mysqli_prepare($connection, "INSERT INTO messages (user_id, receiver_id, message, file_path, datetime, is_read) VALUES (?, NULL, ?, ?, ?, 0)");
    mysqli_stmt_bind_param($stmt, "isss", $user_id, $message, $file_path, $current_datetime);
    
    if (mysqli_stmt_execute($stmt)) {
        $new_message_id = mysqli_insert_id($connection);
        echo json_encode([
            'success' => true, 
            'message' => 'Wiadomość wysłana',
            'message_id' => $new_message_id
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Błąd zapisywania wiadomości: ' . mysqli_error($connection)]);
    }
    
    mysqli_stmt_close($stmt);
    mysqli_close($connection);
    exit();
}

// --- GET: Wyszukiwanie użytkowników do prywatnej rozmowy
if (isset($_GET['search_users'])) {
    json_header();
    
    $search = mysqli_real_escape_string($connection, $_GET['search']);
    $current_user_id = get_user_id();
    
    $query = "SELECT id, username FROM users WHERE id != $current_user_id AND username LIKE '%$search%' LIMIT 10";
    $result = mysqli_query($connection, $query);
    
    $users = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $users[] = [
            'id' => $row['id'],
            'username' => htmlspecialchars($row['username'])
        ];
    }
    
    echo json_encode($users);
    mysqli_close($connection);
    exit();
}

// --- GET: Pobieranie listy prywatnych konwersacji
if (isset($_GET['get_conversations'])) {
    json_header();
    
    $current_user_id = get_user_id();
    
    $query = "SELECT DISTINCT
                CASE 
                    WHEN m.user_id = $current_user_id THEN m.receiver_id 
                    ELSE m.user_id 
                END as other_user_id,
                CASE 
                    WHEN m.user_id = $current_user_id THEN ru.username 
                    ELSE su.username 
                END as other_username,
                MAX(m.datetime) as last_message_time,
                COUNT(CASE WHEN m.receiver_id = $current_user_id AND m.is_read = 0 THEN 1 END) as unread_count
              FROM messages m
              LEFT JOIN users su ON m.user_id = su.id
              LEFT JOIN users ru ON m.receiver_id = ru.id
              WHERE (m.user_id = $current_user_id OR m.receiver_id = $current_user_id)
              AND m.receiver_id IS NOT NULL
              GROUP BY other_user_id, other_username
              ORDER BY last_message_time DESC";
    
    $result = mysqli_query($connection, $query);
    
    $conversations = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $conversations[] = [
            'user_id' => $row['other_user_id'],
            'username' => htmlspecialchars($row['other_username']),
            'last_message_time' => $row['last_message_time'],
            'unread_count' => $row['unread_count']
        ];
    }
    
    echo json_encode($conversations);
    mysqli_close($connection);
    exit();
}

// --- POST: Oznaczanie wiadomości jako przeczytane
if (isset($_POST['mark_as_read'])) {
    if (is_demo_user()) {
        deny_demo_write();
    }
    json_header();
    
    $other_user_id = get_int($_POST, 'other_user_id');
    $current_user_id = get_user_id();
    
    $stmt = mysqli_prepare($connection, "UPDATE messages SET is_read = 1 WHERE user_id = ? AND receiver_id = ? AND is_read = 0");
    mysqli_stmt_bind_param($stmt, "ii", $other_user_id, $current_user_id);
    
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
    
    mysqli_stmt_close($stmt);
    mysqli_close($connection);
    exit();
}

// --- GET: Obsługa AJAX dla publicznych wiadomości
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    json_header();

    $query = "SELECT 
        m.id,
        m.datetime, 
        u.username AS sender_name, 
        m.message, 
        m.file_path 
    FROM messages m
    JOIN users u ON m.user_id = u.id 
    WHERE m.receiver_id IS NULL
    ORDER BY m.datetime ASC
    LIMIT 30";

    $result = mysqli_query($connection, $query);
    if (!$result) {
        echo json_encode(["error" => mysqli_error($connection)]);
        mysqli_close($connection);
        exit();
    }

    $messages = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $messages[] = [
            "id" => $row['id'],
            "datetime" => $row['datetime'],
            "sender_name" => htmlspecialchars($row['sender_name']),
            "message" => htmlspecialchars($row['message'] ?? ''),
            "file_path" => $row['file_path'] ?? ''
        ];
    }

    echo json_encode($messages);
    mysqli_close($connection);
    exit();
}

// --- GET: Obsługa AJAX dla prywatnych wiadomości
if (isset($_GET['private_chat']) && isset($_GET['user_id'])) {
    json_header();

    $current_user_id = get_user_id();
    $other_user_id = get_int($_GET, 'user_id');

    $query = "SELECT 
            messages.datetime, 
            sender.username AS sender_name, 
            receiver.username AS receiver_name, 
            messages.message, 
            messages.file_path,
            messages.user_id
          FROM messages 
          JOIN users AS sender ON messages.user_id = sender.id 
          LEFT JOIN users AS receiver ON messages.receiver_id = receiver.id 
          WHERE (messages.user_id = $current_user_id AND messages.receiver_id = $other_user_id)
          OR (messages.user_id = $other_user_id AND messages.receiver_id = $current_user_id)
          ORDER BY messages.datetime ASC";

    $result = mysqli_query($connection, $query) or die(json_encode(["error" => mysqli_error($connection)]));

    $messages = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $messages[] = [
            "datetime" => date("Y-m-d H:i:s", strtotime($row['datetime'])),
            "sender_name" => htmlspecialchars($row['sender_name']),
            "receiver_name" => $row['receiver_name'] ? htmlspecialchars($row['receiver_name']) : '',
            "message" => htmlspecialchars($row['message']),
            "file_path" => $row['file_path'] ?? '',
            "user_id" => $row['user_id']
        ];
    }

    echo json_encode($messages);
    mysqli_close($connection);
    exit();
}

// --- POST: Obsługa wysyłania prywatnych wiadomości przez AJAX z plikiem
if (isset($_POST['ajax_private_message'])) {
    if (is_demo_user()) {
        deny_demo_write();
    }
    json_header();
    
    $message = trim($_POST['message'] ?? '');
    $receiver_id = get_int($_POST, 'receiver_id');
    $user_id = get_user_id();
    $file_path = '';
    
    // Obsługa przesyłania pliku
    if (isset($_FILES['private_file']) && $_FILES['private_file']['error'] == UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_name = basename($_FILES['private_file']['name']);
        $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'mp3', 'pdf', 'doc', 'docx', 'txt'];
        
        if (in_array($file_extension, $allowed_extensions)) {
            $new_file_name = time() . '_' . $file_name;
            $target_path = $upload_dir . $new_file_name;
            
            if (move_uploaded_file($_FILES['private_file']['tmp_name'], $target_path)) {
                $file_path = $target_path;
            } else {
                echo json_encode(['success' => false, 'error' => 'Błąd przesyłania pliku']);
                exit();
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Niedozwolony format pliku']);
            exit();
        }
    }
    
    // Sprawdzanie, czy wiadomość lub plik nie są puste
    if (empty($message) && empty($file_path)) {
        echo json_encode(['success' => false, 'error' => 'Wiadomość lub plik są wymagane']);
        exit();
    }
    
    if ($receiver_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Nieprawidłowy odbiorca']);
        exit();
    }
    
    // Ustawienie polskiej strefy czasowej
    $current_datetime = date('Y-m-d H:i:s');
    
    $stmt = mysqli_prepare($connection, "INSERT INTO messages (user_id, receiver_id, message, file_path, datetime, is_read) VALUES (?, ?, ?, ?, ?, 0)");
    mysqli_stmt_bind_param($stmt, "iisss", $user_id, $receiver_id, $message, $file_path, $current_datetime);
    
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Błąd wysyłania wiadomości']);
    }
    
    mysqli_stmt_close($stmt);
    mysqli_close($connection);
    exit();
}
// ==================== HTML / UI ====================
?>

<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="pl" lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="Komunikator">
    <meta name="author" content="Filarecki">
    <meta name="keywords" content="chat, komunikator, wiadomości">
    <title>Komunikator</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <!-- DataTables CSS -->
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.10.24/css/dataTables.bootstrap5.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Plik CSS -->
    <link rel="stylesheet" type="text/css" href="twoj_css.css">
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <!-- jQuery i DataTables JS -->
    <script type="text/javascript" src="https://code.jquery.com/jquery-3.5.1.js"></script>
    <script type="text/javascript" src="https://cdn.datatables.net/1.10.24/js/jquery.dataTables.min.js"></script>
    <script type="text/javascript" src="https://cdn.datatables.net/1.10.24/js/dataTables.bootstrap5.min.js"></script>
    <!-- Plik JS -->
    <script type="text/javascript" src="twoj_js.js"></script>

    <style>
        .private-chat-window {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 600px;
            height: 600px;
            background: white;
            border: 2px solid #007bff;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            z-index: 1000;
            display: none;
        }
        
        .private-chat-header {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            padding: 15px;
            border-radius: 8px 8px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .private-chat-body {
            height: 400px;
            overflow-y: auto;
            padding: 15px;
            border-bottom: 1px solid #ddd;
        }
        
        .private-chat-footer {
            padding: 15px;
            background: #f8f9fa;
            border-radius: 0 0 8px 8px;
        }
        
        .message-item {
            margin-bottom: 10px;
            padding: 8px 12px;
            border-radius: 15px;
            max-width: 80%;
        }
        
        .message-sent {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            margin-left: auto;
            text-align: right;
        }
        
        .message-received {
            background: linear-gradient(135deg, #e9ecef, #dee2e6);
            color: #333;
            margin-right: auto;
        }
        
        .close-chat {
            background: none;
            border: none;
            color: white;
            font-size: 20px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .close-chat:hover {
            transform: scale(1.2);
        }
        
        .conversation-btn {
            position: relative;
            margin: 5px;
            padding: 10px 15px;
            border: 1px solid #ced4da;
            background: #f8f9fa;
            color: #6c757d;
            border-radius: 20px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            font-weight: 400;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: all 0.2s ease;
        }
        
        .conversation-btn:hover {
            background: #e9ecef;
            color: #495057;
            border-color: #adb5bd;
            transform: translateY(-1px);
            box-shadow: 0 2px 5px rgba(0,0,0,0.15);
        }
        
        .conversation-btn.has-unread {
            border: 2px solid #007bff;
            background: linear-gradient(135deg, #e7f3ff, #cce7ff);
            color: #007bff;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(0,123,255,0.3);
        }
        
        .conversation-btn.has-unread:hover {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            border-color: #007bff;
        }
        
        .unread-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #007bff;
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 10px;
            font-weight: bold;
            min-width: 16px;
            height: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid white;
        }
        
        .user-icon {
            margin-right: 8px;
            font-size: 14px;
        }
        
        .search-container {
            position: relative;
            margin-bottom: 20px;
        }
        
        .search-input {
            padding-left: 45px;
            border-radius: 25px;
            border: 2px solid #007bff;
            transition: all 0.3s ease;
        }
        
        .search-input:focus {
            box-shadow: 0 0 10px rgba(0,123,255,0.3);
            border-color: #0056b3;
        }
        
        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #007bff;
            z-index: 10;
        }
        
        .search-results {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 15px;
            background: white;
            position: absolute;
            width: 100%;
            z-index: 1000;
            display: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .search-result-item {
            cursor: pointer;
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
        }
        
        .search-result-item:hover {
            background: #f8f9fa;
        }
        
        .search-result-item:last-child {
            border-bottom: none;
        }
        
        .conversations-section {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 30px;
            border: 1px solid #e9ecef;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .conversations-title {
            color: #495057;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }
        
        .conversations-title i {
            margin-right: 10px;
            color: #007bff;
        }
        
        .private-file-input {
            margin-top: 10px;
        }
        
        .file-preview {
            max-width: 100%;
            margin-top: 5px;
            font-size: 12px;
            color: #666;
        }
        
        .conversations-info {
            color: #6c757d;
            font-style: italic;
            text-align: center;
            padding: 20px 0;
        }
        
        .conversations-info i {
            margin-right: 8px;
        }
        
        .public-chat-section {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #e9ecef;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .chat-messages-container {
            height: 400px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 10px;
            padding: 15px;
            background: white;
            margin-bottom: 20px;
        }
        
        .public-message {
            margin-bottom: 15px;
            padding: 10px;
            border-radius: 10px;
            border-left: 4px solid #007bff;
            background: #ffffff;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .public-message.own-message {
            border-left-color: #007bff;
            background: #f0f8ff;
        }
        
        .message-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        
        .message-header .username {
            flex-grow: 1;
        }
        
        .message-header .timestamp {
            color: #666;
            font-size: 12px;
            margin-left: 10px;
        }
        
        .delete-message-btn {
            font-size: 11px !important;
            padding: 2px 6px !important;
            opacity: 0.7;
            transition: opacity 0.3s ease;
        }

        .delete-message-btn:hover {
            opacity: 1;
        }

        .message:hover .delete-message-btn {
            opacity: 1;
        }

        .message {
            transition: all 0.3s ease;
        }

        .position-fixed {
            position: fixed !important;
        }

        @media (max-width: 576px) {
            .delete-message-btn {
                font-size: 10px !important;
                padding: 1px 4px !important;
            }
            
            .message-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            
            .delete-message-btn {
                align-self: flex-end;
                margin-top: -20px;
            }
        }

        .admin-username {
            color: #dc3545 !important;
        }

        .admin-badge {
            font-size: 9px;
            padding: 2px 5px;
            margin-left: 5px;
        }
    </style>
</head>
<body onload="myLoadHeader()">
    <!-- Header -->
    <div id="myHeader" class="text-center"></div>

    <div class="container mt-4">
        <!-- Sekcja z konwersacjami i wyszukiwaniem -->
        <div class="conversations-section">
            <h5 class="conversations-title">
                <i class="fas fa-comments"></i>
                Prywatne rozmowy
            </h5>
            
            <!-- Wyszukiwanie użytkowników -->
            <div class="search-container">
                <i class="fas fa-search search-icon"></i>
                <input type="text" id="userSearch" class="form-control search-input" placeholder="      Wyszukaj nowego rozmówcę...">
                <div id="searchResults" class="search-results"></div>
            </div>
            
            <!-- Lista istniejących konwersacji jako przyciski -->
            <div id="conversationsList" class="d-flex flex-wrap">
                <div class="conversations-info">
                    <i class="fas fa-sync-alt fa-spin"></i>
                    Ładowanie konwersacji...
                </div>
            </div>
        </div>

        <!-- Sekcja czatu publicznego -->
        <div class="public-chat-section">
            <h5 class="conversations-title">
                <i class="fas fa-globe"></i>
                Czat publiczny
            </h5>
            
            <!-- Kontener z wiadomościami -->
            <div id="chatMessagesContainer" class="chat-messages-container">
                <!-- Tu wyświetlają się wiadomości -->
            </div>
            
            <!-- ⭐ ZMIENIONY FORMULARZ - bez action, z ID -->
            <div class="send-form">
                <form id="publicMessageForm" enctype="multipart/form-data">
                    <div class="row align-items-end">
                        <div class="col-md-6">
                            <label for="postInput" class="form-label">Wiadomość publiczna:</label>
                            <input type="text" class="form-control" id="postInput" name="post" maxlength="90" placeholder="Napisz wiadomość...">
                        </div>
                        <div class="col-md-4">
                            <label for="fileInput" class="form-label">Plik (opcjonalnie):</label>
                            <input type="file" class="form-control" id="fileInput" name="file">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-paper-plane"></i>
                                Wyślij
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Okno prywatnego czatu -->
    <div id="privateChatWindow" class="private-chat-window">
        <div class="private-chat-header">
            <h6 id="chatTitle">
                <i class="fas fa-user"></i>
                Rozmowa z użytkownikiem
            </h6>
            <button class="close-chat" onclick="closeChatWindow()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="private-chat-body" id="chatMessages">
            <!-- Tu wyświetlają się wiadomości -->
        </div>
        <div class="private-chat-footer">
            <form id="privateChatForm" enctype="multipart/form-data">
                <div class="mb-2">
                    <input type="text" id="privateMessageInput" class="form-control" placeholder="Napisz prywatną wiadomość..." maxlength="90">
                </div>
                <div class="mb-2">
                    <input type="file" id="privateFileInput" class="form-control private-file-input" accept=".jpg,.jpeg,.png,.gif,.mp4,.mp3,.pdf,.doc,.docx,.txt">
                    <div id="privateFilePreview" class="file-preview"></div>
                </div>
                <input type="hidden" id="privateReceiverId" value="">
                <div class="d-flex justify-content-between">
                    <button type="button" id="clearPrivateFile" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-trash"></i>
                        Usuń plik
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i>
                        Wyślij
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>

        // Zmienne globalne
        const currentUserId = <?php echo $_SESSION['user_id']; ?>;
        const currentUsername = '<?php echo addslashes($_SESSION['username']); ?>';
        const userRole = '<?php echo $_SESSION['role'] ?? 'user'; ?>';

        let currentChatUserId = null;
        let currentChatUsername = null;
        let privateChatInterval = null;
        let conversationsInterval = null;
        let shouldScrollToBottom = true;

        // Wyszukiwanie użytkowników
        function searchUsers(query) {
            if (query.length < 2) {
                document.getElementById('searchResults').style.display = 'none';
                return;
            }
            
            fetch(`komunikator.php?search_users=1&search=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(users => {
                    const resultsDiv = document.getElementById('searchResults');
                    resultsDiv.innerHTML = '';
                    
                    if (users.length > 0) {
                        users.forEach(user => {
                            const item = document.createElement('div');
                            item.className = 'search-result-item';
                            item.innerHTML = `<i class="fas fa-user user-icon"></i>${user.username}`;
                            item.onclick = () => {
                                openChatWindow(user.id, user.username);
                                resultsDiv.style.display = 'none';
                                document.getElementById('userSearch').value = '';
                            };
                            resultsDiv.appendChild(item);
                        });
                        resultsDiv.style.display = 'block';
                    } else {
                        resultsDiv.innerHTML = '<div class="search-result-item"><i class="fas fa-exclamation-circle user-icon"></i>Brak wyników</div>';
                        resultsDiv.style.display = 'block';
                    }
                })
                .catch(error => console.error('Błąd wyszukiwania:', error));
        }

        // Ładowanie listy konwersacji jako przyciski
        function loadConversations() {
            fetch('komunikator.php?get_conversations=1')
                .then(response => response.json())
                .then(conversations => {
                    const listDiv = document.getElementById('conversationsList');
                    listDiv.innerHTML = '';
                    
                    if (conversations.length === 0) {
                        listDiv.innerHTML = '<div class="conversations-info"><i class="fas fa-info-circle"></i> Brak aktywnych konwersacji. Wyszukaj kogoś aby rozpocząć rozmowę!</div>';
                        return;
                    }
                    
                    conversations.forEach((conv) => {
                        const button = document.createElement('button');
                        button.className = `conversation-btn ${conv.unread_count > 0 ? 'has-unread' : ''}`;
                        
                        let content = `<i class="fas fa-user user-icon"></i>${conv.username}`;
                        
                        if (conv.unread_count > 0) {
                            content += `<span class="unread-badge">${conv.unread_count}</span>`;
                        }
                        
                        button.innerHTML = content;
                        button.onclick = () => {
                            openChatWindow(conv.user_id, conv.username);
                        };
                        
                        // Czas ostatniej wiadomości
                        if (conv.last_message_time) {
                            button.title = `Ostatnia wiadomość: ${conv.last_message_time}`;
                        }
                        
                        listDiv.appendChild(button);
                    });
                })
                .catch(error => {
                    console.error('Błąd ładowania konwersacji:', error);
                    document.getElementById('conversationsList').innerHTML = '<div class="conversations-info text-danger"><i class="fas fa-exclamation-triangle"></i> Błąd ładowania konwersacji</div>';
                });
        }

        // Oznaczanie wiadomości jako przeczytane
        function markAsRead(otherUserId) {
            const formData = new FormData();
            formData.append('mark_as_read', '1');
            formData.append('other_user_id', otherUserId);
            
            fetch('komunikator.php', {
                method: 'POST',
                body: formData
            });
        }

        // Odświeżanie publicznych wiadomości
        function odswiezWiadomosci() {
            const container = document.getElementById('chatMessagesContainer');
            const wasAtBottom = container.scrollHeight - container.scrollTop <= container.clientHeight + 50;
        
            
            fetch(`komunikator.php?ajax=1`, { cache: "no-store" })
                .then(response => response.json()) 
                .then(data => {

                    container.innerHTML = "";

                    data.forEach(wiadomosc => {
                        const messageDiv = document.createElement("div");
                        const isOwnMessage = wiadomosc.sender_name === currentUsername;
                        
                        messageDiv.className = `public-message ${isOwnMessage ? 'own-message' : ''}`;
                        messageDiv.setAttribute('data-message-id', wiadomosc.id);
                        
                        let content = `
                            <div class="message-header">
                                <span class="message-sender ${isOwnMessage ? 'own' : ''}">${isOwnMessage ? 'Ty' : wiadomosc.sender_name}</span>
                                <span class="message-time">${wiadomosc.datetime}</span>
                        `;
                        
                        // Przycisk kasowania dla admina
                        if (userRole === 'admin') {
                            content += `
                                <button class="btn btn-outline-danger btn-sm ms-2 delete-message-btn" 
                                        onclick="deleteMessage(${wiadomosc.id})" 
                                        title="Usuń wiadomość (Admin)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            `;
                        }
                        
                        content += `
                            </div>
                            <div class="message-content">${wiadomosc.message || ''}</div>
                        `;

                        // Obsługa plików
                        if (wiadomosc.file_path && wiadomosc.file_path !== "") {
                            let file_extension = wiadomosc.file_path.split('.').pop().toLowerCase();
                            if (["jpg", "jpeg", "png", "gif"].includes(file_extension)) {
                                content += `<div class="mt-2"><img src="${wiadomosc.file_path}" style="max-width: 300px; border-radius: 8px;"></div>`;
                            } else if (file_extension === "mp4") {
                                content += `<div class="mt-2"><video style="max-width: 300px;" controls>
                                                <source src="${wiadomosc.file_path}" type="video/mp4">
                                            </video></div>`;
                            } else if (file_extension === "mp3") {
                                content += `<div class="mt-2"><audio controls>
                                                <source src="${wiadomosc.file_path}" type="audio/mpeg">
                                            </audio></div>`;
                            } else {
                                content += `<div class="mt-2"><a href="${wiadomosc.file_path}" download><i class="fas fa-download"></i> Pobierz plik</a></div>`;
                            }
                        }

                        messageDiv.innerHTML = content;
                        container.appendChild(messageDiv);
                    });
                    
                    if (wasAtBottom || shouldScrollToBottom) {
                        container.scrollTop = container.scrollHeight;
                        shouldScrollToBottom = false;
                    }
                })
                .catch(error => console.error("Błąd pobierania publicznych wiadomości:", error));
        }

        function openChatWindow(userId, username) {
            currentChatUserId = userId;
            currentChatUsername = username;
            
            document.getElementById('chatTitle').innerHTML = `<i class="fas fa-user"></i> Rozmowa z ${username}`;
            document.getElementById('privateReceiverId').value = userId;
            document.getElementById('privateChatWindow').style.display = 'block';
            
            // Oznaczanie wiadomości jako przeczytane
            markAsRead(userId);
            
            loadPrivateMessages(userId);
            
            // Odświeżanie wiadomości co 2 sekundy
            if (privateChatInterval) {
                clearInterval(privateChatInterval);
            }
            privateChatInterval = setInterval(() => loadPrivateMessages(userId), 2000);
        }

        function closeChatWindow() {
            document.getElementById('privateChatWindow').style.display = 'none';
            currentChatUserId = null;
            currentChatUsername = null;
            
            // Czyszczenie formularza
            document.getElementById('privateMessageInput').value = '';
            document.getElementById('privateFileInput').value = '';
            document.getElementById('privateFilePreview').innerHTML = '';
            
            if (privateChatInterval) {
                clearInterval(privateChatInterval);
                privateChatInterval = null;
            }
            
            // Odświeżanie listy konwersacji
            loadConversations();
        }

        function loadPrivateMessages(userId) {
            fetch(`komunikator.php?private_chat=1&user_id=${userId}`, { cache: "no-store" })
                .then(response => response.json())
                .then(data => {
                    const chatMessages = document.getElementById('chatMessages');
                    chatMessages.innerHTML = '';
                    
                    data.forEach(message => {
                        const messageDiv = document.createElement('div');
                        const isCurrentUser = message.user_id == currentUserId;
                        
                        messageDiv.className = `message-item ${isCurrentUser ? 'message-sent' : 'message-received'}`;
                        
                        let content = `<div><strong>${isCurrentUser ? 'Ty' : message.sender_name}</strong></div>`;
                        content += `<div>${message.message}</div>`;
                        content += `<small>${message.datetime}</small>`;
                        
                        if (message.file_path && message.file_path !== "") {
                            let file_extension = message.file_path.split('.').pop().toLowerCase();
                            if (["jpg", "jpeg", "png", "gif"].includes(file_extension)) {
                                content += `<br><img src="${message.file_path}" style="max-width: 150px; border-radius: 8px;">`;
                            } else if (file_extension === "mp4") {
                                content += `<br><video style="max-width: 150px;" controls>
                                                <source src="${message.file_path}" type="video/mp4">
                                            </video>`;
                            } else if (file_extension === "mp3") {
                                content += `<br><audio controls>
                                                <source src="${message.file_path}" type="audio/mpeg">
                                            </audio>`;
                            } else {
                                content += `<br><a href="${message.file_path}" download>📎 Pobierz plik</a>`;
                            }
                        }
                        
                        messageDiv.innerHTML = content;
                        chatMessages.appendChild(messageDiv);
                    });
                    
                    // Przewijanie na dół
                    chatMessages.scrollTop = chatMessages.scrollHeight;
                })
                .catch(error => console.error("Błąd ładowania prywatnych wiadomości:", error));
        }

        // Wysyłanie prywatnych wiadomości przez AJAX z plikiem)
        function sendPrivateMessage(message, receiverId, file) {
            const formData = new FormData();
            formData.append('ajax_private_message', '1');
            formData.append('message', message);
            formData.append('receiver_id', receiverId);
            
            if (file) {
                formData.append('private_file', file);
            }
            
            return fetch('komunikator.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadPrivateMessages(currentChatUserId);
                    return true;
                } else {
                    console.error('Błąd wysyłania wiadomości:', data.error);
                    showNotification('Błąd: ' + data.error, 'error');
                    return false;
                }
            })
            .catch(error => {
                console.error('Błąd wysyłania wiadomości:', error);
                return false;
            });
        }

        // Funkcja kasowania wiadomości tylko dla admina
        function deleteMessage(messageId) {
            if (userRole !== 'admin') {
                showNotification('Nie masz uprawnień do usuwania wiadomości!', 'error');
                return;
            }
            
            if (!confirm('Czy na pewno chcesz usunąć tę wiadomość? Ta akcja jest nieodwracalna.')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('delete_message', '1');
            formData.append('message_id', messageId);
            
            fetch('komunikator.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Usuwanie wiadomości z interfejsu bez przeładowania
                    const messageElement = document.querySelector(`[data-message-id="${messageId}"]`);
                    if (messageElement) {
                        messageElement.style.opacity = '0.5';
                        messageElement.style.transform = 'scale(0.8)';
                        setTimeout(() => {
                            messageElement.remove();
                        }, 300);
                    }
                    
                    // Pokazywanie komunikatu sukcesu
                    showNotification('Wiadomość została usunięta', 'success');
                } else {
                    showNotification('Błąd: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Błąd usuwania wiadomości:', error);
                showNotification('Wystąpił błąd podczas usuwania wiadomości', 'error');
            });
        }

        // Funkcja pokazywania powiadomień
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            const alertType = type === 'success' ? 'success' : (type === 'error' ? 'danger' : 'info');
            const iconType = type === 'success' ? 'check-circle' : (type === 'error' ? 'exclamation-triangle' : 'info-circle');
            
            notification.className = `alert alert-${alertType} alert-dismissible fade show position-fixed`;
            notification.style.cssText = `
                top: 20px; 
                right: 20px; 
                z-index: 9999; 
                min-width: 300px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            `;
            
            notification.innerHTML = `
                <i class="fas fa-${iconType}"></i> 
                ${message}
                <button type="button" class="btn-close" onclick="this.parentElement.remove()"></button>
            `;
            
            document.body.appendChild(notification);
            
            // Automatyczne usuwanie po 3 sekundach
            setTimeout(() => {
                if (notification.parentElement) {
                    notification.remove();
                }
            }, 3000);
        }

        // Obsługa przycisków czatu
        document.addEventListener('DOMContentLoaded', function() {
            // Wyszukiwanie użytkowników
            document.getElementById('userSearch').addEventListener('input', function() {
                const query = this.value.trim();
                searchUsers(query);
            });

            // Ukrywanie wyników wyszukiwania po kliknięciu poza pole wyszukiwania
            document.addEventListener('click', function(e) {
                if (!e.target.closest('#userSearch') && !e.target.closest('#searchResults')) {
                    document.getElementById('searchResults').style.display = 'none';
                }
            });

            // Podgląd wybranego pliku w prywatnym czacie
            document.getElementById('privateFileInput').addEventListener('change', function() {
                const file = this.files[0];
                const preview = document.getElementById('privateFilePreview');
                
                if (file) {
                    preview.innerHTML = `<i class="fas fa-file"></i> Wybrany plik: ${file.name} (${(file.size / 1024).toFixed(1)} KB)`;
                } else {
                    preview.innerHTML = '';
                }
            });

            // Przycisk usuwania pliku
            document.getElementById('clearPrivateFile').addEventListener('click', function() {
                document.getElementById('privateFileInput').value = '';
                document.getElementById('privateFilePreview').innerHTML = '';
            });

            // Formularz wysyłania prywatnej wiadomości
            document.getElementById('privateChatForm').addEventListener('submit', function(e) {
                e.preventDefault();
                
                const message = document.getElementById('privateMessageInput').value.trim();
                const receiverId = document.getElementById('privateReceiverId').value;
                const file = document.getElementById('privateFileInput').files[0];
                
                if (!message && !file) {
                    showNotification('Wpisz wiadomość lub wybierz plik!', 'error');
                    return;
                }
                
                // Wysyłanie wiadomości przez AJAX
                sendPrivateMessage(message, receiverId, file).then(success => {
                    if (success) {
                        document.getElementById('privateMessageInput').value = '';
                        document.getElementById('privateFileInput').value = '';
                        document.getElementById('privateFilePreview').innerHTML = '';
                    }
                });
            });

            // ⭐ NOWY HANDLER - Obsługa wysyłania publicznych wiadomości przez AJAX
            document.getElementById('publicMessageForm').addEventListener('submit', function(e) {
                e.preventDefault(); // Zapobieganie przeładowaniu strony
                
                const messageInput = document.getElementById('postInput');
                const fileInput = document.getElementById('fileInput');
                const message = messageInput.value.trim();
                const file = fileInput.files[0];
                
                // Walidacja - przynajmniej wiadomość LUB plik
                if (!message && !file) {
                    showNotification('Wpisz wiadomość lub wybierz plik!', 'error');
                    return;
                }
                
                // Przygotowanie FormData
                const formData = new FormData();
                formData.append('ajax_public_message', '1');
                formData.append('message', message);
                
                if (file) {
                    // Sprawdzanie rozmiaru pliku (np. max 5MB)
                    if (file.size > 5 * 1024 * 1024) {
                        showNotification('Plik jest za duży! Maksymalny rozmiar to 5MB', 'error');
                        return;
                    }
                    formData.append('public_file', file);
                }
                
                // Wyłączenie przycisku podczas wysyłania
                const submitButton = this.querySelector('button[type="submit"]');
                const originalButtonHTML = submitButton.innerHTML;
                submitButton.disabled = true;
                submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Wysyłanie...';
                
                // Wysłanie żądania AJAX
                fetch('komunikator.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Błąd serwera: ' + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        // Czyszczenie formularza
                        messageInput.value = '';
                        fileInput.value = '';
                        
                        // Odświeżenie wiadomości i przewinięcie na dół
                        shouldScrollToBottom = true;
                        odswiezWiadomosci();
                        
                        showNotification('Wiadomość wysłana!', 'success');
                    } else {
                        showNotification('Błąd: ' + (data.error || 'Nieznany błąd'), 'error');
                    }
                })
                .catch(error => {
                    console.error('Błąd wysyłania wiadomości:', error);
                    showNotification('Wystąpił błąd podczas wysyłania wiadomości', 'error');
                })
                .finally(() => {
                    // Przywrócenie przycisku
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalButtonHTML;
                });
            });

            // Ładowanie konwersacji przy starcie
            loadConversations();
            
            // Ładowanie publicznych wiadomości
            odswiezWiadomosci();
        });

        // Odświeżanie publicznych wiadomości co 5 sekund
        setInterval(odswiezWiadomosci, 5000);

        // Odświeżanie listy konwersacji co 8 sekund
        conversationsInterval = setInterval(loadConversations, 8000);
    </script>

    <!-- Stopka -->
    <?php require_once 'footer.php'; ?>

</body>
</html>

<?php mysqli_close($connection); ?>