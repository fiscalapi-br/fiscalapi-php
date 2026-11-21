<?php

declare(strict_types=1);

namespace FiscalApiBr;

use FiscalApiBr\Exception\APIConnectionException;
use FiscalApiBr\Exception\AuthenticationException;
use FiscalApiBr\Exception\BadRequestException;
use FiscalApiBr\Exception\FiscalAPIException;
use FiscalApiBr\Exception\InsufficientBalanceException;
use FiscalApiBr\Exception\RateLimitException;
use FiscalApiBr\Exception\ServerException;

/**
 * Cliente da FiscalAPI. Usa apenas a extensão curl.
 *
 * Exemplo:
 *   $api = new \FiscalApiBr\FiscalAPI('sua-chave');
 *   $resp = $api->consultarIe('MT', cpf: '12345678909');
 */
final class FiscalAPI
{
    public const VERSION = '0.5.0';
    public const DEFAULT_BASE_URL = 'https://api.fiscalapi.com.br';
    /** Algumas consultas (NF-e, SIGEF) podem levar até 180 s no servidor. */
    public const DEFAULT_TIMEOUT = 200;
    public const DEFAULT_MAX_RETRIES = 3;

    /** Até 1.000 chaves por chamada no Monitor de NF-e. */
    public const MONITOR_NFE_MAX_LOTE = 1000;

    private const MONITOR = '/api/monitor-nfe';
    private const RETRYABLE_ANY_METHOD = [429];
    private const RETRYABLE_IDEMPOTENT = [500, 502, 503, 504];
    private const MAX_BACKOFF = 30.0;
    private const MAX_RETRY_AFTER = 60.0;

    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private int $maxRetries;
    private string $userAgent;

    /**
     * @param string|null $apiKey     Chave de API. Se nula, lê FISCALAPI_API_KEY do ambiente.
     * @param string      $baseUrl    URL base da API.
     * @param int         $timeout    Tempo máximo de cada tentativa, em segundos.
     * @param int         $maxRetries Novas tentativas em 429 e (para GET) em 5xx/falha de rede.
     */
    public function __construct(
        ?string $apiKey = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        int $timeout = self::DEFAULT_TIMEOUT,
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        ?string $userAgent = null,
    ) {
        if (!\extension_loaded('curl')) {
            throw new \RuntimeException('A extensão curl do PHP é obrigatória.');
        }
        $key = $apiKey ?? (getenv('FISCALAPI_API_KEY') ?: null);
        if ($key === null || $key === '') {
            throw new \InvalidArgumentException(
                "Informe a chave de API: new FiscalAPI('sua-chave') ou a variável FISCALAPI_API_KEY."
            );
        }
        $this->apiKey = $key;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
        $this->maxRetries = max(0, $maxRetries);
        $ua = 'fiscalapi-br-php/' . self::VERSION;
        $this->userAgent = $userAgent !== null ? $ua . ' ' . $userAgent : $ua;
    }

    // ------------------------------------------------------------------ IE

    /** Inscrição Estadual em uma UF (GET /api/consultar). Informe um entre cpf, cnpj e ie. */
    public function consultarIe(
        string $uf,
        ?string $cpf = null,
        ?string $cnpj = null,
        ?string $ie = null,
        bool $secundarias = false,
    ): array {
        return $this->get('/api/consultar', [
            'uf' => self::uf($uf),
            ...self::umDocumento(['cpf' => $cpf, 'cnpj' => $cnpj, 'ie' => $ie]),
            'secundarias' => $secundarias ?: null,
        ]);
    }

    /** Inscrição Estadual em todos os estados (GET /api/consultar-ie-todos). */
    public function consultarIeTodos(
        ?string $cpf = null,
        ?string $cnpj = null,
        ?string $ie = null,
        bool $secundarias = false,
    ): array {
        return $this->get('/api/consultar-ie-todos', [
            ...self::umDocumento(['cpf' => $cpf, 'cnpj' => $cnpj, 'ie' => $ie]),
            'secundarias' => $secundarias ?: null,
        ]);
    }

