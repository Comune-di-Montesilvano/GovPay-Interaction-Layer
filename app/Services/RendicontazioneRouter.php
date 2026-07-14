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
        $isGil = str_starts_with($idPendenza, $iuvPrefixGil) || str_starts_with($iuv, $iuvPrefixGil);

        if ($isGil) {
            if ($gruppoAssociato !== null && $gruppoAssociato['modalita'] === 'NOTIFICA_E_SMARCATURA') {
                return new RendicontazioneDecision('IN_ATTESA_CONFERMA', null);
            }
            return new RendicontazioneDecision('GESTITO', 'GIL_MANUALE');
        }

        $best = null;
        foreach ($regoleEsterne as $regola) {
            if ($regola['pattern_tipo'] === 'IUV_PREFIX' && !str_starts_with($iuv, $regola['pattern_valore'])) {
                continue;
            }
            if ($regola['pattern_tipo'] === 'ID_APP_AGID') {
                $idApp = strlen($iuv) > 3 ? substr($iuv, 3, 1) : '';
                if ($idApp !== $regola['pattern_valore']) {
                    continue;
                }
            }
            if ($best === null || strlen($regola['pattern_valore']) > strlen($best['pattern_valore'])) {
                $best = $regola;
            }
        }

        if ($best !== null) {
            return new RendicontazioneDecision('GESTITO', $best['handler']);
        }

        return new RendicontazioneDecision('GESTITO', 'AUTO_ESTERNO');
    }
}
