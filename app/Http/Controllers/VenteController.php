<?php

namespace App\Http\Controllers;

use App\Models\Taux;
use App\Models\ventes;
use App\Models\ligne_ventes;
use App\Models\caisse;
use App\Models\lots;
use App\Models\mouvements_stock_fifos;
use App\Models\produits;
use App\Models\transactions_caisses;
use App\Models\User;
use App\Models\vendeurs;
use App\Notifications\CrudActionNotification;
use App\Notifications\StockInsuffisantNotification;
use App\Services\ProductUnitConversionService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

class VenteController extends ApiCrudController
{
    protected string $modelClass = ventes::class;
    protected array $searchable = ['code'];

    protected function ensureVendorUser(): ?JsonResponse
    {
        $user = auth()->user();
        $role = $user->role ?? null;

        if ($role !== 'vendeur') {
            return response()->json([
                'status' => 'error',
                'message' => 'Seul un vendeur peut créer ou modifier une vente.',
            ], 403);
        }

        return null;
    }

    protected function canCancelSale(): bool
    {
        $role = auth()->user()?->role ?? null;

        return in_array($role, ['admin', 'vendeur'], true);
    }

    private function notifySaleAction(string $action, ventes $vente): void
    {
        $actor = auth()->user();
        $recipients = collect(User::query()->where('role', 'admin')->get());

        if ($actor && method_exists($actor, 'notify')) {
            $recipients->push($actor);
        }

        $recipients = $recipients->filter()->unique(function ($recipient): string {
            return get_class($recipient) . ':' . (string) $recipient->getKey();
        })->values();

        if ($recipients->isEmpty()) {
            return;
        }

        $titles = [
            'created' => 'Vente réussie',
            'updated' => 'Vente modifiée',
            'deleted' => 'Vente supprimée',
        ];

        $messages = [
            'created' => 'La vente a été enregistrée avec succès.',
            'updated' => 'La vente a été modifiée avec succès.',
            'deleted' => 'La vente a été supprimée avec succès.',
        ];

        Notification::send($recipients, new CrudActionNotification([
            'type' => 'vente_' . $action,
            'title' => $titles[$action] ?? 'Vente',
            'message' => $messages[$action] ?? 'Action réalisée sur une vente.',
            'action' => $action,
            'entity' => 'Vente',
            'entity_id' => $vente->getKey(),
            'entity_name' => $vente->code,
            'actor_id' => $actor?->getKey(),
            'actor_name' => $actor?->nom ?? $actor?->name ?? $actor?->email ?? 'Système',
        ]));
    }

    private function decimalValue(string|int|float $value): BigDecimal
    {
        return BigDecimal::of((string) $value);
    }

    private function formatDecimal(BigDecimal $value): string
    {
        return $value->toScale(8, RoundingMode::HALF_UP)->__toString();
    }

    protected function resolveTauxToCurrency(int $sourceCurrencyId, int $targetCurrencyId, string $date): ?BigDecimal
    {
        if ($sourceCurrencyId === $targetCurrencyId) {
            return BigDecimal::of('1');
        }

        $directRate = Taux::query()
            ->where('statut', 'actif')
            ->where('devise_source', $sourceCurrencyId)
            ->where('devise_but', $targetCurrencyId)
            ->whereDate('date_effet', '<=', $date)
            ->orderByDesc('date_effet')
            ->orderByDesc('id')
            ->value('valeur');

        if ($directRate !== null) {
            return BigDecimal::of((string) $directRate);
        }

        $reverseRate = Taux::query()
            ->where('statut', 'actif')
            ->where('devise_source', $targetCurrencyId)
            ->where('devise_but', $sourceCurrencyId)
            ->whereDate('date_effet', '<=', $date)
            ->orderByDesc('date_effet')
            ->orderByDesc('id')
            ->value('valeur');

        if ($reverseRate === null || (float) $reverseRate <= 0) {
            return null;
        }

        return BigDecimal::of('1')->dividedBy(BigDecimal::of((string) $reverseRate), 8, RoundingMode::HALF_UP);
    }

