<?php
declare(strict_types=1);

namespace App\Services;

final class RendicontazioneDecision
{
    public function __construct(
        public readonly string $stato,
        public readonly ?string $handler
    ) {
    }
}

final class RendicontazioneRouter
{
    /**
     * @param array{modalita:string}|null $gruppoAssociato
     * @param array<int,array{pattern_tipo:string,pattern_valore:string,handler:string}> $regoleEsterne
     */
    public static function decide(
        string $idPendenza,
        string $iuv,
        string $iuvPrefixGil,
        ?array $gruppoAssociato,
        array $regoleEsterne
    ): RendicontazioneDecision {
        $isGil = str_starts_with($idPendenza, $iuvPrefixGil);

        if ($isGil) {
            if ($gruppoAssociato !== null && $gruppoAssociato['modalita'] === 'NOTIFICA_E_SMARCATURA') {
                return new RendicontazioneDecision('IN_ATTESA_CONFERMA', null);
            }
            return new RendicontazioneDecision('GESTITO', 'GIL_MANUALE');
        }

        $best = null;
        foreach ($regoleEsterne as $regola) {
            $tipo = (string)($regola['pattern_tipo'] ?? '');
            $valore = (string)($regola['pattern_valore'] ?? '');

            if ($tipo === 'IUV_PREFIX' && !str_starts_with($iuv, $valore)) {
                continue;
            }
            if ($tipo === 'ID_APP_AGID') {
                $idApp = strlen($iuv) > 3 ? substr($iuv, 3, 1) : '';
                if ($idApp !== $valore) {
                    continue;
                }
            }
            if (in_array($tipo, ['REGEX', 'REGEXP'], true)) {
                if ($valore === '') {
                    continue;
                }
                try {
                    $pattern = '/' . str_replace('/', '\/', $valore) . '/';
                    if (@preg_match($pattern, $iuv) !== 1) {
                        continue;
                    }
                } catch (\Throwable $_) {
                    continue;
                }
            }
            if ($best === null || strlen($valore) > strlen((string)($best['pattern_valore'] ?? ''))) {
                $best = $regola;
            }
        }

        if ($best !== null) {
            return new RendicontazioneDecision('GESTITO', (string)($best['handler'] ?? 'AUTO_ESTERNO'));
        }

        return new RendicontazioneDecision('GESTITO', 'AUTO_ESTERNO');
    }
}
