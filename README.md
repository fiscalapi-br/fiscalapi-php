# FiscalAPI — SDK PHP

Cliente PHP da [FiscalAPI](https://fiscalapi.com.br): Inscrição Estadual nos 27 estados, CNPJ, certidões (CND estadual, CND Federal, CNDT, CRF/FGTS), XML de NF-e e de CT-e por chave, Monitor de NF-e por chave com webhook, emissão de NFS-e Nacional, DIFAL de saída por UF, NFS-e por chave, Simples Nacional, SIGEF, CNIR e perfil do produtor rural.

- PHP 8.1 ou superior, só com a extensão `curl` (nenhum pacote do Composer).
- Autoload PSR-4 (`FiscalApiBr\`).
- Novas tentativas automáticas em `429` e em falhas temporárias.
- Exceções tipadas para chave inválida, falta de saldo e limite de requisições.

## Instalação

```bash
composer require fiscalapi-br/fiscalapi-php
```

## Antes de começar

1. Crie sua conta em [app.fiscalapi.com.br](https://app.fiscalapi.com.br) e copie a chave de API no painel.
2. **Plano ou recarga:** pela API, toda consulta pede plano ou saldo, a partir de R$ 50 por mês. As 10 consultas grátis do mês são só no painel: teste lá antes de assinar. Sem plano nem saldo, a API responde `402` (`InsufficientBalanceException`). Sem chave, `403` (`AuthenticationException`).
3. Planos e consumo de créditos: [docs.fiscalapi.com.br](https://docs.fiscalapi.com.br/docs/referencia/plans-and-limits).

## Uso rápido

```php
<?php
require 'vendor/autoload.php';

use FiscalApiBr\FiscalAPI;

$api = new FiscalAPI('sua-chave'); // ou defina FISCALAPI_API_KEY

$resp = $api->consultarIe('MT', cpf: '12345678909');
foreach ($resp['results'] as $ie) {
    echo $ie['inscricao_estadual'], ' ', $ie['situacao_ie'], ' ', $ie['municipio'], PHP_EOL;
}
```

## Métodos

Todos os métodos devolvem `array` com o JSON da API. Use argumentos nomeados para o documento.

| Método | Endpoint | O que retorna |
|---|---|---|
| `consultarIe($uf, cpf: \| cnpj: \| ie:, secundarias: false)` | `GET /api/consultar` | Inscrições estaduais em uma UF |
| `consultarIeTodos(cpf: \| cnpj: \| ie:, secundarias: false)` | `GET /api/consultar-ie-todos` | Inscrições em todos os estados |
| `consultarCnpj($cnpj, forceFresh: false)` | `GET /api/consultar-cnpj` | Dados cadastrais da Receita Federal |
| `consultarCnd($uf, cpf: \| cnpj:)` | `GET /api/consultar-cnd` | CND estadual (ICMS) com PDF |
| `consultarCndTodos(cpf: \| cnpj:)` | `GET /api/consultar-cnd-todos` | CND estadual em todos os estados disponíveis |
| `consultarCndFederal(cnpj: \| cpf: + dataNascimento:, emitirNova: false)` | `GET /api/consultar-cnd-federal` | CND Federal (Receita Federal + PGFN) |
| `consultarCndt(cpf: \| cnpj:)` | `GET /api/consultar-cndt` | Certidão de débitos trabalhistas |
| `consultarCrfFgts(cpf: \| cnpj:)` | `GET /api/consultar-crf-fgts` | Regularidade do FGTS |
| `consultarCertidoesTodas(cpf: \| cnpj:, skipEstaduais: false)` | `GET /api/certidoes-todas` | Todas as certidões em uma chamada |
| `consultarSimplesNacional($cnpj)` | `GET /api/consultar-simples-nacional` | Situação no Simples Nacional e SIMEI |
| `consultarNfse($chave, danfse: false)` | `GET /api/consultar-nfse` | NFS-e (padrão nacional) por chave, sem certificado |
| `consultarNfeXml($chave, $certificado, $senha, manifestar: true)` | `POST /api/consultar-nfe-xml` | XML completo da NF-e |
| `consultarCteXml($chave, $certificado, $senha)` | `POST /api/consultar-cte-xml` | XML completo do CT-e |
| `cadastrarChavesMonitorNfe($chaves, $prazoDias = null)` | `POST /api/monitor-nfe/chaves` | Coloca até 1.000 chaves no Monitor de NF-e |
| `removerChavesMonitorNfe($chaves)` / `removerChaveMonitorNfe($chave)` | `POST /api/monitor-nfe/chaves/remover` / `DELETE /api/monitor-nfe/chaves/{chave}` | Para de monitorar |
| `listarChavesMonitorNfe($status, $ativo, $alteradoDesde, $cursor, $limite)` / `iterarChavesMonitorNfe(...)` | `GET /api/monitor-nfe/chaves` | Chaves monitoradas (uma página / todas) |
| `consultarChaveMonitorNfe($chave)` | `GET /api/monitor-nfe/chaves/{chave}` | Situação, eventos e entregas que falharam |
| `cadastrarWebhookMonitorNfe($url)`, `consultarWebhookMonitorNfe()`, `removerWebhookMonitorNfe()`, `rotacionarSegredoWebhookMonitorNfe()`, `testarWebhookMonitorNfe()` | `/api/monitor-nfe/webhook` | Webhook dos avisos |
| `criarEmpresaNfse($empresa)`, `listarEmpresasNfse()`, `obterEmpresaNfse($empresaId)`, `atualizarEmpresaNfse($empresaId, $mudancas)` | `/api/nfse/empresas` | Empresas emissoras de NFS-e |
| `enviarCertificadoNfse($certificado, $senha)` / `excluirCertificadoNfse($raiz)` | `PUT /api/nfse/certificados` / `DELETE /api/nfse/certificados/{raiz}` | Certificado A1 da empresa (multipart) |
| `emitirNfse($nota, $idempotencyKey = null)` | `POST /api/nfse/notas` | Emite NFS-e (assíncrono, `202`) |
| `obterNfse($notaId)`, `listarNfse(status:, ambiente:, referencia:, atualizadaDesde:, cursor:, limite:)` / `iterarNfse(...)` | `GET /api/nfse/notas` | NFS-e emitidas (uma / uma página / todas) |
| `cancelarNfse($notaId, $motivo, $justificativa)`, `substituirNfse($notaId, $substituicao)` | `POST /api/nfse/notas/{id}/cancelamento` e `/substituicao` | Cancela ou substitui |
| `baixarXmlNfse($notaId)` / `baixarPdfNfse($notaId)` | `GET /api/nfse/notas/{id}/xml` e `/pdf` | XML autorizado e DANFSe (bytes) |
| `configurarWebhookNfse($url)`, `obterWebhookNfse()`, `removerWebhookNfse()`, `reenviarWebhookNfse($notaId)` | `/api/nfse/webhook` | Webhook da emissão |
| `consultarDifalSaida($competencia, $certificado, $senha, $ufs = [])` | `POST /api/consultar-difal-saida` | DIFAL de saída por UF de destino (Beta) |
| `consultarSigef(cpf: \| cnpj:)` | `GET /api/consultar-sigef` | Parcelas certificadas no SIGEF/INCRA |
| `consultarSigefParcela($uuid, forceFresh: false)` | `GET /api/consultar-sigef-parcela` | Uma parcela com polígono, vértices e confrontantes |
| `consultarCnir(cpf: \| cnpj:)` | `GET /api/consultar-cnir` | Imóveis rurais no CNIR/CAFIR |
| `perfilProdutor(cpf: \| cnpj:)` | `GET /api/perfil-produtor` | Perfil consolidado do produtor |

Informe **um** documento por chamada. Pontuação é aceita.

## Exemplos

### Inscrição Estadual em todos os estados

```php
$resp = $api->consultarIeTodos(cnpj: '12.345.678/0001-95');
if (($resp['complete'] ?? true) === false) {
    echo "Algum estado não respondeu; tente de novo antes de concluir que não há IE.\n";
}
foreach ($resp['results'] as $uf) {
    foreach ($uf['results'] as $ie) {
        echo $uf['uf'], ' ', $ie['inscricao_estadual'], ' ', $ie['situacao_ie'], PHP_EOL;
    }
}
```

### CNPJ

```php
$empresa = $api->consultarCnpj('12345678000195');
echo $empresa['result']['razao_social'], ' — ', $empresa['result']['situacao_cadastral'], PHP_EOL;

// Dado atual, sem cache (consome mais créditos):
$api->consultarCnpj('12345678000195', forceFresh: true);
```

### Simples Nacional

```php
$r = $api->consultarSimplesNacional('11222333000181');
var_dump($r['optante_simples'], $r['situacao_simei'], $r['regime_regular_ibs']);
```

### NFS-e por chave

```php
$nfse = $api->consultarNfse(
    '35503082211222333000181000000000012326100000045676',
    danfse: true, // opcional: PDF do DANFSe em base64
);
echo $nfse['emitente']['razao_social'], ' — ', $nfse['tributacao_municipal']['valor_servico'], PHP_EOL;
```

Uma consulta nova leva de 30 a 60 segundos. Só a NFS-e entregue é cobrada.

### Certidões com PDF

```php
$cnd = $api->consultarCnd('MT', cpf: '12345678909');
echo $cnd['result']['status'], ' até ', $cnd['result']['validade_iso'], PHP_EOL;
if (!empty($cnd['result']['pdf_base64'])) {
    file_put_contents('cnd-mt.pdf', base64_decode($cnd['result']['pdf_base64']));
}

$federal = $api->consultarCndFederal(cnpj: '12345678000195');
// CPF: a Receita exige a data de nascimento (DD/MM/AAAA ou AAAA-MM-DD)
$federalPf = $api->consultarCndFederal(cpf: '12345678909', dataNascimento: '12/04/1985');
$trabalhista = $api->consultarCndt(cnpj: '12345678000195');
$fgts = $api->consultarCrfFgts(cnpj: '12345678000195');
```

O campo `status` vem como `negativa`, `positiva`, `positiva_com_efeitos_de_negativa`, `nao_contribuinte`, `sem_certidao` ou `erro`.

### XML de NF-e por chave

```php
$resp = $api->consultarNfeXml(
    '35260112345678000195550010000012341000012345',
    'certificado.pfx',           // caminho do arquivo ou conteúdo em base64
    'senha-do-certificado',
);
$nota = $resp['resultados'][0];
if ($nota['status'] === 'ok') {
    file_put_contents($nota['chave'] . '.xml', $nota['xml']);
} else {
    echo $nota['status'], ': ', $nota['mensagem'], PHP_EOL;
}
```

O certificado A1 precisa ser de um participante da nota (emitente, destinatário, transportador ou autorizado) e **não é armazenado** pela API. Só há cobrança quando o XML é devolvido.

### XML de CT-e por chave

```php
$cte = $api->consultarCteXml(
    '51261011222333000181570010000001231123456784',
    'certificado.pfx',           // caminho do arquivo ou conteúdo em base64
    'senha-do-certificado',
);
if ($cte['status'] === 'ok') {
    file_put_contents($cte['chave'] . '.xml', $cte['xml']);
} else {
    echo $cte['status'], ': ', $cte['mensagem'], PHP_EOL;
}
```

Aceita CT-e (modelo 57), CT-e OS (67) e GTV-e (64). O certificado A1 precisa ser de um participante do documento (emitente, tomador, remetente, destinatário, expedidor ou recebedor) e **não é armazenado** pela API. Só há cobrança quando o XML é devolvido.

### Monitor de NF-e por chave

Cadastre as chaves e a FiscalAPI acompanha cada nota na SEFAZ por até 180 dias. Quando a nota é cancelada, denegada ou recebe um evento (carta de correção, por exemplo), chega um aviso no seu webhook. Sem certificado.

```php
// 1. Webhook (uma vez). Guarde o segredo: ele só volta aqui.
$segredo = $api->cadastrarWebhookMonitorNfe('https://seu-sistema.com/fiscalapi/avisos')['segredo'];

// 2. Chaves: até 1.000 por chamada
$r = $api->cadastrarChavesMonitorNfe(
    ['35261111222333000181550010000001231123456787'],
    prazoDias: 180, // opcional, de 1 a 180
);
echo $r['aceitas'], ' ', $r['valor_cobrado'], PHP_EOL;
foreach ($r['resultados'] as $item) {
    if ($item['situacao'] === 'invalida') {
        echo $item['chave'], ' ', $item['motivo'], PHP_EOL;
    }
}

// 3. Consultar e listar
$nota = $api->consultarChaveMonitorNfe('35261111222333000181550010000001231123456787');
foreach ($api->iterarChavesMonitorNfe(alteradoDesde: '2026-11-21T00:00:00-03:00') as $item) {
    echo $item['chave'], ' ', $item['status'], ' ', $item['entregas_falhas'], PHP_EOL;
}
```

Cobra uma vez por chave aceita. Chave já monitorada não é cobrada de novo; chave inválida volta com `motivo` e não é cobrada. Sem saldo para todas, `InsufficientBalanceException` e nenhuma entra.

**Receber o aviso.** Confira a assinatura com o corpo **bruto** (`php://input`, antes do `json_decode`):

```php
use FiscalApiBr\Webhook;

$corpo = file_get_contents('php://input');
$ok = Webhook::verificarAssinatura(
    $corpo,
    $_SERVER['HTTP_X_FISCALAPI_ASSINATURA'] ?? null,
    $_SERVER['HTTP_X_FISCALAPI_TIMESTAMP'] ?? null,
    (string) getenv('FISCALAPI_WEBHOOK_SEGREDO'),
    tolerancia: 300, // padrão, em segundos
);
if (!$ok) {
    http_response_code(401);
    exit;
}
$aviso = json_decode($corpo, true);
// deduplique por $aviso['id']: a entrega é "pelo menos uma vez"
http_response_code(200); // responda 2xx em até 10 s
```

A assinatura é `v1=` + HMAC-SHA256 do segredo inteiro (`whsec_...`) sobre `"{timestamp}." . corpo`. Depois de `rotacionarSegredoWebhookMonitorNfe()`, o cabeçalho traz `v1=nova,v1=anterior` por 24 h: o helper aceita qualquer uma. Tipos de aviso: `nfe.status_alterado`, `nfe.evento_registrado`, `nfe.monitoramento_encerrado` e `teste`. Detalhes: [documentação](https://docs.fiscalapi.com.br).

### Emissão de NFS-e Nacional

Emita a NFS-e do padrão nacional pela sua empresa, assinada com o certificado A1 dela. A nota é processada em segundos e o resultado chega por webhook. Homologação é grátis (1.000 notas por mês); em produção, só a nota autorizada é cobrada.

**Primeira nota (homologação):**

```php
$api = new \FiscalApiBr\FiscalAPI(getenv('FISCALAPI_API_KEY'));

// 1. Certificado A1 (e-CNPJ ICP-Brasil) da empresa, uma vez: caminho, base64 ou bytes
$api->enviarCertificadoNfse('empresa.pfx', (string) getenv('SENHA_CERTIFICADO'));

// 2. Empresa emissora (fictícia: troque pelos dados da sua)
$empresa = $api->criarEmpresaNfse([
    'cnpj' => '11.222.333/0001-81',
    'razao_social' => 'Exemplo Servicos de Tecnologia Ltda',
    'im' => '123456',
    'cod_mun_ibge' => '4106902', // Curitiba
    'regime' => ['op_simp_nac' => 3, 'reg_ap_trib_sn' => 1], // ME/EPP do Simples
    'serie' => '00001',
]);

// 3. Webhook (opcional). O segredo só volta no primeiro cadastro: guarde-o.
$segredo = $api->configurarWebhookNfse('https://seu-sistema.com.br/webhooks/nfse')['segredo'];

// 4. Emita
$nota = $api->emitirNfse([
    'empresa_id' => $empresa['id'],
    'ambiente' => 'homologacao',
    'referencia' => 'teste-0001', // o seu id do pedido; obrigatório em produção
    'preset' => 'simples_servico',
    'competencia' => '2026-11-22',
    'tomador' => ['tipo' => 'cnpj', 'documento' => '12.345.678/0001-95', 'nome' => 'Cliente Exemplo Comercio Ltda'],
    'servico' => [
        'codigo_tributacao_nacional' => '01.07.01',
        'descricao' => 'Suporte técnico em informática, novembro de 2026',
    ],
    'valores' => ['valor_servico' => '1500.00', 'percentual_tributos_aproximado' => '6.00'],
]);
echo $nota['id'], ' ', $nota['status'], PHP_EOL; // "pendente"; avisos em $nota['avisos']

// 5. Sem webhook, consulte
$atual = $api->obterNfse($nota['id']);
if ($atual['status'] === 'autorizada') {
    file_put_contents('danfse.pdf', $api->baixarPdfNfse($nota['id']));
    file_put_contents('nfse.xml', $api->baixarXmlNfse($nota['id']));
} elseif ($atual['status'] === 'rejeitada') {
    print_r($atual['erros']); // [['codigo' => ..., 'descricao' => ...]]
}
```

Para produção, troque `ambiente` para `'producao'` e mande sempre a `referencia` (ou o segundo argumento `$idempotencyKey`): repetir o mesmo pedido devolve a nota que já existe, sem emitir outra. Com ela, o SDK também repete a chamada em falha de rede e `5xx`. Sem nenhuma das duas, `emitirNfse` em produção lança `InvalidArgumentException` antes de chamar a API. Mande valores como texto (`'1500.00'`).

**Cancelar, substituir e sincronizar:**

```php
$api->cancelarNfse($notaId, 1, 'Valor do serviço informado errado na nota.');

$nova = $api->substituirNfse($notaId, [
    'referencia' => 'pedido-1001-v2',
    'motivo' => '99',
    'justificativa' => 'Descrição corrigida a pedido do cliente.',
    'competencia' => '2026-11-20',
    'tomador' => $tomador,
    'servico' => $servico,
    'valores' => $valores + ['iss' => ['tributacao' => 1]],
]);

foreach ($api->iterarNfse(ambiente: 'producao', atualizadaDesde: $ultimoUpdatedAt) as $n) {
    // trate repetidos pelo id
}
```

**Receber o aviso.** O webhook da emissão usa o mesmo esquema de assinatura do Monitor de NF-e, e o mesmo helper serve:

```php
$corpo = file_get_contents('php://input'); // corpo bruto, antes do json_decode
$ok = \FiscalApiBr\Webhook::verificarAssinatura(
    $corpo,
    $_SERVER['HTTP_X_FISCALAPI_ASSINATURA'] ?? null,
    $_SERVER['HTTP_X_FISCALAPI_TIMESTAMP'] ?? null,
    (string) getenv('FISCALAPI_NFSE_WEBHOOK_SEGREDO'),
);
if (!$ok) {
    http_response_code(401);
    exit;
}
$aviso = json_decode($corpo, true);
// $aviso['evento']: autorizada, rejeitada, cancelada, substituida ou cancelamento_recusado
// deduplique por X-FiscalAPI-Entrega + $aviso['updated_at']; filtre por $aviso['ambiente']
http_response_code(204); // responda 2xx em até 10 s
```

Erros da API chegam com o código em `$e->errorCode` (ex.: `NFSE_DADOS_INVALIDOS`, com a lista em `$e->body['erros']`; `NFSE_REFERENCIA_EM_USO`; `NFSE_TERMOS_PENDENTES`). Campos, presets, IBS/CBS e a tabela de erros: [documentação da emissão](https://docs.fiscalapi.com.br/docs/emissao-nfse/visao-geral).

### DIFAL de saída por UF de destino (Beta)

```php
$difal = $api->consultarDifalSaida(
    '2026-10',
    'certificado-do-emitente.pfx', // caminho do arquivo ou conteúdo em base64
    'senha-do-certificado',
    ['RS', 'SC'],                  // omita para as 10 UFs
);
foreach ($difal['ufs'] as $uf) {
    echo $uf['uf'], ' ', $uf['status'], ' ', $uf['valor_em_aberto'], PHP_EOL;
    foreach ($uf['notas'] as $nota) {
        if ($nota['em_aberto']) {
            echo '  ', $nota['serie'], ' ', $nota['numero'], ' ', $nota['situacao_guia_difal'], PHP_EOL;
        }
    }
}
if (!$difal['completo']) {
    echo 'Alguma UF não veio completa: os totais podem estar incompletos.', PHP_EOL;
}
```

Lista as NF-e do mês com DIFAL e FCP destacados, a situação da guia de cada nota e o valor em aberto, nas UFs de destino AC, AP, DF, GO, MS, MT, RR, RS, SC e SE. O certificado A1 é o do **emitente** das notas e **não é armazenado** pela API. Só `paga` quita: `sem_guia_no_portal`, `pendente_pagamento` e `geracao_rejeitada` somam em `valor_em_aberto`, menos em DF, GO e MT, onde a guia é emitida no sistema da SEFAZ e a nota `sem_guia_no_portal` soma em `valor_a_conferir`. Cobra 1 consulta por UF com `status` `ok`. Guia emitida fora do portal (ERP, Portal GNRE) não aparece. Detalhes: [documentação](https://docs.fiscalapi.com.br/docs/difal/consultar-difal-saida).

### Imóvel rural

```php
$sigef = $api->consultarSigef(cpf: '12345678909');
foreach ($sigef['result']['parcelas'] ?? [] as $p) {
    echo $p['denominacao'], ' — ', $p['area_ha'], ' ha — ', $p['municipio'], '/', $p['uf'], PHP_EOL;
}

$parcela = $api->consultarSigefParcela('0f1e2d3c-4b5a-6978-8a9b-0c1d2e3f4a5b');
echo $parcela['result']['geometria_wkt'], PHP_EOL;

$cnir = $api->consultarCnir(cpf: '12345678909');
echo $cnir['total_imoveis'], " imóveis\n";
```

### Perfil do produtor

```php
$perfil = $api->perfilProdutor(cpf: '12345678909');
```

## Status da fonte

Uma resposta `200` pode trazer `source_status.status` diferente de `success` (por exemplo, `timeout` quando o órgão não respondeu a tempo). Confira esse campo antes de concluir que o documento não tem registro.

## Erros

```php
use FiscalApiBr\Exception\{
    FiscalAPIException, AuthenticationException, InsufficientBalanceException,
    RateLimitException, BadRequestException, ServerException, APIConnectionException
};

try {
    $api->consultarIe('SP', cnpj: '12345678000195');
} catch (AuthenticationException $e) {
    // chave ausente, inválida ou revogada (401/403)
} catch (InsufficientBalanceException $e) {
    echo $e->getConsultasRestantes();    // plano ou saldo esgotado (402)
} catch (RateLimitException $e) {
    echo $e->getRetryAfter();            // limite por minuto do plano (429)
} catch (BadRequestException $e) {
    echo $e->errorCode, ': ', $e->getMessage(); // parâmetro ausente ou inválido (400/422)
} catch (FiscalAPIException $e) {
    echo $e->statusCode, ' ', $e->requestId;    // informe o requestId ao suporte
}
```

## Novas tentativas e timeout

```php
$api = new FiscalAPI('sua-chave', timeout: 200, maxRetries: 3);
```

- `429`: o SDK espera o tempo do cabeçalho `Retry-After` e tenta de novo (menos `NFSE_HOMOLOG_LIMITE`, o limite mensal de homologação).
- `500`, `502`, `503`, `504` e falhas de rede: novas tentativas com espera exponencial, **só em consultas GET**. POST, PUT, PATCH e DELETE (NF-e, cadastro no monitor, webhook) não são repetidos automaticamente; a exceção é `emitirNfse` e `substituirNfse` com `referencia` ou `$idempotencyKey`, que a API trata sem duplicar.
- Algumas consultas (NF-e, SIGEF) podem levar até 3 minutos; o timeout padrão é 200 s. Ajuste também o `max_execution_time` do PHP, se for o caso.

## Links

- Documentação: [docs.fiscalapi.com.br](https://docs.fiscalapi.com.br)
- Guias (Excel, Google Sheets, n8n, Make, Power Automate, Python, Node.js, PHP): [docs.fiscalapi.com.br/docs/guias](https://docs.fiscalapi.com.br/docs/guias)
- Especificação OpenAPI: [docs.fiscalapi.com.br/openapi.json](https://docs.fiscalapi.com.br/openapi.json)
- Servidor MCP: [fiscalapi-br/fiscalapi-mcp](https://github.com/fiscalapi-br/fiscalapi-mcp)
- Para desenvolvedores: [fiscalapi.com.br/desenvolvedores](https://fiscalapi.com.br/desenvolvedores/)
- Painel: [app.fiscalapi.com.br](https://app.fiscalapi.com.br)
- Suporte: contato@fiscalapi.com.br

## Licença

MIT
