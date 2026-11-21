<?php

// Testes do Monitor de NF-e, sem rede externa: assinatura do webhook (vetor fixo)
// e montagem das requisições contra o servidor de eco local.
//   FISCALAPI_ECO_LOG=/tmp/eco.jsonl php -S 127.0.0.1:8765 tests/servidor-eco.php &
//   FISCALAPI_ECO_LOG=/tmp/eco.jsonl php tests/MonitorNfeTest.php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use FiscalApiBr\Exception\BadRequestException;
use FiscalApiBr\FiscalAPI;
use FiscalApiBr\Webhook;

$falhas = 0;
function confere(bool $ok, string $nome): void
{
    global $falhas;
    echo ($ok ? 'ok   ' : 'FALHA ') . $nome . PHP_EOL;
    if (!$ok) {
        $falhas++;
    }
}

function lancou(callable $fn, string $classe): bool
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return $e instanceof $classe;
    }
    return false;
}

const SEGREDO = 'whsec_dGVzdGUtZG8tc2RrLWZpc2NhbGFwaQ';
const TS = 1795294800;
const CORPO = '{"id":"evt_0123456789abcdef0123456789abcdef","tipo":"teste","ocorrido_em":"2026-11-21T17:00:00-03:00"}';
const V1 = '8b03e794ca99a1c2c684cf54191aae0af0f7d4e696ef598f9258365861fa8933';
const V1_ANTERIOR = '31ed23b2a446bf7329ed246b1bd0e1a717f11b19244290f3b4c9acb0f3396846';
const CHAVE = '35261111222333000181550010000001231123456787';

// ------------------------------------------------------------ assinatura

$v = static fn (string $corpo, ?string $assinatura, string|int|null $ts, string $segredo = SEGREDO, int $agora = TS + 10, int $tol = 300): bool
    => Webhook::verificarAssinatura($corpo, $assinatura, $ts, $segredo, $tol, $agora);

confere(Webhook::assinar(SEGREDO, TS, CORPO) === V1, 'vetor fixo');
confere(Webhook::assinar('whsec_anterior', TS, CORPO) === V1_ANTERIOR, 'vetor fixo do segredo anterior');
confere($v(CORPO, 'v1=' . V1, (string) TS), 'aceita v1 válida');
confere($v(CORPO, 'v1=' . V1_ANTERIOR . ', v1=' . V1, TS), 'aceita entre várias v1');
confere(!$v(CORPO . ' ', 'v1=' . V1, TS), 'recusa corpo alterado');
confere(!$v(CORPO, 'v1=' . V1, TS, 'whsec_outro'), 'recusa segredo errado');
confere(!$v(CORPO, V1, TS), 'recusa sem prefixo v1=');
confere(!$v(CORPO, 'v0=' . V1, TS), 'recusa outra versão');
confere(!$v(CORPO, null, TS), 'recusa sem cabeçalho');
confere(!$v(CORPO, 'v1=' . V1, 'abc'), 'recusa timestamp inválido');
confere(!$v(CORPO, 'v1=' . V1, TS + 1), 'recusa timestamp trocado');
confere(!$v(CORPO, 'v1=' . V1, TS, SEGREDO, TS + 301), 'recusa fora da tolerância (futuro)');
confere(!$v(CORPO, 'v1=' . V1, TS, SEGREDO, TS - 301), 'recusa fora da tolerância (passado)');
confere($v(CORPO, 'v1=' . V1, TS, SEGREDO, TS + 301, 600), 'tolerância configurável');

// ------------------------------------------------------------ requisições

$log = (string) getenv('FISCALAPI_ECO_LOG');
$base = getenv('FISCALAPI_ECO_URL') ?: 'http://127.0.0.1:8765';
@unlink($log);
$api = new FiscalAPI('fapi_teste', baseUrl: $base, maxRetries: 0);

