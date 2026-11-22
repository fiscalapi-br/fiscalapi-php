<?php

// Testes da Emissão de NFS-e, sem rede externa: montagem das requisições contra o
// servidor de eco local e a assinatura do webhook com o vetor da documentação.
//   FISCALAPI_ECO_LOG=/tmp/eco.jsonl php -S 127.0.0.1:8765 tests/servidor-eco.php &
//   FISCALAPI_ECO_LOG=/tmp/eco.jsonl php tests/EmissaoNfseTest.php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use FiscalApiBr\Exception\BadRequestException;
use FiscalApiBr\Exception\RateLimitException;
use FiscalApiBr\Exception\ServerException;
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

/** @return list<array<string, mixed>> */
function chamadas(string $log): array
{
    return array_map(
        static fn (string $l): array => json_decode($l, true),
        array_values(array_filter(explode("\n", (string) @file_get_contents($log)))),
    );
}

// Empresa fictícia da documentação (CNPJ com DV válido, não é de cliente).
const EMPRESA_ID = '8f1c2a4e-5b6d-4e7f-9a0b-1c2d3e4f5a6b';
const NOTA_ID = '3b8e2f4a-6c1d-4e9f-8a7b-2c3d4e5f6a7b';
const NOTA = [
    'empresa_id' => EMPRESA_ID,
    'ambiente' => 'homologacao',
    'referencia' => 'teste-0001',
    'preset' => 'simples_servico',
    'competencia' => '2026-11-22',
    'tomador' => ['tipo' => 'cnpj', 'documento' => '12.345.678/0001-95', 'nome' => 'Cliente Exemplo Comercio Ltda'],
    'servico' => ['codigo_tributacao_nacional' => '01.07.01', 'descricao' => 'Suporte técnico em informática'],
    'valores' => ['valor_servico' => '1500.00', 'percentual_tributos_aproximado' => '6.00'],
];

// ------------------------------------------------------------ assinatura

$corpo = '{"id":"3b8e2f4a-6c1d-4e9f-8a7b-2c3d4e5f6a7b","status":"autorizada","ambiente":"homologacao","evento":"autorizada"}';
$segredo = 'whsec_RXhlbXBsbyBkZSBzZWdyZWRvLCBuYW8gdXNlIGVtIHByb2R1Y2Fv';
$assinatura = 'v1=0c95c6c219de4c9a004efd0b8896f1e8e0d36bd373d86eb859f65506d5c0b9ed';
confere(Webhook::verificarAssinatura($corpo, $assinatura, '1795439400', $segredo, 300, 1795439400), 'assinatura: vetor da documentação');
confere(!Webhook::verificarAssinatura(str_replace('homologacao', 'producao', $corpo), $assinatura, '1795439400', $segredo, 300, 1795439400), 'assinatura: recusa corpo alterado');
confere(!Webhook::verificarAssinatura($corpo, $assinatura, '1795439400', $segredo, 300, 1795439400 + 301), 'assinatura: recusa fora da tolerância');

// ------------------------------------------------------------ requisições

$log = (string) getenv('FISCALAPI_ECO_LOG');
$base = getenv('FISCALAPI_ECO_URL') ?: 'http://127.0.0.1:8765';
@unlink($log);
$api = new FiscalAPI('fapi_teste', baseUrl: $base, maxRetries: 0);

$empresa = [
    'cnpj' => '11.222.333/0001-81',
    'razao_social' => 'Exemplo Servicos de Tecnologia Ltda',
    'im' => '123456',
    'cod_mun_ibge' => '4106902',
    'regime' => ['op_simp_nac' => 3, 'reg_ap_trib_sn' => 1],
    'serie' => '00001',
];
$api->criarEmpresaNfse($empresa);
$lista = $api->listarEmpresasNfse();
confere(array_is_list($lista) && ($lista[1]['id'] ?? null) === 'empresa-2', 'listar empresas devolve a lista');
$api->obterEmpresaNfse(EMPRESA_ID);
$api->atualizarEmpresaNfse(EMPRESA_ID, ['ativa' => false]);

