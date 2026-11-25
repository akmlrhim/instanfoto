<?php

declare(strict_types=1);

namespace InstanFoto;

final class Cleanup
{
    public static function run(Config $config, ?int $retentionDays = null): array
    {
        $days = $retentionDays ?? $config->retentionDays;
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $expired = Database::query('SELECT id FROM sessions WHERE created_at < ?', [$cutoff])->fetchAll();
        $deleted = 0;

        foreach ($expired as $row) {
            $sessionId = (string) $row['id'];

            if (preg_match(Security::SESSION_ID_PATTERN, $sessionId) !== 1) {
                continue;
            }

            Storage::deleteSessionFiles($config, $sessionId);
            Database::query('DELETE FROM photos WHERE session_id = ?', [$sessionId]);
            Database::query('DELETE FROM sessions WHERE id = ?', [$sessionId]);
            $deleted += 1;
        }

        Database::query('DELETE FROM rate_limits WHERE window_start < ?', [time() - 86400]);

        return ['deleted' => $deleted, 'retentionDays' => $days];
    }
}