    protected function calculatePaymentSummary(array $validated, array $lineItems, array $payments): array
    {
        $saleCurrencyId = (int) ($validated['devise_vente_id'] ?? ($lineItems[0]['id_devise'] ?? 0));
        $saleDate = (string) $validated['date'];

        $total = BigDecimal::of('0');

        foreach ($lineItems as $item) {
            $lineCurrencyId = (int) ($item['id_devise'] ?? 0);
            $lineAmount = BigDecimal::of((string) $item['prix_vente'])->multipliedBy(BigDecimal::of((string) $item['quantite']));

            $rate = $this->resolveTauxToCurrency($lineCurrencyId, $saleCurrencyId, $saleDate);

            if (! $rate) {
                throw new \RuntimeException('Impossible de convertir une ligne de vente vers la devise de vente. Vérifie les taux actifs.');
            }

            $total = $total->plus($lineAmount->multipliedBy($rate));
        }

        $paymentFee = BigDecimal::of((string) ($validated['frais_transaction'] ?? '0'));
        $total = $total->plus($paymentFee);

        $paid = BigDecimal::of('0');

        foreach ($payments as $payment) {
            $paymentCurrencyId = (int) $payment['devise_id'];
            $paymentAmount = BigDecimal::of((string) $payment['montant']);
            $rate = $this->resolveTauxToCurrency($paymentCurrencyId, $saleCurrencyId, $saleDate);

            if (! $rate) {
                throw new \RuntimeException('Impossible de convertir un paiement vers la devise de vente. Vérifie les taux actifs.');
            }

            $paid = $paid->plus($paymentAmount->multipliedBy($rate));
        }

        $remaining = $total->minus($paid);

        if ($remaining->isLessThan(BigDecimal::of('0'))) {
            $remaining = BigDecimal::of('0');
        }

        return [
            'devise_vente_id' => $saleCurrencyId,
            'montant_total' => $this->formatDecimal($total),
            'montant_paye' => $this->formatDecimal($paid),
            'reste_a_payer' => $this->formatDecimal($remaining),
            'statut_paiement' => $remaining->isGreaterThan(BigDecimal::of('0')) ? 'partielle' : 'payee',
        ];
    }

