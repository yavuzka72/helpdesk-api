# HelpDesk API

Kurumsal helpdesk + teknik servis operasyonları için Laravel tabanlı API projesi.

## Özellikler

- Çoklu firma yapısı (`companies`)
- Rol bazlı kullanıcılar (`admin`, `technician`, `customer`)
- Ticket yönetimi
- Ticket -> Service Request dönüşümü (transaction güvenli)
- Ticket ve Service Request için dosya/ek (screenshot, pdf vb.) yükleme
- Service Report (1 servis = 1 rapor)
- PDF servis formu üretimi
- SLA süre takibi (priority bazlı)
- Activity log (kim ne yaptı)
- Bildirim sistemi + gerçek zamanlı yayın (Reverb)
- Dashboard metrikleri

## Teknoloji

- PHP 8.2+
- Laravel 12
- MySQL
- Laravel Sanctum
- Laravel Reverb (WebSocket)
- DomPDF

## Veritabanı

Bu proje şu anda MySQL için ayarlı:

- `DB_CONNECTION=mysql`
- `DB_HOST=localhost`
- `DB_PORT=3306`
- `DB_DATABASE=HELPDESK_DB`
- `DB_USERNAME=root`
- `DB_PASSWORD=`

## Kurulum

1. Bağımlılıkları kur:

```bash
composer install
```

2. `.env` dosyasını kontrol et ve DB bilgilerini doğrula.

3. Uygulama anahtarı üret:

```bash
php artisan key:generate
```

4. Migration + seed çalıştır:

```bash
php artisan migrate --seed
```

## Çalıştırma

Ayrı terminallerde çalıştır:

```bash
php artisan serve
php artisan queue:work
php artisan reverb:start
```

## Mimari Akış

- Ticket: iletişim süreci
- Service Request: operasyonel iş emri
- İlişki: `Ticket (1) -> ServiceRequest (0..1)`
- Service Report: `ServiceRequest (1) -> ServiceReport (0..1)`

Tipik akış:

1. Customer ticket açar.
2. Admin/teknik ekip ticket'ı değerlendirir.
3. Gerekirse `convert-to-service` ile servis talebi oluşturulur.
4. Technician servis raporu girer, servis `completed` olur.
5. PDF servis formu üretilir.

## ER Diyagramı

```text
companies (1) ----< users (N)
companies (1) ----< tickets (N)
companies (1) ----< service_requests (N)

users (1) ----< tickets.created_by (N)
users (1) ----< tickets.assigned_to (N)
users (1) ----< service_requests.created_by (N)
users (1) ----< service_requests.assigned_to (N)

tickets (1) ----< ticket_messages (N)
tickets (1) ---- service_requests (0..1)

service_requests (1) ---- service_reports (0..1)
users (1) ----< service_reports.technician_id (N)

users (1) ----< activity_logs (N)
users (1) ----< notifications (N)
```

Temel tablolar:

1. `companies`
2. `users`
3. `tickets`
4. `ticket_messages`
5. `service_requests`
6. `service_reports`
7. `sla_settings`
8. `activity_logs`
9. `notifications`

## Roller ve Yetki

- `admin`: tüm kayıtlar + dashboard + activities
- `technician`: kendi atanan işleri görür
- `customer`: kendi şirketine ait kayıtları görür

Route korumaları `auth:sanctum` + `role` middleware ile uygulanır.

## Ana API Uçları

### Ticket

- `GET /api/tickets`
- `POST /api/tickets`
- `GET /api/tickets/{ticket}`
- `PUT/PATCH /api/tickets/{ticket}`
- `DELETE /api/tickets/{ticket}`
- `POST /api/tickets/{ticket}/convert-to-service`
- `POST /api/tickets` (multipart ile `attachments[]` destekler)
- `PUT/PATCH /api/tickets/{ticket}` (multipart ile yeni ek eklenebilir)

### Service Request

- `GET /api/service-requests`
- `POST /api/service-requests`
- `GET /api/service-requests/{service_request}`
- `PUT/PATCH /api/service-requests/{service_request}`
- `DELETE /api/service-requests/{service_request}`
- `POST /api/service-requests/{serviceRequest}/report` (technician)
- `GET /api/service-requests/{serviceRequest}/report`
- `GET /api/service-requests/{serviceRequest}/pdf`
- `POST /api/service-requests` (multipart ile `attachments[]` destekler)
- `PUT/PATCH /api/service-requests/{service_request}` (multipart ile yeni ek eklenebilir)

