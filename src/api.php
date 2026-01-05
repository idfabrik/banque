<?php
$file = 'data.json';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo file_exists($file) ? file_get_contents($file) : json_encode([]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = file_get_contents('php://input');
    if (json_decode($data) !== null) {
        file_put_contents($file, $data);
        echo json_encode(['status' => 'success']);
    }
}