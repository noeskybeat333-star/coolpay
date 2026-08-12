# CoolPay — контекст проекта для Claude Code

## О проекте

Инфраструктура интернет-магазина электроники. Развёрнута на выделенной VM,
приложение магазина — Laravel + Filament.

**Цель проекта:** CRM как центральная точка, где хранится вся информация по
всем каналам продаж — собственная витрина плюс маркетплейсы.

## Окружение

- Гипервизор: Proxmox VE
- VM: `shop-infra`
- ОС: Debian 13
- IP: `10.10.10.20`

## Репозиторий и пути

- Корень git-репозитория: `/opt/infra`
- Приложение магазина: `/opt/infra/apps/shop` (владелец `ruslan`)
- `/opt/infra/compose` принадлежит **root** — правки только через `sudo`
- GitHub: `noeskybeat333-star/coolpay`
- Постоянные данные (вне репозитория): `/opt/data`, `/opt/backups`, `/opt/logs`

## Стек

- Laravel `13.20.0`
- Filament `5.7.1`
- PostgreSQL
- Docker Compose: `shop-app` (php-fpm), `shop-web` (nginx), `shop-scheduler`

Типовая команда для artisan-команд в контейнере:
```bash
cd /opt/infra/apps/shop
docker exec --user "$(id -u):$(id -g)" --env HOME=/tmp shop-app php artisan <command>
```

**Не использовать `--env HOME=/tmp` для `tinker`** — psysh не сможет писать
конфиг и молча выпадет в bash. Для разовых проверок надёжнее одноразовый
php-скрипт в корне приложения, поднимающий Laravel через `bootstrap/app.php`.

## История

```
feat: add Wildberries FBS order import   ← последний
84629fc feat: add marketplace order import foundation
02107b8 feat: add omnichannel order management
052dad5 feat: add guest checkout workflow
e6b8f8b feat: add session cart and order foundation
dcd9f6f feat: add dark theme to product page
```

Перед работой всегда сверяться с фактическим состоянием:
```bash
cd /opt/infra && git status --short && git log --oneline -3
docker exec --env HOME=/tmp shop-app php artisan migrate:status | tail -n 12
```

---

## Что уже реализовано

### Витрина
Главная страница, каталог активных товаров, поиск, фильтрация по категориям,
цены и остатки, страницы товаров, описание/характеристики WB, светлая и тёмная
темы, баннеры, галерея изображений (полноэкранный просмотр, свайпы на мобильных).

### Интеграция Wildberries (каталог)
Импорт карточек WB: изображения, описание, характеристики, цены, остатки FBS,
связывание карточки WB с локальным товаром, отображение в Filament.

### Корзина (гостевая, через сессию)
```
app/Http/Controllers/CartController.php
app/Services/CartService.php
resources/views/store/cart.blade.php
```
Маршруты: `GET /cart`, `POST /cart/items/{product}`, `PATCH /cart/items/{product}`,
`DELETE /cart/items/{product}`.

В сессии хранится только ID товара и количество — цена всегда читается из БД
заново при открытии корзины (защита от устаревшей цены/неактивного товара/
превышения остатка).

### Оформление заказа
```
app/Http/Controllers/CheckoutController.php
app/Http/Requests/StoreOrderRequest.php
resources/views/store/checkout.blade.php
resources/views/store/order-success.blade.php
```
Маршруты: `GET /checkout`, `POST /checkout`, `GET /checkout/success`.

Логика внутри транзакции PostgreSQL: повторное чтение товаров →
`lockForUpdate()` → проверка публикации/цены/остатка → фиксация snapshot
в `order_items` → создание заказа → уменьшение остатка → очистка корзины.
Номер заказа: `CP-YYYYMMDD-NNNNNN`.

### Filament: управление заказами
```
app/Filament/Resources/Orders/
```
`CreateOrder.php`, `EditOrder.php`, `OrderForm.php` намеренно удалены — заказ
может появиться только через витрину или импорт маркетплейса, не вручную.

### Статусы и безопасная отмена
```
app/Services/OrderStatusService.php
```
Статусы заказа: `new, confirmed, processing, shipped, completed, cancelled`.
Статусы оплаты: `pending, paid, failed, refunded`.
`orders.stock_restored_at` — защита от повторного возврата остатка при отмене.

### Импорт заказов маркетплейсов — РАБОТАЕТ
```
app/Integrations/Contracts/ImportsMarketplaceOrders.php
app/Integrations/Results/OrderImportResult.php
app/Services/MarketplaceOrderUpserter.php     общий upsert для всех площадок
app/Services/WildberriesOrderImporter.php     разбор ответов WB
app/Services/MarketplaceOrderSyncRunner.php   блокировка, журнал, запуск
app/Console/Commands/ImportMarketplaceOrders.php
tests/Feature/WildberriesOrderImportTest.php  4 теста
```

Цепочка: `WB API → WildberriesOrderImporter → MarketplaceOrderUpserter →
orders + order_items`. Всё знание про Wildberries живёт в импортёре; upserter
про площадки ничего не знает и переиспользуется для Ozon и остальных.