    // ---------------------------------------------------------------- CNPJ

    /** Dados cadastrais na Receita Federal (GET /api/consultar-cnpj). */
    public function consultarCnpj(string $cnpj, bool $forceFresh = false): array
    {
        if (trim($cnpj) === '') {
            throw new \InvalidArgumentException('Informe o CNPJ.');
        }
        return $this->get('/api/consultar-cnpj', [
            'cnpj' => trim($cnpj),
            'force_fresh' => $forceFresh ?: null,
        ]);
    }

    /** Situação no Simples Nacional e SIMEI (GET /api/consultar-simples-nacional). */
    public function consultarSimplesNacional(string $cnpj): array
    {
        if (trim($cnpj) === '') {
            throw new \InvalidArgumentException('Informe o CNPJ.');
        }
        return $this->get('/api/consultar-simples-nacional', ['cnpj' => trim($cnpj)]);
    }

    // ----------------------------------------------------------- Certidões

    /** CND estadual (ICMS) em uma UF (GET /api/consultar-cnd). */
    public function consultarCnd(string $uf, ?string $cpf = null, ?string $cnpj = null): array
    {
        return $this->get('/api/consultar-cnd', [
            'uf' => self::uf($uf),
            ...self::umDocumento(['cpf' => $cpf, 'cnpj' => $cnpj]),
        ]);
    }

    /** CND estadual em todos os estados disponíveis (GET /api/consultar-cnd-todos). */
    public function consultarCndTodos(?string $cpf = null, ?string $cnpj = null): array
    {
        return $this->get('/api/consultar-cnd-todos', self::umDocumento(['cpf' => $cpf, 'cnpj' => $cnpj]));
    }

    /**
     * CND Federal — Receita Federal + PGFN (GET /api/consultar-cnd-federal).
     * Para CPF, a Receita exige $dataNascimento do titular (DD/MM/AAAA ou AAAA-MM-DD).
     */
    public function consultarCndFederal(
        ?string $cpf = null,
        ?string $cnpj = null,
        bool $emitirNova = false,
        ?string $dataNascimento = null,
    ): array {
        $doc = self::umDocumento(['cpf' => $cpf, 'cnpj' => $cnpj]);
        $nascimento = trim($dataNascimento ?? '');
        if (isset($doc['cpf']) && $nascimento === '') {
            throw new \InvalidArgumentException('Informe dataNascimento: a CND Federal de CPF exige a data de nascimento.');
        }
        return $this->get('/api/consultar-cnd-federal', [
            ...$doc,
            'data_nascimento' => isset($doc['cpf']) ? $nascimento : null,
            'emitir_nova' => $emitirNova ?: null,
        ]);
    }

    /** CNDT — débitos trabalhistas (GET /api/consultar-cndt). */
    public function consultarCndt(?string $cpf = null, ?string $cnpj = null): array
    {
        return $this->get('/api/consultar-cndt', self::umDocumento(['cpf' => $cpf, 'cnpj' => $cnpj]));
    }

    /** CRF — regularidade do FGTS (GET /api/consultar-crf-fgts). */
    public function consultarCrfFgts(?string $cpf = null, ?string $cnpj = null): array
    {
        return $this->get('/api/consultar-crf-fgts', self::umDocumento(['cpf' => $cpf, 'cnpj' => $cnpj]));
    }

    /** Todas as certidões em uma chamada (GET /api/certidoes-todas). */
    public function consultarCertidoesTodas(
        ?string $cpf = null,
        ?string $cnpj = null,
        bool $skipEstaduais = false,
    ): array {
        return $this->get('/api/certidoes-todas', [
            ...self::umDocumento(['cpf' => $cpf, 'cnpj' => $cnpj]),
            'skip_estaduais' => $skipEstaduais ?: null,
        ]);
    }

