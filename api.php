<?php
/**
 * JSON API used by assets/app.js.
 *
 *   GET  api.php?action=question&area=europe&lang=sv   -> a new country to guess
 *   POST api.php?action=answer&lang=sv  {code} or {name} -> was it correct?
 *   GET  api.php?action=names&lang=sv                   -> all country names (autocomplete)
 *   POST api.php?action=reset                           -> reset the score
 */

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use CountryQuiz\CountryRepository;
use CountryQuiz\Quiz;

// Compress responses when possible: map outlines compress very well (~4x), which matters on mobile.
if (extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
    ob_start('ob_gzhandler');
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/**
 * Sends a JSON response and stops.
 */
function respond(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

$action = (string) ($_GET['action'] ?? '');
$language = ($_GET['lang'] ?? 'en') === 'sv' ? 'sv' : 'en';
$area = (string) ($_GET['area'] ?? 'world');
if (!CountryRepository::isValidArea($area)) {
    $area = 'world';
}
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

session_start();
$quiz = new Quiz(new CountryRepository(COUNTRY_DATA_FILE), $_SESSION);

try {
    switch ($action) {
        case 'question':
            respond($quiz->newQuestion($area, $language));

        case 'answer':
            if (!$isPost) {
                respond(['error' => 'Use POST'], 405);
            }
            $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
            $chosenCode = isset($body['code']) && is_string($body['code']) ? $body['code'] : null;
            $typedName = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : null;
            // Country names are short; anything longer is not a real answer.
            if ($typedName !== null && strlen($typedName) > 200) {
                $typedName = substr($typedName, 0, 200);
            }
            if ($chosenCode === null && $typedName === null) {
                respond(['error' => 'Send either "code" or "name"'], 400);
            }
            $questionId = isset($body['questionId']) && is_string($body['questionId']) ? $body['questionId'] : '';
            respond($quiz->answer($questionId, $chosenCode, $typedName, $language));

        case 'names':
            respond(['names' => $quiz->allNames($language)]);

        case 'reset':
            if (!$isPost) {
                respond(['error' => 'Use POST'], 405);
            }
            $quiz->resetScore();
            respond(['score' => $quiz->score()]);

        default:
            respond(['error' => 'Unknown action'], 404);
    }
} catch (RuntimeException $exception) {
    respond(['error' => $exception->getMessage()], 409);
}