$pfx = 'pfx-ficticio-de-teste'; // o eco grava o corpo em JSON: bytes ASCII
$api->enviarCertificadoNfse(base64_encode($pfx), 's3nha', 'empresa.pfx');
$api->excluirCertificadoNfse('11.222.333');
$api->excluirCertificadoNfse('11.222.333/0001-81');

$api->emitirNfse(NOTA);
$prod = NOTA;
$prod['ambiente'] = 'producao';
unset($prod['referencia']);
$api->emitirNfse($prod, 'pedido-1001');
$api->obterNfse(NOTA_ID);
$vistos = [];
foreach ($api->iterarNfse(status: 'autorizada', ambiente: 'producao', atualizadaDesde: '2026-11-22T00:00:00-03:00', limite: 2) as $nota) {
    $vistos[] = $nota['id'];
}
confere($vistos === ['a', 'b', 'c'], 'iterar segue o cursor');
$api->cancelarNfse(NOTA_ID, 1, ' Valor do serviço informado errado na nota. ');
$subst = [
    'referencia' => 'pedido-1001-v2',
    'motivo' => '99',
    'justificativa' => 'Descrição do serviço corrigida a pedido do cliente.',
    'competencia' => '2026-11-20',
    'tomador' => NOTA['tomador'],
    'servico' => NOTA['servico'],
    'valores' => NOTA['valores'] + ['iss' => ['tributacao' => 1]],
];
$api->substituirNfse(NOTA_ID, $subst);
$xml = $api->baixarXmlNfse(NOTA_ID);
confere(str_starts_with($xml, '<?xml') && str_contains($xml, 'NFS4106'), 'XML devolvido como texto');
$pdf = $api->baixarPdfNfse(NOTA_ID);
confere($pdf === "%PDF-1.7\n\x00\xff", 'PDF devolvido em bytes');
$api->configurarWebhookNfse(' https://seu-sistema.com.br/webhooks/nfse ');
$api->obterWebhookNfse();
$api->reenviarWebhookNfse(NOTA_ID);
$api->removerWebhookNfse();

$c = chamadas($log);
$rotas = array_map(static fn (array $x): string => $x['metodo'] . ' ' . $x['uri'], $c);
$desde = '2026-11-22T00%3A00%3A00-03%3A00';
confere($rotas === [
    'POST /api/nfse/empresas',
    'GET /api/nfse/empresas',
    'GET /api/nfse/empresas/' . EMPRESA_ID,
    'PATCH /api/nfse/empresas/' . EMPRESA_ID,
    'PUT /api/nfse/certificados',
    'DELETE /api/nfse/certificados/11222333',
    'DELETE /api/nfse/certificados/11222333',
    'POST /api/nfse/notas',
    'POST /api/nfse/notas',
    'GET /api/nfse/notas/' . NOTA_ID,
    "GET /api/nfse/notas?status=autorizada&ambiente=producao&atualizada_desde={$desde}&limite=2",
    "GET /api/nfse/notas?status=autorizada&ambiente=producao&atualizada_desde={$desde}&cursor=c1&limite=2",
    'POST /api/nfse/notas/' . NOTA_ID . '/cancelamento',
    'POST /api/nfse/notas/' . NOTA_ID . '/substituicao',
    'GET /api/nfse/notas/' . NOTA_ID . '/xml',
    'GET /api/nfse/notas/' . NOTA_ID . '/pdf',
    'PUT /api/nfse/webhook',
    'GET /api/nfse/webhook',
    'POST /api/nfse/notas/' . NOTA_ID . '/webhook',
    'DELETE /api/nfse/webhook',
], 'métodos e caminhos: ' . implode(' | ', $rotas));
confere(json_decode($c[0]['corpo'], true) === $empresa, 'corpo do cadastro da empresa');
confere(json_decode($c[3]['corpo'], true) === ['ativa' => false], 'corpo do PATCH');
$multipart = (string) $c[4]['corpo'];
confere(str_starts_with((string) ($c[4]['headers']['content-type'] ?? ''), 'multipart/form-data; boundary='), 'certificado em multipart');
confere(str_contains($multipart, 'name="certificado"; filename="empresa.pfx"') && str_contains($multipart, $pfx), 'arquivo do certificado no multipart');
confere(str_contains($multipart, "name=\"senha\"\r\n\r\ns3nha\r\n"), 'senha no multipart');
confere(json_decode($c[7]['corpo'], true) === NOTA, 'corpo da nota');
confere(!isset($c[7]['headers']['idempotency-key']), 'sem Idempotency-Key quando não informada');
confere(($c[8]['headers']['idempotency-key'] ?? null) === 'pedido-1001', 'Idempotency-Key no cabeçalho');
confere(json_decode($c[12]['corpo'], true) === ['motivo' => 1, 'justificativa' => 'Valor do serviço informado errado na nota.'], 'corpo do cancelamento');
confere(json_decode($c[13]['corpo'], true) === $subst, 'corpo da substituição');
confere(($c[15]['headers']['accept'] ?? null) === 'application/pdf', 'Accept do PDF');
confere(json_decode($c[16]['corpo'], true) === ['url' => 'https://seu-sistema.com.br/webhooks/nfse'], 'corpo do webhook');
confere(($c[18]['headers']['content-length'] ?? null) === '0', 'reenvio sem corpo manda Content-Length 0');

