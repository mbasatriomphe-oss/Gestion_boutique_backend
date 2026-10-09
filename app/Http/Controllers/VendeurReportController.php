<?php

namespace App\Http\Controllers;

use App\Models\Taux;
use App\Models\ventes;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VendeurReportController extends Controller
{
    public function export(Request $request)
    {
        $filters = $request->validate([
            'type' => ['required', 'in:ventes,produits,clients,credits,encaissements,client_sales,benefices'],
            'format' => ['required', 'in:pdf,csv,json'],
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date', 'after_or_equal:date_debut'],
            'id_client' => ['required_if:type,client_sales', 'nullable', 'integer', 'exists:clients,id'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        if ($filters['type'] === 'benefices' && ($request->user()?->role ?? null) !== 'admin') {
            abort(403, 'Le rapport des bénéfices est réservé à l’administration.');
        }

        $sales = ventes::query()
            ->with([
                'client',
                'vendeur',
                'deviseVente',
                'lignes.produit.unite',
                'lignes.unite',
                'lignes.devise',
                'lignes.mouvementsStock.lot.devise',
                'transactionsCaisses.caisse.devise',
                'transactionsCaisses.creator',
            ])
            ->when($filters['date_debut'] ?? null, fn ($query, $date) => $query->whereDate('date', '>=', $date))
            ->when($filters['date_fin'] ?? null, fn ($query, $date) => $query->whereDate('date', '<=', $date))
            ->when($filters['id_client'] ?? null, fn ($query, $id) => $query->where('id_client', $id))
            ->when(trim($filters['search'] ?? '') !== '', function ($query) use ($filters): void {
                $search = trim($filters['search']);
                $query->where(function ($inner) use ($search): void {
                    $inner->where('code', 'like', '%'.$search.'%')
                        ->orWhereHas('client', function ($clientQuery) use ($search): void {
                            $clientQuery->where('nom', 'like', '%'.$search.'%')
                                ->orWhere('prenom', 'like', '%'.$search.'%')
                                ->orWhere('post_nom', 'like', '%'.$search.'%');
                        })
                        ->orWhereHas('vendeur', function ($sellerQuery) use ($search): void {
                            $sellerQuery->where('nom', 'like', '%'.$search.'%')
                                ->orWhere('prenom', 'like', '%'.$search.'%');
                        });
                });
            })
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        [$title, $headers, $rows] = $this->buildReport($filters['type'], $sales);
        $clientName = $filters['type'] === 'client_sales'
            ? $this->personName($sales->first()?->client ?? \App\Models\clients::query()->find($filters['id_client']))
            : null;
        if ($clientName !== null) {
            $title .= ' : '.$clientName;
        }
        $period = isset($filters['date_debut']) || isset($filters['date_fin'])
            ? $this->periodDate($filters['date_debut'] ?? null, 'Début').' — '.$this->periodDate($filters['date_fin'] ?? null, 'Aujourd’hui')
            : 'Toutes les périodes';
        $filename = 'rapport-'.$filters['type'].'-'.now()->format('Ymd-His');

        if ($filters['format'] === 'json') {
            return response()->json([
                'title' => $title,
                'period' => $period,
                'client' => $clientName,
                'headers' => $headers,
                'rows' => $rows,
            ]);
        }

        if ($filters['format'] === 'csv') {
            return $this->csvResponse($filename, $title, $period, $clientName, $headers, $rows);
        }

        $pdf = Pdf::loadView('reports.vendeur', [
            'title' => $title,
            'period' => $period,
            'clientName' => $clientName,
            'headers' => $headers,
            'rows' => $rows,
            'generatedAt' => now()->format('d/m/Y H:i'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download($filename.'.pdf');
    }

    /**
     * @return array{0:string,1:array<int,string>,2:array<int,array<int,string|int|float|null>>}
     */
    private function buildReport(string $type, Collection $sales): array
    {
        if ($type === 'client_sales') {
            $rows = [];
            foreach ($sales as $sale) {
                if ($sale->lignes->isEmpty()) {
                    $rows[] = [
                        $this->dateLabel($sale),
                        $sale->code ?? (string) $sale->id,
                        '—',
                        '—',
                        '—',
                        $this->personName($sale->vendeur),
                        $this->currencyLabel($sale->deviseVente),
                        $sale->montant_total,
                        $sale->montant_paye,
                        $sale->reste_a_payer,
                        $sale->type_paiement,
                    ];

                    continue;
                }

                foreach ($sale->lignes as $line) {
                    $rows[] = [
                        $this->dateLabel($sale),
                        $sale->code ?? (string) $sale->id,
                        $line->produit?->nom ?? 'Produit supprimé',
                        (float) $line->quantite,
                        $line->unite?->symbole ?: $line->unite?->nom ?: $line->produit?->unite?->symbole ?: $line->produit?->unite?->nom ?: '—',
                        $this->personName($sale->vendeur),
                        $this->currencyLabel($sale->deviseVente),
                        $sale->montant_total,
                        $sale->montant_paye,
                        $sale->reste_a_payer,
                        $sale->type_paiement,
                    ];
                }
            }

            return [
                'Ventes du client',
                ['Date', 'Référence', 'Produit', 'Quantité', 'Unité', 'Vendeur', 'Devise', 'Total', 'Payé', 'Reste dû', 'Type de paiement'],
                $rows,
            ];
        }

        if ($type === 'ventes') {
            $rows = $sales->map(fn (ventes $sale) => [
                $this->dateLabel($sale),
                $sale->code ?? (string) $sale->id,
                $this->personName($sale->client),
                $this->personName($sale->vendeur),
                $sale->lignes->map(fn ($line) => ($line->produit?->nom ?? 'Produit').' × '.$line->quantite)->implode(', '),
                $this->currencyLabel($sale->deviseVente),
                $sale->montant_total,
                $sale->montant_paye,
                $sale->reste_a_payer,
                $sale->statut_paiement,
            ])->all();

            return ['Détail des ventes', ['Date', 'Référence', 'Client', 'Vendeur', 'Articles', 'Devise', 'Total', 'Payé', 'Reste dû', 'Statut'], $rows];
        }

        if ($type === 'credits') {
            $rows = $sales->filter(fn (ventes $sale) => (float) $sale->reste_a_payer > 0)
                ->map(fn (ventes $sale) => [
                    $this->dateLabel($sale),
                    $sale->code ?? (string) $sale->id,
                    $this->personName($sale->client),
                    $sale->client?->telephone ?? $sale->client?->contact ?? '',
                    $this->currencyLabel($sale->deviseVente),
                    $sale->montant_total,
                    $sale->montant_paye,
                    $sale->reste_a_payer,
                    $sale->type_paiement ?? '',
                ])->values()->all();

            return ['Crédits à recouvrer', ['Date', 'Référence', 'Client', 'Téléphone', 'Devise', 'Total', 'Payé', 'Reste dû', 'Type'], $rows];
        }

        if ($type === 'encaissements') {
            $rows = [];
            foreach ($sales as $sale) {
                foreach ($sale->transactionsCaisses as $transaction) {
                    $rows[] = [
                        $transaction->created_at?->format('d/m/Y H:i') ?? '',
                        $sale->code ?? (string) $sale->id,
                        $this->personName($sale->client),
                        $this->personName($transaction->creator),
                        $this->currencyLabel($transaction->caisse?->devise),
                        $transaction->type,
                        $transaction->montant,
                        $transaction->description,
                    ];
                }
            }

            return ['Encaissements', ['Date', 'Vente', 'Client', 'Enregistré par', 'Devise', 'Type', 'Montant', 'Description'], $rows];
        }

        if ($type === 'benefices') {
            $groups = [];
            foreach ($sales as $sale) {
                foreach ($sale->lignes as $line) {
                    $currency = $line->devise ?? $sale->deviseVente;
                    $currencyId = (int) ($line->id_devise ?? $sale->devise_vente_id ?? 0);
                    $currencyCode = $this->currencyLabel($currency);
                    $key = $line->id_produit.'|'.$currencyId;
                    $revenue = (float) $line->quantite * (float) $line->prix_vente;
                    $cost = 0.0;
                    $hasCost = false;
                    $hasIncompleteCost = false;

                    foreach ($line->mouvementsStock as $movement) {
                        if ($movement->type_mouvement !== 'sortie' || ! $movement->lot) {
                            continue;
                        }

                        $hasCost = true;
                        $lot = $movement->lot;
                        $lotCurrencyId = (int) ($lot->id_devise ?? 0);
                        if ($lot->prix_unitaire_stock === null) {
                            $hasIncompleteCost = true;

                            continue;
                        }
                        $unitCost = (float) $lot->prix_unitaire_stock;

                        if ($lotCurrencyId !== $currencyId) {
                            $rate = $this->resolveRate($lotCurrencyId, $currencyId, (string) $sale->date);
                            if ($rate === null) {
                                $hasIncompleteCost = true;

                                continue;
                            }
                            $unitCost *= $rate;
                        }

                        $cost += (float) $movement->quantite * $unitCost;
                    }

                    $groups[$key] ??= [
                        $line->produit?->nom ?? 'Produit supprimé',
                        $currencyCode,
                        0.0,
                        0.0,
                        0.0,
                        0.0,
                        true,
                    ];
                    $groups[$key][2] += (float) $line->quantite;
                    $groups[$key][3] += $revenue;

                    if (! $hasCost || $hasIncompleteCost) {
                        $groups[$key][6] = false;
                    } else {
                        $groups[$key][4] += $cost;
                        $groups[$key][5] += $revenue - $cost;
                    }
                }
            }

            $rows = array_map(static function (array $row): array {
                if (! $row[6]) {
                    $row[4] = 'Indisponible';
                    $row[5] = 'Indisponible';
                }
                unset($row[6]);

                return $row;
            }, array_values($groups));

            return ['Bénéfices bruts par produit', ['Produit', 'Devise', 'Quantité vendue', 'Chiffre d’affaires', 'Coût FIFO', 'Bénéfice brut'], $rows];
        }

        if ($type === 'clients') {
            $groups = [];
            foreach ($sales as $sale) {
                $currency = $this->currencyLabel($sale->deviseVente);
                $key = $sale->id_client.'|'.$currency;
                $groups[$key] ??= [
                    $this->personName($sale->client),
                    $sale->client?->telephone ?? $sale->client?->contact ?? '',
                    $currency,
                    0,
                    0.0,
                    0.0,
                    0.0,
                ];
                $groups[$key][3]++;
                $groups[$key][4] += (float) $sale->montant_total;
                $groups[$key][5] += (float) $sale->montant_paye;
                $groups[$key][6] += (float) $sale->reste_a_payer;
            }

            return ['Achats par client', ['Client', 'Téléphone', 'Devise', 'Nombre de ventes', 'Total', 'Payé', 'Reste dû'], array_values($groups)];
        }

        $groups = [];
        foreach ($sales as $sale) {
            foreach ($sale->lignes as $line) {
                $currency = $this->currencyLabel($line->devise ?? $sale->deviseVente);
                $key = $line->id_produit.'|'.$currency;
                $groups[$key] ??= [
                    $line->produit?->nom ?? 'Produit supprimé',
                    $line->produit?->code ?? '',
                    $currency,
                    0.0,
                    0.0,
                    0,
                ];
                $groups[$key][3] += (float) $line->quantite;
                $groups[$key][4] += (float) $line->quantite * (float) $line->prix_vente;
                $groups[$key][5]++;
            }
        }

        return ['Produits vendus', ['Produit', 'Code', 'Devise', 'Quantité vendue', 'Montant vendu', 'Lignes vendues'], array_values($groups)];
    }

    private function csvResponse(string $filename, string $title, string $period, ?string $clientName, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($title, $period, $clientName, $headers, $rows): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new \RuntimeException('Impossible de créer le fichier CSV.');
            }

            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Rapport', $title], ';');
            fputcsv($stream, ['Période', $period], ';');
            if ($clientName !== null) {
                fputcsv($stream, ['Client', $clientName], ';');
            }
            fputcsv($stream, [], ';');
            fputcsv($stream, $headers, ';');
            foreach ($rows as $row) {
                fputcsv($stream, $row, ';');
            }
            fclose($stream);
        }, $filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function periodDate(?string $date, string $fallback): string
    {
        return $date ? \Illuminate\Support\Carbon::parse($date)->format('d/m/Y') : $fallback;
    }

    private function dateLabel(ventes $sale): string
    {
        return $sale->date?->format('d/m/Y') ?? $sale->created_at?->format('d/m/Y') ?? '';
    }

    private function personName(mixed $person): string
    {
        if (! $person) {
            return '—';
        }

        return collect([$person->prenom ?? null, $person->post_nom ?? null, $person->nom ?? null])
            ->filter()
            ->implode(' ') ?: '—';
    }

    private function currencyLabel(mixed $currency): string
    {
        return $currency?->symbole ?: $currency?->code ?: '—';
    }

    private function resolveRate(int $sourceDeviseId, int $targetDeviseId, string $date): ?float
    {
        if (! $sourceDeviseId || ! $targetDeviseId) {
            return null;
        }
        if ($sourceDeviseId === $targetDeviseId) {
            return 1.0;
        }

        $direct = Taux::query()
            ->where('statut', 'actif')
            ->where('devise_source', $sourceDeviseId)
            ->where('devise_but', $targetDeviseId)
            ->whereDate('date_effet', '<=', $date)
            ->orderByDesc('date_effet')
            ->orderByDesc('id')
            ->value('valeur');

        if ($direct !== null && (float) $direct > 0) {
            return (float) $direct;
        }

        $reverse = Taux::query()
            ->where('statut', 'actif')
            ->where('devise_source', $targetDeviseId)
            ->where('devise_but', $sourceDeviseId)
            ->whereDate('date_effet', '<=', $date)
            ->orderByDesc('date_effet')
            ->orderByDesc('id')
            ->value('valeur');

        return $reverse !== null && (float) $reverse > 0 ? 1 / (float) $reverse : null;
    }
}
