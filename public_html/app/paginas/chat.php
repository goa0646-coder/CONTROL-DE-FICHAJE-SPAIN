<?php
// Slack: comando /inda con Request URL ?p=chat&canal=slack. Teams: webhook saliente con URL ?p=chat&canal=teams.
// Secretos en config-inda.php: 'slack_secreto' (Signing Secret) y 'teams_secreto' (token HMAC del webhook).
header('Content-Type: application/json; charset=utf-8');
$cuerpo = file_get_contents('php://input');
$canal = $_GET['canal'] ?? '';
$c = config();
try {
    if ($canal === 'slack') {
        if (!firma_slack_ok($c['slack_secreto'] ?? '', $_SERVER['HTTP_X_SLACK_REQUEST_TIMESTAMP'] ?? '', $cuerpo, $_SERVER['HTTP_X_SLACK_SIGNATURE'] ?? '')) {
            http_response_code(401);
            exit('{}');
        }
        parse_str($cuerpo, $d);
        $txt = orden_chat(db(), 'slack:' . ($d['team_id'] ?? '') . ':' . ($d['user_id'] ?? ''), (string)($d['text'] ?? ''));
        exit(json_encode(['response_type' => 'ephemeral', 'text' => $txt], JSON_UNESCAPED_UNICODE));
    }
    if ($canal === 'teams') {
        if (!firma_teams_ok($c['teams_secreto'] ?? '', $cuerpo, $_SERVER['HTTP_AUTHORIZATION'] ?? '')) {
            http_response_code(401);
            exit('{}');
        }
        $d = json_decode($cuerpo, true) ?: [];
        $txt = orden_chat(db(), 'teams:' . ($d['from']['aadObjectId'] ?? $d['from']['id'] ?? ''), strip_tags((string)($d['text'] ?? '')));
        exit(json_encode(['type' => 'message', 'text' => $txt], JSON_UNESCAPED_UNICODE));
    }
    http_response_code(404);
    exit('{}');
} catch (DomainException $e) {
    exit(json_encode($canal === 'slack' ? ['response_type' => 'ephemeral', 'text' => $e->getMessage()] : ['type' => 'message', 'text' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
}
