# Локальная разработка: WhatsApp webhook → CRM
# Запускайте в 3 отдельных терминалах ИЛИ только шаг 2+3 после serve.

Write-Host "=== МФПО CRM: WhatsApp (локально) ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "Терминал 1: php artisan serve" -ForegroundColor Yellow
Write-Host "Терминал 2: npx localtunnel --port 8000" -ForegroundColor Yellow
Write-Host "  Скопируйте URL (https://xxx.loca.lt)" -ForegroundColor Gray
Write-Host "Терминал 3: php artisan green:setup-webhook https://xxx.loca.lt" -ForegroundColor Yellow
Write-Host ""
Write-Host "Текущий URL из .env:" -ForegroundColor Green
$envFile = Join-Path $PSScriptRoot "..\.env"
if (Test-Path $envFile) {
    Get-Content $envFile | Select-String "WEBHOOK_PUBLIC_URL"
}
Write-Host ""
Write-Host "Webhook endpoint: /api/green/webhook" -ForegroundColor Gray
Write-Host "В CRM: Сделки → сбросить фильтр по дате" -ForegroundColor Gray
