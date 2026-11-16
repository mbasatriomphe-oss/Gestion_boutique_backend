<?php

namespace App\Services;

use App\Models\ligne_approvisionnements;
use App\Models\lots;
use App\Models\mouvements_stock_fifos;
use App\Models\caisse;
use App\Models\transactions_caisses;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PurchaseLineEditor
{
    public function update(int $id, array $changes): ligne_approvisionnements
    {
        return DB::transaction(function () use ($id, $changes) {
            $line = ligne_approvisionnements::query()->lockForUpdate()->findOrFail($id);
            foreach (['id_produit', 'id_approvisionnement', 'id_unite'] as $field) {
                if (isset($changes[$field]) && (int) $changes[$field] !== (int) $line->$field) {
                    throw ValidationException::withMessages(['lignes' => 'Le produit, l’unité et la réception d’une ligne existante ne peuvent pas être remplacés.']);
                }
            }
            $next = array_merge($line->only(['quantite', 'prix_unitaire', 'prix_vente', 'id_devise', 'paye_par_caisse']), $changes);
            $stockChanged = !BigDecimal::of((string) $line->quantite)->isEqualTo((string) $next['quantite']);
            $costChanged = !BigDecimal::of((string) $line->prix_unitaire)->isEqualTo((string) $next['prix_unitaire']);
            $currencyChanged = (int) $line->id_devise !== (int) $next['id_devise'];
            $paymentChanged = (bool) $line->paye_par_caisse !== (bool) $next['paye_par_caisse'];
            $economicChange = $stockChanged || $costChanged || $currencyChanged || $paymentChanged;
            $lots = lots::query()->where('id_ligne_approvisionnement', $id)->orderBy('id')->lockForUpdate()->get();
            if ($economicChange && mouvements_stock_fifos::query()->whereIn('id_lot', $lots->modelKeys())->whereIn('type_mouvement', ['sortie', 'retour'])->exists()) {
                throw ValidationException::withMessages(['lignes' => 'Ce lot a déjà été vendu ou retourné. Seul son prix de vente peut être modifié ; ses quantités, coûts, devise et paiement sont conservés.']);
            }
            if ($stockChanged && $lots->count() !== 1) {
                throw ValidationException::withMessages(['lignes' => 'La quantité ne peut être corrigée que pour une ligne associée à un seul lot.']);
            }
            if ($economicChange) {
                $this->adjustCash($line, $next);
            }
            $factor = $line->facteur_conversion ?: 1;
            $conversion = app(ProductUnitConversionService::class);
            $line->update([
                'quantite' => $next['quantite'],
                'prix_unitaire' => $next['prix_unitaire'],
                'prix_vente' => $next['prix_vente'],
                'id_devise' => $next['id_devise'],
                'paye_par_caisse' => (bool) $next['paye_par_caisse'],
                'quantite_stock' => $conversion->quantityInStockUnit($next['quantite'], $factor),
                'prix_unitaire_stock' => $conversion->pricePerStockUnit($next['prix_unitaire'], $factor),
                'prix_vente_stock' => $next['prix_vente'] !== null ? $conversion->pricePerStockUnit($next['prix_vente'], $factor) : null,
            ]);
            foreach ($lots as $lot) {
                $values = ['id_devise' => $line->id_devise, 'prix_unitaire_stock' => $line->prix_unitaire_stock];
                if ($stockChanged) {
                    $values['quantite_initial'] = $line->quantite_stock;
                    mouvements_stock_fifos::query()->where('id_lot', $lot->id)->where('type_mouvement', 'entree')->update([
                        'quantite' => $line->quantite_stock, 'quantite_restante_avant' => 0, 'quantite_restante_apres' => $line->quantite_stock,
                    ]);
                }
                $lot->update($values);
            }
            return $line->fresh(['produit.unite', 'unite', 'devise', 'lots']);
        });
    }

    private function adjustCash(ligne_approvisionnements $line, array $next): void
    {
        $deltas = [];
        if ($line->paye_par_caisse) {
            $deltas[$line->id_devise] = BigDecimal::of($line->quantite)->multipliedBy($line->prix_unitaire);
        }
        if ($next['paye_par_caisse']) {
            $id = (int) $next['id_devise'];
            $deltas[$id] = ($deltas[$id] ?? BigDecimal::zero())->minus(BigDecimal::of((string) $next['quantite'])->multipliedBy((string) $next['prix_unitaire']));
        }
        ksort($deltas);
        foreach ($deltas as $currency => $delta) {
            if ($delta->isZero()) continue;
            $cash = caisse::query()->where('id_devise', $currency)->lockForUpdate()->first();
            if (!$cash) throw ValidationException::withMessages(['lignes' => "Caisse absente pour la devise #$currency."]);
            $before = BigDecimal::of($cash->solde);
            $after = $before->plus($delta)->toScale(8, RoundingMode::HALF_UP);
            if ($after->isNegative()) throw ValidationException::withMessages(['lignes' => "Solde de caisse insuffisant pour la devise #$currency."]);
            $cash->update(['solde' => (string) $after]);
            transactions_caisses::create([
                'id_caisse' => $cash->id, 'type' => $delta->isPositive() ? 'entree' : 'sortie',
                'montant' => (string) $delta->abs()->toScale(8, RoundingMode::HALF_UP),
                'reference_type' => 'correction_approvisionnement', 'reference_id' => $line->id,
                'description' => 'Correction achat #'.$line->id_approvisionnement,
                'solde_avant' => (string) $before, 'solde_apres' => (string) $after,
            ]);
        }
    }
}
