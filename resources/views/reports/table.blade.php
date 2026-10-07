<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 10px; }
        .flag { height: 4px; }
        .flag span { display: inline-block; width: 33.33%; height: 4px; }
        header { border-bottom: 2px solid #047857; padding: 10px 0 8px; margin-bottom: 14px; }
        .brand { font-size: 16px; font-weight: bold; color: #047857; }
        .brand small { color: #64748b; font-weight: normal; font-size: 10px; }
        h1 { font-size: 15px; margin: 6px 0 2px; }
        .muted { color: #64748b; }
        .summary { width: 100%; border-collapse: separate; border-spacing: 6px; margin: 0 -6px 12px; }
        .summary td { background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 6px; padding: 8px 10px; }
        .summary .label { color: #047857; font-size: 8px; text-transform: uppercase; letter-spacing: .5px; }
        .summary .value { font-size: 13px; font-weight: bold; margin-top: 2px; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: #064e3b; color: #fff; text-align: left; padding: 6px; font-size: 9px; }
        table.data td { padding: 5px 6px; border-bottom: 1px solid #e2e8f0; }
        table.data tr:nth-child(even) td { background: #f8fafc; }
        footer { position: fixed; bottom: -14px; left: 0; right: 0; font-size: 8px; color: #94a3b8; }
    </style>
</head>
<body>
<div class="flag"><span style="background:#078930"></span><span style="background:#fcdd09"></span><span style="background:#da121a"></span></div>
<header>
    <div class="brand">MedLink Ethiopia <small>&middot; {{ $organization }}</small></div>
    <h1>{{ $title }}</h1>
    <div class="muted">{{ $subtitle }}</div>
</header>

@if ($summary)
    <table class="summary">
        <tr>
            @foreach ($summary as $label => $value)
                <td>
                    <div class="label">{{ $label }}</div>
                    <div class="value">{{ $value }}</div>
                </td>
                @if ($loop->iteration % 4 === 0 && ! $loop->last)
                    </tr><tr>
                @endif
            @endforeach
        </tr>
    </table>
@endif

<table class="data">
    <thead>
    <tr>@foreach ($columns as $heading)<th>{{ $heading }}</th>@endforeach</tr>
    </thead>
    <tbody>
    @forelse ($rows as $row)
        <tr>@foreach (array_keys($columns) as $key)<td>{{ $row[$key] ?? '' }}</td>@endforeach</tr>
    @empty
        <tr><td colspan="{{ count($columns) }}" class="muted">No records for this period.</td></tr>
    @endforelse
    </tbody>
</table>

<footer>Generated {{ now()->format('d M Y H:i') }} (Addis Ababa){{ $generatedBy ? ' by '.$generatedBy : '' }} &middot; Confidential</footer>
</body>
</html>
