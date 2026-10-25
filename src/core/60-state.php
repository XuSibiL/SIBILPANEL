<?php
declare(strict_types=1);

/* 6) Conversation state (persisted — survives service restarts) */

final class State
{
    public static function get(int $uid): array
    {
        $row = DB::one('SELECT state, data FROM tg_state WHERE uid=?', [$uid]);
        if ($row === null) {
            return ['state' => '', 'data' => []];
        }
        return ['state' => (string) $row['state'],
                'data'  => json_decode((string) $row['data'], true) ?: []];
    }

    public static function set(int $uid, string $state, array $data = []): void
    {
        DB::exec('INSERT INTO tg_state(uid, state, data, updated_at) VALUES(?,?,?,?) '
            . 'ON CONFLICT(uid) DO UPDATE SET state=excluded.state, data=excluded.data, '
            . 'updated_at=excluded.updated_at',
            [$uid, $state, json_encode($data, JSON_UNESCAPED_UNICODE), now_ts()]);
    }

    public static function clear(int $uid): void
    {
        DB::exec('DELETE FROM tg_state WHERE uid=?', [$uid]);
    }
}

