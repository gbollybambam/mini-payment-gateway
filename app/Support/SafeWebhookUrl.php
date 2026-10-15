<?php

namespace App\Support;

final class SafeWebhookUrl
{
    public static function isSafe(?string $url): bool
    {
        if (!$url) {
            return true;
        }

        $parts = parse_url($url);
        if (!$parts || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }

        $host = trim($parts['host'], '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : array_merge(
                array_map(static fn (array $record): string => $record['ip'], @dns_get_record($host, DNS_A) ?: []),
                array_map(static fn (array $record): string => $record['ipv6'], @dns_get_record($host, DNS_AAAA) ?: [])
            );

        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (self::isPrivateAddress($address)) {
                return false;
            }
        }

        return true;
    }

    private static function isPrivateAddress(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return true;
        }

        if (strlen($packed) === 4) {
            $ip = unpack('N', $packed)[1];
            return ($ip & 0xff000000) === 0x00000000
                || ($ip & 0xff000000) === 0x7f000000
                || ($ip & 0xff000000) === 0x0a000000
                || ($ip & 0xffff0000) === 0xa9fe0000
                || ($ip & 0xffff0000) === 0xc0a80000
                || ($ip & 0xfff00000) === 0xac100000
                || ($ip & 0xffc00000) === 0x64400000;
        }

        return $packed === str_repeat("\x00", 15) . "\x01"
            || (ord($packed[0]) & 0xfe) === 0xfc
            || (ord($packed[0]) === 0xfe && (ord($packed[1]) & 0xc0) === 0x80)
            || (substr($packed, 0, 12) === str_repeat("\x00", 10) . "\xff\xff"
                && self::isPrivateAddress(inet_ntop(substr($packed, 12))));
    }
}
