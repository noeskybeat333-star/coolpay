# CoolPay — контекст проекта для Claude Code

## О проекте

Инфраструктура интернет-магазина электроники. Развёрнута на выделенной VM,
приложение магазина — Laravel + Filament.

## Окружение

- Гипервизор: Proxmox VE
- VM: `shop-infra`
- ОС: Debian 13
- IP: `10.10.10.20`

## Репозиторий и пути

- Корень git-репозитория: `/opt/infra`
- Приложение магазина: `/opt/infra/apps/shop`
- GitHub: `noeskybeat333-star/coolpay`
- Remote: `git@github.com:noeskybeat333-star/coolpay.git`
- Постоянные данные (вне репозитория): `/opt/data`, `/opt/backups`, `/opt/logs`

## Стек

- Laravel `13.20.0`
- Filament `5.7.1`
- PostgreSQL
- Docker Compose, приложение в контейнере `shop-app`

Типовая команда для artisan-команд в контейнере:
```bash
cd /opt/infra/apps/shop
docker exec --user "$(id -u):$(id -g)" --env HOME=/tmp shop-app php artisan <command>
```

## Последний закоммиченный коммит

```
02107b8 feat: add omnichannel order management
```

История последних этапов:
```
02107b8 feat: add omnichannel order management
052dad5 feat: add guest checkout workflow
e6b8f8b feat: add session cart and order foundation
dcd9f6f feat: add dark theme to product page
60c288e feat: add interactive product image gallery
26aadef feat: add storefront and marketplace price management
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
Файлы:
```
app/Http/Controllers/CartController.php
app/Services/CartService.php
resources/views/store/cart.blade.php
```
Маршруты: `GET /cart`, `POST /cart/items/{product}`, `PATCH /cart/items/{product}`,
`DELETE /cart/items/{product}`.

В сессии хранится только ID товара и количество — цена всегда читается из БД
заново при открытии корзины (защита от устаревшей цены/неактивного товара/
превышения остатка). Реализовано: добавление, изменение количества, удаление,
персистентность после reload, счётчик в шапке, проверка доступности, расчёт суммы.

### Оформление заказа
Файлы:
```
app/Http/Controllers/CheckoutController.php
app/Http/Requests/StoreOrderRequest.php
resources/views/store/checkout.blade.php
resources/views/store/order-success.blade.php
```
Маршруты: `GET /checkout`, `POST /checkout`, `GET /checkout/success`.

Форма: имя, телефон, email (необязательно), доставка/самовывоз, адрес,
способ оплаты (`cash`, `bank_transfer` — **без оплаты через маркетплейс,
это осознанно исключено**), комментарий.

Логика внутри транзакции PostgreSQL: повторное чтение товаров →
`lockForUpdate()` → проверка публикации/цены/остатка → фиксация snapshot
(название, SKU, цена) в `order_items` → создание заказа → уменьшение остатка →
очистка корзины. Защищает от гонки при одновременной покупке последней единицы.
Номер заказа: `CP-YYYYMMDD-NNNNNN`.

Таблицы: `orders`, `order_items` (со snapshot данных на момент заказа).

### Filament: управление заказами
```
app/Filament/Resources/Orders/
├── OrderResource.php
├── Pages/ListOrders.php
├── Pages/ViewOrder.php
├── Schemas/OrderInfolist.php
└── Tables/OrdersTable.php
```
`CreateOrder.php`, `EditOrder.php`, `OrderForm.php` намеренно удалены — заказ
может появиться только через витрину или импорт маркетплейса, не вручную.

Список: внутренний номер, канал, статус, статус оплаты, покупатель, телефон,
способ получения, схема FBO/FBS, кол-во позиций, сумма, внешний номер, дата.
Фильтры: источник, кабинет(ы) маркетплейса, статус, статус оплаты, способ получения.

### Статусы и безопасная отмена
```
app/Services/OrderStatusService.php
```
Статусы заказа: `new, confirmed, processing, shipped, completed, cancelled`.
Статусы оплаты: `pending, paid, failed, refunded`.

Поле `orders.stock_restored_at` — защита от повторного возврата остатка при
отмене. Для заказов маркетплейсов остаток НЕ возвращается при отмене (CRM его
не списывала при импорте).

### Единая структура заказов всех каналов
Новые поля в `orders`:
```
source, marketplace_account_id, external_id, external_number,
external_status, fulfillment_type, external_created_at, synced_at, metadata
```
`source = storefront | marketplace`. Уникальность внешнего заказа:
`marketplace_account_id + external_id`. Повторная синхронизация должна
обновлять заказ, а не дублировать.

### Подготовка универсального импорта маркетплейсов
```
app/Integrations/Contracts/ImportsMarketplaceOrders.php
app/Integrations/Results/OrderImportResult.php
app/Services/MarketplaceOrderUpserter.php
```
`ImportsMarketplaceOrders` — контракт для драйверов, умеющих импортировать
заказы (не ломает базовый `MarketplaceDriver`). `OrderImportResult` — счётчики
результата синхронизации. `MarketplaceOrderUpserter` — общий сервис upsert
заказов (объединяет одинаковые товары, суммирует количество, не дублирует).

### Nullable поля покупателя
Миграция `2026_08_07_164959_make_marketplace_customer_fields_nullable_in_orders_table`
делает `customer_name`/`customer_phone` nullable (для заказов маркетплейсов,
где эти данные не передаются). Оформление на сайте по-прежнему требует их
через `StoreOrderRequest`.

### Архитектура интеграций маркетплейсов
```
IntegrationType → MarketplaceAccount → Order
MarketplaceDriverManager, MarketplaceDriver, WildberriesDriver, UnsupportedDriver
```
Один заказ привязан к конкретному кабинету, а не просто к площадке.
Текущие драйверы: `wildberries → WildberriesDriver`, остальные
(`ozon, yandex-market, megamarket, mvideo`) → `UnsupportedDriver`.

---

## Особенности импорта Wildberries (важно для следующего шага)

- Начинаем с **FBS** (магазин работает по этой модели, токен Marketplace API
  уже используется).
- Одна покупка может вернуться как несколько сборочных заданий — объединять
  по `orderUid`, ID отдельных заданий сохранять в snapshot позиции.
- Сопоставление товара: WB даёт `nmId`/`article`/`skus` →
  `MarketplaceListing.external_id` (это `nmId`) → `MarketplaceListing.product_id`
  → локальный товар.
- Источники на будущее: FBS (Marketplace API), DBS/DBW (отдельные разделы API),
  FBO и общие заказы (отчёты/статистика WB). Разные источники могут требовать
  разных прав токена (Marketplace vs Statistics/Reports) — если прав нет, CRM
  должна показать понятную ошибку, а не упасть.

---

## ⚠️ ТЕКУЩАЯ ТОЧКА ПРОДОЛЖЕНИЯ — последнее действие не выполнено

Последняя инструкция была начать фактическое подключение импорта заказов WB FBS.
**Она не была выполнена.** Перед тем как писать код — проверить фактическое
состояние:

```bash
cd /opt/infra
git status --short
```

```bash
cd /opt/infra/apps/shop
docker exec --env HOME=/tmp shop-app php artisan migrate:status | tail -n 12
```

Ожидаемо (нужно подтвердить через `git status`) незакоммиченными могут быть:
```
app/Integrations/Contracts/ImportsMarketplaceOrders.php
app/Integrations/Results/OrderImportResult.php
app/Services/MarketplaceOrderUpserter.php
app/Models/Order.php
app/Services/OrderStatusService.php
database/migrations/2026_08_07_164959_make_marketplace_customer_fields_nullable_in_orders_table.php
```

### Что нужно сделать

1. Создать класс:
```bash
cd /opt/infra/apps/shop
docker exec --user "$(id -u):$(id -g)" --env HOME=/tmp shop-app php artisan make:class Services/WildberriesOrderImporter
```

2. В `app/Integrations/Drivers/WildberriesDriver.php`:

Добавить импорты:
```php
use App\Integrations\Contracts\ImportsMarketplaceOrders;
use App\Integrations\Results\OrderImportResult;
use App\Services\WildberriesOrderImporter;
use Carbon\CarbonImmutable;
```

Изменить объявление класса:
```php
class WildberriesDriver implements
    MarketplaceDriver,
    ImportsMarketplaceOrders