    // ---------------------------------------------------------------- NF-e

    /**
     * XML completo de uma NF-e pela chave de acesso (POST /api/consultar-nfe-xml).
     *
     * @param string $chave       Chave de acesso com 44 dígitos.
     * @param string $certificado Caminho do arquivo .pfx/.p12 ou o conteúdo em base64.
     *                            O certificado não é armazenado pela API.
     * @param string $senha       Senha do certificado.
     * @param bool   $manifestar  Registrar a manifestação do destinatário quando só houver o resumo.
     */
    public function consultarNfeXml(string $chave, string $certificado, string $senha, bool $manifestar = true): array
    {
        if (trim($chave) === '') {
            throw new \InvalidArgumentException('Informe a chave de acesso.');
        }
        return $this->request('POST', '/api/consultar-nfe-xml', [], [
            'chave' => preg_replace('/\D/', '', $chave),
            'certificado' => self::certificadoBase64($certificado),
            'senha' => $senha,
            'manifestar' => $manifestar,
        ]);
    }

    /**
     * XML completo de um CT-e, CT-e OS ou GTV-e pela chave de acesso (POST /api/consultar-cte-xml).
     *
     * @param string $chave       Chave de acesso com 44 dígitos.
     * @param string $certificado Caminho do arquivo .pfx/.p12 ou o conteúdo em base64.
     *                            O certificado não é armazenado pela API.
     * @param string $senha       Senha do certificado.
     */
    public function consultarCteXml(string $chave, string $certificado, string $senha): array
    {
        if (trim($chave) === '') {
            throw new \InvalidArgumentException('Informe a chave de acesso.');
        }
        return $this->request('POST', '/api/consultar-cte-xml', [], [
            // Aceita CNPJ alfanumérico: só tira separadores e põe em maiúsculas.
            'chave' => strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $chave) ?? ''),
            'certificado' => self::certificadoBase64($certificado),
            'senha' => $senha,
        ]);
    }

    /**
     * DIFAL de saída (EC 87/2015) por UF de destino, num mês
     * (POST /api/consultar-difal-saida). Beta.
     *
     * Lista as NF-e do emitente com DIFAL e FCP destacados, a situação da guia
     * de cada nota e o valor em aberto. Cobra 1 consulta por UF com
     * status "ok". Pode levar cerca de 70 s. Não é repetido em 5xx.
     *
     * @param string   $competencia Mês de emissão das notas, AAAA-MM (ex.: 2026-10).
     * @param string   $certificado Caminho do arquivo .pfx/.p12 do EMITENTE das notas
     *                              ou o conteúdo em base64. Não é armazenado pela API.
     * @param string   $senha       Senha do certificado.
     * @param string[] $ufs         Siglas das UFs de destino. Vazio = as 10 atendidas
     *                              (AC, AP, DF, GO, MS, MT, RR, RS, SC, SE).
     */
    public function consultarDifalSaida(
        string $competencia,
        string $certificado,
        string $senha,
        array $ufs = []
    ): array {
        $competencia = trim($competencia);
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $competencia)) {
            throw new \InvalidArgumentException('Informe a competência no formato AAAA-MM (ex.: 2026-10).');
        }
        $body = [
            'certificado' => self::certificadoBase64($certificado),
            'senha' => $senha,
            'competencia' => $competencia,
        ];
        if ($ufs !== []) {
            $body['ufs'] = array_values(array_map(static fn ($uf): string => self::uf((string) $uf), $ufs));
        }
        return $this->request('POST', '/api/consultar-difal-saida', [], $body);
    }

    /**
     * NFS-e (padrão nacional) pela chave de acesso, sem certificado
     * (GET /api/consultar-nfse). Com $danfse = true, inclui o PDF do DANFSe em
     * base64. Uma consulta nova pode levar de 30 a 60 segundos.
     */
    public function consultarNfse(string $chave, bool $danfse = false): array
    {
        $digitos = preg_replace('/\D/', '', $chave) ?? '';
        if ($digitos === '') {
            throw new \InvalidArgumentException('Informe a chave de acesso.');
        }
        return $this->get('/api/consultar-nfse', [
            'chave' => $digitos,
            'danfse' => $danfse ?: null,
        ]);
    }


    // ----------------------------------------------------- Monitor de NF-e

    /**
     * Coloca até 1.000 chaves de NF-e no monitor (POST /api/monitor-nfe/chaves).
     *
     * Cada chave aceita é cobrada uma vez e acompanhada por até $prazoDias (1 a 180,
     * padrão 180). Chave já monitorada não é cobrada de novo; chave inválida volta com
     * "motivo", sem cobrança. Sem saldo para todas as aceitas, lança
     * InsufficientBalanceException (402) e nenhuma entra. Não é repetido em 5xx.
     *
     * @param array<int, string|array{chave: string, status_conhecido?: string}> $chaves
     *        Chaves (string) ou ['chave' => ..., 'status_conhecido' => 'autorizada'].
     */
    public function cadastrarChavesMonitorNfe(array $chaves, ?int $prazoDias = null): array
    {
        $itens = [];
        foreach (self::lote($chaves) as $item) {
            if (\is_array($item)) {
                $novo = ['chave' => self::chaveNfe((string) ($item['chave'] ?? ''))];
                if (!empty($item['status_conhecido'])) {
                    $novo['status_conhecido'] = (string) $item['status_conhecido'];
                }
                $itens[] = $novo;
            } else {
                $itens[] = ['chave' => self::chaveNfe((string) $item)];
            }
        }
        $body = ['chaves' => $itens];
        if ($prazoDias !== null) {
            if ($prazoDias < 1 || $prazoDias > 180) {
                throw new \InvalidArgumentException('prazoDias deve ser um inteiro de 1 a 180.');
            }
            $body['prazo_dias'] = $prazoDias;
        }
        return $this->request('POST', self::MONITOR . '/chaves', [], $body);
    }

    /**
     * Para de monitorar até 1.000 chaves (POST /api/monitor-nfe/chaves/remover).
     * Sem estorno e sem aviso no webhook.
     *
     * @param string[] $chaves
     */
    public function removerChavesMonitorNfe(array $chaves): array
    {
        $lista = array_map(static fn ($c): string => self::chaveNfe((string) $c), self::lote($chaves));
        return $this->request('POST', self::MONITOR . '/chaves/remover', [], ['chaves' => $lista]);
    }

    /**
     * Para de monitorar uma chave (DELETE /api/monitor-nfe/chaves/{chave}).
     * Chave fora do monitor: BadRequestException com status 404.
     */
    public function removerChaveMonitorNfe(string $chave): void
    {
        $this->request('DELETE', self::MONITOR . '/chaves/' . self::chaveNfe($chave));
    }

    /**
     * Uma página das chaves monitoradas (GET /api/monitor-nfe/chaves).
     *
     * @param string|null                   $status        Só notas nesta situação.
     * @param bool|null                     $ativo         true: só monitoramentos correndo; false: só os encerrados.
     * @param string|\DateTimeInterface|null $alteradoDesde Só o que mudou a partir deste instante (o servidor volta
     *                                                     5 min: deduplique por chave + atualizada_em).
     * @param string|null                   $cursor        proximo_cursor da página anterior.
     * @param int|null                      $limite        Itens por página, de 1 a 1.000 (padrão do servidor: 100).
     */
    public function listarChavesMonitorNfe(
        ?string $status = null,
        ?bool $ativo = null,
        string|\DateTimeInterface|null $alteradoDesde = null,
        ?string $cursor = null,
        ?int $limite = null,
    ): array {
        if ($limite !== null && ($limite < 1 || $limite > 1000)) {
            throw new \InvalidArgumentException('limite deve ser um inteiro de 1 a 1.000.');
        }
        $desde = $alteradoDesde instanceof \DateTimeInterface
            ? $alteradoDesde->format(\DateTimeInterface::ATOM)
            : (($alteradoDesde ?? '') !== '' ? $alteradoDesde : null);
        return $this->get(self::MONITOR . '/chaves', [
            'status' => $status,
            'ativo' => $ativo,
            'alterado_desde' => $desde,
            'cursor' => ($cursor ?? '') !== '' ? $cursor : null,
            'limite' => $limite !== null ? (string) $limite : null,
        ]);
    }

    /**
     * Percorre todas as páginas da listagem, seguindo proximo_cursor.
     *
     *   foreach ($api->iterarChavesMonitorNfe(ativo: true) as $item) { ... }
     *
     * @return \Generator<int, array>
     */
    public function iterarChavesMonitorNfe(
        ?string $status = null,
        ?bool $ativo = null,
        string|\DateTimeInterface|null $alteradoDesde = null,
        ?int $limite = null,
    ): \Generator {
        $cursor = null;
        do {
            $pagina = $this->listarChavesMonitorNfe($status, $ativo, $alteradoDesde, $cursor, $limite);
            foreach ($pagina['itens'] ?? [] as $item) {
                yield $item;
            }
            $cursor = $pagina['proximo_cursor'] ?? null;
        } while (\is_string($cursor) && $cursor !== '');
    }

    /** Situação, eventos e entregas que falharam de uma chave (GET /api/monitor-nfe/chaves/{chave}). */
    public function consultarChaveMonitorNfe(string $chave): array
    {
        return $this->get(self::MONITOR . '/chaves/' . self::chaveNfe($chave), []);
    }

    /**
     * Cadastra ou troca a URL do webhook (PUT /api/monitor-nfe/webhook).
     * Só https na porta 443, com nome público. O "segredo" volta só aqui: guarde-o.
     * Cadastrar de novo gera outro segredo e reativa o webhook.
     */
    public function cadastrarWebhookMonitorNfe(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            throw new \InvalidArgumentException('Informe a URL do webhook.');
        }
        return $this->request('PUT', self::MONITOR . '/webhook', [], ['url' => $url]);
    }

    /** URL cadastrada e se os avisos estão saindo (GET /api/monitor-nfe/webhook). */
    public function consultarWebhookMonitorNfe(): array
    {
        return $this->get(self::MONITOR . '/webhook', []);
    }

    /** Remove o webhook (DELETE /api/monitor-nfe/webhook). O monitoramento continua. */
    public function removerWebhookMonitorNfe(): void
    {
        $this->request('DELETE', self::MONITOR . '/webhook');
    }

    /**
     * Gera um segredo novo (POST /api/monitor-nfe/webhook/rotacionar-segredo).
     * Por 24 h os avisos levam as duas assinaturas (v1=nova,v1=anterior).
     */
    public function rotacionarSegredoWebhookMonitorNfe(): array
    {
        return $this->request('POST', self::MONITOR . '/webhook/rotacionar-segredo');
    }

    /** Enfileira um aviso tipo "teste" (POST /api/monitor-nfe/webhook/teste). */
    public function testarWebhookMonitorNfe(): array
    {
        return $this->request('POST', self::MONITOR . '/webhook/teste');
    }

    // ------------------------------------------------------- Imóvel rural

    /** Parcelas certificadas no SIGEF/INCRA (GET /api/consultar-sigef). */
    public function consultarSigef(?string $cpf = null, ?string $cnpj = null): array
    {
        return $this->get('/api/consultar-sigef', self::umDocumento(['cpf' => $cpf, 'cnpj' => $cnpj]));
    }

    /** Uma parcela SIGEF pelo UUID (GET /api/consultar-sigef-parcela). */
    public function consultarSigefParcela(string $uuid, bool $forceFresh = false): array
    {
        if (trim($uuid) === '') {
            throw new \InvalidArgumentException('Informe o UUID da parcela.');
        }
        return $this->get('/api/consultar-sigef-parcela', [
            'uuid' => trim($uuid),
            'force_fresh' => $forceFresh ?: null,
        ]);
    }

    /** Imóveis rurais no CNIR/CAFIR (GET /api/consultar-cnir). */
    public function consultarCnir(?string $cpf = null, ?string $cnpj = null): array
    {
        return $this->get('/api/consultar-cnir', self::umDocumento(['cpf' => $cpf, 'cnpj' => $cnpj]));
    }

    // ------------------------------------------------------------- Perfil

    /** Perfil consolidado do produtor rural (GET /api/perfil-produtor). */
    public function perfilProdutor(?string $cpf = null, ?string $cnpj = null): array
    {
        return $this->get('/api/perfil-produtor', self::umDocumento(['cpf' => $cpf, 'cnpj' => $cnpj]));
    }

    // ---------------------------------------------------------- Transporte

    /** @param array<string, string|bool|null> $query */
    private function get(string $path, array $query): array
    {
        return $this->request('GET', $path, $query);
    }

    /**
     * @param array<string, string|bool|null> $query
     * @param array<string, mixed>|null       $body
     */
    private function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = $this->baseUrl . $path . self::encodeQuery($query);
        $headers = [
            'X-API-Key: ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: ' . $this->userAgent,
        ];
        $payload = null;
        if ($body !== null) {
            $payload = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }
        $idempotent = $method === 'GET';

        for ($attempt = 0; ; $attempt++) {
            $responseHeaders = [];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                    $parts = explode(':', $line, 2);
                    if (\count($parts) === 2) {
                        $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return \strlen($line);
                },
            ]);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            } elseif ($method === 'POST' || $method === 'PUT') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, ''); // Content-Length: 0
            }

            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            unset($ch); // curl_close() é no-op desde o PHP 8.0 e foi depreciado no 8.5

            if ($raw === false || $errno !== 0) {
                if ($idempotent && $attempt < $this->maxRetries) {
                    self::sleep(self::backoff($attempt, null));
                    continue;
                }
                throw new APIConnectionException('Falha ao conectar à FiscalAPI: ' . $error);
            }

            if ($status >= 200 && $status < 300) {
                return self::decodeSuccess((string) $raw);
            }

            $retryable = \in_array($status, self::RETRYABLE_ANY_METHOD, true)
                || ($idempotent && \in_array($status, self::RETRYABLE_IDEMPOTENT, true));
            if ($retryable && $attempt < $this->maxRetries) {
                self::sleep(self::backoff($attempt, $responseHeaders['retry-after'] ?? null));
                continue;
            }
            throw self::errorFromResponse($status, (string) $raw, $responseHeaders);
        }
    }

    // --------------------------------------------------------- utilitários

    /**
     * @param array<string, string|null> $docs
     * @return array<string, string>
     */
    private static function umDocumento(array $docs): array
    {
        $informados = [];
        foreach ($docs as $nome => $valor) {
            if ($valor !== null && trim($valor) !== '') {
                $informados[$nome] = trim($valor);
            }
        }
        $nomes = implode(' ou ', array_keys($docs));
        if (\count($informados) === 0) {
            throw new \InvalidArgumentException("Informe {$nomes}.");
        }
        if (\count($informados) > 1) {
            throw new \InvalidArgumentException("Informe apenas um: {$nomes}.");
        }
        return $informados;
    }

    /**
     * @param array<int, mixed> $chaves
     * @return array<int, mixed>
     */
    private static function lote(array $chaves): array
    {
        if ($chaves === []) {
            throw new \InvalidArgumentException('Informe ao menos uma chave.');
        }
        if (\count($chaves) > self::MONITOR_NFE_MAX_LOTE) {
            throw new \InvalidArgumentException('Envie no máximo ' . self::MONITOR_NFE_MAX_LOTE . ' chaves por chamada.');
        }
        return array_values($chaves);
    }

    /** Chave de NF-e só com dígitos (tira pontos, espaços e o prefixo "NFe"). */
    private static function chaveNfe(string $chave): string
    {
        $digitos = preg_replace('/\D/', '', $chave) ?? '';
        if ($digitos === '') {
            throw new \InvalidArgumentException('Informe a chave de acesso.');
        }
        return $digitos;
    }

    private static function uf(string $uf): string
    {
        $uf = strtoupper(trim($uf));
        if (\strlen($uf) !== 2) {
            throw new \InvalidArgumentException('Informe a UF com 2 letras (ex.: MT).');
        }
        return $uf;
    }

    /** @param array<string, string|bool|null> $query */
    private static function encodeQuery(array $query): string
    {
        $clean = [];
        foreach ($query as $k => $v) {
            if ($v === null) {
                continue;
            }
            $clean[$k] = \is_bool($v) ? ($v ? 'true' : 'false') : $v;
        }
        return $clean === [] ? '' : '?' . http_build_query($clean, '', '&', PHP_QUERY_RFC3986);
    }

    private static function certificadoBase64(string $certificado): string
    {
        if (\strlen($certificado) < 4096 && @is_file($certificado)) {
            $bytes = file_get_contents($certificado);
            if ($bytes === false) {
                throw new \RuntimeException("Não foi possível ler o certificado: {$certificado}");
            }
            return base64_encode($bytes);
        }
        return preg_replace('/\s+/', '', $certificado) ?? $certificado;
    }

    private static function backoff(int $attempt, ?string $retryAfter): float
    {
        if ($retryAfter !== null && is_numeric($retryAfter) && (float) $retryAfter >= 0) {
            return min((float) $retryAfter, self::MAX_RETRY_AFTER);
        }
        $base = min(self::MAX_BACKOFF, 0.5 * (2 ** $attempt));
        return $base + (mt_rand() / mt_getrandmax()) * ($base / 4);
    }

    private static function sleep(float $seconds): void
    {
        usleep((int) round($seconds * 1_000_000));
    }

    private static function decodeSuccess(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new FiscalAPIException('Resposta da API não é JSON válido.', body: substr($raw, 0, 500), previous: $e);
        }
        return \is_array($data) ? $data : ['data' => $data];
    }

    /** @param array<string, string> $headers */
    private static function errorFromResponse(int $status, string $raw, array $headers): FiscalAPIException
    {
        $body = null;
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            $body = \is_array($decoded) ? $decoded : substr($raw, 0, 500);
        }

        $code = null;
        $message = "Erro HTTP {$status}";
        $requestId = null;
        if (\is_array($body)) {
            $c = $body['error'] ?? $body['title'] ?? null;
            $code = \is_string($c) ? $c : null;
            $detail = $body['message'] ?? $body['detail'] ?? null;
            if (\is_string($detail) && $detail !== '') {
                $message = $detail;
            } elseif (\is_array($detail) && $detail !== []) {
                $message = implode('; ', array_map(
                    static fn ($d) => \is_array($d) && isset($d['msg']) ? (string) $d['msg'] : json_encode($d),
                    $detail,
                ));
            }
            $rid = $body['request_id'] ?? null;
            $requestId = \is_string($rid) && $rid !== '' ? $rid : null;
        } elseif (\is_string($body) && $body !== '') {
            $message = $body;
        }

        $args = [$message, $status, $code, $requestId, $body, $headers];
        return match (true) {
            $status === 401, $status === 403 => new AuthenticationException(...$args),
            $status === 402 => new InsufficientBalanceException(...$args),
            $status === 429 => new RateLimitException(...$args),
            $status >= 500 => new ServerException(...$args),
            $status >= 400 => new BadRequestException(...$args),
            default => new FiscalAPIException(...$args),
        };
    }
}
