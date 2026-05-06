<?php
require_once __DIR__ . '/app/bootstrap/session.php';

start_session();
session_unset();
session_destroy();
header('Location: logowanie.php');
?>