Запуск тремя способами:
- кнопка «Синхронизировать заказы» на странице подключения в Filament
- `php artisan marketplace:import-orders --days=30 [--account=ID]`
- планировщик, ежечасно (`routes/console.php`, контейнер `shop-scheduler`)

Все три идут через `MarketplaceOrderSyncRunner`: `Cache::lock` не даёт двум
импортам столкнуться, результат пишется в `marketplace_sync_logs`
(`operation = orders_import`). Экрана для просмотра этих логов пока нет —
ни у заказов, ни у каталога.

Проверено на живом кабинете: 6 заказов FBS, суммы сошлись с кабинетом WB
до копейки, отменённый заказ распознан, повторный прогон даёт
`создано: 0, обновлено: 6`.

---

## Wildberries API — намеренные факты, не догадки

Всё ниже проверено запросами к живому кабинету 12.08.2026. Не переоткрывать.

### Заказы FBS: `GET marketplace-api.wildberries.ru/api/v3/orders`

- **Глубина строго 90 дней.** `dateFrom` за 90 дней отдаёт данные, за 120 и
  больше — `HTTP 200` с пустым списком, без ошибки. Отсюда
  `WildberriesOrderImporter::MAX_DEPTH_DAYS = 90`.
- **`dateTo` не поддерживается** — `HTTP 400 IncorrectParameter`. Значит обойти
  предел окнами вглубь нельзя: 90 дней это потолок самого WB, а не нашего кода.
- **Окно скользит.** Заказ старше 90 дней исчезает из выдачи навсегда. Поэтому
  ежечасная автосинхронизация обязательна, а не желательна. На момент первого
  импорта самый старый заказ был возрастом 89 дней — сутки до потери.
- **`next` не обнуляется** даже на неполной странице, признаком конца выборки
  служить не может. Останавливаться по короткой странице или повторению курсора.
- **Цены в копейках.** `price`/`convertedPrice` делить на 100.
- **Одна запись = одна единица товара**, поля количества нет. Несколько единиц
  приходят отдельными сборочными заданиями с общим `orderUid`.
- **Данных покупателя нет** — ни имени, ни телефона, `address: null`. Ради этого
  и делались nullable поля в `orders`.
- **Статусов в ответе нет.** Отдельный `POST /api/v3/orders/status`
  с `{"orders":[id,...]}` → `supplierStatus` + `wbStatus`.

### Маппинг статусов

| `wbStatus` | наш `status` | `payment_status` |
|---|---|---|
| `sold` | `completed` | `paid` |
| `canceled`, `canceled_by_client`, `declined_by_client`, `defect` | `cancelled` | `refunded` |
| прочее | по `supplierStatus` | `pending` |

| `supplierStatus` | наш `status` |
|---|---|
| `new` | `new` |
| `confirm` | `confirmed` |
| `complete`, `deliver`, `receive` | `shipped` |
| `cancel` | `cancelled` |

Сырьё сохраняется в `orders.external_status` строкой `supplierStatus/wbStatus`,
полный контекст задания — в `orders.metadata`.

### Идентификаторы

- `external_id` заказа = `orderUid` (группирующий).
- `external_number` = минимальный `id` сборочного задания — именно он виден
  в колонке «Заказ» в кабинете WB.
- ID всех заданий группы → `order_items.product_snapshot.external_line_ids`
  и `orders.metadata.assembly_task_ids`.
- Товар: `nmId` → `MarketplaceListing.external_id` → `product_id`.
  Запасной путь — `article` против `seller_sku`/`offer_id`.

### Другие модели работы

Продавец работает **не только по FBS**. В кабинете три вкладки: Маркетплейс
(FBS), Витрина (DBS), Курьером (DBW). В `WildberriesOrderImporter::SOURCES`
объявлены все три, включён только `fbs`.

- `GET /api/v3/dbs/orders` отвечает `HTTP 400 IncorrectParameter` на те же
  параметры, что и FBS — эндпоинт живой, но ждёт другие. Надо доразведать.
- `GET /api/v3/dbs/orders/new` отвечает `HTTP 200`.

### Statistics API — ЗАКРЫТ, нужен новый токен

`statistics-api.wildberries.ru/api/v1/supplier/orders` и `/sales` отвечают
`HTTP 401 "token scope not allowed"`. У текущего токена нет категории
«Статистика».

Это **единственный путь** к полной истории и к FBO — оперативный API дальше
90 дней не отдаёт ничего. Для цели «CRM хранит всё» нужен токен с категориями
Контент + Маркетплейс + Цены и скидки + Статистика + Аналитика. Поле
`api_token` в `credentials` уже есть, менять схему не потребуется.

---

## ТЕКУЩАЯ ТОЧКА ПРОДОЛЖЕНИЯ

Импорт заказов WB FBS завершён и закоммичен. Дальше по приоритету:

