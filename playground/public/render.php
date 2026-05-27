<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(0);

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');

$shutdownHandled = false;
register_shutdown_function(function () use (&$shutdownHandled): void {
    if ($shutdownHandled) {
        return;
    }
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => 'Internal server error']);
    }
});

require dirname(__DIR__) . '/bootstrap.php';

use PhpMarkdown\Exception\ParseException;
use PhpMarkdown\MarkdownParser;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body = (string) file_get_contents('php://input');

if (strlen($body) > 65536) {
    http_response_code(413);
    echo json_encode(['error' => 'Request body exceeds 64 KB limit']);
    exit;
}

$data = json_decode($body, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit;
}

if (!isset($data['markdown']) || !is_string($data['markdown'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid "markdown" key; expected a string']);
    exit;
}

try {
    $parser = new MarkdownParser(maxBytes: 65536, linkTarget: '_blank', linkRel: 'noopener noreferrer');
    $html = $parser->parse($data['markdown']);
} catch (ParseException $e) {
    http_response_code(413);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

$shutdownHandled = true;
http_response_code(200);
echo json_encode(['html' => $html]);
