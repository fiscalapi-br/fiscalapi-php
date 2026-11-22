<?php

declare(strict_types=1);

namespace FiscalApiBr;

/**
 * Verificação da assinatura dos avisos do Monitor de NF-e e da Emissão de
 * NFS-e (o esquema é o mesmo nos dois produtos).
 *
 * Cada aviso chega com X-FiscalAPI-Timestamp (segundos Unix) e
 * X-FiscalAPI-Assinatura: v1=<hex>, onde hex = HMAC-SHA256(segredo,
 * "{timestamp}." . corpo_bruto) e o segredo é a string inteira "whsec_...".
 * Na troca de segredo o cabeçalho traz "v1=nova,v1=anterior" por 24 h.
 *
 * Exemplo:
 *   $ok = \FiscalApiBr\Webhook::verificarAssinatura(
 *       file_get_contents('php://input'),
 *       $_SERVER['HTTP_X_FISCALAPI_ASSINATURA'] ?? null,
 *       $_SERVER['HTTP_X_FISCALAPI_TIMESTAMP'] ?? null,
 *       (string) getenv('FISCALAPI_WEBHOOK_SEGREDO'),
 *   );
 */
final class Webhook
{
    /** Diferença máxima aceita entre o timestamp do aviso e o relógio local, em segundos. */
    public const TOLERANCIA_PADRAO = 300;

    /** Hex da assinatura v1 de um corpo: útil para testar seu receptor. */
    public static function assinar(string $segredo, int|string $timestamp, string $corpo): string
    {
        return hash_hmac('sha256', trim((string) $timestamp) . '.' . $corpo, $segredo);
    }

    /**
     * Confere se um aviso do Monitor de NF-e ou da Emissão de NFS-e veio da FiscalAPI.
     * Depois, deduplique: no monitor, pelo "id" do corpo; na emissão, por
     * X-FiscalAPI-Entrega + "updated_at".
     *
     * @param string          $corpoBruto          Corpo exatamente como chegou (php://input).
     * @param string|null     $cabecalhoAssinatura Valor de X-FiscalAPI-Assinatura.
     * @param string|int|null $cabecalhoTimestamp  Valor de X-FiscalAPI-Timestamp.
     * @param string          $segredo             Segredo do webhook ("whsec_...").
     * @param int             $tolerancia          Diferença máxima aceita, em segundos (0 desliga).
     * @param int|null        $agora               Instante atual em segundos Unix (para testes).
     *
     * @return bool true só se alguma assinatura v1= bater e o timestamp estiver na tolerância.
     */
    public static function verificarAssinatura(
        string $corpoBruto,
        ?string $cabecalhoAssinatura,
        string|int|null $cabecalhoTimestamp,
        string $segredo,
        int $tolerancia = self::TOLERANCIA_PADRAO,
        ?int $agora = null,
    ): bool {
        if ($segredo === '' || $cabecalhoAssinatura === null || $cabecalhoAssinatura === '' || $cabecalhoTimestamp === null) {
            return false;
        }
        $ts = trim((string) $cabecalhoTimestamp);
        if (!preg_match('/^\d+$/', $ts)) {
            return false;
        }
        $agora ??= time();
        if ($tolerancia > 0 && abs($agora - (int) $ts) > $tolerancia) {
            return false;
        }

        $esperada = self::assinar($segredo, $ts, $corpoBruto);
        $ok = false;
        foreach (explode(',', $cabecalhoAssinatura) as $parte) {
            $parte = trim($parte);
            if (!str_starts_with($parte, 'v1=')) {
                continue;
            }
            $recebida = strtolower(trim(substr($parte, 3)));
            $ok = hash_equals($esperada, $recebida) || $ok;
        }
        return $ok;
    }
}
