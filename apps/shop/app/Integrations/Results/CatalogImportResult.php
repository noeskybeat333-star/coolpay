<?php

namespace App\Integrations\Results;

final readonly class CatalogImportResult
{
    public function __construct(
        public int $received = 0,
        public int $created = 0,
        public int $updated = 0,
        public int $failed = 0,
        public array $errors = [],

        /**
         * Карточки сохранены, но цены получить не удалось — обычно
         * из-за лимита частоты у API цен. Это не провал импорта:
         * цены догоняются отдельной задачей.
         */
        public bool $pricesDeferred = false,

        /**
         * Сколько секунд маркетплейс просил подождать, если цены
         * отложены именно из-за лимита частоты. Ноль — цены отложены
         * по другой причине либо не отложены вовсе.
         */
        public int $pricesRetryAfterSeconds = 0,

        /**
         * Карточки, которых площадка больше не отдаёт: удалены или
         * переименован артикул. Не удаляем — на них могут ссылаться
         * заказы, — но помечаем архивными.
         */
        public int $archived = 0,
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }
}

