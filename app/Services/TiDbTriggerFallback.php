<?php

namespace App\Services;

use App\Models\caisse;
use App\Models\ligne_approvisionnements;
use App\Models\ligne_retours;
use App\Models\lots;
use App\Models\mouvements_stock_fifos;
use App\Models\transactions_caisses;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TiDbTriggerFallback
{
    public static function purchaseCreated(ligne_approvisionnements $line): void
    {
        if ($line->paye_par_caisse) {
            self::debit((int) $line->id_devise, BigDecimal::of($line->quantite)->multipliedBy($line->prix_unitaire), 'approvisionnement', (int) $line->id);
        }
    }

    public static function returnCreated(ligne_retours $line): void
    {
        DB::transaction(function () use ($line): void {
            self::debit((int) $line->id_devise, BigDecimal::of($line->prix_remboursement)->multipliedBy($line->quantite_retournee), 'retour', (int) $line->id_retour, false);

            if ($line->etat_produit !== 'bon' || !$line->reintegre_stock) {
                return;
            }

            $lot = lots::query()->lockForUpdate()->findOrFail($line->id_lot);
            $outgoing = mouvements_stock_fifos::query()->where('id_lot', $lot->id)->where('type_mouvement', 'sortie')->sum('quantite');
            $before = BigDecimal::of((string) $lot->quantite_initial)->minus((string) $outgoing);
            $quantity = BigDecimal::of((string) $line->quantite_retournee);
            $lot->increment('quantite_initial', $line->quantite_retournee);
            mouvements_stock_fifos::create([
                'id_lot' => $lot->id,
                'id_ligne_retour' => $line->id,
                'type_mouvement' => 'retour',
                'quantite' => (string) $quantity,
                'quantite_restante_avant' => (string) $before,
                'quantite_restante_apres' => (string) $before->plus($quantity),
                'date_mouvement' => now()->toDateString(),
            ]);
        });
    }

    private static function debit(int $currency, BigDecimal $amount, string $reference, int $referenceId, bool $checkBalance = true): void
    {
        DB::transaction(function () use ($currency, $amount, $reference, $referenceId, $checkBalance): void {
            $cash = caisse::query()->where('id_devise', $currency)->lockForUpdate()->firstOrFail();
            $before = BigDecimal::of($cash->solde);
            if ($checkBalance && $before->isLessThan($amount)) {
                throw ValidationException::withMessages(['lignes' => 'Solde insuffisant pour cet approvisionnement']);
            }
            $after = $before->minus($amount)->toScale(8, RoundingMode::HALF_UP);
            $cash->update(['solde' => (string) $after]);
            transactions_caisses::create([
                'id_caisse' => $cash->id,
                'type' => 'sortie',
                'montant' => (string) $amount->toScale(8, RoundingMode::HALF_UP),
                'reference_type' => $reference,
                'reference_id' => $referenceId,
                'description' => "$reference #$referenceId",
                'solde_avant' => (string) $before,
                'solde_apres' => (string) $after,
            ]);
        });
    }
}
