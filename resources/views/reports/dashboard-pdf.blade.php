<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Статистика дашборда</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; padding: 16px; }
        h1 { font-size: 16px; margin-bottom: 4px; }
        .meta { color: #666; font-size: 9px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; margin-bottom: 16px; }
        th, td { border: 1px solid #ddd; padding: 6px; text-align: left; }
        th { background: #f0f0f0; font-weight: bold; font-size: 9px; }
        td { font-size: 9px; }
        h2 { font-size: 12px; margin-top: 16px; margin-bottom: 6px; }
        .stats-block { margin-bottom: 12px; }
    </style>
</head>
<body>
    <h1>Статистика дашборда</h1>
    <p class="meta">Период: {{ $period }}. Сформировано: {{ $generated }}</p>

    <div class="stats-block">
        <h2>Общие показатели</h2>
        <table style="width: auto;">
            <tr><td>Всего в базе</td><td>{{ $stats['total_leads'] }}</td></tr>
            <tr><td>Сумма сделок (₸)</td><td>{{ number_format($stats['active_deals_sum'], 0, '', ' ') }}</td></tr>
            <tr><td>В процессе</td><td>{{ $stats['in_progress_count'] }}</td></tr>
            <tr><td>Конверсия %</td><td>{{ $stats['conversion_rate'] }}</td></tr>
        </table>
    </div>

    <h2>Эффективность по дням</h2>
    <table>
        <thead>
            <tr>
                <th>Дата</th>
                <th>Создано</th>
                <th>Закрыто успешно</th>
                <th>Отказов</th>
                <th>Выручка (₸)</th>
            </tr>
        </thead>
        <tbody>
            @forelse($daily_stats as $d)
            <tr>
                <td>{{ $d['date'] }}</td>
                <td>{{ $d['opened'] }}</td>
                <td>{{ $d['won'] }}</td>
                <td>{{ $d['lost'] }}</td>
                <td>{{ number_format($d['revenue'], 0, '', ' ') }}</td>
            </tr>
            @empty
            <tr><td colspan="5">Нет данных</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Лидеры периода</h2>
    <table>
        <thead>
            <tr>
                <th>Менеджер</th>
                <th>Новых</th>
                <th>В работе</th>
                <th>Успешно</th>
                <th>Отказов</th>
                <th>Выручка (₸)</th>
            </tr>
        </thead>
        <tbody>
            @forelse($managers as $m)
            <tr>
                <td>{{ $m['name'] }}</td>
                <td>{{ $m['new_leads'] }}</td>
                <td>{{ $m['processing_leads'] }}</td>
                <td>{{ $m['won_leads'] }}</td>
                <td>{{ $m['lost_leads'] }}</td>
                <td>{{ number_format($m['total_money'], 0, '', ' ') }}</td>
            </tr>
            @empty
            <tr><td colspan="6">Нет данных</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
