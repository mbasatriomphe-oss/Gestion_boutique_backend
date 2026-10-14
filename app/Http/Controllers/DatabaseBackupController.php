<?php

namespace App\Http\Controllers;

use App\Services\DatabaseBackupGenerator;
use App\Services\DatabaseBackupSender;
use App\Services\DatabaseBackupSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class DatabaseBackupController extends Controller
{
    public function settings(DatabaseBackupSettings $settings): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $settings->get(),
        ]);
    }

    public function updateSettings(Request $request, DatabaseBackupSettings $settings): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'nullable|required_if:enabled,true|email|max:255',
            'enabled' => 'required|boolean',
            'timezone' => 'required|timezone',
        ]);

        $current = $settings->get();
        $updated = $settings->update([
            'email' => $validated['email'] ?? '',
            'enabled' => (bool) $validated['enabled'],
            'timezone' => $validated['timezone'],
            'last_attempted_at' => $current['last_attempted_at'] ?? null,
            'last_completed_at' => $current['last_completed_at'] ?? null,
            'last_status' => $current['last_status'] ?? null,
            'last_error' => $current['last_error'] ?? null,
            'fallback_available' => $current['fallback_available'] ?? false,
        ]);

        return response()->json(['status' => 'success', 'data' => $updated]);
    }

    public function sendNow(
        DatabaseBackupSettings $settings,
        DatabaseBackupGenerator $generator,
        DatabaseBackupSender $sender,
    ): JsonResponse {
        if (empty($settings->get()['email'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Configurez une adresse e-mail avant de demander une sauvegarde par e-mail.',
            ], 422);
        }

        $result = $sender->send($settings, $generator);

        return response()->json([
            'status' => $result['success'] ? 'success' : 'error',
            'message' => $result['message'],
            'fallback_available' => $result['fallback_available'],
        ], $result['success'] ? 200 : 503);
    }

    public function download(DatabaseBackupGenerator $generator): BinaryFileResponse|JsonResponse
    {
        try {
            $path = $generator->generate();
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => 'error',
                'message' => $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'La sauvegarde a échoué. Vérifiez les droits de lecture du compte de base de données et réessayez.',
            ], 500);
        }

        return response()->download($path, 'sauvegarde-' . now()->format('Y-m-d_H-i-s') . '.sql', [
            'Content-Type' => 'application/sql; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ])->deleteFileAfterSend(true);
    }

    public function downloadLatestLocal(): BinaryFileResponse|JsonResponse
    {
        $disk = Storage::disk('local');
        $path = 'database-backups/latest.sql';

        if (! $disk->exists($path)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Aucune sauvegarde locale de secours n’est disponible.',
            ], 404);
        }

        return response()->download(
            $disk->path($path),
            'sauvegarde-locale-' . now()->format('Y-m-d_H-i-s') . '.sql',
            ['Content-Type' => 'application/sql; charset=UTF-8', 'Cache-Control' => 'no-store, private'],
        );
    }

}
