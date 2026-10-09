<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use App\Models\Taux;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\Process\Process;

class ReportController extends BaseController
{
    /**
     * Generate ventes PDF using external renderer (headless Chrome)
     */
    public function ventesPdf(Request $request)
    {
        try {
            $ventes = DB::table('ventes')->get();
            return $this->generatePdfFromView('reports.ventes', ['ventes' => $ventes], 'ventes.pdf');
        } catch (\Exception $e) {
            if (config('app.debug')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ], 500);
            }
            return response()->json(['message' => 'Erreur interne lors de la génération du PDF ventes.'], 500);
        }
    }

    /**
     * Generate stock PDF using external renderer (headless Chrome)
     */
    public function stockPdf(Request $request)
    {
        try {
            $produits = DB::table('produits')->get();
            return $this->generatePdfFromView('reports.stock', ['produits' => $produits], 'stock.pdf');
        } catch (\Exception $e) {
            if (config('app.debug')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ], 500);
            }
            return response()->json(['message' => 'Erreur interne lors de la génération du PDF stock.'], 500);
        }
    }

    private function buildStockFifoRows(?int $productId = null, ?int $targetDeviseId = null)
    {
        $query = DB::table('mouvements_stock_fifos as m')
            ->join('lots as l', 'l.id', '=', 'm.id_lot')
            ->join('produits as p', 'p.id', '=', 'l.id_produit')
            ->leftJoin('ligne_approvisionnements as la', 'la.id', '=', 'l.id_ligne_approvisionnement')
            ->leftJoin('devises as d', 'd.id', '=', 'l.id_devise')
            ->select(
                'm.id as mouvement_id',
                'p.code as produit_code',
                'p.nom as produit_nom',
                'l.numero_lot',
                'l.date_reception',
                'm.date_mouvement',
                'm.type_mouvement',
                'm.quantite',
                'm.quantite_restante_apres',
                'l.id_devise',
                'd.code as source_devise_code',
                'd.symbole as source_devise_symbole',
                DB::raw('COALESCE(la.prix_unitaire_stock, la.prix_unitaire / NULLIF(la.facteur_conversion, 0), l.prix_unitaire_stock) as prix_unitaire')
            )
            ->when($productId, fn($q) => $q->where('l.id_produit', $productId))
            ->orderBy('m.date_mouvement')
            ->orderBy('m.id');

        return $query->get()->map(function ($row) use ($targetDeviseId) {
            $cu = (float) ($row->prix_unitaire ?? 0);
            $sourceDeviseId = (int) ($row->id_devise ?? 0);
            $rate = null;

            if ($targetDeviseId && $sourceDeviseId && $sourceDeviseId !== $targetDeviseId) {
                $rate = $this->resolveRate($sourceDeviseId, $targetDeviseId, $row->date_mouvement ?? date('Y-m-d'));
                if ($rate !== null) {
                    $cu = $cu * $rate;
                }
            }

            $quantiteEntree = $row->type_mouvement === 'entree' ? (float) $row->quantite : 0;
            $quantiteSortie = $row->type_mouvement === 'sortie' ? (float) $row->quantite : 0;
            $quantiteStock = max(0, (float) ($row->quantite_restante_apres ?? 0));

            return [
                'produit_nom' => $row->produit_nom,
                'produit_code' => $row->produit_code,
                'numero_lot' => $row->numero_lot,
                'date_reception' => $row->date_reception,
                'date_mouvement' => $row->date_mouvement,
                'type_mouvement' => $row->type_mouvement,
                'libelle' => $row->type_mouvement === 'entree' ? 'Entrée' : 'Sortie',
                'quantite_entree' => $quantiteEntree,
                'prix_unitaire' => $cu,
                'valeur_entree' => $quantiteEntree * $cu,
                'quantite_sortie' => $quantiteSortie,
                'valeur_sortie' => $quantiteSortie * $cu,
                'quantite_stock' => $quantiteStock,
                'valeur_stock' => $quantiteStock * $cu,
                'source_devise_code' => $row->source_devise_code,
                'source_devise_symbole' => $row->source_devise_symbole,
                'conversion_rate' => $rate,
            ];
        });
    }

    public function stockFifoPdf(Request $request)
    {
        try {
            $productId = $request->query('produit_id') ?? $request->query('produitId');
            $productId = $productId ? (int) $productId : null;
            $targetDeviseId = $request->query('devise_id') ? (int) $request->query('devise_id') : null;
            $rows = $this->buildStockFifoRows($productId, $targetDeviseId);
            $currency = $this->resolveCurrencyLabel($targetDeviseId, $rows);
            $filename = $productId ? "stock_fifo_produit_{$productId}.pdf" : 'stock_fifo.pdf';
            return $this->generatePdfFromView('reports.stock_fifo', ['rows' => $rows, 'currencyLabel' => $currency['label'], 'currencyCode' => $currency['code'], 'hasMissingRate' => $currency['hasMissingRate']], $filename);
        } catch (\Exception $e) {
            if (config('app.debug')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ], 500);
            }
            return response()->json(['message' => 'Erreur interne lors de la génération du PDF FIFO stock.'], 500);
        }
    }

    public function stockFifoHtml(Request $request)
    {
        $productId = $request->query('produit_id') ?? $request->query('produitId');
        $productId = $productId ? (int) $productId : null;
        $targetDeviseId = $request->query('devise_id') ? (int) $request->query('devise_id') : null;
        $rows = $this->buildStockFifoRows($productId, $targetDeviseId);
        $currency = $this->resolveCurrencyLabel($targetDeviseId, $rows);
        return view('reports.stock_fifo', ['rows' => $rows, 'currencyLabel' => $currency['label'], 'currencyCode' => $currency['code'], 'hasMissingRate' => $currency['hasMissingRate']]);
    }

    private function resolveCurrencyLabel(?int $targetDeviseId, $rows): array
    {
        $label = '';
        $code = '';
        $hasMissingRate = false;

        if ($targetDeviseId) {
            $currency = DB::table('devises')->where('id', $targetDeviseId)->first(['symbole', 'code']);
            $label = $currency?->symbole ?? $currency?->code ?? '';
            $code = $currency?->code ?? '';
        }

        foreach ($rows as $row) {
            if ($targetDeviseId && $row['conversion_rate'] === null && ! empty($row['source_devise_code']) && $row['source_devise_code'] !== $code) {
                $hasMissingRate = true;
                break;
            }
        }

        if (! $label && count($rows) > 0) {
            $label = $rows[0]['source_devise_symbole'] ?? $rows[0]['source_devise_code'] ?? '';
            $code = $rows[0]['source_devise_code'] ?? '';
        }

        return ['label' => $label, 'code' => $code, 'hasMissingRate' => $hasMissingRate];
    }

    /**
     * Resolve conversion rate from source currency id to target currency id on a given date.
     * Returns float rate (multiply source amount by this to get target amount) or null if unavailable.
     */
    private function resolveRate(?int $sourceDeviseId, ?int $targetDeviseId, string $date): ?float
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

        if ($reverse === null || (float) $reverse <= 0) {
            return null;
        }

        return 1.0 / (float) $reverse;
    }

    // --- HTML fallbacks (existing) ---
    public function stockHtml(Request $request)
    {
        $produits = DB::table('produits')->get();
        return view('reports.stock', compact('produits'));
    }

    public function ventesHtml(Request $request)
    {
        $ventes = DB::table('ventes')->get();
        return view('reports.ventes', compact('ventes'));
    }

    // --- Signed URL generators ---
    public function stockSigned(Request $request)
    {
        $url = URL::temporarySignedRoute('rapports.stock.public', now()->addMinutes(10));
        return response()->json(['url' => $url]);
    }

    public function ventesSigned(Request $request)
    {
        $url = URL::temporarySignedRoute('rapports.ventes.public', now()->addMinutes(10));
        return response()->json(['url' => $url]);
    }

    // --- Public signed endpoints ---
    public function stockHtmlPublic(Request $request)
    {
        $produits = DB::table('produits')->get();
        return view('reports.stock', compact('produits'));
    }

    public function ventesHtmlPublic(Request $request)
    {
        $ventes = DB::table('ventes')->get();
        return view('reports.ventes', compact('ventes'));
    }

    // --- Helper: render a Blade view to PDF using Dompdf ---
    private function generatePdfFromView(string $view, array $data, string $filename)
    {
        $pdf = Pdf::loadView($view, $data)
            ->setPaper('a4', 'landscape')
            ->setOption('dpi', 150)
            ->setOption('enable-local-file-access', true);

        return $pdf->download($filename);
    }

    /**
     * Accept raw HTML (posted from frontend), render to PDF via Dompdf and return PDF.
     */
    public function htmlToPdf(Request $request)
    {
        $html = $request->input('html');
        $filename = $request->input('filename') ?? 'report.pdf';
        if (!$html) {
            return response()->json(['message' => 'Missing html'], 400);
        }

        $orientation = $request->input('orientation') === 'portrait' ? 'portrait' : 'landscape';
        $pdf = Pdf::loadHTML($html)
            ->setPaper('a4', $orientation)
            ->setOption('dpi', 150)
            ->setOption('enable-local-file-access', true);

        return $pdf->download($filename);
    }
}
