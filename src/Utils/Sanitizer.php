<?php

declare(strict_types=1);

namespace AdminIntelligence\LogShipper\Utils;

/**
 * The one set of redaction rules, used by the handler and by ShipLogJob::failed().
 */
class Sanitizer
{
    public const REDACTED = '[REDACTED]';

    /**
     * Top-level payload fields that hold URLs whose query strings can carry secrets.
     */
    protected const URL_FIELDS = ['request_url', 'referrer'];

    public static function sanitize(array $data, array $sensitiveFields): array
    {
        $data = self::sanitizeRecursive($data, $sensitiveFields);

        foreach (self::URL_FIELDS as $field) {
            if (is_string($data[$field] ?? null)) {
                $data[$field] = self::sanitizeUrl($data[$field], $sensitiveFields);
            }
        }

        return $data;
    }

    public static function sanitizeRecursive(array $data, array $sensitiveFields): array
    {
        foreach ($data as $key => $value) {
            // Key check first: a sensitive key loses its whole value, arrays included (headers are arrays)
            if (self::isSensitiveKey($key, $sensitiveFields)) {
                $data[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $data[$key] = self::sanitizeRecursive($value, $sensitiveFields);
            }
        }

        return $data;
    }

    /**
     * Redact the values of sensitive query (and fragment) parameters, keep the rest of the URL.
     */
    public static function sanitizeUrl(string $url, array $sensitiveFields): string
    {
        return preg_replace_callback(
            '/(?<=[?&#])([^=&#]+)=([^&#]*)/',
            fn (array $m) => self::isSensitiveKey(urldecode($m[1]), $sensitiveFields) ? $m[1] . '=' . self::REDACTED : $m[0],
            $url
        ) ?? $url;
    }

    /**
     * Check if a key is sensitive.
     *
     * Keys and fields are split into lowercase words (camelCase, _, -, spaces, brackets...).
     * A key matches when the field's words appear in it as a run of whole words (each word may
     * also be its plural with a trailing "s"), so apiKey, x-api-key, API_KEY and apiKeys match
     * "api_key", but "secretary" does not match "secret".
     *
     * Accepts int keys: lists in the log context (e.g. ['ids' => [1, 2, 3]]) are
     * traversed with their integer keys, which can never be a sensitive field name.
     */
    public static function isSensitiveKey(string|int $key, array $sensitiveFields): bool
    {
        $keyWords = self::words((string) $key);
        $joinedKey = implode('', $keyWords);

        foreach ($sensitiveFields as $field) {
            $fieldWords = self::words((string) $field);
            if ($fieldWords === []) {
                continue;
            }

            // Joined compare catches odd casing like "PassWord" for "password"
            $joinedField = implode('', $fieldWords);
            if ($joinedKey === $joinedField || $joinedKey === $joinedField . 's') {
                return true;
            }

            // Field words as a run of whole key words; a key word may be the plural (keys for key)
            for ($i = 0; $i + count($fieldWords) <= count($keyWords); $i++) {
                foreach ($fieldWords as $j => $fieldWord) {
                    if ($keyWords[$i + $j] !== $fieldWord && $keyWords[$i + $j] !== $fieldWord . 's') {
                        continue 2;
                    }
                }

                return true;
            }
        }

        return false;
    }

    /**
     * "X-XSRF-Token" -> [x, xsrf, token], "clientSecret" -> [client, secret].
     */
    protected static function words(string $value): array
    {
        $value = preg_replace(['/(?<=[a-z0-9])(?=[A-Z])/', '/(?<=[A-Z])(?=[A-Z][a-z])/'], ' ', $value) ?? $value;

        return preg_split('/[^a-z0-9]+/', strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
