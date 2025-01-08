<?php

require_once 'GameFunctions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['method'])) {
        echo json_encode(["success" => false, "message" => "'method' parameter is required."]);
        exit;
    }

    $method = $input['method'];

    try {
        switch ($method) {

            case 'register':
                if (isset($input['username']) && isset($input['password'])) {
                    registerPlayer($input['username'], $input['password']);
                } else {
                    echo json_encode(["success" => false, "message" => "'username' and 'password' are required for registration."]);
                }
                break;

            case 'login':
                if (isset($input['username']) && isset($input['password'])) {
                    loginPlayer($input['username'], $input['password']);
                    // debugSession();
                } else {
                    echo json_encode(["success" => false, "message" => "'username' and 'password' are required for login."]);
                }
                break;

            case 'createGame':
                try {
                    // Ensure necessary inputs are provided
                    if (!isset($input['player1Id'], $input['player2Id'], $input['player1Token'], $input['player2Token'])) {
                        echo json_encode([
                            "success" => false,
                            "message" => "Player1Id, Player2Id, Player1Token, and Player2Token are required."
                        ]);
                        break;
                    }

                    $player1Id = $input['player1Id'];
                    $player2Id = $input['player2Id'];
                    $player1Token = $input['player1Token'];
                    $player2Token = $input['player2Token'];

                    $conn = getDatabaseConnection();

                    // Call InitializeGame stored procedure
                    $stmt = $conn->prepare("CALL InitializeGame(?, ?, ?, ?, @gameId, @gameToken)");
                    if (!$stmt) {
                        throw new Exception("Failed to prepare stored procedure: " . $conn->error);
                    }

                    $stmt->bind_param("iiss", $player1Id, $player2Id, $player1Token, $player2Token);
                    if (!$stmt->execute()) {
                        throw new Exception("Failed to execute stored procedure: " . $stmt->error);
                    }

                    // Clear any remaining results
                    while ($conn->more_results() && $conn->next_result()) {
                        if ($result = $conn->store_result()) {
                            $result->free();
                        }
                    }

                    // Fetch the output parameters
                    $result = $conn->query("SELECT @gameId AS game_id, @gameToken AS game_token");
                    if (!$result) {
                        throw new Exception("Failed to fetch output parameters.");
                    }

                    $gameData = $result->fetch_assoc();
                    $gameId = $gameData['game_id'];
                    $gameToken = $gameData['game_token'];

                    // Store game data in the session
                    $_SESSION['gameId'] = $gameId;
                    $_SESSION['gameToken'] = $gameToken;

                    // Display success message
                    echo json_encode([
                        "success" => true,
                        "message" => "Game created successfully.",
                        "gameId" => $gameId,
                        "gameToken" => $gameToken
                    ]);

                    // Call setupGame to prepare the game environment
                    setupGame();
                } catch (Exception $e) {
                    echo json_encode([
                        "success" => false,
                        "message" => $e->getMessage()
                    ]);
                } finally {
                    if (isset($conn)) {
                        $conn->close();
                    }
                }
                break;

            case 'makeMove':
                if (isset($_POST['game_id']) && isset($_POST['player_id']) &&
                        isset($_POST['piece_id']) && isset($_POST['startX']) && isset($_POST['startY'])) {

                    $gameId = $_POST['game_id'];
                    $playerId = $_POST['player_id'];
                    $pieceId = $_POST['piece_id'];
                    $startX = $_POST['startX'];
                    $startY = $_POST['startY'];

                    makeMove($gameId, $playerId, $pieceId, $startX, $startY);
                } else {
                    echo "Error: Missing parameters. Required: 'game_id', 'player_id', 'piece_id', 'startX', 'startY'.";
                }
                break;

            default:
                echo json_encode(["success" => false, "message" => "Unknown method '$method'."]);
                break;
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    }
} else {
    echo json_encode(["success" => false, "message" => "Invalid request method. Please use POST."]);
}

function registerPlayer($username, $password) {
    try {
        $conn = getDatabaseConnection();

        // Call the stored procedure for registration
        $stmt = $conn->prepare("CALL RegisterPlayer(?, ?, @playerId)");
        $stmt->bind_param("ss", $username, $password);
        if (!$stmt->execute()) {
            throw new Exception("Failed to register player: " . $stmt->error);
        }

        // Fetch the output parameter
        $result = $conn->query("SELECT @playerId AS playerId");
        if (!$result) {
            throw new Exception("Failed to fetch player ID after registration.");
        }

        $playerData = $result->fetch_assoc();
        if (!isset($playerData['playerId'])) {
            throw new Exception("Player ID not returned from stored procedure.");
        }

        echo json_encode([
            "success" => true,
            "message" => "Player registered successfully.",
            "playerId" => $playerData['playerId'],
            "username" => $username
        ]);
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => $e->getMessage()
        ]);
    } finally {
        if (isset($conn)) {
            $conn->close();
        }
    }
}

function loginPlayer($username, $password) {
    try {
        $conn = getDatabaseConnection();

        // Call the stored procedure for login
        $stmt = $conn->prepare("CALL LoginPlayer(?, ?, @playerId)");
        $stmt->bind_param("ss", $username, $password);
        if (!$stmt->execute()) {
            throw new Exception("Failed to log in player: " . $stmt->error);
        }

        // Process all result sets to avoid 'commands out of sync' error
        while ($conn->more_results() && $conn->next_result()) {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        }

        // Fetch the player ID from the output parameter
        $result = $conn->query("SELECT @playerId AS playerId, token FROM Players WHERE ID = @playerId");
        if (!$result) {
            throw new Exception("Failed to fetch player ID and token during login.");
        }

        $playerData = $result->fetch_assoc();
        if (!isset($playerData['playerId'], $playerData['token'])) {
            throw new Exception("Player ID or token not returned from stored procedure.");
        }

        // Store in session
        $_SESSION['playerId'] = $playerData['playerId'];
        $_SESSION['username'] = $username;
        $_SESSION['token'] = $playerData['token'];

        // Send response
        echo json_encode([
            "success" => true,
            "message" => "Player logged in successfully.",
            "playerId" => $playerData['playerId'],
            "username" => $username,
            "token" => $playerData['token']
        ]);
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => $e->getMessage()
        ]);
    } finally {
        if (isset($conn)) {
            $conn->close();
        }
    }
}

function generateToken() {
    return hash('sha256', random_bytes(32));
}

function debugSession() {
    echo "<pre>";
    print_r($_SESSION);
    echo "</pre>";
}

?>