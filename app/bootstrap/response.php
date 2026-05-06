<?php declare(strict_types=1);

function json_response(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

function json_error(string $message, int $statusCode = 400): void {
    json_response(['success' => false, 'error' => $message], $statusCode);
}

function redirect_to(string $url): void {
    header('Location: ' . $url);
    exit();
}