```

Добавить метод перед `capabilities()`:
```php
public function importOrders(
    MarketplaceAccount $account,
    ?CarbonImmutable $since = null,
): OrderImportResult {
    return app(WildberriesOrderImporter::class)->import($account, $since);
}
```

Обновить `capabilities()`:
```php
'orders_read' => true,
'orders_fbs' => true,
'orders_dbs' => false,
'orders_dbw' => false,
'orders_fbo' => false,
```

3. Реализовать сам `WildberriesOrderImporter` (использует `MarketplaceOrderUpserter`,
   группировку по `orderUid`, сопоставление товара через `MarketplaceListing`).

### Дальнейшая последовательность

1. Проверить git и миграции
2. Выполнить пропущенное действие выше
3. Реализовать `WildberriesOrderImporter`
4. Импортировать FBS за тестовый период 7 дней
5. Проверить права токена
6. Проверить группировку по `orderUid`
7. Проверить цены и связи с товарами
8. Добавить кнопку синхронизации в Filament
9. Добавить журнал результата
10. Написать автоматические тесты
11. Коммит и пуш в GitHub
12. Перейти к настраиваемому дашборду (см. ниже)
13. Убрать стандартные виджеты Filament (см. ниже)
14. Добавить графики и фильтры по каналам

---

## Что запланировано, но ещё не начато

### Настраиваемый дашборд Filament
Убрать `AccountWidget` и `FilamentInfoWidget` из
`app/Providers/Filament/AdminPanelProvider.php` (сейчас в `->widgets([...])`),
заменить на кастомные: новые заказы, заказы за сегодня, ожидающие оплаты,
сумма заказов, график количества/суммы во времени, круговая диаграмма
статусов, таблица последних заказов.

Персональные настройки на пользователя: какие виджеты показывать, период
7/30/90 дней, кол-во vs сумма, учитывать/исключать отменённые, сколько заказов
в таблице, фильтр по сайту/маркетплейсам/кабинетам. Пока только спроектировано,
код не создан.

### Регистрация пользователей
Не подключена, но `orders.user_id` уже nullable — архитектура готова
(гостевые заказы сейчас, привязка к аккаунту позже, объединение гостевой
корзины с аккаунтом после входа — существующие гостевые заказы переделывать
не придётся).

### Полный список нереализованного
- Фактическая загрузка заказов WB (см. точку продолжения выше)
- Автосинхронизация по расписанию, кнопка "Синхронизировать", журнал импорта
- Импорт DBS/DBW/FBO, Ozon, Яндекс Маркет
- Настраиваемый дашборд и графики
- Регистрация покупателей, личный кабинет, история заказов
- Автоматический расчёт доставки, онлайн-оплата
- Финансовая сверка выплат маркетплейсов
- Уведомления о новых заказах
- Полноценные тесты корзины и заказов (сейчас только `2 passed, 2 assertions` —
  стандартные тесты Laravel, не покрытие новой логики)

---

## Как работать с этим проектом (правила для Claude Code)

- **Не ломать существующее.** Корзина, оформление заказа, Filament-ресурс
  заказов, статусы — рабочий, закоммиченный функционал. Любые изменения в
  этих зонах — только точечные и с пониманием, зачем.
- **Способ оплаты через маркетплейс НЕ добавлять** на сайт — пользователь
  явно исключил этот вариант. Выплаты маркетплейсов продавцу — отдельный
  будущий финансовый модуль, не способ оплаты заказа.
- **Заказы создаются только через витрину или импорт** — не восстанавливать
  `CreateOrder`/`EditOrder`/`OrderForm` в Filament, это удалено намеренно.
- **Транзакционность и `lockForUpdate()`** в оформлении заказа — не убирать,
  это защита от гонки при последней единице товара.
- **Snapshot данных в `order_items`** (название/SKU/цена на момент заказа) —
  обязателен, история заказа не должна зависеть от текущего состояния товара.
- **Для заказов маркетплейсов остаток не возвращается при отмене** — это
  осознанное решение, не "баг".
- Перед любой новой миграцией — проверить `artisan migrate:status`, не
  накатывать вслепую поверх незакоммиченных изменений.
- Все artisan-команды — через `docker exec` в контейнер `shop-app`, не
  напрямую на хосте.
