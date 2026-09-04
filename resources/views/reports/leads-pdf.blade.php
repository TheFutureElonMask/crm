<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Отчёт по сделкам</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; padding: 16px; }
        h1 { font-size: 16px; margin-bottom: 4px; }
        .meta { color: #666; font-size: 9px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #ddd; padding: 6px; text-align: left; }
        th { background: #f0f0f0; font-weight: bold; font-size: 9px; }
        td { font-size: 9px; }
    </style>
</head>
<body>
    <h1>Отчёт по сделкам</h1>
    <p class="meta">Сформировано: {{ $generated }}{{ $limit_note ?? '' }}</p>

    <table>
        <thead>
            <tr>
                <th>Сделка</th>
                <th>Клиент</th>
                <th>Телефон</th>
                <th>Сумма</th>
                <th>Статус</th>
                <th>Менеджер</th>
                <th>Дата</th>
            </tr>
        </thead>
        <tbody>
            @forelse($leads as $lead)
            <tr>
                <td>{{ $lead['title'] }}</td>
                <td>{{ $lead['client_name'] }}</td>
                <td>{{ $lead['phone'] }}</td>
                <td>{{ $lead['price'] }}</td>
                <td>{{ $lead['status'] }}</td>
                <td>{{ $lead['manager'] }}</td>
                <td>{{ $lead['date'] }}</td>
            </tr>
            @empty
            <tr><td colspan="7" style="text-align:center">Нет данных</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
