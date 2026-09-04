<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Отчёт по таймеру — {{ $manager->name }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; padding: 20px; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        .meta { color: #666; font-size: 11px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f0f0f0; font-weight: bold; }
        .total { font-weight: bold; background: #f8f8f8; }
    </style>
</head>
<body>
    <h1>Отчёт по таймеру</h1>
    <p class="meta">{{ $manager->name }} · {{ $periodLabel ?? ('Период: ' . $from . ' — ' . $to) }}</p>

    <table>
        <thead>
            <tr>
                <th>Дата</th>
                <th>Работа</th>
                <th>Перерыв</th>
            </tr>
        </thead>
        <tbody>
            @foreach($days as $day)
            <tr>
                <td>{{ $day['date'] }}</td>
                <td>{{ $day['work_hm'] }}</td>
                <td>{{ $day['break_hm'] }}</td>
            </tr>
            @endforeach
            <tr class="total">
                <td>Итого</td>
                <td>{{ $total_work }}</td>
                <td>{{ $total_break }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