    public function createSaleFromPayload(array $validated, array $lineItems, array $payments): ventes
    {
        $lineItems = $this->normalizeSaleLineItems($lineItems);

        return DB::transaction(function () use ($validated, $lineItems, $payments) {
            $paymentSummary = $this->calculatePaymentSummary($validated, $lineItems, $payments);
            $paymentType = $validated['type_paiement'] ?? (
                BigDecimal::of($paymentSummary['reste_a_payer'])->isGreaterThan(BigDecimal::of('0'))
                    ? (BigDecimal::of($paymentSummary['montant_paye'])->isGreaterThan(BigDecimal::of('0')) ? 'avance' : 'credit')
                    : 'total'
            );
            $paid = BigDecimal::of($paymentSummary['montant_paye']);
            $remaining = BigDecimal::of($paymentSummary['reste_a_payer']);
            $total = BigDecimal::of($paymentSummary['montant_total']);

            if (
                ($paymentType === 'credit' && ($payments !== [] || ! $remaining->isGreaterThan(BigDecimal::of('0'))))
                || ($paymentType === 'avance' && (! $paid->isGreaterThan(BigDecimal::of('0')) || ! $remaining->isGreaterThan(BigDecimal::of('0'))))
                || ($paymentType === 'total' && (
                    $remaining->isGreaterThan(BigDecimal::of('0'))
                    || $paid->isGreaterThan($total)
                ))
            ) {
                throw new \RuntimeException('Le montant payé ne correspond pas au type de paiement sélectionné.');
            }

            $code = $validated['code'] ?? $this->generateUniqueCode('ventes', 'code', 'VEN');

            /** @var ventes $vente */
            $vente = ventes::create([
                'code'       => $code,
                'date'       => $validated['date'],
                'id_vendeur' => (int) $validated['id_vendeur'],
                'id_client'  => (int) $validated['id_client'],
                'devise_vente_id' => $paymentSummary['devise_vente_id'],
                'montant_total' => $paymentSummary['montant_total'],
                'montant_paye' => $paymentSummary['montant_paye'],
                'reste_a_payer' => $paymentSummary['reste_a_payer'],
                'statut_paiement' => $paymentSummary['statut_paiement'],
                'type_paiement' => $paymentType,
                'mode_paiement' => $validated['mode_paiement'] ?? 'cash',
                'paiement_en_ligne' => $validated['paiement_en_ligne'] ?? false,
                'frais_transaction' => $validated['frais_transaction'] ?? 0,
            ]);

            foreach ($lineItems as $item) {
                $line = ligne_ventes::create([
                    'id_vente'   => $vente->id,
                    'id_produit' => (int) $item['id_produit'],
                    'id_unite' => (int) $item['id_unite'],
                    'quantite'   => $item['quantite'],
                    'facteur_conversion' => $item['facteur_conversion'],
                    'quantite_stock' => $item['quantite_stock'],
                    'prix_vente' => $item['prix_vente'],
                    'id_devise'  => (int) $item['id_devise'],
                ]);

                $this->consumeStockFifo($line, (string) $item['quantite_stock']);
            }

            if (! ($validated['paiement_en_ligne'] ?? false)) {
                foreach ($payments as $payment) {
                    $this->recordCashMovement(
                        (int) $payment['devise_id'],
                        'entree',
                        (float) $payment['montant'],
                        'vente',
                        $vente->id,
                        'Paiement de la vente #' . $vente->code,
                    );
                }
            }

            return $vente->fresh(['client', 'vendeur', 'deviseVente', 'lignes.produit', 'lignes.unite', 'lignes.devise', 'transactionsCaisses.caisse.devise']);
        });
    }

    private function consumeStockFifo(ligne_ventes $line, string $requiredQuantity): void
    {
        $remaining = BigDecimal::of($requiredQuantity);
        $lots = lots::query()
            ->where('id_produit', $line->id_produit)
            ->orderBy('date_reception')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($lots as $lot) {
            $issued = BigDecimal::of((string) $lot->mouvementsStock()->where('type_mouvement', 'sortie')->sum('quantite'));
            $available = BigDecimal::of((string) $lot->quantite_initial)->minus($issued);
            if (! $available->isGreaterThan(BigDecimal::of('0'))) {
                continue;
            }

            $taken = $available->isLessThan($remaining) ? $available : $remaining;
            $after = $available->minus($taken);
            mouvements_stock_fifos::create([
                'id_lot' => $lot->id,
                'id_ligne_vente' => $line->id,
                'type_mouvement' => 'sortie',
                'quantite' => $this->formatDecimal($taken),
                'quantite_restante_avant' => $this->formatDecimal($available),
                'quantite_restante_apres' => $this->formatDecimal($after),
                'date_mouvement' => now()->toDateString(),
            ]);

            $remaining = $remaining->minus($taken);
            if (! $remaining->isGreaterThan(BigDecimal::of('0'))) {
                return;
            }
        }

        throw new \RuntimeException('Stock insuffisant pour cette vente');
    }

    private function normalizeSaleLineItems(array $lineItems): array
    {
        $productIds = array_map(static fn (array $item) => (int) $item['id_produit'], $lineItems);
        $products = produits::with('unitesVente')->whereIn('id', $productIds)->get()->keyBy('id');
        $conversionService = app(ProductUnitConversionService::class);

        foreach ($lineItems as &$item) {
            $product = $products->get((int) $item['id_produit']);
            $unitId = (int) ($item['id_unite'] ?? $product->unite_id);
            $conversion = $product->unitesVente->firstWhere('id_unite', $unitId);

            if (! $conversion && $unitId === (int) $product->unite_id) {
                $factor = 1;
            } elseif ($conversion) {
                $factor = $conversion->facteur;
            } else {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'lignes' => "L’unité de vente choisie n’est pas configurée pour {$product->nom}.",
                ]);
            }

