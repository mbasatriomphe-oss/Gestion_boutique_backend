<?php

namespace App\Http\Controllers;

use App\Models\taux as Taux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TauxController extends ApiCrudController
{
    protected string $modelClass = Taux::class;

    protected function indexQuery(Request $request): Builder
    {
        $query = Taux::with(['deviseSource', 'deviseBut']);

        if ($request->filled('statut')) {
            $query->where('statut', $request->string('statut'));
        }

        if ($request->filled('devise_source')) {
            $query->where('devise_source', $request->integer('devise_source'));
        }

        if ($request->filled('devise_but')) {
            $query->where('devise_but', $request->integer('devise_but'));
        }

        if ($request->filled('date_effet')) {
            $query->whereDate('date_effet', $request->date('date_effet'));
        }

        return $query->orderByDesc('date_effet')->orderByDesc('id');
    }

    protected function storeRules(): array
    {
        return [
            'devise_source' => 'required|integer|exists:devises,id',
            'devise_but' => 'required|integer|exists:devises,id|different:devise_source',
            'valeur' => 'required|numeric|min:0',
            'date_effet' => 'required|date',
            'statut' => 'sometimes|in:actif,inactif',
        ];
    }

    protected function updateRules(\Illuminate\Database\Eloquent\Model $model): array
    {
        return [
            'devise_source' => 'sometimes|integer|exists:devises,id',
            'devise_but' => 'sometimes|integer|exists:devises,id|different:devise_source',
            'valeur' => 'sometimes|numeric|min:0',
            'date_effet' => 'sometimes|date',
            'statut' => 'sometimes|in:actif,inactif',
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $rules = $this->storeRules();
        $rules['avec_inverse'] = 'sometimes|boolean';
        $validated = $request->validate($rules);

        $statut = $validated['statut'] ?? 'inactif';
        $avecInverse = (bool) ($validated['avec_inverse'] ?? false);

        // Direct rate
        $directData = [
            'devise_source' => $validated['devise_source'],
            'devise_but'    => $validated['devise_but'],
            'valeur'        => $validated['valeur'],
            'date_effet'    => $validated['date_effet'],
            'statut'        => $statut,
            'valeur_inverse' => $this->calculateInverseValue($validated['valeur']),
        ];

        $alreadyExists = Taux::query()
            ->where('devise_source', $directData['devise_source'])
            ->where('devise_but', $directData['devise_but'])
            ->whereDate('date_effet', $directData['date_effet'])
            ->exists();

        if ($alreadyExists) {
            return response()->json([
                'status' => 'error',
                'message' => 'Un taux existe déjà pour ces devises à cette date.',
            ], 422);
        }

        $taux = Taux::create($directData);

        if ($statut === 'actif') {
            $this->deactivateSiblingRates($taux);
        }

        $result = [
            'direct' => $this->transformTaux($taux->fresh()->load(['deviseSource', 'deviseBut'])),
        ];

        // Optionally create the inverse record (FC→$) with symmetric precision
        if ($avecInverse) {
            $inverseValeur = $this->calculateInverseValue($validated['valeur']);

            $inverseAlreadyExists = Taux::query()
                ->where('devise_source', $directData['devise_but'])
                ->where('devise_but', $directData['devise_source'])
                ->whereDate('date_effet', $directData['date_effet'])
                ->exists();

            if (! $inverseAlreadyExists) {
                $inverseData = [
                    'devise_source' => $directData['devise_but'],
                    'devise_but'    => $directData['devise_source'],
                    'valeur'        => $inverseValeur,
                    'date_effet'    => $directData['date_effet'],
                    'statut'        => $statut,
                    'valeur_inverse' => $this->formatDecimal($validated['valeur']),
                ];

                $tauxInverse = Taux::create($inverseData);

                if ($statut === 'actif') {
                    $this->deactivateSiblingRates($tauxInverse);
                }

                $result['inverse'] = $this->transformTaux($tauxInverse->fresh()->load(['deviseSource', 'deviseBut']));
            } else {
                $result['inverse_skipped'] = 'Un taux inverse existe déjà pour cette date.';
            }
        }

        return response()->json(['status' => 'success', 'data' => $result], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $taux = Taux::findOrFail($id);
        $validated = $request->validate($this->updateRules($taux));

        $nextSource = $validated['devise_source'] ?? $taux->devise_source;
        $nextBut = $validated['devise_but'] ?? $taux->devise_but;
        $nextDate = $validated['date_effet'] ?? $taux->date_effet;

        $alreadyExists = Taux::query()
            ->where('id', '!=', $taux->getKey())
            ->where('devise_source', $nextSource)
            ->where('devise_but', $nextBut)
            ->whereDate('date_effet', $nextDate)
            ->exists();

        if ($alreadyExists) {
            return response()->json([
                'status' => 'error',
                'message' => 'Un taux existe déjà pour ces devises à cette date.',
            ], 422);
        }

        if (array_key_exists('valeur', $validated)) {
            $validated['valeur_inverse'] = $this->calculateInverseValue($validated['valeur']);
        }

        $taux->update($validated);

        if (($validated['statut'] ?? $taux->statut) === 'actif') {
            $this->deactivateSiblingRates($taux->fresh());
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->transformTaux($taux->fresh()->load(['deviseSource', 'deviseBut'])),
        ]);
    }

    public function actifByDate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'nullable|date',
            'devise_source' => 'nullable|integer|exists:devises,id',
            'devise_but' => 'nullable|integer|exists:devises,id',
        ]);

        $query = Taux::with(['deviseSource', 'deviseBut'])->where('statut', 'actif');

        if (! empty($validated['date'])) {
            $query->whereDate('date_effet', '<=', $validated['date']);
        }

        if (! empty($validated['devise_source'])) {
            $query->where('devise_source', $validated['devise_source']);
        }

        if (! empty($validated['devise_but'])) {
            $query->where('devise_but', $validated['devise_but']);
        }

        $query->orderByDesc('date_effet')->orderByDesc('id');

        if (! empty($validated['devise_source']) && ! empty($validated['devise_but'])) {
            $taux = $query->first();

            if (! $taux) {
                $inverseTaux = Taux::with(['deviseSource', 'deviseBut'])
                    ->where('statut', 'actif')
                    ->when(! empty($validated['date']), fn ($query) => $query->whereDate('date_effet', '<=', $validated['date']))
                    ->where('devise_source', $validated['devise_but'])
                    ->where('devise_but', $validated['devise_source'])
                    ->orderByDesc('date_effet')
                    ->orderByDesc('id')
                    ->first();

                if ($inverseTaux) {
                    return response()->json([
                        'status' => 'success',
                        'data' => $this->transformTaux($inverseTaux, [
                            'devise_source' => (int) $validated['devise_source'],
                            'devise_but' => (int) $validated['devise_but'],
                            'valeur' => $this->formatDecimal($this->calculateInverseValue($inverseTaux->valeur)),
                            'valeur_inverse' => $this->formatDecimal($inverseTaux->valeur),
                            'devise_source_info' => $inverseTaux->deviseBut?->toArray(),
                            'devise_but_info' => $inverseTaux->deviseSource?->toArray(),
                        ]),
                    ]);
                }

                return response()->json([
                    'status' => 'error',
                    'message' => 'Aucun taux actif trouvé pour ce couple de devises à cette date.',
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $this->transformTaux($taux),
            ]);
        }

        return response()->json([
            'status' => 'success',
            'data' => $query->get()->map(fn (Taux $item) => $this->transformTaux($item))->values(),
        ]);
    }

    private function transformTaux(Taux $taux, array $overrides = []): array
    {
        $payload = $taux->toArray();
        $payload['valeur'] = $this->formatDecimal($taux->valeur);
        $payload['valeur_inverse'] = $this->formatDecimal($taux->valeur_inverse ?? $this->calculateInverseValue($taux->valeur));

        // toArray() overrides FK integers with relation objects (same snake_case name).
        // Re-set them as integers and expose relations under _info keys.
        $payload['devise_source'] = (int) $taux->getRawOriginal('devise_source');
        $payload['devise_but']    = (int) $taux->getRawOriginal('devise_but');
        $payload['devise_source_info'] = $taux->deviseSource?->toArray();
        $payload['devise_but_info']    = $taux->deviseBut?->toArray();
        unset($payload['devise_source_relation'], $payload['devise_but_relation']);

        foreach ($overrides as $key => $value) {
            $payload[$key] = $value;
        }

        return $payload;
    }

    private function calculateInverseValue(string|int|float $value): string
    {
        $str = rtrim(rtrim(number_format((float) $value, 20, '.', ''), '0'), '.');

        if (empty($str) || $str === '0') {
            return '0';
        }

        // Use BCMath for high-precision division: 1 / value with 20 decimal places
        $result = bcdiv('1', $str, 20);

        return $this->formatDecimal($result);
    }

    /**
     * Format a decimal value: strip trailing zeros, keep up to $precision significant
     * decimal digits. Uses BCMath to avoid float rounding artifacts.
     */
    private function formatDecimal(string|int|float $value, int $precision = 20): string
    {
        // Normalise to string without exponential notation
        $str = number_format((float) $value, $precision, '.', '');

        return rtrim(rtrim($str, '0'), '.');
    }

    private function deactivateSiblingRates(Taux $taux): void
    {
        Taux::query()
            ->where('id', '!=', $taux->getKey())
            ->where('devise_source', $taux->devise_source)
            ->where('devise_but', $taux->devise_but)
            ->update(['statut' => 'inactif']);
    }
}