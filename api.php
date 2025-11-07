<?php
require __DIR__ . '/vendor/autoload.php';

use Google\Cloud\Dialogflow\V2\SessionsClient;
use Google\Cloud\Dialogflow\V2\TextInput;
use Google\Cloud\Dialogflow\V2\QueryInput;
use Dotenv\Dotenv;

// Cargar .env
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

header('Content-Type: application/json');

// Recibir JSON del frontend
$input = json_decode(file_get_contents('php://input'), true);
$userInput = $input['mensaje'] ?? '';

if (!$userInput) {
    echo json_encode(['error' => 'Mensaje vacío']);
    exit;
}

// 1. DETECTAR MATRÍCULA
preg_match('/\b(\d{8})\b/', $userInput, $match);
$matricula = $match[1] ?? null;

// 2. SI HAY MATRÍCULA → CONSULTAR BD DIRECTAMENTE (sin webhook)
if ($matricula) {
    try {
        $pdo = new PDO(
            "mysql:host=" . $_ENV["DB_HOST"] . ";dbname=" . $_ENV["DB_NAME"],
            $_ENV["DB_USER"],
            $_ENV["DB_PASS"],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $st = $pdo->prepare("SELECT nombre, carrera, cuatrimestre FROM alumno WHERE matricula = ?");
        $st->execute([$matricula]);
        $alumno = $st->fetch(PDO::FETCH_ASSOC);

        if ($alumno) {
            echo json_encode([
                "respuesta" =>
                    "Hola " . $alumno["nombre"] .
                    ", tu carrera es " . $alumno["carrera"] .
                    " y tu cuatrimestre actual es " . $alumno["cuatrimestre"] . "."
            ]);
        } else {
            echo json_encode([
                "respuesta" => "No encontré información para la matrícula $matricula."
            ]);
        }

        exit;

    } catch (Exception $e) {
        echo json_encode(["respuesta" => "Error de BD: " . $e->getMessage()]);
        exit;
    }
}

// 3. SIN MATRÍCULA → USAR DIALOGFLOW NORMAL
putenv('GOOGLE_APPLICATION_CREDENTIALS=' . __DIR__ . '/clave.json');

$projectId = 'utconnect-lniw';

try {
    session_start();
    $sessionId = session_id();

    $sessionsClient = new SessionsClient();
    $session = $sessionsClient->sessionName($projectId, $sessionId);

    $textInput = new TextInput();
    $textInput->setText($userInput);
    $textInput->setLanguageCode('es');

    $queryInput = new QueryInput();
    $queryInput->setText($textInput);

    $response = $sessionsClient->detectIntent($session, $queryInput);
    $responseText = $response->getQueryResult()->getFulfillmentText();

    echo json_encode([
        "respuesta" => $responseText ?: "No tengo información para tu consulta."
    ]);

} catch (Exception $e) {
    echo json_encode([
        'error' => "Error al consultar Dialogflow: " . $e->getMessage()
    ]);
}