1. **Ждём новый токен WB с категорией «Статистика»** (действие пользователя
   в кабинете WB). Как появится — второй импортёр по Statistics API:
   полная история + FBO. Складывать в те же `orders` через тот же
   `MarketplaceOrderUpserter`, дедупликация по
   `marketplace_account_id + external_id` не даст задвоиться.
2. Разведать параметры `GET /api/v3/dbs/orders`, включить `dbs` и `dbw`
   в `SOURCES` — у продавца эти модели активны.
3. Настраиваемый дашборд Filament (см. ниже).
4. Ресурс Filament для просмотра `marketplace_sync_logs`.
5. Ozon и Яндекс Маркет через тот же upserter.

---

## Что запланировано, но ещё не начато

### Настраиваемый дашборд Filament
Убрать `AccountWidget` и `FilamentInfoWidget` из
`app/Providers/Filament/AdminPanelProvider.php`, заменить на кастомные: новые
заказы, заказы за сегодня, ожидающие оплаты, сумма заказов, график
количества/суммы во времени, круговая диаграмма статусов, таблица последних
заказов. Персональные настройки на пользователя: набор виджетов, период
7/30/90 дней, кол-во vs сумма, учитывать отменённые, фильтр по каналам.

### Регистрация пользователей
Не подключена, но `orders.user_id` уже nullable — архитектура готова.

### Полный список нереализованного
- Statistics API: полная история и FBO (упирается в токен)
- DBS/DBW, Ozon, Яндекс Маркет
- Настраиваемый дашборд и графики
- Ресурс для просмотра журнала синхронизаций
- Регистрация покупателей, личный кабинет, история заказов
- Автоматический расчёт доставки, онлайн-оплата
- Финансовая сверка выплат маркетплейсов
- Уведомления о новых заказах
- Тесты корзины и оформления заказа (импорт заказов покрыт, остальное нет)

---

## Известные проблемы инфраструктуры

### Сборка ассетов в Docker
`Dockerfile` не собирает фронтенд (нет Node), `public/build` в `.gitignore`,
а bind-mount `/opt/infra/apps/shop:/var/www/html` перекрывает всё, что образ
собрал при билде — включая `composer install`. То есть `vendor/` и
`public/build` обязаны существовать на хосте. Пересборка образа тратит ~2.5
минуты на работу, результат которой затем скрывается маунтом.

Решение (не сделано): multi-stage образ с Node-стадией, ассеты и `vendor`
внутрь образа, маунт кода убрать, а под `storage/app/public` завести именованный
том — иначе загруженные фото товаров потеряются при первой же пересборке.

### DNS в контейнерах
Docker формирует `/etc/resolv.conf` контейнера **в момент создания**. При
загрузке VM контейнеры с `restart: unless-stopped` стартуют раньше, чем dhcpcd
запишет DNS, и получают пустой список апстримов — тогда любые обращения к
внешним API падают с `cURL error 6: Could not resolve host`.

Симптом: в `docker exec <c> cat /etc/resolv.conf` вместо `# ExtServers: [...]`
написано `NO EXTERNAL NAMESERVERS DEFINED`.
Лечение: `docker compose up -d --force-recreate` для затронутого стека.
Постоянное решение (не сделано): прописать `dns` в `/etc/docker/daemon.json`.

### Прочее
- HTTPS не настроен: в `compose/caddy/Caddyfile` только `:80` без домена.
- `docs/network.md` расходится с реальностью: LAN описан как `10.10.10.0/24`,
  а DHCP раздаёт DNS из `172.16.250.0/24`.

---

## Как работать с этим проектом (правила для Claude Code)

- **Пользователь выполняет команды сам.** Давать точную команду и объяснять,
  что она делает и что искать в выводе, а не запускать за него. Читать
  исходники проекта — можно и нужно, это контекст для объяснений.
- **Не ломать существующее.** Корзина, оформление заказа, Filament-ресурс
  заказов, статусы, импорт заказов — рабочий функционал. Изменения только
  точечные и с пониманием, зачем.
- **Способ оплаты через маркетплейс НЕ добавлять** на сайт — пользователь
  явно исключил этот вариант. Выплаты маркетплейсов продавцу — отдельный
  будущий финансовый модуль, не способ оплаты заказа.
- **Заказы создаются только через витрину или импорт** — не восстанавливать
  `CreateOrder`/`EditOrder`/`OrderForm` в Filament, это удалено намеренно.
- **Транзакционность и `lockForUpdate()`** в оформлении заказа — не убирать,
  это защита от гонки при последней единице товара.
- **Snapshot данных в `order_items`** обязателен: история заказа не должна
  зависеть от текущего состояния товара.
- **Для заказов маркетплейсов остаток не возвращается при отмене** — это
  осознанное решение, не «баг».
- **Проверять фактами, а не догадками.** Перед тем как писать разбор ответа
  внешнего API — получить один реальный ответ и посмотреть на поля. Этот
  подход уже сэкономил здесь несколько переделок.
- Перед любой новой миграцией — `artisan migrate:status`.
- Все artisan-команды — через `docker exec` в `shop-app`, не на хосте.
