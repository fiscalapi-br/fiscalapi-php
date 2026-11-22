<?php

// Servidor de teste (php -S 127.0.0.1:8765 tests/servidor-eco.php): grava cada
// requisição em FISCALAPI_ECO_LOG e devolve respostas fixas do Monitor de NF-e
// e da Emissão de NFS-e.

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
if (isset($_SERVER['CONTENT_TYPE'])) {
    $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
}
file_put_contents(
    (string) getenv('FISCALAPI_ECO_LOG'),
    json_encode(['metodo' => $metodo, 'uri' => $uri, 'corpo' => $corpo, 'headers' => $headers], JSON_INVALID_UTF8_SUBSTITUTE) . "\n",
    FILE_APPEND,
);

header('Content-Type: application/json');
if (str_starts_with($uri, '/status404/')) {
    http_response_code(404);
    echo json_encode(['title' => 'WEBHOOK_NAO_CADASTRADO', 'status' => 404, 'detail' => 'Nenhum webhook cadastrado.']);
    return true;
}
if (str_starts_with($uri, '/sempre503/')) {
    http_response_code(503);
    header('Retry-After: 0');
    echo json_encode(['title' => 'FEATURE_UNDER_REVIEW', 'status' => 503, 'detail' => 'Indisponível.']);
    return true;
}
if (str_starts_with($uri, '/limite429/')) {
    http_response_code(429);
    header('Retry-After: 0');
    echo json_encode(['title' => 'NFSE_HOMOLOG_LIMITE', 'status' => 429, 'detail' => 'Limite do mês.']);
    return true;
}
if (str_starts_with($uri, '/status409/')) {
    http_response_code(409);
    echo json_encode(['title' => 'NFSE_NOTA_SEM_XML', 'status' => 409, 'detail' => 'A nota ainda não foi autorizada.']);
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
if ($metodo === 'GET' && $caminho === '/api/nfse/empresas') {
    echo json_encode([['id' => 'empresa-1'], ['id' => 'empresa-2']]);
    return true;
}
if ($metodo === 'GET' && $caminho === '/api/nfse/notas') {
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $q);
    echo ($q['cursor'] ?? '') === 'c1'
        ? json_encode(['itens' => [['id' => 'c']], 'proximo' => null])
        : json_encode(['itens' => [['id' => 'a'], ['id' => 'b']], 'proximo' => 'c1']);
    return true;
}
if ($metodo === 'GET' && str_ends_with($caminho, '/xml')) {
    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?><NFSe><infNFSe Id="NFS4106"/></NFSe>';
    return true;
}
if ($metodo === 'GET' && str_ends_with($caminho, '/pdf')) {
    header('Content-Type: application/pdf');
    echo "%PDF-1.7\n\x00\xff";
    return true;
}
echo json_encode(['ok' => true, 'metodo' => $metodo, 'caminho' => $caminho]);
return true;
