<?php

header('Content-Type: application/json');

// Define the file path for our simple JSON database
$file = 'players.json';

// Handle POST requests (saving player data or scores)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400); // Bad Request
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input.']);
        exit;
    }

    // Load existing data or initialize an empty array
    if (file_exists($file)) {
        $players_data = json_decode(file_get_contents($file), true);
        if ($players_data === null) {
            $players_data = []; // Handle corrupted JSON file
        }
    } else {
        $players_data = [];
    }

    // Find the player by their ID
    $found = false;
    foreach ($players_data as &$player) {
        if ($player['playerId'] === $data['playerId']) {
            $found = true;
            // Update player's general info (name, superpower)
            if (isset($data['playerName'])) {
                $player['playerName'] = $data['playerName'];
            }
            if (isset($data['playerSuperpower'])) {
                $player['playerSuperpower'] = $data['playerSuperpower'];
            }
            
            // Check if scores are being submitted
            if (isset($data['scores'])) {
                $player['scores'] = $data['scores'];
            }
            
            // Recalculate the total score based on the 'scores' object
            $totalScore = 0;
            if (isset($player['scores'])) {
                foreach ($player['scores'] as $score) {
                    $totalScore += $score;
                }
            }
            $player['totalScore'] = $totalScore;

            break;
        }
    }
    unset($player); // break reference

    // If player not found, add them
    if (!$found) {
        $new_player = [
            'playerId' => $data['playerId'],
            'playerName' => $data['playerName'],
            'playerSuperpower' => $data['playerSuperpower'],
            'totalScore' => 0,
            'scores' => []
        ];
        
        // If a score is submitted with the new player, add it
        if (isset($data['scores'])) {
            $new_player['scores'] = $data['scores'];
            $new_player['totalScore'] = array_sum($data['scores']);
        }
        
        $players_data[] = $new_player;
    }
    
    // Save the updated data back to the file
    if (file_put_contents($file, json_encode($players_data, JSON_PRETTY_PRINT)) === false) {
        http_response_code(500); // Internal Server Error
        echo json_encode(['success' => false, 'message' => 'Could not write to file. Check permissions.']);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Player data saved successfully.']);
    
} 
// Handle GET requests (retrieving leaderboard data)
else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (file_exists($file)) {
        $players_data = json_decode(file_get_contents($file), true);
        if ($players_data === null) {
            $players_data = []; // Handle corrupted JSON
        }
    } else {
        $players_data = [];
    }
    
    echo json_encode($players_data);
    
} else {
    // Handle unsupported request methods
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
}