$r = $api->cadastrarChavesMonitorNfe(
    ['NFe ' . substr(CHAVE, 0, 4) . '.' . substr(CHAVE, 4), ['chave' => CHAVE, 'status_conhecido' => 'cancelada']],
    prazoDias: 30,
);
confere(($r['ok'] ?? false) === true, 'cadastrar devolve o JSON');
$api->removerChavesMonitorNfe([CHAVE]);
$api->removerChaveMonitorNfe(CHAVE);
$api->consultarChaveMonitorNfe(CHAVE);
$vistos = [];
foreach ($api->iterarChavesMonitorNfe(status: 'cancelada', ativo: true, limite: 2) as $item) {
    $vistos[] = $item['chave'];
}
confere($vistos === ['a', 'b', 'c'], 'iterar segue o cursor');
$api->cadastrarWebhookMonitorNfe(' https://exemplo.com/h ');
$api->consultarWebhookMonitorNfe();
$api->rotacionarSegredoWebhookMonitorNfe();
$api->testarWebhookMonitorNfe();
$api->removerWebhookMonitorNfe();

$chamadas = array_map(
    static fn (string $l): array => json_decode($l, true),
    array_values(array_filter(explode("\n", (string) file_get_contents($log)))),
);
$rotas = array_map(static fn (array $c): string => $c['metodo'] . ' ' . $c['uri'], $chamadas);
confere($rotas === [
    'POST /api/monitor-nfe/chaves',
    'POST /api/monitor-nfe/chaves/remover',
    'DELETE /api/monitor-nfe/chaves/' . CHAVE,
    'GET /api/monitor-nfe/chaves/' . CHAVE,
    'GET /api/monitor-nfe/chaves?status=cancelada&ativo=true&limite=2',
    'GET /api/monitor-nfe/chaves?status=cancelada&ativo=true&cursor=c1&limite=2',
    'PUT /api/monitor-nfe/webhook',
    'GET /api/monitor-nfe/webhook',
    'POST /api/monitor-nfe/webhook/rotacionar-segredo',
    'POST /api/monitor-nfe/webhook/teste',
    'DELETE /api/monitor-nfe/webhook',
], 'métodos e caminhos: ' . implode(' | ', $rotas));
confere(json_decode($chamadas[0]['corpo'], true) === [
    'chaves' => [['chave' => CHAVE], ['chave' => CHAVE, 'status_conhecido' => 'cancelada']],
    'prazo_dias' => 30,
], 'corpo do cadastro');
confere(($chamadas[0]['headers']['x-api-key'] ?? null) === 'fapi_teste', 'cabeçalho X-API-Key');
confere(json_decode($chamadas[1]['corpo'], true) === ['chaves' => [CHAVE]], 'corpo da remoção em lote');
confere(json_decode($chamadas[6]['corpo'], true) === ['url' => 'https://exemplo.com/h'], 'corpo do webhook');
confere(($chamadas[8]['headers']['content-length'] ?? null) === '0', 'POST sem corpo manda Content-Length 0');

// validação antes de chamar
$antes = \count($chamadas);
confere(lancou(static fn () => $api->cadastrarChavesMonitorNfe([]), \InvalidArgumentException::class), 'recusa lote vazio');
confere(lancou(static fn () => $api->cadastrarChavesMonitorNfe(array_fill(0, 1001, CHAVE)), \InvalidArgumentException::class), 'recusa lote acima de 1.000');
confere(lancou(static fn () => $api->cadastrarChavesMonitorNfe([CHAVE], 181), \InvalidArgumentException::class), 'recusa prazo acima de 180');
confere(\count(array_filter(explode("\n", (string) file_get_contents($log)))) === $antes, 'nada enviado quando a validação falha');

// erro 404
$api404 = new FiscalAPI('fapi_teste', baseUrl: $base . '/status404', maxRetries: 0);
try {
    $api404->consultarWebhookMonitorNfe();
    confere(false, '404 lança BadRequestException');
} catch (BadRequestException $e) {
    confere($e->statusCode === 404 && $e->errorCode === 'WEBHOOK_NAO_CADASTRADO', '404 lança BadRequestException com o código');
}

echo $falhas === 0 ? PHP_EOL . 'todos os testes passaram' . PHP_EOL : PHP_EOL . "{$falhas} falha(s)" . PHP_EOL;
exit($falhas === 0 ? 0 : 1);