            $item['id_unite'] = $unitId;
            $item['facteur_conversion'] = $factor;
            $item['quantite_stock'] = $conversionService->quantityInStockUnit($item['quantite'], $factor);
        }
        unset($item);

        return $lineItems;
    }

    protected function indexQuery(Request $request): Builder
    {
        $query = ventes::with(['vendeur', 'client', 'deviseVente', 'lignes.produit', 'lignes.unite', 'lignes.devise', 'transactionsCaisses.caisse.devise']);

        if ($request->filled('id_vendeur')) {
            $query->where('id_vendeur', $request->integer('id_vendeur'));
        }

        if ($request->filled('id_client')) {
            $query->where('id_client', $request->integer('id_client'));
        }

        // Support both single date and date ranges (date / start & end)
        if ($request->filled('date')) {
            $query->whereDate('date', $request->date('date'));
        } else {
            $start = $request->query('date_debut') ?? $request->query('start');
            $end = $request->query('date_fin') ?? $request->query('end');

            if ($start) {
                $query->whereDate('date', '>=', $start);
            }

            if ($end) {
                $query->whereDate('date', '<=', $end);
            }
        }

        if ($request->filled('payment_status')) {
            $paymentStatus = $request->string('payment_status')->toString();

            if ($paymentStatus === 'due') {
                $query->where('reste_a_payer', '>', 0);
            } elseif ($paymentStatus === 'paid') {
                $query->where('reste_a_payer', '<=', 0);
            } elseif ($paymentStatus === 'partial') {
                $query->where('statut_paiement', 'partielle');
            }
        }
        $period = strtolower((string) $request->query('period', ''));

        if ($period === 'daily') {
            $query->whereDate('created_at', $request->filled('date') ? $request->date('date') : now()->toDateString());
        } elseif ($period === 'monthly') {
            $month = (string) $request->query('month', now()->format('Y-m'));

            if (preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
                [$year, $monthNumber] = array_map('intval', explode('-', $month, 2));
                $query->whereYear('created_at', $year)->whereMonth('created_at', $monthNumber);
            }
        }

        // Optional payment currency filter: 'franc', 'dollar', 'both'
        $paymentFilter = strtolower((string) $request->query('payment_currency', ''));
        if ($paymentFilter !== '') {
            $francId = DB::table('devises')->where(function ($q) {
                $q->whereRaw("lower(code) = 'cdf' OR lower(code) = 'fc'")
                    ->orWhereRaw("lower(nom) LIKE '%franc%'")
                    ->orWhere('symbole', 'FC');
            })->value('id');

            $dollarId = DB::table('devises')->where(function ($q) {
                $q->whereRaw("lower(code) = 'usd'")
                    ->orWhereRaw("lower(nom) LIKE '%dollar%'")
                    ->orWhere('symbole', '$');
            })->value('id');

            if ($paymentFilter === 'franc' && $francId) {
                $query->paidInCurrency($francId);
            } elseif ($paymentFilter === 'dollar' && $dollarId) {
                $query->paidInCurrency($dollarId);
            } elseif ($paymentFilter === 'both' && $francId && $dollarId) {
                $query->paidInBothCurrencies($francId, $dollarId);
            }
        }

        return $query;
    }

    private function cancellationBlockedReason(ventes $vente): ?string
    {
        $user = auth()->user();
        $role = $user?->role ?? null;

        if ($role === 'admin') {
            return null;
        }

        if ($role !== 'vendeur') {
            return 'Vous n\'êtes pas autorisé à annuler cette vente.';
        }

        if ((int) ($vente->id_vendeur ?? 0) !== (int) ($user?->id ?? 0)) {
            return 'Vous ne pouvez annuler que vos propres ventes.';
        }

        if (! $vente->created_at || $vente->created_at->diffInMinutes(now()) > 60) {
            return 'Une vente ne peut être annulée que dans l\'heure qui suit sa création.';
        }

        return null;
    }

    protected function storeRules(): array
    {
        return [
            'code'                    => 'sometimes|string|max:50|unique:ventes,code',
            'date'                    => 'required|date',
            'id_vendeur'              => 'required|integer|exists:vendeurs,id',
            'id_client'               => 'required|integer|exists:clients,id',
            'devise_vente_id'         => 'nullable|integer|exists:devises,id',
            'type_paiement'           => 'sometimes|string|in:total,avance,credit',
            'mode_paiement'           => 'sometimes|string|in:cash,card',
            'paiement_en_ligne'       => 'sometimes|boolean',
            'frais_transaction'       => 'sometimes|numeric|min:0',
            'paiements'               => 'present|array',
            'paiements.*.devise_id'   => 'required|integer|exists:devises,id',
            'paiements.*.montant'     => 'required|numeric|min:0.01',
            'lignes'                  => 'required|array|min:1',
            'lignes.*.id_produit'     => 'required|integer|exists:produits,id',
            'lignes.*.quantite'       => 'required|numeric|gt:0',
            'lignes.*.id_unite'       => 'sometimes|integer|exists:unites,id',
            'lignes.*.prix_vente'     => 'required|numeric|min:0',
            'lignes.*.id_devise'      => 'required|integer|exists:devises,id',
        ];
    }

    protected function recordCashMovement(
        int $deviseId,
        string $type,
        float $montant,
        string $referenceType,
        int $referenceId,
        string $description,
        ?int $createdBy = null,
    ): void {
        $caisse = caisse::query()
            ->where('id_devise', $deviseId)
            ->lockForUpdate()
            ->first();

        if (! $caisse) {
            throw new \RuntimeException('Aucune caisse n\'est configurée pour cette devise.');
        }

        $montantDecimal = $this->decimalValue($montant);
        $soldeAvant = $this->decimalValue($caisse->solde);

        if ($type === 'sortie' && $soldeAvant->isLessThan($montantDecimal)) {
            throw new \RuntimeException('Solde insuffisant dans la caisse.');
        }

        $soldeApres = $type === 'entree'
            ? $soldeAvant->plus($montantDecimal)
            : $soldeAvant->minus($montantDecimal);

        $caisse->update(['solde' => $this->formatDecimal($soldeApres)]);

        transactions_caisses::create([
            'id_caisse' => $caisse->getKey(),
            'type' => $type,
            'montant' => $this->formatDecimal($montantDecimal),
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'description' => $description,
            'solde_avant' => $this->formatDecimal($soldeAvant),
            'solde_apres' => $this->formatDecimal($soldeApres),
            'created_by' => $createdBy ?? auth()->user()?->id,
        ]);
    }

    protected function updateRules(Model $model): array
    {
        return [
            'code'       => ['sometimes', 'string', 'max:50', Rule::unique('ventes', 'code')->ignore($model->getKey())],
            'date'       => 'sometimes|date',
            'id_vendeur' => 'sometimes|integer|exists:vendeurs,id',
            'id_client'  => 'sometimes|integer|exists:clients,id',
        ];
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->ensureVendorUser()) {
            return $response;
        }

        $validated = $request->validate($this->storeRules());

        $lineItems   = $validated['lignes'];
        $payments    = $validated['paiements'];
        $productIds  = array_map(static fn (array $l) => (int) $l['id_produit'], $lineItems);

        if (count($productIds) !== count(array_unique($productIds))) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Un même produit ne peut apparaître qu\'une seule fois dans la même vente.',
            ], 422);
        }

        $lineItems = $this->normalizeSaleLineItems($lineItems);

        $stockRows = DB::table('v_stock_disponible')
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $insufficientItems = [];
        foreach ($lineItems as $item) {
            $productId = (int) $item['id_produit'];
            $availableStock = (float) ($stockRows->get($productId)?->stock_actuel ?? 0);
            $requestedQuantity = (float) $item['quantite_stock'];

            if ($availableStock + 0.00000001 < $requestedQuantity) {
                $product = DB::table('produits')->where('id', $productId)->first();
                $insufficientItems[] = [
                    'id_produit' => $productId,
                    'produit' => $product?->nom ?? $product?->code ?? "Produit #{$productId}",
                    'demande' => $requestedQuantity,
                    'disponible' => $availableStock,
                ];
            }
        }

        if ($insufficientItems !== []) {
            $admins = User::query()->where('role', 'admin')->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new StockInsuffisantNotification([
                    'message' => 'Une vente a été refusée car le stock était insuffisant.',
                    'vente_code' => $validated['code'] ?? null,
                    'items' => $insufficientItems,
                    'created_by' => (int) (auth()->user()?->id ?? 0),
                    'created_by_name' => auth()->user()?->nom ?? auth()->user()?->email ?? 'Système',
                ]));
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Stock insuffisant pour cette vente',
                'details' => $insufficientItems,
            ], 422);
        }

        try {
            $created = $this->createSaleFromPayload($validated, $lineItems, $payments);

            $this->notifySaleAction('created', $created);
        } catch (QueryException|\RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'Stock insuffisant pour cette vente')) {
                $admins = User::query()->where('role', 'admin')->get();

                if ($admins->isNotEmpty()) {
                    Notification::send($admins, new StockInsuffisantNotification([
                        'message' => 'Le trigger de stock a refusé une vente.',
                        'vente_code' => $validated['code'] ?? null,
                        'items' => array_map(static fn (array $item) => [
                            'id_produit' => (int) $item['id_produit'],
                            'demande' => (float) $item['quantite_stock'],
                        ], $lineItems),
                        'created_by' => (int) (auth()->user()?->id ?? 0),
                        'created_by_name' => auth()->user()?->nom ?? auth()->user()?->email ?? 'Système',
                    ]));
                }
            }

            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $created,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($response = $this->ensureVendorUser()) {
            return $response;
        }

        $vente     = ventes::findOrFail($id);
        $validated = $request->validate($this->updateRules($vente));
        $vente->update($validated);

        $freshVente = $vente->fresh(['client', 'vendeur', 'deviseVente', 'lignes.produit', 'lignes.unite', 'lignes.devise', 'transactionsCaisses.caisse.devise']);
        $this->notifySaleAction('updated', $freshVente);

        return response()->json([
            'status' => 'success',
            'data'   => $freshVente,
        ]);
    }

    public function addPayment(Request $request, int $id): JsonResponse
    {
        $user = auth()->user();
        if (! $user || (! $user instanceof vendeurs && ! in_array($user->role ?? null, ['admin', 'vendeur'], true))) {
            return response()->json([
                'status' => 'error',
                'message' => 'Seuls un administrateur ou un vendeur peuvent enregistrer un paiement.',
            ], 403);
        }

        $rules = [
            'paiements' => 'required|array|min:1',
            'paiements.*.devise_id' => 'required|integer|exists:devises,id',
            'paiements.*.montant' => 'required|numeric|min:0.01',
        ];

        $validated = $request->validate($rules);

        $payments = $validated['paiements'];

        try {
            $updated = DB::transaction(function () use ($id, $payments) {
                $vente = ventes::query()
                    ->with('lignes')
                    ->lockForUpdate()
                    ->findOrFail($id);

                if ((float) $vente->reste_a_payer <= 0) {
                    throw new \RuntimeException('Cette vente ne présente aucun solde à payer.');
                }

                $lineItems = $vente->lignes->map(static fn ($line) => [
                    'id_produit' => (int) $line->id_produit,
                    'quantite' => (string) $line->quantite,
                    'prix_vente' => $line->prix_vente,
                    'id_devise' => (int) $line->id_devise,
                ])->all();

                // Validate that a caisse exists for each payment devise before recording
                foreach ($payments as $payment) {
                    $deviseId = (int) $payment['devise_id'];
                    $caisseExists = caisse::query()->where('id_devise', $deviseId)->exists();
                    if (! $caisseExists) {
                        throw new \RuntimeException("Aucune caisse configurée pour la devise id={$deviseId}. Configure une caisse avant d'enregistrer ce paiement.");
                    }
                }

                $validatedContext = [
                    'devise_vente_id' => $vente->devise_vente_id,
                    'date' => $vente->date->toDateString(),
                    'frais_transaction' => $vente->frais_transaction ?? 0,
                ];
                $allPayments = array_merge($this->collectExistingPayments($vente->id), $payments);
                $summary = $this->calculatePaymentSummary($validatedContext, $lineItems, $allPayments);

                if (BigDecimal::of($summary['montant_paye'])->isGreaterThan(BigDecimal::of($summary['montant_total']))) {
                    throw new \RuntimeException('Le montant versé dépasse le solde restant de cette vente.');
                }

                foreach ($payments as $payment) {
                    $this->recordCashMovement(
                        (int) $payment['devise_id'],
                        'entree',
                        (float) $payment['montant'],
                        'vente',
                        $vente->id,
                        'Paiement (complément) de la vente #' . $vente->code,
                    );
                }

                $vente->update([
                    'montant_total' => $summary['montant_total'],
                    'montant_paye' => $summary['montant_paye'],
                    'reste_a_payer' => $summary['reste_a_payer'],
                    'statut_paiement' => $summary['statut_paiement'],
                ]);

                return $vente->fresh(['client', 'vendeur', 'deviseVente', 'lignes.produit', 'lignes.unite', 'lignes.devise', 'transactionsCaisses.caisse.devise']);
            });

            $this->notifySaleAction('updated', $updated);

            return response()->json(['status' => 'success', 'data' => $updated], 200);
        } catch (QueryException|\RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    private function collectExistingPayments(int $venteId): array
    {
        $rows = transactions_caisses::query()
            ->where('reference_type', 'vente')
            ->where('reference_id', $venteId)
            ->where('type', 'entree')
            ->get()
            ->map(function ($t) {
                return ['devise_id' => $t->caisse?->id_devise ?? null, 'montant' => $t->montant];
            })
            ->filter(function ($p) { return !empty($p['devise_id']); })
            ->all();

        return $rows;
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $vente = ventes::with(['lignes', 'retours', 'vendeur'])->findOrFail($id);

            if ($reason = $this->cancellationBlockedReason($vente)) {
                return response()->json([
                    'status' => 'error',
                    'message' => $reason,
                ], 422);
            }

            $vente = DB::transaction(function () use ($vente) {
                /** @var ventes $vente */
                if ($vente->retours()->exists()) {
                    throw new \RuntimeException('Cette vente contient déjà un retour et ne peut pas être annulée.');
                }

                $lineIds = $vente->lignes->pluck('id')->all();
                $cashTransactions = transactions_caisses::query()
                    ->where('reference_type', 'vente')
                    ->where('reference_id', $vente->id)
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($cashTransactions as $transaction) {
                    $caisse = caisse::query()
                        ->where('id', $transaction->id_caisse)
                        ->lockForUpdate()
                        ->first();

                    if (! $caisse) {
                        throw new \RuntimeException('Caisse introuvable pour rétablir la vente.');
                    }

                    $montant = $this->decimalValue($transaction->montant);
                    $soldeActuel = $this->decimalValue($caisse->solde);
                    $soldeApresAnnulation = $transaction->type === 'entree'
                        ? $soldeActuel->minus($montant)
                        : $soldeActuel->plus($montant);

                    $caisse->update(['solde' => $this->formatDecimal($soldeApresAnnulation)]);
                    $transaction->delete();
                }

                if ($lineIds !== []) {
                    DB::table('mouvements_stock_fifos')
                        ->whereIn('id_ligne_vente', $lineIds)
                        ->delete();

                    ligne_ventes::query()
                        ->where('id_vente', $vente->id)
                        ->delete();
                }

                $vente->delete();

                return $vente;
            });

            $this->notifySaleAction('deleted', $vente);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Suppression effectuee',
        ]);
    }

}