### Notifications

- `GET /api/notifications`
- `POST /api/notifications/{id}/read`

### Dashboard

- `GET /api/dashboard` (admin)
- `GET /api/activities` (admin)

### Diğer CRUD

- `companies`, `users`, `ticket-messages`, `service-reports` için `apiResource` endpointleri mevcut.

## Postman Kullanımı

Projeye hazır collection dosyası eklendi:

- `docs/postman/HelpDesk_API.postman_collection.json`

Import adımları:

1. Postman -> `Import` -> `File` seç.
2. `docs/postman/HelpDesk_API.postman_collection.json` dosyasını yükle.
3. Collection variables içinde `base_url` ve `token` değerlerini ayarla.

Önerilen `base_url`:

- `http://127.0.0.1:8000/api`

## SLA

Varsayılan SLA seed değerleri:

- `low`: 4 saat yanıt / 24 saat çözüm
- `medium`: 2 saat yanıt / 12 saat çözüm
- `high`: 1 saat yanıt / 6 saat çözüm
- `critical`: 30 dk yanıt / 2 saat çözüm

Ticket oluşturulurken `response_due_at` ve `resolution_due_at` otomatik hesaplanır.

## Activity Log

`activity_logs` tablosunda tutulur.

Örnek aksiyonlar:

- `created_ticket`
- `updated_ticket_status`
- `assigned_ticket`
- `created_service_request`
- `updated_service_status`
- `completed_service`

## Real-time Notifications (Reverb)

- Event: `NotificationCreated`
- Channel: `user.{id}` (private)
- Event name: `notification.created`

Channel auth: `routes/channels.php`.

Flutter tarafında dinlenecek kanal:

- `private-user.{id}`
- event: `.notification.created`

## PDF Servis Formu

- View: `resources/views/pdf/service_report.blade.php`
- Endpoint: `GET /api/service-requests/{serviceRequest}/pdf`

## Dosya Yükleme (Attachments)

Desteklenen alan:

- `attachments[]` (çoklu dosya)

Kısıtlar:

- Maksimum 5 dosya
- Her dosya maksimum 10MB
- İzinli tipler: `jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,txt,zip,rar`

İstek tipi:

- `multipart/form-data`

Not:

- Public URL için bir kez `php artisan storage:link` çalıştırın.

## Faydalı Komutlar

```bash
php artisan route:list --path=api
php artisan test
php artisan migrate:fresh --seed
```

## Not

Bu repoda henüz özel `login/logout` endpointi yok. Sanctum token yönetimi için ayrı auth endpointleri eklenebilir.

## Flutter Web Entegrasyon Akışı

### 1. Uygulama Başlangıcı

1. API base URL ayarla (`/api`).
2. Kullanıcı token'ını secure storage'dan oku.
3. Tüm isteklerde `Authorization: Bearer <token>` gönder.

### 2. Dashboard Ekranı

1. `GET /api/dashboard` çağır.
2. `tickets`, `services`, `sla` kartlarını doldur.
3. Admin için `GET /api/activities` ile son aktiviteleri göster.

### 3. Ticket Akışı

1. Liste: `GET /api/tickets`
2. Oluşturma: `POST /api/tickets`
3. Detay: `GET /api/tickets/{id}`
4. Servise dönüştürme: `POST /api/tickets/{id}/convert-to-service`

### 4. Servis Akışı

1. Liste: `GET /api/service-requests`
2. Rapor oluşturma (technician): `POST /api/service-requests/{id}/report`
3. PDF indirme: `GET /api/service-requests/{id}/pdf`

### 5. Bildirim Merkezi

1. Liste: `GET /api/notifications`
2. Okundu işaretleme: `POST /api/notifications/{id}/read`

### 6. Reverb ile Gerçek Zamanlı Dinleme

Flutter tarafında (ör. `pusher_channels_flutter` ile) kullanıcı kanalına abone ol:

1. Kanal: `private-user.<auth_user_id>`
2. Event: `notification.created`
3. Event gelince local state'e ekle ve badge sayısını güncelle.

Not: Private channel auth için backend `/broadcasting/auth` endpointi `auth:sanctum` ile erişilebilir olmalı.
