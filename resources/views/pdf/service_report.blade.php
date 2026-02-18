<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
        .header { text-align: center; margin-bottom: 20px; }
        .box { border: 1px solid #ccc; padding: 10px; margin-bottom: 10px; }
        .title { font-size: 16px; font-weight: bold; }
    </style>
</head>
<body>

<div class="header">
    <div class="title">SERVIS RAPOR FORMU</div>
    <div>Servis No: {{ $service->request_number }}</div>
</div>

<div class="box">
    <strong>Firma:</strong> {{ $service->company->name ?? '' }} <br>
    <strong>Adres:</strong> {{ $service->address }} <br>
</div>

<div class="box">
    <strong>Teknisyen:</strong> {{ $service->report->technician->name ?? '' }} <br>
    <strong>Servis Turu:</strong> {{ $service->service_type }} <br>
</div>

<div class="box">
    <strong>Yapilan Islem:</strong><br>
    {{ $service->report->work_summary ?? '' }}
</div>

<div class="box">
    <strong>Kullanilan Parcalar:</strong><br>
    {{ $service->report->parts_used ?? '' }}
</div>

<div class="box">
    <strong>Toplam Sure:</strong>
    {{ $service->report->total_minutes ?? '' }} dakika
</div>

<br><br>

Musteri Imza: ____________________________

</body>
</html>
