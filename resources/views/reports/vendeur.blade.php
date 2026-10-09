<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 14mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #17212b; }
        h1 { margin: 0 0 6px; font-size: 20px; }
        .meta { margin-bottom: 16px; color: #52606d; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 6px 5px; border: 1px solid #d8dee6; text-align: left; vertical-align: top; }
        th { background: #edf2f7; font-weight: bold; }
        tr { page-break-inside: avoid; }
        footer { margin-top: 14px; color: #687785; font-size: 8px; text-align: right; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="meta">
        @if ($clientName)
            Client : {{ $clientName }} ·
        @endif
        Période : {{ $period }} · Généré le {{ $generatedAt }} · Source : base de données
    </div>
    <table>
        <thead>
            <tr>
                @foreach ($headers as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell ?? '' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($headers) }}">Aucune donnée pour les filtres sélectionnés.</td></tr>
            @endforelse
        </tbody>
    </table>
    <footer>{{ count($rows) }} ligne(s)</footer>
</body>
</html>
