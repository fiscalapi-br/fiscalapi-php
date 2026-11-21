<?php

// Servidor de teste (php -S 127.0.0.1:8765 tests/servidor-eco.php): grava cada
// requisição em FISCALAPI_ECO_LOG e devolve respostas fixas do Monitor de NF-e.

declare(strict_types=1);

$metodo = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
$corpo = file_get_contents('php://input');
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
    }
}
if (isset($_SERVER['CONTENT_LENGTH'])) {
    $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
}
file_put_contents(
    (string) getenv('FISCALAPI_ECO_LOG'),
    json_encode(['metodo' => $metodo, 'uri' => $uri, 'corpo' => $corpo, 'headers' => $headers]) . "\n",
    FILE_APPEND,
);

header('Content-Type: application/json');
if (str_starts_with($uri, '/status404/')) {
    http_response_code(404);
    echo json_encode(['title' => 'WEBHOOK_NAO_CADASTRADO', 'status' => 404, 'detail' => 'Nenhum webhook cadastrado.']);
    return true;
}
if ($metodo === 'DELETE') {
    http_response_code(204);
    return true;
}
$caminho = (string) parse_url($uri, PHP_URL_PATH);
if ($metodo === 'GET' && $caminho === '/api/monitor-nfe/chaves') {
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $q);
    echo ($q['cursor'] ?? '') === 'c1'
        ? json_encode(['itens' => [['chave' => 'c']], 'proximo_cursor' => null])
        : json_encode(['itens' => [['chave' => 'a'], ['chave' => 'b']], 'proximo_cursor' => 'c1']);
    return true;
}
echo json_encode(['ok' => true, 'metodo' => $metodo, 'caminho' => $caminho]);
return true;
