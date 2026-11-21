<?php

namespace App\Support;

/**
 * Outcome of one SalesRender GraphQL request.
 *
 * SalesRender reports a missing role permission with HTTP 200 and
 * errors[].extensions.code = FIELD_RESTRICTED_BY_ROLE, so HTTP status alone is not enough.
 */
final class SrResult
{
    public const PERMISSION_DENIED        = 'PERMISSION_DENIED';        // updateOrder
    public const PERMISSION_DENIED_READ   = 'PERMISSION_DENIED_READ';   // ordersFetcher & other queries
    public const PERMISSION_DENIED_CREATE = 'PERMISSION_DENIED_CREATE'; // addOrder
    public const AUTH_INVALID             = 'AUTH_INVALID';
    public const AUTH_FORBIDDEN_IP        = 'AUTH_FORBIDDEN_IP';
    public const CONFIG_COMPANY           = 'CONFIG_COMPANY';
    public const CONFIG_INCOMPLETE        = 'CONFIG_INCOMPLETE';
    public const ORDER_NOT_FOUND          = 'ORDER_NOT_FOUND';
    public const FIELD_NOT_APPLIED        = 'FIELD_NOT_APPLIED';
    public const CART_ITEM_UNMAPPED       = 'CART_ITEM_UNMAPPED';
    public const STATUS_NOT_FOUND_IN_SR   = 'STATUS_NOT_FOUND_IN_SR';
    public const SR_REJECTED              = 'SR_REJECTED';
    public const TRANSIENT                = 'TRANSIENT';

    /** Error classes that need a human action (token / settings), no point retrying. */
    private const ACCESS_CODES = [
        self::PERMISSION_DENIED, self::PERMISSION_DENIED_READ, self::PERMISSION_DENIED_CREATE,
        self::AUTH_INVALID, self::AUTH_FORBIDDEN_IP, self::CONFIG_COMPANY, self::CONFIG_INCOMPLETE,
    ];

    public bool $ok;
    public ?array $data;
    public ?string $errorCode;
    public ?int $httpStatus;
    public string $message;
    public ?array $restriction;

    private function __construct(bool $ok, ?array $data, ?string $errorCode, ?int $httpStatus, string $message, ?array $restriction)
    {
        $this->ok          = $ok;
        $this->data        = $data;
        $this->errorCode   = $errorCode;
        $this->httpStatus  = $httpStatus;
        $this->message     = $message;
        $this->restriction = $restriction;
    }

    public static function success(array $data, int $httpStatus = 200): self
    {
        return new self(true, $data, null, $httpStatus, '', null);
    }

    public static function failure(string $code, ?int $httpStatus = null, ?string $detail = null, ?array $restriction = null): self
    {
        return new self(false, null, $code, $httpStatus, self::userMessage($code, $detail, $restriction), $restriction);
    }

    public function isTransient(): bool
    {
        return $this->errorCode === self::TRANSIENT;
    }

    /** Permission / token / settings problem: the user (or the call center) must act. */
    public function isAccessProblem(): bool
    {
        return in_array($this->errorCode, self::ACCESS_CODES, true);
    }

    /** "OrderMutation.updateOrder" or null. */
    public function restrictionKey(): ?string
    {
        if (!$this->restriction) {
            return null;
        }

        return ($this->restriction['type'] ?? '?') . '.' . ($this->restriction['field'] ?? '?');
    }

    /**
     * Human readable text for the UI; never contains the token or raw SalesRender payloads.
     */
    public static function userMessage(string $code, ?string $detail = null, ?array $restriction = null): string
    {
        $detail = $detail !== null ? self::clip($detail) : '';

        switch ($code) {
            case self::PERMISSION_DENIED:
                return 'У токена SalesRender нет права изменять заказы (updateOrder). Изменения сохранены в CRM, но колл-центр их не видит.';
            case self::PERMISSION_DENIED_READ:
                return 'У токена SalesRender нет права читать заказы. Статусы из колл-центра не обновляются, изменения нельзя сверить.';
            case self::PERMISSION_DENIED_CREATE:
                return 'У токена SalesRender нет права создавать заказы: новые заявки не попадают в колл-центр.';
            case self::AUTH_INVALID:
                return 'Токен SalesRender недействителен или истёк. Укажите новый токен в настройках.';
            case self::AUTH_FORBIDDEN_IP:
                return 'SalesRender не принимает запросы с адреса нашего сервера (IP-ограничение роли токена).';
            case self::CONFIG_COMPANY:
                return 'Неверный Company ID в настройках SalesRender.';
            case self::CONFIG_INCOMPLETE:
                return 'Интеграция SalesRender включена, но не заполнены токен или Company ID.';
            case self::ORDER_NOT_FOUND:
                return 'Заявка не найдена в SalesRender (удалена или ID неверный).';
            case self::FIELD_NOT_APPLIED:
                return 'SalesRender принял запрос, но не изменил' . ($detail !== '' ? ': ' . $detail : ' данные заявки') . '. Проверьте поля заказа в проекте SalesRender.';
            case self::CART_ITEM_UNMAPPED:
                return 'Товар' . ($detail !== '' ? ' «' . $detail . '»' : '') . ' не связан с позицией SalesRender. Укажите ID позиции в карточке товара.';
            case self::STATUS_NOT_FOUND_IN_SR:
                return 'В SalesRender нет статуса' . ($detail !== '' ? ' «' . $detail . '»' : '') . '. Проверьте соответствие статусов.';
            case self::TRANSIENT:
                return 'SalesRender временно недоступен' . ($detail !== '' ? ' (' . $detail . ')' : '') . '. Повторим отправку автоматически.';
            default:
                return 'SalesRender отклонил запрос' . ($detail !== '' ? ': ' . $detail : '.');
        }
    }

    public static function clip(string $text, int $max = 200): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }
}
