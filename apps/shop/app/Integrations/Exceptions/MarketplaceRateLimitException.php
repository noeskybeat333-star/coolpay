<?php

namespace App\Integrations\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Маркетплейс ответил «слишком много запросов».
 *
 * Отдельный класс нужен, чтобы очередь могла отличить временный отказ
 * от настоящей ошибки: по нему джоба возвращает задачу в очередь с паузой,
 * вместо того чтобы падать и терять работу.
 *
 * Наследуется от RuntimeException намеренно — существующие обработчики,
 * ловящие RuntimeException, продолжают работать как раньше.
 */
class MarketplaceRateLimitException extends RuntimeException
{
    /**
     * Пауза, когда маркетплейс её не сообщил. Осознанно большая: повторять
     * слишком рано вреднее, чем подождать лишнее — преждевременный запрос
     * попадает в тот же счётчик и продлевает блокировку.
     */
    private const FALLBACK_SECONDS = 420;

    private const MAX_SECONDS = 3600;

    /**
     * Запас поверх названного маркетплейсом времени: возвращаться ровно
     * в момент истечения окна — значит рисковать очередным отказом.
     */
    private const SAFETY_MARGIN_SECONDS = 20;

    public function __construct(
        string $message = 'Превышен лимит запросов к API маркетплейса.',
        public readonly int $retryAfterSeconds = self::FALLBACK_SECONDS,
    ) {
        parent::__construct($message);
    }

    /**
     * Собирает исключение, взяв паузу из заголовков ответа.
     *
     * Wildberries отдаёт X-Ratelimit-Retry (секунды до сброса); стандартный
     * Retry-After поддерживается как запасной вариант.
     */
    public static function fromResponse(
        Response $response,
        string $message,
    ): self {
        $seconds = (int) (
            $response->header('X-Ratelimit-Retry')
            ?: $response->header('X-Ratelimit-Reset')
            ?: $response->header('Retry-After')
            ?: 0
        );

        $seconds = $seconds > 0
            ? $seconds + self::SAFETY_MARGIN_SECONDS
            : self::FALLBACK_SECONDS;

        return new self(
            $message.' Повтор через '.$seconds.' с.',
            min($seconds, self::MAX_SECONDS),
        );
    }
}