// validação antes de chamar
$antes = \count($c);
confere(lancou(static fn () => $api->emitirNfse($prod), \InvalidArgumentException::class), 'produção sem referência é recusada');
confere(lancou(static fn () => $api->emitirNfse(['referencia' => 'a'] + NOTA, 'b'), \InvalidArgumentException::class), 'referência e chave diferentes são recusadas');
confere(lancou(static fn () => $api->cancelarNfse(NOTA_ID, 1, 'curta'), \InvalidArgumentException::class), 'justificativa curta é recusada');
confere(lancou(static fn () => $api->listarNfse(limite: 101), \InvalidArgumentException::class), 'limite acima de 100 é recusado');
confere(lancou(static fn () => $api->excluirCertificadoNfse('123'), \InvalidArgumentException::class), 'raiz inválida é recusada');
confere(lancou(static fn () => $api->atualizarEmpresaNfse(EMPRESA_ID, []), \InvalidArgumentException::class), 'PATCH vazio é recusado');
confere(lancou(static fn () => $api->enviarCertificadoNfse('/nao/existe/empresa.pfx'), \RuntimeException::class), 'caminho inexistente é recusado');
confere(\count(chamadas($log)) === $antes, 'nada enviado quando a validação falha');

// novas tentativas: com referência repete em 5xx; sem referência, não
@unlink($log);
$api503 = new FiscalAPI('fapi_teste', baseUrl: $base . '/sempre503', maxRetries: 1);
confere(lancou(static fn () => $api503->emitirNfse(NOTA), ServerException::class), '503 vira ServerException');
confere(\count(chamadas($log)) === 2, 'com referência, repete em 5xx');
$semRef = NOTA;
unset($semRef['referencia']);
@unlink($log);
confere(lancou(static fn () => $api503->emitirNfse($semRef), ServerException::class), '503 sem referência');
confere(\count(chamadas($log)) === 1, 'sem referência, não repete');

// 429 do limite mensal de homologação: não repete
@unlink($log);
$api429 = new FiscalAPI('fapi_teste', baseUrl: $base . '/limite429', maxRetries: 3);
try {
    $api429->emitirNfse(NOTA);
    confere(false, '429 lança RateLimitException');
} catch (RateLimitException $e) {
    confere($e->errorCode === 'NFSE_HOMOLOG_LIMITE', '429 lança RateLimitException com o código');
}
confere(\count(chamadas($log)) === 1, '429 NFSE_HOMOLOG_LIMITE não é repetido');

// 409 no PDF
$api409 = new FiscalAPI('fapi_teste', baseUrl: $base . '/status409', maxRetries: 0);
try {
    $api409->baixarPdfNfse(NOTA_ID);
    confere(false, '409 lança BadRequestException');
} catch (BadRequestException $e) {
    confere($e->statusCode === 409 && $e->errorCode === 'NFSE_NOTA_SEM_XML', '409 lança BadRequestException com o código');
}

echo $falhas === 0 ? PHP_EOL . 'todos os testes passaram' . PHP_EOL : PHP_EOL . "{$falhas} falha(s)" . PHP_EOL;
exit($falhas === 0 ? 0 : 1);
